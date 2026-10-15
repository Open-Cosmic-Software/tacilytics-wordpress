# End-to-end tests (manual, need Docker + Playwright)

They run a throw-away WordPress + MariaDB and a throw-away Tacilytics server in one Docker network,
then drive a real browser:

- `wp.js` – setup flow (choice, Cloud tile opens the Cloud setup with the sign-up link, bad URL rejected, connect, masked API key, connection test, dashboard widget, admin not counted)
- `front.js` – visitor page view, download, outbound, search with / without results, 404, no cookies, editor not counted, own `?tacilytics=off` respected (also across a role change), admin → subscriber counted again, attribute values with `$0` / `\1` kept literally (needs `mu/taci-test.php`)
- `woo.js` – WooCommerce checkout: one Purchase event with value / currency / items, no customer data, not counted twice (reload, other session, 10 parallel claims), blocked tracker → order stays unclaimed

Environment: `IP` (Tacilytics container IP), `WP` (WordPress host:port, default 127.0.0.1:8931), `SRV` (server URL as WordPress sees it), `TACI_DB` (path to the test server's SQLite DB), `KEY_FILE` (read-only `tk_` key), `PID` (WooCommerce product id), `OUT` (screenshots).

Run them against the distributed package, not the repository: `rsync -a --delete --exclude-from=.distignore ./ <plugin dir>/`.

Last run 15.10.2026 on WordPress 7.1.3 + WooCommerce 11.2.1 + PHP 8.3: 19/19, 14/14, 11/11. Plugin Check: no errors.
Mutation checks (each turns a test red): role marker overriding an existing opt-out; removing the `add_option()` claim lock (5 of 10 parallel claims win); `preg_replace` with the attribute string instead of the callback (`a<scriptbc`).
