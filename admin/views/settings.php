<?php
/**
 * Settings view.
 *
 * Expects:
 *
 * @var array $settings Current settings (merged with defaults).
 *
 * @package Order_Health_Doctor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$notice        = isset( $_GET['ohd_notice'] ) ? sanitize_key( wp_unslash( $_GET['ohd_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
$requested_tab = isset( $_GET['ohd_tab'] ) ? sanitize_key( wp_unslash( $_GET['ohd_tab'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
$allowed_tabs  = array( 'general', 'detection', 'notifications' );
$active_tab    = in_array( $requested_tab, $allowed_tabs, true ) ? $requested_tab : 'general';
if ( 0 === strpos( $notice, 'test_email_' ) || 0 === strpos( $notice, 'telegram_' ) ) {
	$active_tab = 'notifications';
}
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
$ohd_number_field = function ( $key, $label, $help, $settings, $min, $max ) {
	?>
	<div class="col-md-6 col-xl-4">
		<label class="form-label" for="ohd-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label>
		<input type="number" class="form-control" id="ohd-<?php echo esc_attr( $key ); ?>"
			name="ohd_settings[<?php echo esc_attr( $key ); ?>]"
			value="<?php echo esc_attr( $settings[ $key ] ); ?>"
			min="<?php echo esc_attr( $min ); ?>" max="<?php echo esc_attr( $max ); ?>" />
		<div class="form-text"><?php echo esc_html( $help ); ?></div>
	</div>
	<?php
};
?>
<div class="wrap ohd-wrap">
	<div class="container-fluid px-0">

		<h1 class="h3 mb-3"><?php esc_html_e( 'Settings', 'order-health-doctor' ); ?></h1>

		<?php if ( 'settings_saved' === $notice ) : ?>
			<div class="alert alert-success" role="alert"><?php esc_html_e( 'Settings saved.', 'order-health-doctor' ); ?></div>
		<?php elseif ( 'test_email_sent' === $notice ) : ?>
			<div class="alert alert-success" role="alert"><?php esc_html_e( 'Test email sent to the configured recipients.', 'order-health-doctor' ); ?></div>
		<?php elseif ( 'test_email_failed' === $notice ) : ?>
			<div class="alert alert-danger" role="alert"><?php esc_html_e( 'The test email could not be sent. Check your WordPress email configuration.', 'order-health-doctor' ); ?></div>
		<?php elseif ( 'test_email_no_recipients' === $notice ) : ?>
			<div class="alert alert-warning" role="alert"><?php esc_html_e( 'No valid recipients are configured. Add recipients and save before sending a test.', 'order-health-doctor' ); ?></div>
			<?php elseif ( 'telegram_test_sent' === $notice ) : ?>
				<div class="alert alert-success" role="alert"><?php esc_html_e( 'Test message sent to your Telegram chat.', 'order-health-doctor' ); ?></div>
			<?php elseif ( 'telegram_test_failed' === $notice ) : ?>
				<div class="alert alert-danger" role="alert"><?php esc_html_e( 'The Telegram test failed. Double-check the bot token and chat ID.', 'order-health-doctor' ); ?></div>
			<?php elseif ( 'telegram_not_configured' === $notice ) : ?>
				<div class="alert alert-warning" role="alert"><?php esc_html_e( 'Add a Telegram bot token and chat ID, then save, before sending a test.', 'order-health-doctor' ); ?></div>
		<?php endif; ?>

		<!-- Standalone forms keep the notification test actions valid while their buttons sit inside the settings UI. -->
		<form id="ohd-test-email-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="order_health_doctor_send_test_email" />
			<?php wp_nonce_field( 'ohd_send_test_email' ); ?>
		</form>
		<form id="ohd-test-telegram-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="ohd_send_telegram_test" />
			<?php wp_nonce_field( 'ohd_send_telegram_test' ); ?>
		</form>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="ohd_save_settings" />
			<input type="hidden" id="ohd-active-settings-tab" name="ohd_active_tab" value="<?php echo esc_attr( $active_tab ); ?>" />
			<?php wp_nonce_field( 'ohd_save_settings' ); ?>

			<div class="ohd-settings-toolbar d-flex flex-wrap align-items-end gap-2">
				<nav class="ohd-settings-nav flex-grow-1" aria-label="<?php esc_attr_e( 'Settings sections', 'order-health-doctor' ); ?>">
					<div class="nav nav-tabs" id="ohd-settings-tabs" role="tablist">
						<button class="nav-link<?php echo 'general' === $active_tab ? ' active' : ''; ?>" id="ohd-general-tab" data-bs-toggle="tab" data-bs-target="#ohd-general-pane" type="button" role="tab" aria-controls="ohd-general-pane" aria-selected="<?php echo 'general' === $active_tab ? 'true' : 'false'; ?>"><?php esc_html_e( 'General', 'order-health-doctor' ); ?></button>
						<button class="nav-link<?php echo 'detection' === $active_tab ? ' active' : ''; ?>" id="ohd-detection-tab" data-bs-toggle="tab" data-bs-target="#ohd-detection-pane" type="button" role="tab" aria-controls="ohd-detection-pane" aria-selected="<?php echo 'detection' === $active_tab ? 'true' : 'false'; ?>"><?php esc_html_e( 'Detection', 'order-health-doctor' ); ?></button>
						<button class="nav-link<?php echo 'notifications' === $active_tab ? ' active' : ''; ?>" id="ohd-notifications-tab" data-bs-toggle="tab" data-bs-target="#ohd-notifications-pane" type="button" role="tab" aria-controls="ohd-notifications-pane" aria-selected="<?php echo 'notifications' === $active_tab ? 'true' : 'false'; ?>"><?php esc_html_e( 'Notifications', 'order-health-doctor' ); ?></button>
					</div>
				</nav>
				<button type="submit" class="btn btn-primary mb-1"><?php esc_html_e( 'Save Settings', 'order-health-doctor' ); ?></button>
			</div>

			<div class="tab-content ohd-settings-tabs pt-3" id="ohd-settings-tab-content">
				<div class="tab-pane fade<?php echo 'general' === $active_tab ? ' show active' : ''; ?>" id="ohd-general-pane" role="tabpanel" aria-labelledby="ohd-general-tab" tabindex="0">
					<div class="row g-3">
						<div class="col-lg-4">
			<!-- General toggles -->
			<div class="card h-100">
				<div class="card-header fw-semibold"><?php esc_html_e( 'General', 'order-health-doctor' ); ?></div>
				<div class="card-body">
					<div class="form-check mb-2">
						<input class="form-check-input" type="checkbox" id="ohd-enable_monitoring" name="ohd_settings[enable_monitoring]" value="yes" <?php checked( 'yes', $settings['enable_monitoring'] ); ?> />
						<label class="form-check-label" for="ohd-enable_monitoring"><?php esc_html_e( 'Enable monitoring (manual and scheduled scans)', 'order-health-doctor' ); ?></label>
					</div>
					<div class="form-check mb-2">
						<input class="form-check-input" type="checkbox" id="ohd-daily_admin_notice" name="ohd_settings[daily_admin_notice]" value="yes" <?php checked( 'yes', $settings['daily_admin_notice'] ); ?> />
						<label class="form-check-label" for="ohd-daily_admin_notice"><?php esc_html_e( 'Show a daily admin notice when critical issues exist', 'order-health-doctor' ); ?></label>
					</div>
					<div class="form-check mb-2">
						<input class="form-check-input" type="checkbox" id="ohd-reopen_resolved" name="ohd_settings[reopen_resolved]" value="yes" <?php checked( 'yes', $settings['reopen_resolved'] ); ?> />
						<label class="form-check-label" for="ohd-reopen_resolved"><?php esc_html_e( 'Reopen resolved issues if the problem is detected again', 'order-health-doctor' ); ?></label>
					</div>
					<div class="form-check">
						<input class="form-check-input" type="checkbox" id="ohd-delete_data_on_uninstall" name="ohd_settings[delete_data_on_uninstall]" value="yes" <?php checked( 'yes', $settings['delete_data_on_uninstall'] ); ?> />
						<label class="form-check-label" for="ohd-delete_data_on_uninstall"><?php esc_html_e( 'Delete all plugin data on uninstall', 'order-health-doctor' ); ?></label>
					</div>
				</div>
			</div>
						</div>

						<div class="col-lg-8">
			<!-- Detection thresholds -->
			<div class="card">
				<div class="card-header fw-semibold"><?php esc_html_e( 'Detection thresholds', 'order-health-doctor' ); ?></div>
				<div class="card-body">
					<div class="row g-3">
						<?php
						$ohd_number_field( 'scan_days', __( 'Scan order range (days)', 'order-health-doctor' ), __( 'How far back to look for order issues.', 'order-health-doctor' ), $settings, 1, 365 );
						$ohd_number_field( 'paid_pending_minutes', __( 'Paid pending threshold (minutes)', 'order-health-doctor' ), __( 'Flag paid-looking pending/on-hold orders older than this.', 'order-health-doctor' ), $settings, 1, 10080 );
						$ohd_number_field( 'processing_days', __( 'Processing too long (days)', 'order-health-doctor' ), __( 'Flag processing orders older than this.', 'order-health-doctor' ), $settings, 1, 365 );
						$ohd_number_field( 'on_hold_days', __( 'On-hold too long (days)', 'order-health-doctor' ), __( 'Flag on-hold orders older than this.', 'order-health-doctor' ), $settings, 1, 365 );
						$ohd_number_field( 'duplicate_window_minutes', __( 'Duplicate order window (minutes)', 'order-health-doctor' ), __( 'Orders from the same customer with the same total within this window are flagged as possible duplicates.', 'order-health-doctor' ), $settings, 1, 1440 );
						$ohd_number_field( 'failed_order_threshold', __( 'Failed order threshold', 'order-health-doctor' ), __( 'Minimum failed orders in 24h before a spike can be reported.', 'order-health-doctor' ), $settings, 1, 10000 );
						$ohd_number_field( 'failed_order_multiplier', __( 'Failed order average multiplier', 'order-health-doctor' ), __( 'Today must exceed the recent daily average times this multiplier.', 'order-health-doctor' ), $settings, 1, 100 );
						$ohd_number_field( 'resolved_retention_days', __( 'Resolved issue retention (days)', 'order-health-doctor' ), __( 'Automatically delete resolved issues older than this many days.', 'order-health-doctor' ), $settings, 1, 3650 );
						?>
					</div>
				</div>
			</div>
						</div>
					</div>
				</div>

			<!-- Detection Rules (enable + severity per rule) -->
				<div class="tab-pane fade<?php echo 'detection' === $active_tab ? ' show active' : ''; ?>" id="ohd-detection-pane" role="tabpanel" aria-labelledby="ohd-detection-tab" tabindex="0">
					<?php require ORDER_HEALTH_DOCTOR_PATH . 'admin/views/settings-rules.php'; ?>
				</div>

			<!-- Email Notifications -->
				<div class="tab-pane fade<?php echo 'notifications' === $active_tab ? ' show active' : ''; ?>" id="ohd-notifications-pane" role="tabpanel" aria-labelledby="ohd-notifications-tab" tabindex="0">
					<div class="row g-3">
						<div class="col-lg-8">
			<div class="card h-100">
				<div class="card-header fw-semibold"><?php esc_html_e( 'Email Notifications', 'order-health-doctor' ); ?></div>
				<div class="card-body">

					<div class="alert alert-info py-2 small" role="alert">
						<?php esc_html_e( 'Order Health Doctor sends internal alerts only. It does not email customers.', 'order-health-doctor' ); ?>
					</div>

					<!-- Toggles -->
					<div class="form-check mb-2">
						<input class="form-check-input" type="checkbox" id="ohd-email_notifications_enabled" name="ohd_settings[email_notifications_enabled]" value="yes" <?php checked( 'yes', $settings['email_notifications_enabled'] ); ?> />
						<label class="form-check-label" for="ohd-email_notifications_enabled"><?php esc_html_e( 'Enable email notifications', 'order-health-doctor' ); ?></label>
					</div>
					<div class="form-check mb-2">
						<input class="form-check-input" type="checkbox" id="ohd-email_immediate_critical" name="ohd_settings[email_immediate_critical]" value="yes" <?php checked( 'yes', $settings['email_immediate_critical'] ); ?> />
						<label class="form-check-label" for="ohd-email_immediate_critical"><?php esc_html_e( 'Send immediate alerts when matching issues are detected', 'order-health-doctor' ); ?></label>
					</div>
					<div class="form-check mb-3">
						<input class="form-check-input" type="checkbox" id="ohd-email_daily_summary" name="ohd_settings[email_daily_summary]" value="yes" <?php checked( 'yes', $settings['email_daily_summary'] ); ?> />
						<label class="form-check-label" for="ohd-email_daily_summary"><?php esc_html_e( 'Send a daily health summary (only when open issues exist)', 'order-health-doctor' ); ?></label>
					</div>

					<div class="row g-3">
						<!-- Recipient mode -->
						<div class="col-md-6">
							<label class="form-label" for="ohd-email_recipient_mode"><?php esc_html_e( 'Recipient mode', 'order-health-doctor' ); ?></label>
							<?php
							$recipient_mode_labels = array(
								'site_admin'            => __( 'Site admin email', 'order-health-doctor' ),
								'custom_emails'         => __( 'Custom emails', 'order-health-doctor' ),
								'selected_users'        => __( 'Selected WordPress users', 'order-health-doctor' ),
								'site_admin_and_custom' => __( 'Site admin + custom emails', 'order-health-doctor' ),
							);
							?>
							<select class="form-select" id="ohd-email_recipient_mode" name="ohd_settings[email_recipient_mode]">
								<?php foreach ( Order_Health_Doctor_Settings::recipient_modes() as $mode_value ) : ?>
									<option value="<?php echo esc_attr( $mode_value ); ?>" <?php selected( $settings['email_recipient_mode'], $mode_value ); ?>>
										<?php echo esc_html( isset( $recipient_mode_labels[ $mode_value ] ) ? $recipient_mode_labels[ $mode_value ] : $mode_value ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</div>

						<!-- From name -->
						<div class="col-md-6">
							<label class="form-label" for="ohd-email_from_name"><?php esc_html_e( 'Email from name', 'order-health-doctor' ); ?></label>
							<input type="text" class="form-control" id="ohd-email_from_name" name="ohd_settings[email_from_name]" value="<?php echo esc_attr( $settings['email_from_name'] ); ?>" />
						</div>

						<!-- Custom recipients -->
						<div class="col-md-6">
							<label class="form-label" for="ohd-email_custom_recipients"><?php esc_html_e( 'Custom recipient emails', 'order-health-doctor' ); ?></label>
							<textarea class="form-control" id="ohd-email_custom_recipients" name="ohd_settings[email_custom_recipients]" rows="4" placeholder="admin@example.test&#10;manager@example.test"><?php echo esc_textarea( $settings['email_custom_recipients'] ); ?></textarea>
							<div class="form-text"><?php esc_html_e( 'Enter one email per line or comma separated. Invalid addresses are ignored.', 'order-health-doctor' ); ?></div>
						</div>

						<!-- Selected users -->
						<div class="col-md-6">
							<label class="form-label" for="ohd-email_selected_users"><?php esc_html_e( 'Selected WordPress users', 'order-health-doctor' ); ?></label>
							<?php $selected_user_ids = array_map( 'absint', (array) $settings['email_selected_users'] ); ?>
							<select class="form-select" id="ohd-email_selected_users" name="ohd_settings[email_selected_users][]" multiple size="5">
								<?php foreach ( $eligible_users as $eligible_user ) : ?>
									<option value="<?php echo esc_attr( $eligible_user->ID ); ?>" <?php selected( in_array( (int) $eligible_user->ID, $selected_user_ids, true ) ); ?>>
										<?php echo esc_html( $eligible_user->display_name . ' (' . $eligible_user->user_email . ')' ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<div class="form-text"><?php esc_html_e( 'Only users who can manage WooCommerce or the site are listed. Hold Ctrl/Cmd to select multiple.', 'order-health-doctor' ); ?></div>
						</div>

						<!-- Severities -->
						<div class="col-md-6">
							<label class="form-label d-block"><?php esc_html_e( 'Send alerts for severities', 'order-health-doctor' ); ?></label>
							<?php
							$current_severities = (array) $settings['email_severities'];
							foreach ( Order_Health_Doctor_Settings::email_severity_options() as $sev ) :
								?>
								<div class="form-check form-check-inline">
									<input class="form-check-input" type="checkbox" id="ohd-sev-<?php echo esc_attr( $sev ); ?>" name="ohd_settings[email_severities][]" value="<?php echo esc_attr( $sev ); ?>" <?php checked( in_array( $sev, $current_severities, true ) ); ?> />
									<label class="form-check-label" for="ohd-sev-<?php echo esc_attr( $sev ); ?>"><?php echo esc_html( ucfirst( $sev ) ); ?></label>
								</div>
							<?php endforeach; ?>
						</div>

						<!-- Issue types -->
						<div class="col-md-6">
							<label class="form-label d-block"><?php esc_html_e( 'Send alerts for issue types', 'order-health-doctor' ); ?></label>
							<?php
							$current_types = (array) $settings['email_issue_types'];
							foreach ( Order_Health_Doctor_Settings::email_issue_type_options() as $type_slug ) :
								?>
								<div class="form-check">
									<input class="form-check-input" type="checkbox" id="ohd-type-<?php echo esc_attr( $type_slug ); ?>" name="ohd_settings[email_issue_types][]" value="<?php echo esc_attr( $type_slug ); ?>" <?php checked( in_array( $type_slug, $current_types, true ) ); ?> />
									<label class="form-check-label" for="ohd-type-<?php echo esc_attr( $type_slug ); ?>"><?php echo esc_html( Order_Health_Doctor_Admin::issue_type_label( $type_slug ) ); ?></label>
								</div>
							<?php endforeach; ?>
						</div>
					</div>
				</div>
					<div class="card-footer bg-white d-flex flex-wrap justify-content-between align-items-center gap-2">
						<div>
							<?php
							printf(
								/* translators: %d: number of configured recipients */
								esc_html__( 'Configured recipients: %d', 'order-health-doctor' ),
								(int) $recipient_count
							);
							?>
							<div class="form-text mb-0"><?php esc_html_e( 'Save your settings first, then send a test to confirm delivery.', 'order-health-doctor' ); ?></div>
						</div>
						<button type="submit" form="ohd-test-email-form" class="btn btn-outline-primary"><?php esc_html_e( 'Send Test Email', 'order-health-doctor' ); ?></button>
					</div>
			</div>
						</div>

			<!-- Telegram Alerts -->
						<div class="col-lg-4">
			<div class="card h-100">
				<div class="card-header fw-semibold"><?php esc_html_e( 'Telegram Alerts', 'order-health-doctor' ); ?></div>
				<div class="card-body">

					<div class="alert alert-info py-2 small" role="alert">
						<?php esc_html_e( 'Free internal alerts to a Telegram chat. Uses the same severity and issue-type filters as email above.', 'order-health-doctor' ); ?>
					</div>

					<div class="form-check mb-3">
						<input class="form-check-input" type="checkbox" id="ohd-telegram_enabled" name="ohd_settings[telegram_enabled]" value="yes" <?php checked( 'yes', $settings['telegram_enabled'] ); ?> />
						<label class="form-check-label" for="ohd-telegram_enabled"><?php esc_html_e( 'Enable Telegram alerts', 'order-health-doctor' ); ?></label>
					</div>

					<div class="row g-3">
						<div class="col-12">
							<label class="form-label" for="ohd-telegram_bot_token"><?php esc_html_e( 'Bot token', 'order-health-doctor' ); ?></label>
							<input type="password" class="form-control" id="ohd-telegram_bot_token" name="ohd_settings[telegram_bot_token]" value="<?php echo esc_attr( $settings['telegram_bot_token'] ); ?>" autocomplete="new-password" spellcheck="false" />
							<div class="form-text"><?php esc_html_e( 'Create a bot with @BotFather in Telegram and paste the token it gives you.', 'order-health-doctor' ); ?></div>
						</div>
						<div class="col-12">
							<label class="form-label" for="ohd-telegram_chat_id"><?php esc_html_e( 'Chat ID', 'order-health-doctor' ); ?></label>
							<input type="text" class="form-control" id="ohd-telegram_chat_id" name="ohd_settings[telegram_chat_id]" value="<?php echo esc_attr( $settings['telegram_chat_id'] ); ?>" autocomplete="off" />
							<div class="form-text"><?php esc_html_e( 'Your numeric chat ID, or @channelusername. Message @userinfobot to find your ID.', 'order-health-doctor' ); ?></div>
						</div>
					</div>
				</div>
					<div class="card-footer bg-white d-flex flex-wrap justify-content-between align-items-center gap-2">
						<div class="form-text mb-0"><?php esc_html_e( 'Save your bot token and chat ID first, then send a test message to confirm delivery.', 'order-health-doctor' ); ?></div>
						<button type="submit" form="ohd-test-telegram-form" class="btn btn-outline-primary"><?php esc_html_e( 'Send Test Message', 'order-health-doctor' ); ?></button>
					</div>
			</div>
						</div>
					</div>
				</div>
			</div>

			<div class="d-flex justify-content-end mt-4">
				<button type="submit" class="btn btn-primary"><?php esc_html_e( 'Save Settings', 'order-health-doctor' ); ?></button>
			</div>
		</form>

	</div>
</div>
