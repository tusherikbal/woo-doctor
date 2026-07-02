<?php
/**
 * Issue repository: all reads/writes to the custom issues table.
 *
 * @package Woo_Order_Doctor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Woo_Order_Doctor_Issue_Repository
 *
 * Encapsulates every database interaction with the custom issues table so the
 * rest of the plugin never writes raw SQL. All queries use $wpdb->prepare for
 * dynamic values.
 */
class Woo_Order_Doctor_Issue_Repository {

	/**
	 * Allowed status values. Used to validate enum-style input.
	 *
	 * @var string[]
	 */
	private static $valid_statuses = array( 'open', 'reviewed', 'resolved', 'ignored' );

	/**
	 * Allowed severity values.
	 *
	 * @var string[]
	 */
	private static $valid_severities = array( 'critical', 'high', 'medium', 'low', 'info' );

	/**
	 * Generate a stable hash that uniquely identifies a logical issue.
	 *
	 * The hash is built from the issue type plus the objects it refers to, so
	 * the same problem detected repeatedly maps to a single row.
	 *
	 * @param string $issue_type        Issue type slug.
	 * @param string $object_type       Object type (order, product, etc.).
	 * @param int    $object_id         Primary object ID.
	 * @param int    $related_object_id Optional related object ID.
	 * @return string 64-char SHA-256 hash.
	 */
	public static function build_hash( $issue_type, $object_type, $object_id, $related_object_id = 0 ) {
		$parts = array(
			$issue_type,
			$object_type,
			(int) $object_id,
			(int) $related_object_id,
		);
		return hash( 'sha256', implode( '|', $parts ) );
	}

	/**
	 * Create a new issue or update/reopen an existing one (idempotent).
	 *
	 * Behaviour:
	 * - If no row with this hash exists, insert a new "open" issue.
	 * - If a row exists and is already open, just bump updated_at.
	 * - If a row exists but was resolved/reviewed/ignored, reopen it to "open".
	 *
	 * @param array $data {
	 *     Issue data.
	 *
	 *     @type string $issue_type        Required. Issue type slug.
	 *     @type string $severity          Required. Severity.
	 *     @type string $object_type       Object type.
	 *     @type int    $object_id         Primary object ID.
	 *     @type int    $related_object_id Related object ID.
	 *     @type string $title             Short title.
	 *     @type string $message           Human-readable message.
	 *     @type string $suggested_action  Suggested action text.
	 *     @type array  $metadata          Optional metadata (stored as JSON).
	 * }
	 * @return array|false {
	 *     Result array on success, false on failure. Lets callers (e.g. the
	 *     scanner) know whether an issue is brand new or was reopened so the
	 *     email notifier can decide whether to alert.
	 *
	 *     @type int  $id          Issue ID.
	 *     @type bool $is_new      True when a new row was inserted.
	 *     @type bool $is_reopened True when an existing closed issue became open.
	 * }
	 */
	public static function create_or_update_issue( $data ) {
		global $wpdb;

		$table = Woo_Order_Doctor_DB::table_name();

		// Validate the enum-style fields before trusting them.
		$issue_type  = sanitize_key( $data['issue_type'] );
		$severity    = in_array( $data['severity'], self::$valid_severities, true ) ? $data['severity'] : 'info';
		$object_type = isset( $data['object_type'] ) ? sanitize_key( $data['object_type'] ) : 'system';
		$object_id   = isset( $data['object_id'] ) ? absint( $data['object_id'] ) : 0;
		$related_id  = isset( $data['related_object_id'] ) ? absint( $data['related_object_id'] ) : 0;

		$hash = self::build_hash( $issue_type, $object_type, $object_id, $related_id );
		$now  = current_time( 'mysql' );

		// Look up an existing row by its unique hash.
		$existing = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, status FROM {$table} WHERE issue_hash = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$hash
			)
		);

		if ( $existing ) {
			// Re-detecting an issue always sets it back to "open" (this both keeps
			// open issues open and reopens any previously resolved/ignored ones).
			$new_status  = 'open';
			$is_reopened = ( 'open' !== $existing->status );

			$wpdb->update(
				$table,
				array(
					'severity'         => $severity,
					'title'            => $data['title'],
					'message'          => $data['message'],
					'suggested_action' => isset( $data['suggested_action'] ) ? $data['suggested_action'] : '',
					'metadata'         => isset( $data['metadata'] ) ? wp_json_encode( $data['metadata'] ) : null,
					'status'           => $new_status,
					'updated_at'       => $now,
				),
				array( 'id' => $existing->id ),
				array( '%s', '%s', '%s', '%s', '%s', '%s', '%s' ),
				array( '%d' )
			);

			return array(
				'id'          => (int) $existing->id,
				'is_new'      => false,
				'is_reopened' => $is_reopened,
			);
		}

		// Insert a brand new issue.
		$inserted = $wpdb->insert(
			$table,
			array(
				'issue_hash'        => $hash,
				'issue_type'        => $issue_type,
				'severity'          => $severity,
				'status'            => 'open',
				'object_type'       => $object_type,
				'object_id'         => $object_id ? $object_id : null,
				'related_object_id' => $related_id ? $related_id : null,
				'title'             => $data['title'],
				'message'           => $data['message'],
				'suggested_action'  => isset( $data['suggested_action'] ) ? $data['suggested_action'] : '',
				'metadata'          => isset( $data['metadata'] ) ? wp_json_encode( $data['metadata'] ) : null,
				'detected_at'       => $now,
				'updated_at'        => $now,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		if ( ! $inserted ) {
			return false;
		}

		return array(
			'id'          => (int) $wpdb->insert_id,
			'is_new'      => true,
			'is_reopened' => false,
		);
	}

	/**
	 * Record that a notification email was sent for an issue.
	 *
	 * Sets last_notified_at to now and increments notification_count. Used by
	 * the email notifier for spam prevention.
	 *
	 * @param int $issue_id Issue ID.
	 * @return bool True on success.
	 */
	public static function mark_notified( $issue_id ) {
		global $wpdb;

		$table    = Woo_Order_Doctor_DB::table_name();
		$issue_id = absint( $issue_id );

		// Bump the counter and timestamp in a single prepared statement.
		$result = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET last_notified_at = %s, notification_count = notification_count + 1 WHERE id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				current_time( 'mysql' ),
				$issue_id
			)
		);

		return false !== $result;
	}

	/**
	 * Check whether an issue was notified within the cooldown window.
	 *
	 * Prevents sending repeated immediate alerts for the same issue.
	 *
	 * @param int $issue_id       Issue ID.
	 * @param int $cooldown_hours Cooldown window in hours. Default 24.
	 * @return bool True when a notification was sent inside the window.
	 */
	public static function was_recently_notified( $issue_id, $cooldown_hours = 24 ) {
		global $wpdb;

		$table    = Woo_Order_Doctor_DB::table_name();
		$issue_id = absint( $issue_id );

		$last_notified = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT last_notified_at FROM {$table} WHERE id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$issue_id
			)
		);

		// Never notified, or stored as NULL.
		if ( empty( $last_notified ) || '0000-00-00 00:00:00' === $last_notified ) {
			return false;
		}

		// Compare the stored timestamp to "now" using the same site-local clock
		// (both parsed by strtotime) so the difference is timezone-consistent.
		$last_ts  = strtotime( $last_notified );
		$now_ts   = strtotime( current_time( 'mysql' ) );
		$cooldown = absint( $cooldown_hours ) * HOUR_IN_SECONDS;

		return ( $last_ts && ( $now_ts - $last_ts ) < $cooldown );
	}

	/**
	 * Get a filtered list of issues.
	 *
	 * @param array $args {
	 *     Optional query arguments.
	 *
	 *     @type string $status     Filter by status.
	 *     @type string $severity   Filter by severity.
	 *     @type string $issue_type Filter by issue type.
	 *     @type int    $object_id  Filter/search by object ID.
	 *     @type int    $limit      Max rows. Default 200.
	 *     @type int    $offset     Offset. Default 0.
	 * }
	 * @return array Array of issue row objects.
	 */
	public static function get_issues( $args = array() ) {
		global $wpdb;

		$table = Woo_Order_Doctor_DB::table_name();

		$where  = array( '1=1' );
		$params = array();

		if ( ! empty( $args['status'] ) && in_array( $args['status'], self::$valid_statuses, true ) ) {
			$where[]  = 'status = %s';
			$params[] = $args['status'];
		}

		if ( ! empty( $args['severity'] ) && in_array( $args['severity'], self::$valid_severities, true ) ) {
			$where[]  = 'severity = %s';
			$params[] = $args['severity'];
		}

		if ( ! empty( $args['issue_type'] ) ) {
			$where[]  = 'issue_type = %s';
			$params[] = sanitize_key( $args['issue_type'] );
		}

		if ( ! empty( $args['object_id'] ) ) {
			// Match either the primary or the related object ID.
			$where[]  = '( object_id = %d OR related_object_id = %d )';
			$params[] = absint( $args['object_id'] );
			$params[] = absint( $args['object_id'] );
		}

		$limit  = isset( $args['limit'] ) ? absint( $args['limit'] ) : 200;
		$offset = isset( $args['offset'] ) ? absint( $args['offset'] ) : 0;

		$where_sql = implode( ' AND ', $where );

		// Order by severity weight then most recent. We build a CASE so the most
		// urgent issues surface first regardless of detection order.
		$sql = "SELECT * FROM {$table} WHERE {$where_sql}
			ORDER BY FIELD(severity,'critical','high','medium','low','info'), detected_at DESC
			LIMIT %d OFFSET %d";

		$params[] = $limit;
		$params[] = $offset;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return $wpdb->get_results( $wpdb->prepare( $sql, $params ) );
	}

	/**
	 * Get counts of open issues grouped by severity, plus a total.
	 *
	 * Used by the dashboard health score and summary cards.
	 *
	 * @return array{total:int,critical:int,high:int,medium:int,low:int,info:int}
	 */
	public static function get_issue_counts() {
		global $wpdb;

		$table = Woo_Order_Doctor_DB::table_name();

		$counts = array(
			'total'    => 0,
			'critical' => 0,
			'high'     => 0,
			'medium'   => 0,
			'low'      => 0,
			'info'     => 0,
		);

		// Only "open" issues count toward the health score / summary.
		$rows = $wpdb->get_results(
			"SELECT severity, COUNT(*) AS num FROM {$table} WHERE status = 'open' GROUP BY severity" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		foreach ( $rows as $row ) {
			$severity = $row->severity;
			$num      = (int) $row->num;
			if ( isset( $counts[ $severity ] ) ) {
				$counts[ $severity ] = $num;
			}
			$counts['total'] += $num;
		}

		return $counts;
	}

	/**
	 * Get the most recently detected open issues.
	 *
	 * @param int $limit Max rows. Default 10.
	 * @return array Issue rows.
	 */
	public static function get_recent_issues( $limit = 10 ) {
		global $wpdb;

		$table = Woo_Order_Doctor_DB::table_name();
		$limit = absint( $limit );

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE status = 'open' ORDER BY detected_at DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$limit
			)
		);
	}

	/**
	 * Update the status of a single issue.
	 *
	 * @param int    $issue_id Issue ID.
	 * @param string $status   New status (validated against the allowed list).
	 * @return bool True on success.
	 */
	public static function update_status( $issue_id, $status ) {
		global $wpdb;

		// Reject any status that is not in our known set.
		if ( ! in_array( $status, self::$valid_statuses, true ) ) {
			return false;
		}

		$table = Woo_Order_Doctor_DB::table_name();

		$result = $wpdb->update(
			$table,
			array(
				'status'     => $status,
				'updated_at' => current_time( 'mysql' ),
			),
			array( 'id' => absint( $issue_id ) ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		return false !== $result;
	}

	/**
	 * Delete resolved issues older than a given number of days.
	 *
	 * Housekeeping helper to stop the table growing forever.
	 *
	 * @param int $days Age threshold in days.
	 * @return int Number of rows deleted.
	 */
	public static function delete_resolved_older_than( $days ) {
		global $wpdb;

		$table     = Woo_Order_Doctor_DB::table_name();
		$days      = absint( $days );
		$threshold = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );

		return (int) $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE status = 'resolved' AND updated_at < %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$threshold
			)
		);
	}

	/**
	 * Get a single issue by its ID.
	 *
	 * @param int $issue_id Issue ID.
	 * @return object|null Issue row, or null when not found.
	 */
	public static function get_issue_by_id( $issue_id ) {
		global $wpdb;

		$table = Woo_Order_Doctor_DB::table_name();

		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				absint( $issue_id )
			)
		);
	}

	/**
	 * Get open issues attached to a specific object (e.g. an order).
	 *
	 * Used by the order edit meta box to show relevant problems.
	 *
	 * @param string $object_type Object type.
	 * @param int    $object_id   Object ID.
	 * @return array Issue rows.
	 */
	public static function get_open_issues_for_object( $object_type, $object_id ) {
		global $wpdb;

		$table = Woo_Order_Doctor_DB::table_name();

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table}
				WHERE status = 'open' AND object_type = %s AND ( object_id = %d OR related_object_id = %d )
				ORDER BY FIELD(severity,'critical','high','medium','low','info')", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				sanitize_key( $object_type ),
				absint( $object_id ),
				absint( $object_id )
			)
		);
	}
}
