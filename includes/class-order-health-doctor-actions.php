<?php
/**
 * Admin POST action handlers.
 *
 * @package Order_Health_Doctor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Order_Health_Doctor_Actions
 *
 * Handles all state-changing admin requests: running a manual scan, saving
 * settings, and changing an issue's status. Every handler verifies a nonce and
 * a capability, then performs a safe redirect (Post/Redirect/Get) so refreshes
 * do not resubmit.
 */
class Order_Health_Doctor_Actions {

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
	 * Whether the current user may manage the plugin.
	 *
	 * @return bool
	 */
	public static function can_manage() {

		return current_user_can( 'manage_woocommerce' ) || current_user_can( 'manage_options' );
	}

	/**
	 * Wire up admin_post handlers for each action.
	 */
	public function register_hooks() {
		add_action( 'admin_post_ohd_run_scan', array( $this, 'handle_run_scan' ) );
		add_action( 'admin_post_ohd_save_settings', array( $this, 'handle_save_settings' ) );
		add_action( 'admin_post_ohd_update_issue_status', array( $this, 'handle_update_issue_status' ) );
		add_action( 'admin_post_order_health_doctor_send_test_email', array( $this, 'handle_send_test_email' ) );
		add_action( 'admin_post_ohd_send_telegram_test', array( $this, 'handle_send_telegram_test' ) );
		add_action( 'admin_post_ohd_export_csv', array( $this, 'handle_export_csv' ) );
	}

	/**
	 * Guard helper: verify capability or stop execution.
	 */
	private function require_capability() {

		if ( ! self::can_manage() ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'order-health-doctor' ) );
		}
	}

	/**
	 * Handle the "Run Scan Now" button from the dashboard.
	 */
	public function handle_run_scan() {
		$this->require_capability();
		check_admin_referer( 'ohd_run_scan' );

		// Reuse the same scanner class used by the scheduled cron event.
		$scanner = new Order_Health_Doctor_Scanner();
		$result  = $scanner->run_scan();

		// A manual scan may send immediate alerts for newly found issues to every
		// enabled channel, but never the full daily summary (scheduled-scan only).
		if ( ! empty( $result['ran'] ) && ! empty( $result['new_or_reopened'] ) ) {
			$dispatcher = new Order_Health_Doctor_Notifier_Dispatcher();
			$dispatcher->process_new_issues( $result['new_or_reopened'] );
		}

		$notice = ! empty( $result['ran'] ) ? 'scan_done' : 'scan_skipped';

		$this->redirect_to(
			'order-health-doctor',
			array(
				'ohd_notice'   => $notice,
				'ohd_detected' => isset( $result['detected'] ) ? (int) $result['detected'] : 0,
			)
		);
	}

	/**
	 * Handle saving the settings form.
	 */
	public function handle_save_settings() {
		$this->require_capability();
		check_admin_referer( 'ohd_save_settings' );

		// Settings class validates/sanitizes every field and clamps ranges.
		// Sanitized field-by-field by Order_Health_Doctor_Settings::sanitize().
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash
		$raw   = isset( $_POST['ohd_settings'] ) ? $_POST['ohd_settings'] : array();
		$clean = Order_Health_Doctor_Settings::sanitize( $raw );

		update_option( Order_Health_Doctor_Settings::OPTION_NAME, $clean );

		$active_tab = isset( $_POST['ohd_active_tab'] ) ? sanitize_key( wp_unslash( $_POST['ohd_active_tab'] ) ) : 'general';
		if ( ! in_array( $active_tab, array( 'general', 'detection', 'notifications' ), true ) ) {
			$active_tab = 'general';
		}

		$this->redirect_to(
			'order-health-doctor-settings',
			array(
				'ohd_notice' => 'settings_saved',
				'ohd_tab'    => $active_tab,
			)
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
		check_admin_referer( 'ohd_send_test_email' );

		$channel = new Order_Health_Doctor_Channel_Email();

		// Report a clearer error when there are simply no valid recipients.
		if ( empty( $channel->get_recipients() ) ) {
			$notice = 'test_email_no_recipients';
		} else {
			$notice = $channel->send_test() ? 'test_email_sent' : 'test_email_failed';
		}

		$this->redirect_to(
			'order-health-doctor-settings',
			array(
				'ohd_notice' => $notice,
				'ohd_tab'    => 'notifications',
			)
		);
	}

	/**
	 * Handle the "Send Test" button for the Telegram channel.
	 *
	 * POST only, nonce + capability protected, then a safe redirect with a
	 * success/error notice.
	 */
	public function handle_send_telegram_test() {
		$this->require_capability();
		check_admin_referer( 'ohd_send_telegram_test' );

		$channel = new Order_Health_Doctor_Channel_Telegram();

		if ( ! $channel->is_configured() ) {
			$notice = 'telegram_not_configured';
		} else {
			$notice = $channel->send_test() ? 'telegram_test_sent' : 'telegram_test_failed';
		}

		$this->redirect_to(
			'order-health-doctor-settings',
			array(
				'ohd_notice' => $notice,
				'ohd_tab'    => 'notifications',
			)
		);
	}

	/**
	 * Handle an issue status change (reviewed / resolved / ignored).
	 */
	public function handle_update_issue_status() {
		$this->require_capability();
		check_admin_referer( 'ohd_update_issue_status' );

		$issue_id = isset( $_POST['issue_id'] ) ? absint( $_POST['issue_id'] ) : 0;
		$status   = isset( $_POST['new_status'] ) ? sanitize_key( wp_unslash( $_POST['new_status'] ) ) : '';

		// Only allow the three reachable target statuses from the UI.
		$allowed = array( 'reviewed', 'resolved', 'ignored' );
		if ( $issue_id && in_array( $status, $allowed, true ) ) {
			Order_Health_Doctor_Issue_Repository::update_status( $issue_id, $status );
			$notice = 'status_updated';
		} else {
			$notice = 'status_error';
		}

		// Return the user to wherever they came from (issues page or metabox).
		$redirect_page = isset( $_POST['redirect_page'] ) ? sanitize_key( wp_unslash( $_POST['redirect_page'] ) ) : 'order-health-doctor-issues';
		$this->redirect_to( $redirect_page, array( 'ohd_notice' => $notice ) );
	}

	/**
	 * Handle the "Export CSV" button on the Issues page.
	 *
	 * Streams the current filtered issue list as a CSV download. Nonce +
	 * capability protected; honours the same filters as the Issues view.
	 */
	public function handle_export_csv() {
		$this->require_capability();
		check_admin_referer( 'ohd_export_csv' );

		$issues = Order_Health_Doctor_Issue_Repository::get_issues(
			array(
				'status'     => isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '',
				'severity'   => isset( $_GET['severity'] ) ? sanitize_key( wp_unslash( $_GET['severity'] ) ) : '',
				'issue_type' => isset( $_GET['issue_type'] ) ? sanitize_key( wp_unslash( $_GET['issue_type'] ) ) : '',
				'object_id'  => isset( $_GET['object_id'] ) ? absint( $_GET['object_id'] ) : 0,
				'limit'      => 5000,
			)
		);

		$filename = 'order-doctor-issues-' . gmdate( 'Y-m-d' ) . '.csv';

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=' . $filename );

		$out = fopen( 'php://output', 'w' );

		fputcsv(
			$out,
			array(
				__( 'ID', 'order-health-doctor' ),
				__( 'Severity', 'order-health-doctor' ),
				__( 'Type', 'order-health-doctor' ),
				__( 'Status', 'order-health-doctor' ),
				__( 'Title', 'order-health-doctor' ),
				__( 'Message', 'order-health-doctor' ),
				__( 'Object type', 'order-health-doctor' ),
				__( 'Object ID', 'order-health-doctor' ),
				__( 'Detected at', 'order-health-doctor' ),
			)
		);

		foreach ( $issues as $issue ) {
			fputcsv(
				$out,
				array(
					$issue->id,
					self::csv_safe_cell( $issue->severity ),
					self::csv_safe_cell( $issue->issue_type ),
					self::csv_safe_cell( $issue->status ),
					self::csv_safe_cell( $issue->title ),
					self::csv_safe_cell( $issue->message ),
					self::csv_safe_cell( $issue->object_type ),
					$issue->object_id,
					self::csv_safe_cell( $issue->detected_at ),
				)
			);
		}

		fclose( $out );
		exit;
	}

	/**
	 * Prevent spreadsheet applications from interpreting exported text as a formula.
	 *
	 * @param mixed $value CSV cell value.
	 * @return string
	 */
	private static function csv_safe_cell( $value ) {

		$value = (string) $value;
		if ( preg_match( '/^[=+\-@\t\r]/', $value ) ) {
			return "'" . $value;
		}
		return $value;
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
