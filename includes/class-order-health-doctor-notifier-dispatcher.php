<?php
/**
 * Notification dispatcher: routes issues/summaries to every enabled channel.
 *
 * @package Order_Health_Doctor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Order_Health_Doctor_Notifier_Dispatcher
 *
 * The single place that decides WHICH issues get sent and enforces a per-channel
 * cooldown, then hands each message to every active channel (Email, Telegram, and
 * any Pro channels). Centralizing the severity/type filtering and the cooldown
 * here keeps the channels themselves simple "dumb senders".
 *
 * Cooldown uses a transient per channel + issue (no schema change), so Email and
 * Telegram can each alert independently for the same issue.
 */
class Order_Health_Doctor_Notifier_Dispatcher {

	/**
	 * Channel registry.
	 *
	 * @var Order_Health_Doctor_Channel_Registry
	 */
	private $registry;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->registry = new Order_Health_Doctor_Channel_Registry();
	}

	/**
	 * Send immediate alerts for the issues created/reopened during a scan.
	 *
	 * @param int[] $issue_ids Issue IDs collected by the scanner.
	 * @return int Total alert messages sent across all channels.
	 */
	public function process_new_issues( $issue_ids ) {
		if ( empty( $issue_ids ) || ! is_array( $issue_ids ) ) {
			return 0;
		}

		// Global immediate-alerts toggle (shared across channels).
		if ( ! Order_Health_Doctor_Settings::is_enabled( 'email_immediate_critical' ) ) {
			return 0;
		}

		$channels = $this->registry->get_active_channels();
		if ( empty( $channels ) ) {
			return 0;
		}

		$severities = (array) Order_Health_Doctor_Settings::get( 'email_severities', array() );
		$types      = (array) Order_Health_Doctor_Settings::get( 'email_issue_types', array() );
		$cooldown   = $this->cooldown_seconds();
		$sent       = 0;

		foreach ( array_unique( array_map( 'intval', $issue_ids ) ) as $issue_id ) {
			$issue = Order_Health_Doctor_Issue_Repository::get_issue_by_id( $issue_id );
			if ( ! $issue ) {
				continue;
			}

			// Shared severity + issue-type filter.
			if ( ! in_array( $issue->severity, $severities, true ) ) {
				continue;
			}
			if ( ! in_array( $issue->issue_type, $types, true ) ) {
				continue;
			}

			foreach ( $channels as $channel ) {
				$key = $this->cooldown_key( $channel->get_id(), $issue->id );

				// Per-channel spam guard.
				if ( get_transient( $key ) ) {
					continue;
				}

				if ( $channel->send_issue( $issue ) ) {
					set_transient( $key, 1, $cooldown );

					// Back-compat: keep the email channel's last_notified_at column
					// populated for display in the admin.
					if ( 'email' === $channel->get_id() ) {
						Order_Health_Doctor_Issue_Repository::mark_notified( $issue->id );
					}

					++$sent;
				}
			}
		}

		return $sent;
	}

	/**
	 * Send the daily health summary to every active channel.
	 *
	 * Skipped entirely when there are no open issues. A per-channel, per-day guard
	 * prevents duplicate summaries if the scan runs more than once in a day.
	 *
	 * @return int Number of channels that sent a summary.
	 */
	public function send_daily_summaries() {
		if ( ! Order_Health_Doctor_Settings::is_enabled( 'email_daily_summary' ) ) {
			return 0;
		}

		$counts = Order_Health_Doctor_Issue_Repository::get_issue_counts();

		// Do not send when there are no open issues.
		if ( empty( $counts['total'] ) ) {
			return 0;
		}

		$channels = $this->registry->get_active_channels();
		if ( empty( $channels ) ) {
			return 0;
		}

		$health = Order_Health_Doctor_Admin::calculate_health_score( $counts );
		$data   = array(
			'counts'       => $counts,
			'health'       => $health,
			'health_label' => Order_Health_Doctor_Admin::health_label( $health ),
			'site_name'    => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			'top'          => Order_Health_Doctor_Issue_Repository::get_recent_issues( 10 ),
		);

		$today = current_time( 'Y-m-d' );
		$sent  = 0;

		foreach ( $channels as $channel ) {
			$option_key = 'ohd_last_summary_' . $channel->get_id();

			// One summary per channel per calendar day (site timezone).
			if ( get_option( $option_key ) === $today ) {
				continue;
			}

			if ( $channel->send_summary( $data ) ) {
				update_option( $option_key, $today );
				++$sent;
			}
		}

		return $sent;
	}

	/**
	 * Build the cooldown transient key for a channel + issue.
	 *
	 * @param string $channel_id Channel id.
	 * @param int    $issue_id   Issue id.
	 * @return string
	 */
	private function cooldown_key( $channel_id, $issue_id ) {
		return 'ohd_notified_' . sanitize_key( $channel_id ) . '_' . absint( $issue_id );
	}

	/**
	 * Cooldown window in seconds (default 24h).
	 *
	 * @return int
	 */
	private function cooldown_seconds() {
		/**
		 * Filter the per-issue, per-channel notification cooldown (seconds).
		 *
		 * @param int $seconds Default DAY_IN_SECONDS.
		 */
		return (int) apply_filters( 'order_health_doctor_notification_cooldown', DAY_IN_SECONDS );
	}
}
