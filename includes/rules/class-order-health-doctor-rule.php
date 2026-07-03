<?php
/**
 * Base class for all detection rules.
 *
 * @package Order_Health_Doctor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Order_Health_Doctor_Rule
 *
 * Each detection rule is a small class that extends this base. Turning the old
 * hardcoded scanner methods into rule objects makes the rule set extensible via
 * the "order_health_doctor_rules" filter without editing the plugin.
 *
 * A rule's run() returns an array of "issue data" arrays (the same shape the
 * repository's create_or_update_issue() expects) but WITHOUT a severity — the
 * scanner injects the effective severity, which the admin can override per rule.
 */
abstract class Order_Health_Doctor_Rule {

	/**
	 * Unique, stable rule id (snake_case). Also used as the issue_type.
	 *
	 * @return string
	 */
	abstract public function get_id();

	/**
	 * Human-readable label for the settings UI.
	 *
	 * @return string
	 */
	abstract public function get_label();

	/**
	 * Short description shown under the label in the Rules settings table.
	 *
	 * @return string
	 */
	public function get_description() {
		return '';
	}

	/**
	 * Logical group used to cluster rules in the UI (e.g. "orders", "stock").
	 *
	 * @return string
	 */
	public function get_group() {
		return 'orders';
	}

	/**
	 * The severity a rule ships with before any admin override.
	 *
	 * @return string One of critical|high|medium|low|info.
	 */
	abstract public function get_default_severity();

	/**
	 * Whether the rule is enabled by default (before any admin override).
	 *
	 * @return bool
	 */
	public function is_enabled_by_default() {
		return true;
	}

	/**
	 * Execute the rule and return an array of issue-data arrays.
	 *
	 * Each returned array matches Order_Health_Doctor_Issue_Repository::create_or_update_issue()
	 * minus the "severity" key (the runner injects the effective severity).
	 *
	 * @param Order_Health_Doctor_Scan_Context $ctx Shared scan context.
	 * @return array[] List of issue-data arrays.
	 */
	abstract public function run( Order_Health_Doctor_Scan_Context $ctx );
}
