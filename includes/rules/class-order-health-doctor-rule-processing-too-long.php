<?php
/**
 * Rule: Processing Too Long.
 *
 * @package Order_Health_Doctor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Order_Health_Doctor_Rule_Processing_Too_Long
 *
 * Orders stuck in "processing" longer than the configured number of days. Oldest
 * orders are queried first so the most stale ones surface within the batch cap.
 */
class Order_Health_Doctor_Rule_Processing_Too_Long extends Order_Health_Doctor_Rule {

	/**
	 * {@inheritDoc}
	 */
	public function get_id() {
		return 'processing_too_long';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_label() {
		return __( 'Processing Too Long', 'order-health-doctor' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_description() {
		return __( 'Orders stuck in processing past the configured number of days.', 'order-health-doctor' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_default_severity() {
		return 'medium';
	}

	/**
	 * Run this detection rule.
	 *
	 * @param Order_Health_Doctor_Scan_Context $ctx Shared scan context.
	 * @return array[] Detected issue data.
	 */
	public function run( Order_Health_Doctor_Scan_Context $ctx ) {
		$issues = array();
		$days   = (int) $ctx->get_setting( 'processing_days', 5 );
		$cutoff = time() - ( $days * DAY_IN_SECONDS );
		$start  = time() - $ctx->scan_window_seconds();

		if ( $cutoff <= $start ) {
			return $issues;
		}

		$orders = wc_get_orders(
			array(
				'status'       => array( 'processing' ),
				'date_created' => $start . '...' . $cutoff,
				'limit'        => 200,
				'orderby'      => 'date',
				'order'        => 'ASC',
				'return'       => 'objects',
			)
		);

		foreach ( $orders as $order ) {
			$created  = $order->get_date_created();
			$age_days = $created ? max( 1, (int) floor( ( time() - $created->getTimestamp() ) / DAY_IN_SECONDS ) ) : $days;

			$issues[] = array(
				'issue_type'       => $this->get_id(),
				'object_type'      => 'order',
				'object_id'        => $order->get_id(),
				'title'            => __( 'Order processing for too long', 'order-health-doctor' ),
				'message'          => sprintf(
					/* translators: 1: order number, 2: age in days */
					__( 'Order #%1$s has been processing for %2$d days. It may be waiting to be completed or shipped.', 'order-health-doctor' ),
					$order->get_order_number(),
					$age_days
				),
				'suggested_action' => __( 'Open the order and confirm fulfillment status, then mark it completed if appropriate.', 'order-health-doctor' ),
				'metadata'         => array( 'age_days' => $age_days ),
			);
		}

		return $issues;
	}
}
