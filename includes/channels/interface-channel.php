<?php
/**
 * Notification channel contract.
 *
 * @package Order_Health_Doctor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Interface Order_Health_Doctor_Notification_Channel
 *
 * A notification channel is a "dumb sender": the dispatcher decides WHICH issues
 * to send (severity/type filtering + per-channel cooldown); the channel only
 * knows HOW to format and deliver a message. The free plugin ships Email and
 * Telegram; third-party code can register another sender by filtering
 * "order_health_doctor_notification_channels".
 */
interface Order_Health_Doctor_Notification_Channel {

	/**
	 * Stable channel id (e.g. "email", "telegram"). Used for cooldown keys.
	 *
	 * @return string
	 */
	public function get_id();

	/**
	 * Human-readable label for the settings UI.
	 *
	 * @return string
	 */
	public function get_label();

	/**
	 * Whether the admin has switched this channel on.
	 *
	 * @return bool
	 */
	public function is_enabled();

	/**
	 * Whether the channel is fully configured (has recipients / token, etc.).
	 *
	 * @return bool
	 */
	public function is_configured();

	/**
	 * Send an immediate alert for a single issue.
	 *
	 * @param object $issue Issue row from the repository.
	 * @return bool True if delivery was accepted.
	 */
	public function send_issue( $issue );

	/**
	 * Send the daily health summary.
	 *
	 * @param array $data Prepared summary data (counts, health, top issues, etc.).
	 * @return bool True if delivery was accepted.
	 */
	public function send_summary( $data );

	/**
	 * Send a test message so the admin can confirm the channel works.
	 *
	 * @return bool True if delivery was accepted.
	 */
	public function send_test();
}
