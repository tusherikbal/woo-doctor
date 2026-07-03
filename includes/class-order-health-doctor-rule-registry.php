<?php
/**
 * Detection rule registry.
 *
 * @package Order_Health_Doctor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Order_Health_Doctor_Rule_Registry
 *
 * Owns the list of detection rules. The plugin registers its seven rules;
 * third-party code can add more by hooking the
 * "order_health_doctor_rules" filter. The registry is the single source of truth the
 * scanner and the settings UI both read, so new rules appear everywhere at once.
 */
class Order_Health_Doctor_Rule_Registry {

	/**
	 * Cached, keyed list of rule objects (id => rule).
	 *
	 * @var Order_Health_Doctor_Rule[]|null
	 */
	private $rules = null;

	/**
	 * Build (once) and return every registered rule, keyed by id.
	 *
	 * @return Order_Health_Doctor_Rule[]
	 */
	public function get_rules() {
		if ( null !== $this->rules ) {
			return $this->rules;
		}

		// The free plugin's default rule set.
		$defaults = array(
			new Order_Health_Doctor_Rule_Paid_But_Pending(),
			new Order_Health_Doctor_Rule_Processing_Too_Long(),
			new Order_Health_Doctor_Rule_On_Hold_Too_Long(),
			new Order_Health_Doctor_Rule_Failed_Order_Spike(),
			new Order_Health_Doctor_Rule_Duplicate_Order(),
			new Order_Health_Doctor_Rule_Stock_Mismatch(),
			new Order_Health_Doctor_Rule_Email_Settings(),
		);

		/**
		 * Filter the list of detection rules.
		 *
		 * Add a rule by appending an object that extends Order_Health_Doctor_Rule.
		 *
		 * @param Order_Health_Doctor_Rule[] $defaults Default rule objects.
		 */
		$rules = apply_filters( 'order_health_doctor_rules', $defaults );

		$this->rules = array();
		foreach ( (array) $rules as $rule ) {
			if ( $rule instanceof Order_Health_Doctor_Rule ) {
				$this->rules[ $rule->get_id() ] = $rule;
			}
		}

		return $this->rules;
	}

	/**
	 * Get a single rule by id, or null when it is not registered.
	 *
	 * @param string $rule_id Rule id.
	 * @return Order_Health_Doctor_Rule|null
	 */
	public function get_rule( $rule_id ) {
		$rules = $this->get_rules();
		return isset( $rules[ $rule_id ] ) ? $rules[ $rule_id ] : null;
	}

	/**
	 * Get the rules that should actually run.
	 *
	 * @return Order_Health_Doctor_Rule[]
	 */
	public function get_active_rules() {
		$active = array();

		foreach ( $this->get_rules() as $rule ) {
			$config = Order_Health_Doctor_Settings::get_rule_config( $rule );
			if ( 'yes' !== $config['enabled'] ) {
				continue;
			}

			$active[ $rule->get_id() ] = $rule;
		}

		return $active;
	}
}
