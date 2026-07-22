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
 * @package    GuardLMS
 * @copyright  2026 Luuk Verhoeven, ldesignmedia.nl <info@ldesignmedia.nl>
 * @author     Hamza Tamyachte
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
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

		list( $webserver_name, $webserver_version ) = self::split_server_signature( (string) $webserver );

		// os_family, os and webserver stay as they were: an older GuardLMS keeps
		// reading them while the split fields below are what CVE matching needs.
		return array_merge(
			array(
				'os_family'         => PHP_OS_FAMILY,
				'os'                => PHP_OS,
				'hostname'          => false !== $hostname ? $hostname : null,
				'webserver'         => $webserver,
				'webserver_name'    => $webserver_name,
				'webserver_version' => $webserver_version,
			),
			self::collect_os()
		);
	}

	/**
	 * Distribution level operating system detail.
	 *
	 * PHP only reports the kernel ("Linux"), which is not enough to match an OS
	 * against known vulnerabilities or an end-of-life date. On Linux the release
	 * is read from the os-release file, the standard the distributions publish.
	 * Reads only: no shell commands, so it also works where exec() is disabled.
	 *
	 * @return array The operating system fields.
	 */
	private static function collect_os(): array {
		$info = array(
			'os_name'    => PHP_OS_FAMILY,
			'os_id'      => strtolower( PHP_OS_FAMILY ),
			'os_version' => '',
			'os_pretty'  => '',
			'kernel'     => php_uname( 'r' ),
			'arch'       => php_uname( 'm' ),
		);

		$release = self::os_release_values();

		if ( $release ) {
			$info['os_name']    = isset( $release['NAME'] ) ? $release['NAME'] : $info['os_name'];
			$info['os_id']      = isset( $release['ID'] ) ? $release['ID'] : $info['os_id'];
			$info['os_version'] = isset( $release['VERSION_ID'] ) ? $release['VERSION_ID'] : '';
			$info['os_pretty']  = isset( $release['PRETTY_NAME'] ) ? $release['PRETTY_NAME'] : '';
		} elseif ( 'Darwin' === PHP_OS_FAMILY ) {
			// macOS has no os-release file; the Darwin kernel version is the only
			// version PHP exposes without shelling out to sw_vers.
			$info['os_name']    = 'macOS';
			$info['os_id']      = 'macos';
			$info['os_version'] = php_uname( 'r' );
		} elseif ( 'Windows' === PHP_OS_FAMILY ) {
			$info['os_name']    = php_uname( 's' );
			$info['os_id']      = 'windows';
			$info['os_version'] = php_uname( 'r' );
		}

		if ( '' === $info['os_pretty'] ) {
			$info['os_pretty'] = trim( $info['os_name'] . ' ' . $info['os_version'] );
		}

		return $info;
	}

	/**
	 * Parse /etc/os-release into key => value pairs.
	 *
	 * @return array Empty when the file is absent or unreadable.
	 */
	private static function os_release_values(): array {
		$values = array();

		foreach ( array( '/etc/os-release', '/usr/lib/os-release' ) as $path ) {
			if ( ! is_readable( $path ) ) {
				continue;
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.PHP.NoSilencedErrors.Discouraged -- Local system file, not a remote request, and a race with an unreadable file must not warn during a cron push.
			$contents = @file_get_contents( $path );
			if ( false === $contents ) {
				continue;
			}

			foreach ( preg_split( '/\R/', $contents ) as $line ) {
				if ( false === strpos( $line, '=' ) || 0 === strpos( trim( $line ), '#' ) ) {
					continue;
				}
				list( $key, $value )    = explode( '=', $line, 2 );
				$values[ trim( $key ) ] = trim( trim( $value ), "\"'" );
			}

			if ( $values ) {
				return $values;
			}
		}

		return $values;
	}

	/**
	 * Split a web server signature into its product name and version.
	 *
	 * "Apache/2.4.68 (Debian)" becomes array( 'Apache', '2.4.68' ), "nginx/1.24.0"
	 * becomes array( 'nginx', '1.24.0' ). A signature without a version keeps the
	 * name and returns an empty version.
	 *
	 * @param string $signature Raw SERVER_SOFTWARE value.
	 * @return array Name and version, both possibly empty strings.
	 */
	private static function split_server_signature( string $signature ): array {
		$signature = trim( $signature );
		if ( '' === $signature ) {
			return array( '', '' );
		}

		// Only the leading product token matters; the rest is "(Debian) PHP/8.2".
		$product = strtok( $signature, ' ' );
		if ( false === $product ) {
			return array( '', '' );
		}

		if ( false === strpos( $product, '/' ) ) {
			return array( $product, '' );
		}

		return explode( '/', $product, 2 );
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
