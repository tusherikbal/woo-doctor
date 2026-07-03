<?php
/**
 * Scanner: runs the registered detection rules.
 *
 * @package Order_Health_Doctor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Order_Health_Doctor_Scanner
 *
 * Thin runner over the rule registry. Both the manual "Run Scan Now" button and
 * the scheduled daily cron call run_scan(), so detection stays in a single place.
 * Detection logic itself now lives in the individual rule classes under
 * includes/rules/; this class only orchestrates them, applies the admin's
 * per-rule severity override, and persists the results.
 */
class Order_Health_Doctor_Scanner {

	/**
	 * Option used as an atomic cross-request scan lock.
	 */
	const LOCK_OPTION = 'ohd_scan_lock';

	/**
	 * Cached settings array for the duration of a scan.
	 *
	 * @var array
	 */
	private $settings;

	/**
	 * IDs of issues that were newly created or reopened during the current scan.
	 *
	 * @var int[]
	 */
	private $new_or_reopened = array();

	/**
	 * Run the full scan: execute every enabled rule in turn.
	 *
	 * Guard clauses make sure we never run when monitoring is disabled or when
	 * WooCommerce is unavailable.
	 *
	 * @return array Summary with whether it ran, count detected, and new/reopened IDs.
	 */
	public function run_scan() {
		if ( ! Order_Health_Doctor_Dependency::is_woocommerce_active() ) {
			return array(
				'ran'      => false,
				'reason'   => 'woocommerce_inactive',
				'detected' => 0,
			);
		}

		$this->settings = Order_Health_Doctor_Settings::get_all();

		if ( 'yes' !== $this->settings['enable_monitoring'] ) {
			return array(
				'ran'      => false,
				'reason'   => 'monitoring_disabled',
				'detected' => 0,
			);
		}

		if ( ! $this->acquire_lock() ) {
			return array(
				'ran'      => false,
				'reason'   => 'scan_locked',
				'detected' => 0,
			);
		}

		try {
			// Reset the per-scan collection of newly created/reopened issues.
			$this->new_or_reopened = array();

			$context  = new Order_Health_Doctor_Scan_Context( $this->settings );
			$registry = new Order_Health_Doctor_Rule_Registry();

			$detected = 0;
			foreach ( $registry->get_active_rules() as $rule ) {
				$config   = Order_Health_Doctor_Settings::get_rule_config( $rule );
				$severity = $config['severity'];

				// A single rule failing must never abort the whole scan.
				try {
					$found = $rule->run( $context );
				} catch ( \Throwable $e ) {
					$found = array();
				}

				foreach ( (array) $found as $issue_data ) {
					// The runner is the single place that stamps severity, so the
					// admin's per-rule override is always respected.
					$issue_data['severity'] = $severity;
					$this->record_issue( $issue_data );
					++$detected;
				}
			}

			// Record when we last scanned so the dashboard can show it.
			update_option( 'ohd_last_scan', current_time( 'mysql' ) );

			return array(
				'ran'             => true,
				'detected'        => $detected,
				'new_or_reopened' => $this->new_or_reopened,
			);
		} finally {
			delete_option( self::LOCK_OPTION );
		}
	}

	/**
	 * Acquire the scan lock, recovering a stale lock after fifteen minutes.
	 *
	 * @return bool
	 */
	private function acquire_lock() {
		$now      = time();
		$existing = (int) get_option( self::LOCK_OPTION, 0 );

		if ( $existing > 0 && ( $now - $existing ) > 15 * MINUTE_IN_SECONDS ) {
			delete_option( self::LOCK_OPTION );
		}

		return add_option( self::LOCK_OPTION, $now, '', false );
	}

	/**
	 * Persist an issue and record its ID when it is new or reopened.
	 *
	 * @param array $data Issue data (see Issue_Repository::create_or_update_issue).
	 * @return void
	 */
	private function record_issue( $data ) {
		$result = Order_Health_Doctor_Issue_Repository::create_or_update_issue( $data );

		if ( is_array( $result ) && ! empty( $result['id'] ) && ( ! empty( $result['is_new'] ) || ! empty( $result['is_reopened'] ) ) ) {
			$this->new_or_reopened[] = (int) $result['id'];
		}
	}
}
