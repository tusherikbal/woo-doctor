<?php
/**
 * Scanner: runs all the free detection rules.
 *
 * @package Woo_Order_Doctor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Woo_Order_Doctor_Scanner
 *
 * Implements every free detection rule. Both the manual "Run Scan Now" button
 * and the scheduled daily cron call run_scan(), so detection logic lives in a
 * single place.
 *
 * All order access goes through wc_get_orders() / WC_Order CRUD methods so the
 * plugin stays HPOS-compatible and never touches wp_posts / wp_postmeta for
 * orders directly.
 */
class Woo_Order_Doctor_Scanner {

	/**
	 * Cached settings array for the duration of a scan.
	 *
	 * @var array
	 */
	private $settings;

	/**
	 * IDs of issues that were newly created or reopened during the current scan.
	 *
	 * Collected so the email notifier can send immediate alerts after the scan
	 * completes, rather than emailing from low-level database methods.
	 *
	 * @var int[]
	 */
	private $new_or_reopened = array();

	/**
	 * Run the full scan: execute every detection rule in turn.
	 *
	 * Guard clauses make sure we never run when monitoring is disabled or when
	 * WooCommerce is unavailable.
	 *
	 * @return array Summary with a timestamp and the number of issues found.
	 */
	public function run_scan() {
		if ( ! Woo_Order_Doctor_Dependency::is_woocommerce_active() ) {
			return array(
				'ran'      => false,
				'reason'   => 'woocommerce_inactive',
				'detected' => 0,
			);
		}

		$this->settings = Woo_Order_Doctor_Settings::get_all();

		if ( 'yes' !== $this->settings['enable_monitoring'] ) {
			return array(
				'ran'      => false,
				'reason'   => 'monitoring_disabled',
				'detected' => 0,
			);
		}

		// Reset the per-scan collection of newly created/reopened issues.
		$this->new_or_reopened = array();

		// Run each rule. Each returns the number of issues it created/updated.
		$detected = 0;
		$detected += $this->scan_paid_but_pending();
		$detected += $this->scan_processing_too_long();
		$detected += $this->scan_on_hold_too_long();
		$detected += $this->scan_failed_order_spike();
		$detected += $this->scan_duplicate_orders();
		$detected += $this->scan_stock_mismatch();
		$detected += $this->scan_email_settings();

		// Record when we last scanned so the dashboard can show it.
		update_option( 'wod_last_scan', current_time( 'mysql' ) );

		return array(
			'ran'             => true,
			'detected'        => $detected,
			'new_or_reopened' => $this->new_or_reopened,
		);
	}

	/**
	 * Persist an issue and record its ID when it is new or reopened.
	 *
	 * Thin wrapper around the repository so detection rules stay readable while
	 * the scanner transparently collects which issues warrant an immediate
	 * email alert.
	 *
	 * @param array $data Issue data (see Issue_Repository::create_or_update_issue).
	 * @return void
	 */
	private function record_issue( $data ) {
		$result = Woo_Order_Doctor_Issue_Repository::create_or_update_issue( $data );

		if ( is_array( $result ) && ! empty( $result['id'] ) && ( ! empty( $result['is_new'] ) || ! empty( $result['is_reopened'] ) ) ) {
			$this->new_or_reopened[] = (int) $result['id'];
		}
	}

	/**
	 * Rule 1: Paid But Pending (critical).
	 *
	 * Looks for pending / on-hold orders that have a positive total and are
	 * older than the configured threshold, and that show signs of payment
	 * (a transaction ID or a payment method). These are likely paid orders that
	 * were never advanced and need a human to verify.
	 *
	 * @return int Issues created/updated.
	 */
	public function scan_paid_but_pending() {
		$count            = 0;
		$threshold_minute = (int) $this->settings['paid_pending_minutes'];
		$cutoff           = time() - ( $threshold_minute * MINUTE_IN_SECONDS );

		$orders = wc_get_orders(
			array(
				'status'       => array( 'pending', 'on-hold' ),
				'date_created' => '>' . ( time() - $this->scan_window_seconds() ),
				'limit'        => 200,
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

			$this->record_issue(
				array(
					'issue_type'       => 'paid_but_pending',
					'severity'         => 'critical',
					'object_type'      => 'order',
					'object_id'        => $order->get_id(),
					'title'            => __( 'Possible paid order still pending', 'woo-order-doctor' ),
					'message'          => sprintf(
						/* translators: 1: order number, 2: age in minutes */
						__( 'Order #%1$s may need review because it is still pending after %2$d minutes.', 'woo-order-doctor' ),
						$order->get_order_number(),
						$age_minutes
					),
					'suggested_action' => __( 'Open the order and verify payment notes before manually changing the order status.', 'woo-order-doctor' ),
					'metadata'         => array(
						'status'         => $order->get_status(),
						'payment_method' => $order->get_payment_method(),
						'age_minutes'    => $age_minutes,
					),
				)
			);
			$count++;
		}

		return $count;
	}

	/**
	 * Rule 2: Processing Too Long (medium).
	 *
	 * Orders stuck in "processing" longer than the configured number of days.
	 *
	 * @return int Issues created/updated.
	 */
	public function scan_processing_too_long() {
		$count  = 0;
		$days   = (int) $this->settings['processing_days'];
		$cutoff = time() - ( $days * DAY_IN_SECONDS );

		$orders = wc_get_orders(
			array(
				'status'       => array( 'processing' ),
				'date_created' => '<' . $cutoff,
				'limit'        => 200,
				'return'       => 'objects',
			)
		);

		foreach ( $orders as $order ) {
			$created    = $order->get_date_created();
			$age_days   = $created ? max( 1, (int) floor( ( time() - $created->getTimestamp() ) / DAY_IN_SECONDS ) ) : $days;

			$this->record_issue(
				array(
					'issue_type'       => 'processing_too_long',
					'severity'         => 'medium',
					'object_type'      => 'order',
					'object_id'        => $order->get_id(),
					'title'            => __( 'Order processing for too long', 'woo-order-doctor' ),
					'message'          => sprintf(
						/* translators: 1: order number, 2: age in days */
						__( 'Order #%1$s has been processing for %2$d days. It may be waiting to be completed or shipped.', 'woo-order-doctor' ),
						$order->get_order_number(),
						$age_days
					),
					'suggested_action' => __( 'Open the order and confirm fulfillment status, then mark it completed if appropriate.', 'woo-order-doctor' ),
					'metadata'         => array( 'age_days' => $age_days ),
				)
			);
			$count++;
		}

		return $count;
	}

	/**
	 * Rule 3: On Hold Too Long (medium).
	 *
	 * Orders stuck in "on-hold" longer than the configured number of days.
	 *
	 * @return int Issues created/updated.
	 */
	public function scan_on_hold_too_long() {
		$count  = 0;
		$days   = (int) $this->settings['on_hold_days'];
		$cutoff = time() - ( $days * DAY_IN_SECONDS );

		$orders = wc_get_orders(
			array(
				'status'       => array( 'on-hold' ),
				'date_created' => '<' . $cutoff,
				'limit'        => 200,
				'return'       => 'objects',
			)
		);

		foreach ( $orders as $order ) {
			$created  = $order->get_date_created();
			$age_days = $created ? max( 1, (int) floor( ( time() - $created->getTimestamp() ) / DAY_IN_SECONDS ) ) : $days;

			$this->record_issue(
				array(
					'issue_type'       => 'on_hold_too_long',
					'severity'         => 'medium',
					'object_type'      => 'order',
					'object_id'        => $order->get_id(),
					'title'            => __( 'Order on hold for too long', 'woo-order-doctor' ),
					'message'          => sprintf(
						/* translators: 1: order number, 2: age in days */
						__( 'Order #%1$s has been on hold for %2$d days. Payment may be awaiting confirmation.', 'woo-order-doctor' ),
						$order->get_order_number(),
						$age_days
					),
					'suggested_action' => __( 'Open the order, confirm whether payment was received, and update the status accordingly.', 'woo-order-doctor' ),
					'metadata'         => array( 'age_days' => $age_days ),
				)
			);
			$count++;
		}

		return $count;
	}

	/**
	 * Rule 4: Failed Order Spike (high).
	 *
	 * Compares the failed-order count in the last 24 hours against the average
	 * daily failed count over the previous 7 days. A single system issue is
	 * raised when today's failures are both above the absolute threshold AND
	 * above the average multiplied by the configured multiplier.
	 *
	 * @return int Issues created/updated (0 or 1).
	 */
	public function scan_failed_order_spike() {
		$threshold  = (int) $this->settings['failed_order_threshold'];
		$multiplier = (float) $this->settings['failed_order_multiplier'];

		// Failed orders in the last 24 hours.
		$last_24h = $this->count_failed_orders_between(
			time() - DAY_IN_SECONDS,
			time()
		);

		// Failed orders across the previous 7 days (the 7 days before yesterday's window).
		$prev_7_days_total = $this->count_failed_orders_between(
			time() - ( 8 * DAY_IN_SECONDS ),
			time() - DAY_IN_SECONDS
		);
		$average = $prev_7_days_total / 7;

		// Both conditions must hold to avoid noise on low-volume stores.
		if ( $last_24h > $threshold && $last_24h > ( $average * $multiplier ) ) {
			$this->record_issue(
				array(
					'issue_type'       => 'failed_order_spike',
					'severity'         => 'high',
					'object_type'      => 'system',
					'object_id'        => 0,
					'title'            => __( 'Spike in failed orders detected', 'woo-order-doctor' ),
					'message'          => sprintf(
						/* translators: 1: today count, 2: 7-day average (one decimal) */
						__( 'There were %1$d failed orders in the last 24 hours, compared to a recent daily average of about %2$s. This may indicate a payment gateway problem.', 'woo-order-doctor' ),
						$last_24h,
						number_format_i18n( $average, 1 )
					),
					'suggested_action' => __( 'Review the failed orders and check your payment gateway status and logs.', 'woo-order-doctor' ),
					'metadata'         => array(
						'last_24h' => $last_24h,
						'average'  => round( $average, 2 ),
					),
				)
			);
			return 1;
		}

		return 0;
	}

	/**
	 * Rule 5: Duplicate Order (medium).
	 *
	 * Within the scan window, flags pairs of orders that share the same billing
	 * email OR customer ID, have the same total, and were created within the
	 * configured duplicate window. To reduce false positives, orders are sorted
	 * by date and only adjacent matching pairs are reported once.
	 *
	 * @return int Issues created/updated.
	 */
	public function scan_duplicate_orders() {
		$count         = 0;
		$window_minute = (int) $this->settings['duplicate_window_minutes'];
		$window_secs   = $window_minute * MINUTE_IN_SECONDS;

		$orders = wc_get_orders(
			array(
				'date_created' => '>' . ( time() - $this->scan_window_seconds() ),
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

					$same_total   = wc_format_decimal( $previous->get_total() ) === wc_format_decimal( $order->get_total() );
					$within_window = ( $prev_ts && $cur_ts && ( $cur_ts - $prev_ts ) <= $window_secs );

					if ( $same_total && $within_window ) {
						$this->record_issue(
							array(
								'issue_type'        => 'duplicate_order',
								'severity'          => 'medium',
								'object_type'       => 'order',
								'object_id'         => $previous->get_id(),
								'related_object_id' => $order->get_id(),
								'title'             => __( 'Possible duplicate order', 'woo-order-doctor' ),
								'message'           => sprintf(
									/* translators: 1: first order number, 2: second order number */
									__( 'Orders #%1$s and #%2$s look like duplicates: same customer and total, created close together.', 'woo-order-doctor' ),
									$previous->get_order_number(),
									$order->get_order_number()
								),
								'suggested_action'  => __( 'Compare both orders and cancel or refund one if it is a genuine duplicate.', 'woo-order-doctor' ),
								'metadata'          => array(
									'total'        => $order->get_total(),
									'gap_seconds'  => $cur_ts - $prev_ts,
								),
							)
						);
						$count++;
					}
				}
				$previous = $order;
			}
		}

		return $count;
	}

	/**
	 * Rule 6: Basic Stock Mismatch (high).
	 *
	 * Finds products with stock management enabled and a negative stock
	 * quantity (oversold). Uses WooCommerce product data methods, not raw meta.
	 *
	 * @return int Issues created/updated.
	 */
	public function scan_stock_mismatch() {
		$count = 0;

		// wc_get_products with stock_quantity is reliable; we additionally
		// confirm manage_stock and a negative quantity on the product object.
		$products = wc_get_products(
			array(
				'limit'        => 200,
				'manage_stock' => true,
				'return'       => 'objects',
			)
		);

		foreach ( $products as $product ) {
			if ( ! $product->managing_stock() ) {
				continue;
			}

			$stock = $product->get_stock_quantity();
			if ( null === $stock || $stock >= 0 ) {
				continue;
			}

			$this->record_issue(
				array(
					'issue_type'       => 'stock_mismatch',
					'severity'         => 'high',
					'object_type'      => 'product',
					'object_id'        => $product->get_id(),
					'title'            => __( 'Negative stock detected', 'woo-order-doctor' ),
					'message'          => sprintf(
						/* translators: 1: product name, 2: stock quantity */
						__( 'Product "%1$s" has a negative stock quantity (%2$s), which usually means it was oversold.', 'woo-order-doctor' ),
						$product->get_name(),
						$stock
					),
					'suggested_action' => __( 'Edit the product and correct the stock quantity, then review recent orders for this item.', 'woo-order-doctor' ),
					'metadata'         => array( 'stock_quantity' => $stock ),
				)
			);
			$count++;
		}

		return $count;
	}

	/**
	 * Rule 7: Email Settings Warning (medium).
	 *
	 * Checks that key transactional WooCommerce emails are enabled and that the
	 * New Order email has an admin recipient. This is a configuration check
	 * only; it cannot verify actual inbox delivery.
	 *
	 * @return int Issues created/updated.
	 */
	public function scan_email_settings() {
		$count   = 0;
		$mailer  = WC()->mailer();
		$emails  = $mailer->get_emails();

		// Map of email class => human label for the emails we care about.
		$watched = array(
			'WC_Email_New_Order'                 => __( 'New Order', 'woo-order-doctor' ),
			'WC_Email_Customer_Processing_Order' => __( 'Customer Processing Order', 'woo-order-doctor' ),
			'WC_Email_Customer_Completed_Order'  => __( 'Customer Completed Order', 'woo-order-doctor' ),
		);

		$disabled = array();
		foreach ( $watched as $class => $label ) {
			if ( isset( $emails[ $class ] ) && ! $emails[ $class ]->is_enabled() ) {
				$disabled[] = $label;
			}
		}

		// Raise an issue when one or more important emails are disabled.
		if ( ! empty( $disabled ) ) {
			$this->record_issue(
				array(
					'issue_type'       => 'email_settings_warning',
					'severity'         => 'medium',
					'object_type'      => 'settings',
					'object_id'        => 0,
					'related_object_id' => 0,
					'title'            => __( 'Important order emails are disabled', 'woo-order-doctor' ),
					'message'          => sprintf(
						/* translators: %s: comma-separated list of disabled email names */
						__( 'These transactional emails appear disabled: %s. Customers or admins may not be notified about orders.', 'woo-order-doctor' ),
						implode( ', ', $disabled )
					),
					'suggested_action' => __( 'Open WooCommerce email settings and enable the required transactional emails.', 'woo-order-doctor' ),
					'metadata'         => array( 'disabled' => $disabled ),
				)
			);
			$count++;
		}

		// Separately, warn if the New Order email has no admin recipient.
		if ( isset( $emails['WC_Email_New_Order'] ) ) {
			$recipient = trim( (string) $emails['WC_Email_New_Order']->get_recipient() );
			if ( '' === $recipient ) {
				$this->record_issue(
					array(
						'issue_type'       => 'email_settings_warning',
						'severity'         => 'medium',
						'object_type'      => 'settings',
						'object_id'        => 1, // Distinct object id so it gets its own hash.
						'title'            => __( 'New Order email has no admin recipient', 'woo-order-doctor' ),
						'message'          => __( 'The WooCommerce New Order email does not have an admin recipient configured, so store admins may not be notified of new orders.', 'woo-order-doctor' ),
						'suggested_action' => __( 'Open WooCommerce email settings and add a recipient to the New Order email.', 'woo-order-doctor' ),
						'metadata'         => array(),
					)
				);
				$count++;
			}
		}

		return $count;
	}

	/**
	 * Count failed orders created between two unix timestamps.
	 *
	 * Uses wc_get_orders with a return of "ids" for efficiency and HPOS safety.
	 *
	 * @param int $start Start timestamp (inclusive).
	 * @param int $end   End timestamp (inclusive).
	 * @return int Number of failed orders.
	 */
	private function count_failed_orders_between( $start, $end ) {
		$ids = wc_get_orders(
			array(
				'status'       => array( 'failed' ),
				'date_created' => absint( $start ) . '...' . absint( $end ),
				'limit'        => -1,
				'return'       => 'ids',
			)
		);

		return is_array( $ids ) ? count( $ids ) : 0;
	}

	/**
	 * Convert the configured scan_days setting into seconds.
	 *
	 * @return int Seconds in the scan window.
	 */
	private function scan_window_seconds() {
		$days = (int) $this->settings['scan_days'];
		return $days * DAY_IN_SECONDS;
	}
}
