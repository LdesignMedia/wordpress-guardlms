# GuardLMS for WordPress

GuardLMS is a WordPress plugin that reports your site's core version, plugin/theme inventory,
and server/PHP environment to the [GuardLMS](https://app.guardlms.com) service on a daily
basis, so known CVEs affecting your stack are detected and surfaced in your GuardLMS dashboard.

This plugin is a WordPress port of the observable behaviour of `moodle-local_guardlms`,
re-implemented with WordPress-native primitives (Options API, WP-Cron, HTTP API, Settings API).

## Documentation

* [`CONTRACT.md`](CONTRACT.md) — the authoritative shared contract every class, option key,
  method signature, and file in this plugin must conform to exactly.

Read it before contributing; this README summarizes it but is not a substitute.

## Architecture

The plugin is a set of small, single-responsibility classes under `includes/`, each in its own
file, using the classic `GuardLMS_` class prefix (no PHP namespace, for WPCS filename
compatibility):

* `GuardLMS_Options` / `GuardLMS_Credentials` — settings storage. Non-secret settings
  (`guardlms_settings`) are autoloaded; the API key (`guardlms_credentials`) is stored
  separately with `autoload => 'no'` and is never rendered or logged.
* `GuardLMS_Collector` — builds the inventory payload (core, plugins, themes, server, PHP,
  optional allowlisted config).
* `GuardLMS_Http` / `GuardLMS_Pusher` — sends the payload to GuardLMS via `wp_remote_post()`
  with `redirection => 0` and `reject_unsafe_urls => true`, including a degraded-push guard
  that refuses to overwrite GuardLMS's inventory with an implausible empty/partial snapshot.
* `GuardLMS_Cron` — schedules the jittered daily push and the one-off initial push.
* `GuardLMS_Head_Injector` — renders the `<meta name="guardlms-verification">` ownership tag.
* `includes/admin/GuardLMS_Settings` — the Settings API page: enable/disable, base URL, push
  path, API key, verification token, "Push now", and status/expiry/clone notices.

See `CONTRACT.md` for the full file layout and class contracts. The Phase 2 connect/OAuth
classes are not yet implemented.

## Phase 1 vs Phase 2 scope

This repository currently ships **Phase 1**:

* Daily and on-demand inventory push using a manually issued, site-bound GuardLMS API key.
* Settings page showing the exact site URL to register in the GuardLMS dashboard, plus last
  push time/status.
* Key-expiry warning (within 30 days) and an automatic clone/URL guard that clears the stored
  key if the site's URL changes (e.g. staging clones).
* Optional, opt-in ownership verification via a `<meta name="guardlms-verification">` tag,
  using a token pasted from the GuardLMS dashboard.

**Phase 2** (not yet implemented, gated on GuardLMS backend co-deliverables)
will add a keyless one-click "Connect" flow (OAuth-style consent + callback + server-to-server
key exchange), automatic ownership verification (meta tag + DNS-TXT, delivered via the connect
exchange), and cache-purge-on-connect for common caching plugins/hosts.

No functionality beyond what is described above and in `CONTRACT.md` is implemented;
if you're looking for connect/OAuth, disconnect, or automated ownership verification, that work
is tracked as Phase 2.

## Development

### Requirements

* PHP 7.4+ (tested against PHP 7.4 and 8.2 in CI)
* [Composer](https://getcomposer.org/)
* WordPress 6.0+ (target environment; unit tests run without a live WordPress install via
  Brain Monkey)

### Setup

```bash
composer install
```

### Coding standards

The project follows the `WordPress-Extra` PHPCS ruleset (see `phpcs.xml.dist`), with the
`guardlms`/`GuardLMS` text-domain and prefix, targeting PHP 7.4+.

```bash
composer phpcs
```

### Tests

```bash
composer test
```

Unit tests cover the collector, options, credentials, HTTP, pusher, and head-injector classes
(including the degraded-push guard and HTTP error mapping) using PHPUnit with Brain
Monkey/Mockery.

### Continuous Integration

`.github/workflows/ci.yml` runs `composer phpcs` and `composer test` on PHP 7.4 and 8.2 for
every push and pull request.

## Distribution & update channel

**WordPress.org SVN is the primary distribution channel.** Once the plugin passes the
WordPress.org plugin review, releases are published to the WordPress.org plugin directory and
sites receive updates through WordPress's built-in update mechanism, same as any other
WordPress.org-hosted plugin.

As a fallback for self-hosted or pre-release distribution (e.g. before the plugin is approved
on WordPress.org, or for a premium/private build), the plugin can be wired to
[`plugin-update-checker`](https://github.com/YahnisElsts/plugin-update-checker) to check a
self-hosted update manifest instead of WordPress.org. This is a fallback only — WordPress.org
SVN remains the primary, intended distribution channel for this plugin.

## License

GuardLMS is licensed under the GNU General Public License v3.0 (GPLv3). See
https://www.gnu.org/licenses/gpl-3.0.html for the full license text.
