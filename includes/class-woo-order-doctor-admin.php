<?php
/**
 * Admin UI: menus, asset loading, notices and view rendering.
 *
 * @package Woo_Order_Doctor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Woo_Order_Doctor_Admin
 *
 * Builds the admin menu, renders the Dashboard / Issues / Settings views,
 * enqueues Bootstrap + plugin assets only on the plugin's own pages, and shows
 * admin notices. It also exposes small static helpers (health score, severity
 * badges, edit URLs) used by the view templates.
 */
class Woo_Order_Doctor_Admin {

	/**
	 * Page hook suffixes for the plugin's admin pages.
	 *
	 * Stored so we can enqueue assets only on these screens.
	 *
	 * @var string[]
	 */
	private $page_hooks = array();

	/**
	 * Register admin hooks.
	 */
	public function register_hooks() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_notices', array( $this, 'render_admin_notices' ) );
	}

	/**
	 * Register the top-level menu and its submenus.
	 */
	public function register_menu() {
		$capability = Woo_Order_Doctor_Actions::capability();

		$this->page_hooks['dashboard'] = add_menu_page(
			__( 'Woo Order Doctor', 'woo-order-doctor' ),
			__( 'Order Doctor', 'woo-order-doctor' ),
			$capability,
			'woo-order-doctor',
			array( $this, 'render_dashboard_page' ),
			'dashicons-heart',
			56
		);

		// Dashboard submenu (mirrors the top-level item with a clearer label).
		$this->page_hooks['dashboard_sub'] = add_submenu_page(
			'woo-order-doctor',
			__( 'Dashboard', 'woo-order-doctor' ),
			__( 'Dashboard', 'woo-order-doctor' ),
			$capability,
			'woo-order-doctor',
			array( $this, 'render_dashboard_page' )
		);

		$this->page_hooks['issues'] = add_submenu_page(
			'woo-order-doctor',
			__( 'Issues', 'woo-order-doctor' ),
			__( 'Issues', 'woo-order-doctor' ),
			$capability,
			'woo-order-doctor-issues',
			array( $this, 'render_issues_page' )
		);

		$this->page_hooks['settings'] = add_submenu_page(
			'woo-order-doctor',
			__( 'Settings', 'woo-order-doctor' ),
			__( 'Settings', 'woo-order-doctor' ),
			$capability,
			'woo-order-doctor-settings',
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * Enqueue Bootstrap and the plugin's admin assets, but only on our pages.
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 */
	public function enqueue_assets( $hook_suffix ) {
		// Bail unless we are on one of the plugin's registered screens.
		if ( ! in_array( $hook_suffix, $this->page_hooks, true ) ) {
			return;
		}

		// Local bundled Bootstrap (no CDN, not loaded globally).
		wp_enqueue_style(
			'wod-bootstrap',
			WOO_ORDER_DOCTOR_URL . 'assets/vendor/bootstrap/bootstrap.min.css',
			array(),
			'5.3.3'
		);
		wp_enqueue_script(
			'wod-bootstrap',
			WOO_ORDER_DOCTOR_URL . 'assets/vendor/bootstrap/bootstrap.bundle.min.js',
			array(),
			'5.3.3',
			true
		);

		// Plugin-specific styles and scripts.
		wp_enqueue_style(
			'wod-admin',
			WOO_ORDER_DOCTOR_URL . 'assets/css/admin.css',
			array( 'wod-bootstrap' ),
			WOO_ORDER_DOCTOR_VERSION
		);
		wp_enqueue_script(
			'wod-admin',
			WOO_ORDER_DOCTOR_URL . 'assets/js/admin.js',
			array( 'wod-bootstrap' ),
			WOO_ORDER_DOCTOR_VERSION,
			true
		);
	}

	/* ---------------------------------------------------------------------
	 * View renderers
	 * ------------------------------------------------------------------- */

	/**
	 * Render the Dashboard page.
	 */
	public function render_dashboard_page() {
		$this->guard_page();

		// Data prepared for the view template.
		$counts        = Woo_Order_Doctor_Issue_Repository::get_issue_counts();
		$health        = self::calculate_health_score( $counts );
		$health_label  = self::health_label( $health );
		$recent_issues = Woo_Order_Doctor_Issue_Repository::get_recent_issues( 10 );
		$last_scan     = get_option( 'wod_last_scan', '' );

		require WOO_ORDER_DOCTOR_PATH . 'admin/views/dashboard.php';
	}

	/**
	 * Render the Issues page.
	 */
	public function render_issues_page() {
		$this->guard_page();

		// Read and sanitize filters from the query string.
		$filters = array(
			'status'     => isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification
			'severity'   => isset( $_GET['severity'] ) ? sanitize_key( wp_unslash( $_GET['severity'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification
			'issue_type' => isset( $_GET['issue_type'] ) ? sanitize_key( wp_unslash( $_GET['issue_type'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification
			'object_id'  => isset( $_GET['object_id'] ) ? absint( $_GET['object_id'] ) : 0, // phpcs:ignore WordPress.Security.NonceVerification
		);

		$issues = Woo_Order_Doctor_Issue_Repository::get_issues(
			array(
				'status'     => $filters['status'],
				'severity'   => $filters['severity'],
				'issue_type' => $filters['issue_type'],
				'object_id'  => $filters['object_id'],
				'limit'      => 200,
			)
		);

		require WOO_ORDER_DOCTOR_PATH . 'admin/views/issues.php';
	}

	/**
	 * Render the Settings page.
	 */
	public function render_settings_page() {
		$this->guard_page();

		$settings = Woo_Order_Doctor_Settings::get_all();

		// Eligible internal users for the "selected users" recipient mode. We
		// prefer users who can manage WooCommerce or the site (never customers).
		$eligible_users = get_users(
			array(
				'capability__in' => array( 'manage_woocommerce', 'manage_options' ),
				'fields'         => array( 'ID', 'display_name', 'user_email' ),
				'number'         => 200,
			)
		);

		// Recipient count for the test/preview card.
		$notifier        = new Woo_Order_Doctor_Email_Notifier();
		$recipient_count = count( $notifier->get_recipients() );

		require WOO_ORDER_DOCTOR_PATH . 'admin/views/settings.php';
	}

	/**
	 * Capability guard run at the top of every page render.
	 */
	private function guard_page() {
		if ( ! current_user_can( Woo_Order_Doctor_Actions::capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'woo-order-doctor' ) );
		}
	}

	/* ---------------------------------------------------------------------
	 * Admin notices
	 * ------------------------------------------------------------------- */

	/**
	 * Render admin notices: WooCommerce missing, and critical issue alerts.
	 */
	public function render_admin_notices() {
		// Notice 1: WooCommerce required.
		if ( ! Woo_Order_Doctor_Dependency::is_woocommerce_active() ) {
			echo '<div class="notice notice-error"><p>';
			echo esc_html__( 'Woo Order Doctor requires WooCommerce to be installed and active.', 'woo-order-doctor' );
			echo '</p></div>';
			return;
		}

		// Only show the critical-issue nag to authorized users who opted in.
		if ( ! current_user_can( Woo_Order_Doctor_Actions::capability() ) ) {
			return;
		}
		if ( ! Woo_Order_Doctor_Settings::is_enabled( 'daily_admin_notice' ) ) {
			return;
		}

		// Respect a per-user, per-day dismissal so we do not nag repeatedly.
		$dismissed_on = get_user_meta( get_current_user_id(), 'wod_notice_dismissed_day', true );
		if ( $dismissed_on === gmdate( 'Y-m-d' ) ) {
			return;
		}

		$counts = Woo_Order_Doctor_Issue_Repository::get_issue_counts();
		if ( empty( $counts['critical'] ) ) {
			return;
		}

		$issues_url  = admin_url( 'admin.php?page=woo-order-doctor-issues&severity=critical' );
		$dismiss_url = wp_nonce_url(
			admin_url( 'admin.php?page=woo-order-doctor&wod_dismiss_notice=1' ),
			'wod_dismiss_notice'
		);

		echo '<div class="notice notice-warning"><p>';
		printf(
			/* translators: %d: number of critical issues */
			esc_html__( 'Woo Order Doctor found %d critical order issues. Review now.', 'woo-order-doctor' ),
			(int) $counts['critical']
		);
		echo ' <a class="button button-primary" href="' . esc_url( $issues_url ) . '">' . esc_html__( 'Review Issues', 'woo-order-doctor' ) . '</a>';
		echo ' <a href="' . esc_url( $dismiss_url ) . '">' . esc_html__( 'Dismiss for today', 'woo-order-doctor' ) . '</a>';
		echo '</p></div>';
	}

	/**
	 * Handle the "dismiss for today" link for the critical notice.
	 *
	 * Hooked on admin_init by the main class.
	 */
	public function maybe_dismiss_notice() {
		if ( empty( $_GET['wod_dismiss_notice'] ) ) {
			return;
		}
		if ( ! current_user_can( Woo_Order_Doctor_Actions::capability() ) ) {
			return;
		}
		check_admin_referer( 'wod_dismiss_notice' );

		update_user_meta( get_current_user_id(), 'wod_notice_dismissed_day', gmdate( 'Y-m-d' ) );

		wp_safe_redirect( admin_url( 'admin.php?page=woo-order-doctor' ) );
		exit;
	}

	/* ---------------------------------------------------------------------
	 * Static helpers used by views and the metabox
	 * ------------------------------------------------------------------- */

	/**
	 * Calculate the order health score (0-100) from open issue counts.
	 *
	 * Weighting: critical -20, high -10, medium -5, low -2. Clamped to [0,100].
	 *
	 * @param array $counts Counts from get_issue_counts().
	 * @return int Score between 0 and 100.
	 */
	public static function calculate_health_score( $counts ) {
		$score  = 100;
		$score -= ( isset( $counts['critical'] ) ? (int) $counts['critical'] : 0 ) * 20;
		$score -= ( isset( $counts['high'] ) ? (int) $counts['high'] : 0 ) * 10;
		$score -= ( isset( $counts['medium'] ) ? (int) $counts['medium'] : 0 ) * 5;
		$score -= ( isset( $counts['low'] ) ? (int) $counts['low'] : 0 ) * 2;

		return max( 0, min( 100, $score ) );
	}

	/**
	 * Map a numeric health score to a human label.
	 *
	 * @param int $score Score 0-100.
	 * @return string Label.
	 */
	public static function health_label( $score ) {
		if ( $score >= 90 ) {
			return __( 'Excellent', 'woo-order-doctor' );
		}
		if ( $score >= 75 ) {
			return __( 'Good', 'woo-order-doctor' );
		}
		if ( $score >= 50 ) {
			return __( 'Needs Attention', 'woo-order-doctor' );
		}
		return __( 'Critical', 'woo-order-doctor' );
	}

	/**
	 * Map a severity to a Bootstrap badge contextual class.
	 *
	 * @param string $severity Severity slug.
	 * @return string Bootstrap background class.
	 */
	public static function severity_badge_class( $severity ) {
		$map = array(
			'critical' => 'bg-danger',
			'high'     => 'bg-warning text-dark',
			'medium'   => 'bg-info text-dark',
			'low'      => 'bg-secondary',
			'info'     => 'bg-light text-dark',
		);
		return isset( $map[ $severity ] ) ? $map[ $severity ] : 'bg-secondary';
	}

	/**
	 * Get a safe edit URL for an order, HPOS-aware.
	 *
	 * Prefers WC_Order::get_edit_order_url(); falls back to the WooCommerce
	 * admin order URL rather than assuming the legacy post.php URL.
	 *
	 * @param int $order_id Order ID.
	 * @return string Edit URL (escaped-safe to pass through esc_url).
	 */
	public static function get_order_edit_url( $order_id ) {
		$order_id = absint( $order_id );

		// Guard against WooCommerce being inactive: the Issues page can still be
		// rendered for previously-stored order issues, so never assume WC's
		// order functions exist.
		if ( function_exists( 'wc_get_order' ) ) {
			$order = wc_get_order( $order_id );
			if ( $order && method_exists( $order, 'get_edit_order_url' ) ) {
				return $order->get_edit_order_url();
			}
		}

		// HPOS-aware fallback: the WooCommerce orders admin page with action=edit.
		if ( class_exists( '\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController' ) && function_exists( 'wc_get_page_screen_id' ) ) {
			return admin_url( 'admin.php?page=wc-orders&action=edit&id=' . $order_id );
		}

		// Final legacy fallback.
		return admin_url( 'post.php?post=' . $order_id . '&action=edit' );
	}

	/**
	 * Get the URL to the WooCommerce Orders list filtered by failed status.
	 *
	 * @return string Orders list URL.
	 */
	public static function get_failed_orders_url() {
		// Works for both HPOS (wc-orders) and legacy; we link to the orders list
		// filtered by the failed status where supported.
		if ( class_exists( '\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController' ) ) {
			return admin_url( 'admin.php?page=wc-orders&status=wc-failed' );
		}
		return admin_url( 'edit.php?post_type=shop_order&post_status=wc-failed' );
	}

	/**
	 * Get the URL to WooCommerce email settings.
	 *
	 * @return string Email settings URL.
	 */
	public static function get_email_settings_url() {
		return admin_url( 'admin.php?page=wc-settings&tab=email' );
	}

	/**
	 * Get a human-friendly label for an issue type slug.
	 *
	 * @param string $issue_type Issue type slug.
	 * @return string Label.
	 */
	public static function issue_type_label( $issue_type ) {
		$labels = self::issue_type_labels();
		return isset( $labels[ $issue_type ] ) ? $labels[ $issue_type ] : ucwords( str_replace( '_', ' ', $issue_type ) );
	}

	/**
	 * Map of all known issue types to labels (used for the filter dropdown too).
	 *
	 * @return array
	 */
	public static function issue_type_labels() {
		return array(
			'paid_but_pending'       => __( 'Paid But Pending', 'woo-order-doctor' ),
			'processing_too_long'    => __( 'Processing Too Long', 'woo-order-doctor' ),
			'on_hold_too_long'       => __( 'On Hold Too Long', 'woo-order-doctor' ),
			'failed_order_spike'     => __( 'Failed Order Spike', 'woo-order-doctor' ),
			'duplicate_order'        => __( 'Duplicate Order', 'woo-order-doctor' ),
			'stock_mismatch'         => __( 'Stock Mismatch', 'woo-order-doctor' ),
			'email_settings_warning' => __( 'Email Settings Warning', 'woo-order-doctor' ),
		);
	}

	/**
	 * Render the action buttons for a single issue row.
	 *
	 * Centralizes the per-object-type button logic so the Issues table and the
	 * order meta box stay consistent. Each status-changing button is its own
	 * nonce-protected POST form.
	 *
	 * @param object $issue        Issue row.
	 * @param string $redirect_page Page slug to return to after an action.
	 */
	public static function render_issue_action_buttons( $issue, $redirect_page = 'woo-order-doctor-issues' ) {
		$object_type = $issue->object_type;
		$object_id   = (int) $issue->object_id;
		$related_id  = (int) $issue->related_object_id;

		echo '<div class="wod-actions d-flex flex-wrap gap-1">';

		// Object-specific "go to" buttons first.
		switch ( $object_type ) {
			case 'order':
				echo '<a class="btn btn-sm btn-outline-primary" href="' . esc_url( self::get_order_edit_url( $object_id ) ) . '">' . esc_html__( 'View Order', 'woo-order-doctor' ) . '</a>';
				if ( 'duplicate_order' === $issue->issue_type && $related_id ) {
					echo '<a class="btn btn-sm btn-outline-primary" href="' . esc_url( self::get_order_edit_url( $related_id ) ) . '">' . esc_html__( 'View Duplicate Order', 'woo-order-doctor' ) . '</a>';
				}
				break;

			case 'product':
				echo '<a class="btn btn-sm btn-outline-primary" href="' . esc_url( get_edit_post_link( $object_id ) ) . '">' . esc_html__( 'Edit Product', 'woo-order-doctor' ) . '</a>';
				break;

			case 'settings':
				echo '<a class="btn btn-sm btn-outline-primary" href="' . esc_url( self::get_email_settings_url() ) . '">' . esc_html__( 'Open Settings', 'woo-order-doctor' ) . '</a>';
				break;

			case 'system':
			default:
				if ( 'failed_order_spike' === $issue->issue_type ) {
					echo '<a class="btn btn-sm btn-outline-primary" href="' . esc_url( self::get_failed_orders_url() ) . '">' . esc_html__( 'View Failed Orders', 'woo-order-doctor' ) . '</a>';
				}
				break;
		}

		// Status-change buttons (reviewed / resolved / ignored) as POST forms.
		self::render_status_button( $issue->id, 'reviewed', __( 'Mark Reviewed', 'woo-order-doctor' ), 'btn-outline-secondary', $redirect_page );
		self::render_status_button( $issue->id, 'resolved', __( 'Resolve', 'woo-order-doctor' ), 'btn-outline-success', $redirect_page );
		self::render_status_button( $issue->id, 'ignored', __( 'Ignore', 'woo-order-doctor' ), 'btn-outline-dark', $redirect_page );

		echo '</div>';
	}

	/**
	 * Render a single nonce-protected status-change button.
	 *
	 * @param int    $issue_id      Issue ID.
	 * @param string $status        Target status.
	 * @param string $label         Button label.
	 * @param string $btn_class     Bootstrap button class.
	 * @param string $redirect_page Page slug to return to.
	 */
	private static function render_status_button( $issue_id, $status, $label, $btn_class, $redirect_page ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="wod-inline-form">';
		echo '<input type="hidden" name="action" value="wod_update_issue_status" />';
		echo '<input type="hidden" name="issue_id" value="' . esc_attr( $issue_id ) . '" />';
		echo '<input type="hidden" name="new_status" value="' . esc_attr( $status ) . '" />';
		echo '<input type="hidden" name="redirect_page" value="' . esc_attr( $redirect_page ) . '" />';
		wp_nonce_field( 'wod_update_issue_status' );
		echo '<button type="submit" class="btn btn-sm ' . esc_attr( $btn_class ) . '">' . esc_html( $label ) . '</button>';
		echo '</form>';
	}
}
