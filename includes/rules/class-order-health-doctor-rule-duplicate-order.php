<?php
/**
 * Rule: Duplicate Order.
 *
 * @package Order_Health_Doctor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Order_Health_Doctor_Rule_Duplicate_Order
 *
 * Within the scan window, flags pairs of orders that share the same billing email
 * OR customer ID, have the same total, and were created within the configured
 * duplicate window. Orders are sorted by date and only adjacent matching pairs are
 * reported to reduce false positives.
 */
class Order_Health_Doctor_Rule_Duplicate_Order extends Order_Health_Doctor_Rule {

	/**
	 * {@inheritDoc}
	 */
	public function get_id() {
		return 'duplicate_order';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_label() {
		return __( 'Duplicate Order', 'order-health-doctor' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_description() {
		return __( 'Likely duplicate orders from the same customer (same total, close together).', 'order-health-doctor' );
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
		$issues        = array();
		$window_minute = (int) $ctx->get_setting( 'duplicate_window_minutes', 30 );
		$window_secs   = $window_minute * MINUTE_IN_SECONDS;

		$orders = wc_get_orders(
			array(
				'date_created' => '>' . ( time() - $ctx->scan_window_seconds() ),
				'limit'        => 300,
				'orderby'      => 'date',
				'order'        => 'ASC',
				'return'       => 'objects',
			)
		);

		// Group orders by a customer key (email + customer id) so we only compare
		// orders that plausibly belong to the same buyer.
		$groups = array();
		foreach ( $orders as $order ) {
			$email = strtolower( trim( (string) $order->get_billing_email() ) );
			$key   = $email . '|' . (int) $order->get_customer_id();
			if ( '' === $email && 0 === (int) $order->get_customer_id() ) {
				// No way to associate a guest with no email; skip.
				continue;
			}
			$groups[ $key ][] = $order;
		}

		foreach ( $groups as $group ) {
			if ( count( $group ) < 2 ) {
				continue;
			}

			// Compare consecutive orders within the same customer group.
			$previous = null;
			foreach ( $group as $order ) {
				if ( $previous ) {
					$prev_ts = $previous->get_date_created() ? $previous->get_date_created()->getTimestamp() : 0;
					$cur_ts  = $order->get_date_created() ? $order->get_date_created()->getTimestamp() : 0;

					$same_total    = wc_format_decimal( $previous->get_total() ) === wc_format_decimal( $order->get_total() );
					$within_window = ( $prev_ts && $cur_ts && ( $cur_ts - $prev_ts ) <= $window_secs );

					if ( $same_total && $within_window ) {
						$issues[] = array(
							'issue_type'        => $this->get_id(),
							'object_type'       => 'order',
							'object_id'         => $previous->get_id(),
							'related_object_id' => $order->get_id(),
							'title'             => __( 'Possible duplicate order', 'order-health-doctor' ),
							'message'           => sprintf(
								/* translators: 1: first order number, 2: second order number */
								__( 'Orders #%1$s and #%2$s look like duplicates: same customer and total, created close together.', 'order-health-doctor' ),
								$previous->get_order_number(),
								$order->get_order_number()
							),
							'suggested_action'  => __( 'Compare both orders and cancel or refund one if it is a genuine duplicate.', 'order-health-doctor' ),
							'metadata'          => array(
								'total'       => $order->get_total(),
								'gap_seconds' => $cur_ts - $prev_ts,
							),
						);
					}
				}
				$previous = $order;
			}
		}

		return $issues;
	}
}
