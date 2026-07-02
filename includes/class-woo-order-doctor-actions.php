<?php
/**
 * Admin POST action handlers.
 *
 * @package Woo_Order_Doctor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Woo_Order_Doctor_Actions
 *
 * Handles all state-changing admin requests: running a manual scan, saving
 * settings, and changing an issue's status. Every handler verifies a nonce and
 * a capability, then performs a safe redirect (Post/Redirect/Get) so refreshes
 * do not resubmit.
 */
class Woo_Order_Doctor_Actions {

	/**
	 * Required capability for all actions (with a sensible fallback).
	 *
	 * @return string Capability name the current user must have.
	 */
	public static function capability() {
		// Prefer manage_woocommerce; fall back to manage_options for stores where
		// shop managers are not configured.
		return current_user_can( 'manage_woocommerce' ) ? 'manage_woocommerce' : 'manage_options';
	}

	/**
	 * Wire up admin_post handlers for each action.
	 */
	public function register_hooks() {
		add_action( 'admin_post_wod_run_scan', array( $this, 'handle_run_scan' ) );
		add_action( 'admin_post_wod_save_settings', array( $this, 'handle_save_settings' ) );
		add_action( 'admin_post_wod_update_issue_status', array( $this, 'handle_update_issue_status' ) );
		add_action( 'admin_post_woo_order_doctor_send_test_email', array( $this, 'handle_send_test_email' ) );
	}

	/**
	 * Guard helper: verify capability or stop execution.
	 */
	private function require_capability() {
		if ( ! current_user_can( self::capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'woo-order-doctor' ) );
		}
	}

	/**
	 * Handle the "Run Scan Now" button from the dashboard.
	 */
	public function handle_run_scan() {
		$this->require_capability();
		check_admin_referer( 'wod_run_scan' );

		// Reuse the same scanner class used by the scheduled cron event.
		$scanner = new Woo_Order_Doctor_Scanner();
		$result  = $scanner->run_scan();

		// A manual scan may send immediate critical alerts for newly found
		// issues, but never the full daily summary (that is scheduled-scan only).
		if ( ! empty( $result['ran'] ) && ! empty( $result['new_or_reopened'] ) ) {
			$notifier = new Woo_Order_Doctor_Email_Notifier();
			$notifier->process_new_issues( $result['new_or_reopened'] );
		}

		$notice = ! empty( $result['ran'] ) ? 'scan_done' : 'scan_skipped';

		$this->redirect_to(
			'woo-order-doctor',
			array(
				'wod_notice'   => $notice,
				'wod_detected' => isset( $result['detected'] ) ? (int) $result['detected'] : 0,
			)
		);
	}

	/**
	 * Handle saving the settings form.
	 */
	public function handle_save_settings() {
		$this->require_capability();
		check_admin_referer( 'wod_save_settings' );

		// Settings class validates/sanitizes every field and clamps ranges.
		$raw   = isset( $_POST['wod_settings'] ) ? wp_unslash( $_POST['wod_settings'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitized
		$clean = Woo_Order_Doctor_Settings::sanitize( $raw );

		update_option( Woo_Order_Doctor_Settings::OPTION_NAME, $clean );

		$this->redirect_to(
			'woo-order-doctor-settings',
			array( 'wod_notice' => 'settings_saved' )
		);
	}

	/**
	 * Handle the "Send Test Email" button on the settings page.
	 *
	 * POST only, nonce + capability protected, then a safe redirect with a
	 * success/error notice.
	 */
	public function handle_send_test_email() {
		$this->require_capability();
		check_admin_referer( 'wod_send_test_email' );

		$notifier = new Woo_Order_Doctor_Email_Notifier();

		// Report a clearer error when there are simply no valid recipients.
		if ( empty( $notifier->get_recipients() ) ) {
			$notice = 'test_email_no_recipients';
		} else {
			$notice = $notifier->send_test_email() ? 'test_email_sent' : 'test_email_failed';
		}

		$this->redirect_to(
			'woo-order-doctor-settings',
			array( 'wod_notice' => $notice )
		);
	}

	/**
	 * Handle an issue status change (reviewed / resolved / ignored).
	 */
	public function handle_update_issue_status() {
		$this->require_capability();
		check_admin_referer( 'wod_update_issue_status' );

		$issue_id = isset( $_POST['issue_id'] ) ? absint( $_POST['issue_id'] ) : 0;
		$status   = isset( $_POST['new_status'] ) ? sanitize_key( wp_unslash( $_POST['new_status'] ) ) : '';

		// Only allow the three reachable target statuses from the UI.
		$allowed = array( 'reviewed', 'resolved', 'ignored' );
		if ( $issue_id && in_array( $status, $allowed, true ) ) {
			Woo_Order_Doctor_Issue_Repository::update_status( $issue_id, $status );
			$notice = 'status_updated';
		} else {
			$notice = 'status_error';
		}

		// Return the user to wherever they came from (issues page or metabox).
		$redirect_page = isset( $_POST['redirect_page'] ) ? sanitize_key( wp_unslash( $_POST['redirect_page'] ) ) : 'woo-order-doctor-issues';
		$this->redirect_to( $redirect_page, array( 'wod_notice' => $notice ) );
	}

	/**
	 * Build a safe admin URL for a plugin page and redirect to it.
	 *
	 * @param string $page_slug Admin page slug.
	 * @param array  $args      Extra query args.
	 */
	private function redirect_to( $page_slug, $args = array() ) {
		$url = add_query_arg(
			array_merge( array( 'page' => $page_slug ), $args ),
			admin_url( 'admin.php' )
		);
		wp_safe_redirect( esc_url_raw( $url ) );
		exit;
	}
}
