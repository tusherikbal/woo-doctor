<?php
/**
 * Main plugin class: wires all the pieces together.
 *
 * @package Order_Health_Doctor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Order_Health_Doctor
 *
 * The orchestrator. It instantiates the admin, actions and meta box components,
 * registers their hooks, and connects the scheduled daily scan event to the
 * scanner. Keeping wiring here keeps the bootstrap file tiny.
 */
class Order_Health_Doctor {

	/**
	 * Admin component.
	 *
	 * @var Order_Health_Doctor_Admin
	 */
	private $admin;

	/**
	 * Actions component.
	 *
	 * @var Order_Health_Doctor_Actions
	 */
	private $actions;

	/**
	 * Order meta box component.
	 *
	 * @var Order_Health_Doctor_Order_Metabox
	 */
	private $metabox;

	/**
	 * AJAX handler component.
	 *
	 * @var Order_Health_Doctor_Ajax
	 */
	private $ajax;

	/**
	 * WP dashboard widget component.
	 *
	 * @var Order_Health_Doctor_Dashboard_Widget
	 */
	private $widget;

	/**
	 * Constructor: build the components.
	 */
	public function __construct() {
		$this->admin   = new Order_Health_Doctor_Admin();
		$this->actions = new Order_Health_Doctor_Actions();
		$this->metabox = new Order_Health_Doctor_Order_Metabox();
		$this->ajax    = new Order_Health_Doctor_Ajax();
		$this->widget  = new Order_Health_Doctor_Dashboard_Widget();
	}

	/**
	 * Register all WordPress hooks for the plugin.
	 */
	public function run() {
		// Actions (admin_post handlers) and the dismiss-notice handler always run
		// in admin so links/forms work even if WooCommerce check happens later.
		$this->actions->register_hooks();
		add_action( 'admin_init', array( $this->admin, 'maybe_dismiss_notice' ) );
		add_action( 'admin_init', array( $this, 'add_privacy_policy_content' ) );
		// Run lightweight DB upgrades (e.g. new notification columns). This is a
		// cheap version-option comparison on every load; the heavier dbDelta only
		// runs on the single request after an update. We do it here (on
		// plugins_loaded) rather than admin_init so a cron-triggered scan also has
		// the up-to-date schema even when no admin page has been opened yet.
		Order_Health_Doctor_DB::maybe_upgrade();

		// Admin UI (menus, assets, notices).
		$this->admin->register_hooks();

		// Dynamic UI: AJAX endpoints and the WordPress dashboard widget.
		$this->ajax->register_hooks();
		$this->widget->register_hooks();

		// The scheduled daily scan callback. The scanner itself re-checks that
		// WooCommerce is active and monitoring is enabled before doing anything.
		add_action( 'order_health_doctor_daily_scan', array( $this, 'run_scheduled_scan' ) );

		// Order meta box only makes sense when WooCommerce is present.
		if ( Order_Health_Doctor_Dependency::is_woocommerce_active() ) {
			$this->metabox->register_hooks();
		}

		/**
		 * Fires once the plugin has wired all of its hooks.
		*/
		do_action( 'order_health_doctor_registered' );
	}

	/**
	 * Suggest privacy-policy text for the optional Telegram integration.
	 */
	public function add_privacy_policy_content() {

		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		$content = '<p>' . esc_html__(
			'When Telegram alerts are enabled, Order Health Doctor sends the site name, issue severity, issue title, issue description, suggested action, health totals, and an administration link to the Telegram Bot API. The plugin does not send billing names, addresses, email addresses, or payment details. Telegram processes this information under its own privacy policy and terms.',
			'order-health-doctor'
		) . '</p>';

		wp_add_privacy_policy_content(
			__( 'Order Health Doctor', 'order-health-doctor' ),
			wp_kses_post( wpautop( $content, false ) )
		);
	}
	/**
	 * Callback for the daily cron event.
	 *
	 * Runs the same scanner used by the manual button, then lets the email
	 * notifier send immediate alerts for new/reopened issues and (only on this
	 * scheduled run) the daily health summary.
	 */
	public function run_scheduled_scan() {
		if ( ! Order_Health_Doctor_Dependency::is_woocommerce_active() ) {
			return;
		}

		$scanner = new Order_Health_Doctor_Scanner();
		$result  = $scanner->run_scan();

		// Only attempt notifications when the scan actually ran.
		if ( empty( $result['ran'] ) ) {
			return;
		}

		// Record a daily health snapshot for the dashboard trend sparkline.
		Order_Health_Doctor_History::record_snapshot();

		$dispatcher = new Order_Health_Doctor_Notifier_Dispatcher();

		// Immediate alerts for issues found during this scan (all enabled channels).
		if ( ! empty( $result['new_or_reopened'] ) ) {
			$dispatcher->process_new_issues( $result['new_or_reopened'] );
		}

		// Daily summary is sent only on the scheduled scan (not manual scans).
		$dispatcher->send_daily_summaries();

		// Housekeeping: prune old resolved issues so the table does not grow forever.
		$retention = (int) Order_Health_Doctor_Settings::get( 'resolved_retention_days', 30 );
		if ( $retention > 0 ) {
			Order_Health_Doctor_Issue_Repository::delete_resolved_older_than( $retention );
		}
	}
}
