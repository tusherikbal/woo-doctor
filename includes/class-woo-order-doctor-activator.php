<?php
/**
 * Plugin activation routines.
 *
 * @package Woo_Order_Doctor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Woo_Order_Doctor_Activator
 *
 * Runs once when the plugin is activated. Responsible for creating the custom
 * table, seeding default settings, storing the plugin version, and scheduling
 * the daily scan cron event.
 */
class Woo_Order_Doctor_Activator {

	/**
	 * Perform activation tasks.
	 */
	public static function activate() {
		// Create / upgrade the custom issues table.
		Woo_Order_Doctor_DB::create_table();

		// Store the current plugin version so future upgrades can compare.
		update_option( 'wod_db_version', WOO_ORDER_DOCTOR_VERSION );

		// Seed default settings only if they do not exist yet.
		if ( false === get_option( 'wod_settings', false ) ) {
			add_option( 'wod_settings', Woo_Order_Doctor_Settings::get_defaults() );
		}

		// Schedule the daily scan if it is not already scheduled.
		if ( ! wp_next_scheduled( 'woo_order_doctor_daily_scan' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'woo_order_doctor_daily_scan' );
		}
	}
}
