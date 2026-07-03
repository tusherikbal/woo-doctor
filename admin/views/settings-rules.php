<?php
/**
 * Detection Rules settings partial.
 *
 * Rendered inside the main settings form (admin/views/settings.php), so the
 * fields post together under ohd_settings[rules][<id>][...]. Lets the admin
 * enable/disable each rule and choose the severity it should report.
 *
 * Expects:
 *
 * @var array                     $settings Current settings (merged with defaults).
 * @var Order_Health_Doctor_Rule[]   $rules    All registered rules.
 *
 * @package Order_Health_Doctor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$severity_choices = array(
	'critical' => __( 'Critical', 'order-health-doctor' ),
	'high'     => __( 'High', 'order-health-doctor' ),
	'medium'   => __( 'Medium', 'order-health-doctor' ),
	'low'      => __( 'Low', 'order-health-doctor' ),
	'info'     => __( 'Info', 'order-health-doctor' ),
);

?>
<div class="card mb-3">
	<div class="card-header fw-semibold d-flex justify-content-between align-items-center">
		<span><?php esc_html_e( 'Detection Rules', 'order-health-doctor' ); ?></span>
		<span class="text-muted small fw-normal"><?php esc_html_e( 'Turn rules on/off and set how serious each one is for your store.', 'order-health-doctor' ); ?></span>
	</div>
	<div class="card-body p-0">
		<table class="table table-striped align-middle mb-0">
			<thead>
				<tr>
					<th style="width:90px;"><?php esc_html_e( 'Enabled', 'order-health-doctor' ); ?></th>
					<th><?php esc_html_e( 'Rule', 'order-health-doctor' ); ?></th>
					<th style="width:170px;"><?php esc_html_e( 'Severity', 'order-health-doctor' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $rules as $rule ) : ?>
					<?php
					$rule_id  = $rule->get_id();
					$config   = Order_Health_Doctor_Settings::get_rule_config( $rule );
					$field    = 'ohd_settings[rules][' . esc_attr( $rule_id ) . ']';
					$input_id = 'ohd-rule-' . esc_attr( $rule_id );
					?>
					<tr>
						<td>
							<div class="form-check mb-0">
								<input
									class="form-check-input"
									type="checkbox"
									id="<?php echo esc_attr( $input_id ); ?>"
									name="<?php echo esc_attr( $field ); ?>[enabled]"
									value="yes"
									<?php checked( 'yes', $config['enabled'] ); ?>
									/>
							</div>
						</td>
						<td>
							<label class="fw-semibold mb-0" for="<?php echo esc_attr( $input_id ); ?>">
								<?php echo esc_html( $rule->get_label() ); ?>
							</label>
							<div class="text-muted small"><?php echo esc_html( $rule->get_description() ); ?></div>
						</td>
						<td>
							<select class="form-select form-select-sm" name="<?php echo esc_attr( $field ); ?>[severity]">
								<?php foreach ( $severity_choices as $slug => $label ) : ?>
									<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $config['severity'], $slug ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>
</div>
