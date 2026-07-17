<?php
/**
 * Unit tests for GuardLMS_Collector::build_payload() (AC2: payload shape,
 * inventory mapping, no secrets).
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

use Brain\Monkey\Functions;

require_once __DIR__ . '/AbstractGuardLMSTestCase.php';
require_once GUARDLMS_PLUGIN_DIR . 'includes/class-guardlms-options.php';
require_once GUARDLMS_PLUGIN_DIR . 'includes/class-guardlms-collector.php';

/**
 * @covers GuardLMS_Collector
 */
final class CollectorTest extends AbstractGuardLMSTestCase {

	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['wp_version'] = '6.7.1';

		$_SERVER['SERVER_SOFTWARE'] = 'nginx/1.25.3';

		// Inventory: two folder plugins, one root single-file plugin, one
		// mu-plugin, one dropin.
		Functions\when( 'get_plugins' )->justReturn(
			array(
				'akismet/akismet.php' => array(
					'Name'    => 'Akismet Anti-Spam',
					'Version' => '5.3',
				),
				'hello.php'           => array(
					'Name'    => 'Hello Dolly',
					'Version' => '1.7.2',
				),
				'jetpack/jetpack.php' => array(
					'Name'    => 'Jetpack',
					'Version' => '13.0',
				),
			)
		);
		Functions\when( 'get_mu_plugins' )->justReturn(
			array(
				'0-guardlms-loader.php' => array(
					'Name'    => 'GuardLMS Loader',
					'Version' => '1.0',
				),
			)
		);
		Functions\when( 'get_dropins' )->justReturn(
			array(
				'object-cache.php' => array(
					'Name'    => 'Object Cache',
					'Version' => '2.0',
				),
			)
		);

		// akismet is single-site active; jetpack is network active.
		Functions\when( 'get_site_option' )->justReturn( array( 'jetpack/jetpack.php' => 1700000000 ) );

		Functions\when( 'wp_get_themes' )->justReturn(
			array(
				'twentytwentyfour' => new GuardLMS_Test_Theme(
					array(
						'Name'    => 'Twenty Twenty-Four',
						'Version' => '1.2',
					)
				),
				'storefront'       => new GuardLMS_Test_Theme(
					array(
						'Name'    => 'Storefront',
						'Version' => '4.5.6',
					)
				),
			)
		);
		Functions\when( 'get_stylesheet' )->justReturn( 'twentytwentyfour' );

		Functions\when( 'home_url' )->justReturn( 'https://example.test/' );
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'get_locale' )->justReturn( 'en_US' );
		Functions\when( 'wp_timezone_string' )->justReturn( 'Europe/Amsterdam' );
		Functions\when( 'sanitize_text_field' )->returnArg( 1 );
		Functions\when( 'wp_unslash' )->returnArg( 1 );

		$this->stubOptions( array() );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wp_version'], $_SERVER['SERVER_SOFTWARE'] );
		parent::tearDown();
	}

	/**
	 * Route get_option() reads: settings and the config allowlist options.
	 *
	 * @param array $settings Stored guardlms_settings value.
	 * @return void
	 */
	private function stubOptions( array $settings ): void {
		Functions\when( 'get_option' )->alias(
			static function ( $name, $default = false ) use ( $settings ) {
				switch ( $name ) {
					case 'guardlms_settings':
						return $settings;
					case 'active_plugins':
						return array( 'akismet/akismet.php' );
					case 'users_can_register':
						return 1;
					case 'default_role':
						return 'subscriber';
					case 'blog_public':
						return 1;
					default:
						return $default;
				}
			}
		);
	}

	public function test_top_level_shape_and_wordpress_section(): void {
		$payload = GuardLMS_Collector::build_payload();

		$this->assertSame( 'wordpress', $payload['platform'] );
		$this->assertSame( 'https://example.test', $payload['siteurl'] );
		$this->assertIsInt( $payload['generatedtime'] );

		$this->assertSame( '6.7.1', $payload['wordpress']['version'] );
		$this->assertFalse( $payload['wordpress']['multisite'] );
		$this->assertSame( 'en_US', $payload['wordpress']['locale'] );

		// plugincount equals the flattened plugins + mu-plugins + dropins count.
		$this->assertCount( $payload['wordpress']['plugincount'], $payload['wordpress']['plugins'] );
		$this->assertSame( 5, $payload['wordpress']['plugincount'] );
	}

	public function test_plugin_slug_kind_and_active_flags(): void {
		$payload = GuardLMS_Collector::build_payload();
		$plugins = $this->indexBy( $payload['wordpress']['plugins'], 'file' );

		$akismet = $plugins['akismet/akismet.php'];
		$this->assertSame( 'akismet', $akismet['slug'] );
		$this->assertSame( 'Akismet Anti-Spam', $akismet['name'] );
		$this->assertSame( '5.3', $akismet['version'] );
		$this->assertSame( 'plugin', $akismet['kind'] );
		$this->assertFalse( $akismet['isstandard'] );
		$this->assertSame( 1, $akismet['enabled'] );
		$this->assertSame( 0, $akismet['networkactive'] );

		// Root single-file plugin: slug falls back to basename without extension.
		$hello = $plugins['hello.php'];
		$this->assertSame( 'hello', $hello['slug'] );
		$this->assertSame( 0, $hello['enabled'] );

		// Network-activated plugin: networkactive=1, not single-site enabled.
		$jetpack = $plugins['jetpack/jetpack.php'];
		$this->assertSame( 'jetpack', $jetpack['slug'] );
		$this->assertSame( 0, $jetpack['enabled'] );
		$this->assertSame( 1, $jetpack['networkactive'] );
	}

	public function test_mu_plugins_and_dropins_present_and_enabled(): void {
		$payload = GuardLMS_Collector::build_payload();
		$plugins = $this->indexBy( $payload['wordpress']['plugins'], 'file' );

		$mu = $plugins['0-guardlms-loader.php'];
		$this->assertSame( 'muplugin', $mu['kind'] );
		$this->assertSame( '0-guardlms-loader', $mu['slug'] );
		$this->assertSame( 1, $mu['enabled'] );

		$dropin = $plugins['object-cache.php'];
		$this->assertSame( 'dropin', $dropin['kind'] );
		$this->assertSame( 'object-cache', $dropin['slug'] );
		$this->assertSame( 1, $dropin['enabled'] );
	}

	public function test_themes_present_with_active_flag(): void {
		$payload = GuardLMS_Collector::build_payload();
		$themes  = $this->indexBy( $payload['wordpress']['themes'], 'slug' );

		$this->assertCount( 2, $payload['wordpress']['themes'] );

		$this->assertSame( 'Twenty Twenty-Four', $themes['twentytwentyfour']['name'] );
		$this->assertSame( 'theme', $themes['twentytwentyfour']['kind'] );
		$this->assertTrue( $themes['twentytwentyfour']['active'] );

		$this->assertFalse( $themes['storefront']['active'] );
	}

	public function test_server_and_php_sections_present(): void {
		$payload = GuardLMS_Collector::build_payload();

		$this->assertSame( PHP_OS_FAMILY, $payload['server']['os_family'] );
		$this->assertSame( PHP_OS, $payload['server']['os'] );
		$this->assertArrayHasKey( 'hostname', $payload['server'] );
		// Falls back to $_SERVER['SERVER_SOFTWARE'] when no cached webserver.
		$this->assertSame( 'nginx/1.25.3', $payload['server']['webserver'] );

		$this->assertSame( PHP_VERSION, $payload['php']['version'] );
		$this->assertSame( 'Europe/Amsterdam', $payload['php']['timezone'] );
		$this->assertIsArray( $payload['php']['extensions'] );
		// Extensions are sorted.
		$sorted = $payload['php']['extensions'];
		$copy   = $sorted;
		sort( $copy );
		$this->assertSame( $copy, $sorted );
	}

	public function test_webserver_prefers_cached_option_value(): void {
		$this->stubOptions( array( 'webserver' => 'apache/2.4-cached' ) );

		$payload = GuardLMS_Collector::build_payload();

		$this->assertSame( 'apache/2.4-cached', $payload['server']['webserver'] );
	}

	public function test_config_absent_by_default_present_on_opt_in(): void {
		$without = GuardLMS_Collector::build_payload( false );
		$this->assertArrayNotHasKey( 'config', $without );

		$with = GuardLMS_Collector::build_payload( true );
		$this->assertArrayHasKey( 'config', $with );
		// Allowlisted WordPress options resolve as strings.
		$this->assertSame( '1', $with['config']['users_can_register'] );
		$this->assertSame( 'subscriber', $with['config']['default_role'] );
		$this->assertSame( '1', $with['config']['blog_public'] );
	}

	public function test_webserver_is_null_when_neither_cached_nor_in_server_global(): void {
		unset( $_SERVER['SERVER_SOFTWARE'] );
		$this->stubOptions( array() );

		$payload = GuardLMS_Collector::build_payload();

		$this->assertNull( $payload['server']['webserver'] );
	}

	/**
	 * The config allowlist resolves uppercase names as constants (winning over
	 * options) and stringifies bool/null/array values. Defining constants is
	 * global + irreversible, so this runs in an isolated process.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_config_resolves_constants_and_stringifies_values(): void {
		define( 'WP_DEBUG', true );
		define( 'FORCE_SSL_ADMIN', null );
		define( 'BLOG_PUBLIC', array( 'nested' => 1 ) );

		Functions\when( 'wp_json_encode' )->alias(
			static function ( $data ) {
				return json_encode( $data );
			}
		);

		$config = GuardLMS_Collector::build_payload( true )['config'];

		// Boolean constant -> '1'.
		$this->assertSame( '1', $config['WP_DEBUG'] );
		// Null constant -> ''.
		$this->assertSame( '', $config['force_ssl_admin'] );
		// Array constant -> JSON string.
		$this->assertSame( '{"nested":1}', $config['blog_public'] );
		// Names without a defined constant fall back to options.
		$this->assertSame( 'subscriber', $config['default_role'] );

		$this->assertNoSecrets( $config, 'config' );
	}

	public function test_payload_contains_no_secret_looking_keys_or_values(): void {
		$this->assertNoSecrets( GuardLMS_Collector::build_payload( true ) );
	}

	/**
	 * Reindex a list of associative rows by one of their columns.
	 *
	 * @param array[] $rows   Rows to index.
	 * @param string  $column Column to key by.
	 * @return array<string,array>
	 */
	private function indexBy( array $rows, string $column ): array {
		$out = array();
		foreach ( $rows as $row ) {
			$out[ $row[ $column ] ] = $row;
		}
		return $out;
	}
}
