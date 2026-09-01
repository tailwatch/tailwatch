<?php
/**
 * Tables Optimize Controller
 *
 * Handles per-table optimization tasks (revisions, comments, transients, logs).
 *
 * @package    Tailwatch
 * @subpackage Admin\App\Api\Controllers\Database\DBOptimizer
 */

namespace Tailwatch\Admin\App\Api\Controllers\Database\DBOptimizer;

use Tailwatch\Admin\App\Api\Models\DatabaseOptimizationModel;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class TablesOptimizeController
 *
 */
class TablesOptimizeController {

	private $optimizer_model;
	private $optimizer_controller;

	public function __construct() {
		$this->optimizer_model      = new DatabaseOptimizationModel();
		$this->optimizer_controller = new DatabaseOptimizerController();
	}

	public function tailwatch_get_spam_comments( $number_interval ): array {
		$number_of_days = $this->optimizer_controller->tailwatch_optimize_maintain_data();
		return $this->optimizer_model->get_spam_comments( $number_interval, $number_of_days );
	}

	public function tailwatch_clean_all_spam_comments( array &$json_data, $number_interval ): void {
		$spam_comments  = $this->tailwatch_get_spam_comments( $number_interval );
		$total_count    = count( $spam_comments );
		$rows_processed = $json_data['spam_comments']['rows_processed'];
		$sum_rows       = $rows_processed + $total_count;

		$this->optimizer_controller->tailwatch_optimize_logs_records(
			0 === $total_count
				? 'Spam comments cleanup: no entries found'
				: 'Processing spam comments (rows ' . $rows_processed . '-' . $sum_rows . ')'
		);

		$json_data['spam_comments']['rows_processed'] = $sum_rows;
		$this->optimizer_controller->update_db_optimization_data( $json_data );

		foreach ( $spam_comments as $comment ) {
			$this->optimizer_model->delete_comment( (int) $comment->comment_ID );
		}

		if ( empty( $spam_comments ) ) {
			$json_data['spam_comments']['is_completed'] = true;
			$this->optimizer_controller->update_db_optimization_data( $json_data );
			$this->optimizer_controller->tailwatch_optimize_logs_records( 'Spam comments cleanup completed', 'OK' );
		}
	}

	public function tailwatch_get_trash_comments( $number_interval ): array {
		$number_of_days = $this->optimizer_controller->tailwatch_optimize_maintain_data();
		return $this->optimizer_model->get_trash_comments( $number_interval, $number_of_days );
	}

	public function tailwatch_clean_trash_comments( array &$json_data, $number_interval ): void {
		$trash_comments = $this->tailwatch_get_trash_comments( $number_interval );
		$total_count    = count( $trash_comments );
		$rows_processed = $json_data['trashed_comments']['rows_processed'];
		$sum_rows       = $rows_processed + $total_count;

		$this->optimizer_controller->tailwatch_optimize_logs_records(
			0 === $total_count
				? 'Trashed comments cleanup: no entries found'
				: 'Processing trashed comments (rows ' . $rows_processed . '-' . $sum_rows . ')'
		);

		$json_data['trashed_comments']['rows_processed'] = $sum_rows;
		$this->optimizer_controller->update_db_optimization_data( $json_data );

		foreach ( $trash_comments as $comment ) {
			$this->optimizer_model->delete_comment( (int) $comment->comment_ID );
		}

		if ( empty( $trash_comments ) ) {
			$json_data['trashed_comments']['is_completed'] = true;
			$this->optimizer_controller->update_db_optimization_data( $json_data );
			$this->optimizer_controller->tailwatch_optimize_logs_records( 'Trashed comments cleanup completed', 'OK' );
		}
	}

	public function tailwatch_get_drafts_post( $number_interval ): array {
		$number_of_days = $this->optimizer_controller->tailwatch_optimize_maintain_data();
		return $this->optimizer_model->get_auto_drafts( $number_interval, $number_of_days );
	}

	public function tailwatch_clean_auto_drafts( array &$json_data, $number_interval ): void {
		$auto_drafts    = $this->tailwatch_get_drafts_post( $number_interval );
		$total_count    = count( $auto_drafts );
		$rows_processed = $json_data['auto_drafts']['rows_processed'];
		$sum_rows       = $rows_processed + $total_count;

		$this->optimizer_controller->tailwatch_optimize_logs_records(
			0 === $total_count
				? 'Auto drafts cleanup: no entries found'
				: 'Processing auto drafts (rows ' . $rows_processed . '-' . $sum_rows . ')'
		);

		$json_data['auto_drafts']['rows_processed'] = $sum_rows;
		$this->optimizer_controller->update_db_optimization_data( $json_data );

		foreach ( $auto_drafts as $draft_id ) {
			$this->optimizer_model->delete_post( (int) $draft_id );
		}

		if ( empty( $auto_drafts ) ) {
			$json_data['auto_drafts']['is_completed'] = true;
			$this->optimizer_controller->update_db_optimization_data( $json_data );
			$this->optimizer_controller->tailwatch_optimize_logs_records( 'Auto drafts cleanup completed', 'OK' );
		}
	}

	public function tailwatch_get_trash_posts( $number_interval ): array {
		$number_of_days = $this->optimizer_controller->tailwatch_optimize_maintain_data();
		return $this->optimizer_model->get_trashed_posts( $number_interval, $number_of_days );
	}

	public function tailwatch_clean_trash_posts( array &$json_data, $number_interval ): void {
		$trashed_posts  = $this->tailwatch_get_trash_posts( $number_interval );
		$total_count    = count( $trashed_posts );
		$rows_processed = $json_data['trashed_posts']['rows_processed'];
		$sum_rows       = $rows_processed + $total_count;

		$this->optimizer_controller->tailwatch_optimize_logs_records(
			0 === $total_count
				? 'Trashed posts cleanup: no entries found'
				: 'Processing trashed posts (rows ' . $rows_processed . '-' . $sum_rows . ')'
		);

		$json_data['trashed_posts']['rows_processed'] = $sum_rows;
		$this->optimizer_controller->update_db_optimization_data( $json_data );

		foreach ( $trashed_posts as $post_id ) {
			$this->optimizer_model->delete_post( (int) $post_id );
		}

		if ( empty( $trashed_posts ) ) {
			$json_data['trashed_posts']['is_completed'] = true;
			$this->optimizer_controller->update_db_optimization_data( $json_data );
			$this->optimizer_controller->tailwatch_optimize_logs_records( 'Trashed posts cleanup completed', 'OK' );
		}
	}

	public function tailwatch_get_orphaned_post( $number_interval ): array {
		$number_of_days = $this->optimizer_controller->tailwatch_optimize_maintain_data();
		return $this->optimizer_model->get_orphaned_post_meta( $number_interval, $number_of_days );
	}

	public function tailwatch_clean_orphaned_post( array &$json_data, $number_interval ): void {
		$orphaned_meta  = $this->tailwatch_get_orphaned_post( $number_interval );
		$total_count    = count( $orphaned_meta );
		$rows_processed = $json_data['orphaned_post_meta']['rows_processed'];
		$sum_rows       = $rows_processed + $total_count;

		$this->optimizer_controller->tailwatch_optimize_logs_records(
			0 === $total_count
				? 'Orphaned post meta cleanup: no entries found'
				: 'Processing orphaned post meta (rows ' . $rows_processed . '-' . $sum_rows . ')'
		);

		$json_data['orphaned_post_meta']['rows_processed'] = $sum_rows;
		$this->optimizer_controller->update_db_optimization_data( $json_data );

		foreach ( $orphaned_meta as $meta ) {
			$this->optimizer_model->delete_post_meta( (int) $meta->meta_id );
		}

		if ( empty( $orphaned_meta ) ) {
			$json_data['orphaned_post_meta']['is_completed'] = true;
			$this->optimizer_controller->update_db_optimization_data( $json_data );
			$this->optimizer_controller->tailwatch_optimize_logs_records( 'Orphaned post meta cleanup completed', 'OK' );
		}
	}


	public function tailwatch_get_expired_transients( $number_interval ): array {
		return $this->optimizer_model->get_expired_transients( $number_interval );
	}

	public function tailwatch_clean_expired_transients( array &$json_data, $number_interval ): void {
		$expired_transients = $this->tailwatch_get_expired_transients( $number_interval );
		$total_count        = count( $expired_transients );
		$rows_processed     = $json_data['expired_transients']['rows_processed'];
		$sum_rows           = $rows_processed + $total_count;

		$this->optimizer_controller->tailwatch_optimize_logs_records(
			0 === $total_count
				? 'Expired transients cleanup: no entries found'
				: 'Processing expired transients (rows ' . $rows_processed . '-' . $sum_rows . ')'
		);

		$json_data['expired_transients']['rows_processed'] = $sum_rows;
		$this->optimizer_controller->update_db_optimization_data( $json_data );

		foreach ( $expired_transients as $transient_timeout ) {
			$this->optimizer_model->delete_expired_transient( $transient_timeout );
		}

		if ( empty( $expired_transients ) ) {
			$json_data['expired_transients']['is_completed'] = true;
			$this->optimizer_controller->update_db_optimization_data( $json_data );
			$this->optimizer_controller->tailwatch_optimize_logs_records( 'Expired transients cleanup completed', 'OK' );
		}
	}

	public function tailwatch_get_trackback_pingbacks( $number_interval ): array {
		$number_of_days = $this->optimizer_controller->tailwatch_optimize_maintain_data();
		return $this->optimizer_model->get_trackback_pingbacks( $number_interval, $number_of_days );
	}

	public function tailwatch_clean_trackback_pingbacks( array &$json_data, $number_interval ): void {
		$trackbacks_pingbacks = $this->tailwatch_get_trackback_pingbacks( $number_interval );
		$total_count          = count( $trackbacks_pingbacks );
		$rows_processed       = $json_data['trackbacks_pingbacks']['rows_processed'];
		$sum_rows             = $rows_processed + $total_count;

		$this->optimizer_controller->tailwatch_optimize_logs_records(
			0 === $total_count
				? 'Trackbacks/Pingbacks cleanup: no entries found'
				: 'Processing trackbacks/pingbacks (rows ' . $rows_processed . '-' . $sum_rows . ')'
		);

		$json_data['trackbacks_pingbacks']['rows_processed'] = $sum_rows;
		$this->optimizer_controller->update_db_optimization_data( $json_data );

		foreach ( $trackbacks_pingbacks as $comment ) {
			$this->optimizer_model->delete_comment( (int) $comment->comment_ID );
		}

		if ( empty( $trackbacks_pingbacks ) ) {
			$json_data['trackbacks_pingbacks']['is_completed'] = true;
			$this->optimizer_controller->update_db_optimization_data( $json_data );
			$this->optimizer_controller->tailwatch_optimize_logs_records( 'Trackbacks/Pingbacks cleanup completed', 'OK' );
		}
	}

	public function tailwatch_get_logs( $option, $number_interval = null ): array {
		$number_of_days = $this->optimizer_controller->tailwatch_optimize_maintain_data();
		return $this->optimizer_model->get_logs_by_option( $option, $number_of_days, $number_interval );
	}

	public function tailwatch_clean_logs_activity( array &$json_data, $number_interval = null ): void {
		$logs_activity  = $this->tailwatch_get_logs( 'default_logs_activity', $number_interval );
		$total_count    = count( $logs_activity );
		$rows_processed = $json_data['logs_activity']['rows_processed'] ?? 0;
		$sum_rows       = $rows_processed + $total_count;

		$this->optimizer_controller->tailwatch_optimize_logs_records(
			$total_count === 0
				? 'Activity logs cleanup: no entries found'
				: "Processing activity logs (rows {$rows_processed}-{$sum_rows})"
		);

		$json_data['logs_activity']['rows_processed'] = $sum_rows;
		$this->optimizer_controller->update_db_optimization_data( $json_data );

		foreach ( $logs_activity as $log_id ) {
			$this->optimizer_model->delete_data_by_id( (int) $log_id );
		}

		if ( empty( $logs_activity ) ) {
			$json_data['logs_activity']['is_completed'] = true;
			$this->optimizer_controller->update_db_optimization_data( $json_data );
			$this->optimizer_controller->tailwatch_optimize_logs_records( 'Activity logs cleanup completed', 'OK' );
		}
	}

	public function tailwatch_clean_network_logs( array &$json_data, $number_interval = null ): void {
		$network_logs        = $this->tailwatch_get_logs( 'default_network_logs', $number_interval );
		$total_network_logs  = $this->tailwatch_get_logs( 'default_network_logs', null );
		$count_total_logs = count( $total_network_logs );
		$total_count      = count( $network_logs );
		$rows_processed   = $json_data['network_logs']['rows_processed'] ?? 0;
		$sum_rows         = $rows_processed + $total_count;

		$this->optimizer_controller->tailwatch_optimize_logs_records(
			$total_count === 0
				? 'Network Logs cleanup: no entries found'
				: "Processing Network Logs (rows {$rows_processed}-{$sum_rows})"
		);

		if ( ! isset( $json_data['network_logs']['total_counts'] ) ) {
			$json_data['network_logs']['total_counts'] = $count_total_logs;
		}

		$json_data['network_logs']['rows_processed'] = $sum_rows;
		$this->optimizer_controller->update_db_optimization_data( $json_data );

		foreach ( $network_logs as $log_id ) {
			$this->optimizer_model->delete_data_by_id( (int) $log_id );
		}

		$verify_total_counts = $json_data['network_logs']['total_counts'];
		$total_rows_counts   = $json_data['network_logs']['rows_processed'];

		if ( $verify_total_counts === $total_rows_counts || empty( $network_logs ) ) {
			$json_data['network_logs']['is_completed'] = true;
			$this->optimizer_controller->update_db_optimization_data( $json_data );
			$this->optimizer_controller->tailwatch_optimize_logs_records( 'Network Logs cleanup completed', 'OK' );
		}
	}

	public function tailwatch_clean_monitoring_logs( array &$json_data, $number_interval = null ): void {
		$monitoring_logs = $this->tailwatch_get_logs( 'default_monitoring_logs', $number_interval );
		$total_count     = count( $monitoring_logs );
		$rows_processed  = isset( $json_data['monitoring_logs']['rows_processed'] ) ? $json_data['monitoring_logs']['rows_processed'] : 0;
		$sum_rows        = $rows_processed + $total_count;

		$this->optimizer_controller->tailwatch_optimize_logs_records(
			0 === $total_count
				? 'Error logs cleanup: no entries found'
				: 'Processing error logs (rows ' . $rows_processed . '-' . $sum_rows . ')'
		);

		$json_data['monitoring_logs']['rows_processed'] = $sum_rows;
		$this->optimizer_controller->update_db_optimization_data( $json_data );

		foreach ( $monitoring_logs as $log_id ) {
			$this->optimizer_model->delete_data_by_id( (int) $log_id );
		}

		if ( empty( $monitoring_logs ) ) {
			$json_data['monitoring_logs']['is_completed'] = true;
			$this->optimizer_controller->update_db_optimization_data( $json_data );
			$this->optimizer_controller->tailwatch_optimize_logs_records( 'Error logs cleanup completed', 'OK' );
		}
	}

	public function tailwatch_clean_email_logs( array &$json_data, $number_interval = null ): void {
		$email_logs     = $this->tailwatch_get_logs( 'default_email_logs', $number_interval );
		$total_count    = count( $email_logs );
		$rows_processed = isset( $json_data['email_logs']['rows_processed'] ) ? $json_data['email_logs']['rows_processed'] : 0;
		$sum_rows       = $rows_processed + $total_count;

		$this->optimizer_controller->tailwatch_optimize_logs_records(
			0 === $total_count
				? 'Email logs cleanup: no entries found'
				: 'Processing email logs (rows ' . $rows_processed . '-' . $sum_rows . ')'
		);

		$json_data['email_logs']['rows_processed'] = $sum_rows;
		$this->optimizer_controller->update_db_optimization_data( $json_data );

		foreach ( $email_logs as $log_id ) {
			$this->optimizer_model->delete_data_by_id( (int) $log_id );
		}

		if ( empty( $email_logs ) ) {
			$json_data['email_logs']['is_completed'] = true;
			$this->optimizer_controller->update_db_optimization_data( $json_data );
			$this->optimizer_controller->tailwatch_optimize_logs_records( 'Email logs cleanup completed', 'OK' );
		}
	}
}
