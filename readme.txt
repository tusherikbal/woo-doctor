=== Order Health Doctor ===
Contributors: tusherikbal
Tags: woocommerce, orders, monitoring, diagnostics, store health
Requires at least: 6.5
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Find stuck orders, failed-order spikes, likely duplicates, negative stock, and order-email configuration risks.

== Description ==

Order Health Doctor monitors WooCommerce order health and gives store managers a focused list of conditions that may need attention.

Detection rules include:

* Paid-looking orders still pending or on hold.
* Processing and on-hold orders older than configured thresholds.
* Failed-order spikes compared with the recent daily average.
* Likely duplicate orders from the same customer.
* Stock-managed products with negative stock.
* Important WooCommerce transactional email configuration warnings.

The plugin includes:

* A current order-health score and recent health trend.
* Filterable and paginated issues with bulk status actions.
* Manual and automatic daily scans.
* Reviewed, resolved, and ignored issue states.
* CSV export.
* An HPOS-compatible order meta box.
* Optional internal email and Telegram alerts.
* A WordPress dashboard summary widget.

Order Health Doctor never changes order statuses automatically. Order access uses WooCommerce CRUD APIs and supports High-Performance Order Storage (HPOS).

== External services ==

Order Health Doctor can optionally connect to the Telegram Bot API at `https://api.telegram.org` when a store administrator enables Telegram alerts and provides a bot token and chat ID.

For issue alerts, the plugin sends the site name, issue severity, issue title, issue description, suggested action, and an administration link. For summaries, it sends the site name, health score, and aggregate issue counts. It does not send customer billing names, addresses, email addresses, or payment details.

This service is disabled by default. Enabling and configuring Telegram constitutes the administrator's request to send this data to Telegram.

* [Telegram Privacy Policy](https://telegram.org/privacy)
* [Telegram Terms of Service](https://telegram.org/tos)
* [Telegram Bot API Terms](https://telegram.org/tos/bot-developers)

== Installation ==

1. Install and activate WooCommerce 8.2 or newer.
2. Install and activate Order Health Doctor.
3. Open **Order Health Doctor > Dashboard**.
4. Choose thresholds and notification preferences under **Settings**.
5. Run a manual scan or wait for the scheduled daily scan.

== Frequently Asked Questions ==

= Does the plugin modify orders? =

No. It reports possible problems but never changes order status, stock, payment, or customer data automatically.

= Is High-Performance Order Storage supported? =

Yes. HPOS compatibility is declared and order data is read through WooCommerce APIs.

= Are customers emailed? =

No. Email notifications are sent only to the internal recipients configured by an administrator.

= Does the plugin contact an external service? =

Only when Telegram alerts are explicitly enabled. See the External services section for the endpoint and data sent.

= What happens to data when the plugin is deleted? =

Data is retained by default. Enable **Delete all plugin data on uninstall** before deletion to remove the custom table, options, cooldown transients, user notice metadata, and scheduled event.

= Are scans bounded on very large stores? =

Yes. To protect request time and memory, individual order and product rules inspect up to 200 matching records per run; duplicate detection inspects up to 300 recent orders. Status-based rules use explicit oldest-first ordering inside the configured scan window.

== Changelog ==

= 1.0.0 =

* Initial WordPress.org release.
* Seven configurable WooCommerce health rules.
* Dashboard, health history, issues workflow, CSV export, and dashboard widget.
* Optional email and Telegram notifications.
* HPOS support and automatic resolved-issue retention.
