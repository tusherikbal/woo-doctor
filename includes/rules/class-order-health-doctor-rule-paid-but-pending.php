<?php
/**
 * Rule: Paid But Pending.
 *
 * @package Order_Health_Doctor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Order_Health_Doctor_Rule_Paid_But_Pending
 *
 * Looks for pending / on-hold orders that have a positive total and are older
 * than the configured threshold, and that show signs of payment (a transaction
 * ID or a payment method). These are likely paid orders that were never advanced
 * and need a human to verify.
 */
class Order_Health_Doctor_Rule_Paid_But_Pending extends Order_Health_Doctor_Rule {

	/**
	 * {@inheritDoc}
	 */
	public function get_id() {
		return 'paid_but_pending';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_label() {
		return __( 'Paid But Pending', 'order-health-doctor' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_description() {
		return __( 'Paid-looking pending/on-hold orders older than the threshold.', 'order-health-doctor' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_default_severity() {
		return 'critical';
	}

	/**
	 * Run this detection rule.
	 *
	 * @param Order_Health_Doctor_Scan_Context $ctx Shared scan context.
	 * @return array[] Detected issue data.
	 */
	public function run( Order_Health_Doctor_Scan_Context $ctx ) {
		$issues           = array();
		$threshold_minute = (int) $ctx->get_setting( 'paid_pending_minutes', 30 );
		$cutoff           = time() - ( $threshold_minute * MINUTE_IN_SECONDS );

		$orders = wc_get_orders(
			array(
				'status'       => array( 'pending', 'on-hold' ),
				'date_created' => '>' . ( time() - $ctx->scan_window_seconds() ),
				'limit'        => 200,
				'orderby'      => 'date',
				'order'        => 'ASC',
				'return'       => 'objects',
			)
		);

		foreach ( $orders as $order ) {
			// Only consider orders that look paid: positive total + payment hint.
			if ( (float) $order->get_total() <= 0 ) {
				continue;
			}

			$created    = $order->get_date_created();
			$created_ts = $created ? $created->getTimestamp() : 0;

			// Skip orders that are not yet older than the threshold.
			if ( ! $created_ts || $created_ts > $cutoff ) {
				continue;
			}

			$has_payment_hint = ( '' !== $order->get_transaction_id() ) || ( '' !== $order->get_payment_method() );
			if ( ! $has_payment_hint ) {
				continue;
			}

			$age_minutes = max( 1, (int) floor( ( time() - $created_ts ) / MINUTE_IN_SECONDS ) );

			$issues[] = array(
				'issue_type'       => $this->get_id(),
				'object_type'      => 'order',
				'object_id'        => $order->get_id(),
				'title'            => __( 'Possible paid order still pending', 'order-health-doctor' ),
				'message'          => sprintf(
					/* translators: 1: order number, 2: age in minutes */
					__( 'Order #%1$s may need review because it is still pending after %2$d minutes.', 'order-health-doctor' ),
					$order->get_order_number(),
					$age_minutes
				),
				'suggested_action' => __( 'Open the order and verify payment notes before manually changing the order status.', 'order-health-doctor' ),
				'metadata'         => array(
					'status'         => $order->get_status(),
					'payment_method' => $order->get_payment_method(),
					'age_minutes'    => $age_minutes,
				),
			);
		}

		return $issues;
	}
}
