=== Tailwatch – Security, Backups, Monitoring & Management ===
Contributors: wptailwatch
Tags: security, backup, monitoring, login-security, audit-log
Requires at least: 6.3
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.4
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
* Restore & Migration (put a backup back onto the site, or move a whole site between two installs by carrying the backup across)
* Search & Replace (safe database-wide updates with serialized data handling)
* Content Restrictions (browser-level protection controls like copy/inspect restrictions)
* Database Optimization Tools
* Broken Link Scanner
* Cron Job Manager
* 301 Redirect Manager
* User Management (create, edit, delete users and manage roles)
* Login Defender (IP-based brute-force protection: failed-login throttling, automatic temporary lockouts, login logs kept for 30 days)
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

Push delivery uses notification credits from your Tailwatch account. When the credits run out, Tailwatch skips the push; the event itself is still recorded in the plugin's logs.

= Tailwatch Pro =

Tailwatch Pro extends the free version with:

* Malware Guard (cloud malware scanning, suspicious file detection, cleanup, and push alerts for risk-detection events)
* Country blocking (allow or block visitors by country, applied to login forms or your entire site)
* Role-based Two-Factor Authentication (2FA)
* Advanced User Management (account-expiry rules, "login as another user", per-user 2FA status surfaced in the user list)
* Expanded notification credits
* Advanced scheduling controls for backups and maintenance tasks
* Automatic site-to-site migration (the two sites transfer the backup between themselves, with nothing to download or upload by hand)
* Database-only or files-only restore
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
No. An account is only required for optional features like push notifications and account sync.

= Will it slow down my site? =
Tailwatch is built to keep its impact small: it uses lazy loading and caching, and runs long tasks such as backups, restores and scans in the background in small steps. While those tasks run they use your server's CPU, memory and disk like any other work, so their effect depends on your hosting.

= Does Tailwatch send data externally? =
Only when you enable optional features such as:
– Push notifications
– License verification
– Optional feedback submission

Core features remain fully local. See the "External Services" section below for the full breakdown of each transmission.

= What data does Tailwatch store, and where? =
Tailwatch stores all logs and operational data locally in your WordPress database (custom tables `{prefix}tw_settings`, `{prefix}tw_logs`, `{prefix}tw_filemon_baseline`, and `{prefix}tw_filemon_scans`) and on disk under `uploads/tailwatch/tailwatch-logs/` (logs and generated data) and `wp-content/tailwatch/tailwatch-backup/` (backup archives). While a restore runs, it prepares the backup's files and sets the site's replaced files aside in `wp-content/tailwatch/tailwatch-restore/`, and removes them when it finishes. These directories are sealed with deny files (.htaccess, index.php, web.config) so their contents are not reachable over the web.

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

No user data, logs, or PII is transmitted to external servers unless an optional feature is explicitly enabled by the site administrator. The full list of outbound transmissions is documented in the "External Services" section below.

= Can I disable features? =
Yes. Every module in Tailwatch can be individually enabled or disabled.

= How does the one-click login link work? =
When you request a login link from an already-authenticated Tailwatch session (the mobile app or the connected dashboard), the plugin issues a single-use link that expires within one hour and signs in only the administrator who requested it. The link is stored hashed, so a copy of your database reveals no usable link, and it is invalidated the moment it is opened. Because it is a passwordless login you start yourself, opening the link signs you straight in — like a password-reset link, it is an alternative to typing your password rather than a second prompt.

= Does Tailwatch modify my site's .htaccess file? =
The optional Performance Optimizer feature can add a "Tailwatch Performance Settings" block to your site's root .htaccess — but only if you apply PHP configuration settings from that feature and your server runs Apache with mod_php (the plugin probes for support first). It uses WordPress core's insert_with_markers() function, which scopes the change to a single BEGIN/END marker block and preserves every other line in the file; this feature never rewrites the whole file. After writing, it verifies your site still responds and automatically removes the block if anything fails. You can clear these settings at any time from the feature. The optional File & Directory Access hardening options (under Files & Permissions) can add a separate "Tailwatch File Access" block to your root .htaccess when you turn them on — for example to block direct web access to sensitive files such as wp-config.php, disable directory browsing, or stop PHP execution inside the uploads folder. That block uses the same WordPress core insert_with_markers() function (scoped to its own BEGIN/END markers, every other line preserved), is only written when the .htaccess file is writable, is verified with a loopback request, and is automatically removed if your site stops responding; turning the options off removes it. Separately, Tailwatch writes deny files (.htaccess, index.php, web.config) inside its own storage folders under uploads/tailwatch/ and wp-content/tailwatch/ to keep their contents unreachable over the web.

There is one case where Tailwatch does replace your root .htaccess rather than adding a marker block to it: while a restore or a site migration is running, and only then. Replacing the whole site's rewrite rules is the point — the site has to stop serving visitors from a database and a set of files that are being swapped underneath it. Before the file is replaced, your original is copied to .htaccess.tailwatch-migration-bak in the same folder, and Tailwatch also writes a tailwatch_maintenance.php file in your site's root to serve the "Site Under Maintenance" page. When the restore or migration finishes — or fails, or is abandoned — both are removed and your original .htaccess is put back exactly as it was. The maintenance file also removes itself and restores your .htaccess if it is left behind for more than 30 minutes without the job that created it checking in, so a crashed job cannot leave your site closed. You only ever get here by starting a restore or a migration yourself.

= Does Tailwatch change my PHP settings? =
Only in these two cases:

* Performance Optimizer (optional): when you apply PHP settings from that feature (memory limit, execution and input time, upload and post size, number of uploads), Tailwatch applies the ones PHP allows to change at run time on each request with ini_set(), and writes all of them to your server's own configuration so the rest take effect too: the "Tailwatch Performance Settings" block in your root .htaccess on Apache with mod_php (see above), or the same-named block in a .user.ini file in your site root on PHP-FPM, FastCGI, LiteSpeed and CGI servers (PHP picks up .user.ini changes within about five minutes). Only that block is changed; every other line in .user.ini is kept. Clearing the settings from the feature removes the block. Deactivating the plugin does not, so clear the settings first if you no longer want them.
* Tailwatch's own long tasks: while a backup, restore, file integrity check, hardening audit or (with Tailwatch Pro) malware scan runs, Tailwatch raises PHP's memory limit and maximum execution time for that request only, through WordPress's wp_raise_memory_limit() and set_time_limit(). It never lowers a limit, nothing is saved, and the next request starts from your site's own limits again.

= Does a restore or migration change my other plugins? =
Only when it brings in a database. Right after the database is switched over, Tailwatch switches off the plugins that could lock you out of the restored site: login-hiding, firewall, captcha and domain-mapping plugins and, on a site without HTTPS, forced-SSL plugins and settings (such as a shop's forced secure checkout). They are listed at the end of the restore and stay installed, so you can switch them back on from the Plugins screen. Developers can change or empty the list with the tailwatch_restore_deactivate_plugins filter.

During every restore Tailwatch also adds a small temporary must-use file, 0-0-tailwatch-restore-guard.php. If a must-use plugin crashes the site while it starts up, the guard renames that plugin's file (adding .tailwatch-disabled) so the next page loads, and the restore lists it at the end. The guard is removed when the restore finishes, fails or is given up on, and deletes itself on the next page load once it is more than 30 minutes old if anything leaves it behind.

= Does Tailwatch change PHP settings during scans? =
During a heavy operation you start — a malware scan (with Tailwatch Pro), file-integrity check, hardening audit, or backup — Tailwatch may temporarily raise PHP limits (memory_limit, max_execution_time) for the duration of that request so the operation does not fail on constrained hosts. This is a runtime-only adjustment: nothing is written to any file, it never lowers a limit your host already sets higher, and it has no effect outside the running scan.

= What does the optional Network Logs feature record? =
The optional Network Logs feature (off by default) records lightweight metadata about internal WordPress traffic — REST API, admin-ajax, cron, and XML-RPC requests — so you can review recent activity, status codes, and slow responses. For REST requests it reads the already-parsed request through WordPress's own rest_post_dispatch hook; for the others it reads only the sanitized action and request parameters. It stores the endpoint/route, HTTP method, status code, response time, and the requesting user and IP. Before anything is saved, credential, token, and nonce values are redacted, cookies are never captured or stored, and every value is length-capped. Raw request bodies and response bodies are never stored, and none of this data is sent anywhere — it is kept in your own database and can be cleared at any time from the feature. The feature only records while you have it switched on.

= Can I move Tailwatch's security keys into wp-config.php? =
Yes. For extra defense in depth you can define TAILWATCH_JWT_SECRET_KEY, TAILWATCH_APP_SECRET_KEY, and TAILWATCH_ENCRYPTION_KEY in your wp-config.php file (seed each with a fresh value from https://api.wordpress.org/secret-key/1.1/salt/). When these constants are set, Tailwatch uses them for token signing and encryption instead of generating and storing a key in the database, which keeps the secret out of database backups, staging clones, and read-only SQL exposure, the same way WordPress keeps its own security keys and salts in wp-config.php. This is entirely optional: if you do not set them, Tailwatch automatically generates a strong random key and stores it in a non-autoloaded option, the same approach WordPress core uses for its own fallback salts.

= Where is the source code? =
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

== External Services ==

This plugin connects to several external services. Each service, the data sent, and the conditions under which the connection is made are listed below. Most connections only occur when the corresponding optional feature is enabled by the site administrator.

= Tailwatch API (api.wptailwatch.com) =
Used for license verification, push notification delivery, and optional deactivation feedback submission. License verification and push notification delivery are active only after you connect a free Tailwatch account from the plugin's License screen. The deactivation feedback submission is independent of any account connection and is sent only if you voluntarily submit it (see below).

What is sent and when:
- License verification: your anonymized Tailwatch user identifier and your site URL (query string), plus an `X-Tailwatch-Header-Key` request header containing the license credential issued when you connected your account. Sent on dashboard visits (throttled by a short server-side cache), every 12 hours while your account is connected, and on demand when you click "Verify License". No system metadata is included in this request;
- Mobile push notifications: anonymized user identifier, your site domain, and the notification type/severity tag, sent to a single relay endpoint authenticated with a per-site routing token (the plugin sends no pre-written title or body; the service composes those from the event context). When mobile notifications are enabled, an event-context payload is also stored on api.wptailwatch.com so the mobile app can show details when you tap a push: the event name and feature, the action and any before/after state change, the timestamp, the IP address and user agent of the request (the acting admin's, or a visitor's for events such as failed logins and error pages, together with the username tried), and per-event display fields (for example, an Email/SMTP event's recipient address and subject line). It never includes message bodies, credentials, tokens, or license keys. This payload stays on api.wptailwatch.com and is not forwarded to Firebase Cloud Messaging (below), which only ever receives the slim title/body/type payload. The Recovery Mode alert for a critical site error is sent whenever your account is connected, even with mobile notifications off; its event context is the HTTP status code and the address of the page that failed.
- Deactivation feedback: site domain, deactivation reason, plugin version, your "keep data / delete data" choice from the deactivation modal, and optional free-form comments — sent only if you voluntarily click "Submit & Deactivate" (the "Skip & Deactivate" button avoids any transmission).

Service provider: WP Tailwatch
Privacy policy: https://wptailwatch.com/privacy-policy
Terms: https://wptailwatch.com/terms-of-services

= Tailwatch Dashboard (dashboard.wptailwatch.com) =
The web dashboard for your account. Your browser opens it only when you connect or disconnect your site, or follow an account link in the plugin.

What is sent: connecting creates this connection's credentials (a client ID, client secret and header key that let the dashboard and mobile app manage your site, and a standard WordPress recovery-mode cookie so the dashboard can help you regain access if the site becomes unreachable), then opens the dashboard sign-in page with your site URL, the label "production" and these four credentials in the page address. They reach dashboard.wptailwatch.com before you sign in and can stay in your browser history. Disconnecting opens it with your site URL and account email in the address. License information is stored in your WordPress database after you sign in. If you connect a site directly from the dashboard (remote provisioning), the dashboard sends your license details to your site over an HTTPS request you authorize with a WordPress Application Password approved on your own site; the plugin stores them locally, records who approved the connection and when, and emails the site administrator a connection notice.

Service provider: WP Tailwatch
Privacy policy: https://wptailwatch.com/privacy-policy
Terms: https://wptailwatch.com/terms-of-services

= Firebase Cloud Messaging (fcm.googleapis.com) =
Used to deliver mobile push notifications to your paired mobile devices. Only active when (a) you have connected a Tailwatch account, (b) you have enabled mobile notifications in plugin settings, and (c) you have toggled on the specific per-feature notification. The Recovery Mode alert needs only (a), so it reaches you even with mobile notifications off.

What is sent: an anonymized device token and the notification payload (title, body, type tag) — routed via api.wptailwatch.com to Firebase Cloud Messaging for delivery to your paired device.

Service provider: Google LLC
Privacy policy: https://firebase.google.com/support/privacy
Terms: https://firebase.google.com/terms

= Smart SSL Monitoring — host certificate inspection =
The Smart SSL feature connects to your own site's domain (from home_url()) to read its certificate metadata (issuer, validity dates, chain) and issues an HTTP HEAD probe to detect HTTP to HTTPS redirection. Only your own site is contacted; no third-party service is involved. Disable Smart SSL in plugin settings to stop these requests.

= Broken Link Checker — external URL scanning =
The Broken Links scanner issues GET requests to URLs found in your own post content, pages, term descriptions, user meta, and options to check whether they return 4xx or 5xx errors. The URLs contacted are controlled entirely by your site's content; Tailwatch maintains no external list. Disable the Broken Links feature in plugin settings to stop these requests.

= MaxMind GeoLite2 database download (download.maxmind.com) =
The optional GeoIP integration downloads the MaxMind GeoLite2-Country database so the plugin can resolve visitor IP addresses to a country (used to display the country of an event in the Login Defender logs, and — with the Pro add-on — for country-based access rules). This connection is made ONLY when you have entered your own MaxMind license key on the Integrations screen, and ONLY on an explicit action you take: when you save the key, and when you click "Check for updates". There is no automatic, background, or scheduled download.

What is sent: your MaxMind license key and the requested database edition ("GeoLite2-Country"), in a request to https://download.maxmind.com/app/geoip_download. The downloaded database file is stored inside your site's uploads directory. You must obtain your own (free) MaxMind account and license key; the GeoLite2 data is provided by MaxMind under the MaxMind GeoLite2 End User License Agreement. Remove the license key on the Integrations screen to delete the database and stop all such requests.

Service provider: MaxMind, Inc.
Privacy policy: https://www.maxmind.com/en/privacy-policy
GeoLite2 EULA: https://www.maxmind.com/en/geolite2/eula

= WordPress.org — plugin, theme, and core updates & rollback =
The optional Updates & Rollback feature contacts WordPress.org (api.wordpress.org for available-update and version information, downloads.wordpress.org for packages) to update or roll back your plugins, themes, and WordPress core through WordPress's own upgrade routines (WP_Upgrader / Core_Upgrader). Only official WordPress.org packages are downloaded; no third-party source is contacted. These requests are made when you open the Updates screen or click Update or Rollback and, if you turn on automatic updates (core limited to minor and security releases), on WordPress's own auto-update schedule for the types you switch on, while the feature is enabled. Disable the feature (or the individual automatic-update toggles) in plugin settings to stop these requests.

Service provider: WordPress.org (The WordPress Foundation)
Privacy policy: https://wordpress.org/about/privacy/

== Third-Party Libraries ==

= Bundled PHP libraries =

Tailwatch bundles the following open-source PHP libraries. They run entirely on your server. Each library's license is named below; all are compatible with this plugin's GPLv2-or-later license (the Apache-2.0 ones through GPLv3, which "or later" allows).

* MaxMind GeoIP2 PHP API + MaxMind-DB Reader — reads a GeoLite2-Country database (which you either download on the Integrations screen using your own MaxMind license key, or upload manually to your site's uploads directory) to resolve visitor IPs to a country for the IP Management / Login Defender geolocation feature. Runs under the `Tailwatch\Vendor\MaxMind\*` namespace. Source: https://github.com/maxmind/GeoIP2-php and https://github.com/maxmind/MaxMind-DB-Reader-php — License: Apache-2.0 (full text in `Vendor/MaxMind/LICENSE`)
* Firebase JWT (firebase/php-jwt) — encodes and verifies the signed HS256 JSON Web Tokens that authenticate the Connect REST API (used by the mobile app and cloud dashboard). Runs under the `Tailwatch\Vendor\Firebase\JWT\*` namespace. Source: https://github.com/firebase/php-jwt — License: BSD-3-Clause (full text in `Vendor/Firebase/JWT/LICENSE`)

All server-side HTTP requests use WordPress core's built-in HTTP API (wp_remote_get / wp_remote_post / wp_remote_head). No external PHP HTTP client libraries (e.g. Guzzle) are bundled.

= Bundled JavaScript libraries =

The plugin's admin dashboard is a React single-page application. Its compiled bundle in `Admin/View/Static/js/` and `Admin/View/Static/css/` is built from the following open-source libraries, all under GPL-compatible licenses:

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
* lucide-react (License: ISC) and react-icons (License: MIT) — icon sets. The react-icons sets used are Font Awesome Free (CC BY 4.0), Material Design, Remix and Grommet (Apache-2.0), Circum (MPL-2.0), Simple Icons (CC0 1.0) and MIT-licensed sets
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

== Screenshots ==

1. Features Overview
2. Real-time Notification Settings
3. Updates & Rollback (Real-time Notifications)
4. Dashboard Overview
5. Backup Vault
6. Malware Guard (Tailwatch Pro)
7. System Settings
8. Connect License

== Changelog ==

= 1.0.4 =
* New: Restore - put a backup back onto this site and follow each step on its own screen. Visitors see a maintenance page while it runs, a backup from another site is moved to this site's address and, on a single site, to its database table prefix, and the backup and the site are checked (every part present and readable, table names, write access, free space, and that every file has its place on this site) before anything is replaced. Only plain table statements are imported, the site keeps its own Tailwatch connection, and sign-in data carried across is removed when the restore finishes. Tables that a plugin links to each other or to WordPress's own tables (foreign keys) keep those links, and so do such tables when they are not in the backup. If a restore cannot finish, the site's own files are put back. Linked folders and folders the site cannot read are left where they are, and the restore log names them and any must-use plugin that had to be switched off for the site to start. Restoring is off until you switch it on. Before it starts, the confirmation lists what will happen. After a restore that brings in a database, the site's file-access rules are written again, and the restore screen says what has to be set up again, such as an email password this site cannot read or a connection that was dropped; until that password is entered again, SMTP mail goes out through WordPress's own mail instead of failing.
* New: Manual migration - move a site between two installs by exporting a backup on one and importing it on the other, for sites that cannot reach each other. It shows "In Progress" from the start and ticks each part as it arrives, uploads go through WordPress's upload handler, an interrupted upload continues where it stopped, and the restore that applies it starts on its own, with its screen following it. If the backup cannot be applied, the migration log says why.
* New: connect a site directly from the Tailwatch dashboard, approved on your own site with a WordPress Application Password.
* New: an invitation on the WordPress Dashboard for administrators to connect their free Tailwatch account, shown once setup is finished and only while the site is not connected. It can be closed for good or put off for 7 days, uses only files bundled with the plugin, and sends nothing to any outside service.
* Improved: backups check for free disk space before they start, split archives into parts of the size you choose, and continue after an interruption instead of starting again.
* Improved: when the number of backups kept drops without you choosing it (Tailwatch Pro switched off or its plan changed, Backup Vault switched off, its settings reset), the backups made before are kept; only newer backups take turns, and the Backup page says how many older ones are kept so you can delete them yourself. A lower number you choose still removes the older backups.
* Improved: a task that was stopped, failed or finished stays that way; a retry or a late step no longer starts it again.
* Improved: long tasks raise PHP's memory and time limits for their own requests only, and never lower a limit you or your host set.
* Improved: every free feature runs without a Tailwatch Pro license; Pro-only options are shown as Pro features.
* Improved: waiting tasks show "Queued", and Active Tasks refreshes each time you open it.
* Improved: Fix All on the dashboard switches on every free item it lists, now including Security Keys Rotation and Updates & Rollback, with the settings the security score checks, and names each feature in its results.
* Improved: the bundled JavaScript libraries axios, DOMPurify and React Router are updated.
* Improved: Connect Account in the sidebar shows whether the site is connected, and the account page has moved out of Settings.
* Improved: tasks that cannot run together never do. A paused task holds back any task that would change the site under it, resuming a task checks first, two starts at the same moment cannot both begin, plugin, theme and WordPress updates wait while a restore or migration runs, and a backup that a restore or malware scan is reading is not deleted.
* Security: backup download links are checked without being used up and work once; disconnecting also revokes the app's refresh tokens.
* Security: the maintenance page shows translated text as plain text; old configuration copies left by earlier versions are removed once.
* Security: login protection no longer exempts addresses that look like a logout, and each failed login is counted once.
* Security: push alerts a visitor can trigger (blocked IPs, password-reset emails, error pages) are limited to 5 per hour per event type; the log entry is always kept.
* Security: Network and Email Logs hide more secrets (sign-in headers, values inside forms and JSON, one-time links); stack traces no longer store argument values.
* Security: database backups leave out the Tailwatch connection secrets.
* Security: role changes need the Promote Users permission; temporary-login accounts cannot use a password, reset one, or use application passwords; the site always keeps an administrator who can sign in.
* Security: recovery links work only on a connected site and only once; theme actions accept only installed themes.
* Fix: the Tailwatch Pro card on add-on features, the message after disconnecting, and several screen texts.
* Fix: a backup of a large folder no longer looks stuck while its file list is built on a slow disk, and the log shows its progress.
* Fix: a folder the server will not let Tailwatch read is skipped and named in the backup log, instead of failing the whole backup.
* Fix: the backup log screen finishes on its own when a backup completes.
* Fix: updating from an earlier version no longer writes database errors to the PHP error log.
* Fix: the Users search box stays usable, and Add Schedule in Cron Jobs follows the feature switch.
* Fix: a backup started right after cancelling another is no longer failed, broken or stalled by the cancelled one.
* Fix: Resume acts only on a backup that is paused; a finished, cancelled or failed backup is never reopened.
* Change: "Allow self-signed certificates" for SMTP is off for new installs.
* Change: the country whitelist (letting visitors in by country) is now part of Tailwatch Pro, next to country blocking. Without Tailwatch Pro, countries whitelisted earlier are no longer exempted. The IP and IP-range whitelist stays in the free plugin.
* Change: Login Defender no longer switches on "Disable User Signup Form" and "Disable Lost Password" by default, and Fix All leaves them off.

= 1.0.3 =
* Hardening: on multisite, database Search & Replace and PHP performance-limit changes now require network administrator capabilities.
* Hardening: stricter Connect API token validation, and a safeguard that prevents an already-connected site from being silently re-paired.
* Improved: broader hosting compatibility for the Connect API on servers that don't forward the standard HTTP Authorization header to PHP.
* Improved: File & Directory Access protections, Network Logs metadata handling, and cron-health detection refinements.
* Added the Tailwatch logo to the connection consent screen.
* Improved: the Tailwatch dashboard hides admin notices from other plugins on its own screen so they no longer overlap the interface; notices still appear normally on every other admin screen.
* Fix: dashboard disk and database usage no longer fails with an error on sites with a low PHP memory limit; the figures are now cached, calculated within a safe time budget, and can be refreshed on demand.
* Housekeeping: the full changelog now lives in this readme file.

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

= 1.0.1 =
* Connect a free Tailwatch (wptailwatch.com) account for web-dashboard and mobile app access with real-time push notifications, over a hardened REST connection with dedicated-key encryption.
* One-click administrator login links (single-use, hashed, expire within one hour) initiated from a connected Tailwatch session.
* Recovery Mode provisioning so you can regain access if your site becomes unreachable.
* SMTP test tool for verifying your outgoing email configuration.
* MaxMind GeoLite2 database integration (admin-initiated download) for country detection in logs and geo features.
* Plugin and theme update management with one-click rollback.
* Performance Optimizer that raises PHP limits during heavy operations, with optional .htaccess tuning.
* Setup now runs only after you choose to begin, and remembers your choice.
* Login Defender: trusted allow-list IPs and countries are consistently excluded from brute-force tracking while the allow-list is enabled.
* Feature backfill on update so newly added features appear on existing sites; incompatibility notices are limited to administrators.
* Tested up to WordPress 7.1.

= 1.0.0 =
* Initial release
* Activity monitoring system
* Error (HTTP 4xx / 5xx) logging
* File integrity monitoring
* SSL monitoring
* Backup system (files and database)
* Database optimization tools
* Cron job manager
* Login Defender (IP-based brute-force protection + lockouts)
* Geo-blocking (IP and IP-range allow/block lists)
* Event-based push notifications


== Upgrade Notice ==

= 1.0.4 =
Adds Restore and manual Migration, and hardens backups and the Connect API. The country whitelist is now part of Tailwatch Pro. Recommended update.

