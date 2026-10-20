<?php
/**
 * Backup Maintain Controller
 *
 * Handles backup settings retrieval and maintenance (retention, cleanup).
 *
 * @package    Tailwatch
 * @subpackage Admin\App\Api\Controllers\Backup
 */

namespace Tailwatch\Admin\App\Api\Controllers\Backup;

use Tailwatch\Admin\App\Api\Controllers\Features\OptionsController;
use Tailwatch\Admin\App\Api\Models\DBModel;
use Tailwatch\Admin\App\Api\Models\BackupModel;
use Tailwatch\Admin\App\Api\Services\Backup\BackupChain;
use Tailwatch\Admin\App\Api\Services\Backup\RetentionHold;
use Tailwatch\Admin\App\Api\Logging\Log;
use Tailwatch\Admin\App\Api\Services\ProcessGuard;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class BackupMaintainController
 *
 */
class BackupMaintainController {

	/**
	 * process_run values produced by scan-driven backups (malware scanner /
	 * integrity handoff). These are partial snapshots and must not be allowed
	 * to evict user/scheduled complete backups from the retention pool.
	 */
	const SCAN_DRIVEN_PROCESS_RUNS = array( 'malware', 'files_integrity' );

	/**
	 * Maximum number of scan-driven backups to retain. Kept small because
	 * each one is partial (7 DB tables, optionally only changed files) and
	 * is mainly useful as an immediate rollback target for the scan that
	 * produced it.
	 */
	const SCAN_BACKUP_RETENTION_LIMIT = 1;

	/**
	 * Maximum number of migrated backups to retain.
	 *
	 * A migrated backup is not a backup this site asked for — it is another site's data
	 * that arrived here, published with a catalogue row so the Backups screen can see it.
	 * Counted in the user pool it would always be the newest entry, so it would survive every
	 * cycle while pushing one of this site's own backups out. With the retention count at its
	 * default of 1 that is the safety backup taken minutes earlier, which is the only way back
	 * from a migration that went wrong.
	 *
	 * Kept small for the same reason the scan pool is: it is useful as the thing that was
	 * just applied, not as history.
	 */
	const MIGRATED_BACKUP_RETENTION_LIMIT = 1;

	/**
	 * Option of the Backup Vault feature row.
	 */
	const FEATURE_OPTION = 'default_backup_enable';

	/**
	 * Get feature options for backup (wrapper for OptionsController).
	 *
	 * @return array
	 */
	public function get_features_options() {
		$key                = 'default_feature_settings';
		$option             = self::FEATURE_OPTION;
		$is_active          = 1;
		$options_controller = new OptionsController();
		return $options_controller->get_features_options( $key, $option, $is_active );
	}

	public function tailwatch_get_backup_settings() {
		$existing_settings = $this->get_features_options();

		if ( $existing_settings ) {
			$enable_backup     = isset( $existing_settings['field_1']['options']['option']['selected'] ) ? $existing_settings['field_1']['options']['option']['selected'] : null;
			$backup_type       = null;
			$backup_option     = null;
			$secret_key        = null;
			$access_key        = null;
			$backup_interval   = null;
			$backup_maintain   = 1;
			$optimize_database = isset( $existing_settings['field_1']['sub_options']['field_8']['options']['option']['selected'] ) ? $existing_settings['field_1']['sub_options']['field_8']['options']['option']['selected'] : null;
			// Absent on a site whose settings predate the field; the backup falls back to its default.
			$part_size_mb = null;
			foreach ( (array) ( $existing_settings['field_1']['sub_options']['field_12']['options'] ?? array() ) as $part_option ) {
				if ( isset( $part_option['selected'] ) && true === $part_option['selected'] ) {
					$part_size_mb = (int) $part_option['value'];
				}
			}

			if ( isset( $existing_settings['field_1']['sub_options']['field_2']['options'] ) ) {
				foreach ( $existing_settings['field_1']['sub_options']['field_2']['options'] as $backup_is ) {
					if ( isset( $backup_is['selected'] ) && true === $backup_is['selected'] ) {
						$backup_type = $backup_is['value'];
					}
				}
			}

			if ( isset( $existing_settings['field_1']['sub_options']['field_3']['options'] ) ) {
				$backup_options = $existing_settings['field_1']['sub_options']['field_3']['options'];

				foreach ( $backup_options as $option_key => $option_value ) {
					if ( isset( $option_value['selected'] ) && 1 === (int) $option_value['selected'] ) {
						$backup_option = $option_value['value'];

						if ( 'Digital Ocean' === $backup_option && isset( $option_value['sub_options'] ) ) {
							$secret_key = isset( $option_value['sub_options']['field_4']['options']['option']['value'] ) ? $option_value['sub_options']['field_4']['options']['option']['value'] : null;
							$access_key = isset( $option_value['sub_options']['field_5']['options']['option']['value'] ) ? $option_value['sub_options']['field_5']['options']['option']['value'] : null;
						}
					}
				}
			}

			if ( isset( $existing_settings['field_1']['sub_options']['field_6']['options'] ) ) {
				foreach ( $existing_settings['field_1']['sub_options']['field_6']['options'] as $interval_option ) {
					if ( isset( $interval_option['selected'] ) && true === $interval_option['selected'] ) {
						$backup_interval = $interval_option['value'];
					}
				}
			}

			if ( isset( $existing_settings['field_1']['sub_options']['field_7']['options'] ) ) {
				$backup_maintain = null;
				foreach ( $existing_settings['field_1']['sub_options']['field_7']['options'] as $maintain_option ) {
					if ( isset( $maintain_option['selected'] ) && true === $maintain_option['selected'] ) {
						$backup_maintain = $this->tailwatch_backup_keep_count( $maintain_option );
					}
				}
			}

			$response_data = array(
				'backupsEnabled'   => $enable_backup,
				'backupType'       => $backup_type,
				'backupOption'     => $backup_option,
				'secretKey'        => $secret_key,
				'accessKey'        => $access_key,
				'backupInterval'   => $backup_interval,
				'backupMaintain'   => $backup_maintain,
				'optimizeDatabase' => $optimize_database,
				'partSizeMb'       => $part_size_mb,
			);
		} else {
			$response_data = array();
		}

		return $response_data;
	}

	/**
	 * How many backups a retention choice keeps, or null when the choice cannot be read,
	 * in which case nothing is pruned. A plain number is the choice itself; any other
	 * option is answered by whoever offered it.
	 *
	 * @param array $maintain_option Selected retention option.
	 * @return int|null
	 */
	private function tailwatch_backup_keep_count( $maintain_option ) {
		$value = isset( $maintain_option['value'] ) && is_scalar( $maintain_option['value'] ) ? trim( (string) $maintain_option['value'] ) : '';
		if ( 1 === preg_match( '/^[1-9]\d*$/', $value ) ) {
			return (int) $value;
		}

		$answer = apply_filters( 'tailwatch_backup_retention_count', 0, $maintain_option );
		return ( is_numeric( $answer ) && (int) $answer >= 1 ) ? (int) $answer : null;
	}

	/**
	 * The number of backups kept, and whether it was read from the settings.
	 *
	 * Settings that cannot be read (the feature is off, or not set up yet) keep 2; a choice that
	 * cannot be read is null, and nothing is pruned.
	 *
	 * @return array{0:int|null,1:bool}
	 */
	private function retention_limit() {
		$settings = $this->tailwatch_get_backup_settings();
		if ( ! array_key_exists( 'backupMaintain', $settings ) ) {
			return array( 2, false );
		}

		return array( $settings['backupMaintain'], true );
	}

	/**
	 * The number of backups the retention choice keeps; null when it cannot be read.
	 *
	 * @return int|null
	 */
	public function tailwatch_retention_count() {
		list( $limit, $read ) = $this->retention_limit();
		return $read && is_int( $limit ) ? $limit : null;
	}

	/**
	 * The owner saved the backup settings: a number of backups kept that differs from before is
	 * their own choice.
	 *
	 * @param int|null $before Number kept before the save.
	 * @return void
	 */
	public function tailwatch_retention_saved( $before ) {
		$after = $this->tailwatch_retention_count();
		if ( null !== $after && $after !== $before ) {
			RetentionHold::chosen( $after );
		}
	}

	/**
	 * How many of the site's own backups retention holds now.
	 *
	 * @return int
	 */
	public function tailwatch_held_backup_count() {
		list( $limit ) = $this->retention_limit();
		if ( null === $limit ) {
			return 0;
		}

		$rows              = ( new DBModel() )->get_log_value( 'default_backup_scan', 'scan_backp' );
		list( $user_pool ) = $this->pools( is_array( $rows ) ? $rows : array() );
		list( , $held )    = RetentionHold::split( $user_pool, (int) $limit );

		return count( $held );
	}

	public function maintain_backups( $key, $option ) {
		$feature_controller = new DBModel();
		$backups_raw        = $feature_controller->get_log_value( $key, $option );

		list( $user_pool, $scan_pool, $migrated_pool ) = $this->pools( is_array( $backups_raw ) ? $backups_raw : array() );
		list( $user_backup_limit, $read )              = $this->retention_limit();

		// An unreadable retention choice keeps every backup rather than guessing a count.
		if ( null !== $user_backup_limit ) {
			if ( $read ) {
				RetentionHold::observe( (int) $user_backup_limit );
			}
			list( $taking_part, $held ) = RetentionHold::split( $user_pool, (int) $user_backup_limit );
			$this->prune_backup_pool( $taking_part, (int) $user_backup_limit, $feature_controller, $held );
		}
		$this->prune_backup_pool( $scan_pool, self::SCAN_BACKUP_RETENTION_LIMIT, $feature_controller );
		$this->prune_backup_pool( $migrated_pool, self::MIGRATED_BACKUP_RETENTION_LIMIT, $feature_controller );
	}

	/**
	 * Finished backups by the retention each falls under: the site's own, scan-driven, migrated.
	 *
	 * @param array $backups Catalogue rows.
	 * @return array{0:array,1:array,2:array}
	 */
	private function pools( array $backups ) {
		$user_pool     = array();
		$scan_pool     = array();
		$migrated_pool = array();
		foreach ( $backups as $backup ) {
			$backup_data = json_decode( $backup['value'], true );
			if ( ! isset( $backup_data['completed'] ) || $backup_data['completed'] !== true ) {
				continue;
			}
			// A row whose label and value name different folders is damaged; nothing is pruned on its word.
			if ( ! self::tailwatch_row_matches_label( $backup, $backup_data ) ) {
				continue;
			}

			$process_run = isset( $backup_data['process_run'] ) ? $backup_data['process_run'] : 'backup';
			if ( ! empty( $backup_data['migrated'] ) ) {
				// Checked before process_run, which a migrated row carries as 'backup' like
				// any other. Left in the user pool it is always the newest entry, so it is
				// never the one evicted — it evicts instead, and what it evicts is this
				// site's own most recent backup.
				$migrated_pool[] = $backup;
			} elseif ( in_array( $process_run, self::SCAN_DRIVEN_PROCESS_RUNS, true ) ) {
				$scan_pool[] = $backup;
			} else {
				$user_pool[] = $backup;
			}
		}

		return array( $user_pool, $scan_pool, $migrated_pool );
	}

	/**
	 * Whether a backup row's label ('backup_<folderDate>') names the folder its value names.
	 *
	 * Rows that carry no such label (written before runs were labelled, or a scan's own record)
	 * are taken as they are.
	 *
	 * @param array      $row   Raw row with `type`.
	 * @param array|null $value Its decoded value.
	 * @return bool
	 */
	public static function tailwatch_row_matches_label( array $row, $value ) {
		$type = isset( $row['type'] ) ? (string) $row['type'] : '';
		if ( 0 !== strpos( $type, 'backup_' ) ) {
			return true;
		}
		return is_array( $value ) && isset( $value['folderDate'] ) && is_scalar( $value['folderDate'] )
			&& 'backup_' . (string) $value['folderDate'] === $type;
	}

	/**
	 * Delete oldest backups in the given pool until its size is within the limit.
	 *
	 * @param array   $pool               Backup rows (raw DB rows with id + value).
	 * @param int     $limit              Maximum entries to keep.
	 * @param DBModel $feature_controller Shared model instance for related-entry cleanup.
	 * @param array   $also_kept          Rows kept outside the pool, whose chains are kept too.
	 */
	private function prune_backup_pool( array $pool, $limit, $feature_controller, array $also_kept = array() ) {
		$pool_size = count( $pool );
		if ( $pool_size <= $limit ) {
			return;
		}

		$backups_to_delete = $pool_size - $limit;

		// Oldest first. Deletion starts from the front of this order, so it must not
		// depend on the order the database happens to return rows in.
		usort(
			$pool,
			function ( $a, $b ) {
				$key_a = $this->backup_sort_key( $a );
				$key_b = $this->backup_sort_key( $b );
				if ( $key_a === $key_b ) {
					// Total order: same-second backups still sort deterministically.
					return (int) ( $a['id'] ?? 0 ) <=> (int) ( $b['id'] ?? 0 );
				}
				return $key_a <=> $key_b;
			}
		);

		// An incremental backup is only restorable next to the ones it builds on, so
		// deleting by age alone would delete the base out from under the backups being
		// kept and leave the whole retained set unrestorable — silently, since nothing
		// reads an archive until someone needs it.
		$protected = $this->chain_protected_folders( array_merge( array_slice( $pool, $pool_size - $limit ), $also_kept ) );

		/**
		 * Filter the backup folders retention must leave alone.
		 *
		 * Retention decides by age, and age is the wrong question for a folder something is
		 * reading right now. A migration serves its parts straight out of the backup tree,
		 * one request per part over minutes — so a retention cycle landing mid-transfer can
		 * delete the folder out from under it, and the destination receives a short set that
		 * still reports as accepted.
		 *
		 * @param array<string,bool> $protected Folder name => true.
		 */
		$protected = (array) apply_filters( 'tailwatch_backup_protected_folders', $protected );

		$deleted = 0;
		foreach ( $pool as $backup ) {
			if ( $deleted >= $backups_to_delete ) {
				break;
			}

			$backup_data = json_decode( $backup['value'], true );
			$folder_name = is_array( $backup_data ) && isset( $backup_data['folderDate'] ) ? $backup_data['folderDate'] : '';

			if ( '' !== $folder_name && isset( $protected[ $folder_name ] ) ) {
				// Skipped, not counted as deleted: it comes up again next cycle and goes as
				// soon as the backups depending on it have themselves rolled off.
				continue;
			}

			// Both trees. A database-only backup has no files folder, so deleting files/ alone
			// would leave its record in place and the pool would never come down to the limit.
			$delete_backup_file = ( new BackupController() )->tailwatch_delete_backup_folders( is_array( $backup_data ) ? $backup_data : array() );

			if ( $delete_backup_file ) {
				$backup_model = new BackupModel();
				$this->remove_related_entries( $feature_controller, $folder_name );
				$backup_model->delete_backup_by_id( (int) $backup['id'] );
				++$deleted;
			}
		}

		// A chain being held alive is a decision this method just made, not an anomaly,
		// and Log::error routes to notifications. Only report a shortfall the protection
		// does not explain.
		if ( $deleted < $backups_to_delete && ( $deleted + count( $protected ) ) < $backups_to_delete ) {
			// Report the shortfall rather than letting retention quietly under-run: the pool
			// stays over its limit and grows every cycle.
			Log::error(
				'Backup retention removed fewer backups than the limit required.',
				array(
					'feature'  => 'backup',
					'action'   => 'backup_retention_shortfall',
					'origin'   => 'system',
					'expected' => $backups_to_delete,
					'deleted'  => $deleted,
				)
			);
		}
	}

	/**
	 * Backups in a delete request that something NOT being deleted still needs.
	 *
	 * The distinction that matters is "what survives this request", not "what exists":
	 * deleting a whole chain at once is perfectly safe, and refusing it would leave an
	 * operator unable to remove a chain at all.
	 *
	 * @param array   $ids                Backup row ids being deleted.
	 * @param DBModel $feature_controller Shared model instance.
	 * @return array<string,array<string>> Folder being deleted => folders that depend on it.
	 */
	private function chain_blocked_folders( array $ids, $feature_controller ) {
		$deleting = array();
		foreach ( $ids as $backup_id ) {
			$value = $feature_controller->get_feature_value( (int) $backup_id );
			if ( empty( $value ) ) {
				continue;
			}
			$data = json_decode( $value, true );
			if ( is_array( $data ) && ! empty( $data['folderDate'] ) ) {
				$deleting[ (string) $data['folderDate'] ] = true;
			}
		}

		if ( empty( $deleting ) ) {
			return array();
		}

		$blocked = array();
		foreach ( BackupChain::all() as $folder => $entry ) {
			if ( isset( $deleting[ $folder ] ) ) {
				continue; // going too; its ancestors are free to go with it.
			}

			$chain = BackupChain::chain_for( $folder );
			if ( empty( $chain['ok'] ) ) {
				continue; // already unrestorable; holding its ancestors serves nobody.
			}

			foreach ( $chain['links'] as $link ) {
				if ( $link['folder'] !== $folder && isset( $deleting[ $link['folder'] ] ) ) {
					$blocked[ $link['folder'] ][] = $folder;
				}
			}
		}

		return $blocked;
	}

	/**
	 * Backups that the ones being kept cannot be restored without.
	 *
	 * @param array $keeping Pool rows being retained.
	 * @return array<string,bool> Folder name => true.
	 */
	private function chain_protected_folders( array $keeping ) {
		$protected = array();

		foreach ( $keeping as $row ) {
			$data   = json_decode( $row['value'], true );
			$folder = is_array( $data ) && isset( $data['folderDate'] ) ? (string) $data['folderDate'] : '';
			if ( '' === $folder ) {
				continue;
			}

			$chain = BackupChain::chain_for( $folder );
			if ( empty( $chain['ok'] ) ) {
				// The chain is already broken, so there is nothing here worth holding on to.
				// Protecting an unrestorable set would keep it forever.
				continue;
			}

			foreach ( $chain['links'] as $link ) {
				$protected[ $link['folder'] ] = true;
			}
		}

		return $protected;
	}

	/**
	 * Sortable creation timestamp for a backup row.
	 *
	 * Tries three sources in descending order of reliability:
	 *
	 * 1. the `date_created` column — a real MySQL datetime written at insert time
	 * 2. `folderDate` in the payload — 'Y-m-d_H-i-s', which needs an explicit
	 *    format parse because strtotime() returns false for it
	 * 3. `zipId` — a raw time() captured when the backup started
	 *
	 * @param array $row Raw backup row (DB columns plus the JSON `value` payload).
	 * @return int Unix timestamp, or 0 when nothing usable is present.
	 */
	private function backup_sort_key( array $row ) {
		if ( ! empty( $row['date_created'] ) ) {
			$timestamp = strtotime( $row['date_created'] );
			if ( false !== $timestamp ) {
				return (int) $timestamp;
			}
		}

		$data = isset( $row['value'] ) ? json_decode( $row['value'], true ) : null;
		if ( ! is_array( $data ) ) {
			return 0;
		}

		if ( ! empty( $data['folderDate'] ) ) {
			// Match only the timestamp prefix. Folder names carry a random suffix
			// ('2026-09-08_10-00-00-a1b2c3d4') so the path is not enumerable, and
			// createFromFormat() returns false rather than ignoring trailing data.
			if ( preg_match( '/^\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2}/', $data['folderDate'], $match ) ) {
				$parsed = \DateTime::createFromFormat( 'Y-m-d_H-i-s', $match[0] );
				if ( $parsed instanceof \DateTime ) {
					return (int) $parsed->getTimestamp();
				}
			}
		}

		if ( isset( $data['zipId'] ) && is_numeric( $data['zipId'] ) ) {
			return (int) $data['zipId'];
		}

		return 0;
	}

	private function remove_related_entries( $feature_controller, $folder_name ) {
		$related_entries = array(
			array(
				'key'    => 'default_backup_scan',
				'option' => 'backup_cancel_pause',
			),
			array(
				'key'    => 'default_backup_scan',
				'option' => 'scan_backup_payload',
			),
		);

		foreach ( $related_entries as $entry ) {
			$related_backups_raw = $feature_controller->get_log_value( $entry['key'], $entry['option'] );
			$related_backups     = is_array( $related_backups_raw ) ? $related_backups_raw : array();
			foreach ( $related_backups as $related_backup ) {
				$related_backup_data = json_decode( $related_backup['value'], true );
				if ( isset( $related_backup_data['folderDate'] ) && $related_backup_data['folderDate'] === $folder_name ) {
					$backup_model = new BackupModel();
					$backup_model->delete_backup_by_id( (int) $related_backup['id'] );
				}
			}
		}
	}

	public function tailwatch_delete_backup_folder( $post_data ) {
		try {
			// Block while any consumer of the backup artifacts is mid-flight:
			// a running backup writes to a folder we might rmdir; download /
			// restore / migration read a folder we might rmdir; manual upload
			// writes to the same paths; a malware scan and its restore put
			// files back from the backup taken before the scan. Strict for both
			// single-id and is_delete-all because identifying "which exact backup
			// is in-flight" requires per-module state that isn't worth the
			// complexity for a rare user action during long scans.
			$blocked = ( new ProcessGuard() )->ensure_can_modify_artifacts(
				array(
					'backup',
					'backup_download',
					'restore',
					'migration',
					'migration_backup',
					'migration_backup_upload',
					'malware_scan',
					'malware_restore',
				)
			);
			if ( null !== $blocked ) {
				return $blocked;
			}

			$json_data = isset( $post_data ) ? wp_unslash( $post_data ) : '';
			$data      = json_decode( $json_data, true );

			if ( ! $data ) {
				Log::error(
					'Backup folder deletion failed: Invalid input data provided',
					array(
						'feature' => 'backup',
						'action'  => 'backup_folder_delete_failed',
					)
				);
				return array(
					'data'    => array(),
					'message' => __( 'Invalid input data.', 'tailwatch' ),
					'code'    => 400,
				);
			}

			$feature_controller = new DBModel();
			$success_count      = 0;
			$failed_ids         = array();
			$failed_messages    = array();

			if ( isset( $data['is_delete'] ) && true === $data['is_delete'] ) {
				$tailwatch_key = 'default_backup_scan';
				$option   = 'scan_backp';

				$all_backups_raw = $feature_controller->get_log_value( $tailwatch_key, $option );
				$all_backups     = is_array( $all_backups_raw ) ? $all_backups_raw : array();
				if ( empty( $all_backups ) ) {
					Log::error(
						'Backup folder deletion failed: No backups found to delete',
						array(
							'feature' => 'backup',
							'action'  => 'backup_folder_delete_failed',
						)
					);
					return array(
						'data'    => array(),
						'message' => __( 'No backups found to delete.', 'tailwatch' ),
						'code'    => 404,
					);
				}

				$ids = array_column( $all_backups, 'id' );
			} else {
				if ( ! isset( $data['ids'] ) || ! is_array( $data['ids'] ) ) {
					Log::error(
						'Backup folder deletion failed: IDs must be provided as an array',
						array(
							'feature' => 'backup',
							'action'  => 'backup_folder_delete_failed',
						)
					);
					return array(
						'data'    => array(),
						'message' => __( 'IDs must be provided as an array, or use is_delete:true to delete all backups.', 'tailwatch' ),
						'code'    => 400,
					);
				}

				$ids = $data['ids'];

				if ( empty( $ids ) ) {
					Log::error(
						'Backup folder deletion failed: No IDs provided in the request',
						array(
							'feature' => 'backup',
							'action'  => 'backup_folder_delete_failed',
						)
					);
					return array(
						'data'    => array(),
						'message' => __( 'No IDs provided in the request.', 'tailwatch' ),
						'code'    => 400,
					);
				}
			}

			// Deleting a backup that a KEPT backup builds on leaves that one unrestorable,
			// and nothing would say so until someone needed it. Automatic retention already
			// refuses this; a manual delete has to as well, or the guard is only as strong as
			// the path nobody took. Deleting the dependents in the same request is fine — the
			// whole chain goes together — so the check is against what SURVIVES.
			$chain_blocked = $this->chain_blocked_folders( $ids, $feature_controller );

			foreach ( $ids as $backup_id ) {
				$value = $feature_controller->get_feature_value( (int) $backup_id );

				if ( empty( $value ) ) {
					$failed_ids[]      = $backup_id;
					$failed_messages[] = "No backup data found for ID: $backup_id";
					continue;
				}

				$folder_data = json_decode( $value, true );
				$folder_date = isset( $folder_data['folderDate'] ) ? $folder_data['folderDate'] : null;

				if ( empty( $folder_date ) ) {
					$failed_ids[]      = $backup_id;
					$failed_messages[] = 'Folder name not found in backup data for ID: ' . $backup_id;
					continue;
				}

				if ( isset( $chain_blocked[ $folder_date ] ) ) {
					$failed_ids[]      = $backup_id;
					$failed_messages[] = sprintf(
						/* translators: 1: name of the backup being deleted, 2: comma-separated names of the newer backups that depend on it. */
						__( 'Kept "%1$s" — these newer backups are built on it and could not be restored without it: %2$s', 'tailwatch' ),
						sanitize_text_field( (string) $folder_date ),
						implode( ', ', array_map( 'sanitize_text_field', $chain_blocked[ $folder_date ] ) )
					);
					continue;
				}

				$backup_directory    = TAILWATCH_BACKUP_DIR . '/files/' . $folder_date . '/';
				$backup_directory_db = TAILWATCH_BACKUP_DIR . '/database/' . $folder_date . '/';
				$backup_controller   = new BackupController();

				$delete_backup_file = $backup_controller->delete_backup_folder( $backup_directory );
				$delete_backup_db   = $backup_controller->delete_backup_folder( $backup_directory_db );

				if ( $delete_backup_file && $delete_backup_db ) {
					$this->remove_related_entries( $feature_controller, $folder_date );
					$backup_model = new BackupModel();
					if ( false !== $backup_model->delete_backup_by_id( (int) $backup_id ) ) {
						++$success_count;
					} else {
						$failed_ids[]      = $backup_id;
						$failed_messages[] = "Failed to delete the main backup entry for ID: $backup_id";
					}
				} else {
					$failed_ids[]      = $backup_id;
					$failed_messages[] = "Failed to delete the backup folder for ID: $backup_id";
				}
			}

			// Prepare response.
			$total_count       = count( $ids );
			$delete_all_impact = 'Make sure you still have a recent backup in the Vault — without one, you can\'t restore your site if something goes wrong.';
			if ( $success_count === $total_count ) {
				$noun_all = ( 1 === $success_count ) ? 'backup' : 'backups';
				Log::info(
					"You removed {$success_count} {$noun_all} from your Vault. {$delete_all_impact}",
					array(
						'feature'   => 'backup',
						'action'    => 'backup_folder_deleted',
						'title'     => "{$success_count} {$noun_all} removed",
						'count'     => $success_count,
						'meta_data' => array(
							'feature'       => 'Backup Vault',
							'event'         => 'Removed',
							'impact'        => $delete_all_impact,
							'success_count' => (int) $success_count,
							'failed_count'  => 0,
							'total'         => $total_count,
						),
					)
				);
				return array(
					'data'    => array(
						'success_count' => $success_count,
						'total'         => $total_count,
					),
					'message' => __( 'All backup folders and associated entries deleted successfully.', 'tailwatch' ),
					'code'    => 200,
				);
			} elseif ( $success_count > 0 ) {
				$failed_count          = count( $failed_ids );
				$delete_partial_impact = 'Some backups couldn\'t be deleted — check the Vault to see which ones are still there before you free up space again.';
				Log::info(
					"You removed {$success_count} of {$total_count} backups; {$failed_count} couldn't be deleted. {$delete_partial_impact}",
					array(
						'feature'       => 'backup',
						'action'        => 'backup_folder_deleted',
						'title'         => "{$success_count} of {$total_count} backups removed",
						'success_count' => $success_count,
						'failed_count'  => $failed_count,
						'meta_data'     => array(
							'feature'       => 'Backup Vault',
							'event'         => 'Partially removed',
							'impact'        => $delete_partial_impact,
							'success_count' => (int) $success_count,
							'failed_count'  => $failed_count,
							'total'         => $total_count,
						),
					)
				);
				return array(
					'data'    => array(
						'success_count'   => $success_count,
						'failed_count'    => count( $failed_ids ),
						'failed_ids'      => $failed_ids,
						'failed_messages' => $failed_messages,
						'total'           => count( $ids ),
					),
					'message' => sprintf(
						/* translators: 1: number of successfully deleted backups, 2: total number of backups */
						__( 'Deleted %1$d out of %2$d backup folders.', 'tailwatch' ),
						$success_count,
						count( $ids )
					),
					'code'    => 207, // Multi-Status
				);
			} else {
				Log::error(
					'Backup folder deletion failed: Failed to delete any backup folders',
					array(
						'feature'  => 'backup',
						'action'   => 'backup_folder_delete_failed',
						'messages' => $failed_messages,
					)
				);
				return array(
					'data'    => array(
						'failed_ids'      => $failed_ids,
						'failed_messages' => $failed_messages,
						'total'           => count( $ids ),
					),
					'message' => __( 'Failed to delete any backup folders.', 'tailwatch' ),
					'code'    => 500,
				);
			}
		} catch ( \Throwable $e ) {
			Log::error(
				'Backup folder deletion failed: ' . $e->getMessage(),
				array(
					'feature'   => 'backup',
					'action'    => 'backup_folder_delete_failed',
					'exception' => $e,
				)
			);
			return array(
				'data'    => array(),
				'message' => __( 'An unexpected error occurred while deleting backup folders.', 'tailwatch' ),
				'code'    => 500,
			);
		}
	}

	public function tailwatch_get_backup_status() {
		try {
			$backup_controller = new BackupController();
			$is_backup_enable  = $backup_controller->get_backup_feature_data();
			if ( ! $is_backup_enable['feature_enable'] ) {
				return array(
					'data'           => array(),
					'feature_enable' => $is_backup_enable['feature_enable'],
					'parent_enable'  => $is_backup_enable['parent_enable'],
					'message'        => __( 'Backup feature is not enabled. Please enable it first.', 'tailwatch' ),
					'code'           => 400,
				);
			}

			$get_backup_option = $this->tailwatch_get_backup_settings();

			$started_key        = 'default_backup_scan';
			$started_option     = 'scan_backp';
			$feature_controller = new DBModel();
			$json_data          = $feature_controller->get_recent_data( $started_option, $started_key );

			$started_time = ! empty( $json_data ) && isset( $json_data['started_time'] )
				? gmdate( 'Y-m-d H:i:s', $json_data['started_time'] ) // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_gmdate
				: 'Not Yet Started';

			$backup_type     = $get_backup_option['backupType'] ?? 'N/A';
			$backup_option   = $get_backup_option['backupOption'] ?? 'N/A';
			$backup_interval = $get_backup_option['backupInterval'] ?? 'N/A';
			$backup_maintain = $get_backup_option['backupMaintain'] ?? 'N/A';

			$next_scheduled          = wp_next_scheduled( 'tailwatch_backup_schedule_run' );
			$current_time            = time();
			$next_schedule_formatted = $next_scheduled ? gmdate( 'Y-m-d H:i:s', $next_scheduled ) : 'Not Scheduled'; // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_gmdate

			$next_run = $next_scheduled ? human_time_diff( $current_time, $next_scheduled ) : __( 'Not scheduled', 'tailwatch' );

			$return_data = array(
				'started_time'   => $started_time,
				'backup_type'    => $backup_type,
				'backup_option'  => $backup_option,
				'time_interval'  => $backup_interval,
				'backupMaintain' => $backup_maintain,
				'backupsHeld'    => $this->tailwatch_held_backup_count(),
				'next_schedule'  => $next_schedule_formatted,
				'next_run'       => $next_run,
			);
			return array(
				'code'    => 200,
				'data'    => $return_data,
				'message' => __( 'Data retrieved successfully', 'tailwatch' ),
			);
		} catch ( \Throwable $e ) {
			Log::error(
				'Backup status retrieval failed: ' . $e->getMessage(),
				array(
					'feature'   => 'backup',
					'action'    => 'backup_status_retrieval_failed',
					'exception' => $e,
				)
			);
			return array(
				'data'    => array(),
				'message' => __( 'An unexpected error occurred while retrieving backup status.', 'tailwatch' ),
				'code'    => 500,
			);
		}
	}
}
