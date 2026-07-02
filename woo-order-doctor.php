<?php
/**
 * Plugin Name:       Woo Order Doctor
 * Plugin URI:        https://example.com/woo-order-doctor
 * Description:       Detects hidden WooCommerce order problems (stuck orders, failed order spikes, duplicates, stock and email config issues) before customers complain.
 * Version:           1.1.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Woo Order Doctor
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       woo-order-doctor
 * Domain Path:       /languages
 *
 * @package Woo_Order_Doctor
 */

// Block direct access to this file.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * --------------------------------------------------------------------------
 * Plugin constants.
 * --------------------------------------------------------------------------
 * These constants are used throughout the plugin so paths, URLs and the
 * version number are defined in a single place.
 */
define( 'WOO_ORDER_DOCTOR_VERSION', '1.1.0' );
define( 'WOO_ORDER_DOCTOR_FILE', __FILE__ );
define( 'WOO_ORDER_DOCTOR_PATH', plugin_dir_path( __FILE__ ) );
define( 'WOO_ORDER_DOCTOR_URL', plugin_dir_url( __FILE__ ) );
define( 'WOO_ORDER_DOCTOR_TEXT_DOMAIN', 'woo-order-doctor' );

/*
 * --------------------------------------------------------------------------
 * Load core class files.
 * --------------------------------------------------------------------------
 * The MVP keeps things simple by requiring the class files directly instead
 * of using a Composer autoloader (Composer is intentionally not used).
 */
require_once WOO_ORDER_DOCTOR_PATH . 'includes/class-woo-order-doctor-dependency.php';
require_once WOO_ORDER_DOCTOR_PATH . 'includes/class-woo-order-doctor-db.php';
require_once WOO_ORDER_DOCTOR_PATH . 'includes/class-woo-order-doctor-activator.php';
require_once WOO_ORDER_DOCTOR_PATH . 'includes/class-woo-order-doctor-deactivator.php';
require_once WOO_ORDER_DOCTOR_PATH . 'includes/class-woo-order-doctor-settings.php';
require_once WOO_ORDER_DOCTOR_PATH . 'includes/class-woo-order-doctor-issue-repository.php';
require_once WOO_ORDER_DOCTOR_PATH . 'includes/class-woo-order-doctor-scanner.php';
require_once WOO_ORDER_DOCTOR_PATH . 'includes/class-woo-order-doctor-email-notifier.php';
require_once WOO_ORDER_DOCTOR_PATH . 'includes/class-woo-order-doctor-actions.php';
require_once WOO_ORDER_DOCTOR_PATH . 'includes/class-woo-order-doctor-order-metabox.php';
require_once WOO_ORDER_DOCTOR_PATH . 'includes/class-woo-order-doctor-admin.php';
require_once WOO_ORDER_DOCTOR_PATH . 'includes/class-woo-order-doctor.php';

/**
 * Run activation tasks (create table, schedule cron, store version).
 *
 * Registered as a global wrapper because register_activation_hook needs a
 * callable available at file load time.
 */
function woo_order_doctor_activate() {
	Woo_Order_Doctor_Activator::activate();
}
register_activation_hook( __FILE__, 'woo_order_doctor_activate' );

/**
 * Run deactivation tasks (clear scheduled cron event).
 */
function woo_order_doctor_deactivate() {
	Woo_Order_Doctor_Deactivator::deactivate();
}
register_deactivation_hook( __FILE__, 'woo_order_doctor_deactivate' );

/**
 * Declare HPOS (High-Performance Order Storage) compatibility.
 *
 * This must run on before_woocommerce_init so WooCommerce knows the plugin
 * is safe to use with custom order tables. We only declare compatibility if
 * the FeaturesUtil helper exists to avoid fatal errors on older WooCommerce.
 */
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
				'custom_order_tables',
				WOO_ORDER_DOCTOR_FILE,
				true
			);
		}
	}
);

/**
 * Boot the plugin after all plugins are loaded.
 *
 * Running on plugins_loaded guarantees WooCommerce (if active) has been
 * loaded so its functions and classes are available to us.
 */
function woo_order_doctor_bootstrap() {
	// Load translations for the plugin.
	load_plugin_textdomain(
		WOO_ORDER_DOCTOR_TEXT_DOMAIN,
		false,
		dirname( plugin_basename( __FILE__ ) ) . '/languages'
	);

	// Hand control to the main plugin class which wires up all hooks.
	$plugin = new Woo_Order_Doctor();
	$plugin->run();
}
add_action( 'plugins_loaded', 'woo_order_doctor_bootstrap' );
