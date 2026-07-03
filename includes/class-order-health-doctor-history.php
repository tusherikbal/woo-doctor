<?php
/**
 * Health-score history (daily snapshots for the dashboard trend sparkline).
 *
 * @package Order_Health_Doctor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Order_Health_Doctor_History
 *
 * Stores a tiny daily snapshot of the order-health score and open-issue total so
 * the dashboard can draw a trend sparkline. Kept in a single option (capped at 30
 * entries) — no extra table, negligible footprint.
 */
class Order_Health_Doctor_History {

	const OPTION   = 'ohd_health_history';
	const MAX_DAYS = 30;

	/**
	 * Record today's snapshot (idempotent per calendar day).
	 *
	 * The latest scan of the day wins, so the number reflects the current state.
	 *
	 * @return void
	 */
	public static function record_snapshot() {
		$counts = Order_Health_Doctor_Issue_Repository::get_issue_counts();
		$score  = Order_Health_Doctor_Admin::calculate_health_score( $counts );
		$today  = current_time( 'Y-m-d' );

		$history = self::get_all();

		// Replace today's entry if it exists, otherwise append.
		$history[ $today ] = array(
			'date'  => $today,
			'score' => (int) $score,
			'total' => isset( $counts['total'] ) ? (int) $counts['total'] : 0,
		);

		// Keep only the most recent MAX_DAYS entries.
		ksort( $history );
		if ( count( $history ) > self::MAX_DAYS ) {
			$history = array_slice( $history, - self::MAX_DAYS, null, true );
		}

		update_option( self::OPTION, $history );
	}

	/**
	 * Get the raw history keyed by date.
	 *
	 * @return array
	 */
	public static function get_all() {
		$history = get_option( self::OPTION, array() );
		return is_array( $history ) ? $history : array();
	}

	/**
	 * Get the last N days of snapshots as a flat, date-ordered list.
	 *
	 * @param int $days Number of days.
	 * @return array[] List of { date, score, total }.
	 */
	public static function get_series( $days = 14 ) {
		$history = self::get_all();
		ksort( $history );
		$series = array_values( $history );

		$days = max( 1, (int) $days );
		if ( count( $series ) > $days ) {
			$series = array_slice( $series, - $days );
		}

		return $series;
	}
}
