<?php
/**
 * GuardLMS inventory + environment collector.
 *
 * Builds the typed JSON payload (WordPress core version, full plugin/theme
 * inventory with versions, server + PHP environment, and an optional opt-in
 * config section) that GuardLMS pushes to the backend for CVE/security
 * monitoring. The payload contains NO secrets: no API keys, no database
 * credentials, no salts.
 *
 * @package GuardLMS
 * @license GPL-3.0-or-later
 */

/**
 * GuardLMS
 * Copyright (C) 2026 LdesignMedia.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Assembles the GuardLMS push payload from WordPress-native primitives.
 */
class GuardLMS_Collector {

	/**
	 * Allowlist of non-secret config keys, reported only on explicit opt-in.
	 *
	 * Uppercase names resolve as PHP/wp-config constants (e.g. WP_DEBUG,
	 * FORCE_SSL_ADMIN); the remaining lowercase names resolve as WordPress
	 * options. NEVER add secrets (keys, salts, credentials) to this list.
	 *
	 * @var string[]
	 */
	const CONFIG_KEYS = array(
		'WP_DEBUG',
		'force_ssl_admin',
		'users_can_register',
		'default_role',
		'blog_public',
	);

	/**
	 * Build the full GuardLMS push payload.
	 *
	 * @param bool $include_config Whether to append the opt-in config section.
	 * @return array The typed payload. Contains NO secrets.
	 */
	public static function build_payload( bool $include_config = false ): array {
		global $wp_version;

		$plugins = self::collect_plugins();

		$payload = array(
			'platform'      => 'wordpress',
			'siteurl'       => rtrim( home_url(), '/' ),
			'generatedtime' => time(),
			'wordpress'     => array(
				'version'     => (string) $wp_version,
				'multisite'   => is_multisite(),
				'locale'      => get_locale(),
				'plugincount' => count( $plugins ),
				'plugins'     => $plugins,
				'themes'      => self::collect_themes(),
			),
			'server'        => self::collect_server(),
			'php'           => self::collect_php(),
		);

		if ( $include_config ) {
			$payload['config'] = self::collect_config();
		}

		return $payload;
	}

	/**
	 * Collect the full installed inventory: plugins, mu-plugins and dropins.
	 *
	 * The `plugin.php` admin include is required first because get_plugins()
	 * and friends are undefined in cron/CLI contexts (a fatal, not an empty
	 * result). Each source is flattened into one list; `plugincount` is the
	 * count of this list.
	 *
	 * @return array[] List of plugin entries.
	 */
	private static function collect_plugins(): array {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		$active         = (array) get_option( 'active_plugins', array() );
		$network_active = (array) get_site_option( 'active_sitewide_plugins', array() );

		$plugins = array();

		foreach ( get_plugins() as $file => $data ) {
			$plugins[] = self::plugin_entry(
				$file,
				$data,
				'plugin',
				in_array( $file, $active, true ) ? 1 : 0,
				isset( $network_active[ $file ] ) ? 1 : 0
			);
		}

		foreach ( get_mu_plugins() as $file => $data ) {
			$plugins[] = self::plugin_entry( $file, $data, 'muplugin', 1, 0 );
		}

		foreach ( get_dropins() as $file => $data ) {
			$plugins[] = self::plugin_entry( $file, $data, 'dropin', 1, 0 );
		}

		return $plugins;
	}

	/**
	 * Normalise a single plugin/mu-plugin/dropin into a payload entry.
	 *
	 * The CVE match key is the bare folder slug (`dirname( $file )`); root
	 * single-file plugins have no folder, so they fall back to the filename
	 * without its extension.
	 *
	 * @param string $file          Plugin file path relative to its source dir.
	 * @param array  $data          Plugin header data (Name, Version, ...).
	 * @param string $kind          One of 'plugin', 'muplugin', 'dropin'.
	 * @param int    $enabled       1 when active, else 0.
	 * @param int    $networkactive 1 when network-activated, else 0.
	 * @return array The normalised plugin entry.
	 */
	private static function plugin_entry( string $file, array $data, string $kind, int $enabled, int $networkactive ): array {
		$dir  = dirname( $file );
		$slug = ( '.' === $dir ) ? basename( $file, '.php' ) : $dir;

		return array(
			'slug'          => $slug,
			'file'          => $file,
			'name'          => isset( $data['Name'] ) ? (string) $data['Name'] : '',
			'version'       => isset( $data['Version'] ) ? (string) $data['Version'] : '',
			'kind'          => $kind,
			'isstandard'    => false,
			'enabled'       => $enabled,
			'networkactive' => $networkactive,
		);
	}

	/**
	 * Collect installed themes and flag the active stylesheet.
	 *
	 * @return array[] List of theme entries.
	 */
	private static function collect_themes(): array {
		$current = get_stylesheet();
		$themes  = array();

		foreach ( wp_get_themes() as $slug => $theme ) {
			$themes[] = array(
				'slug'    => $slug,
				'name'    => (string) $theme->get( 'Name' ),
				'version' => (string) $theme->get( 'Version' ),
				'kind'    => 'theme',
				'active'  => ( $slug === $current ),
			);
		}

		return $themes;
	}

	/**
	 * Collect the server environment.
	 *
	 * `webserver` prefers the value cached to options during a web request
	 * (system-cron WP-Cron may lack `$_SERVER['SERVER_SOFTWARE']`), falling
	 * back to the live server variable, else null.
	 *
	 * @return array The server section.
	 */
	private static function collect_server(): array {
		$webserver = GuardLMS_Options::get( 'webserver' );

		if ( null === $webserver || '' === $webserver ) {
			$webserver = isset( $_SERVER['SERVER_SOFTWARE'] )
				? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) )
				: null;
		}

		$hostname = gethostname();

		return array(
			'os_family' => PHP_OS_FAMILY,
			'os'        => PHP_OS,
			'hostname'  => false !== $hostname ? $hostname : null,
			'webserver' => $webserver,
		);
	}

	/**
	 * Collect the PHP runtime environment.
	 *
	 * @return array The php section.
	 */
	private static function collect_php(): array {
		$extensions = get_loaded_extensions();
		sort( $extensions );

		$ini = php_ini_loaded_file();

		return array(
			'version'             => PHP_VERSION,
			'sapi'                => php_sapi_name(),
			'ini'                 => false !== $ini ? $ini : 'none',
			'memory_limit'        => ini_get( 'memory_limit' ),
			'max_execution_time'  => ini_get( 'max_execution_time' ),
			'upload_max_filesize' => ini_get( 'upload_max_filesize' ),
			'post_max_size'       => ini_get( 'post_max_size' ),
			'timezone'            => wp_timezone_string(),
			'extensions'          => $extensions,
		);
	}

	/**
	 * Collect the opt-in config section from the allowlist.
	 *
	 * Each key is resolved as a constant when its uppercase form is defined
	 * (undefined constants are skipped), otherwise as a WordPress option
	 * (absent options are skipped). All values are cast to strings and NEVER
	 * include secrets.
	 *
	 * @return array<string,string> Config key/value pairs.
	 */
	private static function collect_config(): array {
		$config = array();

		foreach ( self::CONFIG_KEYS as $key ) {
			$constant = strtoupper( $key );

			if ( defined( $constant ) ) {
				$config[ $key ] = self::stringify( constant( $constant ) );
				continue;
			}

			$value = get_option( $key, null );

			if ( null !== $value ) {
				$config[ $key ] = self::stringify( $value );
			}
		}

		return $config;
	}

	/**
	 * Cast a scalar config value to its string representation.
	 *
	 * Booleans become '1'/'0' so a disabled flag is distinguishable from an
	 * empty string; null becomes an empty string.
	 *
	 * @param mixed $value The raw config value.
	 * @return string The string representation.
	 */
	private static function stringify( $value ): string {
		if ( is_bool( $value ) ) {
			return $value ? '1' : '0';
		}

		if ( null === $value ) {
			return '';
		}

		if ( is_scalar( $value ) ) {
			return (string) $value;
		}

		return (string) wp_json_encode( $value );
	}
}
