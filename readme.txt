=== Woo Order Doctor ===
Contributors: wooorderdoctor
Tags: woocommerce, orders, monitoring, health, diagnostics
Requires at least: 5.8
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Detects hidden WooCommerce order problems before customers complain: stuck orders, failed order spikes, duplicates, negative stock, and email config risks.

== Description ==

Woo Order Doctor is a lightweight WooCommerce order health monitoring plugin. It quietly watches your store and surfaces order problems that are easy to miss until a customer emails you about them.

The free version detects:

* **Paid but pending** — orders that look paid but are still pending or on hold.
* **Processing too long** — orders stuck in processing past your threshold.
* **On hold too long** — orders left on hold past your threshold.
* **Failed order spike** — an unusual jump in failed orders in the last 24 hours, compared to your recent average.
* **Duplicate orders** — likely duplicate orders from the same customer.
* **Negative stock** — stock-managed products that went below zero.
* **Email settings warning** — important WooCommerce transactional emails that appear disabled or missing an admin recipient.

It is HPOS (High-Performance Order Storage) compatible and uses only official WooCommerce data methods. No external APIs, no cloud sync, no account required.

== Features ==

* Order Health Score dashboard with Bootstrap cards.
* Issues list with status, severity and type filters plus search by order/product ID.
* One-click manual scan, plus an automatic daily scan.
* Per-issue actions: Mark Reviewed, Resolve, Ignore.
* Order edit screen meta box showing issues for that order.
* All actions protected by nonces and capability checks.

== Installation ==

1. Upload the `woo-order-doctor` folder to `/wp-content/plugins/`, or install the zip via Plugins > Add New > Upload.
2. Activate the plugin through the Plugins screen in WordPress.
3. Make sure WooCommerce is installed and active.
4. Go to **Order Doctor > Dashboard** and click **Run Scan Now**, or wait for the daily scan.

== Frequently Asked Questions ==

= Does this plugin send any data to an external service? =
No. All detection runs locally on your site. There are no external APIs or cloud services.

= Is it compatible with High-Performance Order Storage (HPOS)? =
Yes. The plugin declares HPOS compatibility and reads orders only through official WooCommerce methods.

= Can it guarantee my order emails are delivered? =
No. The free version checks WooCommerce email *configuration* (whether key emails are enabled and the New Order email has a recipient). It does not verify inbox delivery.

= Will it change my orders automatically? =
No. The plugin never changes order status automatically. You review each issue and act manually.

= How do I remove all data when uninstalling? =
Enable "Delete all plugin data on uninstall" on the Settings page before deleting the plugin.

== Changelog ==

= 1.1.0 =
* Added internal email notifications (admin/manager/custom recipients only — never customers).
* Immediate alerts for newly detected/reopened issues, filtered by severity and issue type, with a 24-hour per-issue cooldown.
* Daily order health summary email after the scheduled scan (skipped when there are no open issues).
* New Email Notifications settings section with a "Send Test Email" button.
* Added notification tracking columns to the issues table via a safe dbDelta upgrade.

= 1.0.0 =
* Initial release.
* Seven free detection rules: paid but pending, processing too long, on hold too long, failed order spike, duplicate orders, negative stock, email settings warning.
* Dashboard health score, issues list with filters, settings page, order meta box.
* HPOS compatible. Manual and daily scheduled scans.

== Upgrade Notice ==

= 1.0.0 =
Initial release.
