# GuardLMS for WordPress

A WordPress plugin that reports the site to [GuardLMS](https://app.guardlms.com)
for security monitoring. Once a day the plugin pushes the WordPress version, the
installed plugin and theme inventory and the server environment to GuardLMS over
HTTPS. GuardLMS matches the site against known CVEs.

This plugin is a WordPress port of the observable behaviour of
[`moodle-local_guardlms`](https://github.com/LdesignMedia/moodle-local_guardlms),
re-implemented with WordPress-native primitives (Options API, WP-Cron, HTTP API,
Settings API, REST API).

## What it does

A daily scheduled task builds a payload and sends it to the configured GuardLMS
endpoint, authenticated with a bearer push key. The payload is a typed envelope so
sections can grow over time:

- `wordpress`: version, multisite flag, locale and every installed plugin, theme,
  must-use plugin and drop-in as its folder slug, version, display name,
  standard/third party flag and enabled/active state
- `server`: operating system, hostname and webserver software
- `php`: PHP version, SAPI, loaded `php.ini`, memory limit, max execution time,
  upload and post size limits, timezone and the loaded extensions
- `config` (optional, off by default): selected security settings, such as
  `WP_DEBUG`, `force_ssl_admin`, `users_can_register`, `default_role` and
  `blog_public`, so GuardLMS can review how the site is hardened

Plugin and theme versions are reported with the raw values exactly as WordPress
records them, because GuardLMS matches CVEs on the folder slug and version — the
same identity the WPScan and NVD feeds use.

### Example payload

```json
{
  "platform": "wordpress",
  "siteurl": "https://example.com",
  "generatedtime": 1781308800,
  "wordpress": {
    "version": "6.7.1",
    "multisite": false,
    "locale": "en_US",
    "plugincount": 2,
    "plugins": [
      {
        "slug": "woocommerce",
        "file": "woocommerce/woocommerce.php",
        "name": "WooCommerce",
        "version": "9.4.2",
        "kind": "plugin",
        "isstandard": false,
        "enabled": 1,
        "networkactive": 0
      },
      {
        "slug": "akismet",
        "file": "akismet/akismet.php",
        "name": "Akismet Anti-spam",
        "version": "5.3.0",
        "kind": "plugin",
        "isstandard": true,
        "enabled": 0,
        "networkactive": 0
      }
    ],
    "themes": [
      {
        "slug": "twentytwentyfour",
        "name": "Twenty Twenty-Four",
        "version": "1.2",
        "kind": "theme",
        "active": true
      }
    ]
  },
  "server": {
    "os_family": "Linux",
    "os": "Linux",
    "hostname": "web01",
    "webserver": "Apache/2.4.58"
  },
  "php": {
    "version": "8.2.0",
    "sapi": "fpm-fcgi",
    "ini": "/etc/php/8.2/fpm/php.ini",
    "memory_limit": "512M",
    "max_execution_time": "30",
    "upload_max_filesize": "100M",
    "post_max_size": "100M",
    "timezone": "UTC",
    "extensions": ["Core", "curl", "json", "..."]
  }
}
```

## Installation

1. Upload the plugin to `wp-content/plugins/guardlms` (or install the zip from the
   Plugins screen).
2. Activate **GuardLMS** from the Plugins screen.

## Connect to GuardLMS

1. Open **Settings > GuardLMS**.
2. Click **Connect to GuardLMS**. Your browser is sent to GuardLMS where you log
   in or create a **free** account and confirm the connection.
3. Done. The site is registered in GuardLMS, site ownership is verified
   automatically (the plugin serves a verification meta tag), the push key is
   installed, and the first inventory push is queued.

No API keys to copy, no tokens to paste. The daily push runs from WP-Cron. If
pushes ever fail or the push key is about to expire, open the page again and click
**Reconnect**.

## Advanced settings (support and self-hosted only)

The settings page shows the connection status and one button, nothing else, so a
site owner cannot break a working connection by editing an endpoint. The
connection fields live on the same page behind a URL parameter:

```
/wp-admin/options-general.php?page=guardlms&advanced=1
```

That view exposes the base URL, the push path, the API key, the verification
token, the reporting toggle, the "Include configuration" toggle and **Push now**.

Values can also be pinned in `wp-config.php`, which takes precedence over the
stored settings, keeps them out of the database, and renders them read-only in the
advanced view:

```php
define( 'GUARDLMS_PUSH_KEY', '...' );                        // inventory push key
define( 'GUARDLMS_BASEURL', 'https://guardlms.example.com' ); // self-hosted GuardLMS
define( 'GUARDLMS_PUSHPATH', '/api/externalpush/wordpress' );
```

The push key is stored in a dedicated, non-autoloaded option and is never rendered
or logged.

## Requirements

- WordPress 6.0 or later.
- PHP 7.4 or later.

## Development

```bash
composer install
composer phpcs   # WordPress-Extra coding standard
composer test    # PHPUnit + Brain Monkey unit tests
```

`CONTRACT.md` documents the class layout, option keys, method signatures and the
exact payload shape every part of the plugin conforms to. WordPress core functions
are stubbed with Brain Monkey, so the unit tests run without a live WordPress
install. CI runs `composer phpcs` and `composer test` on PHP 7.4 and 8.2.

**Distribution:** WordPress.org SVN is the primary channel; for self-hosted or
pre-release builds the plugin can be wired to
[`plugin-update-checker`](https://github.com/YahnisElsts/plugin-update-checker)
as a fallback.

## License

GNU GPL v3 or later. See <https://www.gnu.org/licenses/gpl-3.0.html>.
