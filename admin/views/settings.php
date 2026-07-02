<?php
/**
 * Settings view.
 *
 * Expects:
 *
 * @var array $settings Current settings (merged with defaults).
 *
 * @package Woo_Order_Doctor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$notice = isset( $_GET['wod_notice'] ) ? sanitize_key( wp_unslash( $_GET['wod_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification

/**
 * Tiny inline helper to render a number field row.
 *
 * @param string $key      Setting key.
 * @param string $label    Field label.
 * @param string $help     Help text.
 * @param array  $settings Current settings.
 * @param int    $min      Min value.
 * @param int    $max      Max value.
 */
$wod_number_field = function ( $key, $label, $help, $settings, $min, $max ) {
	?>
	<div class="col-md-6">
		<label class="form-label" for="wod-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label>
		<input type="number" class="form-control" id="wod-<?php echo esc_attr( $key ); ?>"
			name="wod_settings[<?php echo esc_attr( $key ); ?>]"
			value="<?php echo esc_attr( $settings[ $key ] ); ?>"
			min="<?php echo esc_attr( $min ); ?>" max="<?php echo esc_attr( $max ); ?>" />
		<div class="form-text"><?php echo esc_html( $help ); ?></div>
	</div>
	<?php
};
?>
<div class="wrap wod-wrap">
	<div class="container-fluid px-0">

		<h1 class="h3 mb-3"><?php esc_html_e( 'Settings', 'woo-order-doctor' ); ?></h1>

		<?php if ( 'settings_saved' === $notice ) : ?>
			<div class="alert alert-success" role="alert"><?php esc_html_e( 'Settings saved.', 'woo-order-doctor' ); ?></div>
		<?php elseif ( 'test_email_sent' === $notice ) : ?>
			<div class="alert alert-success" role="alert"><?php esc_html_e( 'Test email sent to the configured recipients.', 'woo-order-doctor' ); ?></div>
		<?php elseif ( 'test_email_failed' === $notice ) : ?>
			<div class="alert alert-danger" role="alert"><?php esc_html_e( 'The test email could not be sent. Check your WordPress email configuration.', 'woo-order-doctor' ); ?></div>
		<?php elseif ( 'test_email_no_recipients' === $notice ) : ?>
			<div class="alert alert-warning" role="alert"><?php esc_html_e( 'No valid recipients are configured. Add recipients and save before sending a test.', 'woo-order-doctor' ); ?></div>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="wod_save_settings" />
			<?php wp_nonce_field( 'wod_save_settings' ); ?>

			<!-- General toggles -->
			<div class="card mb-3">
				<div class="card-header fw-semibold"><?php esc_html_e( 'General', 'woo-order-doctor' ); ?></div>
				<div class="card-body">
					<div class="form-check form-switch mb-2">
						<input class="form-check-input" type="checkbox" id="wod-enable_monitoring" name="wod_settings[enable_monitoring]" value="yes" <?php checked( 'yes', $settings['enable_monitoring'] ); ?> />
						<label class="form-check-label" for="wod-enable_monitoring"><?php esc_html_e( 'Enable monitoring (manual and scheduled scans)', 'woo-order-doctor' ); ?></label>
					</div>
					<div class="form-check form-switch mb-2">
						<input class="form-check-input" type="checkbox" id="wod-daily_admin_notice" name="wod_settings[daily_admin_notice]" value="yes" <?php checked( 'yes', $settings['daily_admin_notice'] ); ?> />
						<label class="form-check-label" for="wod-daily_admin_notice"><?php esc_html_e( 'Show a daily admin notice when critical issues exist', 'woo-order-doctor' ); ?></label>
					</div>
					<div class="form-check form-switch">
						<input class="form-check-input" type="checkbox" id="wod-delete_data_on_uninstall" name="wod_settings[delete_data_on_uninstall]" value="yes" <?php checked( 'yes', $settings['delete_data_on_uninstall'] ); ?> />
						<label class="form-check-label" for="wod-delete_data_on_uninstall"><?php esc_html_e( 'Delete all plugin data on uninstall', 'woo-order-doctor' ); ?></label>
					</div>
				</div>
			</div>

			<!-- Detection thresholds -->
			<div class="card mb-3">
				<div class="card-header fw-semibold"><?php esc_html_e( 'Detection thresholds', 'woo-order-doctor' ); ?></div>
				<div class="card-body">
					<div class="row g-3">
						<?php
						$wod_number_field( 'scan_days', __( 'Scan order range (days)', 'woo-order-doctor' ), __( 'How far back to look for order issues.', 'woo-order-doctor' ), $settings, 1, 365 );
						$wod_number_field( 'paid_pending_minutes', __( 'Paid pending threshold (minutes)', 'woo-order-doctor' ), __( 'Flag paid-looking pending/on-hold orders older than this.', 'woo-order-doctor' ), $settings, 1, 10080 );
						$wod_number_field( 'processing_days', __( 'Processing too long (days)', 'woo-order-doctor' ), __( 'Flag processing orders older than this.', 'woo-order-doctor' ), $settings, 1, 365 );
						$wod_number_field( 'on_hold_days', __( 'On-hold too long (days)', 'woo-order-doctor' ), __( 'Flag on-hold orders older than this.', 'woo-order-doctor' ), $settings, 1, 365 );
						$wod_number_field( 'duplicate_window_minutes', __( 'Duplicate order window (minutes)', 'woo-order-doctor' ), __( 'Orders from the same customer with the same total within this window are flagged as possible duplicates.', 'woo-order-doctor' ), $settings, 1, 1440 );
						$wod_number_field( 'failed_order_threshold', __( 'Failed order threshold', 'woo-order-doctor' ), __( 'Minimum failed orders in 24h before a spike can be reported.', 'woo-order-doctor' ), $settings, 1, 10000 );
						$wod_number_field( 'failed_order_multiplier', __( 'Failed order average multiplier', 'woo-order-doctor' ), __( 'Today must exceed the recent daily average times this multiplier.', 'woo-order-doctor' ), $settings, 1, 100 );
						?>
					</div>
				</div>
			</div>

			<!-- Email Notifications -->
			<div class="card mb-3">
				<div class="card-header fw-semibold"><?php esc_html_e( 'Email Notifications', 'woo-order-doctor' ); ?></div>
				<div class="card-body">

					<div class="alert alert-info py-2 small" role="alert">
						<?php esc_html_e( 'Woo Order Doctor sends internal alerts only. It does not email customers.', 'woo-order-doctor' ); ?>
					</div>

					<!-- Toggles -->
					<div class="form-check form-switch mb-2">
						<input class="form-check-input" type="checkbox" id="wod-email_notifications_enabled" name="wod_settings[email_notifications_enabled]" value="yes" <?php checked( 'yes', $settings['email_notifications_enabled'] ); ?> />
						<label class="form-check-label" for="wod-email_notifications_enabled"><?php esc_html_e( 'Enable email notifications', 'woo-order-doctor' ); ?></label>
					</div>
					<div class="form-check form-switch mb-2">
						<input class="form-check-input" type="checkbox" id="wod-email_immediate_critical" name="wod_settings[email_immediate_critical]" value="yes" <?php checked( 'yes', $settings['email_immediate_critical'] ); ?> />
						<label class="form-check-label" for="wod-email_immediate_critical"><?php esc_html_e( 'Send immediate alerts when matching issues are detected', 'woo-order-doctor' ); ?></label>
					</div>
					<div class="form-check form-switch mb-3">
						<input class="form-check-input" type="checkbox" id="wod-email_daily_summary" name="wod_settings[email_daily_summary]" value="yes" <?php checked( 'yes', $settings['email_daily_summary'] ); ?> />
						<label class="form-check-label" for="wod-email_daily_summary"><?php esc_html_e( 'Send a daily health summary (only when open issues exist)', 'woo-order-doctor' ); ?></label>
					</div>

					<div class="row g-3">
						<!-- Recipient mode -->
						<div class="col-md-6">
							<label class="form-label" for="wod-email_recipient_mode"><?php esc_html_e( 'Recipient mode', 'woo-order-doctor' ); ?></label>
							<?php
							$recipient_mode_labels = array(
								'site_admin'            => __( 'Site admin email', 'woo-order-doctor' ),
								'custom_emails'         => __( 'Custom emails', 'woo-order-doctor' ),
								'selected_users'        => __( 'Selected WordPress users', 'woo-order-doctor' ),
								'site_admin_and_custom' => __( 'Site admin + custom emails', 'woo-order-doctor' ),
							);
							?>
							<select class="form-select" id="wod-email_recipient_mode" name="wod_settings[email_recipient_mode]">
								<?php foreach ( Woo_Order_Doctor_Settings::recipient_modes() as $mode_value ) : ?>
									<option value="<?php echo esc_attr( $mode_value ); ?>" <?php selected( $settings['email_recipient_mode'], $mode_value ); ?>>
										<?php echo esc_html( isset( $recipient_mode_labels[ $mode_value ] ) ? $recipient_mode_labels[ $mode_value ] : $mode_value ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</div>

						<!-- From name -->
						<div class="col-md-6">
							<label class="form-label" for="wod-email_from_name"><?php esc_html_e( 'Email from name', 'woo-order-doctor' ); ?></label>
							<input type="text" class="form-control" id="wod-email_from_name" name="wod_settings[email_from_name]" value="<?php echo esc_attr( $settings['email_from_name'] ); ?>" />
						</div>

						<!-- Custom recipients -->
						<div class="col-md-6">
							<label class="form-label" for="wod-email_custom_recipients"><?php esc_html_e( 'Custom recipient emails', 'woo-order-doctor' ); ?></label>
							<textarea class="form-control" id="wod-email_custom_recipients" name="wod_settings[email_custom_recipients]" rows="4" placeholder="admin@example.com&#10;manager@example.com"><?php echo esc_textarea( $settings['email_custom_recipients'] ); ?></textarea>
							<div class="form-text"><?php esc_html_e( 'Enter one email per line or comma separated. Invalid addresses are ignored.', 'woo-order-doctor' ); ?></div>
						</div>

						<!-- Selected users -->
						<div class="col-md-6">
							<label class="form-label" for="wod-email_selected_users"><?php esc_html_e( 'Selected WordPress users', 'woo-order-doctor' ); ?></label>
							<?php $selected_user_ids = array_map( 'absint', (array) $settings['email_selected_users'] ); ?>
							<select class="form-select" id="wod-email_selected_users" name="wod_settings[email_selected_users][]" multiple size="5">
								<?php foreach ( $eligible_users as $eligible_user ) : ?>
									<option value="<?php echo esc_attr( $eligible_user->ID ); ?>" <?php selected( in_array( (int) $eligible_user->ID, $selected_user_ids, true ) ); ?>>
										<?php echo esc_html( $eligible_user->display_name . ' (' . $eligible_user->user_email . ')' ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<div class="form-text"><?php esc_html_e( 'Only users who can manage WooCommerce or the site are listed. Hold Ctrl/Cmd to select multiple.', 'woo-order-doctor' ); ?></div>
						</div>

						<!-- Severities -->
						<div class="col-md-6">
							<label class="form-label d-block"><?php esc_html_e( 'Send alerts for severities', 'woo-order-doctor' ); ?></label>
							<?php
							$current_severities = (array) $settings['email_severities'];
							foreach ( Woo_Order_Doctor_Settings::email_severity_options() as $sev ) :
								?>
								<div class="form-check form-check-inline">
									<input class="form-check-input" type="checkbox" id="wod-sev-<?php echo esc_attr( $sev ); ?>" name="wod_settings[email_severities][]" value="<?php echo esc_attr( $sev ); ?>" <?php checked( in_array( $sev, $current_severities, true ) ); ?> />
									<label class="form-check-label" for="wod-sev-<?php echo esc_attr( $sev ); ?>"><?php echo esc_html( ucfirst( $sev ) ); ?></label>
								</div>
							<?php endforeach; ?>
						</div>

						<!-- Issue types -->
						<div class="col-md-6">
							<label class="form-label d-block"><?php esc_html_e( 'Send alerts for issue types', 'woo-order-doctor' ); ?></label>
							<?php
							$current_types = (array) $settings['email_issue_types'];
							foreach ( Woo_Order_Doctor_Settings::email_issue_type_options() as $type_slug ) :
								?>
								<div class="form-check">
									<input class="form-check-input" type="checkbox" id="wod-type-<?php echo esc_attr( $type_slug ); ?>" name="wod_settings[email_issue_types][]" value="<?php echo esc_attr( $type_slug ); ?>" <?php checked( in_array( $type_slug, $current_types, true ) ); ?> />
									<label class="form-check-label" for="wod-type-<?php echo esc_attr( $type_slug ); ?>"><?php echo esc_html( Woo_Order_Doctor_Admin::issue_type_label( $type_slug ) ); ?></label>
								</div>
							<?php endforeach; ?>
						</div>
					</div>
				</div>
			</div>

			<button type="submit" class="btn btn-primary"><?php esc_html_e( 'Save Settings', 'woo-order-doctor' ); ?></button>
		</form>

		<!-- Test email card (separate form: different admin-post action) -->
		<div class="card mt-3">
			<div class="card-header fw-semibold"><?php esc_html_e( 'Test &amp; Preview', 'woo-order-doctor' ); ?></div>
			<div class="card-body d-flex flex-wrap justify-content-between align-items-center gap-2">
				<div>
					<?php
					printf(
						/* translators: %d: number of configured recipients */
						esc_html__( 'Configured recipients: %d', 'woo-order-doctor' ),
						(int) $recipient_count
					);
					?>
					<div class="form-text mb-0"><?php esc_html_e( 'Save your settings first, then send a test to confirm delivery.', 'woo-order-doctor' ); ?></div>
				</div>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="woo_order_doctor_send_test_email" />
					<?php wp_nonce_field( 'wod_send_test_email' ); ?>
					<button type="submit" class="btn btn-outline-primary"><?php esc_html_e( 'Send Test Email', 'woo-order-doctor' ); ?></button>
				</form>
			</div>
		</div>

	</div>
</div>
