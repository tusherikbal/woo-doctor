<?php
/**
 * Rule: Email Settings Warning.
 *
 * @package Order_Health_Doctor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Order_Health_Doctor_Rule_Email_Settings
 *
 * Checks that key transactional WooCommerce emails are enabled and that the New
 * Order email has an admin recipient. This is a configuration check only; it
 * cannot verify actual inbox delivery.
 */
class Order_Health_Doctor_Rule_Email_Settings extends Order_Health_Doctor_Rule {

	/**
	 * {@inheritDoc}
	 */
	public function get_id() {
		return 'email_settings_warning';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_label() {
		return __( 'Email Settings Warning', 'order-health-doctor' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_description() {
		return __( 'Important WooCommerce transactional emails disabled or missing a recipient.', 'order-health-doctor' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_group() {
		return 'settings';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_object_type() {
		return 'settings';
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
		$mailer = WC()->mailer();
		$emails = $mailer->get_emails();

		// Map of email class => human label for the emails we care about.
		$watched = array(
			'WC_Email_New_Order'                 => __( 'New Order', 'order-health-doctor' ),
			'WC_Email_Customer_Processing_Order' => __( 'Customer Processing Order', 'order-health-doctor' ),
			'WC_Email_Customer_Completed_Order'  => __( 'Customer Completed Order', 'order-health-doctor' ),
		);

		$disabled = array();
		foreach ( $watched as $class => $label ) {
			if ( isset( $emails[ $class ] ) && ! $emails[ $class ]->is_enabled() ) {
				$disabled[] = $label;
			}
		}

		// Raise an issue when one or more important emails are disabled.
		if ( ! empty( $disabled ) ) {
			$issues[] = array(
				'issue_type'        => $this->get_id(),
				'object_type'       => 'settings',
				'object_id'         => 0,
				'related_object_id' => 0,
				'title'             => __( 'Important order emails are disabled', 'order-health-doctor' ),
				'message'           => sprintf(
					/* translators: %s: comma-separated list of disabled email names */
					__( 'These transactional emails appear disabled: %s. Customers or admins may not be notified about orders.', 'order-health-doctor' ),
					implode( ', ', $disabled )
				),
				'suggested_action'  => __( 'Open WooCommerce email settings and enable the required transactional emails.', 'order-health-doctor' ),
				'metadata'          => array( 'disabled' => $disabled ),
			);
		}

		// Separately, warn if the New Order email has no admin recipient.
		if ( isset( $emails['WC_Email_New_Order'] ) ) {
			$recipient = trim( (string) $emails['WC_Email_New_Order']->get_recipient() );
			if ( '' === $recipient ) {
				$issues[] = array(
					'issue_type'       => $this->get_id(),
					'object_type'      => 'settings',
					'object_id'        => 1, // Distinct object id so it gets its own hash.
					'title'            => __( 'New Order email has no admin recipient', 'order-health-doctor' ),
					'message'          => __( 'The WooCommerce New Order email does not have an admin recipient configured, so store admins may not be notified of new orders.', 'order-health-doctor' ),
					'suggested_action' => __( 'Open WooCommerce email settings and add a recipient to the New Order email.', 'order-health-doctor' ),
					'metadata'         => array(),
				);
			}
		}

		return $issues;
	}
}
