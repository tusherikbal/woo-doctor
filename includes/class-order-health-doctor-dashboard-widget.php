<?php
/**
 * WordPress admin dashboard widget.
 *
 * @package Order_Health_Doctor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Order_Health_Doctor_Dashboard_Widget
 *
 * Adds an "Order Health" widget to the main WordPress dashboard so store managers
 * see the health score and any critical issues the moment they log in, without
 * opening the plugin. Uses native WP admin styling (Bootstrap is not loaded here).
 */
class Order_Health_Doctor_Dashboard_Widget {

	/**
	 * Register the widget hook.
	 */
	public function register_hooks() {
		add_action( 'wp_dashboard_setup', array( $this, 'add_widget' ) );
	}

	/**
	 * Add the widget for capable users only.
	 */
	public function add_widget() {
		if ( ! Order_Health_Doctor_Actions::can_manage() ) {
			return;
		}

		wp_add_dashboard_widget(
			'ohd_dashboard_widget',
			__( 'Order Health', 'order-health-doctor' ),
			array( $this, 'render' )
		);
	}

	/**
	 * Render the widget content.
	 */
	public function render() {
		$counts = Order_Health_Doctor_Issue_Repository::get_issue_counts();
		$health = Order_Health_Doctor_Admin::calculate_health_score( $counts );
		$label  = Order_Health_Doctor_Admin::health_label( $health );
		$color  = Order_Health_Doctor_Admin::health_hex( $health );

		$dashboard = admin_url( 'admin.php?page=order-health-doctor' );
		$issues    = admin_url( 'admin.php?page=order-health-doctor-issues&severity=critical' );

		echo '<div style="display:flex;align-items:center;gap:16px;">';

		// Simple inline SVG ring so the widget is self-contained (no assets here).
		$circumference = 2 * M_PI * 26;
		$offset        = $circumference * ( 1 - ( $health / 100 ) );
		echo '<svg width="72" height="72" viewBox="0 0 72 72" aria-hidden="true">';
		echo '<circle cx="36" cy="36" r="26" fill="none" stroke="#e2e4e7" stroke-width="8" />';
		echo '<circle cx="36" cy="36" r="26" fill="none" stroke="' . esc_attr( $color ) . '" stroke-width="8" stroke-linecap="round" stroke-dasharray="' . esc_attr( $circumference ) . '" stroke-dashoffset="' . esc_attr( $offset ) . '" transform="rotate(-90 36 36)" />';
		echo '<text x="36" y="41" text-anchor="middle" font-size="18" font-weight="700" fill="#1d2327">' . (int) $health . '</text>';
		echo '</svg>';

		echo '<div>';
		echo '<div style="font-size:15px;font-weight:600;color:' . esc_attr( $color ) . ';">' . esc_html( $label ) . '</div>';
		echo '<div style="color:#646970;">' . sprintf(
			/* translators: %d: total open issues */
			esc_html__( '%d open issues', 'order-health-doctor' ),
			(int) $counts['total']
		) . '</div>';
		if ( ! empty( $counts['critical'] ) ) {
			echo '<div style="margin-top:4px;"><a class="button button-small button-primary" href="' . esc_url( $issues ) . '">' . sprintf(
				/* translators: %d: number of critical issues */
				esc_html__( 'Review %d critical', 'order-health-doctor' ),
				(int) $counts['critical']
			) . '</a></div>';
		}
		echo '</div>';

		echo '</div>';

		echo '<p style="margin:12px 0 0;"><a href="' . esc_url( $dashboard ) . '">' . esc_html__( 'Open Order Doctor', 'order-health-doctor' ) . ' &rarr;</a></p>';
	}
}
