<?php
/**
 * Dashboard view.
 *
 * Expects the following variables provided by Order_Health_Doctor_Admin::render_dashboard_page():
 *
 * @var array  $counts        Open issue counts by severity.
 * @var int    $health        Health score 0-100.
 * @var string $health_label  Health score label.
 * @var array  $recent_issues Recent open issue rows.
 * @var string $last_scan     Last scan datetime (mysql) or empty.
 * @var array  $sparkline     Health-score history points ({date,score,total}).
 *
 * @package Order_Health_Doctor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Read a one-off notice from the query string (kept for the no-JS POST fallback).
$notice = isset( $_GET['ohd_notice'] ) ? sanitize_key( wp_unslash( $_GET['ohd_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification

$band_class = Order_Health_Doctor_Admin::health_band_class( $health );
$band_hex   = Order_Health_Doctor_Admin::health_hex( $health );

// Gauge geometry (SVG ring).
$radius        = 52;
$circumference = 2 * M_PI * $radius;

// Build the sparkline point string (scores 0-100 across a 100x30 viewBox).
$spark_scores = array();
foreach ( (array) $sparkline as $point ) {
	$spark_scores[] = (int) $point['score'];
}
?>
<div class="wrap ohd-wrap">
	<div class="container-fluid px-0">

		<div class="d-flex justify-content-between align-items-center mb-3">
			<h1 class="h3 mb-0"><?php esc_html_e( 'Order Health Doctor', 'order-health-doctor' ); ?></h1>
			<span class="text-muted small">
				<?php esc_html_e( 'Last scan:', 'order-health-doctor' ); ?>
				<span id="ohd-last-scan"><?php echo $last_scan ? esc_html( mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $last_scan ) ) : esc_html__( 'Never', 'order-health-doctor' ); ?></span>
			</span>
		</div>

		<?php if ( 'scan_skipped' === $notice ) : ?>
			<div class="alert alert-warning" role="alert">
				<?php esc_html_e( 'Scan was skipped. Make sure monitoring is enabled and WooCommerce is active.', 'order-health-doctor' ); ?>
			</div>
		<?php endif; ?>

		<div class="row g-3 mb-3">

			<!-- Health score card with animated gauge -->
			<div class="col-md-4">
				<div class="card h-100">
					<div class="card-body text-center">
						<h2 class="h6 text-muted"><?php esc_html_e( 'Order Health Score', 'order-health-doctor' ); ?></h2>
						<div class="ohd-gauge" data-score="<?php echo esc_attr( $health ); ?>" data-circumference="<?php echo esc_attr( $circumference ); ?>">
							<svg viewBox="0 0 120 120" width="140" height="140">
								<circle class="ohd-gauge-track" cx="60" cy="60" r="<?php echo esc_attr( $radius ); ?>" />
								<circle class="ohd-gauge-fill" cx="60" cy="60" r="<?php echo esc_attr( $radius ); ?>"
									stroke="<?php echo esc_attr( $band_hex ); ?>"
									stroke-dasharray="<?php echo esc_attr( $circumference ); ?>"
									stroke-dashoffset="<?php echo esc_attr( $circumference ); ?>"
									transform="rotate(-90 60 60)" />
								<text class="ohd-gauge-num" x="60" y="66" text-anchor="middle">0</text>
							</svg>
						</div>
						<span class="badge <?php echo esc_attr( $band_class ); ?> ohd-health-badge"><?php echo esc_html( $health_label ); ?></span>
					</div>
				</div>
			</div>

			<!-- Summary counts card -->
			<div class="col-md-5">
				<div class="card h-100">
					<div class="card-body">
						<h2 class="h6 text-muted mb-3"><?php esc_html_e( 'Open Issues', 'order-health-doctor' ); ?></h2>
						<div class="row text-center g-2">
							<div class="col">
								<div class="fs-3 fw-bold ohd-count" data-count="total"><?php echo esc_html( $counts['total'] ); ?></div>
								<div class="text-muted small"><?php esc_html_e( 'Total', 'order-health-doctor' ); ?></div>
							</div>
							<div class="col">
								<div class="fs-3 fw-bold text-danger ohd-count" data-count="critical"><?php echo esc_html( $counts['critical'] ); ?></div>
								<div class="text-muted small"><?php esc_html_e( 'Critical', 'order-health-doctor' ); ?></div>
							</div>
							<div class="col">
								<div class="fs-3 fw-bold text-warning ohd-count" data-count="high"><?php echo esc_html( $counts['high'] ); ?></div>
								<div class="text-muted small"><?php esc_html_e( 'High', 'order-health-doctor' ); ?></div>
							</div>
							<div class="col">
								<div class="fs-3 fw-bold text-info ohd-count" data-count="medium"><?php echo esc_html( $counts['medium'] ); ?></div>
								<div class="text-muted small"><?php esc_html_e( 'Medium', 'order-health-doctor' ); ?></div>
							</div>
							<div class="col">
								<div class="fs-3 fw-bold text-secondary ohd-count" data-count="low"><?php echo esc_html( $counts['low'] ); ?></div>
								<div class="text-muted small"><?php esc_html_e( 'Low', 'order-health-doctor' ); ?></div>
							</div>
						</div>
					</div>
				</div>
			</div>

			<!-- Trend sparkline card -->
			<div class="col-md-3">
				<div class="card h-100">
					<div class="card-body">
						<h2 class="h6 text-muted"><?php esc_html_e( 'Health Trend', 'order-health-doctor' ); ?></h2>
						<?php if ( count( $spark_scores ) >= 2 ) : ?>
							<svg class="ohd-sparkline" viewBox="0 0 100 40" preserveAspectRatio="none" width="100%" height="60"
								data-scores="<?php echo esc_attr( wp_json_encode( $spark_scores ) ); ?>"
								data-color="<?php echo esc_attr( $band_hex ); ?>"></svg>
							<div class="text-muted small mt-1"><?php echo esc_html( sprintf( /* translators: %d: days */ _n( 'Last %d day', 'Last %d days', count( $spark_scores ), 'order-health-doctor' ), count( $spark_scores ) ) ); ?></div>
						<?php else : ?>
							<p class="text-muted small mb-0"><?php esc_html_e( 'The trend appears after a couple of daily scans.', 'order-health-doctor' ); ?></p>
						<?php endif; ?>
					</div>
				</div>
			</div>
		</div>

		<!-- Scan controls (AJAX, degrades to a normal POST) -->
		<div class="card mb-3">
			<div class="card-body d-flex flex-wrap justify-content-end align-items-center gap-2">
				<form id="ohd-scan-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="ohd_run_scan" />
					<?php wp_nonce_field( 'ohd_run_scan' ); ?>
					<button type="submit" id="ohd-scan-btn" class="btn btn-primary">
						<span class="ohd-scan-label"><?php esc_html_e( 'Run Scan Now', 'order-health-doctor' ); ?></span>
					</button>
				</form>
			</div>
		</div>

		<!-- Recent issues -->
		<div class="card">
			<div class="card-header d-flex justify-content-between align-items-center">
				<span class="fw-semibold"><?php esc_html_e( 'Recent Issues', 'order-health-doctor' ); ?></span>
				<a class="btn btn-sm btn-outline-secondary" href="<?php echo esc_url( admin_url( 'admin.php?page=order-health-doctor-issues' ) ); ?>"><?php esc_html_e( 'View all issues', 'order-health-doctor' ); ?></a>
			</div>
			<div class="card-body p-0">
				<table class="table table-hover mb-0 align-middle">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Severity', 'order-health-doctor' ); ?></th>
							<th><?php esc_html_e( 'Issue', 'order-health-doctor' ); ?></th>
							<th><?php esc_html_e( 'Detected', 'order-health-doctor' ); ?></th>
						</tr>
					</thead>
					<tbody id="ohd-recent-body">
						<?php
						// Shared renderer so the AJAX scan can swap identical markup.
						echo Order_Health_Doctor_Admin::render_recent_issue_rows( $recent_issues ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						?>
					</tbody>
				</table>
			</div>
		</div>

	</div>

	<!-- Toast container for dynamic feedback -->
	<div class="ohd-toasts" id="ohd-toasts" aria-live="polite" aria-atomic="true"></div>
</div>
