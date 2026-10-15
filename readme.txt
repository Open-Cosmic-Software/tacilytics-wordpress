=== Tacilytics – Privacy-Friendly Analytics ===
Contributors: opencosmicsoftware
Tags: analytics, statistics, privacy, cookieless, woocommerce
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Cookieless website statistics for WordPress and WooCommerce. Self-host Tacilytics for free or use Tacilytics Cloud.

== Description ==

Tacilytics counts visitors without cookies, without storing IP addresses and without building personal profiles. Unique visitors are counted with a daily rotating, salted one-way hash that cannot be turned back into a person.

This plugin connects your WordPress site to a Tacilytics server and adds the tracker for you.

**Two ways to use it**

* **Self-hosted (free):** run the open-source Tacilytics server ([GitHub](https://github.com/Open-Cosmic-Software/tacilytics), MIT licence) and enter its address. Your data stays on your server.
* **Tacilytics Cloud:** we host it for you in Germany. 90 days free, then €1.99 per site and month (up to 50,000 page views per month). *Opening soon.*

**What it counts**

* Page views, unique visitors, sources, UTM campaigns, countries, devices, browsers
* Clicks on links to other websites
* File downloads (PDF, ZIP, …)
* Site search: search terms and whether there were results
* Broken links (404 pages)
* WooCommerce purchases with revenue: order total, net total, currency and number of items. No order number, no customer data.

**Built for real WordPress sites**

* Works with page caches (WP Rocket, LiteSpeed Cache, W3 Total Cache, Cloudflare APO): your own visits are excluded in the browser, so a cached page never decides who gets counted.
* Visits of administrators, editors and shop managers are not counted (configurable per role).
* Optional: respect "Do Not Track" and Global Privacy Control.
* Dashboard widget with visitors, page views, revenue, top pages and sources of the last 7 days.
* HPOS compatible.
* English and German.

== External services ==

This plugin sends page views and events from your visitors' browsers to the Tacilytics server you choose, and the WordPress backend reads statistics from it. Nothing is sent before you finish the setup.

**Self-hosted:** data goes only to the server address you enter. You are the controller and the processor.

**Tacilytics Cloud** (`https://cloud.tacilytics.com`): only used if you choose it during setup.

* What is sent from the visitor's browser: page path, referrer, UTM parameters, browser language and time zone, time on page and scroll depth, and, if enabled, the events listed above. The server sees the IP address and user agent to compute the daily visitor hash, the country, browser and device type. The IP address is not stored.
* What is sent from WordPress: the API key you enter and the site domain, to read statistics for the dashboard widget.
* Terms of service: https://tacilytics.com/terms
* Privacy policy: https://tacilytics.com/privacy

== Installation ==

1. Install and activate the plugin.
2. Go to **Settings → Tacilytics** and choose **Self-hosted** or **Tacilytics Cloud**.
3. Self-hosted: add this site in your Tacilytics dashboard, then enter the dashboard address in WordPress.
4. Optional: create a read-only API key in Tacilytics (Settings → Security → API keys) and paste it to see your numbers on the WordPress dashboard.

== Frequently Asked Questions ==

= Do I need a cookie banner for Tacilytics? =

Tacilytics sets no cookies. For a normal visitor it writes nothing to the browser; it only checks localStorage for an opt-out flag. That flag is written when someone opens the site with `?tacilytics=off`, and for logged-in users whose role you excluded. Many privacy experts consider cookieless, anonymous counting exempt from consent requirements, but this depends on your country and setup (reading local storage can fall under rules like the ePrivacy Directive). This is not legal advice.

= My own visits are counted. =

Make sure your role is ticked under **Do not count** and visit the site once while logged in. To exclude a browser without logging in, open your site with `?tacilytics=off` (undo with `?tacilytics=on`).

= Does it slow my site down? =

The tracker is about 2 KB, loaded with `defer`, and sends one small request per page view.

= Can I change the tracker attributes? =

Use the `tacilytics_script_attributes` filter. To skip tracking on a request, return false from `tacilytics_should_track`.

= My self-hosted server is on a private network / plain http. =

The plugin refuses http addresses (except localhost) and blocks private network addresses for server-side requests. If you know what you are doing, allow them with the `tacilytics_allow_http_server` and `tacilytics_allow_private_server` filters.

== Screenshots ==

1. First run: choose self-hosted or Tacilytics Cloud.
2. Settings.
3. Dashboard widget.

== Changelog ==

= 1.0.0 =
* First release.
