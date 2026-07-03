<?php
/**
 * Rule: Failed Order Spike.
 *
 * @package Order_Health_Doctor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Order_Health_Doctor_Rule_Failed_Order_Spike
 *
 * Compares the failed-order count in the last 24 hours against the average daily
 * failed count over the previous 7 days. A single system issue is raised when
 * today's failures are both above the absolute threshold AND above the average
 * multiplied by the configured multiplier.
 */
class Order_Health_Doctor_Rule_Failed_Order_Spike extends Order_Health_Doctor_Rule {

	/**
	 * {@inheritDoc}
	 */
	public function get_id() {
		return 'failed_order_spike';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_label() {
		return __( 'Failed Order Spike', 'order-health-doctor' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_description() {
		return __( 'Unusual jump in failed orders in the last 24 hours.', 'order-health-doctor' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_group() {
		return 'system';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_default_severity() {
		return 'high';
	}

	/**
	 * Run this detection rule.
	 *
	 * @param Order_Health_Doctor_Scan_Context $ctx Shared scan context.
	 * @return array[] Detected issue data.
	 */
	public function run( Order_Health_Doctor_Scan_Context $ctx ) {
		$threshold  = (int) $ctx->get_setting( 'failed_order_threshold', 5 );
		$multiplier = (float) $ctx->get_setting( 'failed_order_multiplier', 2 );

		// Failed orders in the last 24 hours.
		$last_24h = $ctx->count_failed_orders_between(
			time() - DAY_IN_SECONDS,
			time()
		);

		// Failed orders across the previous 7 days (the 7 days before yesterday's window).
		$prev_7_days_total = $ctx->count_failed_orders_between(
			time() - ( 8 * DAY_IN_SECONDS ),
			time() - DAY_IN_SECONDS
		);
		$average           = $prev_7_days_total / 7;

		// Both conditions must hold to avoid noise on low-volume stores.
		if ( $last_24h > $threshold && $last_24h > ( $average * $multiplier ) ) {
			return array(
				array(
					'issue_type'       => $this->get_id(),
					'object_type'      => 'system',
					'object_id'        => 0,
					'title'            => __( 'Spike in failed orders detected', 'order-health-doctor' ),
					'message'          => sprintf(
						/* translators: 1: today count, 2: 7-day average (one decimal) */
						__( 'There were %1$d failed orders in the last 24 hours, compared to a recent daily average of about %2$s. This may indicate a payment gateway problem.', 'order-health-doctor' ),
						$last_24h,
						number_format_i18n( $average, 1 )
					),
					'suggested_action' => __( 'Review the failed orders and check your payment gateway status and logs.', 'order-health-doctor' ),
					'metadata'         => array(
						'last_24h' => $last_24h,
						'average'  => round( $average, 2 ),
					),
				),
			);
		}

		return array();
	}
}
