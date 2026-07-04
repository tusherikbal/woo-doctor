<?php
/**
 * Settings management.
 *
 * @package Order_Health_Doctor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Order_Health_Doctor_Settings
 *
 * Central place to read, default and sanitize the plugin's settings, which are
 * stored as a single option array under the "ohd_settings" key.
 */
class Order_Health_Doctor_Settings {

	/**
	 * Option name where all settings are stored.
	 */
	const OPTION_NAME = 'ohd_settings';

	/**
	 * Return the default settings array.
	 *
	 * Each value is also the canonical type we expect after sanitization.
	 *
	 * @return array Default settings.
	 */
	public static function get_defaults() {
		return array(
			'enable_monitoring'           => 'yes',
			'daily_admin_notice'          => 'yes',
			'scan_days'                   => 30,
			'paid_pending_minutes'        => 30,
			'processing_days'             => 5,
			'on_hold_days'                => 2,
			'duplicate_window_minutes'    => 30,
			'failed_order_threshold'      => 5,
			'failed_order_multiplier'     => 2,
			'delete_data_on_uninstall'    => 'no',

			// Housekeeping + reopen behaviour.
			'resolved_retention_days'     => 30,
			'reopen_resolved'             => 'yes',

			// Per-rule config: rules[<rule_id>] = array( enabled, severity ).
			// Left empty by default; each rule falls back to its own defaults via
			// get_rule_config(). Only admin overrides are stored here.
			'rules'                       => array(),

			// Email notification settings (internal alerts only — never customers).
			'email_notifications_enabled' => 'no',
			'email_immediate_critical'    => 'yes',
			'email_daily_summary'         => 'yes',
			'email_recipient_mode'        => 'site_admin',
			'email_custom_recipients'     => '',
			'email_selected_users'        => array(),
			'email_severities'            => array( 'critical', 'high' ),
			'email_issue_types'           => array(
				'paid_but_pending',
				'processing_too_long',
				'on_hold_too_long',
				'failed_order_spike',
				'duplicate_order',
				'stock_mismatch',
				'email_settings_warning',
			),
			'email_from_name'             => 'Order Health Doctor',

			// Telegram channel (internal alerts only — never customers). Uses the
			// shared email_severities / email_issue_types filters above.
			'telegram_enabled'            => 'no',
			'telegram_bot_token'          => '',
			'telegram_chat_id'            => '',
		);
	}

	/**
	 * Resolve the effective per-rule config (enabled + severity) for a rule.
	 *
	 * Reads any admin override stored under settings['rules'][<id>] and falls back
	 * to the rule's own defaults. This is the single helper the scanner and the
	 * Rules settings table both use, so a rule and its stored config never drift.
	 *
	 * @param Order_Health_Doctor_Rule $rule Rule object.
	 * @return array{enabled:string,severity:string}
	 */
	public static function get_rule_config( $rule ) {
		$all   = self::get_all();
		$rules = ( isset( $all['rules'] ) && is_array( $all['rules'] ) ) ? $all['rules'] : array();
		$id    = $rule->get_id();
		$cfg   = ( isset( $rules[ $id ] ) && is_array( $rules[ $id ] ) ) ? $rules[ $id ] : array();

		$default_enabled = $rule->is_enabled_by_default() ? 'yes' : 'no';
		$enabled         = isset( $cfg['enabled'] ) ? ( 'yes' === $cfg['enabled'] ? 'yes' : 'no' ) : $default_enabled;

		$severity = ( isset( $cfg['severity'] ) && in_array( $cfg['severity'], self::severity_slugs(), true ) )
			? $cfg['severity']
			: $rule->get_default_severity();

		return array(
			'enabled'  => $enabled,
			'severity' => $severity,
		);
	}

	/**
	 * The full list of severity slugs, most to least urgent.
	 *
	 * @return string[]
	 */
	public static function severity_slugs() {
		return array( 'critical', 'high', 'medium', 'low', 'info' );
	}

	/**
	 * Allowed values for the recipient mode select.
	 *
	 * @return string[]
	 */
	public static function recipient_modes() {
		return array( 'site_admin', 'custom_emails', 'selected_users', 'site_admin_and_custom' );
	}

	/**
	 * Allowed severity slugs for the email severity checkboxes.
	 *
	 * @return string[]
	 */
	public static function email_severity_options() {
		return array( 'critical', 'high', 'medium', 'low' );
	}

	/**
	 * Allowed issue-type slugs for the email issue-type checkboxes.
	 *
	 * @return string[]
	 */
	public static function email_issue_type_options() {
		return array(
			'paid_but_pending',
			'processing_too_long',
			'on_hold_too_long',
			'failed_order_spike',
			'duplicate_order',
			'stock_mismatch',
			'email_settings_warning',
		);
	}

	/**
	 * Get all settings merged with defaults.
	 *
	 * Merging with defaults protects against missing keys if the option was
	 * saved by an older version of the plugin.
	 *
	 * @return array Settings.
	 */
	public static function get_all() {
		$saved = get_option( self::OPTION_NAME, array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}
		return wp_parse_args( $saved, self::get_defaults() );
	}

	/**
	 * Get a single setting value.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Optional fallback if not found.
	 * @return mixed Setting value.
	 */
	public static function get( $key, $default = null ) {
		$settings = self::get_all();
		if ( isset( $settings[ $key ] ) ) {
			return $settings[ $key ];
		}
		return $default;
	}

	/**
	 * Convenience boolean check for "yes"/"no" style settings.
	 *
	 * @param string $key Setting key.
	 * @return bool True when the setting equals "yes".
	 */
	public static function is_enabled( $key ) {
		return 'yes' === self::get( $key );
	}

	/**
	 * Sanitize a raw settings array (typically from $_POST).
	 *
	 * Every value is validated to its expected type and clamped to a sensible
	 * range. Unknown keys are dropped by only reading expected keys.
	 *
	 * @param array $input Raw input.
	 * @return array Clean settings ready to be stored.
	 */
	public static function sanitize( $input ) {
		$input    = is_array( $input ) ? $input : array();
		$defaults = self::get_defaults();
		$clean    = array();

		// Checkbox-style "yes"/"no" fields.
		$clean['enable_monitoring']        = self::sanitize_yes_no( $input, 'enable_monitoring' );
		$clean['daily_admin_notice']       = self::sanitize_yes_no( $input, 'daily_admin_notice' );
		$clean['delete_data_on_uninstall'] = self::sanitize_yes_no( $input, 'delete_data_on_uninstall' );
		$clean['reopen_resolved']          = self::sanitize_yes_no( $input, 'reopen_resolved' );

		// Integer fields with min/max validation. Format: key => [min, max].
		$int_fields = array(
			'scan_days'                => array( 1, 365 ),
			'paid_pending_minutes'     => array( 1, 10080 ),
			'processing_days'          => array( 1, 365 ),
			'on_hold_days'             => array( 1, 365 ),
			'duplicate_window_minutes' => array( 1, 1440 ),
			'failed_order_threshold'   => array( 1, 10000 ),
			'failed_order_multiplier'  => array( 1, 100 ),
			'resolved_retention_days'  => array( 1, 3650 ),
		);

		foreach ( $int_fields as $key => $range ) {
			$value         = isset( $input[ $key ] ) ? (int) $input[ $key ] : $defaults[ $key ];
			$value         = max( $range[0], min( $range[1], $value ) );
			$clean[ $key ] = $value;
		}

		// --- Email notification fields ------------------------------------

		// Checkbox toggles.
		$clean['email_notifications_enabled'] = self::sanitize_yes_no( $input, 'email_notifications_enabled' );
		$clean['email_immediate_critical']    = self::sanitize_yes_no( $input, 'email_immediate_critical' );
		$clean['email_daily_summary']         = self::sanitize_yes_no( $input, 'email_daily_summary' );

		// Recipient mode: must be one of the known modes.
		$mode                          = isset( $input['email_recipient_mode'] ) ? sanitize_key( wp_unslash( $input['email_recipient_mode'] ) ) : 'site_admin';
		$clean['email_recipient_mode'] = in_array( $mode, self::recipient_modes(), true ) ? $mode : 'site_admin';

		// Custom recipients: validate each line/comma entry with is_email; keep
		// only valid addresses and store them one per line.
		$clean['email_custom_recipients'] = self::sanitize_custom_recipients(
			isset( $input['email_custom_recipients'] ) ? wp_unslash( $input['email_custom_recipients'] ) : ''
		);

		// Selected users: absint each ID and keep only existing users.
		$clean['email_selected_users'] = self::sanitize_selected_users(
			isset( $input['email_selected_users'] ) ? $input['email_selected_users'] : array()
		);

		// Severities: intersect submitted values with the allowed set.
		$clean['email_severities'] = self::sanitize_value_list(
			isset( $input['email_severities'] ) ? $input['email_severities'] : array(),
			self::email_severity_options()
		);

		// Issue types: intersect submitted values with the allowed set.
		$clean['email_issue_types'] = self::sanitize_value_list(
			isset( $input['email_issue_types'] ) ? $input['email_issue_types'] : array(),
			self::email_issue_type_options()
		);

		// From name: plain text, fall back to default if emptied.
		$from_name                = isset( $input['email_from_name'] ) ? sanitize_text_field( wp_unslash( $input['email_from_name'] ) ) : '';
		$clean['email_from_name'] = ( '' !== $from_name ) ? $from_name : 'Order Health Doctor';

		// --- Telegram channel ---------------------------------------------

		$clean['telegram_enabled'] = self::sanitize_yes_no( $input, 'telegram_enabled' );

		// Bot token: BotFather tokens look like "123456789:AAE...". Keep only the
		// safe character set; store as-is otherwise (validated on send).
		$token                       = isset( $input['telegram_bot_token'] ) ? trim( (string) wp_unslash( $input['telegram_bot_token'] ) ) : '';
		$clean['telegram_bot_token'] = preg_replace( '/[^A-Za-z0-9:_-]/', '', $token );

		// Chat id: numeric (may be negative for groups) or an @channelusername.
		$chat_id                   = isset( $input['telegram_chat_id'] ) ? trim( (string) wp_unslash( $input['telegram_chat_id'] ) ) : '';
		$clean['telegram_chat_id'] = preg_replace( '/[^A-Za-z0-9@_-]/', '', $chat_id );

		// --- Per-rule config (enabled + severity) -------------------------

		$clean['rules'] = self::sanitize_rules(
			isset( $input['rules'] ) ? $input['rules'] : array()
		);

		return $clean;
	}

	/**
	 * Sanitize the per-rule config array.
	 *
	 * Keeps only known severity slugs and yes/no enable flags, keyed by a
	 * sanitized rule id. Unknown ids are harmless (the scanner only reads config
	 * for rules that exist in the registry) but we still normalize the shape.
	 *
	 * @param mixed $raw Raw rules array from the form.
	 * @return array
	 */
	private static function sanitize_rules( $raw ) {
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$clean = array();
		foreach ( $raw as $rule_id => $cfg ) {
			$rule_id = sanitize_key( $rule_id );
			if ( '' === $rule_id || ! is_array( $cfg ) ) {
				continue;
			}

			$enabled  = ( isset( $cfg['enabled'] ) && 'yes' === $cfg['enabled'] ) ? 'yes' : 'no';
			$severity = isset( $cfg['severity'] ) ? sanitize_key( wp_unslash( $cfg['severity'] ) ) : '';
			if ( ! in_array( $severity, self::severity_slugs(), true ) ) {
				$severity = 'info';
			}

			$clean[ $rule_id ] = array(
				'enabled'  => $enabled,
				'severity' => $severity,
			);
		}

		return $clean;
	}

	/**
	 * Parse a textarea of emails (newline and/or comma separated) into a clean
	 * newline-separated string of valid, unique addresses.
	 *
	 * @param string $raw Raw textarea value.
	 * @return string Newline-separated valid emails.
	 */
	private static function sanitize_custom_recipients( $raw ) {
		$raw   = (string) $raw;
		$parts = preg_split( '/[\r\n,]+/', $raw );
		$valid = array();

		foreach ( (array) $parts as $part ) {
			$email = sanitize_email( trim( $part ) );
			if ( '' !== $email && is_email( $email ) ) {
				$valid[ strtolower( $email ) ] = $email; // De-dupe by lowercase key.
			}
		}

		return implode( "\n", array_values( $valid ) );
	}

	/**
	 * Sanitize selected user IDs: cast to int and keep only existing users.
	 *
	 * @param mixed $raw Array (or comma string) of user IDs.
	 * @return int[] Valid user IDs.
	 */
	private static function sanitize_selected_users( $raw ) {
		// Accept both an array (multi-select) and a comma-separated string.
		if ( ! is_array( $raw ) ) {
			$raw = preg_split( '/[\s,]+/', (string) wp_unslash( $raw ) );
		}

		$ids = array();
		foreach ( (array) $raw as $value ) {
			$id = absint( $value );
			if ( $id && get_userdata( $id ) ) {
				$ids[ $id ] = $id; // De-dupe.
			}
		}

		return array_values( $ids );
	}

	/**
	 * Intersect a submitted list of slugs with an allowed list.
	 *
	 * @param mixed    $raw     Submitted values (array expected).
	 * @param string[] $allowed Allowed values.
	 * @return string[] Clean, allowed values.
	 */
	private static function sanitize_value_list( $raw, $allowed ) {
		$out = array();
		foreach ( (array) $raw as $value ) {
			$value = sanitize_key( wp_unslash( $value ) );
			if ( in_array( $value, $allowed, true ) ) {
				$out[ $value ] = $value;
			}
		}
		return array_values( $out );
	}

	/**
	 * Helper to normalize a checkbox value to "yes" or "no".
	 *
	 * @param array  $input Raw input array.
	 * @param string $key   Field key.
	 * @return string "yes" when present/checked, otherwise "no".
	 */
	private static function sanitize_yes_no( $input, $key ) {
		if ( empty( $input[ $key ] ) ) {
			return 'no';
		}
		$value = sanitize_text_field( wp_unslash( $input[ $key ] ) );
		return ( 'yes' === $value || '1' === $value || 'on' === $value ) ? 'yes' : 'no';
	}
}
