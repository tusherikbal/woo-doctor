<?php
/**
 * Main plugin class: wires all the pieces together.
 *
 * @package Woo_Order_Doctor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Woo_Order_Doctor
 *
 * The orchestrator. It instantiates the admin, actions and meta box components,
 * registers their hooks, and connects the scheduled daily scan event to the
 * scanner. Keeping wiring here keeps the bootstrap file tiny.
 */
class Woo_Order_Doctor {

	/**
	 * Admin component.
	 *
	 * @var Woo_Order_Doctor_Admin
	 */
	private $admin;

	/**
	 * Actions component.
	 *
	 * @var Woo_Order_Doctor_Actions
	 */
	private $actions;

	/**
	 * Order meta box component.
	 *
	 * @var Woo_Order_Doctor_Order_Metabox
	 */
	private $metabox;

	/**
	 * Constructor: build the components.
	 */
	public function __construct() {
		$this->admin   = new Woo_Order_Doctor_Admin();
		$this->actions = new Woo_Order_Doctor_Actions();
		$this->metabox = new Woo_Order_Doctor_Order_Metabox();
	}

	/**
	 * Register all WordPress hooks for the plugin.
	 */
	public function run() {
		// Actions (admin_post handlers) and the dismiss-notice handler always run
		// in admin so links/forms work even if WooCommerce check happens later.
		$this->actions->register_hooks();
		add_action( 'admin_init', array( $this->admin, 'maybe_dismiss_notice' ) );

		// Run lightweight DB upgrades (e.g. new notification columns). This is a
		// cheap version-option comparison on every load; the heavier dbDelta only
		// runs on the single request after an update. We do it here (on
		// plugins_loaded) rather than admin_init so a cron-triggered scan also has
		// the up-to-date schema even when no admin page has been opened yet.
		Woo_Order_Doctor_DB::maybe_upgrade();

		// Admin UI (menus, assets, notices).
		$this->admin->register_hooks();

		// The scheduled daily scan callback. The scanner itself re-checks that
		// WooCommerce is active and monitoring is enabled before doing anything.
		add_action( 'woo_order_doctor_daily_scan', array( $this, 'run_scheduled_scan' ) );

		// Order meta box only makes sense when WooCommerce is present.
		if ( Woo_Order_Doctor_Dependency::is_woocommerce_active() ) {
			$this->metabox->register_hooks();
		}
	}

	/**
	 * Callback for the daily cron event.
	 *
	 * Runs the same scanner used by the manual button, then lets the email
	 * notifier send immediate alerts for new/reopened issues and (only on this
	 * scheduled run) the daily health summary.
	 */
	public function run_scheduled_scan() {
		if ( ! Woo_Order_Doctor_Dependency::is_woocommerce_active() ) {
			return;
		}

		$scanner = new Woo_Order_Doctor_Scanner();
		$result  = $scanner->run_scan();

		// Only attempt notifications when the scan actually ran.
		if ( empty( $result['ran'] ) ) {
			return;
		}

		$notifier = new Woo_Order_Doctor_Email_Notifier();

		// Immediate alerts for issues found during this scan.
		if ( ! empty( $result['new_or_reopened'] ) ) {
			$notifier->process_new_issues( $result['new_or_reopened'] );
		}

		// Daily summary is sent only on the scheduled scan (not manual scans).
		$notifier->send_daily_summary();
	}
}
