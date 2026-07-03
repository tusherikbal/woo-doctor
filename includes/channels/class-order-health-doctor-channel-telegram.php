<?php
/**
 * Telegram notification channel.
 *
 * @package Order_Health_Doctor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Order_Health_Doctor_Channel_Telegram
 *
 * Sends INTERNAL alerts to a Telegram chat via the free Telegram Bot API. There
 * is no cost and no third-party library. When explicitly enabled, issue and
 * site-health details are sent in a server-side HTTPS POST to api.telegram.org.
 *
 * Setup: create a bot with @BotFather to get a token, then put the bot in the
 * target chat/channel and use that chat id. The dispatcher handles which issues
 * to send and the per-channel cooldown; this class only formats and delivers.
 */
class Order_Health_Doctor_Channel_Telegram implements Order_Health_Doctor_Notification_Channel {

	const API_BASE = 'https://api.telegram.org/bot';

	/**
	 * {@inheritDoc}
	 */
	public function get_id() {
		return 'telegram';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_label() {
		return __( 'Telegram', 'order-health-doctor' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_enabled() {
		return Order_Health_Doctor_Settings::is_enabled( 'telegram_enabled' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_configured() {
		$token = trim( (string) Order_Health_Doctor_Settings::get( 'telegram_bot_token', '' ) );
		$chat  = trim( (string) Order_Health_Doctor_Settings::get( 'telegram_chat_id', '' ) );
		return ( '' !== $token && '' !== $chat );
	}

	// ---------------------------------------------------------------------
	// Channel API.
	// ---------------------------------------------------------------------

	/**
	 * Send an immediate issue message.
	 *
	 * @param object $issue Issue row.
	 * @return bool
	 */
	public function send_issue( $issue ) {
		$site_name = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );

		$lines   = array();
		$lines[] = '<b>' . $this->esc(
			sprintf(
			/* translators: %s: severity label */
				__( '%s order issue', 'order-health-doctor' ),
				ucfirst( $issue->severity )
			)
		) . '</b>';
		$lines[] = $this->esc( $site_name );
		$lines[] = '';
		$lines[] = '<b>' . $this->esc( $issue->title ) . '</b>';
		$lines[] = $this->esc( $issue->message );
		if ( ! empty( $issue->suggested_action ) ) {
			$lines[] = '';
			$lines[] = '<i>' . $this->esc( $issue->suggested_action ) . '</i>';
		}
		$lines[] = '';
		$lines[] = '<a href="' . esc_url( admin_url( 'admin.php?page=order-health-doctor-issues' ) ) . '">' . $this->esc( __( 'View issues', 'order-health-doctor' ) ) . '</a>';

		return $this->send_message( implode( "\n", $lines ) );
	}

	/**
	 * Send a daily summary message.
	 *
	 * @param array $data Prepared summary data.
	 * @return bool
	 */
	public function send_summary( $data ) {
		$counts    = isset( $data['counts'] ) ? $data['counts'] : array();
		$health    = isset( $data['health'] ) ? (int) $data['health'] : 0;
		$label     = isset( $data['health_label'] ) ? $data['health_label'] : '';
		$site_name = isset( $data['site_name'] ) ? $data['site_name'] : wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );

		$lines   = array();
		$lines[] = '<b>' . $this->esc( __( 'Daily Order Health Summary', 'order-health-doctor' ) ) . '</b>';
		$lines[] = $this->esc( $site_name );
		$lines[] = '';
		$lines[] = $this->esc(
			sprintf(
			/* translators: 1: score, 2: label */
				__( 'Health score: %1$d/100 (%2$s)', 'order-health-doctor' ),
				$health,
				$label
			)
		);
		$lines[] = $this->esc(
			sprintf(
			/* translators: 1: total, 2: critical, 3: high */
				__( 'Open: %1$d · Critical: %2$d · High: %3$d', 'order-health-doctor' ),
				isset( $counts['total'] ) ? (int) $counts['total'] : 0,
				isset( $counts['critical'] ) ? (int) $counts['critical'] : 0,
				isset( $counts['high'] ) ? (int) $counts['high'] : 0
			)
		);
		$lines[] = '';
		$lines[] = '<a href="' . esc_url( admin_url( 'admin.php?page=order-health-doctor' ) ) . '">' . $this->esc( __( 'Open dashboard', 'order-health-doctor' ) ) . '</a>';

		return $this->send_message( implode( "\n", $lines ) );
	}

	/**
	 * {@inheritDoc}
	 */
	public function send_test() {
		$site_name = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );

		$text  = '<b>' . $this->esc( __( 'Order Health Doctor test', 'order-health-doctor' ) ) . '</b>' . "\n";
		$text .= $this->esc(
			sprintf(
			/* translators: %s: site name */
				__( 'If you can read this, Telegram alerts for %s are set up correctly.', 'order-health-doctor' ),
				$site_name
			)
		);

		return $this->send_message( $text );
	}

	// ---------------------------------------------------------------------
	// Transport.
	// ---------------------------------------------------------------------

	/**
	 * POST a message to the Telegram Bot API.
	 *
	 * @param string $text HTML-formatted message text (already escaped).
	 * @return bool True when Telegram accepted the message (HTTP 200 + ok:true).
	 */
	public function send_message( $text ) {
		$token = trim( (string) Order_Health_Doctor_Settings::get( 'telegram_bot_token', '' ) );
		$chat  = trim( (string) Order_Health_Doctor_Settings::get( 'telegram_chat_id', '' ) );

		if ( '' === $token || '' === $chat ) {
			return false;
		}

		// Telegram caps messages at 4096 characters.
		if ( function_exists( 'mb_substr' ) ) {
			$text = mb_substr( $text, 0, 4096 );
		} else {
			$text = substr( $text, 0, 4096 );
		}

		$url = self::API_BASE . $token . '/sendMessage';

		$response = wp_remote_post(
			$url,
			array(
				'timeout' => 15,
				'body'    => array(
					'chat_id'                  => $chat,
					'text'                     => $text,
					'parse_mode'               => 'HTML',
					'disable_web_page_preview' => 'true',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return false;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			return false;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		return is_array( $body ) && ! empty( $body['ok'] );
	}

	/**
	 * Escape a string for Telegram's HTML parse mode (only &, <, > are special).
	 *
	 * @param string $text Raw text.
	 * @return string
	 */
	private function esc( $text ) {
		return str_replace(
			array( '&', '<', '>' ),
			array( '&amp;', '&lt;', '&gt;' ),
			(string) $text
		);
	}
}
