<?php
/**
 * Dependency checker.
 *
 * @package Woo_Order_Doctor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Woo_Order_Doctor_Dependency
 *
 * Small helper that answers a single question: is WooCommerce active and
 * usable? The whole plugin keys off this so we never fatal error when
 * WooCommerce is missing.
 */
class Woo_Order_Doctor_Dependency {

	/**
	 * Check whether WooCommerce is active and loaded.
	 *
	 * We test for the WooCommerce class and the wc_get_orders() helper because
	 * those are the two things this plugin actually depends on.
	 *
	 * @return bool True when WooCommerce is available.
	 */
	public static function is_woocommerce_active() {
		return class_exists( 'WooCommerce' ) && function_exists( 'wc_get_orders' );
	}
}
