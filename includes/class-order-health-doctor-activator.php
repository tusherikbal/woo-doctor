<?php
/**
 * Plugin activation routines.
 *
 * @package Order_Health_Doctor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Order_Health_Doctor_Activator
 *
 * Runs once when the plugin is activated. Responsible for creating the custom
 * table, seeding default settings, storing the plugin version, and scheduling
 * the daily scan cron event.
 */
class Order_Health_Doctor_Activator {

	/**
	 * Perform activation tasks.
	 */
	public static function activate() {
		// Create / upgrade the custom issues table.
		Order_Health_Doctor_DB::create_table();

		// Store the current plugin version so future upgrades can compare.
		update_option( 'ohd_db_version', ORDER_HEALTH_DOCTOR_VERSION );

		// Seed default settings only if they do not exist yet.
		if ( false === get_option( 'ohd_settings', false ) ) {
			add_option( 'ohd_settings', Order_Health_Doctor_Settings::get_defaults() );
		}

		// Schedule the daily scan if it is not already scheduled.
		if ( ! wp_next_scheduled( 'order_health_doctor_daily_scan' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'order_health_doctor_daily_scan' );
		}
	}
}
