<?php
/**
 * Plugin deactivation routines.
 *
 * @package Woo_Order_Doctor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Woo_Order_Doctor_Deactivator
 *
 * Runs once when the plugin is deactivated. We only clear the scheduled cron
 * event here. Data (table + settings) is intentionally left untouched on
 * deactivation; removal is handled by uninstall.php based on a setting.
 */
class Woo_Order_Doctor_Deactivator {

	/**
	 * Perform deactivation tasks.
	 */
	public static function deactivate() {
		// Remove the scheduled daily scan so it does not keep running.
		$timestamp = wp_next_scheduled( 'woo_order_doctor_daily_scan' );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, 'woo_order_doctor_daily_scan' );
		}

		// Belt-and-braces: clear any remaining hooks of this event.
		wp_clear_scheduled_hook( 'woo_order_doctor_daily_scan' );
	}
}
