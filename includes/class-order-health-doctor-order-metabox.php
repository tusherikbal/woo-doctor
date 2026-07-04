<?php
/**
 * Order edit screen meta box.
 *
 * @package Order_Health_Doctor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Order_Health_Doctor_Order_Metabox
 *
 * Adds a meta box to the WooCommerce order edit screen that lists any open
 * Order Health Doctor issues for that order, with severity badges and the same
 * action buttons used on the Issues page. Works with both the legacy
 * (post-based) and HPOS (custom table) order screens.
 */
class Order_Health_Doctor_Order_Metabox {

	/**
	 * Register the meta box hook.
	 */
	public function register_hooks() {
		add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ) );
	}

	/**
	 * Register the meta box on whichever order screen is active.
	 */
	public function add_meta_box() {
		// HPOS uses a custom screen id; the legacy screen uses shop_order.
		$screen = class_exists( '\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController' ) && function_exists( 'wc_get_page_screen_id' )
			? wc_get_page_screen_id( 'shop-order' )
			: 'shop_order';

		add_meta_box(
			'ohd_order_issues',
			__( 'Order Health Doctor', 'order-health-doctor' ),
			array( $this, 'render_meta_box' ),
			$screen,
			'side',
			'high'
		);
	}

	/**
	 * Render the meta box content.
	 *
	 * @param mixed $post_or_order Either a WP_Post (legacy) or WC_Order (HPOS).
	 */
	public function render_meta_box( $post_or_order ) {
		// Resolve the order ID regardless of which object WooCommerce passed in.
		$order_id = ( $post_or_order instanceof WP_Post ) ? $post_or_order->ID : $post_or_order->get_id();

		$issues = Order_Health_Doctor_Issue_Repository::get_open_issues_for_object( 'order', $order_id );

		// The meta box markup uses plain WP admin styles since Bootstrap is not
		// loaded on the order edit screen.
		if ( empty( $issues ) ) {
			echo '<p>' . esc_html__( 'No Order Health Doctor issues found for this order.', 'order-health-doctor' ) . '</p>';
			return;
		}

		echo '<ul class="ohd-metabox-issues">';
		foreach ( $issues as $issue ) {
			echo '<li style="margin-bottom:12px;">';

			// Severity badge (inline-styled because Bootstrap is unavailable here).
			$colors = array(
				'critical' => '#dc3545',
				'high'     => '#fd7e14',
				'medium'   => '#0dcaf0',
				'low'      => '#6c757d',
				'info'     => '#adb5bd',
			);
			$color  = isset( $colors[ $issue->severity ] ) ? $colors[ $issue->severity ] : '#6c757d';
			echo '<span style="display:inline-block;padding:2px 6px;border-radius:3px;color:#fff;background:' . esc_attr( $color ) . ';font-size:11px;text-transform:uppercase;">' . esc_html( $issue->severity ) . '</span>';

			echo '<strong style="display:block;margin-top:4px;">' . esc_html( $issue->title ) . '</strong>';
			echo '<span style="display:block;color:#555;">' . esc_html( $issue->message ) . '</span>';

			// Status-change buttons as small nonce-protected forms.
			echo '<div style="margin-top:6px;">';
			$this->render_metabox_status_button( $issue->id, 'reviewed', __( 'Mark Reviewed', 'order-health-doctor' ) );
			$this->render_metabox_status_button( $issue->id, 'resolved', __( 'Resolve', 'order-health-doctor' ) );
			$this->render_metabox_status_button( $issue->id, 'ignored', __( 'Ignore', 'order-health-doctor' ) );
			echo '</div>';

			echo '</li>';
		}
		echo '</ul>';
	}

	/**
	 * Render a single status-change button for the meta box.
	 *
	 * After acting, the user is returned to the Issues page (the meta box does
	 * not know the order screen URL reliably across HPOS/legacy).
	 *
	 * @param int    $issue_id Issue ID.
	 * @param string $status   Target status.
	 * @param string $label    Button label.
	 */
	private function render_metabox_status_button( $issue_id, $status, $label ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;margin-right:4px;">';
		echo '<input type="hidden" name="action" value="ohd_update_issue_status" />';
		echo '<input type="hidden" name="issue_id" value="' . esc_attr( $issue_id ) . '" />';
		echo '<input type="hidden" name="new_status" value="' . esc_attr( $status ) . '" />';
		echo '<input type="hidden" name="redirect_page" value="order-health-doctor-issues" />';
		wp_nonce_field( 'ohd_update_issue_status' );
		echo '<button type="submit" class="button button-small">' . esc_html( $label ) . '</button>';
		echo '</form>';
	}
}
