<?php
/**
 * Manual Migration Controller
 *
 * @package    Tailwatch
 * @subpackage Admin\App\Api\Controllers\Migration
 * @author     WP Tailwatch
 * @copyright  2025-2026 WP TAILWATCH LLC
 * @license    GPL-2.0+
 */

namespace Tailwatch\Admin\App\Api\Controllers\Migration;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Tailwatch\Admin\App\Api\Controllers\Logs\LiveLogs\LiveLogsController;
use Tailwatch\Admin\App\Api\Logging\Log;
use Tailwatch\Admin\App\Api\Services\Common\FilesystemService;
use Tailwatch\Admin\App\Api\Services\Common\SecureDirectoryService;
use Tailwatch\Admin\App\Api\Services\Backup\BackupReconciler;
use Tailwatch\Admin\App\Api\Services\Backup\MigrationPublisher;
use Tailwatch\Admin\App\Api\Services\Backup\RestorePrefix;
use Tailwatch\Admin\App\Api\Rest\Backup\BackupStreamController;
use Tailwatch\Admin\App\Api\Controllers\Features\OptionsController;
use Tailwatch\Admin\App\Api\Controllers\Restore\RestoreController;
use Tailwatch\Admin\App\Api\Services\ProcessGuard;
use Tailwatch\Admin\App\Api\Services\ProcessManager;
use Tailwatch\Admin\App\Api\Services\ProcessStartClaim;
use Tailwatch\Admin\App\Api\Traits\ContextAuthorizationTrait;
use Tailwatch\Admin\App\Api\Traits\FeatureEnableTrait;

/**
 * Moving a site between two installs without either needing to reach the other.
 *
 * ## The shape, and why it is this shape
 *
 * The automatic transfer — the destination pulls from the source, or asks the source to
 * push — needs the two sites to be able to talk. Plenty cannot: a local build, a site
 * behind a VPN, a client's server with all outbound HTTP blocked, an install that does not
 * resolve yet. For those the only thing that always works is a human carrying the files.
 *
 * So the manual path is deliberately dumb:
 *
 * 1. **Export** on the source produces a small manifest describing one backup — the folder,
 *    the address the data belongs to, the table prefix, and every part with its size and
 *    digest. It is not the backup; it is the packing list.
 * 2. The operator downloads that backup's parts by whatever route they already have.
 * 3. **Import** on the destination takes the manifest first, then the parts, uploaded in
 *    chunks and reassembled here — because a 50MB part will not survive most PHP upload
 *    limits in one request, and asking people to raise those limits is asking them to
 *    change their server to use a backup plugin.
 * 4. Every reassembled part is verified against the manifest before it counts, and when
 *    they are all present the set is published as an ordinary restorable backup.
 *
 * From there nothing is special: the existing restore does the rest, including rewriting
 * the source's URLs to the destination's. That reuse is the point — a separate SQL importer
 * for this path would be a second, weaker copy of something the restore pipeline already
 * does properly.
 *
 * ## What the manifest is not
 *
 * It carries no credentials and grants nothing. Holding one lets you know a backup's part
 * names and digests; it does not let you fetch them. That is why it is safe to email.
 */
class MigrationManualController {

	use ContextAuthorizationTrait;
	use FeatureEnableTrait;

	/**
	 * Where the in-progress manual import records what it is expecting and what has landed.
	 */
	const STATE_OPTION = 'tailwatch_manual_migration_state';

	/**
	 * Where the running commentary for a migration is written.
	 *
	 * A migration is a long operation with a lot of small steps — a part arrives, is
	 * assembled, is checked against the file — and a bar and a count alone cannot explain
	 * them: when a part is refused, "3 of 12" does not say which one or why. One shared
	 * file for both ends, because an operator watches whichever site they are standing on.
	 */
	const LOG_DIRECTORY = TAILWATCH_LOGS_DIRECTORY . '/migration-logs';
	const LOG_FILE      = 'migration.json';

	/**
	 * The feature row that decides whether this site takes part in a migration.
	 */
	const FEATURE_KEY    = 'default_feature_settings';
	const FEATURE_OPTION = 'default_site_migration';

	/**
	 * Methods that keep working when migration is switched off.
	 *
	 * Reading progress and abandoning a half-finished import are how an operator gets
	 * out of a migration, not into one. Gating them would strand the parts already on
	 * disk with no way to see or remove them.
	 *
	 * @var string[]
	 */
	protected $tailwatch_feature_check_exemptions = array(
		'tailwatch_manual_import_status',
		'tailwatch_migration_logs',
		'tailwatch_cancel_manual_import',
	);

	/**
	 * @var ProcessManager
	 */
	private $process_manager;

	public function __construct() {
		$this->process_manager = new ProcessManager();
		$this->register_process_monitoring();
	}

	/**
	 * Declare the import so the dashboard and the guard can both see it.
	 *
	 * Registered as `migration`, the same type the automatic transfer uses. The two never
	 * run together and an operator does not distinguish them: both end with this site
	 * holding a backup from somewhere else. Sharing the type means one label, one route
	 * for the dashboard to send people to, and one entry in everything else's
	 * `cannot_start_while` list.
	 *
	 * A manual import writes only into the backup tree, never the live site — so it does
	 * not close the site the way a restore does. It is declared anyway because it is long,
	 * resumable, and must not be interleaved with anything that rewrites the same tree: a
	 * backup rotating away a folder mid-upload, or a restore reading a set that is still
	 * half-arrived.
	 *
	 * @return void
	 */
	private function register_process_monitoring() {
		ProcessManager::register_process(
			array(
				'process_type'       => 'migration',
				'data_source'        => 'wp_options',
				'data_option'        => self::STATE_OPTION,
				'stuck_threshold'    => 600,
				'max_retries'        => 3,
				'cannot_start_while' => array(
					'backup',
					'restore',
					'malware_restore',
					'settings_import',
					'reset_all',
				),
				// A by-hand migration is twenty to forty minutes of someone uploading parts.
				// Without this, one click on "Allow Migration" ends it: the next chunk is
				// refused and the operator starts the whole transfer again. The uploader does
				// report it rather than failing silently - "Migration was switched off while
				// this was uploading" - but the honest answer is to refuse the click.
				'locks_features'     => array( 'default_site_migration' ),
			)
		);
	}

	/**
	 * Whether this site may take part in a migration.
	 *
	 * @return array{parent_enable:bool,feature_enable:bool}
	 */
	protected function tailwatch_get_feature_status() {
		$options = ( new OptionsController() )->get_features_options( self::FEATURE_KEY, self::FEATURE_OPTION, true );

		if ( empty( $options ) ) {
			return array(
				'parent_enable'  => false,
				'feature_enable' => false,
			);
		}

		return array(
			'parent_enable'  => true,
			'feature_enable' => true === ( $options['field_2']['options']['option']['selected'] ?? false ),
		);
	}

	/**
	 * Largest single chunk accepted, in bytes.
	 *
	 * Comfortably under the upload limit of any host worth supporting, so the client can
	 * pick a chunk size without negotiating.
	 */
	const MAX_CHUNK_BYTES = 4194304;

	/**
	 * Most chunks one part may be split into.
	 *
	 * Enough for the largest part size a backup can be set to (400 MB) at the smallest chunk
	 * chunk_bytes() ever returns (64 KB), while still bounding how many stray `.part` files
	 * one upload can create.
	 */
	const MAX_CHUNKS = 8192;

	/**
	 * Say what is happening, for the operator watching.
	 *
	 * @param string $message What is happening.
	 * @param string $level   INFO, WARNING or ERROR.
	 * @return void
	 */
	public static function narrate( $message, $level = 'INFO' ) {
		if ( ! is_dir( self::LOG_DIRECTORY ) ) {
			wp_mkdir_p( self::LOG_DIRECTORY );
			SecureDirectoryService::ensure( self::LOG_DIRECTORY, true );
		}

		( new LiveLogsController() )->update_live_logs_records( $message, self::LOG_DIRECTORY . '/' . self::LOG_FILE, $level );
	}

	/**
	 * Begin a fresh record for a new migration, by hand or automatic.
	 *
	 * A restore writes one file per backup, so its console is scoped to the run being
	 * watched. A migration has one shared file — so without this, starting one opens a
	 * console showing the previous migration's lines above it, ending in "Every part
	 * arrived and was checked" from a run that finished last week. Reading from index
	 * zero, the panel replays all of it as though it were happening now.
	 *
	 * Public because the automatic transfer lives in the pro plugin, writes into this
	 * same file through the same alias, and has to start a fresh record too.
	 *
	 * @return void
	 */
	public static function start_record() {
		if ( ! is_dir( self::LOG_DIRECTORY ) ) {
			wp_mkdir_p( self::LOG_DIRECTORY );
			SecureDirectoryService::ensure( self::LOG_DIRECTORY, true );
		}

		$fs = FilesystemService::get_filesystem();
		if ( $fs ) {
			$fs->put_contents(
				self::LOG_DIRECTORY . '/' . self::LOG_FILE,
				wp_json_encode( array( 'message' => array() ) ),
				FS_CHMOD_FILE
			);
		}
	}

	/**
	 * The running commentary, from wherever the caller had got to.
	 *
	 * @param string|array $post_data Payload; expects `lastLogIndex`.
	 * @return array
	 */
	public function tailwatch_migration_logs( $post_data ) {
		if ( ! current_user_can( $this->network_capability() ) ) {
			return $this->unauthorized_response();
		}

		$payload = $this->decode( $post_data );
		$from    = isset( $payload['lastLogIndex'] ) ? max( 0, (int) $payload['lastLogIndex'] ) : 0;

		$path = self::LOG_DIRECTORY . '/' . self::LOG_FILE;
		$all  = array();

		if ( is_file( $path ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.PHP.NoSilencedErrors.Discouraged -- the plugin's own log file; checked immediately.
			$raw = @file_get_contents( $path );
			if ( is_string( $raw ) ) {
				$decoded = json_decode( $raw, true );
				if ( is_array( $decoded ) && isset( $decoded['message'] ) && is_array( $decoded['message'] ) ) {
					$all = $decoded['message'];
				}
			}
		}

		$new = $from < count( $all ) ? array_slice( $all, $from ) : array();

		return array(
			'code'           => 200,
			'new_logs'       => array_values( array_map( 'strval', $new ) ),
			'last_log_index' => count( $all ),
		);
	}

	/**
	 * The largest chunk this host will actually take.
	 *
	 * MAX_CHUNK_BYTES is our own ceiling; PHP's upload limits are usually lower, and a
	 * chunk over those is discarded by PHP before any of this code runs — the request
	 * arrives with no file at all and the operator is told "no upload was received",
	 * which is true and useless. Stating the real number up front turns a confusing
	 * failure into a non-event, and means the browser never has to guess.
	 *
	 * @return int Bytes.
	 */
	public static function chunk_bytes() {
		$limits = array( self::MAX_CHUNK_BYTES );
		foreach ( array( 'upload_max_filesize', 'post_max_size' ) as $directive ) {
			$bytes = wp_convert_hr_to_bytes( (string) ini_get( $directive ) );
			if ( $bytes > 0 ) {
				$limits[] = $bytes;
			}
		}

		// A network that limits upload size holds every chunk to it: wp_handle_upload()
		// refuses a larger file before storing anything.
		if ( is_multisite() && ! get_site_option( 'upload_space_check_disabled' ) ) {
			$network_kb = absint( get_site_option( 'fileupload_maxk', 1500 ) );
			if ( $network_kb > 0 ) {
				$limits[] = $network_kb * KB_IN_BYTES;
			}
		}

		// The chunk shares its POST with the action, the nonce and the multipart
		// boundaries, so the file itself has to sit comfortably under the limit rather
		// than exactly on it.
		return max( 65536, (int) floor( min( $limits ) * 0.8 ) );
	}

	/**
	 * Describe a backup so it can be carried to another site.
	 *
	 * @param string|array $post_data Request payload; expects `folderDate`.
	 * @return array
	 */
	public function tailwatch_export_migration_manifest( $post_data ) {
		if ( ! current_user_can( $this->network_capability() ) ) {
			return $this->unauthorized_response();
		}

		$payload = $this->decode( $post_data );
		$folder  = isset( $payload['folderDate'] ) ? sanitize_text_field( wp_unslash( $payload['folderDate'] ) ) : '';

		if ( '' === $folder || ! $this->is_safe_folder( $folder ) ) {
			return array(
				'code'    => 400,
				'message' => __( 'Choose a backup to export.', 'tailwatch' ),
			);
		}

		$marker = BackupReconciler::tailwatch_read_completion_marker( $folder );
		if ( ! is_array( $marker ) ) {
			return array(
				'code'    => 404,
				'message' => __( 'That backup has no completion record, so it cannot be exported. Only a finished backup can be migrated.', 'tailwatch' ),
			);
		}

		// An increment holds only what changed since the backup before it. Carried on its
		// own it would verify perfectly on the destination and rebuild a site missing
		// everything that had not changed recently — the worst shape a failure can take,
		// because nothing reports it.
		if ( ! empty( $marker['incremental'] ) ) {
			return array(
				'code'    => 409,
				'message' => __( 'That is an incremental backup, which cannot be migrated on its own. Take a full backup first, then export that.', 'tailwatch' ),
			);
		}

		global $wpdb;

		$manifest_parts = MigrationPublisher::parts_from_marker( $marker );

		// The export end of a migration writes into the same log the import end does, so
		// an operator standing on either site sees a continuous account of the move.
		self::narrate(
			sprintf(
				/* translators: 1: backup name, 2: number of parts to carry across. */
				__( 'Migration file exported for %1$s: %2$d part(s) to carry to the other site.', 'tailwatch' ),
				$folder,
				count( (array) $manifest_parts )
			)
		);

		return array(
			'code'     => 200,
			'message'  => __( 'Migration file ready.', 'tailwatch' ),
			'manifest' => array(
				'schema'    => 1,
				'folder'    => $folder,
				// The site the DATA describes. After the restore this is what will be
				// sitting in the destination's database, so it is what the URL rewrite has
				// to replace — and the destination cannot work it out once the swap has
				// happened.
				'site_url'  => (string) ( $marker['site_url'] ?? site_url() ),
				// The destination refuses a restore whose tables are named for a different
				// install, so it has to be told which prefix these statements carry.
				'prefix'    => (string) ( $marker['table_prefix'] ?? $wpdb->base_prefix ),
				'generated' => current_time( 'mysql' ),
				'parts'     => $manifest_parts,
			),
		);
	}

	/**
	 * Mint a one-shot link for one file of a backup.
	 *
	 * Step two of carrying a backup by hand: the manifest names the parts, and this says
	 * where to fetch each one. The grant is per file, expires in five minutes and is spent
	 * on first use, so a link that leaks is worth very little and not for long.
	 *
	 * An action rather than a route of its own, and that is the whole point of it being
	 * here. Deciding whether a caller may export a backup is a management decision, and
	 * the connect plane authorises exactly one route — `/dispatch` — which routes by
	 * action. A REST route gated on `current_user_can()` would be reachable from a
	 * browser and from nothing else: the dashboard and the app could take a backup,
	 * restore one and migrate one, but not download one. The bytes still travel over
	 * a token-authenticated route, because what fetches them is often not what can
	 * authenticate — a download manager resuming, another site pulling.
	 *
	 * Answers to the migration switch, like every other method here. Not an extra obstacle:
	 * a caller only knows a part's name because the manifest named it, and the manifest is
	 * refused by the same switch — so with migration off there are no parts on screen to
	 * ask for. Exempting this one would mean parts could be fetched while the manifest
	 * listing them was refused, which is half a flow rather than a clear no.
	 *
	 * @param string|array $post_data Request payload; expects `folder` and `file`.
	 * @return array
	 */
	public function tailwatch_issue_backup_download( $post_data ) {
		// On multisite this is a network-level act: one backup holds every site in the
		// network's data, so a single-site administrator must not be able to export it.
		// Checked here rather than left to the caller's transport, because the two planes
		// gate differently and exporting a backup is the same decision on both.
		if ( ! current_user_can( $this->network_capability() ) ) {
			return $this->unauthorized_response();
		}

		$payload = $this->decode( $post_data );
		$folder  = isset( $payload['folder'] ) ? sanitize_text_field( (string) $payload['folder'] ) : '';
		$file    = isset( $payload['file'] ) ? sanitize_text_field( (string) $payload['file'] ) : '';
		$area    = isset( $payload['area'] ) ? sanitize_text_field( (string) $payload['area'] ) : 'files';

		if ( '' === $folder || '' === $file ) {
			return array(
				'code'    => 400,
				'message' => __( 'A backup folder and file are both required.', 'tailwatch' ),
			);
		}

		$granted = BackupStreamController::grant_for( $folder, $file, $area );

		if ( null === $granted ) {
			// Deliberately the same answer for "no such file" and "outside the tree": the
			// caller is authorised, but there is no reason to help map the filesystem.
			return array(
				'code'    => 404,
				'message' => __( 'That backup file is not available for download.', 'tailwatch' ),
			);
		}

		return array(
			'code'    => 200,
			'url'     => $granted['url'],
			'expires' => $granted['expires'],
		);
	}

	/**
	 * Accept a manifest and start expecting its parts.
	 *
	 * @param string|array $post_data Request payload; expects `manifest`.
	 * @return array
	 */
	public function tailwatch_begin_manual_import( $post_data ) {
		if ( ! current_user_can( $this->network_capability() ) ) {
			return $this->unauthorized_response();
		}

		$payload  = $this->decode( $post_data );
		$manifest = isset( $payload['manifest'] ) ? $payload['manifest'] : null;

		if ( is_string( $manifest ) ) {
			$manifest = json_decode( $manifest, true );
		}

		$invalid = $this->manifest_problem( $manifest );
		if ( null !== $invalid ) {
			return array(
				'code'    => 400,
				'message' => $invalid,
			);
		}

		// The exclusion has to run both ways: the import's `migration` process row stops
		// everything else from starting during one, and this stops the import itself from
		// starting on top of a running backup, restore or scan writing the same backup tree.
		$blocked = ( new ProcessGuard() )->ensure_can_start_process( 'migration' );
		if ( null !== $blocked ) {
			return $blocked;
		}

		$folder = sanitize_text_field( (string) $manifest['folder'] );

		// A folder already holding a backup must not be written into: the parts would mix
		// with an existing set and the marker would describe neither.
		foreach ( array( 'files', 'database' ) as $tree ) {
			if ( is_dir( TAILWATCH_BACKUP_DIR . '/' . $tree . '/' . $folder ) ) {
				return array(
					'code'    => 409,
					'message' => __( 'This site already has a backup with that name. Remove it first, or export a different backup.', 'tailwatch' ),
				);
			}
		}

		$parts = array();
		foreach ( $manifest['parts'] as $part ) {
			$parts[] = array(
				'area'   => ( 'database' === $part['area'] ) ? 'database' : 'files',
				// Stored exactly as the manifest gave it, having been checked above. Storing
				// a rewritten name would mean the state, the manifest and the file on disk
				// all disagreeing about what the part is called.
				'file'   => (string) $part['file'],
				'size'   => isset( $part['size'] ) ? (int) $part['size'] : 0,
				'digest' => isset( $part['digest'] ) ? (string) $part['digest'] : '',
			);
		}

		// Asked again under the claim, so a backup or restore another request started since the
		// check above is not joined by this import.
		$start_claim = ( new ProcessGuard() )->claim_start( 'migration' );
		if ( is_array( $start_claim ) ) {
			return $start_claim;
		}

		try {
			// A new import is a new record. Anything above this line belongs to a migration
			// that is already over.
			self::start_record();

			// The row the dashboard and the guard both read. Created before the state option so
			// there is no window where an import is under way with nothing declaring it.
			$process_id = $this->process_manager->get_or_create_process(
				'migration',
				'',
				array(
					'folder' => $folder,
					'parts'  => count( $parts ),
					'method' => 'manual',
				)
			);
			// Under way from here: the browser sends the parts, there is no scheduled step to wait for.
			if ( $process_id ) {
				$this->process_manager->update_state( $process_id, 'in_progress' );
			}
		} finally {
			ProcessStartClaim::release( $start_claim );
		}

		update_option(
			self::STATE_OPTION,
			array(
				'folder'     => $folder,
				'site_url'   => (string) $manifest['site_url'],
				'prefix'     => (string) $manifest['prefix'],
				'parts'      => $parts,
				'received'   => array(),
				'started'    => time(),
				'process_id' => $process_id,
			),
			false
		);

		self::narrate(
			sprintf(
				/* translators: 1: backup name, 2: number of parts. */
				__( 'Migration file loaded for %1$s. Waiting for %2$d part(s).', 'tailwatch' ),
				$folder,
				count( $parts )
			)
		);

		Log::info(
			'A migration file was imported; waiting for the backup parts.',
			array(
				'feature'   => 'migration',
				'action'    => 'manual_migration_started',
				'meta_data' => array(
					'feature' => 'Migration',
					'event'   => 'Migration file imported',
					'parts'   => count( $parts ),
				),
			)
		);

		return array(
			'code'    => 200,
			'message'     => __( 'Migration file accepted. Upload the backup parts next.', 'tailwatch' ),
			'folder'      => $folder,
			'parts'       => wp_list_pluck( $parts, 'file' ),
			'chunk_bytes' => self::chunk_bytes(),
		);
	}

	/**
	 * Mark the import as moving.
	 *
	 * Every chunk is its own request and the only sign the import is alive, so each one says
	 * so. A part slower than the stuck threshold would otherwise read as stuck, then failed,
	 * while the browser was still sending it, and a "Stuck" set while the browser was quiet
	 * clears with the next chunk.
	 *
	 * @param array $state Import state.
	 * @return void
	 */
	private function note_import_alive( array $state ) {
		if ( ! empty( $state['process_id'] ) ) {
			$this->process_manager->update_state( (string) $state['process_id'], 'in_progress' );
		}
	}

	/**
	 * Receive one chunk of one part, and assemble when the last arrives.
	 *
	 * The ONE action in this API that does not read the dispatcher's `data` payload, and the
	 * only one whose request must be `multipart/form-data`. It takes no argument at all: the
	 * dispatcher still passes `$post_data` and PHP discards it.
	 *
	 *     action_type = tailwatch_upload_migration_chunk
	 *     file        = the part's file name, exactly as the manifest named it
	 *     chunk       = 0-based index of this piece
	 *     chunks      = how many pieces the part was split into
	 *     from        = 0-based index this upload began at; 0 unless a part is being resumed
	 *     part        = the bytes, as a file upload
	 *
	 * All of them are form fields, NOT members of `data`. That is not an oversight to tidy up:
	 * the bytes have to arrive as a real upload, because `is_uploaded_file()` below and
	 * wp_handle_upload() in store_chunk() only accept a file PHP itself wrote to its temp
	 * directory from a multipart request - and that is this route's security model, not a
	 * formality.
	 * Once the request is multipart the scalars ride alongside as form fields; encoding them
	 * as JSON in a `data` field, next to a binary field that cannot be JSON, would buy
	 * nothing. A 3.2 MB chunk base64'd into JSON would also grow by a third and have to be
	 * decoded in memory, which is the cost chunking exists to avoid.
	 *
	 * Each field is judged on arrival rather than trusted: `file` through
	 * is_safe_part_name() and then matched against the manifest by expected_part(), so a
	 * name the import never expected writes nothing; `chunk` and `chunks` through absint()
	 * and a range check against MAX_CHUNKS; `from` through absint(), and it reaches nothing
	 * but a log line - what a resumed upload may skip is decided by resumable_chunks() from
	 * the pieces on disk, never from what the browser claimed it had already sent.
	 *
	 * @return array
	 */
	public function tailwatch_upload_migration_chunk() {
		if ( ! current_user_can( $this->network_capability() ) ) {
			return $this->unauthorized_response();
		}

		$state = get_option( self::STATE_OPTION, array() );
		if ( ! is_array( $state ) || empty( $state['parts'] ) ) {
			return array(
				'code'    => 409,
				'message' => __( 'Import the migration file before uploading parts.', 'tailwatch' ),
			);
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- the AJAX dispatcher verifies the nonce and capability before any controller runs.
		$file_name = isset( $_POST['file'] ) ? sanitize_text_field( wp_unslash( $_POST['file'] ) ) : '';
		if ( '' !== $file_name && ! self::is_safe_part_name( $file_name ) ) {
			$file_name = '';
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- as above.
		$chunk = isset( $_POST['chunk'] ) ? absint( wp_unslash( $_POST['chunk'] ) ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- as above.
		$chunks = isset( $_POST['chunks'] ) ? absint( wp_unslash( $_POST['chunks'] ) ) : 0;
		// Where the browser says this upload began. It decides one log line and nothing
		// else - a chunk is written at the index it carries, whatever this says.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- as above.
		$from = isset( $_POST['from'] ) ? absint( wp_unslash( $_POST['from'] ) ) : 0;

		if ( $chunks < 1 || $chunks > self::MAX_CHUNKS || $chunk >= $chunks ) {
			return array(
				'code'    => 400,
				'message' => __( 'That upload was not split into a usable set of chunks.', 'tailwatch' ),
			);
		}

		// The part has to be one the manifest named. Without this the route would write any
		// file name the browser offered into the backup directory.
		$part = $this->expected_part( $state, $file_name );
		if ( null === $part ) {
			return array(
				'code'    => 400,
				'message' => __( 'That file is not part of the backup described by the migration file.', 'tailwatch' ),
			);
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- the AJAX dispatcher verifies the nonce and the network capability before any controller runs; tmp_name is a path PHP wrote, and is_uploaded_file() on this very line is what validates it. Sanitising it as text would corrupt the path rather than secure it.
		if ( empty( $_FILES['part']['tmp_name'] ) || ! is_uploaded_file( $_FILES['part']['tmp_name'] ) ) {
			return array(
				'code'    => 400,
				'message' => __( 'No upload was received.', 'tailwatch' ),
			);
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce and capability verified by the dispatcher; absint() sanitises.
		$size = isset( $_FILES['part']['size'] ) ? absint( $_FILES['part']['size'] ) : 0;
		if ( $size < 1 || $size > self::MAX_CHUNK_BYTES ) {
			return array(
				'code'    => 413,
				'message' => __( 'That chunk is larger than this site accepts. Use a smaller chunk size.', 'tailwatch' ),
			);
		}

		$dir    = TAILWATCH_BACKUP_DIR . '/' . $part['area'] . '/' . $state['folder'];
		$target = $dir . '/' . $part['file'];

		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- the AJAX dispatcher verifies the nonce and capability before any controller runs; wp_handle_upload() in store_chunk() checks the upload itself, and the name it carries is never used.
		$stored = $this->store_chunk( $_FILES['part'], $part, $dir, $target . '.part' . $chunk );
		if ( true !== $stored ) {
			return $stored;
		}
		$this->note_import_alive( $state );

		// The first chunk is what proves a part has started arriving. Saying it per chunk
		// would bury the log under hundreds of identical lines for one large part.
		if ( 0 === $chunk ) {
			self::narrate(
				sprintf(
					/* translators: 1: part file name, 2: number of chunks it arrives in. */
					__( 'Receiving %1$s in %2$d chunk(s).', 'tailwatch' ),
					$part['file'],
					$chunks
				)
			);
		}

		// A resumed part never sends chunk zero, so without this the log falls silent on the
		// one part whose progress most needs explaining.
		if ( $from > 0 && $chunk === $from && $from < $chunks ) {
			self::narrate(
				sprintf(
					/* translators: 1: part file name, 2: piece being sent now, 3: total pieces. */
					__( 'Resuming %1$s at piece %2$d of %3$d; the earlier pieces are already here.', 'tailwatch' ),
					$part['file'],
					$from + 1,
					$chunks
				)
			);
		}

		if ( $chunk + 1 < $chunks ) {
			return array(
				'code'     => 200,
				'message'  => __( 'Chunk received.', 'tailwatch' ),
				'complete' => false,
			);
		}

		self::narrate(
			sprintf(
				/* translators: %s: part file name. */
				__( 'All chunks of %s arrived; joining them into one file.', 'tailwatch' ),
				$part['file']
			)
		);

		$assembled = $this->assemble( $target, $chunks );
		if ( true !== $assembled ) {
			// Which part failed to assemble, not just that something did — the operator
			// has to know which one to send again.
			self::narrate( sprintf( '%s: %s', $part['file'], (string) $assembled ), 'ERROR' );

			return array(
				'code'    => 500,
				'message' => $assembled,
			);
		}

		// Verified against the manifest, by exactly the rules the automatic transfer
		// applies. A part that fails is removed rather than left to be restored later.
		$verified = MigrationPublisher::verify_part( $target, $part );
		if ( true !== $verified ) {
			// Which part, and why. A count alone cannot say either.
			self::narrate( sprintf( '%s: %s', $part['file'], (string) $verified ), 'ERROR' );

			return array(
				'code'    => 422,
				'message' => $verified,
			);
		}

		$recorded = $this->tailwatch_record_received( $state, array( $part['file'] ) );
		if ( ! is_array( $recorded ) ) {
			// With no import left at all, the part goes, and so does the folder this request
			// made for it: left behind, that folder makes the same migration file refuse to
			// load again. A newer import owns whatever is in its folder, so that is left alone.
			if ( 'cancelled' === $recorded ) {
				wp_delete_file( $target );
				if ( self::holds_only_seal( $dir ) ) {
					$this->remove_directory( $dir );
				}
				self::narrate(
					sprintf(
						/* translators: %s: part file name. */
						__( '%s arrived after the migration was discarded and was not kept.', 'tailwatch' ),
						$part['file']
					),
					'WARNING'
				);
			}

			return array(
				'code'    => 409,
				'message' => __( 'This migration was discarded while the part was arriving, so the part was not kept.', 'tailwatch' ),
			);
		}
		$state = $recorded;

		// Joining and checking a large part takes time of its own.
		$this->note_import_alive( $state );

		$outstanding = $this->outstanding( $state );
		self::narrate(
			sprintf(
				/* translators: 1: part file name, 2: number of parts still to come. */
				__( 'Received and verified %1$s. %2$d still to come.', 'tailwatch' ),
				$part['file'],
				$outstanding
			)
		);
		if ( 0 < $outstanding ) {
			return array(
				'code'        => 200,
				'message'     => __( 'Part received.', 'tailwatch' ),
				'complete'    => true,
				'outstanding' => $outstanding,
			);
		}

		return $this->tailwatch_finish_import( $state );
	}

	/**
	 * Finish an import whose parts have all arrived: check them on disk, seal the set and
	 * start the restore that applies it.
	 *
	 * Reached from the upload of the last missing part, and from a scan that takes the last
	 * missing parts in from the folder.
	 *
	 * @param array $state The import's state, with every part recorded as received.
	 * @return array
	 */
	private function tailwatch_finish_import( array $state ) {
		// Every part said it arrived. Check the disk before believing it.
		//
		// Receipt is recorded in $state['received'] when the part is assembled and
		// verified, and nothing else looks at the folder again. MigrationPublisher::publish()
		// does not look either - it builds the completion marker from this list alone and
		// writes `status => completed` for each entry - so a part that has gone missing
		// since would be sealed into the marker as present. The restore then fails on it,
		// and because the state option has been deleted by then there is no way left to
		// send that part again: an upload of hours ends with nothing to show and nothing
		// to resume.
		//
		// Existence and size only. The digest was checked when the part was assembled, and
		// re-hashing every part here would add minutes to the request that happens to carry
		// the last chunk - on the very hosts that needed the by-hand path because their
		// requests are short. A file that has vanished or been truncated is what this is
		// for, and size catches both.
		$vanished = array();
		foreach ( $state['parts'] as $expected ) {
			$path = TAILWATCH_BACKUP_DIR . '/' . $expected['area'] . '/' . $state['folder'] . '/' . $expected['file'];
			if ( ! is_file( $path ) ) {
				$vanished[] = $expected['file'];
				continue;
			}
			if ( ! empty( $expected['size'] ) && (int) $expected['size'] !== (int) filesize( $path ) ) {
				$vanished[] = $expected['file'];
			}
		}

		if ( ! empty( $vanished ) ) {
			// Forget them, so the count is honest and the operator can send just these
			// again. The state option stays, which is what makes that possible at all.
			$recorded = $this->tailwatch_record_received( $state, array(), $vanished );
			if ( is_array( $recorded ) ) {
				$state = $recorded;
			}

			foreach ( $vanished as $lost ) {
				self::narrate(
					sprintf(
						/* translators: %s: part file name. */
						__( '%s was received earlier but is no longer in the backup folder. It has to be uploaded again.', 'tailwatch' ),
						$lost
					),
					'ERROR'
				);
			}

			// The process row stays open on purpose: the import is not over, it is short.
			return array(
				'code'        => 409,
				'message'     => sprintf(
					/* translators: %d: number of parts that went missing. */
					_n(
						'%d part that had already arrived is missing from the backup folder. Upload it again to finish.',
						'%d parts that had already arrived are missing from the backup folder. Upload them again to finish.',
						count( $vanished ),
						'tailwatch'
					),
					count( $vanished )
				),
				'complete'    => false,
				'outstanding' => count( $vanished ),
				'missing'     => array_values( $vanished ),
			);
		}

		$published = MigrationPublisher::publish(
			$state['folder'],
			$state['parts'],
			$state['site_url'],
			$state['prefix']
		);

		if ( true !== $published ) {
			// The parts are all here but the set could not be sealed. The row has to stop
			// saying "running" either way, or the site is locked out of every other
			// operation by an import that has no way left to finish.
			if ( ! empty( $state['process_id'] ) ) {
				$this->process_manager->mark_failed( $state['process_id'], 'manual_migration_publish_failed' );
			}

			return array(
				'code'    => 500,
				'message' => (string) $published,
			);
		}

		// Closed BEFORE the restore is started, not after. A restore refuses to begin while
		// a `migration` process is live — migration is in its cannot_start_while list — so
		// leaving this row open would have the transfer finish and the apply silently
		// refuse, ending a migration with the parts on disk and the site untouched.
		if ( ! empty( $state['process_id'] ) ) {
			$this->process_manager->mark_completed( $state['process_id'] );
		}

		$folder = (string) $state['folder'];

		delete_option( self::STATE_OPTION );

		self::narrate( __( 'Every part arrived and was checked. Applying the backup to this site.', 'tailwatch' ) );

		$applied = self::apply_migrated_backup( $folder );

		Log::info(
			'A migrated backup was assembled and the restore was started.',
			array(
				'feature'   => 'migration',
				'action'    => 'manual_migration_complete',
				'meta_data' => array(
					'feature' => 'Migration',
					'event'   => 'Applying migrated backup',
					'backup'  => $folder,
				),
			)
		);

		return array(
			'code'        => 200,
			'message'     => $applied
				? __( 'All parts received. The backup is being applied to this site.', 'tailwatch' )
				: __( 'All parts received, but applying the backup could not be started. The migration log says why.', 'tailwatch' ),
			'complete'    => true,
			'outstanding' => 0,
			'ready'       => true,
			// Tells the screen to follow the operation onto the restore, rather than
			// reporting a finished migration over a site that is still being replaced.
			'restoring'   => $applied,
			'folder'      => $folder,
		);
	}

	/**
	 * Put a migrated backup onto this site.
	 *
	 * A migration is one act, and it is not finished when the archive lands — it is
	 * finished when this site IS the other one. Landing the parts and waiting to be told
	 * to apply them would leave an operator with a backup they did not ask for and a site
	 * that did not move.
	 *
	 * The caller must have closed its own `migration` process row first: a restore refuses
	 * to start while one is live, so calling this too early refuses silently and ends the
	 * migration with nothing applied.
	 *
	 * @param string $folder Backup folder that was migrated in.
	 * @return bool Whether the restore actually started.
	 */
	public static function apply_migrated_backup( $folder ) {
		$folder = sanitize_text_field( (string) $folder );
		if ( '' === $folder ) {
			return false;
		}

		$started = ( new RestoreController() )->begin_restore( $folder );
		$ok      = isset( $started['code'] ) && 200 === (int) $started['code'];

		if ( ! $ok ) {
			// The refusal, verbatim, into the migration's own console — this is the last
			// thing that console will say, and "it stopped" without a reason leaves the
			// operator unable to tell a damaged set from a conflicting process. A refused
			// backup carries its reasons in `errors`; the message only says it was refused.
			$reasons = ( isset( $started['errors'] ) && is_array( $started['errors'] ) && ! empty( $started['errors'] ) )
				? $started['errors']
				: array( isset( $started['message'] ) ? (string) $started['message'] : __( 'unknown reason', 'tailwatch' ) );
			foreach ( $reasons as $reason ) {
				self::narrate(
					sprintf(
						/* translators: %s: the reason the restore refused to start. */
						__( 'The backup arrived but could not be applied: %s', 'tailwatch' ),
						(string) $reason
					),
					'ERROR'
				);
			}

			// Waiting only helps when another task was in the way; any other refusal would
			// refuse the same backup again.
			self::narrate(
				( isset( $started['code'] ) && 409 === (int) $started['code'] )
					? __( 'Restore it by hand from the Backup Vault when the site is free.', 'tailwatch' )
					: __( 'The backup is kept in the Backup Vault.', 'tailwatch' ),
				'WARNING'
			);

			return false;
		}

		self::narrate( __( 'Applying the backup. The rest of this runs on the Restore screen.', 'tailwatch' ) );

		return true;
	}

	/**
	 * What the import is still waiting for.
	 *
	 * @return array
	 */
	public function tailwatch_manual_import_status() {
		if ( ! current_user_can( $this->network_capability() ) ) {
			return $this->unauthorized_response();
		}

		// Reported rather than left to be discovered by pressing the button: a screen that
		// only says "not enabled" after a migration file has been chosen sounds like a
		// fault and says nothing about where the switch is. Named the way every other
		// module names it, so there is one vocabulary rather than two.
		$feature = $this->tailwatch_get_feature_status();

		// What would refuse a migration if one were started right now — the same question
		// the start asks, asked on every poll so the screen can say it before anyone
		// presses anything. Both halves of the page are the `migration` process type, so
		// this one answer covers the automatic transfer and the by-hand import alike.
		$blocked = ( new ProcessGuard() )->ensure_can_start_process( 'migration' );
		$in_way  = ( is_array( $blocked ) && ! empty( $blocked['data']['running_process'] ) )
			? (string) $blocked['data']['running_process']
			: '';
		$why     = ( '' !== $in_way && ! empty( $blocked['message'] ) ) ? (string) $blocked['message'] : '';

		$state = get_option( self::STATE_OPTION, array() );
		if ( ! is_array( $state ) || empty( $state['parts'] ) ) {
			return array(
				'code'           => 200,
				'phase'          => 'idle',
				'feature_enable' => $feature['feature_enable'],
				'parent_enable'  => $feature['parent_enable'],
				'chunk_bytes'    => self::chunk_bytes(),
				'blocked_by'     => $in_way,
				'blocked_reason' => $why,
			);
		}

		// Asked of the folder, not only of the record. A part is outstanding if it never
		// arrived OR if it is no longer there - the two are the same thing to an operator,
		// and telling them apart is what turns a part that went missing at minute five into
		// a failure at minute forty with nothing left to resume.
		$missing     = array();
		$resume      = array();
		$forgotten   = array();
		$chunk_bytes = self::chunk_bytes();
		foreach ( $state['parts'] as $part ) {
			$path = TAILWATCH_BACKUP_DIR . '/' . $part['area'] . '/' . $state['folder'] . '/' . $part['file'];

			if ( empty( $state['received'][ $part['file'] ] ) ) {
				$missing[] = $part['file'];

				// How much of it is already here. A part nobody has started costs one
				// is_file() to answer, so this stays cheap at poll rate however many parts
				// the backup has; only the one part an interrupted upload left half-sent
				// costs a walk, and a serial uploader can leave at most one of those.
				$have = $this->resumable_chunks( $path, $chunk_bytes );
				if ( $have > 0 ) {
					$resume[ $part['file'] ] = $have;
				}
				continue;
			}

			if ( ! is_file( $path ) || ( ! empty( $part['size'] ) && (int) $part['size'] !== (int) filesize( $path ) ) ) {
				// Forgotten here too, so the next poll and the upload route agree, and so
				// re-sending it is accepted rather than refused as a part already held.
				unset( $state['received'][ $part['file'] ] );
				$missing[]   = $part['file'];
				$forgotten[] = $part['file'];

				// Deliberately no resume offset for this one. assemble() deletes each piece
				// as it consumes it, so a part that got as far as being received has none
				// left, and anything that did survive would predate the file that has since
				// gone missing. It is sent again whole.
			}
		}

		if ( ! empty( $forgotten ) ) {
			$this->tailwatch_record_received( $state, array(), $forgotten );
		}

		return array(
			'code'           => 200,
			'feature_enable' => $feature['feature_enable'],
			'parent_enable'  => $feature['parent_enable'],
			'phase'          => empty( $missing ) ? 'ready' : 'receiving',
			'folder'      => (string) $state['folder'],
			'total'       => count( $state['parts'] ),
			'received'    => count( $state['parts'] ) - count( $missing ),
			'missing'     => $missing,
			'resume'      => $resume,
			'chunk_bytes' => $chunk_bytes,
			'blocked_by'     => $in_way,
			'blocked_reason' => $why,
		);
	}

	/**
	 * Take in parts that are already sitting in the backup folder.
	 *
	 * Carrying gigabytes through a browser is the weakest link in the by-hand route: the
	 * browser drops downloads, and an upload of the same size is a second chance to lose
	 * one. Anyone who can reach the server by FTP, rsync or a file manager can put the
	 * parts where they belong directly, and this lets the import notice them.
	 *
	 * Found is not trusted. Every file is put through the same check an uploaded part
	 * faces, because a file in the right place with the right name can still be truncated,
	 * from a different backup, or something else entirely.
	 *
	 * @return array
	 */
	public function tailwatch_scan_for_parts() {
		if ( ! current_user_can( $this->network_capability() ) ) {
			return $this->unauthorized_response();
		}

		// The migration switch is enforced controller-wide for every method not named in
		// $tailwatch_feature_check_exemptions, and taking parts IN is getting into a
		// migration rather than out of one - so this is deliberately not exempt.
		$state = get_option( self::STATE_OPTION, array() );
		if ( ! is_array( $state ) || empty( $state['parts'] ) ) {
			return array(
				'code'    => 409,
				'message' => __( 'There is no migration waiting for parts. Choose a migration file first.', 'tailwatch' ),
			);
		}

		$found    = array();
		$rejected = array();

		foreach ( $state['parts'] as $part ) {
			if ( ! empty( $state['received'][ $part['file'] ] ) ) {
				continue;
			}

			$path = TAILWATCH_BACKUP_DIR . '/' . $part['area'] . '/' . $state['folder'] . '/' . $part['file'];
			if ( ! is_file( $path ) ) {
				continue;
			}

			// The same rules an uploaded part is held to. A part that is here but wrong is
			// worse than one that is missing: the import would carry on and the restore
			// would fail later, when the site is already closed.
			$verified = MigrationPublisher::verify_part( $path, $part );
			if ( true !== $verified ) {
				$rejected[] = $part['file'];
				self::narrate( sprintf( '%s: %s', $part['file'], (string) $verified ), 'ERROR' );
				continue;
			}

			$state['received'][ $part['file'] ] = true;
			$found[]                            = $part['file'];
		}

		if ( ! empty( $found ) ) {
			$recorded = $this->tailwatch_record_received( $state, $found );
			if ( ! is_array( $recorded ) ) {
				return array(
					'code'    => 409,
					'message' => __( 'This migration was discarded while its folder was being checked.', 'tailwatch' ),
				);
			}
			$state = $recorded;

			self::narrate(
				sprintf(
					/* translators: %d: number of parts found on disk. */
					_n( 'Found %d part already on this site.', 'Found %d parts already on this site.', count( $found ), 'tailwatch' ),
					count( $found )
				)
			);
		}

		$missing = array();
		foreach ( $state['parts'] as $part ) {
			if ( empty( $state['received'][ $part['file'] ] ) ) {
				$missing[] = $part['file'];
			}
		}

		// Nothing left to upload, so nothing else would finish it: the screen only sends the
		// parts still missing, and the status poll only reports.
		if ( empty( $missing ) ) {
			return array_merge(
				$this->tailwatch_finish_import( $state ),
				array(
					'found'    => $found,
					'rejected' => $rejected,
				)
			);
		}

		return array(
			'code'     => 200,
			'message'  => empty( $found )
				? __( 'No new parts were found in this site\'s backup folder.', 'tailwatch' )
				: sprintf(
					/* translators: %d: number of parts taken in. */
					_n( '%d part was already here and has been taken in.', '%d parts were already here and have been taken in.', count( $found ), 'tailwatch' ),
					count( $found )
				),
			'found'    => $found,
			'rejected' => $rejected,
			'missing'  => $missing,
			'total'    => count( $state['parts'] ),
			'received' => count( $state['parts'] ) - count( $missing ),
			'phase'    => empty( $missing ) ? 'ready' : 'receiving',
		);
	}

	/**
	 * Abandon an import and remove what it half-received.
	 *
	 * @return array
	 */
	public function tailwatch_cancel_manual_import() {
		if ( ! current_user_can( $this->network_capability() ) ) {
			return $this->unauthorized_response();
		}

		$state = get_option( self::STATE_OPTION, array() );
		if ( is_array( $state ) && ! empty( $state['folder'] ) ) {
			foreach ( array( 'files', 'database' ) as $tree ) {
				$dir = TAILWATCH_BACKUP_DIR . '/' . $tree . '/' . $state['folder'];
				if ( is_dir( $dir ) ) {
					$this->remove_directory( $dir );
				}
			}
		}

		// Closing the row is the whole point of discarding: an abandoned import that keeps
		// saying "running" refuses every other operation until the stuck sweep clears it.
		if ( is_array( $state ) && ! empty( $state['process_id'] ) ) {
			$this->process_manager->mark_completed( $state['process_id'] );
		}

		delete_option( self::STATE_OPTION );

		self::narrate( __( 'The part-finished migration was discarded.', 'tailwatch' ), 'WARNING' );

		return array(
			'code'    => 200,
			'message' => __( 'The part-finished migration was discarded.', 'tailwatch' ),
		);
	}

	/**
	 * Keep the folder an import is filling out of the backup cleanup's reach.
	 *
	 * It is catalogued only when the last part is sealed, so until then the half-hourly sweep
	 * of uncatalogued backup folders cannot tell it from a failed backup's leftovers. Static,
	 * and registered at bootstrap, because that sweep runs in a cron request where this
	 * controller is never constructed.
	 *
	 * @param array $folders Folder name => true.
	 * @return array
	 */
	public static function protect_import_folder( $folders ) {
		$folders = is_array( $folders ) ? $folders : array();

		$state = get_option( self::STATE_OPTION, array() );
		if ( is_array( $state ) && ! empty( $state['folder'] ) ) {
			$folders[ (string) $state['folder'] ] = true;
		}

		return $folders;
	}

	/**
	 * Mark parts received, or forget them, on the import as it stands now, not as this
	 * request found it.
	 *
	 * The upload of a part's last chunk and the folder scan both read the import at their
	 * start, then spend seconds - joining and fingerprinting a part, or fingerprinting every
	 * part found. Writing their copy back would undo a Discard pressed meanwhile, and drop
	 * any part another request had recorded in between. The status poll and the final check for
	 * vanished parts forget parts the same way, so they go through here too. The cached
	 * option is dropped first: get_option() would otherwise hand back this request's own
	 * copy rather than what is stored.
	 *
	 * @param array $state  Import as this request read it.
	 * @param array $files  Part file names to mark received.
	 * @param array $forget Part file names that are no longer on disk.
	 * @return array|string The import with the change made; 'cancelled' when there is no
	 *                      import any more, 'replaced' when a different one has started.
	 */
	private function tailwatch_record_received( array $state, array $files, array $forget = array() ) {
		wp_cache_delete( self::STATE_OPTION, 'options' );
		$current = get_option( self::STATE_OPTION, array() );

		if ( ! is_array( $current ) || empty( $current['parts'] ) ) {
			return 'cancelled';
		}
		if ( ( $current['folder'] ?? '' ) !== ( $state['folder'] ?? '' ) || ( $current['started'] ?? 0 ) !== ( $state['started'] ?? 0 ) ) {
			return 'replaced';
		}

		foreach ( $files as $file ) {
			$current['received'][ $file ] = true;
		}
		foreach ( $forget as $file ) {
			unset( $current['received'][ $file ] );
		}
		update_option( self::STATE_OPTION, $current, false );

		return $current;
	}

	/**
	 * How many leading chunks of a part are already here and safe to skip.
	 *
	 * Counts only from zero, only without a gap, and only pieces exactly one chunk long.
	 * Every chunk but the last is full-size, so a short piece is a half-written one from a
	 * request that died mid-write; skipping past it would splice a hole into the assembled
	 * part. The last piece is never counted either, because a part of N full chunks and one
	 * of N full chunks plus a short tail cannot be told apart from here.
	 *
	 * A chunk size that no longer matches what those pieces were written at fails at the
	 * first of them and answers zero, so the part is sent again from the beginning.
	 *
	 * @param string $target      Final path of the part.
	 * @param int    $chunk_bytes Size one whole chunk is expected to be.
	 * @return int
	 */
	private function resumable_chunks( $target, $chunk_bytes ) {
		$chunk_bytes = (int) $chunk_bytes;
		if ( $chunk_bytes < 1 ) {
			return 0;
		}

		// The same request has already stat'd some of these paths, and a cached answer here
		// would describe a file as it was earlier in the request rather than as it is.
		clearstatcache();

		$have = 0;
		while ( $have < self::MAX_CHUNKS ) {
			$piece = $target . '.part' . $have;
			if ( ! is_file( $piece ) || $chunk_bytes !== (int) filesize( $piece ) ) {
				break;
			}
			++$have;
		}

		return $have;
	}

	/**
	 * Join a part's chunks into the finished file.
	 *
	 * @param string $target Final path.
	 * @param int    $chunks How many chunks to expect.
	 * @return true|string
	 */
	private function assemble( $target, $chunks ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- streaming an assembly; WP_Filesystem reads whole files into memory, which is the thing chunking exists to avoid.
		$out = @fopen( $target, 'wb' );
		if ( false === $out ) {
			return __( 'The uploaded part could not be assembled on this site.', 'tailwatch' );
		}

		for ( $i = 0; $i < $chunks; $i++ ) {
			$piece = $target . '.part' . $i;
			if ( ! file_exists( $piece ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- closes the handle opened above.
				fclose( $out );
				wp_delete_file( $target );
				return __( 'A chunk of that part never arrived, so it was discarded. Upload it again.', 'tailwatch' );
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- as above.
			$in = @fopen( $piece, 'rb' );
			if ( false === $in ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- closes the handle opened above.
				fclose( $out );
				wp_delete_file( $target );
				return __( 'A chunk of that part could not be read back, so it was discarded.', 'tailwatch' );
			}

			stream_copy_to_stream( $in, $out );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- closes the handle opened above.
			fclose( $in );
			wp_delete_file( $piece );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- closes the handle opened above.
		fclose( $out );

		// Pieces above the set just consumed belong to an earlier attempt that sliced this
		// same part into more of them - a site whose upload limit changed between tries.
		// Nothing will ever read them, and left alone they sit in the backup folder until
		// the whole import is discarded. They run contiguously upward, because pieces are
		// only ever written from zero, so the first gap is the end of them.
		for ( $i = $chunks; $i < self::MAX_CHUNKS; $i++ ) {
			$stray = $target . '.part' . $i;
			if ( ! file_exists( $stray ) ) {
				break;
			}
			wp_delete_file( $stray );
		}

		return true;
	}

	/**
	 * Store one arriving chunk at the path its part is assembled from.
	 *
	 * Through wp_handle_upload(), with its behaviour set for this call only:
	 *
	 * - `upload_dir` points at the part's folder in the backup tree until the call returns. A
	 *   chunk is a slice of a backup holding a database and a site's configuration, so it
	 *   never passes through the uploads folder, where files are reachable by address. Only
	 *   the path moves: the uploads base stays, so a network's upload quota is still measured
	 *   against the uploads folder rather than against the migration.
	 * - The name is the one given. A resent chunk has to replace its own piece and resuming
	 *   counts pieces by index, so a renamed copy is a piece nothing reads; and
	 *   sanitize_file_name() turns `.sql.zip` into `.sql_.zip`.
	 * - `test_type` is off. A chunk is an arbitrary byte range of an archive or a dump, so only
	 *   the first has a header to recognise; sniffing the rest refuses sound pieces at random,
	 *   and `.sql` is not an upload type at all. What that check guards against is covered
	 *   here instead: every segment of the destination is validated - the folder by
	 *   is_safe_folder(), the file name through is_safe_part_name() and expected_part(), its
	 *   kind by is_allowed_part() - the piece carries a `.partN` suffix nothing executes, the
	 *   folder is sealed with deny files, and the joined part must match the size and digest
	 *   in the migration file before anything uses it.
	 * - `test_form` is off: the dispatcher has verified the nonce and the capability.
	 *
	 * @param array  $upload The chunk as PHP received it.
	 * @param array  $part   Manifest entry the chunk belongs to.
	 * @param string $dir    Folder the part is assembled in.
	 * @param string $piece  Path the chunk is stored at.
	 * @param string $action Upload action for wp_handle_upload(). A command-line test passes
	 *                       another, as WordPress's own tests do, since nothing there is an
	 *                       upload.
	 * @return true|array True once the chunk is at $piece; otherwise the answer to send.
	 */
	private function store_chunk( array $upload, array $part, $dir, $piece, $action = 'wp_handle_upload' ) {
		if ( ! self::is_allowed_part( $part ) ) {
			return array(
				'code'    => 400,
				'message' => __( 'That file is not a kind of backup part this site accepts.', 'tailwatch' ),
			);
		}

		if ( ! wp_mkdir_p( $dir ) ) {
			return array(
				'code'    => 500,
				'message' => __( 'The backup folder could not be created on this site.', 'tailwatch' ),
			);
		}
		// Every level, the way a backup run seals its own folders.
		foreach ( array( TAILWATCH_BACKUP_DIR, dirname( $dir ), $dir ) as $level ) {
			SecureDirectoryService::ensure( $level, true );
		}

		if ( ! function_exists( 'wp_handle_upload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$name           = wp_basename( $piece );
		$upload['name'] = $name;

		$into_folder = function ( $uploads ) use ( $dir ) {
			$uploads['path']   = $dir;
			$uploads['url']    = '';
			$uploads['subdir'] = '';
			$uploads['error']  = false;
			return $uploads;
		};

		add_filter( 'upload_dir', $into_folder, PHP_INT_MAX );
		try {
			$stored = wp_handle_upload(
				$upload,
				array(
					'action'                   => $action,
					'test_form'                => false,
					'test_type'                => false,
					'unique_filename_callback' => function () use ( $name ) {
						return $name;
					},
				)
			);
		} finally {
			remove_filter( 'upload_dir', $into_folder, PHP_INT_MAX );
		}

		$landed = ( is_array( $stored ) && ! empty( $stored['file'] ) ) ? wp_normalize_path( (string) $stored['file'] ) : '';
		if ( wp_normalize_path( $piece ) === $landed && is_file( $piece ) ) {
			return true;
		}

		// Renamed on the way by something hooked into the upload: a piece under another name
		// is one the assembly never reads.
		if ( '' !== $landed && wp_normalize_path( dirname( $landed ) ) === wp_normalize_path( $dir ) ) {
			wp_delete_file( $landed );
		}

		self::narrate(
			sprintf(
				'%s: %s',
				$name,
				( is_array( $stored ) && ! empty( $stored['error'] ) )
					? (string) $stored['error']
					: __( 'the upload handler did not store it where it was sent.', 'tailwatch' )
			),
			'ERROR'
		);

		return array(
			'code'    => 500,
			'message' => __( 'That chunk could not be written on this site.', 'tailwatch' ),
		);
	}

	/**
	 * Whether a folder holds nothing but the deny files it was sealed with.
	 *
	 * @param string $dir Folder.
	 * @return bool
	 */
	private static function holds_only_seal( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return false;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_scandir -- the plugin's own backup folder; dirlist() builds a heavier structure for the same answer.
		return array() === array_diff( (array) scandir( $dir ), array( '.', '..', '.htaccess', 'index.php', 'web.config' ) );
	}

	/**
	 * The manifest entry for a file name, or null when it was never expected.
	 *
	 * @param array  $state Import state.
	 * @param string $file  File name as sent.
	 * @return array|null
	 */
	private function expected_part( array $state, $file ) {
		if ( '' === $file ) {
			return null;
		}

		foreach ( $state['parts'] as $part ) {
			if ( $part['file'] === $file ) {
				return $part;
			}
		}

		return null;
	}

	/**
	 * How many parts are still missing.
	 *
	 * @param array $state Import state.
	 * @return int
	 */
	private function outstanding( array $state ) {
		$missing = 0;
		foreach ( $state['parts'] as $part ) {
			if ( empty( $state['received'][ $part['file'] ] ) ) {
				++$missing;
			}
		}

		return $missing;
	}

	/**
	 * Whether a part's file name is safe to use exactly as given.
	 *
	 * `sanitize_file_name()` is the wrong tool here. It REWRITES a name rather than
	 * judging it: it turns `db_parent_tables_1.sql.zip` into `db_parent_tables_1.sql_.zip`,
	 * because the inner `.sql` is not a registered upload type. Rejecting a name it
	 * changes would therefore reject our own dumps, and storing what it returned would
	 * store a name matching no file on disk.
	 *
	 * Those dumps are named `.sql.zip` on purpose. Plenty of hosts refuse to serve or
	 * accept a bare `.sql` download, and the suffix is what gets a database part past
	 * them - so the name is a compatibility measure, not a mistake.
	 *
	 * What actually matters is that the name cannot escape the folder it is written into,
	 * since the destination is built as backup-dir/area/folder/NAME. So this asks exactly
	 * that and nothing more: one path segment, no traversal, no control characters, and a
	 * conservative character set.
	 *
	 * @param string $name Candidate file name.
	 * @return bool
	 */
	public static function is_safe_part_name( $name ) {
		$name = (string) $name;

		if ( '' === $name || strlen( $name ) > 255 ) {
			return false;
		}

		// One segment only. wp_basename() strips any directory, so a name carrying one
		// comes back different - which is the whole test for a separator, on either slash.
		if ( $name !== wp_basename( $name ) ) {
			return false;
		}

		// `..` never appears in a name we write, and is the only way out of the folder
		// that survives the check above.
		if ( false !== strpos( $name, '..' ) ) {
			return false;
		}

		// A leading dot hides the file and is never something a backup produces.
		if ( 0 === strpos( $name, '.' ) ) {
			return false;
		}

		// Control characters, including the null byte that truncates a path in C.
		if ( preg_match( '/[\x00-\x1F\x7F]/', $name ) ) {
			return false;
		}

		return 1 === preg_match( '/^[A-Za-z0-9._-]+$/', $name );
	}

	/**
	 * Whether a part is a kind of file a backup writes for its area.
	 *
	 * File parts are zip archives and database parts are dumps named `.sql` or `.sql.zip`
	 * (see is_safe_part_name()); nothing else is ever exported, so nothing else is accepted.
	 * The stem carries no dot of its own: folder keys and table names never have one, and a
	 * second extension is what gets a file run by some servers.
	 *
	 * @param array $part Manifest entry.
	 * @return bool
	 */
	private static function is_allowed_part( array $part ) {
		$file    = ( isset( $part['file'] ) && is_string( $part['file'] ) ) ? $part['file'] : '';
		$pattern = ( isset( $part['area'] ) && 'database' === $part['area'] )
			? '/^[A-Za-z0-9_-]+\.sql(\.zip)?\z/'
			: '/^[A-Za-z0-9_-]+\.zip\z/';

		return 1 === preg_match( $pattern, $file );
	}

	/**
	 * Why a manifest cannot be used, or null when it can.
	 *
	 * @param mixed $manifest Decoded manifest.
	 * @return string|null
	 */
	private function manifest_problem( $manifest ) {
		if ( ! is_array( $manifest ) ) {
			return __( 'That does not look like a Tailwatch migration file.', 'tailwatch' );
		}

		foreach ( array( 'folder', 'site_url', 'prefix', 'parts' ) as $field ) {
			if ( ! isset( $manifest[ $field ] ) ) {
				return __( 'That migration file is missing information this site needs. Export it again from the source site.', 'tailwatch' );
			}
		}

		if ( ! is_string( $manifest['prefix'] ) ) {
			return __( 'That migration file is missing information this site needs. Export it again from the source site.', 'tailwatch' );
		}

		if ( ! $this->is_safe_folder( (string) $manifest['folder'] ) ) {
			return __( 'That migration file names a backup folder this site will not accept.', 'tailwatch' );
		}

		// Almost always the operator is simply standing on the wrong site: they exported
		// here, and are now importing here too. Saying so beats letting them upload every
		// part before anything reads as wrong.
		if ( MigrationPublisher::is_this_site( (string) $manifest['site_url'] ) ) {
			return __( 'That migration file was exported from this site, so there is nothing to bring in. Load it on the site you are moving to instead.', 'tailwatch' );
		}

		if ( ! is_array( $manifest['parts'] ) || empty( $manifest['parts'] ) ) {
			return __( 'That migration file lists no backup parts.', 'tailwatch' );
		}

		$has_database = false;

		foreach ( $manifest['parts'] as $part ) {
			if ( ! is_array( $part ) || empty( $part['file'] ) || empty( $part['area'] ) ) {
				return __( 'That migration file describes a part this site cannot read.', 'tailwatch' );
			}
			// Judged, not rewritten - see is_safe_part_name(). Asking whether
			// sanitize_file_name() would change it would reject every `.sql.zip`
			// database part.
			if ( ! self::is_safe_part_name( $part['file'] ) || ! self::is_allowed_part( $part ) ) {
				return __( 'That migration file names a part this site will not accept.', 'tailwatch' );
			}

			if ( 'database' === $part['area'] ) {
				$has_database = true;
			}
		}

		// The restore asks the same question, but only once every part has been uploaded.
		// A set with no database has no table names to fit.
		if ( $has_database ) {
			return RestorePrefix::refusal( $manifest['prefix'] );
		}

		return null;
	}

	/**
	 * Whether a backup folder name is one this site will write to.
	 *
	 * Deliberately strict: the name becomes a directory under the backup tree, so anything
	 * that is not the shape a backup folder actually has is refused rather than cleaned up.
	 *
	 * @param string $folder Folder name.
	 * @return bool
	 */
	private function is_safe_folder( $folder ) {
		return 1 === preg_match( '/^[A-Za-z0-9_\-]{1,64}\z/', (string) $folder );
	}

	/**
	 * Remove a directory and its contents.
	 *
	 * @param string $dir Directory.
	 * @return void
	 */
	private function remove_directory( $dir ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_scandir -- the plugin's own backup folder; dirlist() builds a heavier structure for the same answer.
		foreach ( (array) scandir( $dir ) as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			$path = $dir . '/' . $entry;
			if ( is_dir( $path ) ) {
				$this->remove_directory( $path );
			} else {
				wp_delete_file( $path );
			}
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- removing the plugin's own discarded transfer folder.
		@rmdir( $dir );
	}

	/**
	 * Decode a dispatcher payload, which arrives as a JSON string.
	 *
	 * The payload reaches here already unslashed — the AJAX handler strips the slashes
	 * WordPress adds to $_POST before the dispatcher re-encodes it. Unslashing a second
	 * time would strip the escaping INSIDE the JSON, which is invisible for a flat
	 * payload and fatal for this one: a migration file pasted as text arrives as a JSON
	 * string nested in a JSON string, and losing one layer of escaping turns it into
	 * something that no longer parses. The fallback covers any caller that really does
	 * hand over slashed input, without corrupting one that does not.
	 *
	 * @param string|array $post_data Payload.
	 * @return array
	 */
	private function decode( $post_data ) {
		if ( is_array( $post_data ) ) {
			return $post_data;
		}
		if ( ! is_string( $post_data ) || '' === $post_data ) {
			return array();
		}

		$decoded = json_decode( $post_data, true );
		if ( ! is_array( $decoded ) ) {
			$decoded = json_decode( wp_unslash( $post_data ), true );
		}

		return is_array( $decoded ) ? $decoded : array();
	}
}
