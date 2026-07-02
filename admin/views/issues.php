<?php
/**
 * Issues list view.
 *
 * Expects the following variables from Woo_Order_Doctor_Admin::render_issues_page():
 *
 * @var array $issues  Issue rows.
 * @var array $filters Current filter values (status, severity, issue_type, object_id).
 *
 * @package Woo_Order_Doctor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$notice = isset( $_GET['wod_notice'] ) ? sanitize_key( wp_unslash( $_GET['wod_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification

// Option lists for the filter dropdowns.
$status_options   = array(
	''         => __( 'All statuses', 'woo-order-doctor' ),
	'open'     => __( 'Open', 'woo-order-doctor' ),
	'reviewed' => __( 'Reviewed', 'woo-order-doctor' ),
	'resolved' => __( 'Resolved', 'woo-order-doctor' ),
	'ignored'  => __( 'Ignored', 'woo-order-doctor' ),
);
$severity_options = array(
	''         => __( 'All severities', 'woo-order-doctor' ),
	'critical' => __( 'Critical', 'woo-order-doctor' ),
	'high'     => __( 'High', 'woo-order-doctor' ),
	'medium'   => __( 'Medium', 'woo-order-doctor' ),
	'low'      => __( 'Low', 'woo-order-doctor' ),
	'info'     => __( 'Info', 'woo-order-doctor' ),
);
$type_options     = array( '' => __( 'All types', 'woo-order-doctor' ) ) + Woo_Order_Doctor_Admin::issue_type_labels();
?>
<div class="wrap wod-wrap">
	<div class="container-fluid px-0">

		<h1 class="h3 mb-3"><?php esc_html_e( 'Issues', 'woo-order-doctor' ); ?></h1>

		<?php if ( 'status_updated' === $notice ) : ?>
			<div class="alert alert-success" role="alert"><?php esc_html_e( 'Issue status updated.', 'woo-order-doctor' ); ?></div>
		<?php elseif ( 'status_error' === $notice ) : ?>
			<div class="alert alert-danger" role="alert"><?php esc_html_e( 'Could not update the issue status.', 'woo-order-doctor' ); ?></div>
		<?php endif; ?>

		<!-- Filters (GET form) -->
		<div class="card mb-3">
			<div class="card-body">
				<form method="get" class="row g-2 align-items-end">
					<input type="hidden" name="page" value="woo-order-doctor-issues" />

					<div class="col-md-3">
						<label class="form-label small"><?php esc_html_e( 'Status', 'woo-order-doctor' ); ?></label>
						<select name="status" class="form-select form-select-sm">
							<?php foreach ( $status_options as $value => $label ) : ?>
								<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $filters['status'], $value ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
					</div>

					<div class="col-md-3">
						<label class="form-label small"><?php esc_html_e( 'Severity', 'woo-order-doctor' ); ?></label>
						<select name="severity" class="form-select form-select-sm">
							<?php foreach ( $severity_options as $value => $label ) : ?>
								<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $filters['severity'], $value ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
					</div>

					<div class="col-md-3">
						<label class="form-label small"><?php esc_html_e( 'Issue type', 'woo-order-doctor' ); ?></label>
						<select name="issue_type" class="form-select form-select-sm">
							<?php foreach ( $type_options as $value => $label ) : ?>
								<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $filters['issue_type'], $value ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
					</div>

					<div class="col-md-2">
						<label class="form-label small"><?php esc_html_e( 'Order/Product ID', 'woo-order-doctor' ); ?></label>
						<input type="number" min="0" name="object_id" value="<?php echo esc_attr( $filters['object_id'] ? $filters['object_id'] : '' ); ?>" class="form-control form-control-sm" />
					</div>

					<div class="col-md-1">
						<button type="submit" class="btn btn-sm btn-primary w-100"><?php esc_html_e( 'Filter', 'woo-order-doctor' ); ?></button>
					</div>
				</form>
			</div>
		</div>

		<!-- Issues table -->
		<div class="card">
			<div class="card-body p-0">
				<?php if ( empty( $issues ) ) : ?>
					<p class="p-3 mb-0 text-muted"><?php esc_html_e( 'No issues match your filters.', 'woo-order-doctor' ); ?></p>
				<?php else : ?>
					<table class="table table-striped align-middle mb-0">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Severity', 'woo-order-doctor' ); ?></th>
								<th><?php esc_html_e( 'Issue', 'woo-order-doctor' ); ?></th>
								<th><?php esc_html_e( 'Related Object', 'woo-order-doctor' ); ?></th>
								<th><?php esc_html_e( 'Detected At', 'woo-order-doctor' ); ?></th>
								<th><?php esc_html_e( 'Status', 'woo-order-doctor' ); ?></th>
								<th><?php esc_html_e( 'Actions', 'woo-order-doctor' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $issues as $issue ) : ?>
								<tr>
									<td><span class="badge <?php echo esc_attr( Woo_Order_Doctor_Admin::severity_badge_class( $issue->severity ) ); ?>"><?php echo esc_html( ucfirst( $issue->severity ) ); ?></span></td>
									<td>
										<strong><?php echo esc_html( $issue->title ); ?></strong>
										<div class="text-muted small"><?php echo esc_html( $issue->message ); ?></div>
										<div class="text-muted small"><em><?php echo esc_html( Woo_Order_Doctor_Admin::issue_type_label( $issue->issue_type ) ); ?></em></div>
									</td>
									<td class="small">
										<?php
										// Describe the related object in a readable way.
										if ( 'order' === $issue->object_type ) {
											printf( esc_html__( 'Order #%d', 'woo-order-doctor' ), (int) $issue->object_id );
											if ( $issue->related_object_id ) {
												echo '<br />';
												printf( esc_html__( 'Duplicate #%d', 'woo-order-doctor' ), (int) $issue->related_object_id );
											}
										} elseif ( 'product' === $issue->object_type ) {
											printf( esc_html__( 'Product #%d', 'woo-order-doctor' ), (int) $issue->object_id );
										} elseif ( 'settings' === $issue->object_type ) {
											esc_html_e( 'Email settings', 'woo-order-doctor' );
										} else {
											esc_html_e( 'System', 'woo-order-doctor' );
										}
										?>
									</td>
									<td class="text-nowrap small"><?php echo esc_html( mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $issue->detected_at ) ); ?></td>
									<td><span class="badge bg-light text-dark border"><?php echo esc_html( ucfirst( $issue->status ) ); ?></span></td>
									<td><?php Woo_Order_Doctor_Admin::render_issue_action_buttons( $issue, 'woo-order-doctor-issues' ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</div>
		</div>

	</div>
</div>
