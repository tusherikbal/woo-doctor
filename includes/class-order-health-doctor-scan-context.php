<?php
/**
 * Scan context: shared state and helpers passed to every detection rule.
 *
 * @package Order_Health_Doctor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Order_Health_Doctor_Scan_Context
 *
 * A lightweight value object handed to each rule's run() method. It exposes the
 * resolved settings array plus the couple of shared helpers that used to live on
 * the monolithic scanner (scan window + failed-order counting), so individual
 * rule classes stay small and share the same, HPOS-safe order queries.
 */
class Order_Health_Doctor_Scan_Context {

	/**
	 * Resolved settings array for the duration of a scan.
	 *
	 * @var array
	 */
	private $settings;

	/**
	 * Constructor.
	 *
	 * @param array $settings Resolved settings (from Order_Health_Doctor_Settings::get_all()).
	 */
	public function __construct( array $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Read a single setting with an optional fallback.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Fallback when the key is missing.
	 * @return mixed
	 */
	public function get_setting( $key, $default = null ) {
		return isset( $this->settings[ $key ] ) ? $this->settings[ $key ] : $default;
	}

	/**
	 * The full settings array (handy for rules that read several keys).
	 *
	 * @return array
	 */
	public function get_settings() {
		return $this->settings;
	}

	/**
	 * Convert the configured scan_days setting into seconds.
	 *
	 * @return int Seconds in the scan window.
	 */
	public function scan_window_seconds() {
		$days = (int) $this->get_setting( 'scan_days', 30 );
		return $days * DAY_IN_SECONDS;
	}

	/**
	 * Count failed orders created between two unix timestamps.
	 *
	 * Uses WooCommerce pagination metadata so no unbounded ID list is loaded.
	 *
	 * @param int $start Start timestamp (inclusive).
	 * @param int $end   End timestamp (inclusive).
	 * @return int Number of failed orders.
	 */
	public function count_failed_orders_between( $start, $end ) {
		$result = wc_get_orders(
			array(
				'status'       => array( 'failed' ),
				'date_created' => absint( $start ) . '...' . absint( $end ),
				'limit'        => 1,
				'return'       => 'ids',
				'paginate'     => true,
			)
		);

		return is_object( $result ) && isset( $result->total ) ? absint( $result->total ) : 0;
	}
}
