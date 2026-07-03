<?php
/**
 * Email notification channel.
 *
 * @package Order_Health_Doctor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Order_Health_Doctor_Channel_Email
 *
 * Sends INTERNAL email alerts (site admin, selected WordPress users, or custom
 * addresses). It never emails WooCommerce customers and never exposes private
 * customer data. All sending uses core wp_mail(); there are no external SMTP
 * integrations, APIs, or tracking pixels.
 *
 * Delivery decisions (which issues, cooldown) live in the dispatcher; this class
 * only formats and sends.
 */
class Order_Health_Doctor_Channel_Email implements Order_Health_Doctor_Notification_Channel {

	/**
	 * {@inheritDoc}
	 */
	public function get_id() {
		return 'email';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_label() {
		return __( 'Email', 'order-health-doctor' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_enabled() {
		return Order_Health_Doctor_Settings::is_enabled( 'email_notifications_enabled' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_configured() {
		return ! empty( $this->get_recipients() );
	}

	// ---------------------------------------------------------------------
	// Recipients.
	// ---------------------------------------------------------------------

	/**
	 * Build the list of recipient email addresses from settings.
	 *
	 * @return string[] Unique, valid recipient emails (possibly empty).
	 */
	public function get_recipients() {
		$mode       = Order_Health_Doctor_Settings::get( 'email_recipient_mode', 'site_admin' );
		$recipients = array();

		switch ( $mode ) {
			case 'custom_emails':
				$recipients = $this->get_custom_recipients();
				break;

			case 'selected_users':
				$recipients = $this->get_selected_user_emails();
				break;

			case 'site_admin_and_custom':
				$recipients = array_merge(
					array( get_option( 'admin_email' ) ),
					$this->get_custom_recipients()
				);
				break;

			case 'site_admin':
			default:
				$recipients = array( get_option( 'admin_email' ) );
				break;
		}

		// Final clean-up: validate and de-duplicate (case-insensitive).
		$clean = array();
		foreach ( $recipients as $email ) {
			$email = sanitize_email( (string) $email );
			if ( '' !== $email && is_email( $email ) ) {
				$clean[ strtolower( $email ) ] = $email;
			}
		}

		return array_values( $clean );
	}

	/**
	 * Parse the stored custom recipients string into an array of emails.
	 *
	 * @return string[]
	 */
	private function get_custom_recipients() {
		$raw   = (string) Order_Health_Doctor_Settings::get( 'email_custom_recipients', '' );
		$parts = preg_split( '/[\r\n,]+/', $raw );
		$out   = array();

		foreach ( (array) $parts as $part ) {
			$email = sanitize_email( trim( $part ) );
			if ( '' !== $email && is_email( $email ) ) {
				$out[] = $email;
			}
		}

		return $out;
	}

	/**
	 * Resolve selected user IDs to their email addresses.
	 *
	 * @return string[]
	 */
	private function get_selected_user_emails() {
		$ids = Order_Health_Doctor_Settings::get( 'email_selected_users', array() );
		$out = array();

		foreach ( (array) $ids as $id ) {
			$user = get_userdata( absint( $id ) );
			if ( $user && is_email( $user->user_email ) ) {
				$out[] = $user->user_email;
			}
		}

		return $out;
	}

	// ---------------------------------------------------------------------
	// Sending primitives.
	// ---------------------------------------------------------------------

	/**
	 * Send an internal HTML email to the configured recipients.
	 *
	 * @param string $subject   Email subject (plain text).
	 * @param string $html_body Pre-escaped HTML body.
	 * @return bool True if wp_mail accepted the message, false otherwise.
	 */
	public function send_email( $subject, $html_body ) {
		$recipients = $this->get_recipients();

		if ( empty( $recipients ) ) {
			return false;
		}

		$from_name  = sanitize_text_field( Order_Health_Doctor_Settings::get( 'email_from_name', 'Order Health Doctor' ) );
		$from_email = sanitize_email( get_option( 'admin_email' ) );

		$headers = array( 'Content-Type: text/html; charset=UTF-8' );
		if ( '' !== $from_name && is_email( $from_email ) ) {
			$headers[] = sprintf( 'From: %s <%s>', $from_name, $from_email );
		}

		$document = $this->wrap_html_document( $subject, $html_body );

		$sent = false;
		try {
			$sent = wp_mail( $recipients, $subject, $document, $headers );
		} catch ( \Throwable $e ) {
			// Swallow mailer exceptions; an alert failing must not break a scan.
			$sent = false;
		}

		return (bool) $sent;
	}

	// ---------------------------------------------------------------------
	// Channel API.
	// ---------------------------------------------------------------------

	/**
	 * Send an immediate issue email.
	 *
	 * @param object $issue Issue row.
	 * @return bool
	 */
	public function send_issue( $issue ) {
		$site_name = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );

		$subject = sprintf(
			/* translators: %s: severity label */
			__( '[Order Health Doctor] %s Order Issue Detected', 'order-health-doctor' ),
			ucfirst( $issue->severity )
		);

		$rows = array(
			__( 'Severity', 'order-health-doctor' )   => ucfirst( $issue->severity ),
			__( 'Issue type', 'order-health-doctor' ) => Order_Health_Doctor_Admin::issue_type_label( $issue->issue_type ),
			__( 'Message', 'order-health-doctor' )    => $issue->message,
			__( 'Suggested action', 'order-health-doctor' ) => $issue->suggested_action,
		);

		$body  = '<h2 style="margin:0 0 4px;">' . esc_html( $issue->title ) . '</h2>';
		$body .= '<p style="color:#555;margin:0 0 16px;">' . esc_html( $site_name ) . '</p>';
		$body .= $this->render_details_table( $rows );

		$link = $this->get_object_link( $issue );
		if ( $link ) {
			$body .= '<p style="margin:16px 0 0;"><a href="' . esc_url( $link['url'] ) . '">' . esc_html( $link['label'] ) . '</a></p>';
		}

		$body .= $this->render_dashboard_links();

		return $this->send_email( $subject, $body );
	}

	/**
	 * Send a daily summary email.
	 *
	 * @param array $data Prepared summary data.
	 * @return bool
	 */
	public function send_summary( $data ) {
		$counts    = isset( $data['counts'] ) ? $data['counts'] : array();
		$health    = isset( $data['health'] ) ? (int) $data['health'] : 0;
		$label     = isset( $data['health_label'] ) ? $data['health_label'] : '';
		$site_name = isset( $data['site_name'] ) ? $data['site_name'] : wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$top       = isset( $data['top'] ) ? $data['top'] : array();

		$subject = __( '[Order Health Doctor] Daily Order Health Summary', 'order-health-doctor' );

		$body  = '<h2 style="margin:0 0 4px;">' . esc_html__( 'Daily Order Health Summary', 'order-health-doctor' ) . '</h2>';
		$body .= '<p style="color:#555;margin:0 0 16px;">' . esc_html( $site_name ) . '</p>';

		$rows  = array(
			__( 'Health score', 'order-health-doctor' ) => $health . '/100 (' . $label . ')',
			__( 'Open issues', 'order-health-doctor' )  => isset( $counts['total'] ) ? (int) $counts['total'] : 0,
			__( 'Critical', 'order-health-doctor' )     => isset( $counts['critical'] ) ? (int) $counts['critical'] : 0,
			__( 'High', 'order-health-doctor' )         => isset( $counts['high'] ) ? (int) $counts['high'] : 0,
			__( 'Medium', 'order-health-doctor' )       => isset( $counts['medium'] ) ? (int) $counts['medium'] : 0,
			__( 'Low', 'order-health-doctor' )          => isset( $counts['low'] ) ? (int) $counts['low'] : 0,
		);
		$body .= $this->render_details_table( $rows );

		if ( ! empty( $top ) ) {
			$body .= '<h3 style="margin:20px 0 8px;">' . esc_html__( 'Top open issues', 'order-health-doctor' ) . '</h3>';
			$body .= '<table cellpadding="6" cellspacing="0" border="0" style="border-collapse:collapse;width:100%;font-family:Arial,sans-serif;font-size:13px;">';
			$body .= '<tr style="background:#f3f4f5;">';
			$body .= '<th align="left" style="border:1px solid #e0e0e0;">' . esc_html__( 'Severity', 'order-health-doctor' ) . '</th>';
			$body .= '<th align="left" style="border:1px solid #e0e0e0;">' . esc_html__( 'Issue', 'order-health-doctor' ) . '</th>';
			$body .= '</tr>';
			foreach ( $top as $issue ) {
				$body .= '<tr>';
				$body .= '<td style="border:1px solid #e0e0e0;">' . esc_html( ucfirst( $issue->severity ) ) . '</td>';
				$body .= '<td style="border:1px solid #e0e0e0;">' . esc_html( $issue->title ) . '</td>';
				$body .= '</tr>';
			}
			$body .= '</table>';
		}

		$body .= $this->render_dashboard_links();

		return $this->send_email( $subject, $body );
	}

	/**
	 * {@inheritDoc}
	 */
	public function send_test() {
		$site_name = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );

		$subject = __( '[Order Health Doctor] Test Email', 'order-health-doctor' );

		$body  = '<h2 style="margin:0 0 4px;">' . esc_html__( 'Test email', 'order-health-doctor' ) . '</h2>';
		$body .= '<p style="margin:0 0 16px;">';
		$body .= sprintf(
			/* translators: %s: site name */
			esc_html__( 'This is a test email from Order Health Doctor on %s. If you received it, your internal alert recipients are configured correctly.', 'order-health-doctor' ),
			esc_html( $site_name )
		);
		$body .= '</p>';
		$body .= '<p style="color:#777;font-size:12px;">' . esc_html__( 'Order Health Doctor sends internal alerts only. It does not email customers.', 'order-health-doctor' ) . '</p>';
		$body .= $this->render_dashboard_links();

		return $this->send_email( $subject, $body );
	}

	// ---------------------------------------------------------------------
	// HTML helpers.
	// ---------------------------------------------------------------------

	/**
	 * Render a simple two-column key/value table from an associative array.
	 *
	 * @param array $rows Label => value pairs.
	 * @return string HTML.
	 */
	private function render_details_table( $rows ) {
		$html = '<table cellpadding="6" cellspacing="0" border="0" style="border-collapse:collapse;width:100%;font-family:Arial,sans-serif;font-size:13px;">';
		foreach ( $rows as $label => $value ) {
			$html .= '<tr>';
			$html .= '<td style="border:1px solid #e0e0e0;background:#f9f9f9;font-weight:bold;width:35%;">' . esc_html( $label ) . '</td>';
			$html .= '<td style="border:1px solid #e0e0e0;">' . esc_html( (string) $value ) . '</td>';
			$html .= '</tr>';
		}
		$html .= '</table>';
		return $html;
	}

	/**
	 * Build the trailing dashboard + issues links block.
	 *
	 * @return string HTML.
	 */
	private function render_dashboard_links() {
		$dashboard = admin_url( 'admin.php?page=order-health-doctor' );
		$issues    = admin_url( 'admin.php?page=order-health-doctor-issues' );

		$html  = '<p style="margin:20px 0 0;">';
		$html .= '<a href="' . esc_url( $dashboard ) . '">' . esc_html__( 'Open Dashboard', 'order-health-doctor' ) . '</a>';
		$html .= ' &nbsp;|&nbsp; ';
		$html .= '<a href="' . esc_url( $issues ) . '">' . esc_html__( 'View Issues', 'order-health-doctor' ) . '</a>';
		$html .= '</p>';

		return $html;
	}

	/**
	 * Get a safe "related object" link for an issue, when one applies.
	 *
	 * @param object $issue Issue row.
	 * @return array|null { @type string $url, @type string $label } or null.
	 */
	private function get_object_link( $issue ) {
		switch ( $issue->object_type ) {
			case 'order':
				return array(
					'url'   => Order_Health_Doctor_Admin::get_order_edit_url( (int) $issue->object_id ),
					'label' => __( 'View order', 'order-health-doctor' ),
				);

			case 'product':
				$link = get_edit_post_link( (int) $issue->object_id, '' );
				return $link ? array(
					'url'   => $link,
					'label' => __( 'Edit product', 'order-health-doctor' ),
				) : null;

			case 'settings':
				return array(
					'url'   => Order_Health_Doctor_Admin::get_email_settings_url(),
					'label' => __( 'Open email settings', 'order-health-doctor' ),
				);

			case 'system':
			default:
				if ( 'failed_order_spike' === $issue->issue_type ) {
					return array(
						'url'   => Order_Health_Doctor_Admin::get_failed_orders_url(),
						'label' => __( 'View failed orders', 'order-health-doctor' ),
					);
				}
				return null;
		}
	}

	/**
	 * Wrap a body fragment in a minimal, self-contained HTML document.
	 *
	 * @param string $title Document title (escaped here).
	 * @param string $body  Pre-escaped HTML body fragment.
	 * @return string Full HTML document.
	 */
	private function wrap_html_document( $title, $body ) {
		$html  = '<!DOCTYPE html><html><head><meta charset="utf-8" />';
		$html .= '<title>' . esc_html( $title ) . '</title></head>';
		$html .= '<body style="margin:0;padding:0;background:#f1f1f1;">';
		$html .= '<div style="max-width:640px;margin:0 auto;padding:24px;">';
		$html .= '<div style="background:#fff;border:1px solid #e0e0e0;border-radius:6px;padding:24px;font-family:Arial,sans-serif;color:#222;">';
		$html .= $body;
		$html .= '</div>';
		$html .= '<p style="text-align:center;color:#999;font-size:11px;margin-top:16px;font-family:Arial,sans-serif;">';
		$html .= esc_html__( 'Internal alert from Order Health Doctor. No customer data is shared.', 'order-health-doctor' );
		$html .= '</p>';
		$html .= '</div></body></html>';

		return $html;
	}
}
