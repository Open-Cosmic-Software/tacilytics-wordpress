# Tacilytics for WordPress

WordPress plugin for [Tacilytics](https://github.com/Open-Cosmic-Software/tacilytics), the cookieless, privacy-friendly analytics server.

- Adds the Tacilytics tracker to your site (no cookies, no stored IP addresses).
- Excludes logged-in users by role, also on cached pages.
- WooCommerce: one `Purchase` event per order, with value, currency and items, no customer data.
- Dashboard widget with today's numbers (read-only API key).

The plugin connects to **your own** Tacilytics server. A hosted version (Tacilytics Cloud) is planned and not available yet; the plugin does not contact it.

Requires WordPress 6.3+, PHP 7.4+. User documentation: [`readme.txt`](readme.txt).

## Development

- Release zip: everything except the paths in [`.distignore`](.distignore).
- Checks: [Plugin Check](https://wordpress.org/plugins/plugin-check/) must report no errors.
- End-to-end tests: [`tests/e2e`](tests/e2e/README.md).

## License

GPL-2.0-or-later, see [LICENSE](LICENSE). The Tacilytics server itself is MIT-licensed.
