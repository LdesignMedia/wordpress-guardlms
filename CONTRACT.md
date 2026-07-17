# guardlms plugin — SHARED CONTRACT (authoritative)

Every worker MUST conform to this exactly. Do NOT invent option keys, class names, or method
signatures. Each class is its own file. Text domain: `guardlms`. GPLv3 headers on every PHP file.
No PHP namespace — use the classic `GuardLMS_` class prefix (keeps WPCS filename rule simple).
`declare(strict_types=1);` is NOT used (WP core compat). Escape all output, sanitize all input.

## Constants (defined in guardlms.php)
- `GUARDLMS_VERSION` (string, e.g. '0.1.0')
- `GUARDLMS_PLUGIN_FILE` (__FILE__), `GUARDLMS_PLUGIN_DIR` (plugin_dir_path), `GUARDLMS_PLUGIN_URL`
- `GUARDLMS_DEFAULT_BASEURL` = 'https://app.guardlms.com'
- `GUARDLMS_DEFAULT_PUSHPATH` = '/api/externalpush/wordpress'
- `GUARDLMS_DAILY_HOOK` = 'guardlms_daily_push'
- `GUARDLMS_INITIAL_HOOK` = 'guardlms_initial_push'
- wp-config override (optional, read-only): `GUARDLMS_PUSH_KEY`

## Options
### `guardlms_settings` (AUTOLOADED — public/non-secret only; rendered on wp_head)
Array with keys + default types:
- `enabled` (bool, default true)
- `baseurl` (string, default GUARDLMS_DEFAULT_BASEURL)
- `pushpath` (string, default GUARDLMS_DEFAULT_PUSHPATH)
- `sendconfig` (bool, default false)
- `verificationtoken` (string, default '')  — PUBLIC token pasted from GuardLMS dashboard
- `connected_siteurl` (string, default '')  — rtrim(home_url(),'/') captured when key saved (clone guard)
- `lastpush` (int unix ts, default 0)
- `lastpushstatus` (int http code, default 0)
- `keyexpiresat` (int unix ts, default 0)  — 0 = unknown (manual path)
- `last_plugincount` (int, default 0)  — for degraded-push sanity delta

### `guardlms_credentials` (autoload='no' — SECRET only)
Array: `apikey` (string, default '').

## Classes / signatures
### GuardLMS_Options  (includes/class-guardlms-options.php)
```
const OPTION = 'guardlms_settings';
public static function defaults(): array
public static function all(): array                      // defaults merged over stored
public static function get( string $key, $default = null )
public static function set( string $key, $value ): void
public static function update( array $values ): void     // partial merge + save
public static function ensure_option(): void             // add_option(OPTION, defaults(), '', 'yes')
public static function delete(): void
```
### GuardLMS_Credentials  (includes/class-guardlms-credentials.php)
```
const OPTION = 'guardlms_credentials';
public static function get_key(): string   // if defined('GUARDLMS_PUSH_KEY') return trim(that); else trim(option apikey)
public static function set_key( string $key ): void
public static function has_key(): bool
public static function ensure_option(): void   // add_option(OPTION, ['apikey'=>''], '', 'no')  <-- autoload NO
public static function delete(): void
```
### GuardLMS_Collector  (includes/class-guardlms-collector.php)
```
const CONFIG_KEYS = [ 'WP_DEBUG','force_ssl_admin','users_can_register','default_role','blog_public' ]; // allowlist, no secrets
public static function build_payload( bool $include_config = false ): array   // EXACT shape below
```
Payload shape (build_payload):
```
[
 'platform' => 'wordpress',
 'siteurl'  => rtrim( home_url(), '/' ),
 'generatedtime' => time(),
 'wordpress' => [
   'version' => (string) $wp_version,
   'multisite' => is_multisite(),
   'locale' => get_locale(),
   'plugincount' => <count of plugins[]>,
   'plugins' => [ [ 'slug'=>dirname(file) (fallback basename(file,'.php') for root single-file),
                    'file'=>plugin_file, 'name'=>Name, 'version'=>Version, 'kind'=>'plugin'|'muplugin'|'dropin',
                    'isstandard'=>false, 'enabled'=>1|0, 'networkactive'=>1|0 ], ... ],
   'themes' => [ [ 'slug'=>stylesheet, 'name'=>Name, 'version'=>Version, 'kind'=>'theme', 'active'=>bool ], ... ],
 ],
 'server' => [ 'os_family'=>PHP_OS_FAMILY, 'os'=>PHP_OS, 'hostname'=>gethostname()?:null, 'webserver'=>...cached... ],
 'php' => [ 'version'=>PHP_VERSION,'sapi'=>php_sapi_name(),'ini'=>php_ini_loaded_file()?:'none',
            'memory_limit'=>ini_get(...),'max_execution_time'=>ini_get(...),'upload_max_filesize'=>ini_get(...),
            'post_max_size'=>ini_get(...),'timezone'=>wp_timezone_string(),'extensions'=>sorted get_loaded_extensions() ],
 // 'config' => [ key=>stringvalue ] only when $include_config
]
```
Inventory rules: use `get_plugins()`, `get_mu_plugins()`, `get_dropins()` (require_once ABSPATH.'wp-admin/includes/plugin.php' first). active = in get_option('active_plugins') OR array_key in get_site_option('active_sitewide_plugins') (networkactive=1 for the latter). mu-plugins/dropins enabled=1. themes via wp_get_themes()/get_stylesheet(). NO secrets anywhere. webserver: GuardLMS_Options::get('webserver') fallback $_SERVER['SERVER_SOFTWARE'] (cache to settings during a web request — see plugin boot).

### GuardLMS_Http  (includes/class-guardlms-http.php)
```
// returns [ 'code'=>int, 'body'=>string ] on transport success, or WP_Error on transport failure
public static function post( string $url, string $json_body, array $headers = [] )
// uses wp_remote_post with: timeout 30, redirection 0, reject_unsafe_urls true, headers merged,
// 'Content-Type: application/json','Accept: application/json'
```
### GuardLMS_Pusher  (includes/class-guardlms-pusher.php)
```
public static function push()   // returns true on success (2xx) OR WP_Error on failure
```
Logic: read baseurl+pushpath (Options) + key (Credentials). If key/baseurl empty -> WP_Error('guardlms_notconfigured').
Degraded-push guard: require_once ABSPATH.'wp-admin/includes/plugin.php'; if $wp_version empty -> WP_Error. Build payload;
plugincount = payload count. If last_plugincount>0 && plugincount===0 -> WP_Error('guardlms_degraded') (implausible drop; do not overwrite). endpoint = rtrim(baseurl,'/').'/'.ltrim(pushpath,'/'). Bearer auth header.
On WP_Error transport -> return it. On http<200||>=300 -> WP_Error('guardlms_pushhttp', code); if code===422 message must name registered vs actual siteurl. On 2xx -> Options::update(['lastpush'=>time(),'lastpushstatus'=>code,'last_plugincount'=>plugincount]); return true.

### GuardLMS_Cron  (includes/class-guardlms-cron.php)
```
public static function schedule(): void     // if !wp_next_scheduled(DAILY) wp_schedule_event(time()+wp_rand(0,DAY_IN_SECONDS),'daily',DAILY_HOOK)
public static function unschedule(): void    // wp_clear_scheduled_hook(DAILY) + (INITIAL)
public static function run_daily(): void      // if !enabled return; expiry warn handled in admin; call Pusher::push(), log WP_Error via error_log
public static function run_initial(): void    // Pusher::push()
```
### GuardLMS_Head_Injector  (includes/class-guardlms-head-injector.php)
```
public static function meta_tag(): string     // '' unless Options enabled AND verificationtoken!=''; else '<meta name="guardlms-verification" content="ESC">'."\n"
public static function render(): void          // echo meta_tag() (hooked to wp_head)
```
### GuardLMS_Settings  (includes/admin/class-guardlms-settings.php)
```
public static function register(): void        // add_options_page(slug 'guardlms'); register_setting('guardlms', 'guardlms_settings', sanitize cb) + sections/fields
public static function sanitize( $input ): array
public static function render_page(): void
public static function maybe_notice(): void     // admin_init: key-expiry (<30d) admin notice + clone guard (home_url != connected_siteurl -> clear key, admin notice)
public static function handle_push_now(): void  // admin-post 'guardlms_push_now', check_admin_referer('guardlms_push_now'), current_user_can('manage_options'), run Pusher::push, redirect back w/ result notice
```
Settings fields: enabled, baseurl(url, https only), pushpath, apikey (password; saved via Credentials, NOT in settings array), sendconfig, verificationtoken. On saving apikey also set connected_siteurl = rtrim(home_url(),'/'). Show read-only "Register this URL in GuardLMS: <home_url>" + lastpush/lastpushstatus status.

### GuardLMS_Plugin  (includes/class-guardlms-plugin.php)
```
public static function boot(): void   // require class files; wire hooks:
```
Hooks wired in boot(): `init` -> (nothing that loads textdomain early; WP.org auto-loads translations, so NO load_plugin_textdomain unless needed — if used, hook on 'init'); cache webserver to Options during web request (if !is_admin ok, guard with isset $_SERVER['SERVER_SOFTWARE']); `wp_head` -> GuardLMS_Head_Injector::render; `admin_menu` -> GuardLMS_Settings::register; `admin_init` -> GuardLMS_Settings::register (settings) + GuardLMS_Settings::maybe_notice; `admin_post_guardlms_push_now` -> handle_push_now; DAILY_HOOK -> GuardLMS_Cron::run_daily; INITIAL_HOOK -> GuardLMS_Cron::run_initial. Also register_activation_hook (Credentials::ensure_option + Options::ensure_option + Cron::schedule), register_deactivation_hook (Cron::unschedule).

## guardlms.php (main file)
Standard WP plugin header (Plugin Name: GuardLMS, Version, Requires at least: 6.0, Requires PHP: 7.4,
License GPLv3, Text Domain guardlms). Define constants. `require_once` all includes (or a small loader).
`register_activation_hook`/`register_deactivation_hook`. `add_action('plugins_loaded', ['GuardLMS_Plugin','boot'])`.
Guard: `defined('ABSPATH') || exit;`

## uninstall.php
`defined('WP_UNINSTALL_PLUGIN') || exit;` Hardcode keys 'guardlms_settings','guardlms_credentials'.
delete_option + delete_site_option both. Multisite: loop get_sites() -> switch_to_blog -> delete_option -> restore_current_blog.
wp_clear_scheduled_hook('guardlms_daily_push') and ('guardlms_initial_push').

## Build config
- composer.json: require-dev squizlabs/php_codesniffer, wp-coding-standards/wpcs, dealerdirect/phpcodesniffer-composer-installer, phpunit/phpunit ^9, brain/monkey, mockery/mockery. scripts: phpcs, phpunit.
- phpcs.xml.dist: ruleset WordPress-Extra, text-domain guardlms, prefix GuardLMS/guardlms, testVersion 7.4-.
- phpunit.xml.dist + tests/bootstrap.php (Brain Monkey setup or WP test lib).
- Min versions: Requires at least WP 6.0, Requires PHP 7.4, Tested up to 6.7.
```

## Phase 2 — keyless Connect / OAuth (branch feat/phase2-connect)

Adds a one-click, keyless Connect flow (OAuth authorization-code + `state`) so an admin never
copies an API key. The Phase 1 manual API-key path in GuardLMS_Settings stays fully intact; Connect
simply populates the same options/credentials programmatically.

### Flow (summary)
1. Admin clicks Connect on the Connect page (nonce + manage_options admin-post `guardlms_connect_start`).
2. `GuardLMS_Connect_Manager::start_connect()` mints `state` = bin2hex(random_bytes(20)) (40 hex),
   stores state + 900s expiry + the CURRENT baseurl BOUND to the attempt, and redirects the browser
   (wp_redirect — external host) to `{baseurl}/connect/wordpress?siteurl=&state=&callback=`
   where `callback` = `rest_url('guardlms/v1/connect-callback')` (rawurlencoded; add_query_arg does
   not encode values).
3. Backend consent screen mints a one-time `code` and redirects the browser back to the callback URL
   with `code`+`state` appended (query-aware `?`/`&` separator).
4. Public REST route `GuardLMS_Rest::handle_callback()` reads code+state and calls
   `GuardLMS_Connect_Manager::complete_connect()`, then wp_safe_redirect()s to the Connect page with a
   success/error notice (via a short transient). It never emits JSON to the browser.
5. `complete_connect()` validates state (single-use / TTL / hash_equals), then
   `GuardLMS_Api_Client::exchange()` POSTs `{code,siteurl,state}` to `{bound baseurl}/api/integrations/exchange`.
6. On a 2xx `{ "data": { token, pushpath?, verification_token?, website_id, expires_at } }` response it
   stores token -> Credentials, and pushpath/verificationtoken/websiteid/keyexpiresat/connectedat/
   connected_siteurl/enabled -> Options; queues an immediate push (GUARDLMS_INITIAL_HOOK, +5s); purges caches.
7. Backend later fetches the homepage `<meta name="guardlms-verification">` (Phase 1 head-injector already
   renders it once verificationtoken + enabled are set) and/or DNS-TXT to auto-confirm ownership.

### Auth model (why the callback is public)
`permission_callback => __return_true`. A top-level browser redirect cannot carry the `X-WP-Nonce`
that WP REST cookie auth needs, so a capability check is not implementable on the callback. The
security control is the single-use `state`: created only inside `start_connect()` (which IS gated by
manage_options + a nonce), stored server-side, 900s TTL, hash_equals compared, and CONSUMED (cleared)
before the exchange. The baseurl is BOUND into the state record so a later settings edit cannot
redirect the exchange to another host. The push key is never logged.

### New option keys (added to `guardlms_settings` defaults)
- `connectstate` (string, default '')          — pending state (40 hex), cleared on use
- `connectstateexpires` (int ts, default 0)     — state expiry (time()+900)
- `connectstate_baseurl` (string, default '')   — baseurl BOUND to the attempt
- `websiteid` (int, default 0)                  — GuardLMS website id from the exchange
- `connectedat` (int ts, default 0)             — when the Connect flow last succeeded (>0 == connected)

### REST route
- namespace `guardlms/v1`, route `/connect-callback`, method GET, `permission_callback => __return_true`.
- pretty permalinks -> `/wp-json/guardlms/v1/connect-callback`; plain -> `/?rest_route=/guardlms/v1/connect-callback`.

### New classes / signatures
```
GuardLMS_Api_Client        (includes/class-guardlms-api-client.php)
  const EXCHANGE_PATH = '/api/integrations/exchange';
  public static function exchange( string $code, string $siteurl, string $state, string $baseurl ): array|WP_Error
    // POST {code,siteurl,state} via GuardLMS_Http::post to rtrim(baseurl,'/').EXCHANGE_PATH;
    // 2xx + json_decode + require data.token, else WP_Error. Returns the inner `data` array.

GuardLMS_Connect_Manager   (includes/class-guardlms-connect-manager.php)
  const STATE_TTL = 900;
  public static function start_connect(): string           // mint+store state/expiry/bound baseurl; return consent URL
  public static function complete_connect( string $code, string $state ): true|WP_Error
        // ALWAYS clears connectstate/expires first (single use); '' || !hash_equals || expired -> WP_Error('guardlms_connectstate');
        // exchange(bound baseurl); on success store creds+options, queue initial push (+5s), purge_caches().
  public static function is_connected(): bool               // has_key() && connectedat>0
  public static function disconnect(): void                 // Credentials::delete(); clear connectedat/websiteid/verificationtoken/keyexpiresat/connected_siteurl
  public static function purge_caches(): void               // w3tc_flush_all / litespeed_purge_all / rocket_clean_domain / wp_cache_clear_cache (function_exists guarded)

GuardLMS_Rest              (includes/class-guardlms-rest.php)
  const REST_NAMESPACE = 'guardlms/v1'; const CALLBACK_ROUTE = '/connect-callback';
  public static function register(): void                  // rest_api_init: register connect-callback (GET, __return_true)
  public static function handle_callback( $request ): void // sanitize code(alnum<=64)+state(alnum,40); complete_connect(); set notice transient; wp_safe_redirect to Connect page; exit

GuardLMS_Connect_Page     (includes/admin/class-guardlms-connect-page.php)
  const PAGE = 'guardlms-connect'; const START_ACTION='guardlms_connect_start'; const DISCONNECT_ACTION='guardlms_disconnect'; const NOTICE_TRANSIENT='guardlms_connect_notice';
  public static function register(): void                  // add_submenu_page under Settings (manage_options)
  public static function render_page(): void               // manage_options gate; status (is_connected, websiteid, connectedat, keyexpiresat, lastpush) + Connect/Reconnect + Disconnect nonce forms
  public static function handle_start(): void              // check_admin_referer(START_ACTION)+manage_options; wp_redirect(start_connect()) [external]; exit
  public static function handle_disconnect(): void         // check_admin_referer(DISCONNECT_ACTION)+manage_options; disconnect(); wp_safe_redirect back; exit
```

### Hooks added in GuardLMS_Plugin::boot()
`rest_api_init` -> GuardLMS_Rest::register; `admin_menu` -> GuardLMS_Connect_Page::register;
`admin_post_guardlms_connect_start` -> handle_start; `admin_post_guardlms_disconnect` -> handle_disconnect.
The 4 new files are require_once'd in GuardLMS_Plugin::load(). Head-injector: NO change.
