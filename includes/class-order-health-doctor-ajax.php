<?php
/**
 * AJAX handlers for the dynamic admin UI.
 *
 * @package Order_Health_Doctor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Order_Health_Doctor_Ajax
 *
 * Powers the no-reload dashboard scan and the live issue actions (single + bulk).
 * Every handler verifies the shared "ohd_ajax" nonce and the same capability used
 * by the rest of the plugin, then returns a JSON payload the front-end uses to
 * animate the health gauge, counters and toasts. All actions degrade gracefully:
 * the classic POST forms still work when JavaScript is unavailable.
 */
class Order_Health_Doctor_Ajax {

	/**
	 * Register the ajax hooks (admin-only actions).
	 */
	public function register_hooks() {
		add_action( 'wp_ajax_ohd_run_scan', array( $this, 'run_scan' ) );
		add_action( 'wp_ajax_ohd_issue_action', array( $this, 'issue_action' ) );
		add_action( 'wp_ajax_ohd_bulk_action', array( $this, 'bulk_action' ) );
	}

	/**
	 * Shared guard: verify nonce + capability or send a JSON error.
	 */
	private function guard() {
		if ( ! check_ajax_referer( 'ohd_ajax', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed. Please reload the page.', 'order-health-doctor' ) ), 403 );
		}
		if ( ! Order_Health_Doctor_Actions::can_manage() ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to do that.', 'order-health-doctor' ) ), 403 );
		}
	}

	/**
	 * AJAX: run a scan without reloading the dashboard.
	 */
	public function run_scan() {
		$this->guard();

		$scanner = new Order_Health_Doctor_Scanner();
		$result  = $scanner->run_scan();

		if ( empty( $result['ran'] ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Scan was skipped. Make sure monitoring is enabled and WooCommerce is active.', 'order-health-doctor' ),
				)
			);
		}

		// Immediate alerts to all enabled channels (never the daily summary here).
		if ( ! empty( $result['new_or_reopened'] ) ) {
			$dispatcher = new Order_Health_Doctor_Notifier_Dispatcher();
			$dispatcher->process_new_issues( $result['new_or_reopened'] );
		}

		Order_Health_Doctor_History::record_snapshot();

		$state                = $this->dashboard_state();
		$state['recent_html'] = Order_Health_Doctor_Admin::render_recent_issue_rows(
			Order_Health_Doctor_Issue_Repository::get_recent_issues( 10 )
		);
		$state['message']     = sprintf(
			/* translators: %d: number of issues detected/updated */
			_n( 'Scan complete. %d issue detected or updated.', 'Scan complete. %d issues detected or updated.', (int) $result['detected'], 'order-health-doctor' ),
			(int) $result['detected']
		);
		$state['detected'] = (int) $result['detected'];

		wp_send_json_success( $state );
	}

	/**
	 * AJAX: change a single issue's status.
	 */
	public function issue_action() {
		$this->guard();

		// The shared guard verifies the AJAX nonce before input is read.
		$issue_id = isset( $_POST['issue_id'] ) ? absint( $_POST['issue_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$status   = isset( $_POST['new_status'] ) ? sanitize_key( wp_unslash( $_POST['new_status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		if ( ! $issue_id || ! in_array( $status, array( 'reviewed', 'resolved', 'ignored' ), true ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid request.', 'order-health-doctor' ) ) );
		}

		Order_Health_Doctor_Issue_Repository::update_status( $issue_id, $status );
		Order_Health_Doctor_History::record_snapshot();

		$state             = $this->dashboard_state();
		$state['issue_id'] = $issue_id;
		$state['status']   = $status;
		$state['message']  = __( 'Issue updated.', 'order-health-doctor' );

		wp_send_json_success( $state );
	}

	/**
	 * AJAX: change the status of several issues at once.
	 */
	public function bulk_action() {
		$this->guard();

		// The shared guard verifies the AJAX nonce before input is read.
		$status = isset( $_POST['new_status'] ) ? sanitize_key( wp_unslash( $_POST['new_status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$ids    = isset( $_POST['issue_ids'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['issue_ids'] ) ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$ids    = array_filter( array_map( 'absint', $ids ) );

		if ( empty( $ids ) || ! in_array( $status, array( 'reviewed', 'resolved', 'ignored' ), true ) ) {
			wp_send_json_error( array( 'message' => __( 'Select at least one issue and an action.', 'order-health-doctor' ) ) );
		}

		$updated = 0;
		foreach ( $ids as $id ) {
			if ( Order_Health_Doctor_Issue_Repository::update_status( $id, $status ) ) {
				++$updated;
			}
		}

		Order_Health_Doctor_History::record_snapshot();

		$state            = $this->dashboard_state();
		$state['status']  = $status;
		$state['ids']     = array_values( $ids );
		$state['updated'] = $updated;
		$state['message'] = sprintf(
			/* translators: %d: number of issues updated */
			_n( '%d issue updated.', '%d issues updated.', $updated, 'order-health-doctor' ),
			$updated
		);

		wp_send_json_success( $state );
	}

	/**
	 * Build the shared dashboard-state payload (counts, health, sparkline, scan time).
	 *
	 * @return array
	 */
	private function dashboard_state() {
		$counts = Order_Health_Doctor_Issue_Repository::get_issue_counts();
		$health = Order_Health_Doctor_Admin::calculate_health_score( $counts );

		$series = array();
		foreach ( Order_Health_Doctor_History::get_series( 14 ) as $point ) {
			$series[] = (int) $point['score'];
		}

		$last_scan = get_option( 'ohd_last_scan', '' );

		return array(
			'counts'       => array(
				'total'    => (int) $counts['total'],
				'critical' => (int) $counts['critical'],
				'high'     => (int) $counts['high'],
				'medium'   => (int) $counts['medium'],
				'low'      => (int) $counts['low'],
			),
			'health'       => (int) $health,
			'health_label' => Order_Health_Doctor_Admin::health_label( $health ),
			'health_band'  => Order_Health_Doctor_Admin::health_band_class( $health ),
			'health_hex'   => Order_Health_Doctor_Admin::health_hex( $health ),
			'sparkline'    => $series,
			'last_scan'    => $last_scan ? mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $last_scan ) : __( 'Never', 'order-health-doctor' ),
		);
	}
}
