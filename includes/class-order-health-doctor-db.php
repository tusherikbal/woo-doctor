<?php
/**
 * Database helper.
 *
 * @package Order_Health_Doctor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Order_Health_Doctor_DB
 *
 * Owns everything related to the plugin's custom issues table: its name and
 * its schema creation via dbDelta. Keeping this in one place makes the table
 * definition easy to review and maintain.
 */
class Order_Health_Doctor_DB {

	/**
	 * Return the fully-prefixed custom table name.
	 *
	 * @return string Table name including the site table prefix.
	 */
	public static function table_name() {
		global $wpdb;
		return $wpdb->prefix . 'order_health_doctor_issues';
	}

	/**
	 * Create (or upgrade) the custom issues table using dbDelta.
	 *
	 * The dbDelta function compares the desired schema against the existing one and applies
	 * the difference, so this method is safe to run on every activation.
	 */
	public static function create_table() {
		global $wpdb;

		$table_name      = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();

		// Note: dbDelta is whitespace and formatting sensitive, so the SQL
		// below follows its expected conventions (two spaces after PRIMARY KEY,
		// lowercase types, one field per line).
		$sql = "CREATE TABLE {$table_name} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			issue_hash varchar(64) NOT NULL,
			issue_type varchar(80) NOT NULL,
			severity varchar(20) NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'open',
			object_type varchar(30) NULL,
			object_id bigint(20) unsigned NULL,
			related_object_id bigint(20) unsigned NULL,
			title text NOT NULL,
			message longtext NOT NULL,
			suggested_action longtext NULL,
			metadata longtext NULL,
			last_notified_at datetime NULL,
			notification_count int(10) unsigned NOT NULL DEFAULT 0,
			detected_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY issue_hash (issue_hash),
			KEY issue_type (issue_type),
			KEY severity (severity),
			KEY status (status),
			KEY status_severity_detected (status, severity, detected_at),
			KEY object_type (object_type),
			KEY object_id (object_id),
			KEY object_lookup (object_type, object_id, status),
			KEY detected_at (detected_at)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Upgrade the schema when the stored DB version differs from the plugin's.
	 *
	 * The dbDelta function is safe to run repeatedly and will ALTER the existing table to add
	 * any new columns (e.g. the email-notification tracking columns) without the
	 * admin needing to deactivate/reactivate. Cheap to call, but we only run the
	 * heavier dbDelta when the version actually changed.
	 */
	public static function maybe_upgrade() {
		$installed = get_option( 'ohd_db_version' );

		if ( ORDER_HEALTH_DOCTOR_VERSION === $installed ) {
			return;
		}

		self::create_table();
		update_option( 'ohd_db_version', ORDER_HEALTH_DOCTOR_VERSION );
	}
}
