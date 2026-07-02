<?php
/**
 * Internal email notifications.
 *
 * @package Woo_Order_Doctor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Woo_Order_Doctor_Email_Notifier
 *
 * Sends INTERNAL email alerts (to the site admin, selected WordPress users, or
 * custom addresses) when order health issues are detected. It never emails
 * WooCommerce customers and never exposes private customer data such as full
 * billing address or phone number.
 *
 * All sending uses core wp_mail(). There are no external SMTP integrations,
 * APIs, or tracking pixels.
 */
class Woo_Order_Doctor_Email_Notifier {

	/**
	 * Whether the email system is switched on at all.
	 *
	 * @return bool
	 */
	public function is_enabled() {
		return Woo_Order_Doctor_Settings::is_enabled( 'email_notifications_enabled' );
	}

	/* ---------------------------------------------------------------------
	 * Recipients
	 * ------------------------------------------------------------------- */

	/**
	 * Build the list of recipient email addresses from settings.
	 *
	 * Honours the recipient mode, validates every address, de-duplicates, and
	 * returns a clean array. Customer emails are never sourced here.
	 *
	 * @return string[] Unique, valid recipient emails (possibly empty).
	 */
	public function get_recipients() {
		$mode       = Woo_Order_Doctor_Settings::get( 'email_recipient_mode', 'site_admin' );
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
		$raw   = (string) Woo_Order_Doctor_Settings::get( 'email_custom_recipients', '' );
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
		$ids = Woo_Order_Doctor_Settings::get( 'email_selected_users', array() );
		$out = array();

		foreach ( (array) $ids as $id ) {
			$user = get_userdata( absint( $id ) );
			if ( $user && is_email( $user->user_email ) ) {
				$out[] = $user->user_email;
			}
		}

		return $out;
	}

	/* ---------------------------------------------------------------------
	 * Sending
	 * ------------------------------------------------------------------- */

	/**
	 * Send an internal HTML email to the configured recipients.
	 *
	 * @param string $subject    Email subject (plain text).
	 * @param string $html_body  Pre-escaped HTML body.
	 * @param string $plain_body Optional plain-text alternative (unused by
	 *                           wp_mail HTML send but accepted for the API).
	 * @return bool True if wp_mail accepted the message, false otherwise.
	 */
	public function send_email( $subject, $html_body, $plain_body = '' ) {
		$recipients = $this->get_recipients();

		// No valid recipient: nothing to do.
		if ( empty( $recipients ) ) {
			return false;
		}

		// Build a clean From header from the configured name + the site admin.
		$from_name  = sanitize_text_field( Woo_Order_Doctor_Settings::get( 'email_from_name', 'Woo Order Doctor' ) );
		$from_email = sanitize_email( get_option( 'admin_email' ) );

		$headers = array( 'Content-Type: text/html; charset=UTF-8' );
		if ( '' !== $from_name && is_email( $from_email ) ) {
			$headers[] = sprintf( 'From: %s <%s>', $from_name, $from_email );
		}

		$document = $this->wrap_html_document( $subject, $html_body );

		// Temporarily ensure the content type is HTML, then send. We guard the
		// whole call so a failing mailer never throws a fatal up the stack.
		$sent = false;
		try {
			$sent = wp_mail( $recipients, $subject, $document, $headers );
		} catch ( \Throwable $e ) {
			// Swallow mailer exceptions; an alert failing must not break a scan.
			$sent = false;
		}

		return (bool) $sent;
	}

	/* ---------------------------------------------------------------------
	 * Immediate critical alerts
	 * ------------------------------------------------------------------- */

	/**
	 * Process the issues created/reopened during a scan and send alerts.
	 *
	 * Called by both the manual and scheduled scan flows. Each eligible issue is
	 * emailed at most once per cooldown window (default 24h).
	 *
	 * @param int[] $issue_ids IDs collected by the scanner.
	 * @return int Number of alert emails sent.
	 */
	public function process_new_issues( $issue_ids ) {
		// Respect the master switch and the immediate-alert toggle.
		if ( ! $this->is_enabled() || ! Woo_Order_Doctor_Settings::is_enabled( 'email_immediate_critical' ) ) {
			return 0;
		}

		if ( empty( $issue_ids ) || ! is_array( $issue_ids ) ) {
			return 0;
		}

		$severities = (array) Woo_Order_Doctor_Settings::get( 'email_severities', array() );
		$types      = (array) Woo_Order_Doctor_Settings::get( 'email_issue_types', array() );
		$sent       = 0;

		foreach ( array_unique( $issue_ids ) as $issue_id ) {
			$issue = Woo_Order_Doctor_Issue_Repository::get_issue_by_id( $issue_id );
			if ( ! $issue ) {
				continue;
			}

			// Filter by the admin's selected severities and issue types.
			if ( ! in_array( $issue->severity, $severities, true ) ) {
				continue;
			}
			if ( ! in_array( $issue->issue_type, $types, true ) ) {
				continue;
			}

			// Spam guard: do not re-alert the same issue within the cooldown.
			if ( Woo_Order_Doctor_Issue_Repository::was_recently_notified( $issue->id, 24 ) ) {
				continue;
			}

			if ( $this->send_immediate_alert( $issue ) ) {
				Woo_Order_Doctor_Issue_Repository::mark_notified( $issue->id );
				$sent++;
			}
		}

		return $sent;
	}

	/**
	 * Send a single immediate alert email for one issue.
	 *
	 * @param object $issue Issue row.
	 * @return bool True if sent.
	 */
	private function send_immediate_alert( $issue ) {
		$site_name = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );

		$subject = sprintf(
			/* translators: %s: severity label */
			__( '[Woo Order Doctor] %s Order Issue Detected', 'woo-order-doctor' ),
			ucfirst( $issue->severity )
		);

		// Build a small, escaped details table. Only safe, non-private fields.
		$rows = array(
			__( 'Severity', 'woo-order-doctor' )         => ucfirst( $issue->severity ),
			__( 'Issue type', 'woo-order-doctor' )        => Woo_Order_Doctor_Admin::issue_type_label( $issue->issue_type ),
			__( 'Message', 'woo-order-doctor' )           => $issue->message,
			__( 'Suggested action', 'woo-order-doctor' )  => $issue->suggested_action,
		);

		$body  = '<h2 style="margin:0 0 4px;">' . esc_html( $issue->title ) . '</h2>';
		$body .= '<p style="color:#555;margin:0 0 16px;">' . esc_html( $site_name ) . '</p>';
		$body .= $this->render_details_table( $rows );

		// Optional related object link (order/product/settings).
		$link = $this->get_object_link( $issue );
		if ( $link ) {
			$body .= '<p style="margin:16px 0 0;"><a href="' . esc_url( $link['url'] ) . '">' . esc_html( $link['label'] ) . '</a></p>';
		}

		$body .= $this->render_dashboard_links();

		return $this->send_email( $subject, $body );
	}

	/* ---------------------------------------------------------------------
	 * Daily summary
	 * ------------------------------------------------------------------- */

	/**
	 * Send the daily order-health summary email.
	 *
	 * For the MVP we skip sending entirely when there are zero open issues. A
	 * per-day guard prevents duplicate summaries if the scan runs more than once.
	 *
	 * @return bool True if an email was sent.
	 */
	public function send_daily_summary() {
		if ( ! $this->is_enabled() || ! Woo_Order_Doctor_Settings::is_enabled( 'email_daily_summary' ) ) {
			return false;
		}

		$counts = Woo_Order_Doctor_Issue_Repository::get_issue_counts();

		// MVP rule: do not send when there are no open issues.
		if ( empty( $counts['total'] ) ) {
			return false;
		}

		// Only one summary per calendar day (site timezone).
		$today = current_time( 'Y-m-d' );
		if ( get_option( 'wod_last_daily_summary' ) === $today ) {
			return false;
		}

		$health    = Woo_Order_Doctor_Admin::calculate_health_score( $counts );
		$label     = Woo_Order_Doctor_Admin::health_label( $health );
		$site_name = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );

		$subject = __( '[Woo Order Doctor] Daily Order Health Summary', 'woo-order-doctor' );

		$body  = '<h2 style="margin:0 0 4px;">' . esc_html__( 'Daily Order Health Summary', 'woo-order-doctor' ) . '</h2>';
		$body .= '<p style="color:#555;margin:0 0 16px;">' . esc_html( $site_name ) . '</p>';

		// Score + counts table.
		$rows = array(
			__( 'Health score', 'woo-order-doctor' )  => $health . '/100 (' . $label . ')',
			__( 'Open issues', 'woo-order-doctor' )    => (int) $counts['total'],
			__( 'Critical', 'woo-order-doctor' )       => (int) $counts['critical'],
			__( 'High', 'woo-order-doctor' )           => (int) $counts['high'],
			__( 'Medium', 'woo-order-doctor' )         => (int) $counts['medium'],
			__( 'Low', 'woo-order-doctor' )            => (int) $counts['low'],
		);
		$body .= $this->render_details_table( $rows );

		// Top 10 open issues.
		$top = Woo_Order_Doctor_Issue_Repository::get_recent_issues( 10 );
		if ( ! empty( $top ) ) {
			$body .= '<h3 style="margin:20px 0 8px;">' . esc_html__( 'Top open issues', 'woo-order-doctor' ) . '</h3>';
			$body .= '<table cellpadding="6" cellspacing="0" border="0" style="border-collapse:collapse;width:100%;font-family:Arial,sans-serif;font-size:13px;">';
			$body .= '<tr style="background:#f3f4f5;">';
			$body .= '<th align="left" style="border:1px solid #e0e0e0;">' . esc_html__( 'Severity', 'woo-order-doctor' ) . '</th>';
			$body .= '<th align="left" style="border:1px solid #e0e0e0;">' . esc_html__( 'Issue', 'woo-order-doctor' ) . '</th>';
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

		$sent = $this->send_email( $subject, $body );

		// Record the send so we do not duplicate it today.
		if ( $sent ) {
			update_option( 'wod_last_daily_summary', $today );
		}

		return $sent;
	}

	/* ---------------------------------------------------------------------
	 * Test email
	 * ------------------------------------------------------------------- */

	/**
	 * Send a test email to the configured recipients.
	 *
	 * @return bool True if sent.
	 */
	public function send_test_email() {
		$site_name = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );

		$subject = __( '[Woo Order Doctor] Test Email', 'woo-order-doctor' );

		$body  = '<h2 style="margin:0 0 4px;">' . esc_html__( 'Test email', 'woo-order-doctor' ) . '</h2>';
		$body .= '<p style="margin:0 0 16px;">';
		$body .= sprintf(
			/* translators: %s: site name */
			esc_html__( 'This is a test email from Woo Order Doctor on %s. If you received it, your internal alert recipients are configured correctly.', 'woo-order-doctor' ),
			esc_html( $site_name )
		);
		$body .= '</p>';
		$body .= '<p style="color:#777;font-size:12px;">' . esc_html__( 'Woo Order Doctor sends internal alerts only. It does not email customers.', 'woo-order-doctor' ) . '</p>';
		$body .= $this->render_dashboard_links();

		return $this->send_email( $subject, $body );
	}

	/* ---------------------------------------------------------------------
	 * HTML helpers
	 * ------------------------------------------------------------------- */

	/**
	 * Render a simple two-column key/value table from an associative array.
	 *
	 * Both keys and values are escaped here, so callers may pass raw strings.
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
		$dashboard = admin_url( 'admin.php?page=woo-order-doctor' );
		$issues    = admin_url( 'admin.php?page=woo-order-doctor-issues' );

		$html  = '<p style="margin:20px 0 0;">';
		$html .= '<a href="' . esc_url( $dashboard ) . '">' . esc_html__( 'Open Dashboard', 'woo-order-doctor' ) . '</a>';
		$html .= ' &nbsp;|&nbsp; ';
		$html .= '<a href="' . esc_url( $issues ) . '">' . esc_html__( 'View Issues', 'woo-order-doctor' ) . '</a>';
		$html .= '</p>';

		return $html;
	}

	/**
	 * Get a safe "related object" link for an issue, when one applies.
	 *
	 * Returns admin links only (never customer-facing) and never includes any
	 * private customer data.
	 *
	 * @param object $issue Issue row.
	 * @return array|null { @type string $url, @type string $label } or null.
	 */
	private function get_object_link( $issue ) {
		switch ( $issue->object_type ) {
			case 'order':
				return array(
					'url'   => Woo_Order_Doctor_Admin::get_order_edit_url( (int) $issue->object_id ),
					'label' => __( 'View order', 'woo-order-doctor' ),
				);

			case 'product':
				$link = get_edit_post_link( (int) $issue->object_id, '' );
				return $link ? array(
					'url'   => $link,
					'label' => __( 'Edit product', 'woo-order-doctor' ),
				) : null;

			case 'settings':
				return array(
					'url'   => Woo_Order_Doctor_Admin::get_email_settings_url(),
					'label' => __( 'Open email settings', 'woo-order-doctor' ),
				);

			case 'system':
			default:
				if ( 'failed_order_spike' === $issue->issue_type ) {
					return array(
						'url'   => Woo_Order_Doctor_Admin::get_failed_orders_url(),
						'label' => __( 'View failed orders', 'woo-order-doctor' ),
					);
				}
				return null;
		}
	}

	/**
	 * Wrap a body fragment in a minimal, self-contained HTML document.
	 *
	 * No external CSS, images, fonts, or tracking pixels are used.
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
		$html .= esc_html__( 'Internal alert from Woo Order Doctor. No customer data is shared.', 'woo-order-doctor' );
		$html .= '</p>';
		$html .= '</div></body></html>';

		return $html;
	}
}
