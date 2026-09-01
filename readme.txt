=== Tailwatch – Security, Backups, Monitoring & Management ===
Contributors: wptailwatch
Tags: security, backup, monitoring, ssl, audit-log
Requires at least: 6.3
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

WordPress security with backups, monitoring, SSL tracking, and file integrity checks, managed from a mobile app with real-time push alerts.

== Description ==

Tailwatch is a WordPress security and site management plugin that gives you real-time visibility into what is happening on your website.

It combines security, monitoring, and backups into one lightweight system, managed from your WordPress dashboard. Optionally connect a free Tailwatch account to receive event-based push notifications so you stay informed wherever you are.

= Core Features =

* Activity Logs (logins, failed logins, registrations, password resets)
* HTTP Error Logs (4xx / 5xx monitoring)
* Email / SMTP Logging with custom SMTP server support
* Security Hardening Audit
* File & Permission Protection
* Smart SSL Monitoring & Expiry Alerts
* File Integrity Monitoring (baseline + change detection)
* Full Site Backups (files and database)
* Search & Replace (safe database-wide updates with serialized data handling)
* Content Restrictions (browser-level protection controls like copy/inspect restrictions)
* Database Optimization Tools
* Broken Link Scanner
* Cron Job Manager
* 301 Redirect Manager
* User Management (create, edit, delete users and manage roles)
* Login Defender (IP-based brute-force protection: failed-login throttling, automatic temporary lockouts, configurable retention)
* Geo-blocking (allow/block individual IPs or IP ranges; whitelist trusted IPs to bypass restrictions)
* Username Hardening (audits for common high-risk usernames such as admin and administrator)
* Process Monitoring & Recovery (detect stuck long-running tasks and re-schedule them)
* Disk Space Monitoring
* Event-based Push Notifications

= Notifications =

Once connected to a free Tailwatch account, your site can send event-based push notifications for:

* Login activity (logins, failed logins, registrations, password resets)
* Backup completion and backup failures
* File integrity changes (added, modified, deleted files)
* SSL expiry alerts and certificate status changes
* Security Hardening Audit results (scan completed, issues detected)
* Security feature configuration changes
* Search & Replace completion results
* HTTP error spikes (4xx / 5xx monitoring)
* Database optimization results
* Broken link scan results
* Cron job failures
* Email delivery (success or failed)
* Login Defender events (IPs blocked after repeated failed logins, brute-force attempts detected)

Notifications are delivered to the Tailwatch mobile app using Firebase Cloud Messaging (FCM), routed through api.wptailwatch.com (the plugin does not contact Firebase directly).

= Tailwatch Pro =

Tailwatch Pro extends the free version with:

* Malware Guard (cloud malware scanning, suspicious file detection, cleanup, and push alerts for risk-detection events)
* Country blocking (allow or block visitors by country, applied to login forms or your entire site)
* Role-based Two-Factor Authentication (2FA)
* Advanced User Management (account-expiry rules, "login as another user", per-user 2FA status surfaced in the user list)
* Expanded notification credits
* Advanced scheduling controls for backups and maintenance tasks
* Priority support

== Installation ==

1. Upload the plugin to `/wp-content/plugins/tailwatch`
2. Activate it via the WordPress Plugins screen
3. Open Tailwatch from the admin menu
4. Optionally connect a free Tailwatch account for mobile notifications and dashboard access

== Frequently Asked Questions ==

= Is Tailwatch free? =
Yes. Core features run locally on your WordPress site with no paid subscription required.

= Do I need an account? =
No. An account is only required for optional features like push notifications and license/account sync.

= Will it slow down my site? =
No. Tailwatch uses lazy loading, caching, and scheduled background processing for performance efficiency.

= Does Tailwatch send data externally? =
Only when you enable optional features such as:
– Push notifications
– License verification
– Optional feedback submission

Core features remain fully local. See the "External Services" section below for the full breakdown of each transmission.

= Can I disable features? =
Yes. Every module in Tailwatch can be individually enabled or disabled.

= How does the one-click login link work? =
When you request a login link from an already-authenticated Tailwatch session (the mobile app or the connected dashboard), the plugin issues a single-use link that expires within one hour and signs in only the administrator who requested it. The link is stored hashed, so a copy of your database reveals no usable link, and it is invalidated the moment it is opened. Because it is a passwordless login you start yourself, opening the link signs you straight in — like a password-reset link, it is an alternative to typing your password rather than a second prompt.

= Does Tailwatch modify my site's .htaccess file? =
The optional Performance Optimizer feature can add a "Tailwatch Performance Settings" block to your site's root .htaccess — but only if you apply PHP configuration settings from that feature and your server runs Apache with mod_php (the plugin probes for support first). It uses WordPress core's insert_with_markers() function, which scopes the change to a single BEGIN/END marker block and preserves every other line in the file; the plugin never rewrites the whole file. After writing, it verifies your site still responds and automatically removes the block if anything fails. You can clear these settings at any time from the feature. The optional File & Directory Access hardening options (under Files & Permissions) can add a separate "Tailwatch File Access" block to your root .htaccess when you turn them on — for example to block direct web access to sensitive files such as wp-config.php, disable directory browsing, or stop PHP execution inside the uploads folder. That block uses the same WordPress core insert_with_markers() function (scoped to its own BEGIN/END markers, every other line preserved), is only written when the .htaccess file is writable, is verified with a loopback request, and is automatically removed if your site stops responding; turning the options off removes it. Separately, Tailwatch writes deny files (.htaccess, index.php, web.config) inside its own storage folders under uploads/tailwatch/ and wp-content/tailwatch/ to keep their contents unreachable over the web; it makes no other changes to your .htaccess.

= Does Tailwatch change PHP settings during scans? =
During a heavy operation you start — a malware scan, file-integrity check, hardening audit, or backup — Tailwatch may temporarily raise PHP limits (memory_limit, max_execution_time) for the duration of that request so the operation does not fail on constrained hosts. This is a runtime-only adjustment: nothing is written to any file, it never lowers a limit your host already sets higher, and it has no effect outside the running scan.

= What does the optional Network Logs feature record? =
The optional Network Logs feature (off by default) records lightweight metadata about internal WordPress traffic — REST API, admin-ajax, cron, and XML-RPC requests — so you can review recent activity, status codes, and slow responses. For REST requests it reads the already-parsed request through WordPress's own rest_post_dispatch hook; for the others it reads only the sanitized action and request parameters. It stores the endpoint/route, HTTP method, status code, response time, and the requesting user and IP. Before anything is saved, credential, token, and nonce values are redacted, cookies are never captured or stored, and every value is length-capped. Raw request bodies and response bodies are never stored, and none of this data is sent anywhere — it is kept in your own database and can be cleared at any time from the feature. The feature only records while you have it switched on.

== External Services ==

This plugin connects to several external services. Each service, the data sent, and the conditions under which the connection is made are listed below. Most connections only occur when the corresponding optional feature is enabled by the site administrator.

= Tailwatch API (api.wptailwatch.com) =
Used for license verification, push notification delivery, and optional deactivation feedback submission. License verification and push notification delivery are active only after you connect a free Tailwatch account from the plugin's License screen. The deactivation feedback submission is independent of any account connection and is sent only if you voluntarily submit it (see below).

What is sent and when:
- License verification: your anonymized Tailwatch user identifier and your site URL (query string), plus an `X-Tailwatch-Header-Key` request header containing the license credential issued when you connected your account. Sent on dashboard visits (throttled by a short server-side cache) and on demand when you click "Verify License". No system metadata is included in this request;
- Recovery access provisioning: when you connect your account, the plugin generates a standard WordPress recovery-mode cookie for your own site and sends it to the Tailwatch service so the dashboard can help you regain access if your site later becomes unreachable (for example, a fatal error that prevents wp-admin from loading). This value is a single site-scoped recovery credential — the same kind WordPress itself issues for its built-in recovery mode — that grants recovery-mode access to your site and nothing more. It is transmitted only over the authenticated connect handshake and only after you choose to connect your account;
- Mobile push notifications: anonymized user identifier, your site domain, and the notification type/severity tag (the plugin no longer sends a pre-written title or body; the Tailwatch service composes those from the event context below, and the request goes to a single relay endpoint authenticated with a per-site routing token request header). When mobile notifications are enabled, an additional event-context payload is also stored on api.wptailwatch.com so the mobile app can render notification details when you tap a push. This event-context payload contains: the event name and feature, the action and any state-change narrative (before/after) describing what occurred, the timestamp, the requesting admin's own forensic baseline (IP address and user agent at the time of the event), and per-event fields needed for the mobile app's detail view (for example, for Email / SMTP events the recipient address and subject line so the notification is actionable). It does NOT include message bodies, credentials, tokens, license keys, or any other secret. The event-context payload is held on api.wptailwatch.com only; it is NOT forwarded to Firebase Cloud Messaging (see below) — Firebase only ever receives the slim title/body/type payload.
- Deactivation feedback: site domain, deactivation reason, plugin version, your "keep data / delete data" choice from the deactivation modal, and optional free-form comments — sent only if you voluntarily click "Submit & Deactivate" (the "Skip & Deactivate" button avoids any transmission).

Service provider: WP Tailwatch
Privacy policy: https://wptailwatch.com/privacy-policy
Terms: https://wptailwatch.com/terms-of-services

= Tailwatch Dashboard (dashboard.wptailwatch.com) =
The central web dashboard you use to connect your site and manage your account. The browser opens dashboard.wptailwatch.com only when you click "Connect License".

What is sent: your site URL and environment type (staging or production) are passed via URL parameters when you initiate the connection flow. License information is retrieved and stored in your WordPress database after you log in to the dashboard.

Service provider: WP Tailwatch
Privacy policy: https://wptailwatch.com/privacy-policy
Terms: https://wptailwatch.com/terms-of-services

= Firebase Cloud Messaging (fcm.googleapis.com) =
Used to deliver mobile push notifications to your paired mobile devices. Only active when (a) you have connected a Tailwatch account, (b) you have enabled mobile notifications in plugin settings, and (c) you have toggled on the specific per-feature notification.

What is sent: an anonymized device token and the notification payload (title, body, type tag) — routed via api.wptailwatch.com to Firebase Cloud Messaging for delivery to your paired device.

Service provider: Google LLC
Privacy policy: https://firebase.google.com/support/privacy
Terms: https://firebase.google.com/terms

= Smart SSL Monitoring — host certificate inspection =
The Smart SSL feature opens a TLS connection (raw socket) to your own site's domain (derived from your WordPress Site Address / `home_url()`) to read its certificate metadata (issuer, validity dates, chain). It also issues an HTTP HEAD probe over plain HTTP to your own site to detect HTTP→HTTPS redirection behaviour. Only your own site is contacted — Tailwatch does not connect to any third-party service for this purpose. Disable the Smart SSL feature in plugin settings if you do not want these requests issued.

= Broken Link Checker — external URL scanning =
The Broken Links scanner contacts URLs that you (or your site's authors) have placed in your post content, pages, term descriptions, user meta, and options. The plugin issues GET requests to those URLs to determine whether they return 4xx or 5xx errors. The set of URLs contacted is therefore controlled entirely by your own site content — Tailwatch does not maintain a list of external services for this purpose. Disable the Broken Links feature in plugin settings if you do not want these requests issued.

= MaxMind GeoLite2 database download (download.maxmind.com) =
The optional GeoIP integration downloads the MaxMind GeoLite2-Country database so the plugin can resolve visitor IP addresses to a country (used to display the country of an event in the Login Defender logs, and — with the Pro add-on — for country-based access rules). This connection is made ONLY when you have entered your own MaxMind license key on the Integrations screen, and ONLY on an explicit action you take: when you save the key, and when you click "Check for updates". There is no automatic, background, or scheduled download.

What is sent: your MaxMind license key and the requested database edition ("GeoLite2-Country"), in a request to https://download.maxmind.com/app/geoip_download. The downloaded database file is stored inside your site's uploads directory. You must obtain your own (free) MaxMind account and license key; the GeoLite2 data is provided by MaxMind under the MaxMind GeoLite2 End User License Agreement. Remove the license key on the Integrations screen to delete the database and stop all such requests.

Service provider: MaxMind, Inc.
Privacy policy: https://www.maxmind.com/en/privacy-policy
GeoLite2 EULA: https://www.maxmind.com/en/geolite2/eula

= WordPress.org — plugin, theme, and core updates & rollback =
The optional Updates & Rollback feature contacts WordPress.org to manage updates for your plugins, themes, and WordPress core. When you open the Updates screen it queries api.wordpress.org for available-update information and version lists (the same API WordPress core itself uses); when you update or roll back an item it downloads the official package from downloads.wordpress.org and installs it through WordPress's own upgrade routines (WP_Upgrader / Core_Upgrader). Only official WordPress.org packages are ever downloaded — no third-party source is contacted. By default these requests are made only when you open the Updates screen or explicitly click Update or Rollback. If you turn on automatic updates for plugins, themes, or WordPress core (minor and security core releases only), WordPress's own built-in auto-updater additionally downloads and installs those updates on its regular schedule from the same WordPress.org sources — only for the update types you switch on, and only while the feature is enabled. Disable the Updates & Rollback feature (or the individual automatic-update toggles) in plugin settings if you do not want these requests issued.

Service provider: WordPress.org (The WordPress Foundation)
Privacy policy: https://wordpress.org/about/privacy/

== Third-Party Libraries ==

= Bundled PHP libraries =

Tailwatch bundles the following open-source PHP libraries. They run entirely on your server and are GPLv2-compatible.

* MaxMind GeoIP2 PHP API + MaxMind-DB Reader — reads a GeoLite2-Country database (which you either download on the Integrations screen using your own MaxMind license key, or upload manually to your site's uploads directory) to resolve visitor IPs to a country for the IP Management / Login Defender geolocation feature. Runs under the `Tailwatch\Vendor\MaxMind\*` namespace. Source: https://github.com/maxmind/GeoIP2-php and https://github.com/maxmind/MaxMind-DB-Reader-php — License: Apache-2.0 (full text in `Vendor/MaxMind/LICENSE`)
* Firebase JWT (firebase/php-jwt) — encodes and verifies the signed HS256 JSON Web Tokens that authenticate the Connect REST API (used by the mobile app and cloud dashboard). Runs under the `Tailwatch\Vendor\Firebase\JWT\*` namespace. Source: https://github.com/firebase/php-jwt — License: BSD-3-Clause (full text in `Vendor/Firebase/JWT/LICENSE`)

All server-side HTTP requests use WordPress core's built-in HTTP API (wp_remote_get / wp_remote_post / wp_remote_head). No external PHP HTTP client libraries (e.g. Guzzle) are bundled.

= Bundled JavaScript libraries =

The plugin's admin dashboard is a React single-page application. Its compiled bundle in `Admin/View/Static/js/` and `Admin/View/Static/css/` is built from the following open-source libraries, all of which are GPLv2-compatible:

* React + ReactDOM (UI framework) — https://react.dev — License: MIT
* Redux, Redux Toolkit, and React-Redux (state management) — https://redux.js.org — License: MIT
* React Router (react-router-dom) (client-side routing) — https://reactrouter.com — License: MIT
* React Hook Form (form state and validation) — https://react-hook-form.com — License: MIT
* Axios (HTTP client) — https://github.com/axios/axios — License: MIT
* SweetAlert2 (modal dialogs) — https://sweetalert2.github.io — License: MIT
* Recharts and react-d3-speedometer (charts and gauges) — https://recharts.org — License: MIT
* @tanstack/react-virtual (long-list virtualization) — https://tanstack.com/virtual — License: MIT
* react-loading-skeleton, react-top-loading-bar, and @ramonak/react-progress-bar (loading indicators) — License: MIT
* react-hot-toast and react-toastify (toast notifications) — License: MIT
* react-tooltip (tooltips) — https://react-tooltip.com — License: MIT
* react-slick + slick-carousel (carousels) — https://react-slick.neostack.com — License: MIT
* lucide-react (License: ISC) and react-icons (License: MIT) — icon sets
* @uppy/core (file selection) — https://uppy.io — License: MIT
* file-saver (client-side file downloads) — https://github.com/eligrey/FileSaver.js — License: MIT
* i18n-iso-countries and countries-list (country names and metadata for country name and flag display) — License: MIT
* DOMPurify (HTML sanitisation for user-supplied strings rendered in the dashboard) — https://github.com/cure53/DOMPurify — License: Apache-2.0 / MPL-2.0
* prop-types (runtime prop checks) — https://github.com/facebook/prop-types — License: MIT
* classnames (conditional CSS class-name joining) — https://github.com/JedWatson/classnames — License: MIT
* decimal.js-light (arbitrary-precision decimal math used by the charts) — https://github.com/MikeMcl/decimal.js-light — License: MIT
* Tailwind CSS (utility-first styling) — https://tailwindcss.com — License: MIT

= Bundled font =

* Noto Color Emoji (flags-only subset) — a small subset containing only country-flag glyphs, bundled in `Admin/View/Static/fonts/` so country flag emoji render consistently across platforms, including Windows (whose system emoji font omits flag glyphs). Source: https://github.com/googlefonts/noto-emoji — License: SIL Open Font License 1.1 (full text in `Admin/View/Static/fonts/OFL.txt`).

No JavaScript library is loaded from a remote CDN at runtime; the bundle is served entirely from the plugin directory.

== Privacy Policy ==

Tailwatch stores all logs and operational data locally in your WordPress database (custom tables `{prefix}tw_settings`, `{prefix}tw_logs`, `{prefix}tw_filemon_baseline`, and `{prefix}tw_filemon_scans`) and on disk under `uploads/tailwatch/tailwatch-logs/` (logs and generated data) and `wp-content/tailwatch/tailwatch-backup/` (backup archives). Both directories are sealed with deny files (.htaccess, index.php, web.config) so their contents are not reachable over the web.

Data stored locally:

– **Activity logs** — login/logout events, failed login attempts (including the username submitted and the originating IP address), registrations, password reset events
– **HTTP error logs** — 4xx / 5xx responses captured during the request lifecycle
– **Email logs** — SMTP send results for outbound site mail
– **Backup metadata** — backup catalogue entries (filenames, sizes, timestamps); the actual archives live in `wp-content/tailwatch/tailwatch-backup/`
– **File integrity baselines** — file path + hash snapshots used to detect added / modified / deleted files
– **Broken link / redirection records** — URLs detected on your site and any redirects you have configured
– **System configuration data** — every plugin feature toggle, schedule, and threshold lives in `{prefix}tw_settings`
– **Client IP addresses** — captured for security-relevant events (failed logins, rate-limited requests, redirection hits) so the dashboard and audit trail can show where the event came from
– **Login Defender state** — IP addresses temporarily locked out after repeated failed logins, throttling counters, and the per-IP activity history that drives the lockout decisions
– **Geo-blocking lists** — IPs and IP ranges you have explicitly allow-listed or block-listed via the Geo-blocking screen, plus the timestamp and admin who created each rule
– **Visit / usage telemetry** — a local-only counter in the `tailwatch_visit_data` option, used to drive in-dashboard onboarding hints; never transmitted

No user data, logs, or PII is transmitted to external servers unless an optional feature is explicitly enabled by the site administrator. The full list of outbound transmissions is documented in the "External Services" section above.

== Screenshots ==

1. Features Overview
2. Real-time Notification Settings
3. Updates & Rollback (Real-time Notifications)
4. Dashboard Overview
5. Backup Vault
6. Malware Guard
7. System Settings
8. Connect License

== Changelog ==

= 1.0.2 =
* New: Media Library - browse, upload, and delete your WordPress media library from the Tailwatch dashboard and connected mobile app. Uploads use WordPress's standard handler, are limited to images, and respect your media capabilities.
* New: Scheduled Automatic Updates for plugins, themes, and WordPress core, using WordPress's own built-in updater; core is limited to minor and security releases. Off by default, with per-type toggles.
* New: Security Keys Rotation - optionally regenerate your wp-config.php security keys (salts) on a schedule (every 15 days or monthly). The rewrite is validated and automatically rolled back if anything fails, and each rotation signs out active sessions. Off by default.
* New: Network Logs - optionally record lightweight metadata (endpoint, method, status, response time) for internal REST, AJAX, cron, and XML-RPC requests. Credentials and tokens are redacted and request or response bodies are never stored. Off by default.
* New: File and Directory Access hardening - optional .htaccess rules to block direct access to sensitive files, disable directory browsing, block PHP execution in the uploads folder, and restrict wp-includes. Off by default, and automatically reverted if your site stops responding.
* New: Disable Script Concatenation - optional toggle to turn off WordPress admin script and style concatenation, without modifying wp-config.php. Off by default.
* Fix: Broken Link Checker now uses WordPress's safe HTTP client, so link checks cannot reach internal or private network addresses.
* Fix: Remove HTML Comments and Minify no longer alters inline text spacing, preformatted text, text areas, or inline scripts and styles, and falls back to the original page if it cannot complete.
* Fix: Broader database compatibility for the Login Defender IP activity view (removed a query that required newer MySQL or MariaDB versions).
* Improvement: On multisite, plugin, theme, core, and user-management actions now require the matching per-action capability, so a site administrator cannot act beyond their network permissions. No change on single-site installations.
* Improvement: File integrity monitoring now also covers sites whose wp-content, plugins, or uploads directories are symbolic links.
* Fix: Resolved PHP notices in Login Defender under WP_DEBUG when a stored rule was malformed.
* Performance: Connection-token records are no longer loaded on every page request.
* Fix: Grouped caches always use a valid cache lifetime.
* More interface strings are now translatable.
* Internal cleanup: removed unused code and duplicate notification entries.
* Note: the security score weighting was rebalanced to include the new Security Keys Rotation and Auto-Update features, so your displayed score may change after updating even if your settings are unchanged.
* Tested up to WordPress 7.1.

See changelog.txt (included with the plugin) for the full changelog.

== Source Code ==

Tailwatch is 100% open source and distributed under GPL-2.0-or-later. The
complete, human-readable source code — including the React source and the
Create React App / Webpack build tooling used to produce the admin dashboard
bundle that ships with this plugin — is publicly available at:

https://github.com/tailwatch/tailwatch

The React source for the admin dashboard lives under `admin-app/` in that
repository. To reproduce the compiled bundle that ships in this plugin, from
the repository root:

`npm run build`

That installs the pinned dependencies, builds the React app, and copies the
compiled bundle into `Admin/View/Static/`. Prerequisites are Node.js 20 or
later and npm 10 or later. Full reproducibility notes — the exact Node/npm
versions used for the shipped build, every runtime dependency, and its
license — are documented in `README.md` at the repository root.

Bug reports and pull requests are welcome via GitHub Issues:
https://github.com/tailwatch/tailwatch/issues

== Upgrade Notice ==

= 1.0.2 =
Adds optional Media Library, scheduled auto-updates, security-key rotation, network logs, and file-access hardening, plus safer broken-link checks, a minification fix, broader database compatibility, and multisite capability hardening.

