<?php
/**
 * GuardLMS bootstrap: loads the plugin classes and wires WordPress hooks.
 *
 * @package GuardLMS
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
		require_once GUARDLMS_PLUGIN_DIR . 'includes/admin/class-guardlms-settings.php';
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

		// Admin settings page + Settings API registration + admin notices.
		add_action( 'admin_menu', array( 'GuardLMS_Settings', 'register' ) );
		add_action( 'admin_init', array( 'GuardLMS_Settings', 'register' ) );
		add_action( 'admin_init', array( 'GuardLMS_Settings', 'maybe_notice' ) );
		add_action( 'admin_post_guardlms_push_now', array( 'GuardLMS_Settings', 'handle_push_now' ) );

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
