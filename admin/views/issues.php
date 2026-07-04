<?php
/**
 * Issues list view.
 *
 * Expects the following variables from Order_Health_Doctor_Admin::render_issues_page():
 *
 * @var array $issues  Issue rows.
 * @var array $filters Current filter values (status, severity, issue_type, object_id).
 *
 * @package Order_Health_Doctor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$notice = isset( $_GET['ohd_notice'] ) ? sanitize_key( wp_unslash( $_GET['ohd_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification

// Option lists for the filter dropdowns.
$status_options   = array(
	''         => __( 'All statuses', 'order-health-doctor' ),
	'open'     => __( 'Open', 'order-health-doctor' ),
	'reviewed' => __( 'Reviewed', 'order-health-doctor' ),
	'resolved' => __( 'Resolved', 'order-health-doctor' ),
	'ignored'  => __( 'Ignored', 'order-health-doctor' ),
);
$severity_options = array(
	''         => __( 'All severities', 'order-health-doctor' ),
	'critical' => __( 'Critical', 'order-health-doctor' ),
	'high'     => __( 'High', 'order-health-doctor' ),
	'medium'   => __( 'Medium', 'order-health-doctor' ),
	'low'      => __( 'Low', 'order-health-doctor' ),
	'info'     => __( 'Info', 'order-health-doctor' ),
);
$type_options     = array( '' => __( 'All types', 'order-health-doctor' ) ) + Order_Health_Doctor_Admin::issue_type_labels();
?>
<div class="wrap ohd-wrap">
	<div class="container-fluid px-0">

		<h1 class="h3 mb-3"><?php esc_html_e( 'Issues', 'order-health-doctor' ); ?></h1>

		<?php if ( 'status_updated' === $notice ) : ?>
			<div class="alert alert-success" role="alert"><?php esc_html_e( 'Issue status updated.', 'order-health-doctor' ); ?></div>
		<?php elseif ( 'status_error' === $notice ) : ?>
			<div class="alert alert-danger" role="alert"><?php esc_html_e( 'Could not update the issue status.', 'order-health-doctor' ); ?></div>
		<?php endif; ?>

		<!-- Filters (GET form) -->
		<div class="card mb-3">
			<div class="card-body">
				<form method="get" class="ohd-filter">
					<input type="hidden" name="page" value="order-health-doctor-issues" />

					<div class="ohd-filter-field">
						<label class="form-label small"><?php esc_html_e( 'Status', 'order-health-doctor' ); ?></label>
						<select name="status" class="form-select form-select-sm">
							<?php foreach ( $status_options as $value => $label ) : ?>
								<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $filters['status'], $value ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
					</div>

					<div class="ohd-filter-field">
						<label class="form-label small"><?php esc_html_e( 'Severity', 'order-health-doctor' ); ?></label>
						<select name="severity" class="form-select form-select-sm">
							<?php foreach ( $severity_options as $value => $label ) : ?>
								<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $filters['severity'], $value ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
					</div>

					<div class="ohd-filter-field">
						<label class="form-label small"><?php esc_html_e( 'Issue type', 'order-health-doctor' ); ?></label>
						<select name="issue_type" class="form-select form-select-sm">
							<?php foreach ( $type_options as $value => $label ) : ?>
								<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $filters['issue_type'], $value ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
					</div>

					<div class="ohd-filter-field">
						<label class="form-label small text-nowrap"><?php esc_html_e( 'Order/Product ID', 'order-health-doctor' ); ?></label>
						<input type="number" min="0" name="object_id" value="<?php echo esc_attr( $filters['object_id'] ? $filters['object_id'] : '' ); ?>" class="form-control form-control-sm" />
					</div>

					<div class="ohd-filter-actions">
						<button type="submit" class="btn btn-sm btn-primary text-nowrap"><?php esc_html_e( 'Filter', 'order-health-doctor' ); ?></button>
						<a class="btn btn-sm btn-link text-nowrap" href="<?php echo esc_url( admin_url( 'admin.php?page=order-health-doctor-issues' ) ); ?>"><?php esc_html_e( 'Reset', 'order-health-doctor' ); ?></a>
					</div>
				</form>
			</div>
		</div>

		<?php
		// Build a nonce-protected export URL that carries the current filters.
		$export_args = array_filter(
			array(
				'action'     => 'ohd_export_csv',
				'status'     => $filters['status'],
				'severity'   => $filters['severity'],
				'issue_type' => $filters['issue_type'],
				'object_id'  => $filters['object_id'] ? $filters['object_id'] : '',
			),
			static function ( $v ) {
				return '' !== $v && null !== $v;
			}
		);
		$export_url  = wp_nonce_url( add_query_arg( $export_args, admin_url( 'admin-post.php' ) ), 'ohd_export_csv' );
		?>

		<!-- Toolbar: bulk actions + export -->
		<div class="card mb-3">
			<div class="card-body d-flex flex-wrap justify-content-between align-items-center gap-2">
				<div class="d-flex flex-wrap align-items-center gap-2 ohd-bulkbar">
					<select id="ohd-bulk-action" class="form-select form-select-sm" style="width:auto;">
						<option value=""><?php esc_html_e( 'Bulk actions', 'order-health-doctor' ); ?></option>
						<option value="reviewed"><?php esc_html_e( 'Mark Reviewed', 'order-health-doctor' ); ?></option>
						<option value="resolved"><?php esc_html_e( 'Resolve', 'order-health-doctor' ); ?></option>
						<option value="ignored"><?php esc_html_e( 'Ignore', 'order-health-doctor' ); ?></option>
					</select>
					<button type="button" id="ohd-bulk-apply" class="btn btn-sm btn-outline-primary"><?php esc_html_e( 'Apply', 'order-health-doctor' ); ?></button>
					<span class="text-muted small ohd-bulk-count"></span>
				</div>
				<a class="btn btn-sm btn-outline-secondary" href="<?php echo esc_url( $export_url ); ?>"><?php esc_html_e( 'Export CSV', 'order-health-doctor' ); ?></a>
			</div>
		</div>

		<!-- Issues table -->
		<div class="card">
			<div class="card-body p-0">
				<?php if ( empty( $issues ) ) : ?>
					<p class="p-3 mb-0 text-muted"><?php esc_html_e( 'No issues match your filters.', 'order-health-doctor' ); ?></p>
				<?php else : ?>
					<div class="table-responsive">
					<table class="table table-striped align-middle mb-0" id="ohd-issues-table">
						<thead>
							<tr>
								<th class="ohd-col-check"><input type="checkbox" id="ohd-check-all" class="form-check-input" aria-label="<?php esc_attr_e( 'Select all', 'order-health-doctor' ); ?>" /></th>
								<th class="ohd-col-sev"><?php esc_html_e( 'Severity', 'order-health-doctor' ); ?></th>
								<th class="ohd-col-issue"><?php esc_html_e( 'Issue', 'order-health-doctor' ); ?></th>
								<th class="ohd-col-obj"><?php esc_html_e( 'Related', 'order-health-doctor' ); ?></th>
								<th class="ohd-col-date"><?php esc_html_e( 'Detected', 'order-health-doctor' ); ?></th>
								<th class="ohd-col-status"><?php esc_html_e( 'Status', 'order-health-doctor' ); ?></th>
								<th class="ohd-col-actions"><?php esc_html_e( 'Actions', 'order-health-doctor' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $issues as $issue ) : ?>
								<tr data-issue-id="<?php echo esc_attr( $issue->id ); ?>">
									<td><input type="checkbox" class="form-check-input ohd-check" value="<?php echo esc_attr( $issue->id ); ?>" aria-label="<?php esc_attr_e( 'Select issue', 'order-health-doctor' ); ?>" /></td>
									<td><span class="badge <?php echo esc_attr( Order_Health_Doctor_Admin::severity_badge_class( $issue->severity ) ); ?>"><?php echo esc_html( ucfirst( $issue->severity ) ); ?></span></td>
									<td>
										<strong><?php echo esc_html( $issue->title ); ?></strong>
										<div class="text-muted small"><?php echo esc_html( $issue->message ); ?></div>
										<div class="text-muted small"><em><?php echo esc_html( Order_Health_Doctor_Admin::issue_type_label( $issue->issue_type ) ); ?></em></div>
									</td>
									<td class="small">
										<?php
										// Describe the related object in a readable way.
										if ( 'order' === $issue->object_type ) {
											/* translators: %d: order ID */
											printf( esc_html__( 'Order #%d', 'order-health-doctor' ), (int) $issue->object_id );
											if ( $issue->related_object_id ) {
												echo '<br />';
												/* translators: %d: duplicate order ID */
												printf( esc_html__( 'Duplicate #%d', 'order-health-doctor' ), (int) $issue->related_object_id );
											}
										} elseif ( 'product' === $issue->object_type ) {
											/* translators: %d: product ID */
											printf( esc_html__( 'Product #%d', 'order-health-doctor' ), (int) $issue->object_id );
										} elseif ( 'settings' === $issue->object_type ) {
											esc_html_e( 'Email settings', 'order-health-doctor' );
										} else {
											esc_html_e( 'System', 'order-health-doctor' );
										}
										?>
									</td>
									<td class="text-nowrap small"><?php echo esc_html( mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $issue->detected_at ) ); ?></td>
									<td><span class="badge bg-light text-dark border ohd-status-cell"><?php echo esc_html( ucfirst( $issue->status ) ); ?></span></td>
									<td><?php Order_Health_Doctor_Admin::render_issue_action_buttons( $issue, 'order-health-doctor-issues' ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
					</div>
				<?php endif; ?>
			</div>
		</div>

		<?php
		if ( $total_pages > 1 ) :
			?>
			<nav class="mt-3" aria-label="<?php esc_attr_e( 'Issues pagination', 'order-health-doctor' ); ?>">
				<?php
				echo wp_kses_post(
					paginate_links(
						array(
							'base'      => add_query_arg( 'paged', '%#%' ),
							'format'    => '',
							'current'   => $current_page,
							'total'     => $total_pages,
							'type'      => 'list',
							'prev_text' => __( '&laquo; Previous', 'order-health-doctor' ),
							'next_text' => __( 'Next &raquo;', 'order-health-doctor' ),
						)
					)
				);
				?>
			</nav>
		<?php endif; ?>

	</div>
	<!-- Toast container for dynamic feedback -->
	<div class="ohd-toasts" id="ohd-toasts" aria-live="polite" aria-atomic="true"></div>
</div>
