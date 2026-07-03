<?php
/**
 * Rule: On Hold Too Long.
 *
 * @package Order_Health_Doctor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Order_Health_Doctor_Rule_On_Hold_Too_Long
 *
 * Orders stuck in "on-hold" longer than the configured number of days. Oldest
 * orders are queried first so the most stale ones surface within the batch cap.
 */
class Order_Health_Doctor_Rule_On_Hold_Too_Long extends Order_Health_Doctor_Rule {

	/**
	 * {@inheritDoc}
	 */
	public function get_id() {
		return 'on_hold_too_long';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_label() {
		return __( 'On Hold Too Long', 'order-health-doctor' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_description() {
		return __( 'Orders stuck on hold past the configured number of days.', 'order-health-doctor' );
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
		$days   = (int) $ctx->get_setting( 'on_hold_days', 2 );
		$cutoff = time() - ( $days * DAY_IN_SECONDS );
		$start  = time() - $ctx->scan_window_seconds();

		if ( $cutoff <= $start ) {
			return $issues;
		}

		$orders = wc_get_orders(
			array(
				'status'       => array( 'on-hold' ),
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
				'title'            => __( 'Order on hold for too long', 'order-health-doctor' ),
				'message'          => sprintf(
					/* translators: 1: order number, 2: age in days */
					__( 'Order #%1$s has been on hold for %2$d days. Payment may be awaiting confirmation.', 'order-health-doctor' ),
					$order->get_order_number(),
					$age_days
				),
				'suggested_action' => __( 'Open the order, confirm whether payment was received, and update the status accordingly.', 'order-health-doctor' ),
				'metadata'         => array( 'age_days' => $age_days ),
			);
		}

		return $issues;
	}
}
