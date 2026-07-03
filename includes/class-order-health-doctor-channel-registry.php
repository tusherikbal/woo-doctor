<?php
/**
 * Notification channel registry.
 *
 * @package Order_Health_Doctor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Order_Health_Doctor_Channel_Registry
 *
 * Owns the list of notification channels. The plugin registers Email and
 * Telegram; third-party code can add another sender via the
 * "order_health_doctor_notification_channels" filter.
 */
class Order_Health_Doctor_Channel_Registry {

	/**
	 * Cached, keyed list of channel objects (id => channel).
	 *
	 * @var Order_Health_Doctor_Notification_Channel[]|null
	 */
	private $channels = null;

	/**
	 * Build (once) and return every registered channel, keyed by id.
	 *
	 * @return Order_Health_Doctor_Notification_Channel[]
	 */
	public function get_channels() {
		if ( null !== $this->channels ) {
			return $this->channels;
		}

		$defaults = array(
			new Order_Health_Doctor_Channel_Email(),
			new Order_Health_Doctor_Channel_Telegram(),
		);

		/**
		 * Filter the list of notification channels.
		 *
		 * Add a channel by appending an object implementing
		 * Order_Health_Doctor_Notification_Channel.
		 *
		 * @param Order_Health_Doctor_Notification_Channel[] $defaults Default channels.
		 */
		$channels = apply_filters( 'order_health_doctor_notification_channels', $defaults );

		$this->channels = array();
		foreach ( (array) $channels as $channel ) {
			if ( $channel instanceof Order_Health_Doctor_Notification_Channel ) {
				$this->channels[ $channel->get_id() ] = $channel;
			}
		}

		return $this->channels;
	}

	/**
	 * Get a single channel by id, or null when it is not registered.
	 *
	 * @param string $channel_id Channel id.
	 * @return Order_Health_Doctor_Notification_Channel|null
	 */
	public function get_channel( $channel_id ) {
		$channels = $this->get_channels();
		return isset( $channels[ $channel_id ] ) ? $channels[ $channel_id ] : null;
	}

	/**
	 * Get the channels that should actually send: enabled and configured.
	 *
	 * @return Order_Health_Doctor_Notification_Channel[]
	 */
	public function get_active_channels() {
		$active = array();

		foreach ( $this->get_channels() as $channel ) {
			if ( ! $channel->is_enabled() || ! $channel->is_configured() ) {
				continue;
			}
			$active[ $channel->get_id() ] = $channel;
		}

		return $active;
	}
}
