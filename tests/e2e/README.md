# End-to-end tests (manual, need Docker + Playwright)

They run a throw-away WordPress + MariaDB and a throw-away Tacilytics server in one Docker network,
then drive a real browser:

- `wp.js` – setup flow (choice, cloud "coming soon", bad URL rejected, connect, masked API key, connection test, dashboard widget, admin not counted)
- `front.js` – visitor page view, download, outbound, search with / without results, 404, no cookies, editor not counted, own `?tacilytics=off` respected, admin → subscriber counted again
- `woo.js` – WooCommerce checkout: one Purchase event with value / currency / items, no customer data, not counted twice (reload, other session)

Environment: `IP` (Tacilytics container IP), `SRV` (server URL as WordPress sees it), `TACI_DB` (path to the test server's SQLite DB), `KEY_FILE` (read-only `tk_` key), `PID` (WooCommerce product id), `OUT` (screenshots).

Last run 15.10.2026 on WordPress 7.1.3 + WooCommerce 11.2.1 + PHP 8.3: 17/17, 12/12, 7/7. Plugin Check: no errors.
Mutation checks: removing the role marker or forcing `$skip = false` turns `editor: not counted` red.
