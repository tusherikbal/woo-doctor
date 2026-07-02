<?php
/**
 * Uninstall handler for Woo Order Doctor.
 *
 * Runs when the plugin is deleted from the WordPress admin. It only removes
 * data when the admin explicitly opted in via the "Delete plugin data on
 * uninstall" setting; otherwise everything is left intact.
 *
 * @package Woo_Order_Doctor
 */

// This file must only ever be loaded by WordPress' uninstall routine.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Read the relevant setting directly (the plugin classes are not loaded here).
 */
$wod_settings = get_option( 'wod_settings', array() );
$wod_delete   = is_array( $wod_settings ) && isset( $wod_settings['delete_data_on_uninstall'] )
	? $wod_settings['delete_data_on_uninstall']
	: 'no';

// Respect the admin's choice: keep data unless they asked to delete it.
if ( 'yes' !== $wod_delete ) {
	return;
}

global $wpdb;

// Drop the custom issues table.
$wod_table = $wpdb->prefix . 'woo_order_doctor_issues';
// Table name is built from a trusted prefix + constant; safe to interpolate.
$wpdb->query( "DROP TABLE IF EXISTS {$wod_table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

// Remove plugin options.
delete_option( 'wod_settings' );
delete_option( 'wod_db_version' );
delete_option( 'wod_last_scan' );
delete_option( 'wod_last_daily_summary' );

// Remove per-user dismissal meta.
delete_metadata( 'user', 0, 'wod_notice_dismissed_day', '', true );

// Clear any lingering scheduled event.
wp_clear_scheduled_hook( 'woo_order_doctor_daily_scan' );
