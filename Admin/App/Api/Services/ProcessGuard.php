<?php
namespace Tailwatch\Admin\App\Api\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Process Guard
 *
 * Server-side gate for actions that should be blocked while related background
 * processes are running. Pure registry consumer — no hardcoded feature ↔ process
 * map. Each module declares its `locks_features` directly on its
 * `ProcessManager::register_process()` call, so adding a new module to the
 * gate is one extra array key in its own controller, not a change here.
 *
 * Designed to be reusable: any controller exposing a settings-modify action
 * (or any action that should defer to in-flight work) can call
 *
 *     $blocked = ( new ProcessGuard() )->ensure_can_modify_feature( $feature_option );
 *     if ( null !== $blocked ) { return $blocked; }
 *
 * The strict rule is: while ANY process that has declared this feature in its
 * `locks_features` is running, no save to that feature is permitted.
 * Distinguishing "disable" from "tweak a sub-option" is the wrong
 * granularity — config changes mid-run can break the running process
 * just as surely as disabling can. Strict matches the intent.
 *
 * Layered with the rest of the service tier:
 *
 *   Caller → ProcessGuard::ensure_can_modify_feature( $feature_option )
 *           → ProcessManager::get_all_registered_processes()  (in-memory)
 *           → ProcessStatusService::find_first_running( $types )  (one DB read,
 *             then per-type module verify only if Layer 1 found nothing)
 *
 * @package    Tailwatch
 * @subpackage Admin\App\Api\Services
 */

/**
 * Class ProcessGuard
 *
 */
class ProcessGuard {

	/**
	 * Process types that, when running, block ANY feature modification —
	 * regardless of which feature the user is trying to modify. These are
	 * site-wide operations that rewrite feature configuration in bulk
	 * (settings_import) or wipe it entirely (reset_all). While they're
	 * mid-flight, the feature row a user is editing might be the next row
	 * the system op overwrites, so all per-feature edits and per-feature
	 * resets must wait.
	 *
	 * Implemented as a separate constant from per-feature `locks_features`
	 * because these processes don't naturally tie to one specific feature
	 * row — they affect every feature.
	 *
	 * @var array<string>
	 */
	const SYSTEM_WIDE_BLOCKING_PROCESSES = array(
		'settings_import',
		'reset_all',
	);

	/**
	 * Process types that put back or replace the site's files and database. While one runs,
	 * plugins, themes and WordPress itself are left as they are: a change would be overwritten,
	 * or would land in a site that is half put back.
	 *
	 * @var array<string>
	 */
	const SITE_REPLACING_PROCESSES = array(
		'restore',
		'migration',
		'malware_restore',
	);

	/**
	 * Process types that change the site's files, database or settings. A paused run holds
	 * half-done work that such a change would leave it to finish against something different,
	 * so these do not start, or resume, while a run they conflict with is paused. A process that
	 * only reads the site (a backup, a scan, an audit) starts beside a paused run.
	 *
	 * @var array<string>
	 */
	const SITE_CHANGING_PROCESSES = array(
		'restore',
		'migration',
		'malware_restore',
		'search_replace',
		'db_optimize',
		'settings_import',
		'reset_all',
	);

	/**
	 * Friendly labels used when composing the user-facing rejection message.
	 * Falls back to the raw process_type when an entry is missing — works,
	 * just less polished.
	 *
	 * @var array<string,string>
	 */
	const PROCESS_TYPE_LABELS = array(
		'backup'                  => 'Backup',
		'backup_download'         => 'Backup Download',
		'db_optimize'             => 'Database Optimizer',
		'files_integrity'         => 'File Integrity Check',
		'baseline_update'         => 'Integrity Baseline Update',
		'malware_scan'            => 'Malware Scanner',
		'malware_restore'         => 'Malware Restoration',
		'search_replace'          => 'Search & Replace',
		'broken_link_checker'     => 'Broken Link Checker',
		'hardening_audit'         => 'Hardening Audit',
		'restore'                 => 'Site Restore',
		'migration'               => 'Site Migration',
		'migration_backup'        => 'Migration Backup',
		'migration_backup_upload' => 'Migration Backup Upload',
		'settings_import'         => 'Settings Import',
		'reset_all'               => 'Reset All Settings',
	);

	/**
	 * @var ProcessStatusService
	 */
	private $status_service;

	public function __construct() {
		$this->status_service = new ProcessStatusService();
	}

	/**
	 * Check whether the given feature can be modified right now.
	 *
	 * Returns the codebase's standardized rejected-action response array when
	 * a process that locks this feature is running — the caller's pattern is:
	 *
	 *     $blocked = ( new ProcessGuard() )->ensure_can_modify_feature( $feature['option'] );
	 *     if ( null !== $blocked ) {
	 *         return $blocked;
	 *     }
	 *
	 * Features with no locking processes (either none registered, or none
	 * running) return null — the caller proceeds as normal.
	 *
	 * @param string $feature_option Feature data_option key
	 *                               (e.g. 'default_backup_enable').
	 * @return array|null Blocked-response array, or null if safe to proceed.
	 */
	public function ensure_can_modify_feature( $feature_option ) {
		if ( ! is_string( $feature_option ) || '' === $feature_option ) {
			return null;
		}

		$locking_types = $this->find_locking_process_types( $feature_option );

		// System-wide blockers (settings_import, reset_all) rewrite or wipe every
		// feature row, so they block edits to ANY feature regardless of whether
		// that feature appears in any process's locks_features list.
		$types_to_check = array_values( array_unique( array_merge( $locking_types, self::SYSTEM_WIDE_BLOCKING_PROCESSES ) ) );

		$running = $this->status_service->find_first_running( $types_to_check );
		if ( null === $running ) {
			return null;
		}

		return $this->build_blocked_response( $running );
	}

	/**
	 * Check whether a stored artifact (backup folder, integrity comparison,
	 * malware report, etc.) can be deleted or mutated right now. Distinct
	 * from ensure_can_modify_feature: this gate covers physical artifacts that
	 * a running process is mid-way through reading or writing — folders on
	 * disk, JSON state files, scoped DB rows. Settings-modify and
	 * artifact-delete are different problems with different blast radii, so
	 * the consumer list is passed in by the caller rather than derived from
	 * locks_features. Each delete handler knows best which process_types use
	 * its artifacts.
	 *
	 * Caller pattern, at the top of any artifact-delete handler:
	 *
	 *     $blocked = ( new ProcessGuard() )->ensure_can_modify_artifacts(
	 *         array( 'backup', 'restore', 'migration' )
	 *     );
	 *     if ( null !== $blocked ) {
	 *         return $blocked;
	 *     }
	 *
	 * Returns null when the consumer list is empty or no consumer is running.
	 *
	 * @param array<string> $consumer_process_types Process types that read or
	 *                                              write the artifact and would
	 *                                              be disrupted by its deletion.
	 * @return array|null Blocked-response array, or null if safe to proceed.
	 */
	public function ensure_can_modify_artifacts( $consumer_process_types ) {
		if ( ! is_array( $consumer_process_types ) || empty( $consumer_process_types ) ) {
			return null;
		}

		$running = $this->status_service->find_first_running( $consumer_process_types );
		if ( null === $running ) {
			return null;
		}

		return $this->build_artifact_blocked_response( $running );
	}

	/**
	 * Build the standardized rejected-action response for blocked artifact
	 * deletes. Wording is distinct from build_blocked_response (settings) and
	 * build_start_blocked_response (process start) so users see a sensible
	 * reason for the rejection rather than a generic "wait" message.
	 *
	 * @param string $running_type The running process_type that triggered the block.
	 * @return array
	 */
	private function build_artifact_blocked_response( $running_type ) {
		$label = isset( self::PROCESS_TYPE_LABELS[ $running_type ] )
			? self::PROCESS_TYPE_LABELS[ $running_type ]
			: $running_type;

		$message = sprintf(
			/* translators: %s: name of the running module (e.g. "Backup"). */
			__( '%s is currently running. Please wait for it to finish before deleting these items.', 'tailwatch' ),
			$label
		);

		return array(
			'feature_data' => array(),
			'data'         => array(
				'blocked_by_process' => true,
				'running_process'    => $running_type,
			),
			'message'      => $message,
			'code'         => 409,
		);
	}

	/**
	 * Check whether a new instance of the given process_type can start right
	 * now, given which other processes are currently running.
	 *
	 * Each process declares `cannot_start_while` (a list of process_type
	 * names) on its ProcessManager::register_process() call. When the user
	 * triggers a start (e.g. clicks "Run Backup Now"), this guard checks the
	 * declared list and returns the standardized 409 response if any of those
	 * conflicting types is currently running.
	 *
	 * Caller pattern, at the top of every user-trigger AJAX handler:
	 *
	 *     $blocked = ( new ProcessGuard() )->ensure_can_start_process( 'backup' );
	 *     if ( null !== $blocked ) {
	 *         return $blocked;
	 *     }
	 *
	 * Process types with no `cannot_start_while` declared (or with an empty
	 * list) return null — they can always start. Internal sub-process starts
	 * (e.g. backup spawning db_optimize via tailwatch_database_optimize_start) do
	 * NOT go through this guard; only the user-trigger AJAX entry points do.
	 *
	 * @param string        $process_type The process_type the user is attempting to start.
	 * @param array<string> $ignore       Running processes this start belongs to, such as the
	 *                                    scan that backs the site up first.
	 * @return array|null Blocked-response array, or null if safe to proceed.
	 */
	public function ensure_can_start_process( $process_type, array $ignore = array() ) {
		if ( ! is_string( $process_type ) || '' === $process_type ) {
			return null;
		}

		$config             = ProcessManager::get_process_config( $process_type );
		$cannot_start_while = ( is_array( $config ) && ! empty( $config['cannot_start_while'] ) && is_array( $config['cannot_start_while'] ) )
			? $config['cannot_start_while']
			: array();

		// Self-exclusivity: a process_type never lists itself in its own
		// cannot_start_while (that array answers "what blocks me?", not "am I
		// already running?"), so the second-tab "click Run again" case would
		// otherwise slip through. Prepend the requested type to the check list
		// so self-starts and peer-conflicts are caught in a single DB read.
		$types_to_check = array_values( array_diff( array_unique( array_merge( array( $process_type ), $cannot_start_while ) ), $ignore ) );

		$running = $this->status_service->find_first_running( $types_to_check );
		if ( null !== $running ) {
			return $this->build_start_blocked_response( $running, $process_type );
		}

		// A paused run of another kind holds half-done work: a start that changes the site is
		// refused until that run is resumed and finished, or cancelled. A paused run of this kind
		// never blocks: it is the run a resume continues, or the one a new start replaces.
		if ( in_array( $process_type, self::SITE_CHANGING_PROCESSES, true ) ) {
			$paused = $this->status_service->find_first_paused( array_values( array_diff( $cannot_start_while, array( $process_type ), $ignore ) ) );
			if ( null !== $paused ) {
				return $this->build_start_blocked_response( $paused, $process_type, true );
			}
		}

		return null;
	}

	/**
	 * Check whether plugins, themes or WordPress itself may be changed right now: updated,
	 * rolled back, switched on or off, or deleted.
	 *
	 * @return array|null Blocked-response array, or null if safe to proceed.
	 */
	public function ensure_site_can_change() {
		$running = $this->status_service->find_first_running( self::SITE_REPLACING_PROCESSES );
		if ( null === $running ) {
			return null;
		}

		return $this->build_start_blocked_response( $running );
	}

	/**
	 * Claim the start of a job that takes over the site's files or database, and check again
	 * under the claim.
	 *
	 * Backup, restore and migration check with ensure_can_start_process() first, then spend
	 * seconds estimating sizes or reading archives before their process row exists. Two starts
	 * in that gap would both pass. Under the claim the check is asked again and the row created,
	 * so the later start finds the earlier one running:
	 *
	 *     $claim = ( new ProcessGuard() )->claim_start( 'restore' );
	 *     if ( is_array( $claim ) ) {
	 *         return $claim;
	 *     }
	 *     try {
	 *         // Create the process row.
	 *     } finally {
	 *         ProcessStartClaim::release( $claim );
	 *     }
	 *
	 * @param string        $process_type The process_type being started.
	 * @param array<string> $ignore       Running processes this start belongs to, such as the
	 *                                    scan that backs the site up first.
	 * @return array|string The blocked response, or the claim to release once the process row
	 *                      exists ('' when the claim could not be recorded).
	 */
	public function claim_start( $process_type, array $ignore = array() ) {
		$claim = ProcessStartClaim::claim( $process_type );
		if ( false === $claim ) {
			$holder = ProcessStartClaim::holder();
			return $this->build_start_blocked_response( '' !== $holder ? $holder : $process_type, $process_type );
		}

		$blocked = $this->ensure_can_start_process( $process_type, $ignore );
		if ( null !== $blocked ) {
			ProcessStartClaim::release( $claim );
			return $blocked;
		}

		return $claim;
	}

	/**
	 * Build the standardized rejected-action response for a blocked process
	 * start. Distinguished from build_blocked_response (which is for settings
	 * saves) only by the user-facing message wording.
	 *
	 * @param string      $running_type   The running process_type that triggered the block.
	 * @param string|null $requested_type The process_type the caller attempted to start.
	 *                                    When equal to $running_type the message switches
	 *                                    to "already in progress" wording so the user sees
	 *                                    a duplicate-start, not a peer-conflict.
	 * @param bool        $paused         True when the blocking run is paused, not running.
	 * @return array
	 */
	private function build_start_blocked_response( $running_type, $requested_type = null, $paused = false ) {
		$label = isset( self::PROCESS_TYPE_LABELS[ $running_type ] )
			? self::PROCESS_TYPE_LABELS[ $running_type ]
			: $running_type;

		if ( $paused ) {
			$message = sprintf(
				/* translators: %s: name of the paused module (e.g. "Backup"). */
				__( '%s is paused. Resume it and let it finish, or cancel it, before starting a new task.', 'tailwatch' ),
				$label
			);
		} elseif ( null !== $requested_type && $requested_type === $running_type ) {
			$message = sprintf(
				/* translators: %s: name of the running module (e.g. "Backup"). */
				__( 'A %s is already in progress. Please wait for it to finish before starting another.', 'tailwatch' ),
				$label
			);
		} else {
			$message = sprintf(
				/* translators: %s: name of the running module (e.g. "Malware Scanner"). */
				__( '%s is currently running. Please wait for it to finish before starting a new task.', 'tailwatch' ),
				$label
			);
		}

		return array(
			'feature_data' => array(),
			'data'         => $paused
				? array(
					'blocked_by_process' => true,
					'running_process'    => $running_type,
					'paused'             => true,
				)
				: array(
					'blocked_by_process' => true,
					'running_process'    => $running_type,
				),
			'message'      => $message,
			'code'         => 409,
		);
	}

	/**
	 * Scan the ProcessManager registry for every process_type that has
	 * declared this feature in its `locks_features` config. Pure in-memory
	 * lookup against the static registry — no DB.
	 *
	 * @param string $feature_option Feature data_option key.
	 * @return array<string> Process type names that lock the feature.
	 */
	private function find_locking_process_types( $feature_option ) {
		$matches = array();
		foreach ( ProcessManager::get_all_registered_processes() as $process_type => $config ) {
			if ( ! is_array( $config ) || empty( $config['locks_features'] ) || ! is_array( $config['locks_features'] ) ) {
				continue;
			}
			if ( in_array( $feature_option, $config['locks_features'], true ) ) {
				$matches[] = $process_type;
			}
		}
		return $matches;
	}

	/**
	 * Build the standardized rejected-action response for blocked saves.
	 *
	 * Uses HTTP 409 (Conflict) — the right semantic code for "valid action
	 * but the current state of the resource doesn't allow it right now".
	 * Other rejection reasons in the codebase use 400/403/404; 409 lets
	 * clients distinguish "wait then retry" from "input is wrong" without
	 * parsing the message.
	 *
	 * @param string $running_type The running process_type that triggered the block.
	 * @return array
	 */
	private function build_blocked_response( $running_type ) {
		$label = isset( self::PROCESS_TYPE_LABELS[ $running_type ] )
			? self::PROCESS_TYPE_LABELS[ $running_type ]
			: $running_type;

		$message = sprintf(
			/* translators: %s: name of the running module (e.g. "Malware Scanner"). */
			__( '%s is currently running. Please wait for it to finish before changing this setting.', 'tailwatch' ),
			$label
		);

		return array(
			'feature_data' => array(),
			'data'         => array(
				'blocked_by_process' => true,
				'running_process'    => $running_type,
			),
			'message'      => $message,
			'code'         => 409,
		);
	}
}