<?php
/**
 * Unit tests for GuardLMS_Pusher::push() (AC3 transport, AC4 degraded-push
 * guard, AC5 422 site-URL mismatch messaging).
 *
 * The pusher calls GuardLMS_Http::post(), a thin static wrapper over
 * wp_remote_post(). Since Brain Monkey stubs functions (not static methods),
 * the real wrapper runs and wp_remote_post() is stubbed with canned responses —
 * which is exactly the [code, body] contract the pusher consumes.
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
require_once GUARDLMS_PLUGIN_DIR . 'includes/class-guardlms-credentials.php';
require_once GUARDLMS_PLUGIN_DIR . 'includes/class-guardlms-collector.php';
require_once GUARDLMS_PLUGIN_DIR . 'includes/class-guardlms-http.php';
require_once GUARDLMS_PLUGIN_DIR . 'includes/class-guardlms-connect-manager.php';
require_once GUARDLMS_PLUGIN_DIR . 'includes/class-guardlms-pusher.php';

/**
 * @covers GuardLMS_Pusher
 */
final class PusherTest extends AbstractGuardLMSTestCase {

	/**
	 * Captured update_option() writes (name, value, autoload) tuples.
	 *
	 * @var array[]
	 */
	private $optionWrites = array();

	protected function setUp(): void {
		parent::setUp();
		$this->optionWrites    = array();
		$GLOBALS['wp_version'] = '6.7.1';

		Functions\when( 'home_url' )->justReturn( 'https://example.test' );
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'get_locale' )->justReturn( 'en_US' );
		Functions\when( 'wp_timezone_string' )->justReturn( 'UTC' );
		Functions\when( 'sanitize_text_field' )->returnArg( 1 );
		Functions\when( 'wp_unslash' )->returnArg( 1 );
		Functions\when( 'get_site_option' )->justReturn( array() );
		Functions\when( 'wp_get_themes' )->justReturn( array() );
		Functions\when( 'get_stylesheet' )->justReturn( 'twentytwentyfour' );
		Functions\when( 'wp_json_encode' )->alias(
			static function ( $data ) {
				return json_encode( $data );
			}
		);

		// Capture any settings write so success/no-write assertions are exact.
		Functions\when( 'update_option' )->alias(
			function ( $name, $value, $autoload = 'yes' ) {
				$this->optionWrites[] = array( $name, $value, $autoload );
				return true;
			}
		);
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wp_version'] );
		parent::tearDown();
	}

	/**
	 * Wire the environment: settings option, credentials, and plugin inventory.
	 *
	 * @param array  $settings     guardlms_settings overrides.
	 * @param string $key          Stored push key.
	 * @param array  $plugins      get_plugins() fixture.
	 * @return void
	 */
	private function stubEnvironment( array $settings, string $key, array $plugins ): void {
		$merged = array_merge(
			array(
				'enabled'           => true,
				'baseurl'           => 'https://dashboard.guardlms.com',
				'pushpath'          => '/api/externalpush/wordpress',
				'sendconfig'        => false,
				'verificationtoken' => '',
				'connected_siteurl' => '',
				'lastpush'          => 0,
				'lastpushstatus'    => 0,
				'keyexpiresat'      => 0,
				'last_plugincount'  => 0,
			),
			$settings
		);

		Functions\when( 'get_option' )->alias(
			static function ( $name, $default = false ) use ( $merged, $key ) {
				switch ( $name ) {
					case 'guardlms_settings':
						return $merged;
					case 'guardlms_credentials':
						return array( 'apikey' => $key );
					case 'active_plugins':
						return array();
					default:
						return $default;
				}
			}
		);

		Functions\when( 'get_plugins' )->justReturn( $plugins );
		Functions\when( 'get_mu_plugins' )->justReturn( array() );
		Functions\when( 'get_dropins' )->justReturn( array() );
	}

	/**
	 * A single-plugin fixture so plugincount is a positive number.
	 *
	 * @return array
	 */
	private function samplePlugins(): array {
		return array(
			'akismet/akismet.php' => array(
				'Name'    => 'Akismet',
				'Version' => '5.3',
			),
		);
	}

	private function stubHttpResponse( int $code, string $body ): void {
		Functions\when( 'wp_remote_post' )->justReturn( array( 'stubbed' => true ) );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( $code );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( $body );
	}

	private function settingsWrites(): array {
		return array_values(
			array_filter(
				$this->optionWrites,
				static function ( $write ) {
					return 'guardlms_settings' === $write[0];
				}
			)
		);
	}

	// --- Not configured (AC3) ------------------------------------------------

	public function test_returns_wp_error_when_key_missing(): void {
		$this->stubEnvironment( array(), '', $this->samplePlugins() );

		$result = GuardLMS_Pusher::push();

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'guardlms_notconfigured', $result->get_error_code() );
		$this->assertSame( array(), $this->settingsWrites() );
	}

	public function test_returns_wp_error_when_baseurl_missing(): void {
		$this->stubEnvironment( array( 'baseurl' => '' ), 'valid-key', $this->samplePlugins() );

		$result = GuardLMS_Pusher::push();

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'guardlms_notconfigured', $result->get_error_code() );
		$this->assertSame( array(), $this->settingsWrites() );
	}

	// --- Degraded-push guard (AC4) ------------------------------------------

	public function test_returns_wp_error_when_core_version_empty(): void {
		$GLOBALS['wp_version'] = '';
		$this->stubEnvironment( array(), 'valid-key', $this->samplePlugins() );

		$result = GuardLMS_Pusher::push();

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'guardlms_degraded', $result->get_error_code() );
		$this->assertSame( array(), $this->settingsWrites() );
	}

	public function test_returns_wp_error_on_implausible_plugincount_drop(): void {
		// Previously saw plugins; now the inventory is empty -> refuse to push.
		$this->stubEnvironment( array( 'last_plugincount' => 7 ), 'valid-key', array() );

		$result = GuardLMS_Pusher::push();

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'guardlms_degraded', $result->get_error_code() );
		$this->assertSame( array(), $this->settingsWrites() );
	}

	public function test_first_push_with_zero_plugins_is_allowed(): void {
		// last_plugincount 0 means no prior good inventory; zero now is not a drop.
		$this->stubEnvironment( array( 'last_plugincount' => 0 ), 'valid-key', array() );
		$this->stubHttpResponse( 200, '{"ok":true}' );

		$result = GuardLMS_Pusher::push();

		$this->assertTrue( $result );
	}

	// --- Success (AC3) -------------------------------------------------------

	public function test_success_stores_status_and_returns_true(): void {
		$this->stubEnvironment( array(), 'valid-key', $this->samplePlugins() );
		$this->stubHttpResponse( 200, '{"ok":true}' );

		$result = GuardLMS_Pusher::push();

		$this->assertTrue( $result );

		$writes = $this->settingsWrites();
		$this->assertCount( 1, $writes );
		$saved = $writes[0][1];
		$this->assertSame( 200, $saved['lastpushstatus'] );
		$this->assertSame( 1, $saved['last_plugincount'] );
		$this->assertIsInt( $saved['lastpush'] );
		$this->assertGreaterThan( 0, $saved['lastpush'] );
	}

	public function test_success_accepts_any_2xx(): void {
		$this->stubEnvironment( array(), 'valid-key', $this->samplePlugins() );
		$this->stubHttpResponse( 204, '' );

		$this->assertTrue( GuardLMS_Pusher::push() );
		$this->assertSame( 204, $this->settingsWrites()[0][1]['lastpushstatus'] );
	}

	// --- Non-2xx and errors (AC3 / AC5) -------------------------------------

	public function test_non_2xx_returns_error_and_writes_nothing(): void {
		$this->stubEnvironment( array(), 'valid-key', $this->samplePlugins() );
		$this->stubHttpResponse( 500, '{"error":"boom"}' );

		$result = GuardLMS_Pusher::push();

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'guardlms_pushhttp', $result->get_error_code() );
		$this->assertSame( array( 'code' => 500 ), $result->get_error_data() );
		$this->assertSame( array(), $this->settingsWrites() );
	}

	public function test_422_message_names_both_actual_and_registered_urls(): void {
		$this->stubEnvironment(
			array( 'connected_siteurl' => 'https://registered.example' ),
			'valid-key',
			$this->samplePlugins()
		);
		Functions\when( 'home_url' )->justReturn( 'https://actual.example' );
		$this->stubHttpResponse( 422, '{"error":"site url mismatch"}' );

		$result = GuardLMS_Pusher::push();

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'guardlms_pushhttp', $result->get_error_code() );

		$message = $result->get_error_message();
		$this->assertStringContainsString( 'https://actual.example', $message );
		$this->assertStringContainsString( 'https://registered.example', $message );
		$this->assertSame( array(), $this->settingsWrites() );
	}

	// --- Refused key: the connection is dead, not the push -------------------

	/**
	 * The 401 the WordPress site actually saw: the push key had been deleted
	 * server-side, so every call was refused while the admin screen still read
	 * "Connected". A bare "HTTP 401" gives an admin nothing to act on, so the
	 * refusal is stamped and the message names the remedy.
	 */
	public function test_401_stamps_the_refusal_and_names_reconnect_as_the_fix(): void {
		$this->stubEnvironment( array(), 'dead-key', $this->samplePlugins() );
		$this->stubHttpResponse( 401, '{"message":"Unauthenticated."}' );

		$result = GuardLMS_Pusher::push();

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'guardlms_pushrejected', $result->get_error_code() );
		$this->assertSame( array( 'code' => 401 ), $result->get_error_data() );
		$this->assertStringContainsString( 'Reconnect', $result->get_error_message() );

		$writes = $this->settingsWrites();
		$this->assertCount( 1, $writes );
		$this->assertGreaterThan( 0, $writes[0][1]['authrejectedat'] );
		// A refused key is never thrown away: recovery is the admin's call.
		$this->assertSame( 0, (int) $writes[0][1]['lastpush'] );
	}

	/**
	 * 403 is what a site gets once its website is deleted in the dashboard: the
	 * token survives but loses its website binding. Same dead end, same remedy.
	 */
	public function test_403_stamps_the_refusal_too(): void {
		$this->stubEnvironment( array(), 'unbound-key', $this->samplePlugins() );
		$this->stubHttpResponse( 403, '{"message":"This API key is not bound to a website."}' );

		$result = GuardLMS_Pusher::push();

		$this->assertSame( 'guardlms_pushrejected', $result->get_error_code() );
		$this->assertGreaterThan( 0, $this->settingsWrites()[0][1]['authrejectedat'] );
	}

	public function test_a_refusal_already_recorded_is_not_restamped(): void {
		$this->stubEnvironment( array( 'authrejectedat' => 1750000000 ), 'dead-key', $this->samplePlugins() );
		$this->stubHttpResponse( 401, '{"message":"Unauthenticated."}' );

		GuardLMS_Pusher::push();

		// The admin needs "since when", so the first refusal's timestamp stands.
		$this->assertSame( array(), $this->settingsWrites() );
	}

	public function test_an_accepted_push_clears_a_recorded_refusal(): void {
		$this->stubEnvironment( array( 'authrejectedat' => 1750000000 ), 'fresh-key', $this->samplePlugins() );
		$this->stubHttpResponse( 200, '{"ok":true}' );

		$this->assertTrue( GuardLMS_Pusher::push() );

		$writes = $this->settingsWrites();
		$this->assertSame( 0, (int) end( $writes )[1]['authrejectedat'] );
	}

	public function test_a_plain_server_error_leaves_the_connection_state_alone(): void {
		$this->stubEnvironment( array(), 'valid-key', $this->samplePlugins() );
		$this->stubHttpResponse( 500, '{"error":"boom"}' );

		GuardLMS_Pusher::push();

		// A backend outage is not a credential problem.
		$this->assertSame( array(), $this->settingsWrites() );
	}

	public function test_transport_wp_error_is_passed_through(): void {
		$this->stubEnvironment( array(), 'valid-key', $this->samplePlugins() );
		$transport_error = new WP_Error( 'http_request_failed', 'timed out' );
		Functions\when( 'wp_remote_post' )->justReturn( $transport_error );

		$result = GuardLMS_Pusher::push();

		$this->assertSame( $transport_error, $result );
		$this->assertSame( array(), $this->settingsWrites() );
	}
}
