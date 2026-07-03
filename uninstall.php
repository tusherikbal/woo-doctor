<?php
/**
 * Uninstall handler for Order Health Doctor.
 *
 * Runs when the plugin is deleted from the WordPress admin. It only removes
 * data when the admin explicitly opted in via the "Delete plugin data on
 * uninstall" setting; otherwise everything is left intact.
 *
 * @package Order_Health_Doctor
 */

// This file must only ever be loaded by WordPress' uninstall routine.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Read the relevant setting directly (the plugin classes are not loaded here).
 */
$ohd_settings = get_option( 'ohd_settings', array() );
$ohd_delete   = is_array( $ohd_settings ) && isset( $ohd_settings['delete_data_on_uninstall'] )
	? $ohd_settings['delete_data_on_uninstall']
	: 'no';

// Respect the admin's choice: keep data unless they asked to delete it.
if ( 'yes' !== $ohd_delete ) {
	return;
}

global $wpdb;

// Drop the custom issues table.
$ohd_table = $wpdb->prefix . 'order_health_doctor_issues';
// Table name is built from a trusted prefix + constant; safe to interpolate.
$wpdb->query( "DROP TABLE IF EXISTS {$ohd_table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

// Remove plugin options.
delete_option( 'ohd_settings' );
delete_option( 'ohd_db_version' );
delete_option( 'ohd_last_scan' );
delete_option( 'ohd_last_daily_summary' );
delete_option( 'ohd_last_summary_email' );
delete_option( 'ohd_last_summary_telegram' );
delete_option( 'ohd_health_history' );
delete_option( 'ohd_scan_lock' );
// Remove per-issue notification cooldown transients.
$ohd_transient_like = $wpdb->esc_like( '_transient_ohd_notified_' ) . '%';
$ohd_timeout_like   = $wpdb->esc_like( '_transient_timeout_ohd_notified_' ) . '%';
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		$ohd_transient_like,
		$ohd_timeout_like
	)
);
// Remove per-user dismissal meta.
delete_metadata( 'user', 0, 'ohd_notice_dismissed_day', '', true );

// Clear any lingering scheduled event.
wp_clear_scheduled_hook( 'order_health_doctor_daily_scan' );
