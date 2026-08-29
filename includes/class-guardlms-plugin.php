<?php
/**
 * GuardLMS bootstrap: loads the plugin classes and wires WordPress hooks.
 *
 * @package    GuardLMS
 * @copyright  2026 Luuk Verhoeven, ldesignmedia.nl <info@ldesignmedia.nl>
 * @author     Hamza Tamyachte
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * GuardLMS is free software: you can redistribute it and/or modify it under the
 * terms of the GNU General Public License as published by the Free Software
 * Foundation, either version 3 of the License, or (at your option) any later
 * version.
 *
 * This program is distributed in the hope that it will be useful, but WITHOUT
 * ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS
 * FOR A PARTICULAR PURPOSE. See the GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along with
 * this program. If not, see <https://www.gnu.org/licenses/>.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Central bootstrap for the GuardLMS plugin.
 */
class GuardLMS_Plugin {

	/**
	 * Require the plugin's class files. Idempotent (require_once).
	 *
	 * @return void
	 */
	public static function load() {
		require_once GUARDLMS_PLUGIN_DIR . 'includes/class-guardlms-options.php';
		require_once GUARDLMS_PLUGIN_DIR . 'includes/class-guardlms-credentials.php';
		require_once GUARDLMS_PLUGIN_DIR . 'includes/class-guardlms-collector.php';
		require_once GUARDLMS_PLUGIN_DIR . 'includes/class-guardlms-http.php';
		require_once GUARDLMS_PLUGIN_DIR . 'includes/class-guardlms-pusher.php';
		require_once GUARDLMS_PLUGIN_DIR . 'includes/class-guardlms-cron.php';
		require_once GUARDLMS_PLUGIN_DIR . 'includes/class-guardlms-head-injector.php';
		require_once GUARDLMS_PLUGIN_DIR . 'includes/class-guardlms-api-client.php';
		require_once GUARDLMS_PLUGIN_DIR . 'includes/class-guardlms-sdk-config.php';
		require_once GUARDLMS_PLUGIN_DIR . 'includes/class-guardlms-sdk-status.php';
		require_once GUARDLMS_PLUGIN_DIR . 'includes/class-guardlms-sdk-client.php';
		require_once GUARDLMS_PLUGIN_DIR . 'includes/class-guardlms-sdk-injector.php';
		require_once GUARDLMS_PLUGIN_DIR . 'includes/class-guardlms-connect-manager.php';
		require_once GUARDLMS_PLUGIN_DIR . 'includes/class-guardlms-rest.php';
		require_once GUARDLMS_PLUGIN_DIR . 'includes/admin/class-guardlms-settings.php';
		require_once GUARDLMS_PLUGIN_DIR . 'includes/admin/class-guardlms-connect-page.php';
		require_once GUARDLMS_PLUGIN_DIR . 'includes/admin/class-guardlms-realtime-page.php';
	}

	/**
	 * Load classes and wire all runtime hooks. Fired on plugins_loaded.
	 *
	 * @return void
	 */
	public static function boot() {
		self::load();

		// Front-end ownership meta tag (backend fetches the site root).
		add_action( 'wp_head', array( 'GuardLMS_Head_Injector', 'render' ) );

		// Real-time monitoring SDK. Priority 1 puts it ahead of every other
		// ENQUEUED script; see the GuardLMS_Sdk_Injector class docblock for what
		// that does and does not guarantee. The script_loader_tag filter keeps
		// optimizer plugins from deferring it back into uselessness.
		add_action( 'wp_enqueue_scripts', array( 'GuardLMS_Sdk_Injector', 'enqueue' ), 1 );
		add_filter( 'script_loader_tag', array( 'GuardLMS_Sdk_Injector', 'filter_script_tag' ), 10, 2 );

		// Admin settings page + Settings API registration + admin notices.
		add_action( 'admin_menu', array( 'GuardLMS_Settings', 'register' ) );
		add_action( 'admin_init', array( 'GuardLMS_Settings', 'register' ) );
		add_action( 'admin_init', array( 'GuardLMS_Settings', 'maybe_notice' ) );
		add_action( 'admin_enqueue_scripts', array( 'GuardLMS_Settings', 'enqueue_assets' ) );

		// Purge page caches AFTER the settings write lands, never during
		// sanitize() - see GuardLMS_Settings::maybe_purge_on_toggle().
		add_action( 'update_option_' . GuardLMS_Options::OPTION, array( 'GuardLMS_Settings', 'maybe_purge_on_toggle' ), 10, 2 );
		add_action( 'admin_post_guardlms_push_now', array( 'GuardLMS_Settings', 'handle_push_now' ) );

		// Phase 2 keyless Connect: REST callback and the admin-post actions. The
		// connect UI itself is rendered inside the single GuardLMS settings page.
		add_action( 'rest_api_init', array( 'GuardLMS_Rest', 'register' ) );
		add_action( 'admin_post_guardlms_connect_start', array( 'GuardLMS_Connect_Page', 'handle_start' ) );
		add_action( 'admin_post_guardlms_disconnect', array( 'GuardLMS_Connect_Page', 'handle_disconnect' ) );

		// Real-time monitoring admin actions (nonce + manage_options guarded).
		add_action( 'admin_post_guardlms_sdk_refresh', array( 'GuardLMS_Realtime_Page', 'handle_refresh' ) );
		add_action( 'admin_post_guardlms_sdk_selftest', array( 'GuardLMS_Realtime_Page', 'handle_selftest' ) );
		add_action( 'admin_post_guardlms_sdk_rotate', array( 'GuardLMS_Realtime_Page', 'handle_rotate' ) );

		// WP-Cron push handlers.
		add_action( GUARDLMS_DAILY_HOOK, array( 'GuardLMS_Cron', 'run_daily' ) );
		add_action( GUARDLMS_INITIAL_HOOK, array( 'GuardLMS_Cron', 'run_initial' ) );

		// Cache the web server signature while it is reliably present (web request).
		self::maybe_cache_webserver();
	}

	/**
	 * Persist $_SERVER['SERVER_SOFTWARE'] during a front-end web request so the
	 * collector can report it later from WP-Cron (where SERVER_SOFTWARE may be absent).
	 *
	 * @return void
	 */
	private static function maybe_cache_webserver() {
		if ( is_admin() ) {
			return;
		}
		if ( ! isset( $_SERVER['SERVER_SOFTWARE'] ) ) {
			return;
		}

		$software = sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) );
		if ( '' === $software ) {
			return;
		}

		// Only write when the cached value changed, to avoid a DB write per request.
		if ( GuardLMS_Options::get( 'webserver' ) === $software ) {
			return;
		}

		GuardLMS_Options::set( 'webserver', $software );
	}

	/**
	 * Activation: seed options and schedule the jittered daily push.
	 *
	 * @return void
	 */
	public static function activate() {
		self::load();
		GuardLMS_Options::ensure_option();
		GuardLMS_Credentials::ensure_option();
		GuardLMS_Cron::schedule();
	}

	/**
	 * Deactivation: clear scheduled push hooks. Options are kept until uninstall.
	 *
	 * @return void
	 */
	public static function deactivate() {
		self::load();
		GuardLMS_Cron::unschedule();
	}
}
