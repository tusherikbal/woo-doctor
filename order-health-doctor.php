<?php
/**
 * Plugin Name:       Order Health Doctor
 * Description:       Detects hidden WooCommerce order problems (stuck orders, failed order spikes, duplicates, stock and email config issues) before customers complain.
 * Version:           1.0.0
 * Requires at least: 6.5
 * Requires PHP:      7.4
 * Author:            Tusher Ikbal
 * Requires Plugins:  woocommerce
 * WC requires at least: 8.2
 * WC tested up to:   10.8.1
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       order-health-doctor
 * Domain Path:       /languages
 *
 * @package Order_Health_Doctor
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
define( 'ORDER_HEALTH_DOCTOR_VERSION', '1.0.0' );
define( 'ORDER_HEALTH_DOCTOR_FILE', __FILE__ );
define( 'ORDER_HEALTH_DOCTOR_PATH', plugin_dir_path( __FILE__ ) );
define( 'ORDER_HEALTH_DOCTOR_URL', plugin_dir_url( __FILE__ ) );

/*
 * --------------------------------------------------------------------------
 * Load core class files.
 * --------------------------------------------------------------------------
 * The MVP keeps things simple by requiring the class files directly instead
 * of using a Composer autoloader (Composer is intentionally not used).
 */
require_once ORDER_HEALTH_DOCTOR_PATH . 'includes/class-order-health-doctor-dependency.php';
require_once ORDER_HEALTH_DOCTOR_PATH . 'includes/class-order-health-doctor-db.php';
require_once ORDER_HEALTH_DOCTOR_PATH . 'includes/class-order-health-doctor-activator.php';
require_once ORDER_HEALTH_DOCTOR_PATH . 'includes/class-order-health-doctor-deactivator.php';
require_once ORDER_HEALTH_DOCTOR_PATH . 'includes/class-order-health-doctor-settings.php';
require_once ORDER_HEALTH_DOCTOR_PATH . 'includes/class-order-health-doctor-issue-repository.php';

// Detection rules: the abstract base first, then the scan context, each rule, and
// finally the registry that collects them (extensible via order_health_doctor_rules).
require_once ORDER_HEALTH_DOCTOR_PATH . 'includes/class-order-health-doctor-scan-context.php';
require_once ORDER_HEALTH_DOCTOR_PATH . 'includes/rules/class-order-health-doctor-rule.php';
require_once ORDER_HEALTH_DOCTOR_PATH . 'includes/rules/class-order-health-doctor-rule-paid-but-pending.php';
require_once ORDER_HEALTH_DOCTOR_PATH . 'includes/rules/class-order-health-doctor-rule-processing-too-long.php';
require_once ORDER_HEALTH_DOCTOR_PATH . 'includes/rules/class-order-health-doctor-rule-on-hold-too-long.php';
require_once ORDER_HEALTH_DOCTOR_PATH . 'includes/rules/class-order-health-doctor-rule-failed-order-spike.php';
require_once ORDER_HEALTH_DOCTOR_PATH . 'includes/rules/class-order-health-doctor-rule-duplicate-order.php';
require_once ORDER_HEALTH_DOCTOR_PATH . 'includes/rules/class-order-health-doctor-rule-stock-mismatch.php';
require_once ORDER_HEALTH_DOCTOR_PATH . 'includes/rules/class-order-health-doctor-rule-email-settings.php';
require_once ORDER_HEALTH_DOCTOR_PATH . 'includes/class-order-health-doctor-rule-registry.php';
require_once ORDER_HEALTH_DOCTOR_PATH . 'includes/class-order-health-doctor-scanner.php';

// Notification channels: the interface first, then each channel, the registry,
// and the dispatcher (extensible via order_health_doctor_notification_channels).
require_once ORDER_HEALTH_DOCTOR_PATH . 'includes/channels/interface-channel.php';
require_once ORDER_HEALTH_DOCTOR_PATH . 'includes/channels/class-order-health-doctor-channel-email.php';
require_once ORDER_HEALTH_DOCTOR_PATH . 'includes/channels/class-order-health-doctor-channel-telegram.php';
require_once ORDER_HEALTH_DOCTOR_PATH . 'includes/class-order-health-doctor-channel-registry.php';
require_once ORDER_HEALTH_DOCTOR_PATH . 'includes/class-order-health-doctor-notifier-dispatcher.php';

// Dynamic UI: health-trend history, AJAX handlers, and the WP dashboard widget.
require_once ORDER_HEALTH_DOCTOR_PATH . 'includes/class-order-health-doctor-history.php';
require_once ORDER_HEALTH_DOCTOR_PATH . 'includes/class-order-health-doctor-ajax.php';
require_once ORDER_HEALTH_DOCTOR_PATH . 'includes/class-order-health-doctor-dashboard-widget.php';

require_once ORDER_HEALTH_DOCTOR_PATH . 'includes/class-order-health-doctor-actions.php';
require_once ORDER_HEALTH_DOCTOR_PATH . 'includes/class-order-health-doctor-order-metabox.php';
require_once ORDER_HEALTH_DOCTOR_PATH . 'includes/class-order-health-doctor-admin.php';
require_once ORDER_HEALTH_DOCTOR_PATH . 'includes/class-order-health-doctor.php';

/**
 * Run activation tasks (create table, schedule cron, store version).
 *
 * Registered as a global wrapper because register_activation_hook needs a
 * callable available at file load time.
 */
function order_health_doctor_activate() {
	Order_Health_Doctor_Activator::activate();
}
register_activation_hook( __FILE__, 'order_health_doctor_activate' );

/**
 * Run deactivation tasks (clear scheduled cron event).
 */
function order_health_doctor_deactivate() {
	Order_Health_Doctor_Deactivator::deactivate();
}
register_deactivation_hook( __FILE__, 'order_health_doctor_deactivate' );

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
				ORDER_HEALTH_DOCTOR_FILE,
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
function order_health_doctor_bootstrap() {

	// Hand control to the main plugin class which wires up all hooks.
	$plugin = new Order_Health_Doctor();
	$plugin->run();
}
add_action( 'plugins_loaded', 'order_health_doctor_bootstrap' );
