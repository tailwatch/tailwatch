<?php
/**
 * Tailwatch - Restore Orchestrator
 *
 * @package    Tailwatch
 * @subpackage Admin\App\Api\Services\Restore
 * @author     WP Tailwatch
 * @copyright  2025-2026 WP TAILWATCH LLC
 * @license    GPL-2.0+
 * @since      1.0.4
 */

namespace Tailwatch\Admin\App\Api\Services\Restore;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Tailwatch\Admin\App\Api\Controllers\Backup\BackupController;
use Tailwatch\Admin\App\Api\Controllers\Features\FeatureCacheController;
use Tailwatch\Admin\App\Api\Controllers\Features\OptionsController;
use Tailwatch\Admin\App\Api\Controllers\Email\Smtp\SmtpConfigurationController;
use Tailwatch\Admin\App\Api\Controllers\Restore\RestoreController;
use Tailwatch\Admin\App\Api\Logging\Log;
use Tailwatch\Admin\App\Api\Models\DBModel;
use Tailwatch\Admin\App\Api\Models\IntegrityWatch\FileMonModel;
use Tailwatch\Admin\App\Api\Services\Auth\JwtService;
use Tailwatch\Admin\App\Api\Controllers\Verification\VerifyStatusController;
use Tailwatch\Admin\App\Api\Services\Crypto\AppSecretService;
use Tailwatch\Admin\App\Api\Services\Backup\RestoreDatabase;
use Tailwatch\Admin\App\Api\Services\Backup\RestorePrefix;
use Tailwatch\Admin\App\Api\Services\TickBudget;
use Tailwatch\Admin\App\Api\Services\Backup\RestoreExtractor;
use Tailwatch\Admin\App\Api\Services\Backup\BackupReconciler;
use Tailwatch\Admin\App\Api\Services\Backup\RestoreSqlImporter;
use Tailwatch\Admin\App\Api\Services\Backup\RestoreValidator;
use Tailwatch\Admin\App\Api\Services\Common\SecureDirectoryService;
use Tailwatch\Admin\App\Api\Services\ZipUtility\ArchiveReader;
use Throwable;

/**
 * Sequences a restore across ticks, and decides what happens when one fails.
 *
 * One unit of work per tick, state in, state out. Nothing here schedules or persists —
 * the caller owns both — which is what makes the whole sequence testable without cron.
 *
 * ## Why the database is imported before the files are touched
 *
 * Restoring files first is the usual order. It comes from designs where importing a dump
 * writes straight into the live database, making it destructive and therefore something
 * to do last.
 *
 * Ours is not. RestoreSqlImporter writes into staging tables, so the entire import is
 * non-destructive — the live site keeps serving its own data throughout. Doing it first
 * means a bad dump, a disk-full, or a schema error is discovered while the site is still
 * completely untouched, instead of after its files have already been overwritten.
 *
 * Files are handled the same way. They are extracted into a staging folder in Tailwatch's
 * own storage, then switched in by RestoreSwitch: the site's own copies are moved aside, not
 * deleted, so the switch and the cutover are the only steps that change the site, and
 * both can be undone:
 *
 *     VERIFY -> ENGAGE -> IMPORT (staging) -> EXTRACT (staging) -> PRUNE (switch) -> SWAP (atomic) -> FINALIZE
 *
 * ## What a failure costs, by phase
 *
 * - VERIFY  — nothing touched. The backup is simply refused.
 * - IMPORT  — nothing touched. Staging is discarded and the site never noticed.
 * - EXTRACT — nothing touched. The staging folder is deleted.
 * - PRUNE   — the switch is rolled back from the aside folder and staging is discarded.
 * - SWAP    — atomic: it either happened or it did not. If it did not, the file switch
 *             is rolled back too.
 * - After   — the site is the backup. The tables the cutover renamed aside are dropped
 *             once it is serving again; they exist only because one RENAME cannot put
 *             staging in place without first moving live out of the way.
 */
class RestoreOrchestrator {

	const PHASE_VERIFY   = 'verify';
	const PHASE_ENGAGE   = 'engage';
	const PHASE_IMPORT   = 'import';
	const PHASE_REWRITE  = 'rewrite';
	const PHASE_EXTRACT  = 'extract';
	const PHASE_PRUNE    = 'prune';
	const PHASE_SWAP     = 'swap';
	const PHASE_FINALIZE = 'finalize';
	const PHASE_DONE     = 'done';
	const PHASE_FAILED   = 'failed';

	/**
	 * Cleanup passes that may end with something left behind before a finished restore stops
	 * trying. The site is restored and open by then.
	 */
	const CLEANUP_STUCK_PASSES = 5;

	/**
	 * A restore puts back the whole backup. A backup already knows what it holds - one
	 * without a dump or without archives - and the phases skip an absent half on their own.
	 */
	const SCOPE_FULL = 'full';

	/**
	 * Every scope a restore may be asked for on this site: the whole backup, first and
	 * never removable, then any narrower scope added through `tailwatch_restore_scopes` by
	 * whoever also carries it out (see narrow()). Only clean, distinct names are kept.
	 *
	 * @return string[]
	 */
	public static function scopes() {
		$offered = apply_filters( 'tailwatch_restore_scopes', array( self::SCOPE_FULL ) );
		$scopes  = array( self::SCOPE_FULL );

		foreach ( is_array( $offered ) ? $offered : array() as $scope ) {
			if ( is_string( $scope ) && '' !== $scope && sanitize_key( $scope ) === $scope && ! in_array( $scope, $scopes, true ) ) {
				$scopes[] = $scope;
			}
		}

		return $scopes;
	}

	/**
	 * A fresh restore state.
	 *
	 * @param string $folder_date Backup to restore from.
	 * @param string $scope       How much of it to put back; one of scopes().
	 * @return array
	 */
	public static function begin( $folder_date, $scope = self::SCOPE_FULL ) {
		return array(
			'folder_date'   => (string) $folder_date,
			// Decided when the restore starts and acted on at verify. Anything unrecognised
			// is read as a full restore: a scope that arrives mangled must not silently
			// narrow what gets put back.
			'scope'         => in_array( $scope, self::scopes(), true ) ? $scope : self::SCOPE_FULL,
			'phase'         => self::PHASE_VERIFY,
			'archives'      => array(),
			'archive_index' => 0,
			'entry_offset'  => 0,
			'chain'         => array(),
			'deletions'     => array(),
			'prune_offset'  => 0,
			// Named at verify: where the backup's files are prepared, and where the site's own
			// copies are moved aside while the switch runs.
			'staging'       => '',
			'aside'         => '',
			'switch_ops'    => array(),
			'switch_index'  => 0,
			'switch_kept'   => 0,
			// Captured now, because the swap replaces wp_options: afterwards site_url()
			// returns the address recorded in the BACKUP, and the address the site is
			// actually served from is no longer recoverable from anywhere.
			'live_site_url' => site_url(),
			// The stored options, for putting the address back straight after the swap.
			'live_addresses' => array(
				'siteurl' => (string) get_option( 'siteurl' ),
				'home'    => (string) get_option( 'home' ),
			),
			'sql_files'     => array(),
			'sql_index'     => 0,
			'sql_offset'    => 0,
			'tables'        => array(),
			// Set at verify, and only for a backup whose database was taken under another
			// table prefix than this site's.
			'prefix'        => array(),
			// Links between the backup's tables, as the importer staged them: the name each
			// carries until the restore is over, and the one it takes then.
			'links'         => array(),
			'rewrite'       => array(),
			'warnings'      => array(),
			'errors'        => array(),
			'destructive'   => false,
			// Captured before the cutover for the same reason as live_site_url: wp_options
			// carries the plugin's credential state, and after the rename it is the
			// backup's copy — the live one is not recoverable from anywhere.
			'auth_carry'    => self::capture_auth_state(),
			'started'       => time(),
		);
	}

	/**
	 * Advance a restore by one tick.
	 *
	 * @param array $state    State from begin() or a previous tick.
	 * @param int   $deadline Unix timestamp to stop at (0 = no limit).
	 * @return array Updated state.
	 */
	public static function tick( array $state, $deadline = 0 ) {
		if ( ! isset( $state['phase'] ) || in_array( $state['phase'], array( self::PHASE_DONE, self::PHASE_FAILED ), true ) ) {
			return $state;
		}

		// Keep the gate and the boot guard alive on every tick, so a long restore is never
		// mistaken for an abandoned one by the TTLs that protect against exactly the opposite case.
		if ( $state['destructive'] || self::PHASE_VERIFY !== $state['phase'] ) {
			MaintenanceMode::heartbeat();
			PostRestoreGuard::heartbeat();
		}

		// Narrated around the dispatch rather than inside each phase: one place that
		// cannot forget a phase, and a phase that ends early on a deadline says nothing
		// rather than repeating itself every tick.
		$was      = (string) $state['phase'];
		$warnings = isset( $state['warnings'] ) ? count( (array) $state['warnings'] ) : 0;
		$errors   = isset( $state['errors'] ) ? count( (array) $state['errors'] ) : 0;

		switch ( $state['phase'] ) {
			case self::PHASE_VERIFY:
				$next = self::do_verify( $state );
				break;

			case self::PHASE_ENGAGE:
				$next = self::do_engage( $state );
				break;

			case self::PHASE_IMPORT:
				$next = self::do_import( $state, $deadline );
				break;
			case self::PHASE_REWRITE:
				$next = self::do_rewrite( $state, $deadline );
				break;

			case self::PHASE_EXTRACT:
				$next = self::do_extract( $state, $deadline );
				break;

			case self::PHASE_PRUNE:
				$next = self::do_prune( $state, $deadline );
				break;

			case self::PHASE_SWAP:
				$next = self::do_swap( $state );
				break;

			case self::PHASE_FINALIZE:
				$next = self::do_finalize( $state, $deadline );
				break;

			default:
				return self::fail( $state, __( 'Unknown restore phase.', 'tailwatch' ) );
		}

		self::narrate_tick( $next, $was, $warnings, $errors );

		return $next;
	}

	/**
	 * Tell the operator what just happened, in the words they see on screen.
	 *
	 * @param array  $state        State after the tick.
	 * @param string $was          Phase before it.
	 * @param int    $had_warnings Warning count before it.
	 * @param int    $had_errors   Error count before it.
	 * @return void
	 */
	private static function narrate_tick( array $state, $was, $had_warnings, $had_errors ) {
		$now = isset( $state['phase'] ) ? (string) $state['phase'] : '';

		// Anything newly recorded, verbatim — these are already written for a person.
		foreach ( array_slice( (array) ( $state['errors'] ?? array() ), $had_errors ) as $error ) {
			RestoreController::narrate( $state, (string) $error, 'ERROR' );
		}
		foreach ( array_slice( (array) ( $state['warnings'] ?? array() ), $had_warnings ) as $warning ) {
			RestoreController::narrate( $state, (string) $warning, 'WARNING' );
		}

		if ( $now === $was ) {
			return; // still working through the same phase; nothing new to say.
		}

		$said = self::phase_message( $now, $state );
		if ( null !== $said ) {
			RestoreController::narrate( $state, $said, self::PHASE_FAILED === $now ? 'ERROR' : 'INFO' );
		}
	}

	/**
	 * What to say on entering a phase, for the halves this restore actually covers.
	 *
	 * The phases run for every restore, but a database-only restore replaces no files and a
	 * files-only one loads and switches no database. One fixed line per phase would tell the
	 * owner of a files-only restore that the database was being loaded and switched, when
	 * nothing had touched it.
	 *
	 * @param string $phase The phase just entered.
	 * @param array  $state Restore state; `sql_files` and `archives` are empty for the half
	 *                      this restore leaves alone.
	 * @return string|null Null when the phase does nothing for this restore.
	 */
	public static function phase_message( $phase, array $state ) {
		$database = ! empty( $state['sql_files'] );
		$files    = ! empty( $state['archives'] );

		switch ( (string) $phase ) {
			case self::PHASE_ENGAGE:
				return __( 'The backup checks out. Closing the site to visitors before anything is replaced.', 'tailwatch' );

			case self::PHASE_IMPORT:
				return $database
					? __( 'Site closed. Loading the database from the backup alongside the current one.', 'tailwatch' )
					: __( 'Site closed. Only files are being restored, so the database is left as it is.', 'tailwatch' );

			case self::PHASE_EXTRACT:
				if ( ! $files ) {
					return __( 'Database loaded and verified. No files are being restored.', 'tailwatch' );
				}
				return $database
					? __( 'Database loaded and verified. Preparing the backup\'s files beside the site.', 'tailwatch' )
					: __( 'Preparing the backup\'s files beside the site.', 'tailwatch' );

			case self::PHASE_PRUNE:
				return $files ? __( 'Files prepared. Switching them into place; the site\'s own copies are moved aside, not deleted.', 'tailwatch' ) : null;

			case self::PHASE_SWAP:
				return $database
					? __( 'Switching the site over to the restored database.', 'tailwatch' )
					: __( 'Files in place. Finishing the restore.', 'tailwatch' );

			case self::PHASE_FINALIZE:
				return $database
					? __( 'Database switched. Putting the site back online.', 'tailwatch' )
					: __( 'Putting the site back online.', 'tailwatch' );

			case self::PHASE_DONE:
				return __( 'Restore complete. The site is open again.', 'tailwatch' );

			case self::PHASE_FAILED:
				return __( 'The restore stopped.', 'tailwatch' );
		}

		return null;
	}

	/**
	 * Hand a scope narrower than the whole backup to whoever offered it.
	 *
	 * The answer names the parts to keep, and the phases then run as for any backup, since
	 * they already skip an empty half. It may only take parts away: anything it names that
	 * the backup did not contribute is refused, and so is a narrower scope nobody answers
	 * for, because widening it would replace what the operator chose to keep. With no
	 * files coming back, no file is deleted either. Runs at verify, before anything is
	 * touched.
	 *
	 * @param array $state State after the backup was verified.
	 * @return array
	 */
	private static function narrow( array $state ) {
		$scope = isset( $state['scope'] ) ? (string) $state['scope'] : self::SCOPE_FULL;
		if ( self::SCOPE_FULL === $scope ) {
			return $state;
		}

		$parts  = array(
			'archives'  => array_values( (array) $state['archives'] ),
			'sql_files' => array_values( (array) $state['sql_files'] ),
			'deletions' => array_values( (array) $state['deletions'] ),
		);
		$answer = apply_filters( 'tailwatch_restore_narrow', null, $scope, $parts );

		if ( is_array( $answer ) && isset( $answer['error'] ) && is_string( $answer['error'] ) && '' !== $answer['error'] ) {
			$state['errors'] = array( sanitize_text_field( $answer['error'] ) );
			$state['phase']  = self::PHASE_FAILED;
			return $state;
		}

		$kept = is_array( $answer ) ? $answer : array();
		foreach ( $parts as $key => $had ) {
			if ( ! isset( $kept[ $key ] ) || ! is_array( $kept[ $key ] ) ) {
				$kept = null;
				break;
			}
			$kept[ $key ] = array_values( $kept[ $key ] );
			foreach ( $kept[ $key ] as $part ) {
				if ( ! in_array( $part, $had, true ) ) {
					$kept = null;
					break 2;
				}
			}
		}

		if ( null === $kept || ( empty( $kept['archives'] ) && empty( $kept['sql_files'] ) ) ) {
			$state['errors'] = array( __( 'This restore was asked to put back only part of the backup, and that part could not be prepared. Nothing was changed.', 'tailwatch' ) );
			$state['phase']  = self::PHASE_FAILED;
			return $state;
		}

		$state['archives']  = $kept['archives'];
		$state['sql_files'] = $kept['sql_files'];
		$state['deletions'] = empty( $kept['archives'] ) ? array() : $kept['deletions'];

		foreach ( ( isset( $kept['warnings'] ) && is_array( $kept['warnings'] ) ) ? $kept['warnings'] : array() as $warning ) {
			if ( is_string( $warning ) && '' !== $warning ) {
				$state['warnings'][] = sanitize_text_field( $warning );
			}
		}

		return $state;
	}

	/**
	 * Refuse anything we cannot prove is intact, before touching the site.
	 *
	 * @param array $state State.
	 * @return array
	 */
	private static function do_verify( array $state ) {
		RestoreController::narrate(
			$state,
			sprintf(
				/* translators: %s: the backup's folder name. */
				__( 'Checking backup %s before anything is replaced.', 'tailwatch' ),
				(string) $state['folder_date']
			)
		);

		// Chain-aware: a full backup is a chain of one, so there is no separate path for
		// the ordinary case and therefore no second place for the rules to drift.
		$report = RestoreValidator::validate_chain( $state['folder_date'] );

		$state['warnings'] = $report['warnings'];

		if ( empty( $report['ok'] ) ) {
			// Nothing has been touched, so this is a clean refusal rather than a failure
			// needing cleanup.
			$state['errors'] = $report['errors'];
			$state['phase']  = self::PHASE_FAILED;
			return $state;
		}

		$state['archives']  = $report['archives'];
		$state['chain']     = isset( $report['chain'] ) ? $report['chain'] : array();
		$state['deletions'] = isset( $report['deletions'] ) ? $report['deletions'] : array();

		// Only the target's dump. The database side of a backup is never incremental —
		// every run writes a complete dump — so importing an ancestor's would replace the
		// newest data with older data that the file set does not match.
		$state['sql_files'] = self::sql_files( $state['folder_date'] );

		$state = self::narrow( $state );
		if ( self::PHASE_FAILED === $state['phase'] ) {
			return $state;
		}

		// After narrowing, so a restore that leaves the files out is not refused over them.
		$misplaced = self::layout_errors( $report, (array) $state['archives'] );
		if ( ! empty( $misplaced ) ) {
			$state['errors'] = $misplaced;
			$state['phase']  = self::PHASE_FAILED;
			return $state;
		}

		// Likewise after narrowing: a restore that leaves the database out has no table
		// names to fit to this site.
		$state['prefix'] = array();
		if ( ! empty( $state['sql_files'] ) ) {
			$unfit = self::prefix_errors( $report );
			if ( ! empty( $unfit ) ) {
				$state['errors'] = $unfit;
				$state['phase']  = self::PHASE_FAILED;
				return $state;
			}

			$origin = isset( $report['table_prefix'] ) ? (string) $report['table_prefix'] : '';
			if ( RestorePrefix::differs( $origin ) ) {
				$state['prefix'] = RestorePrefix::plan( $origin );

				RestoreController::narrate(
					$state,
					sprintf(
						/* translators: 1: table prefix the backup was taken under, 2: table prefix this site uses. */
						__( 'This backup was taken under the table prefix "%1$s". Its tables are being loaded under this site\'s prefix, "%2$s".', 'tailwatch' ),
						$state['prefix']['from'],
						$state['prefix']['to']
					)
				);
			}
		}

		// Litter from a run that was killed before it could tidy up, cleared before the
		// import can meet it. Here, once, in the phase that only runs at the start: the
		// import itself spans many ticks and a sweep inside it would take this restore's
		// own staging tables with it.
		if ( ! empty( $state['sql_files'] ) ) {
			$stale = RestoreDatabase::discard_stale_staging();
			if ( $stale > 0 ) {
				RestoreController::narrate(
					$state,
					sprintf(
						/* translators: %d: number of leftover staging tables. */
						_n(
							'Cleared %d staging table an earlier restore left behind.',
							'Cleared %d staging tables an earlier restore left behind.',
							$stale,
							'tailwatch'
						),
						$stale
					),
					'WARNING'
				);
			}
		}

		if ( count( $state['chain'] ) > 1 ) {
			$state['warnings'][] = sprintf(
				/* translators: %d: number of backups that will be applied in order. */
				__( 'This is an incremental backup and will be restored from %d backups applied in order.', 'tailwatch' ),
				count( $state['chain'] )
			);

			// Which backups, in the order they will be applied. A chain that turns out to
			// be missing a link fails against the newest folder name, so without this the
			// operator cannot tell which of the older ones is actually the problem.
			$position = 0;
			foreach ( (array) $state['chain'] as $link ) {
				++$position;
				$link_folder = is_array( $link ) ? ( $link['folder'] ?? '' ) : (string) $link;
				RestoreController::narrate(
					$state,
					sprintf(
						/* translators: 1: position in the chain, 2: total, 3: backup name. */
						__( 'Chain %1$d of %2$d: %3$s', 'tailwatch' ),
						$position,
						count( $state['chain'] ),
						(string) $link_folder
					)
				);
			}
		}

		RestoreController::narrate(
			$state,
			sprintf(
				/* translators: 1: number of archive parts, 2: number of database dump files. */
				__( 'Backup verified: %1$d archive part(s) and %2$d database file(s) to apply.', 'tailwatch' ),
				count( (array) $state['archives'] ),
				count( (array) $state['sql_files'] )
			)
		);

		if ( ! empty( $state['deletions'] ) ) {
			RestoreController::narrate(
				$state,
				sprintf(
					/* translators: %d: number of files recorded as deleted. */
					__( 'The backup also records %d file(s) to remove from this site.', 'tailwatch' ),
					count( (array) $state['deletions'] )
				)
			);
		}

		if ( ! empty( $state['archives'] ) ) {
			$refusal = self::space_refusal( self::prepared_bytes( (array) $state['archives'] ), self::free_bytes() );
			if ( null !== $refusal ) {
				$state['errors'] = array( $refusal );
				$state['phase']  = self::PHASE_FAILED;
				return $state;
			}

			$id               = substr( md5( $state['folder_date'] . '|' . $state['started'] ), 0, 12 );
			$state['staging'] = RestoreSwitch::STAGING_PREFIX . $id;
			$state['aside']   = RestoreSwitch::ASIDE_PREFIX . $id;
		}

		$state['phase']     = self::PHASE_ENGAGE;

		return $state;
	}

	/**
	 * Close the site and arm the exit guarantees.
	 *
	 * @param array $state State.
	 * @return array
	 */
	private static function do_engage( array $state ) {
		MaintenanceMode::arm_fatal_guard();

		// Armed BEFORE any file is written, because what it protects against is a
		// restored mu-plugin that stops the site booting — and by the time we could
		// detect that, there is no working request left to install it from.
		PostRestoreGuard::install_mu_guard();

		RestoreController::narrate( $state, __( 'Recovery guard installed, in case a restored file stops the site booting.', 'tailwatch' ) );

		$engaged = MaintenanceMode::engage();
		if ( empty( $engaged['success'] ) ) {
			// A restore may proceed with the site reachable — that is a visitor-experience
			// problem, not a data-safety one — but the operator has to be told.
			$state['warnings'][] = __( 'The site could not be closed to visitors during this restore.', 'tailwatch' );
		} else {
			RestoreController::narrate( $state, __( 'Site closed to visitors.', 'tailwatch' ) );
		}

		$state['phase'] = self::PHASE_IMPORT;
		return $state;
	}

	/**
	 * Begin the import again, from nothing.
	 *
	 * Called when the previous tick was killed while importing. The byte offset on disk
	 * is from before the statements that tick had already executed, so resuming from it
	 * replays them — and a replayed CREATE or INSERT fails on "already exists" or
	 * "duplicate entry", which the importer counts as an error and turns into an
	 * abandoned restore.
	 *
	 * Safe precisely because the import writes only into staging tables: nothing outside
	 * them has been touched at this phase, so throwing them away costs time and nothing
	 * else. The alternative — making every statement in a foreign dump idempotent — would
	 * mean rewriting SQL this plugin did not author.
	 *
	 * @param array $state State.
	 * @return array
	 */
	public static function restart_import( array $state ) {
		$tables = isset( $state['tables'] ) ? (array) $state['tables'] : array();
		if ( ! empty( $tables ) ) {
			RestoreDatabase::discard_staging( $tables );
		}

		$state['sql_index']  = 0;
		$state['sql_offset'] = 0;
		$state['tables']     = array();
		$state['links']      = array();

		// What the first pass learned about the dump's table names is learned again.
		if ( ! empty( $state['prefix']['from'] ) ) {
			$state['prefix'] = RestorePrefix::plan( (string) $state['prefix']['from'] );
		}

		Log::warning(
			'A restore tick was killed while importing; the import is starting again from the beginning.',
			array(
				'feature'   => 'restore',
				'action'    => 'restore_import_restarted',
				'meta_data' => array(
					'feature' => 'Restore & Migration',
					'event'   => 'Import restarted after a killed tick',
					'backup'  => isset( $state['folder_date'] ) ? (string) $state['folder_date'] : '',
				),
			)
		);

		return $state;
	}

	/**
	 * Load the dump into staging tables. Non-destructive.
	 *
	 * @param array $state    State.
	 * @param int   $deadline Tick deadline.
	 * @return array
	 */
	private static function do_import( array $state, $deadline ) {
		if ( empty( $state['sql_files'] ) ) {
			$state['phase'] = self::PHASE_REWRITE;
			return $state;
		}

		while ( $state['sql_index'] < count( $state['sql_files'] ) ) {
			$file   = $state['sql_files'][ $state['sql_index'] ];

			// Said once per file, on the tick that starts it — not on every tick that
			// continues it, which for a large dump would be hundreds of identical lines.
			if ( 0 === (int) $state['sql_offset'] ) {
				RestoreController::narrate(
					$state,
					sprintf(
						/* translators: 1: file number, 2: total files, 3: file name. */
						__( 'Loading database file %1$d of %2$d: %3$s', 'tailwatch' ),
						(int) $state['sql_index'] + 1,
						count( $state['sql_files'] ),
						wp_basename( (string) $file )
					)
				);
			}

			$before_tables = count( $state['tables'] );
			$result = RestoreSqlImporter::import_chunk( $file, (int) $state['sql_offset'], RestoreDatabase::STAGING_PREFIX, $deadline, self::prefix_rename( $state ), self::run_id( $state ) );

			$state['sql_offset'] = (int) $result['next_offset'];
			$state['tables']     = array_values( array_unique( array_merge( $state['tables'], $result['tables'] ) ) );
			$state               = self::note_prefix_progress( $state, $result );
			$state               = self::note_links( $state, $result );

			// Tables appear as the dump is read, so this names them as they arrive rather
			// than in one unreadable block at the end.
			foreach ( array_slice( $state['tables'], $before_tables ) as $new_table ) {
				RestoreController::narrate(
					$state,
					sprintf(
						/* translators: %s: database table name. */
						__( 'Loaded table %s', 'tailwatch' ),
						(string) $new_table
					)
				);
			}

			if ( ! empty( $result['failed'] ) || ! empty( $result['errors'] ) ) {
				// Nothing outside staging has been written, so this is still reversible
				// by simply throwing the staging tables away.
				return self::abort_before_destructive( $state, $result['errors'] );
			}

			if ( empty( $result['eof'] ) ) {
				return $state; // more of this file next tick.
			}

			RestoreController::narrate(
				$state,
				sprintf(
					/* translators: 1: file name, 2: running total of tables loaded. */
					__( 'Finished %1$s. %2$d table(s) loaded so far.', 'tailwatch' ),
					wp_basename( (string) $file ),
					count( $state['tables'] )
				)
			);

			++$state['sql_index'];
			$state['sql_offset'] = 0;

			// Each pass through a deadline-bounded loop is a 'still running' checkpoint.
			// A tick that is killed leaves the last one behind as its high-water mark,
			// which is the only evidence of where this host really cuts a request off.
			TickBudget::alive( 'restore' );

			if ( TickBudget::spent( $deadline ) ) {
				return $state;
			}
		}

		// Every table is staged by now, so a link that leads to one the dump does not hold
		// can be told from one whose table simply came later in it.
		$unmet = RestoreDatabase::drop_unmet_links( (array) $state['tables'] );
		if ( empty( $unmet['ok'] ) ) {
			return self::abort_before_destructive(
				$state,
				array( __( 'A link between two of the backup\'s tables could not be prepared for this site. Nothing was changed.', 'tailwatch' ) )
			);
		}
		if ( ! empty( $unmet['removed'] ) ) {
			$state['warnings'][] = sprintf(
				/* translators: %s: comma-separated list of table pairs, each written "table -> table it linked to". */
				__( 'The backup does not hold every table its own tables link to, so these links (foreign keys) were not restored: %s', 'tailwatch' ),
				sanitize_text_field( implode( ', ', (array) $unmet['removed'] ) )
			);
		}

		if ( ! empty( $state['prefix'] ) ) {
			// Loaded under its own names, the dump would leave this site reading the tables
			// it already had, behind a restore that reported success.
			if ( empty( $state['prefix']['matched'] ) ) {
				return self::abort_before_destructive(
					$state,
					array(
						sprintf(
							/* translators: %s: table prefix recorded in the backup. */
							__( 'The database in this backup holds no table named for the prefix it records ("%s"), so it cannot be fitted to this site. Nothing was changed.', 'tailwatch' ),
							(string) $state['prefix']['from']
						),
					)
				);
			}

			if ( ! empty( $state['prefix']['skipped'] ) ) {
				$state['warnings'][] = sprintf(
					/* translators: %s: comma-separated list of database table names. */
					__( 'These tables in the backup already carried this site\'s table prefix without belonging to the site that was backed up, and were left out: %s', 'tailwatch' ),
					implode( ', ', (array) $state['prefix']['skipped'] )
				);
			}
		}

		$state['phase'] = self::PHASE_REWRITE;
		return $state;
	}

	/**
	 * The two table prefixes the importer works between, for a backup taken under another one.
	 *
	 * @param array $state State.
	 * @return array Empty when every table keeps the name the dump gives it.
	 */
	private static function prefix_rename( array $state ) {
		if ( empty( $state['prefix']['from'] ) || empty( $state['prefix']['to'] ) ) {
			return array();
		}

		return array(
			'from' => (string) $state['prefix']['from'],
			'to'   => (string) $state['prefix']['to'],
		);
	}

	/**
	 * Left-out tables a restore's warning names.
	 */
	const PREFIX_SKIPPED_NAMED = 20;

	/**
	 * Keep what one slice of the import found out about the dump's table names.
	 *
	 * @param array $state  State.
	 * @param array $result What RestoreSqlImporter::import_chunk() returned.
	 * @return array
	 */
	private static function note_prefix_progress( array $state, array $result ) {
		if ( empty( $state['prefix'] ) ) {
			return $state;
		}

		if ( ! empty( $result['converted'] ) ) {
			$state['prefix']['matched'] = true;
		}

		if ( ! empty( $result['left_out'] ) ) {
			$skipped = array_values( array_unique( array_merge( (array) $state['prefix']['skipped'], (array) $result['left_out'] ) ) );

			$state['prefix']['skipped'] = array_slice( $skipped, 0, self::PREFIX_SKIPPED_NAMED );
		}

		return $state;
	}

	/**
	 * What tells this restore from every other, for the names its staged links are given.
	 *
	 * @param array $state State.
	 * @return string
	 */
	private static function run_id( array $state ) {
		return md5( (string) ( $state['folder_date'] ?? '' ) . '|' . (string) ( $state['started'] ?? '' ) );
	}

	/**
	 * Staged links a restore keeps the names of. A link beyond this stays under the name it
	 * was staged with.
	 */
	const LINKS_CARRIED = 2000;

	/**
	 * Keep the links one slice of the import staged.
	 *
	 * @param array $state  State.
	 * @param array $result What RestoreSqlImporter::import_chunk() returned.
	 * @return array
	 */
	private static function note_links( array $state, array $result ) {
		if ( empty( $result['links'] ) ) {
			return $state;
		}

		$links = isset( $state['links'] ) ? (array) $state['links'] : array();

		foreach ( (array) $result['links'] as $link ) {
			if ( count( $links ) >= self::LINKS_CARRIED ) {
				break;
			}
			$links[] = $link;
		}

		$state['links'] = $links;

		return $state;
	}

	/**
	 * Free the copies set aside from tables that are not part of the restore.
	 *
	 * @param array $state  State.
	 * @param array $tables Live names of the tables whose copies are about to be dropped.
	 * @return array
	 */
	private static function settle_outside_links( array $state, array $tables ) {
		$moved = RestoreDatabase::repoint_outside_links( $tables );

		if ( ! empty( $moved['moved'] ) ) {
			RestoreController::narrate(
				$state,
				sprintf(
					/* translators: %s: comma-separated list of table pairs, each written "table -> table it links to". */
					__( 'Tables that are not in the backup were linked to the restored tables: %s', 'tailwatch' ),
					sanitize_text_field( implode( ', ', (array) $moved['moved'] ) )
				)
			);
		}

		if ( ! empty( $moved['removed'] ) ) {
			$state['warnings'][] = sprintf(
				/* translators: %s: comma-separated list of table pairs, each written "table -> table it linked to". */
				__( 'These tables are not in the backup, and their links (foreign keys) to tables it replaced could not be kept, so the links were removed: %s', 'tailwatch' ),
				sanitize_text_field( implode( ', ', (array) $moved['removed'] ) )
			);
		}

		return $state;
	}

	/**
	 * Drop the copies the cutover set aside, with the links settled on either side of it.
	 *
	 * In this order, because each step frees the next: a table outside the restore can hold a
	 * copy in place, and a copy holds the names the restored tables' links are to carry.
	 * None of it can undo a restore that is already in place, so what cannot be done is said
	 * and the restore goes on.
	 *
	 * @param array $state State.
	 * @return array
	 */
	private static function clear_old_generation( array $state ) {
		$tables = (array) $state['tables'];
		$state  = self::settle_outside_links( $state, $tables );
		$gone   = RestoreDatabase::discard_old( $tables );

		if ( ! empty( $gone['left'] ) ) {
			$state['warnings'][] = sprintf(
				/* translators: %s: comma-separated list of database table names. */
				__( 'The restore is complete, but these copies of the tables it replaced could not be removed and are still in the database: %s', 'tailwatch' ),
				sanitize_text_field( implode( ', ', (array) $gone['left'] ) )
			);
		}

		$named = RestoreDatabase::name_links( isset( $state['links'] ) ? (array) $state['links'] : array() );

		if ( ! empty( $named['left'] ) ) {
			$state['warnings'][] = sprintf(
				/* translators: %s: comma-separated list of database table names. */
				__( 'The links (foreign keys) of these tables work, but could not be given the names the backup holds for them: %s', 'tailwatch' ),
				sanitize_text_field( implode( ', ', (array) $named['left'] ) )
			);
		}

		return $state;
	}

	/**
	 * Make the staging copy this site's own, before anything is switched: the names stored
	 * under the backup's table prefix first, then the backup's addresses.
	 *
	 * @param array $state    State.
	 * @param int   $deadline Tick deadline.
	 * @return array
	 */
	private static function do_rewrite( array $state, $deadline ) {
		if ( ! empty( $state['prefix'] ) && empty( $state['prefix']['done'] ) ) {
			$state['prefix'] = RestorePrefix::convert_keys( $state['prefix'], (array) $state['tables'], $deadline );

			if ( '' !== (string) $state['prefix']['error'] ) {
				// Still only staging has been written.
				return self::abort_before_destructive( $state, array( (string) $state['prefix']['error'] ) );
			}

			// Returned unfinished so that the caller records how far it got: see
			// RestorePrefix::convert_keys().
			if ( empty( $state['prefix']['done'] ) ) {
				return $state;
			}

			if ( (int) $state['prefix']['keys'] > 0 ) {
				RestoreController::narrate(
					$state,
					sprintf(
						/* translators: 1: number of stored names changed, 2: table prefix this site uses. */
						_n(
							'%1$d stored name in the restored database now carries this site\'s table prefix, "%2$s".',
							'%1$d stored names in the restored database now carry this site\'s table prefix, "%2$s".',
							(int) $state['prefix']['keys'],
							'tailwatch'
						),
						(int) $state['prefix']['keys'],
						(string) $state['prefix']['to']
					)
				);
			}
		}

		if ( empty( $state['rewrite'] ) ) {
			$state['rewrite'] = RestoreUrlRewriter::plan( $state );

			if ( ! empty( $state['rewrite']['multisite'] ) ) {
				$state['warnings'][] = sprintf(
					/* translators: 1: address the backup was taken at, 2: address this site is served from. */
					__( 'The backup was taken at %1$s and this network is served from %2$s. The addresses inside the restored database were left as they are, because each site in a network keeps its own; update them with Search & Replace.', 'tailwatch' ),
					$state['rewrite']['origin'],
					$state['rewrite']['live']
				);
			} elseif ( empty( $state['rewrite']['done'] ) ) {
				RestoreController::narrate(
					$state,
					sprintf(
						/* translators: 1: address the backup was taken at, 2: address this site is served from. */
						__( 'The backup was taken at %1$s. Changing its addresses to %2$s in the restored database, before anything is switched.', 'tailwatch' ),
						$state['rewrite']['origin'],
						$state['rewrite']['live']
					)
				);
			}
		}

		$state['rewrite'] = RestoreUrlRewriter::run( $state['rewrite'], $deadline );

		if ( empty( $state['rewrite']['done'] ) ) {
			return $state;
		}

		if ( ! empty( $state['rewrite']['pairs'] ) ) {
			RestoreController::narrate(
				$state,
				( (int) $state['rewrite']['rows'] > 0 )
					? sprintf(
						/* translators: 1: number of rows, 2: number of tables. */
						__( 'Addresses changed in %1$d row(s) across %2$d table(s).', 'tailwatch' ),
						(int) $state['rewrite']['rows'],
						count( (array) $state['rewrite']['changed'] )
					)
					: __( 'The restored database held no stored addresses that needed changing.', 'tailwatch' )
			);

			if ( ! empty( $state['rewrite']['skipped'] ) ) {
				$state['warnings'][] = sprintf(
					/* translators: %s: comma-separated table names. */
					__( 'These tables have no primary key, so the addresses in them were not changed: %s. Search & Replace can update them.', 'tailwatch' ),
					implode( ', ', (array) $state['rewrite']['skipped'] )
				);
			}
			if ( ! empty( $state['rewrite']['failed'] ) ) {
				$state['warnings'][] = sprintf(
					/* translators: %s: comma-separated table names. */
					__( 'The addresses in these tables could not all be changed: %s. Search & Replace can update them.', 'tailwatch' ),
					implode( ', ', (array) $state['rewrite']['failed'] )
				);
			}
		}

		$state['phase'] = self::PHASE_EXTRACT;
		return $state;
	}

	/**
	 * Prepare the backup's files in a staging folder. Nothing live is written.
	 *
	 * @param array $state    State.
	 * @param int   $deadline Tick deadline.
	 * @return array
	 */
	private static function do_extract( array $state, $deadline ) {
		if ( ! empty( $state['archives'] ) && 0 === (int) $state['archive_index'] && 0 === (int) $state['entry_offset'] ) {
			self::remove_stale_staging( $state );
			if ( ! self::prepare_staging( $state ) ) {
				return self::abort_before_switch(
					$state,
					array( __( 'The folder the backup\'s files are prepared in could not be created. Nothing was changed.', 'tailwatch' ) )
				);
			}
		}

		while ( $state['archive_index'] < count( $state['archives'] ) ) {
			$archive = $state['archives'][ $state['archive_index'] ];

			// Once per part, on the tick that opens it. A part that takes many ticks says
			// nothing further until it finishes, so the log stays a list of real events.
			if ( 0 === (int) $state['entry_offset'] ) {
				RestoreController::narrate(
					$state,
					sprintf(
						/* translators: 1: part number, 2: total parts, 3: archive file name. */
						__( 'Preparing files from part %1$d of %2$d: %3$s', 'tailwatch' ),
						(int) $state['archive_index'] + 1,
						count( $state['archives'] ),
						wp_basename( (string) $archive )
					)
				);
			}

			$result  = RestoreExtractor::extract_part( $archive, self::staging_root( $state ), (int) $state['entry_offset'], $deadline );
			$state['kept_plugin_files'] = (int) ( $state['kept_plugin_files'] ?? 0 ) + (int) ( $result['kept'] ?? 0 );

			$state['entry_offset'] = (int) $result['next_offset'];

			if ( ! empty( $result['errors'] ) ) {
				// Only the staging folder has been written, so the site is still untouched.
				return self::abort_before_switch( $state, $result['errors'] );
			}

			if ( empty( $result['eof'] ) ) {
				return $state;
			}

			++$state['archive_index'];
			$state['entry_offset'] = 0;

			RestoreController::narrate(
				$state,
				sprintf(
					/* translators: 1: parts done, 2: total parts. */
					__( 'Prepared %1$d of %2$d file part(s).', 'tailwatch' ),
					(int) $state['archive_index'],
					count( $state['archives'] )
				)
			);

			// Each pass through a deadline-bounded loop is a 'still running' checkpoint.
			// A tick that is killed leaves the last one behind as its high-water mark,
			// which is the only evidence of where this host really cuts a request off.
			TickBudget::alive( 'restore' );

			if ( TickBudget::spent( $deadline ) ) {
				return $state;
			}
		}

		$state['phase'] = self::PHASE_PRUNE;
		return $state;
	}

	/**
	 * Mark the point of no return.
	 *
	 * Kept to one place because everything that reads this flag is deciding what to tell
	 * the operator about their own site, and the two answers are not shades of each other:
	 * the watchdog says "Nothing on your site was changed" when it is false and "your site
	 * may be part-way between the two versions" when it is true. Set from two call sites —
	 * the deletion of a live file, and the file switch — because a restore has two ways of
	 * becoming irreversible, and whichever happens first is the one that counts.
	 *
	 * @param array $state State.
	 * @return array
	 */
	private static function mark_destructive( array $state ) {
		$state['destructive'] = true;
		return $state;
	}

	/**
	 * Apply the chain's deletions to the prepared files, then switch them into place.
	 *
	 * Deletions are applied after ALL extraction rather than between increments: a file
	 * removed by one increment can be shipped again by a later one. They go into staging,
	 * and onto the live site where the switch does not make it match staging: root files,
	 * and uploads that are merged rather than replaced.
	 *
	 * The switch is planned once and run from a saved index, so it survives a killed tick.
	 *
	 * @param array $state    State.
	 * @param int   $deadline Tick deadline.
	 * @return array
	 */
	private static function do_prune( array $state, $deadline ) {
		if ( empty( $state['archives'] ) ) {
			$state['phase'] = self::PHASE_SWAP;
			return $state;
		}

		if ( ! empty( $state['deletions'] ) && empty( $state['deletions_ok'] ) ) {
			$result = RestoreExtractor::remove_entries(
				(array) $state['deletions'],
				self::staging_root( $state ),
				(int) $state['prune_offset'],
				$deadline,
				array()
			);

			$state['prune_offset'] = (int) $result['next_offset'];

			foreach ( $result['errors'] as $error ) {
				$state['warnings'][] = $error;
			}

			if ( empty( $result['eof'] ) ) {
				TickBudget::alive( 'restore' );
				return $state;
			}

			$outside = self::deletions_outside_switch( $state );
			if ( ! empty( $outside ) ) {
				// Marked BEFORE the call, not after it. This is the first thing in the
				// pipeline that changes the live site, and the only one with no way back:
				// the switch keeps the site's own copies aside, a deletion keeps nothing.
				$state = self::mark_destructive( $state );
				RestoreExtractor::remove_entries( $outside, ABSPATH );
			}

			$state['deletions_ok'] = true;

			RestoreController::narrate(
				$state,
				sprintf(
					/* translators: %d: number of files. */
					__( 'Removed %d file(s) the backup records as deleted.', 'tailwatch' ),
					count( (array) $state['deletions'] )
				)
			);
		}

		if ( empty( $state['switch_planned'] ) ) {
			$plan                    = RestoreSwitch::plan( self::switch_layout( $state ) );
			$state['switch_ops']     = $plan['ops'];
			$state['switch_kept']    = (int) $plan['kept'];
			$state['switch_index']   = 0;
			$state['switch_planned'] = true;

			if ( $state['switch_kept'] > 0 ) {
				RestoreController::narrate( $state, __( 'Kept the Tailwatch plugins installed on this site, and its own storage; the backup\'s copies of them were not put in place.', 'tailwatch' ) );
			}

			if ( ! empty( $plan['unbacked'] ) ) {
				RestoreController::narrate(
					$state,
					sprintf(
						/* translators: %s: comma-separated paths, relative to the site root. */
						__( 'Left these in place because no backup can hold them (a link to a folder elsewhere, or a folder this site cannot read): %s', 'tailwatch' ),
						implode( ', ', array_map( 'sanitize_text_field', (array) $plan['unbacked'] ) )
					)
				);
			}
		}

		if ( ! empty( $state['switch_ops'] ) ) {
			$state = self::mark_destructive( $state );
		}

		$run                   = RestoreSwitch::run( self::switch_layout( $state ), (array) $state['switch_ops'], (int) $state['switch_index'], $deadline );
		$state['switch_index'] = (int) $run['index'];

		if ( ! empty( $run['errors'] ) ) {
			return self::roll_back_switch( $state, $run['errors'] );
		}

		if ( empty( $run['done'] ) ) {
			TickBudget::alive( 'restore' );
			return $state;
		}

		self::reset_opcache();

		RestoreController::narrate(
			$state,
			sprintf(
				/* translators: %d: number of files and folders. */
				__( 'Switched %d item(s) into place; the site\'s own copies are kept aside until the restore finishes.', 'tailwatch' ),
				count( (array) $state['switch_ops'] )
			)
		);

		$state['phase'] = self::PHASE_SWAP;
		return $state;
	}

	/**
	 * Cut the database over in a single statement.
	 *
	 * @param array $state State.
	 * @return array
	 */
	private static function do_swap( array $state ) {
		if ( empty( $state['tables'] ) ) {
			$state['phase'] = self::PHASE_FINALIZE;
			return $state;
		}

		// swap() refuses to overwrite a retained generation it did not create, so unless
		// the previous one is dropped the SECOND restore on any site fails at the cutover
		// with 'a previous restore has not been cleared yet', and every restore leaves
		// another whole copy of the database behind.
		//
		// Only the previous one is dropped, never this restore's own. A table whose staging
		// copy is still present has not been swapped by this run, so anything retained for
		// it belongs to an earlier restore. On a tick that swapped and then died before
		// writing state, staging is gone and the retained copy IS this restore's undo
		// point — which the re-entry must leave alone.
		$superseded = array();
		foreach ( $state['tables'] as $table ) {
			if ( RestoreDatabase::table_exists( RestoreDatabase::staging_name( $table ) ) ) {
				$superseded[] = $table;
			}
		}
		// Dropping the retained generation destroys the PREVIOUS restore's undo point, and
		// it has to happen before the swap — the swap renames live to _twold, which would
		// collide with a generation still sitting there. So the order cannot change; what
		// can is refusing FIRST, while the undo point is still intact, in the one case the
		// swap was going to refuse anyway.
		if ( count( $superseded ) !== count( $state['tables'] ) ) {
			return self::roll_back_switch(
				$state,
				array( __( 'The restored database is incomplete, so the swap was not attempted. The database was not changed.', 'tailwatch' ) )
			);
		}

		if ( ! empty( $superseded ) ) {
			// A table outside that restore can still be holding its copies in place.
			$state = self::settle_outside_links( $state, $superseded );

			RestoreDatabase::discard_old( $superseded );
			RestoreController::narrate( $state, __( 'Cleared the database copy kept by the previous restore.', 'tailwatch' ) );
		}

		// Immediately before the rename, so it includes this restore's own process row.
		$state['own_state'] = self::capture_own_state();

		RestoreController::narrate(
			$state,
			sprintf(
				/* translators: %d: number of tables being switched over. */
				__( 'Switching %d table(s) over to the restored database.', 'tailwatch' ),
				count( (array) $state['tables'] )
			)
		);

		$result = RestoreDatabase::swap( $state['tables'] );
		if ( empty( $result['ok'] ) ) {
			// Atomic: no table moved. The files were switched, so they are put back too.
			return self::roll_back_switch( $state, array( $result['error'] ) );
		}

		// The rename swapped the options table underneath a request that has already read
		// from it. WordPress caches options — `alloptions` in particular — so every read
		// below would return the PRE-swap value and every update_option() comparing
		// against it would decide nothing had changed and write nothing at all.
		//
		// Unflushed, that silently disables the repairs this whole section exists for: the
		// check for "the backup did not have Tailwatch active" sees the live list and never
		// fires, the app secret is left as the backup's, and the lockout filter judges the
		// wrong site. Flushing here is what makes the next few lines mean anything.
		if ( function_exists( 'wp_cache_flush' ) ) {
			wp_cache_flush();
		}

		// MUST run in this request, not the next one. Everything below reads or writes
		// wp_options, which the rename above just replaced with the backup's copy —
		// and this is the last moment at which this plugin is known to be loaded.
		$state = self::reinstate_after_swap( $state );

		// The rename replaced wp_options, and the worker lock lives in it — so this tick's
		// own lock is gone and whatever the BACKUP held is in its place. Re-asserting puts
		// a lock this request owns back: exclusivity holds for the rest of the tick, the
		// shutdown release still clears it, and a stale one carried in from the backup
		// cannot outlive this run and block the next restore.
		RestoreController::reassert_worker_lock();

		$state['phase'] = self::PHASE_FINALIZE;
		return $state;
	}

	/**
	 * How many revocation flags a restore will carry across the cutover.
	 *
	 * A flag is one option row per revoked token and they are swept daily once the
	 * token's own lifetime has passed, so a live site holds a handful. The cap exists so
	 * that a site which has somehow accumulated thousands cannot turn a restore's state
	 * row into something too large to write.
	 */
	const REVOCATION_CARRY_LIMIT = 500;

	/**
	 * The rows describing what this site is DOING, as opposed to what it contains.
	 *
	 * The database dump inside a backup was taken while that backup was still running —
	 * it cannot have been taken at any other moment — so the plugin's own state row in it
	 * says the backup is in progress, for ever. Restore that and the site reports the very
	 * backup it was just restored from as still running, and the recovery watchdog starts
	 * rescheduling a backup that finished days ago.
	 *
	 * These rows also ARE the backup catalogue: one row per backup, keyed by folder. The
	 * folders on disk belong to the live site, not to the moment the snapshot was taken,
	 * so the live list is the correct one after a restore too — otherwise the Backups
	 * screen lists sets that are not there and hides sets that are.
	 *
	 * The running restore's own state row is carried for the same reason. It lives in a
	 * table the rename replaces, so without it the tick after the swap reads the BACKUP's
	 * copy — a restore that finished on another site, or none at all — and the run loses
	 * the record of where it had got to.
	 *
	 * Captured immediately before the rename, so it includes the restore's own process
	 * row, and written back immediately after.
	 *
	 * @return array<int,array<string,mixed>> Raw rows.
	 */
	public static function capture_own_state() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the plugin's own operational rows, read once around a table rename that no cache layer models.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE `key` IN ( %s, %s, %s )',
				$wpdb->prefix . TAILWATCH_DB_TABLE_NAME,
				'default_backup_scan',
				'process_monitor',
				RestoreController::STATE_KEY
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Put the live operational rows back over the ones the swap brought in.
	 *
	 * Replaced wholesale rather than merged: the snapshot's copies describe a different
	 * moment on a possibly different site, and there is no sense in which half of each is
	 * right.
	 *
	 * @param array $state State.
	 * @return void
	 */
	private static function reinstate_own_state( array $state ) {
		if ( empty( $state['own_state'] ) || ! is_array( $state['own_state'] ) ) {
			return;
		}

		global $wpdb;
		$table = $wpdb->prefix . TAILWATCH_DB_TABLE_NAME;

		foreach ( array( 'default_backup_scan', 'process_monitor', RestoreController::STATE_KEY ) as $key ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- clearing the snapshot's copies of rows this site owns, around a rename.
			$wpdb->delete( $table, array( 'key' => $key ) );
		}

		foreach ( $state['own_state'] as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			// The id belongs to the table that was just renamed away.
			unset( $row['id'] );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- restoring rows captured from this same table moments earlier.
			$wpdb->insert( $table, $row );
		}

		Log::info(
			'Carried the backup catalogue and process state across the database cutover.',
			array(
				'feature'   => 'restore',
				'action'    => 'restore_own_state_reinstated',
				'meta_data' => array(
					'feature' => 'Restore & Migration',
					'event'   => 'Operational state kept',
					'rows'    => count( $state['own_state'] ),
				),
			)
		);
	}

	/**
	 * The credential state a restore must NOT roll back.
	 *
	 * Two rows in wp_options decide whether a token is still usable, and the cutover
	 * replaces both with the backup's versions:
	 *
	 * - `tailwatch_token_revoked_<jti>` says a token has been withdrawn. A token revoked
	 *   after the backup loses that row, and nothing else records the decision.
	 * - the master secret, from which the signing key is derived. Restoring it makes
	 *   tokens minted before the backup verify again, and — the more visible half —
	 *   silently invalidates the tokens the dashboard is holding right now, so a
	 *   successful restore disconnects the site from the account that ordered it.
	 *
	 * Both are carried forward rather than restored. Revocation is a security decision
	 * an operator made about a credential; a backup taken before it is not evidence that
	 * it was reversed.
	 *
	 * @return array{secret:string,revoked:array<string>,operator:array{login:string,sessions:array},connection:array}
	 */
	private static function capture_auth_state() {
		global $wpdb;

		$carry = array(
			'secret'     => '',
			'revoked'    => array(),
			'operator'   => self::capture_operator_session(),
			'connection' => self::capture_connection(),
		);

		// Only the stored one. A secret defined in wp-config.php is a file, not a table
		// row, so the cutover cannot touch it and re-writing it would be wrong.
		if ( ! defined( 'TAILWATCH_APP_SECRET_KEY' ) || '' === (string) TAILWATCH_APP_SECRET_KEY ) {
			$secret = get_option( AppSecretService::OPTION_NAME );
			if ( is_string( $secret ) && '' !== $secret ) {
				$carry['secret'] = $secret;
			}
		}

		if ( ! isset( $wpdb ) ) {
			return $carry;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- core options table; one read per restore, and the rows are dynamic option names no cache is keyed on.
		$names = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT option_name FROM %i WHERE option_name LIKE %s LIMIT %d',
				$wpdb->options,
				$wpdb->esc_like( 'tailwatch_token_revoked_' ) . '%',
				self::REVOCATION_CARRY_LIMIT
			)
		);

		foreach ( (array) $names as $name ) {
			$jti = substr( (string) $name, strlen( 'tailwatch_token_revoked_' ) );
			if ( JwtService::is_valid_jti_format( $jti ) ) {
				$carry['revoked'][] = $jti;
			}
		}

		return $carry;
	}

	/**
	 * The session of the person who ordered this restore.
	 *
	 * The cutover replaces wp_users and wp_usermeta, so the session token behind their
	 * cookie stops existing the instant the rename lands. They are signed out, the nonce
	 * on the next poll fails, and the progress panel reports that the site is not
	 * responding — permanently, while the restore it is watching finishes perfectly.
	 *
	 * Carried rather than solved with an anonymous progress endpoint. This plugin
	 * registers no `wp_ajax_nopriv_*` handler anywhere, by construction, and an
	 * unauthenticated door into a running restore is a worse trade than a logged-out
	 * observer.
	 *
	 * ONE user: the one who started it. Re-granting every account's sessions would hand
	 * live logins to people the restored site may not even have, which is a different
	 * and much larger act than keeping the operator's own screen working.
	 *
	 * What is stored is the `session_tokens` meta value verbatim — the same hashes
	 * WordPress already keeps there, never a raw token. It is matched back by LOGIN
	 * rather than by id, because the restored users table numbers its rows its own way,
	 * and the auth cookie identifies its owner by username too.
	 *
	 * @return array{login:string,sessions:array}
	 */
	private static function capture_operator_session() {
		$empty = array(
			'login'    => '',
			'sessions' => array(),
		);

		if ( ! function_exists( 'wp_get_current_user' ) ) {
			return $empty;
		}

		$user = wp_get_current_user();
		if ( ! $user || ! $user->exists() ) {
			// A restore started from cron or the command line has no operator watching it.
			return $empty;
		}

		$sessions = get_user_meta( $user->ID, 'session_tokens', true );
		if ( ! is_array( $sessions ) || empty( $sessions ) ) {
			return $empty;
		}

		return array(
			'login'    => (string) $user->user_login,
			'sessions' => $sessions,
		);
	}

	/**
	 * Give the operator's session back to whoever now holds their username.
	 *
	 * A cookie survives this only when the restored account's password hash also matches
	 * the live one — WordPress mixes a fragment of it into the cookie hash, and nothing
	 * here rewrites a restored password, because restoring a backup is supposed to
	 * restore its users. When the hash does differ the operator is signed out and the
	 * front end says so, which is the honest outcome rather than a frozen panel.
	 *
	 * @param array $state State.
	 * @return void
	 */
	private static function reinstate_operator_session( array $state ) {
		if ( empty( $state['auth_carry']['operator'] ) || ! is_array( $state['auth_carry']['operator'] ) ) {
			return;
		}

		$operator = $state['auth_carry']['operator'];
		$login    = isset( $operator['login'] ) ? (string) $operator['login'] : '';
		$sessions = isset( $operator['sessions'] ) && is_array( $operator['sessions'] ) ? $operator['sessions'] : array();

		if ( '' === $login || empty( $sessions ) ) {
			return;
		}

		// Cached user objects describe the table that was renamed away a moment ago.
		if ( function_exists( 'wp_cache_flush' ) ) {
			wp_cache_flush();
		}

		$user = get_user_by( 'login', $login );
		if ( ! $user ) {
			// The restored site has no such account. Nothing to keep signed in, and
			// creating one would be inventing an administrator.
			return;
		}

		// Shape-checked before it is written back. This is the only carried value that
		// grants a login, and the check costs nothing: a token key is a hash, and a
		// session is an array with an expiry in the future. Anything else did not come
		// from WordPress's own session store and is dropped rather than trusted.
		$safe = array();
		foreach ( $sessions as $hash => $session ) {
			if ( ! is_string( $hash ) || ! preg_match( '/^[a-f0-9]{64}$/', $hash ) ) {
				continue;
			}
			if ( ! is_array( $session ) || empty( $session['expiration'] ) ) {
				continue;
			}
			if ( (int) $session['expiration'] <= time() ) {
				// Already over. Restoring an expired session would be pure surface.
				continue;
			}
			$safe[ $hash ] = $session;
		}

		if ( empty( $safe ) ) {
			return;
		}

		$existing = get_user_meta( $user->ID, 'session_tokens', true );
		if ( ! is_array( $existing ) ) {
			$existing = array();
		}

		// Merged, not replaced: the restored account's own sessions were valid before this
		// request and dropping them would sign out anyone the backup had logged in.
		update_user_meta( $user->ID, 'session_tokens', array_merge( $existing, $safe ) );
	}

	/**
	 * The licence binding and the dashboard pairing, as they stand on the LIVE site.
	 *
	 * Both live in tables the cutover replaces — the licence in the plugin's settings
	 * table, the pairing in wp_options — so without this a restore silently adopts
	 * whatever the backup happened to hold. Three outcomes, all wrong:
	 *
	 * - a backup taken BEFORE the site was connected disconnects it;
	 * - a backup taken while a DIFFERENT licence was connected rebinds the site to it;
	 * - after a migration, restoring the source's backup would move the SOURCE's licence
	 *   onto the destination, which is not the destination's entitlement.
	 *
	 * Carrying the live values forward is right in all three: the licence this site holds
	 * now is the truth about this site, and a restore is not a way to change it.
	 *
	 * The paired administrator is recorded by LOGIN, not by id — see reinstate_connection().
	 *
	 * @return array
	 */
	private static function capture_connection() {
		$carry = array(
			'licence'     => null,
			'cta_id'      => '',
			'auth_key'    => '',
			'cta_secret'  => '',
			'admin_login' => '',
		);

		$activation = ( new VerifyStatusController() )->get_plugin_activation_status();
		if ( is_array( $activation ) && ! empty( $activation['extended_connected'] ) ) {
			$carry['licence'] = $activation['extended_connected'];
		}

		$cta_id = get_option( 'tailwatch_cta_id' );
		if ( ! is_string( $cta_id ) || ! preg_match( '/^[a-f0-9]{32}$/', $cta_id ) ) {
			// No pairing, or one that could not have authenticated anyway.
			return $carry;
		}

		$auth_key = get_option( 'tailwatch_auth_header_key' );
		$secret   = get_option( 'tailwatch_cta_secret_' . $cta_id );

		// All three or none. A pairing missing any one of them cannot authenticate, and
		// re-applying a partial set would leave the site looking paired when it is not.
		if ( ! is_string( $auth_key ) || '' === $auth_key || ! is_string( $secret ) || '' === $secret ) {
			return $carry;
		}

		$carry['cta_id']     = $cta_id;
		$carry['auth_key']   = $auth_key;
		$carry['cta_secret'] = $secret;

		// By login. The id is meaningless in the restored users table.
		$admin_id = (int) get_option( 'tailwatch_cta_user_' . $cta_id );
		if ( $admin_id > 0 ) {
			$admin = get_userdata( $admin_id );
			if ( $admin ) {
				$carry['admin_login'] = (string) $admin->user_login;
			}
		}

		return $carry;
	}

	/**
	 * Put the live licence and pairing back over the ones the cutover restored.
	 *
	 * ## Why the paired administrator is resolved by login
	 *
	 * The pairing records which WordPress administrator the dashboard acts as, and
	 * ConnectAuthenticator re-checks that user's live capability on every request. A
	 * restore replaces wp_users wholesale, and user ids are reused: id 8 on this site and
	 * id 8 in the backup are frequently different people. Re-applying the stored id would
	 * therefore hand the dashboard whatever account now happens to occupy that row — and
	 * if that account is an administrator, the check designed to catch it passes.
	 *
	 * So the login is carried and re-resolved against the restored table. If nobody holds
	 * that login any more, or the account that does is not an administrator, the pairing
	 * is dropped rather than pointed at a stranger: the dashboard loses access and has to
	 * be re-paired by someone who can prove they administer the site, which is the
	 * correct outcome and not a silent one.
	 *
	 * @param array $state State.
	 * @return array
	 */
	private static function reinstate_connection( array $state ) {
		if ( empty( $state['auth_carry']['connection'] ) || ! is_array( $state['auth_carry']['connection'] ) ) {
			return $state;
		}

		$carry   = $state['auth_carry']['connection'];
		$foreign = self::backup_is_another_site( $state );

		if ( ! empty( $carry['licence'] ) && is_array( $carry['licence'] ) ) {
			( new VerifyStatusController() )->tailwatch_restore_activation_binding( $carry['licence'] );
		} elseif ( $foreign ) {
			// This site had no licence of its own, and the one the swap brought in belongs to
			// the site the backup came from. Left in place it would report another account's
			// entitlement from this address. Removed through the ordinary disconnect so
			// premium features lock and the other site's tokens stop being usable.
			$status = ( new VerifyStatusController() )->get_plugin_activation_status();
			if ( ! empty( $status['extended_connected'] ) ) {
				( new VerifyStatusController() )->tailwatch_delete_plugin_activation_data( 'extended_connected' );
				$state['warnings'][] = __( 'This site is not connected to Tailwatch any more. Connect it again under Connect Account.', 'tailwatch' );
			}
		}

		// Whatever the swap brought in, the last verification belongs to the site that ran it:
		// its result, its grace window, its timestamp. Kept, they answer for THIS site until they
		// expire — a cached 'live' for another account's licence. Cleared, the next check is a
		// real one against the record reinstated above.
		delete_transient( VerifyStatusController::VERIFY_CACHE_KEY );
		delete_option( 'tailwatch_last_license_check' );
		delete_option( 'tailwatch_license_failure_window' );

		$cta_id = isset( $carry['cta_id'] ) ? (string) $carry['cta_id'] : '';
		if ( '' === $cta_id || ! preg_match( '/^[a-f0-9]{32}$/', $cta_id ) ) {
			// Nothing of this site's to put back. After a restore of this site's OWN backup the
			// pairing that came with it is this site's own history and stands; from another
			// site's backup it is someone else's credential and must not answer here.
			if ( $foreign ) {
				( new DBModel() )->tailwatch_delete_all_cta_keys();
			}
			return $state;
		}

		// The restored users table is what has to be searched, not a cached copy of the
		// one that was renamed away a moment ago.
		if ( function_exists( 'wp_cache_flush' ) ) {
			wp_cache_flush();
		}

		$login = isset( $carry['admin_login'] ) ? (string) $carry['admin_login'] : '';
		$admin = ( '' !== $login ) ? get_user_by( 'login', $login ) : false;

		if ( ! $admin || ! user_can( $admin->ID, 'manage_options' ) ) {
			// The person this site was paired to is not in the restored database — the ordinary
			// case after a migration, where the users come from the other site. The connection
			// itself belongs to THIS site and is worth keeping, so it is re-pointed at an
			// administrator that does exist here rather than dropped.
			//
			// Never at the id it was recorded against: ids are reused across a database
			// replacement, and the account occupying that row is a different person. The
			// replacement is chosen by capability and named to the operator, because the
			// dashboard now acts as somebody they did not choose.
			$admin = self::first_administrator();

			if ( $admin ) {
				$state['warnings'][] = sprintf(
					/* translators: 1: login the site was paired to, 2: login it now acts as. */
					__( 'The administrator this site was paired to (%1$s) is not in the restored database, so the dashboard connection now acts as %2$s instead. Change it by re-pairing this site.', 'tailwatch' ),
					'' !== $login ? $login : __( 'unknown', 'tailwatch' ),
					$admin->user_login
				);
				RestoreController::narrate( $state, end( $state['warnings'] ), 'WARNING' );
				Log::warning(
					'The paired administrator is absent from the restored database; the dashboard connection was re-pointed at another administrator.',
					array(
						'feature'   => 'restore',
						'action'    => 'restore_pairing_rebound',
						'meta_data' => array(
							'feature' => 'Restore & Migration',
							'event'   => 'Dashboard connection re-pointed',
						),
					)
				);
			}
		}

		if ( ! $admin ) {
			// No administrator at all to act as. The rows the swap brought in go too: leaving
			// them would not leave the site unpaired, it would leave it paired AS THE SITE THE
			// BACKUP CAME FROM — reachable with that site's dashboard credentials while this
			// warning says the connection was dropped.
			( new DBModel() )->tailwatch_delete_all_cta_keys();

			Log::warning(
				'The restored database holds no administrator, so the dashboard pairing was not carried over.',
				array(
					'feature' => 'restore',
					'action'  => 'restore_pairing_dropped',
				)
			);

			// Dropping it is right; saying nothing is not. The log entry is for diagnosis
			// afterwards, and afterwards is exactly when this is discovered — as a dashboard
			// that stopped reaching the site, with nothing connecting it to a restore that
			// reported success. The warning rides back on the state, which the status route
			// reports, and the narration puts it on the screen being watched right now.
			$state['warnings'][] = __( 'The restored database has no administrator, so the dashboard connection could not be carried over. Re-pair this site from the dashboard.', 'tailwatch' );
			RestoreController::narrate(
				$state,
				__( 'The dashboard pairing could not be carried over, because the restored database has no administrator to act as. This site has to be re-paired.', 'tailwatch' ),
				'WARNING'
			);

			return $state;
		}

		// Ours replaces what the swap brought in rather than sitting beside it: the other
		// site's secret and admin rows are keyed by ITS pairing id, so overwriting ours would
		// leave a second, working credential behind.
		( new DBModel() )->tailwatch_delete_all_cta_keys();

		update_option( 'tailwatch_cta_id', $cta_id );
		update_option( 'tailwatch_auth_header_key', $carry['auth_key'], false );
		update_option( 'tailwatch_cta_secret_' . $cta_id, $carry['cta_secret'], false );
		update_option( 'tailwatch_cta_user_' . $cta_id, $admin->ID, false );

		return $state;
	}

	/**
	 * An administrator present in the restored database, lowest id first.
	 *
	 * Chosen by capability rather than by the id the pairing was recorded against, which
	 * belongs to a different person once the users table has been replaced.
	 *
	 * @return \WP_User|null
	 */
	private static function first_administrator() {
		$users = get_users(
			array(
				'role'    => 'administrator',
				'orderby' => 'ID',
				'order'   => 'ASC',
				'number'  => 10,
			)
		);

		foreach ( (array) $users as $user ) {
			if ( $user instanceof \WP_User && user_can( $user->ID, 'manage_options' ) ) {
				return $user;
			}
		}

		return null;
	}

	/**
	 * Whether the backup being restored was taken on a different site.
	 *
	 * The connection data the swap brings in belongs to whoever took the backup. Restoring
	 * this site's own backup makes that this site's own history; restoring another site's
	 * makes it someone else's credential, sitting in this site's options.
	 *
	 * @param array $state State.
	 * @return bool
	 */
	private static function backup_is_another_site( array $state ) {
		$live = untrailingslashit(
			(string) ( $state['live_addresses']['siteurl'] ?? ( $state['live_site_url'] ?? '' ) )
		);
		if ( '' === $live ) {
			return false;
		}

		$marker = BackupReconciler::tailwatch_read_completion_marker( (string) ( $state['folder_date'] ?? '' ) );
		$origin = ( is_array( $marker ) && ! empty( $marker['site_url'] ) ) ? untrailingslashit( (string) $marker['site_url'] ) : '';

		return ( '' !== $origin && $origin !== $live );
	}

	/**
	 * Put the credential state back over the one the cutover restored.
	 *
	 * Runs in the same request as the rename, alongside the other wp_options repairs,
	 * because a revocation that only takes effect on the next cron request is a window in
	 * which a withdrawn token works.
	 *
	 * @param array $state State.
	 * @return array
	 */
	private static function reinstate_auth_state( array $state ) {
		if ( empty( $state['auth_carry'] ) || ! is_array( $state['auth_carry'] ) ) {
			return $state;
		}

		$carry = $state['auth_carry'];

		if ( ! empty( $carry['secret'] ) && is_string( $carry['secret'] ) ) {
			update_option( AppSecretService::OPTION_NAME, $carry['secret'], false );
		}

		$revoked = isset( $carry['revoked'] ) && is_array( $carry['revoked'] ) ? $carry['revoked'] : array();
		foreach ( $revoked as $jti ) {
			if ( JwtService::is_valid_jti_format( $jti ) ) {
				update_option( 'tailwatch_token_revoked_' . $jti, true, false );
			}
		}

		self::reinstate_operator_session( $state );

		return self::reinstate_connection( $state );
	}

	/**
	 * The state without the credentials begin() captured, once no phase can read them.
	 *
	 * Only the swap reads them. A restore interrupted before it resumes from the stored
	 * state and needs them there; from finalize on, and once the restore is done or has
	 * failed, they are a copy of the site's secrets that would outlive any later rotation
	 * or revocation of the originals.
	 *
	 * @param array $state State.
	 * @return array
	 */
	public static function without_spent_auth_carry( array $state ) {
		$phase = isset( $state['phase'] ) ? (string) $state['phase'] : '';

		if ( in_array( $phase, array( self::PHASE_FINALIZE, self::PHASE_DONE, self::PHASE_FAILED ), true ) ) {
			unset( $state['auth_carry'] );
		}

		return $state;
	}

	/**
	 * Repair the two things the cutover silently takes away with wp_options.
	 *
	 * The swap replaces wp_options wholesale, so anything this restore had written
	 * there is gone the instant the rename lands:
	 *
	 * - `active_plugins` becomes the backup's. If the backup predates this plugin's
	 *   activation, or was taken while it was inactive, the NEXT cron request does not
	 *   load Tailwatch at all — so `tailwatch_restore_process` fires with no listener,
	 *   finalize never runs, and the site stays behind the maintenance gate until the
	 *   generated file's own mtime self-heal expires. That is a ~30 minute outage
	 *   produced by successfully restoring a backup.
	 * - the watchdog event scheduled at engage() time lives in the cron array, which is
	 *   also a wp_options row. Losing it removes the teardown layer that exists
	 *   precisely for when a tick dies.
	 *
	 * Both are repaired here rather than in finalize because finalize runs in a
	 * separate cron request — the very request that would not exist if active_plugins
	 * had dropped us.
	 *
	 * @param array $state State.
	 * @return array
	 */
	public static function reinstate_after_swap( array $state ) {
		// Before anything else: the rest of this request, and the tick that finishes the
		// restore, reach this site through its address. Writing two options cannot end
		// the request early, so it costs the repairs below nothing.
		self::reinstate_site_address( $state );

		// Then this, because it decides whether a withdrawn credential is usable and the two
		// repairs below can each end this request early on a hostile site.
		$state = self::reinstate_auth_state( $state );

		// What this site is DOING, as opposed to what it contains. Before anything reads
		// a process row and acts on the snapshot's idea of what is running.
		self::reinstate_own_state( $state );

		// The settings table was just replaced, and the feature reader keeps its own
		// caches in front of it — a per-request array and a transient. Neither is keyed on
		// anything a rename touches, so for the rest of this request every feature would
		// read as whatever it was BEFORE the restore.
		( new FeatureCacheController() )->invalidate_all_caches();
		OptionsController::invalidate_request_cache();

		// Settings come from the backup, deliberately: a restore makes the site BE the
		// backup, and singling out this plugin's own switches would restore everything
		// faithfully except the parts we happen to care about. What does NOT come from
		// the backup is the connection and the list of backups — reinstated above — so a
		// switched-off feature can never cost the operator the way back.
		//
		// Silently reverting it would still be a surprise, though, discovered later as a
		// greyed-out button with nothing connecting it to this restore. So it is said.
		// Read exactly the way the gate reads it, both halves: an EMPTY answer means the
		// feature row itself is switched off, which is the commonest case and the one a
		// check for the field alone would miss entirely.
		$restored_feature = ( new OptionsController() )->get_features_options( 'default_feature_settings', 'default_site_migration', true );
		$restore_allowed  = ! empty( $restored_feature )
			&& true === ( $restored_feature['field_1']['options']['option']['selected'] ?? false );

		if ( ! $restore_allowed ) {
			$state['warnings'][] = __( 'The restored backup had Restore & Migration switched off, so it is off again now. Starting another restore needs the setting turned back on.', 'tailwatch' );
		}

		$active = get_option( 'active_plugins', array() );
		if ( ! is_array( $active ) ) {
			$active = array();
		}

		// Whatever is running right now, by definition, has to keep running. Reading
		// the loaded plugins rather than hardcoding a basename keeps this correct for
		// the pro plugin and for a renamed directory.
		$restored = array();
		foreach ( self::running_plugin_basenames() as $basename ) {
			if ( ! in_array( $basename, $active, true ) ) {
				$active[]   = $basename;
				$restored[] = $basename;
			}
		}

		// Drop what would lock the owner out of the site we just handed back. Done here,
		// against the list, rather than via deactivate_plugins(): the plugin API's idea of
		// what is active was invalidated by the rename a few lines ago.
		list( $active, $locked_out ) = PostRestoreGuard::filter_lockout_plugins( $active );
		$relaxed                     = PostRestoreGuard::harden_network_and_options();

		if ( ! empty( $restored ) || ! empty( $locked_out ) ) {
			update_option( 'active_plugins', array_values( $active ) );
		}

		if ( ! empty( $restored ) ) {
			$state['warnings'][] = __( 'The restored backup did not have Tailwatch active. It has been kept active so the restore could finish.', 'tailwatch' );
			Log::warning(
				'Restore re-activated Tailwatch after the database cutover: ' . implode( ', ', $restored ),
				array(
					'feature' => 'restore',
					'action'  => 'restore_reactivated_self',
				)
			);
		}

		if ( ! empty( $locked_out ) ) {
			$state['warnings'][] = sprintf(
				/* translators: %s: comma-separated list of plugin names. */
				__( 'These plugins were deactivated because they could have locked you out of the restored site: %s', 'tailwatch' ),
				implode( ', ', array_map( 'sanitize_text_field', $locked_out ) )
			);
		}
		if ( ! empty( $relaxed ) ) {
			$state['warnings'][] = sprintf(
				/* translators: %s: comma-separated list of settings. */
				__( 'These network or SSL settings were relaxed so the restored site stays reachable: %s', 'tailwatch' ),
				implode( ', ', array_map( 'sanitize_text_field', $relaxed ) )
			);
		}

		// Re-arm the watchdog into the cron array that now exists.
		if ( ! wp_next_scheduled( MaintenanceMode::WATCHDOG_HOOK ) && MaintenanceMode::is_active() ) {
			wp_schedule_single_event( time() + MaintenanceMode::MAINTENANCE_TTL, MaintenanceMode::WATCHDOG_HOOK );
		}

		return $state;
	}

	/**
	 * Point the restored site back at the address it is served from.
	 *
	 * A backup of another site brings that site's `siteurl` and `home`. Until they are put
	 * back, every loopback this site makes to itself is built from them: WordPress starting
	 * its own cron, and the tick that finishes this restore, go to the other site and the
	 * finish never runs here. Addresses inside the content are rewritten later, from the
	 * backup's recorded origin, which this does not touch.
	 *
	 * The stored option values are used, not site_url() and home_url(), so nothing a filter
	 * adds to them is written back into the database.
	 *
	 * @param array $state State.
	 * @return void
	 */
	private static function reinstate_site_address( array $state ) {
		if ( empty( $state['live_addresses'] ) || ! is_array( $state['live_addresses'] ) ) {
			return;
		}

		foreach ( array( 'siteurl', 'home' ) as $option ) {
			$live = isset( $state['live_addresses'][ $option ] ) ? (string) $state['live_addresses'][ $option ] : '';
			if ( '' === $live ) {
				continue;
			}

			if ( untrailingslashit( (string) get_option( $option ) ) !== untrailingslashit( $live ) ) {
				update_option( $option, $live );
			}
		}
	}

	/**
	 * Plugin basenames for the Tailwatch plugins loaded into this request.
	 *
	 * Derived from the class files actually in memory, so a renamed plugin directory
	 * still resolves and no basename is guessed.
	 *
	 * @return array<string>
	 */
	private static function running_plugin_basenames() {
		$basenames = array();

		// Free: TAILWATCH_FILE when it is defined, otherwise derived from this file's own
		// location, because the constant is not guaranteed to exist in every harness that
		// loads this class. Either way a renamed plugin directory still resolves.
		if ( defined( 'TAILWATCH_FILE' ) && is_string( TAILWATCH_FILE ) ) {
			$basenames[] = plugin_basename( TAILWATCH_FILE );
		} else {
			// <plugin-dir>/Admin/App/Api/Services/Restore/ -> <plugin-dir>
			$dir  = rtrim( dirname( __DIR__, 5 ), '/\\' );
			$slug = wp_basename( $dir );
			if ( '' !== $slug && is_file( $dir . '/' . $slug . '.php' ) ) {
				$basenames[] = $slug . '/' . $slug . '.php';
			}
		}

		// Pro defines its own basename when it loads, so the constant being defined means
		// it is running in this request.
		if ( defined( 'TAILWATCH_PRO_PLUGIN' ) && is_string( TAILWATCH_PRO_PLUGIN ) && '' !== TAILWATCH_PRO_PLUGIN ) {
			$basenames[] = TAILWATCH_PRO_PLUGIN;
		}

		return array_values( array_unique( array_filter( $basenames ) ) );
	}

	/**
	 * Reopen the site.
	 *
	 * @param array $state State.
	 * @return array
	 */
	private static function do_finalize( array $state, $deadline = 0 ) {
		if ( ! empty( $state['reopened'] ) ) {
			return self::finish_cleanup( $state, $deadline );
		}

		// Anything the guard had to disable is reported before it is removed, or the
		// owner would never learn why a plugin of theirs stopped loading.
		$disabled = PostRestoreGuard::consume_incidents();
		if ( ! empty( $disabled ) ) {
			$state['warnings'][] = sprintf(
				/* translators: %s: comma-separated list of file names. */
				__( 'These must-use plugins prevented the site from starting and were disabled: %s', 'tailwatch' ),
				implode( ', ', $disabled )
			);
		}

		PostRestoreGuard::remove_mu_guard();
		RestoreController::narrate(
			$state,
			empty( $disabled )
				? __( 'Recovery guard removed; the site started cleanly.', 'tailwatch' )
				: __( 'Recovery guard removed. The site only started once the must-use plugins listed in the warnings were disabled.', 'tailwatch' )
		);

		// The cutover renames the live tables aside rather than dropping them, because one
		// RENAME cannot put staging in place without first moving live out of the way. That
		// copy has no purpose once the site is back up, so it goes here — left alone it
		// would be a second full database sitting on disk until the next restore, and every
		// backup taken afterwards would carry two copies of everything.
		//
		// Dropped at finalize rather than at the swap: until the site has come back up,
		// those tables are the only thing that could still put it right.
		if ( ! empty( $state['tables'] ) ) {
			$state = self::clear_old_generation( $state );
		}

		MaintenanceMode::lift();
		RestoreController::narrate( $state, __( 'Site reopened to visitors.', 'tailwatch' ) );

		$state['reopened'] = true;

		return self::finish_cleanup( $state, $deadline );
	}

	/**
	 * Stop while the site is still untouched: discard staging and reopen.
	 *
	 * @param array $state  State.
	 * @param array $errors Errors to report.
	 * @return array
	 */
	private static function abort_before_destructive( array $state, array $errors ) {
		RestoreDatabase::discard_staging( $state['tables'] );
		return self::fail( $state, $errors );
	}

	/**
	 * Mark the restore failed and reopen the site.
	 *
	 * Lifting is unconditional even on the failure path: leaving a site behind a
	 * maintenance page because a restore went wrong turns a recoverable problem into an
	 * outage.
	 *
	 * @param array        $state  State.
	 * @param array|string $errors Errors to report.
	 * @return array
	 */
	private static function fail( array $state, $errors ) {
		$state['errors'] = array_merge( $state['errors'], (array) $errors );
		$state['phase']  = self::PHASE_FAILED;

		// The guard is removed on the failure path too. It is armed for the duration of a
		// restore, not left behind on a site that is no longer being restored, where it
		// would go on renaming mu-plugins indefinitely.
		$disabled = PostRestoreGuard::consume_incidents();
		if ( ! empty( $disabled ) ) {
			$state['warnings'][] = sprintf(
				/* translators: %s: comma-separated list of file names. */
				__( 'These must-use plugins prevented the site from starting and were disabled: %s', 'tailwatch' ),
				implode( ', ', $disabled )
			);
		}
		PostRestoreGuard::remove_mu_guard();

		MaintenanceMode::lift();
		return $state;
	}

	/**
	 * Bytes kept free beyond the prepared files.
	 */
	const SPACE_MARGIN = 52428800;

	/**
	 * Uncompressed size of every file the archives hold.
	 *
	 * @param array $archives Archive paths.
	 * @return int
	 */
	private static function prepared_bytes( array $archives ) {
		$bytes = 0;
		foreach ( $archives as $archive ) {
			foreach ( (array) ArchiveReader::read_entries( (string) $archive ) as $entry ) {
				$bytes += isset( $entry['size'] ) ? (int) $entry['size'] : 0;
			}
		}
		return $bytes;
	}

	/**
	 * Free bytes on the disk the restore prepares its files on, or null when the host does not say.
	 *
	 * @return int|null
	 */
	private static function free_bytes() {
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- disabled on some hosts; null is handled.
		$free = function_exists( 'disk_free_space' ) ? @disk_free_space( RestoreValidator::restore_disk_dir() ) : false;
		return false === $free ? null : (int) $free;
	}

	/**
	 * Why a restore cannot prepare its files beside the site, or null when it can.
	 *
	 * Unknown free space is not a refusal: many hosts disable the call, and a disk that
	 * fills during extraction still stops the restore before anything live is touched.
	 *
	 * @param int      $needed Bytes the prepared files take.
	 * @param int|null $free   Free bytes, or null when unknown.
	 * @return string|null
	 */
	public static function space_refusal( $needed, $free ) {
		if ( null === $free ) {
			return null;
		}

		$required = (int) ceil( (int) $needed * 1.05 ) + self::SPACE_MARGIN;
		if ( (int) $free >= $required ) {
			return null;
		}

		return sprintf(
			/* translators: 1: disk space needed, 2: disk space free. */
			__( 'There is not enough free disk space to prepare this restore beside the site: it needs %1$s and %2$s is free. Nothing was changed.', 'tailwatch' ),
			size_format( $required ),
			size_format( (int) $free )
		);
	}

	/**
	 * The site root, resolved and normalised.
	 *
	 * @return string
	 */
	private static function site_root() {
		$real = realpath( ABSPATH );
		return rtrim( wp_normalize_path( false !== $real ? $real : ABSPATH ), '/' );
	}

	/**
	 * A directory's path relative to the site root, as the packer names it in an archive.
	 *
	 * @param string $dir     Directory.
	 * @param string $assumed Path used when the directory is outside the site root.
	 * @return string
	 */
	private static function site_rel( $dir, $assumed ) {
		$real = ( is_string( $dir ) && '' !== $dir ) ? realpath( $dir ) : false;
		if ( false !== $real ) {
			$real = rtrim( wp_normalize_path( $real ), '/' );
			$site = self::site_root();
			if ( 0 === strpos( $real . '/', $site . '/' ) ) {
				return trim( substr( $real, strlen( $site ) ), '/' );
			}
		}
		return $assumed;
	}

	/**
	 * The folder a restore works in, inside Tailwatch's own storage and never the site root.
	 *
	 * @return string
	 */
	private static function work_root() {
		return rtrim( wp_normalize_path( TAILWATCH_RESTORE_DIR ), '/' );
	}

	/**
	 * The folder a restore prepares its files in.
	 *
	 * @param array $state State.
	 * @return string
	 */
	private static function staging_container( array $state ) {
		return self::work_root() . '/' . (string) $state['staging'];
	}

	/**
	 * Where the site's own copies are set aside, as a path from the site root.
	 *
	 * @param array $state State.
	 * @return string
	 */
	private static function aside_location( array $state ) {
		$dir  = self::work_root() . '/' . (string) $state['aside'];
		$site = self::site_root();
		return 0 === strpos( $dir, $site . '/' ) ? substr( $dir, strlen( $site ) + 1 ) : $dir;
	}

	/**
	 * Where the backup's files are extracted, laid out like the site root.
	 *
	 * @param array $state State.
	 * @return string
	 */
	private static function staging_root( array $state ) {
		return self::staging_container( $state ) . '/files';
	}

	/**
	 * Create the staging folder inside the restore folder, which is sealed with deny files.
	 *
	 * @param array $state State.
	 * @return bool
	 */
	private static function prepare_staging( array $state ) {
		if ( empty( $state['staging'] ) || ! SecureDirectoryService::ensure_private_root( self::work_root() ) ) {
			return false;
		}

		return wp_mkdir_p( self::staging_root( $state ) );
	}

	/**
	 * Delete staging folders an earlier restore left behind in the restore folder.
	 *
	 * Only staging: an aside folder from a restore that could not roll back may hold the
	 * site's own files, so those are never removed on anyone's behalf.
	 *
	 * @param array $state State.
	 * @return void
	 */
	private static function remove_stale_staging( array $state ) {
		$root = self::work_root();
		if ( ! is_dir( $root ) ) {
			return;
		}

		foreach ( (array) scandir( $root ) as $name ) {
			$name = (string) $name;
			if ( 0 !== strpos( $name, RestoreSwitch::STAGING_PREFIX ) || (string) $state['staging'] === $name ) {
				continue;
			}
			RestoreSwitch::remove_tree( $root . '/' . $name, time() + 10 );
		}
	}

	/**
	 * Delete the staging folder a failed restore had no time left to delete.
	 *
	 * A failed restore takes no further ticks, so its staging folder would otherwise stay
	 * until the next restore. Only the folder the stored state names, only once that restore
	 * has ended, and never its aside folder: after a rollback that could not finish, that one
	 * holds the site's own files.
	 *
	 * @param array $state    Stored restore state.
	 * @param int   $deadline Unix timestamp to stop at.
	 * @return void
	 */
	public static function clear_failed_staging( array $state, $deadline ) {
		$phase = isset( $state['phase'] ) ? (string) $state['phase'] : '';
		if ( ! in_array( $phase, array( self::PHASE_DONE, self::PHASE_FAILED ), true ) || empty( $state['staging'] ) ) {
			return;
		}

		if ( 0 !== strpos( wp_basename( (string) $state['staging'] ), RestoreSwitch::STAGING_PREFIX ) ) {
			return;
		}

		RestoreSwitch::remove_tree( self::work_root() . '/' . wp_basename( (string) $state['staging'] ), $deadline );
	}

	/**
	 * Why the files of these archives cannot be put in place on this site, if they cannot.
	 *
	 * The switch writes each area at its path under the site root. An area this site reads
	 * from outside that root is never reached that way, and files a backup keeps under
	 * another top-level folder land beside the site rather than over it. Either way the
	 * restore would end with the site's own files unchanged, so it is refused before
	 * anything is touched. A link at the usual path that leads to the real folder is
	 * reached, and passes.
	 *
	 * @param array    $report   Validation report, with the top-level folders of each archive.
	 * @param string[] $archives Archives this restore will extract.
	 * @return string[] Errors; empty when every file has its place.
	 */
	public static function layout_errors( array $report, array $archives ) {
		if ( empty( $archives ) ) {
			return array();
		}

		$site    = self::site_root();
		$areas   = self::areas();
		$outside = array();
		$known   = array( 'wp-admin', WPINC );

		foreach ( $areas as $area ) {
			$known[] = strstr( $area['rel'] . '/', '/', true );
			if ( '' === $area['dir'] ) {
				continue;
			}
			// A side that cannot be resolved is not a mismatch: there is nothing to compare.
			$reads  = RestoreExtractor::resolve_existing( wp_normalize_path( $area['dir'] ) );
			$writes = RestoreExtractor::resolve_existing( $site . '/' . $area['rel'] );
			if ( null !== $reads && null !== $writes && $reads !== $writes ) {
				$outside[] = $area['rel'];
			}
		}

		$errors = array();
		if ( ! empty( $outside ) ) {
			$errors[] = sprintf(
				/* translators: %s: comma-separated folder names, relative to the site root. */
				__( 'This site keeps these folders outside its WordPress folder, where a restore cannot put files back: %s. The restore has been stopped and nothing was changed.', 'tailwatch' ),
				implode( ', ', $outside )
			);
		}

		$foreign = array();
		foreach ( $archives as $archive ) {
			$folders = isset( $report['top_folders'][ $archive ] ) ? (array) $report['top_folders'][ $archive ] : array();
			foreach ( $folders as $folder ) {
				if ( ! in_array( (string) $folder, $known, true ) ) {
					$foreign[ (string) $folder ] = true;
				}
			}
		}
		if ( ! empty( $foreign ) ) {
			$errors[] = sprintf(
				/* translators: 1: comma-separated folder names from the backup, 2: the folder this site keeps its content in. */
				__( 'This backup keeps its files under "%1$s", and this site keeps them under "%2$s". Restoring it would put them beside the files of this site instead of over them, so it has been stopped and nothing was changed.', 'tailwatch' ),
				sanitize_text_field( implode( '", "', array_keys( $foreign ) ) ),
				$areas['content']['rel']
			);
		}

		return $errors;
	}

	/**
	 * Why a backup's database cannot be fitted to this site's table prefix.
	 *
	 * Asked only for a restore that puts the database back; one that leaves it out has no
	 * table names to fit.
	 *
	 * @param array $report The validator's report.
	 * @return string[] Errors; empty when it fits, or needs no fitting.
	 */
	public static function prefix_errors( array $report ) {
		$error = isset( $report['prefix_error'] ) ? (string) $report['prefix_error'] : '';

		return '' === $error ? array() : array( $error );
	}

	/**
	 * The areas a restore replaces or merges: where this site reads each one, and its path
	 * under the site root.
	 *
	 * @return array<string,array{dir:string,rel:string}>
	 */
	private static function areas() {
		$content = self::site_rel( WP_CONTENT_DIR, BackupController::tailwatch_content_archive_name() );
		$uploads = wp_upload_dir( null, false );
		$basedir = isset( $uploads['basedir'] ) ? (string) $uploads['basedir'] : '';
		$mu_dir  = defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins';
		$themes  = get_theme_root();

		return array(
			'content'    => array(
				'dir' => WP_CONTENT_DIR,
				'rel' => $content,
			),
			'plugins'    => array(
				'dir' => WP_PLUGIN_DIR,
				'rel' => self::site_rel( WP_PLUGIN_DIR, $content . '/plugins' ),
			),
			'themes'     => array(
				'dir' => $themes,
				'rel' => self::site_rel( $themes, $content . '/themes' ),
			),
			'mu-plugins' => array(
				'dir' => $mu_dir,
				'rel' => self::site_rel( $mu_dir, $content . '/mu-plugins' ),
			),
			'uploads'    => array(
				'dir' => $basedir,
				'rel' => self::site_rel( $basedir, $content . '/uploads' ),
			),
		);
	}

	/**
	 * What the file switch replaces, merges and never touches, for this site and this restore.
	 *
	 * @param array $state State.
	 * @return array
	 */
	private static function switch_layout( array $state ) {
		$site   = self::site_root();
		$areas  = self::areas();
		$mu_dir = $areas['mu-plugins']['dir'];

		$replace = array(
			$areas['content']['rel'],
			$areas['plugins']['rel'],
			$areas['themes']['rel'],
			$areas['mu-plugins']['rel'],
		);
		$uploads_rel = $areas['uploads']['rel'];
		$merge       = array();

		// Without the database, today's posts still point at today's media, so uploads keep
		// what the site gained after the backup. With it, uploads go back with the rest.
		if ( empty( $state['sql_files'] ) ) {
			$merge[] = $uploads_rel;
		} else {
			$replace[] = $uploads_rel;
		}

		$never = array(
			defined( 'TAILWATCH_CONTENT_DIR_BASE' ) ? TAILWATCH_CONTENT_DIR_BASE : '',
			defined( 'TAILWATCH_LOGS_DIRECTORY' ) ? TAILWATCH_LOGS_DIRECTORY : '',
			$mu_dir . '/' . PostRestoreGuard::MU_GUARD_FILE,
			$mu_dir . '/' . PostRestoreGuard::INCIDENT_FILE,
			$site . '/.htaccess',
			$site . '/.htaccess' . MaintenanceMode::BACKUP_SUFFIX,
			$site . '/tailwatch_maintenance.php',
			// Database credentials, salts and table prefix belong to this machine, not to
			// the backup. RestoreExtractor keeps the backup's copy out of staging; this
			// refuses one that reaches staging by any other route.
			$site . '/wp-config.php',
		);

		$keep = RestoreExtractor::running_plugin_dirs();
		foreach ( $never as $path ) {
			if ( '' !== $path ) {
				$keep[] = wp_normalize_path( $path );
			}
		}

		return array(
			'site'       => $site,
			'staging'    => self::staging_root( $state ),
			'old'        => self::work_root() . '/' . (string) $state['aside'],
			'whole'      => array( 'wp-admin', WPINC ),
			'replace'    => $replace,
			'merge'      => $merge,
			'keep'       => $keep,
			'keep_names' => RestoreExtractor::FOREIGN_HOST_MU_PLUGINS,
		);
	}

	/**
	 * Recorded deletions the switch does not carry out itself: site-root files, and uploads
	 * when they are merged rather than replaced.
	 *
	 * @param array $state State.
	 * @return array<string>
	 */
	private static function deletions_outside_switch( array $state ) {
		$merge = self::switch_layout( $state )['merge'];
		$out   = array();

		foreach ( (array) $state['deletions'] as $name ) {
			$name = ltrim( str_replace( '\\', '/', (string) $name ), '/' );
			if ( false === strpos( $name, '/' ) ) {
				$out[] = $name;
				continue;
			}
			foreach ( $merge as $rel ) {
				if ( 0 === strpos( $name, $rel . '/' ) ) {
					$out[] = $name;
					break;
				}
			}
		}

		return $out;
	}

	/**
	 * Put the site's own files back after a failed switch or cutover, then stop.
	 *
	 * @param array $state  State.
	 * @param array $errors What went wrong.
	 * @return array
	 */
	private static function roll_back_switch( array $state, array $errors ) {
		$switched            = ! empty( $state['switch_ops'] );
		list( $state, $undo ) = self::undo_switch( $state );

		if ( ! empty( $undo ) ) {
			$errors = array_merge( $errors, $undo );
		} elseif ( $switched ) {
			$errors[] = __( 'The site\'s own files were put back, so they are as they were before the restore.', 'tailwatch' );
		}

		if ( ! empty( $state['tables'] ) ) {
			RestoreDatabase::discard_staging( (array) $state['tables'] );
		}

		return self::fail( $state, $errors );
	}

	/**
	 * Undo a file switch and clear what the restore prepared.
	 *
	 * Shared by a failure inside the pipeline and by a restore that recovery gave up on:
	 * either way, before the cutover the site's own copies are still aside and go back.
	 *
	 * @param array $state State.
	 * @return array{0:array,1:array} State (destructive cleared when every file went back), and errors.
	 */
	public static function undo_switch( array $state ) {
		$undo = array();

		if ( ! empty( $state['switch_ops'] ) ) {
			$layout = self::switch_layout( $state );
			$undo   = RestoreSwitch::rollback( $layout, (array) $state['switch_ops'], (int) $state['switch_index'] );
			self::reset_opcache();

			if ( empty( $undo ) ) {
				$state['destructive'] = false;
				RestoreSwitch::cleanup( $layout, (array) $state['switch_ops'], time() + 15 );
			} else {
				$undo[] = sprintf(
					/* translators: %s: folder path, from the site root. */
					__( 'Some files could not be put back. The site\'s own copies are kept in the %s folder.', 'tailwatch' ),
					self::aside_location( $state )
				);
			}
		}

		if ( empty( $undo ) && ! empty( $state['staging'] ) ) {
			RestoreSwitch::remove_tree( self::staging_container( $state ), time() + 15 );
		}

		return array( $state, $undo );
	}

	/**
	 * Stop before the switch: nothing live was written, so staging is simply discarded.
	 *
	 * @param array $state  State.
	 * @param array $errors What went wrong.
	 * @return array
	 */
	private static function abort_before_switch( array $state, array $errors ) {
		if ( ! empty( $state['staging'] ) ) {
			RestoreSwitch::remove_tree( self::staging_container( $state ), time() + 15 );
		}
		return self::abort_before_destructive( $state, $errors );
	}

	/**
	 * Delete the staging and aside folders once the site is open again, in passes.
	 *
	 * A pass stopped by the deadline carries on in the next tick. A pass that leaves something
	 * it cannot delete is tried again a few times, and then the restore ends with a warning:
	 * the site is already restored, and a leftover must not keep the restore, and every task
	 * it blocks, running for good.
	 *
	 * @param array $state    State.
	 * @param int   $deadline Tick deadline.
	 * @return array
	 */
	private static function finish_cleanup( array $state, $deadline ) {
		if ( ! empty( $state['sql_files'] ) && empty( $state['settled'] ) ) {
			$state = self::settle_after_database_restore( $state );
		}

		if ( ! empty( $state['staging'] ) ) {
			self::reset_opcache();

			$cleaned = RestoreSwitch::cleanup( self::switch_layout( $state ), (array) $state['switch_ops'], $deadline );
			if ( true === $cleaned ) {
				$cleaned = RestoreSwitch::remove_tree( self::staging_container( $state ), $deadline );
			}

			if ( true !== $cleaned ) {
				if ( 'partial' !== $cleaned ) {
					$state['cleanup_stuck'] = ( isset( $state['cleanup_stuck'] ) ? (int) $state['cleanup_stuck'] : 0 ) + 1;
				}
				if ( 'partial' === $cleaned || $state['cleanup_stuck'] < self::CLEANUP_STUCK_PASSES ) {
					TickBudget::alive( 'restore' );
					return $state;
				}

				$leftover = sprintf(
					/* translators: 1: start of the names of the folders the site's own files were set aside in, 2: start of the names of the folders the backup was prepared in, 3: folder path, from the site root. */
					__( 'The restore is complete, but some of its temporary files could not be deleted. They are in folders whose names start with "%1$s" or "%2$s", in %3$s or next to the folders the restore replaced, and can be deleted by hand.', 'tailwatch' ),
					RestoreSwitch::ASIDE_PREFIX,
					RestoreSwitch::STAGING_PREFIX,
					dirname( self::aside_location( $state ) )
				);

				$state['warnings'][] = $leftover;
				RestoreController::narrate( $state, $leftover, 'WARNING' );
			}
		}

		$state['phase'] = self::PHASE_DONE;
		return $state;
	}

	/**
	 * Put right, once, what does not come back with a database, and say what is left to the owner.
	 *
	 * The switches came from the backup but .htaccess did not, so the file-access rules are
	 * written again from them. A backup from another site also brings that site's full file
	 * paths into the integrity baseline, where they name nothing. Secrets saved with a key
	 * this site does not have are reported, and the pro plugin settles its own data.
	 *
	 * @param array $state State.
	 * @return array
	 */
	private static function settle_after_database_restore( array $state ) {
		$state['settled'] = true;
		$from_other_site  = self::backup_is_another_site( $state );

		delete_option( 'tailwatch_file_access_signature' );
		if ( $from_other_site ) {
			( new FileMonModel() )->clear_all_baselines();
		}

		try {
			$notes = array_merge(
				SmtpConfigurationController::tailwatch_unreadable_secret_notes(),
				(array) apply_filters( 'tailwatch_restore_finished_warnings', array(), array( 'from_other_site' => $from_other_site ) )
			);
		} catch ( Throwable $e ) {
			$notes = array();
			Log::error(
				'Settling the site after a restore failed: ' . $e->getMessage(),
				array(
					'feature' => 'restore',
					'action'  => 'restore_settle_failed',
				)
			);
		}

		foreach ( $notes as $note ) {
			if ( is_string( $note ) && '' !== trim( $note ) ) {
				$state['warnings'][] = sanitize_text_field( $note );
			}
		}

		return $state;
	}

	/**
	 * Make PHP run the restored code on the next request, not a cached copy of the old.
	 *
	 * @return void
	 */
	private static function reset_opcache() {
		if ( function_exists( 'opcache_reset' ) ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- refused by opcache.restrict_api on some hosts; nothing to do then.
			@opcache_reset();
		}
	}

	/**
	 * SQL parts belonging to a backup, in a stable order.
	 *
	 * @param string $folder_date Backup folder.
	 * @return array Absolute paths.
	 */
	private static function sql_files( $folder_date ) {
		$dir = TAILWATCH_BACKUP_DIR . '/database/' . $folder_date . '/';
		if ( ! is_dir( $dir ) ) {
			return array();
		}

		// The marker's list, not whatever matches db_* on disk. The two are deliberately
		// different: the marker EXCLUDES any part that did not complete, precisely so the
		// restore does not consume a file the validator never checked. Globbing would undo
		// that — a truncated part that happens to end on a statement boundary imports cleanly
		// and the restored table is silently short of rows, after validation reported a
		// clean set. It would also sweep up stray .partN fragments a retried upload can leave.
		$marker = BackupReconciler::tailwatch_read_completion_marker( $folder_date );
		$named  = ( is_array( $marker ) && ! empty( $marker['database']['files'] ) && is_array( $marker['database']['files'] ) )
			? $marker['database']['files']
			: array();

		if ( empty( $named ) ) {
			// A marker written before the list existed. Fall back to the directory rather
			// than refusing a backup that is probably fine.
			$found = glob( $dir . 'db_*' );
			$named = is_array( $found ) ? array_map( 'wp_basename', $found ) : array();
		}

		// Schema before rows: a parent-tables part carries the CREATE statements the
		// INSERT parts depend on, and no ordering of names is guaranteed to put it first.
		$parents = array();
		$rest    = array();

		foreach ( $named as $entry ) {
			$base = wp_basename( (string) $entry );
			$path = $dir . $base;

			if ( '' === $base || ! is_file( $path ) ) {
				continue;
			}

			if ( 0 === strpos( $base, 'db_parent_tables_' ) ) {
				$parents[] = $path;
			} else {
				$rest[] = $path;
			}
		}

		// Natural order, so part 10 follows part 9 rather than part 1.
		sort( $parents, SORT_NATURAL );
		sort( $rest, SORT_NATURAL );

		return array_values( array_unique( array_merge( $parents, $rest ) ) );
	}
}
