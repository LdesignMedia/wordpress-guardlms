=== GuardLMS ===
Contributors: ldesignmedia
Tags: security, cve, monitoring, vulnerability
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv3
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Reports your WordPress core version, plugin/theme inventory, and server/PHP environment to GuardLMS daily so known CVEs affecting your site are detected automatically.

== Description ==

GuardLMS keeps your WordPress installation under continuous vulnerability monitoring. Once
configured, the plugin sends a daily snapshot of your site's software inventory to the GuardLMS
service (https://app.guardlms.com), where it is matched against a database of known CVEs
affecting WordPress core, plugins, and themes. If a vulnerable component is detected, it is
surfaced in your GuardLMS dashboard so you can patch or remove it before it is exploited.

**What the plugin does**

* Collects the installed WordPress core version, the full plugin inventory (including
  must-use plugins and drop-ins) with slugs, names, versions and active state, and the active
  theme inventory with slugs, names and versions.
* Collects basic server and PHP environment details (operating system, hostname, web server
  signature, PHP version, SAPI, memory/execution limits, and loaded extensions) to help GuardLMS
  assess environment-specific risk.
* Pushes this snapshot to GuardLMS once a day via a background (WP-Cron) task, and on demand
  whenever you click "Push now" in the plugin settings.
* Renders a `<meta name="guardlms-verification">` tag in your site's `<head>` so GuardLMS can
  verify that you own the site. The token is installed by the connect flow.
* Optionally includes a small, non-secret set of configuration flags (`WP_DEBUG`,
  `force_ssl_admin`, `users_can_register`, `default_role`, `blog_public`) when you explicitly
  opt in, disabled by default.

Setup is one click: "Connect to GuardLMS" sends you to GuardLMS to confirm, then installs the
push key and verifies ownership automatically. No API key to copy.

= Third Party Services =

This plugin relies on a third-party service, **GuardLMS** (https://app.guardlms.com), to
perform CVE and vulnerability monitoring for your site. This section discloses exactly what is
shared with that service, in line with the WordPress.org plugin guidelines.

**What is sent to GuardLMS:**

* Your WordPress core version number.
* Your installed plugin and theme slugs, versions, and active/inactive state (including
  must-use plugins and drop-ins).
* Basic server details: operating system, hostname, and web server software string.
* Basic PHP environment details: PHP version, SAPI, memory limit, max execution time, upload
  and post size limits, timezone, and the list of loaded PHP extensions.
* Optionally, if you explicitly enable "Include configuration" in the plugin settings, a small
  allowlist of non-secret configuration flags (`WP_DEBUG`, `force_ssl_admin`,
  `users_can_register`, `default_role`, `blog_public`).
* Your site URL, used by GuardLMS to identify which registered website the data belongs to.

**What is never sent:** no personal data, no user data, no post/page content, no database
contents, and no secrets or credentials of any kind. The GuardLMS API key you configure is used
only to authenticate the outgoing push request to GuardLMS and is never included in the
transmitted payload.

**When data is sent:** once daily via a scheduled background task, and immediately whenever you
click "Push now" on the plugin settings page.

By installing and configuring this plugin, you agree to GuardLMS's own Terms of Service and
Privacy Policy, which govern how GuardLMS itself handles the data described above:

* Terms of Service: https://guardlms.com/terms
* Privacy Policy: https://guardlms.com/privacy

If you do not wish to use this third-party service, do not enter a GuardLMS API key, or
deactivate/uninstall the plugin — no data is sent to GuardLMS while the plugin is disabled or
unconfigured.

== Installation ==

1. Upload the `guardlms` folder to the `/wp-content/plugins/` directory, or install the plugin
   through the WordPress Plugins screen directly.
2. Activate the plugin through the "Plugins" screen in WordPress.
3. Go to **Settings → GuardLMS**.
4. Click "Connect to GuardLMS". You are sent to GuardLMS to sign in or create a free account and
   confirm the connection, then returned to your site.
5. That is it. The site is registered, ownership is verified automatically, the push key is
   installed and the first inventory push is queued.

Advanced settings (base URL, push path, API key, verification token, manual push) are hidden on
purpose so a working connection cannot be broken by accident. Support and self-hosted setups can
reach them at `/wp-admin/options-general.php?page=guardlms&advanced=1`, or pin them in
`wp-config.php` with `GUARDLMS_PUSH_KEY`, `GUARDLMS_BASEURL` and `GUARDLMS_PUSHPATH`.

== Frequently Asked Questions ==

= Does this plugin send any personal or user data to GuardLMS? =

No. GuardLMS only receives your WordPress core version, plugin/theme inventory, and
server/PHP environment details as described in the "Third Party Services" section above. No
posts, pages, users, or database content are ever transmitted.

= Do I need a GuardLMS account? =

Yes. You need a GuardLMS account. A free account is enough. Register at
https://app.guardlms.com, or create one during the connect flow.

= What happens if I don't connect the site? =

The plugin stays inactive: no data is collected or sent, and the daily push is skipped until you
click "Connect to GuardLMS".

= Why did my push key stop working after I cloned or moved my site? =

GuardLMS ties a push key to the site URL it was issued for. If the plugin detects that your
site's URL has changed since the key was saved (for example after cloning to a staging
environment), it automatically clears the stored key so the clone cannot push data as if it
were the original site. Click "Connect to GuardLMS" again to reconnect the new URL.

= How often is data sent? =

Once a day via WP-Cron, plus on demand with "Push now" in the advanced view.

= Is my GuardLMS API key stored securely? =

The API key is stored in a dedicated, non-autoloaded WordPress option and is never included in
outgoing payload data. You may alternatively define `GUARDLMS_PUSH_KEY` as a constant in
`wp-config.php`, which takes precedence over the stored key and keeps it out of the database
entirely.

== Changelog ==

= 0.1.0 =
* Initial Phase 1 release: daily/on-demand inventory push (core, plugins, themes,
  server/PHP environment) to GuardLMS using a manually issued API key, settings page,
  key-expiry warning, clone/URL guard, and optional pasted-token ownership verification meta
  tag.

== Upgrade Notice ==

= 0.1.0 =
Initial release.
