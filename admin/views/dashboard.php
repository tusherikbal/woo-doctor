<?php
/**
 * Dashboard view.
 *
 * Expects the following variables provided by Woo_Order_Doctor_Admin::render_dashboard_page():
 *
 * @var array  $counts        Open issue counts by severity.
 * @var int    $health        Health score 0-100.
 * @var string $health_label  Health score label.
 * @var array  $recent_issues Recent open issue rows.
 * @var string $last_scan     Last scan datetime (mysql) or empty.
 *
 * @package Woo_Order_Doctor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Read a one-off notice from the query string (set after the scan redirect).
$notice = isset( $_GET['wod_notice'] ) ? sanitize_key( wp_unslash( $_GET['wod_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification

// Pick a progress-bar colour band based on the score.
$score_class = 'bg-danger';
if ( $health >= 90 ) {
	$score_class = 'bg-success';
} elseif ( $health >= 75 ) {
	$score_class = 'bg-primary';
} elseif ( $health >= 50 ) {
	$score_class = 'bg-warning';
}
?>
<div class="wrap wod-wrap">
	<div class="container-fluid px-0">

		<h1 class="h3 mb-3"><?php esc_html_e( 'Woo Order Doctor', 'woo-order-doctor' ); ?></h1>

		<?php if ( 'scan_done' === $notice ) : ?>
			<div class="alert alert-success" role="alert">
				<?php
				$detected = isset( $_GET['wod_detected'] ) ? absint( $_GET['wod_detected'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
				printf(
					/* translators: %d: number of issues detected */
					esc_html__( 'Scan complete. %d issue(s) detected or updated.', 'woo-order-doctor' ),
					$detected
				);
				?>
			</div>
		<?php elseif ( 'scan_skipped' === $notice ) : ?>
			<div class="alert alert-warning" role="alert">
				<?php esc_html_e( 'Scan was skipped. Make sure monitoring is enabled and WooCommerce is active.', 'woo-order-doctor' ); ?>
			</div>
		<?php endif; ?>

		<div class="row g-3 mb-3">

			<!-- Health score card -->
			<div class="col-md-4">
				<div class="card h-100">
					<div class="card-body">
						<h2 class="h6 text-muted"><?php esc_html_e( 'Order Health Score', 'woo-order-doctor' ); ?></h2>
						<div class="display-4 fw-bold"><?php echo esc_html( $health ); ?><span class="fs-5 text-muted">/100</span></div>
						<div class="progress my-2" style="height:10px;">
							<div class="progress-bar <?php echo esc_attr( $score_class ); ?>" role="progressbar" style="width: <?php echo esc_attr( $health ); ?>%;" aria-valuenow="<?php echo esc_attr( $health ); ?>" aria-valuemin="0" aria-valuemax="100"></div>
						</div>
						<span class="badge <?php echo esc_attr( $score_class ); ?>"><?php echo esc_html( $health_label ); ?></span>
					</div>
				</div>
			</div>

			<!-- Summary counts card -->
			<div class="col-md-8">
				<div class="card h-100">
					<div class="card-body">
						<h2 class="h6 text-muted mb-3"><?php esc_html_e( 'Open Issues', 'woo-order-doctor' ); ?></h2>
						<div class="row text-center g-2">
							<div class="col">
								<div class="fs-3 fw-bold"><?php echo esc_html( $counts['total'] ); ?></div>
								<div class="text-muted small"><?php esc_html_e( 'Total', 'woo-order-doctor' ); ?></div>
							</div>
							<div class="col">
								<div class="fs-3 fw-bold text-danger"><?php echo esc_html( $counts['critical'] ); ?></div>
								<div class="text-muted small"><?php esc_html_e( 'Critical', 'woo-order-doctor' ); ?></div>
							</div>
							<div class="col">
								<div class="fs-3 fw-bold text-warning"><?php echo esc_html( $counts['high'] ); ?></div>
								<div class="text-muted small"><?php esc_html_e( 'High', 'woo-order-doctor' ); ?></div>
							</div>
							<div class="col">
								<div class="fs-3 fw-bold text-info"><?php echo esc_html( $counts['medium'] ); ?></div>
								<div class="text-muted small"><?php esc_html_e( 'Medium', 'woo-order-doctor' ); ?></div>
							</div>
							<div class="col">
								<div class="fs-3 fw-bold text-secondary"><?php echo esc_html( $counts['low'] ); ?></div>
								<div class="text-muted small"><?php esc_html_e( 'Low', 'woo-order-doctor' ); ?></div>
							</div>
						</div>
					</div>
				</div>
			</div>
		</div>

		<!-- Scan controls -->
		<div class="card mb-3">
			<div class="card-body d-flex flex-wrap justify-content-between align-items-center gap-2">
				<div>
					<strong><?php esc_html_e( 'Last scan:', 'woo-order-doctor' ); ?></strong>
					<?php
					if ( $last_scan ) {
						echo esc_html( mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $last_scan ) );
					} else {
						esc_html_e( 'Never', 'woo-order-doctor' );
					}
					?>
				</div>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="wod_run_scan" />
					<?php wp_nonce_field( 'wod_run_scan' ); ?>
					<button type="submit" class="btn btn-primary"><?php esc_html_e( 'Run Scan Now', 'woo-order-doctor' ); ?></button>
				</form>
			</div>
		</div>

		<!-- Recent issues -->
		<div class="card">
			<div class="card-header d-flex justify-content-between align-items-center">
				<span class="fw-semibold"><?php esc_html_e( 'Recent Issues', 'woo-order-doctor' ); ?></span>
				<a class="btn btn-sm btn-outline-secondary" href="<?php echo esc_url( admin_url( 'admin.php?page=woo-order-doctor-issues' ) ); ?>"><?php esc_html_e( 'View all issues', 'woo-order-doctor' ); ?></a>
			</div>
			<div class="card-body p-0">
				<?php if ( empty( $recent_issues ) ) : ?>
					<p class="p-3 mb-0 text-muted"><?php esc_html_e( 'No open issues. Your store looks healthy!', 'woo-order-doctor' ); ?></p>
				<?php else : ?>
					<table class="table table-hover mb-0 align-middle">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Severity', 'woo-order-doctor' ); ?></th>
								<th><?php esc_html_e( 'Issue', 'woo-order-doctor' ); ?></th>
								<th><?php esc_html_e( 'Detected', 'woo-order-doctor' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $recent_issues as $issue ) : ?>
								<tr>
									<td><span class="badge <?php echo esc_attr( Woo_Order_Doctor_Admin::severity_badge_class( $issue->severity ) ); ?>"><?php echo esc_html( ucfirst( $issue->severity ) ); ?></span></td>
									<td>
										<strong><?php echo esc_html( $issue->title ); ?></strong>
										<div class="text-muted small"><?php echo esc_html( $issue->message ); ?></div>
									</td>
									<td class="text-nowrap small"><?php echo esc_html( mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $issue->detected_at ) ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</div>
		</div>

	</div>
</div>
