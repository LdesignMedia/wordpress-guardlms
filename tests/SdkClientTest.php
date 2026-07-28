<?php
/**
 * Unit tests for GuardLMS_Sdk_Client.
 *
 * Covers AC D8 (the body is a JSON STRING, not an array) and AC D9 (the
 * synchronous bootstrap sets its lock BEFORE the request goes out). The real
 * GuardLMS_Http runs and wp_remote_post() beneath it returns canned responses,
 * because Brain Monkey cannot stub a static method.
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
require_once GUARDLMS_PLUGIN_DIR . 'includes/class-guardlms-http.php';
require_once GUARDLMS_PLUGIN_DIR . 'includes/class-guardlms-sdk-config.php';
require_once GUARDLMS_PLUGIN_DIR . 'includes/class-guardlms-sdk-status.php';
require_once GUARDLMS_PLUGIN_DIR . 'includes/class-guardlms-sdk-client.php';
require_once GUARDLMS_PLUGIN_DIR . 'includes/class-guardlms-api-client.php';
require_once GUARDLMS_PLUGIN_DIR . 'includes/class-guardlms-connect-manager.php';

/**
 * @covers GuardLMS_Sdk_Client
 */
final class SdkClientTest extends AbstractGuardLMSTestCase {

	/**
	 * In-memory option store keyed by option name.
	 *
	 * @var array<string,mixed>
	 */
	private $store = array();

	/**
	 * In-memory transient store.
	 *
	 * @var array<string,mixed>
	 */
	private $transients = array();

	/**
	 * Captured wp_remote_post() calls: url + args.
	 *
	 * @var array[]
	 */
	private $requests = array();

	/**
	 * Canned response for the next wp_remote_post(): code + body, or a WP_Error.
	 *
	 * @var mixed
	 */
	private $response = array(
		'code' => 200,
		'body' => '{}',
	);

	protected function setUp(): void {
		parent::setUp();
		$this->store      = array();
		$this->transients = array();
		$this->requests   = array();
		$this->response   = array(
			'code' => 200,
			'body' => '{}',
		);

		Functions\when( 'get_option' )->alias(
			function ( $name, $default = false ) {
				return array_key_exists( $name, $this->store ) ? $this->store[ $name ] : $default;
			}
		);
		Functions\when( 'update_option' )->alias(
			function ( $name, $value ) {
				$this->store[ $name ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_option' )->alias(
			function ( $name ) {
				unset( $this->store[ $name ] );
				return true;
			}
		);
		Functions\when( 'get_transient' )->alias(
			function ( $key ) {
				return array_key_exists( $key, $this->transients ) ? $this->transients[ $key ] : false;
			}
		);
		Functions\when( 'set_transient' )->alias(
			function ( $key, $value ) {
				$this->transients[ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'wp_json_encode' )->alias(
			static function ( $data ) {
				return json_encode( $data );
			}
		);
		Functions\when( 'esc_url_raw' )->returnArg( 1 );
		Functions\when( 'sanitize_text_field' )->returnArg( 1 );
		Functions\when( 'home_url' )->justReturn( 'https://site.test/' );

		Functions\when( 'wp_remote_post' )->alias(
			function ( $url, $args ) {
				$this->requests[] = array(
					'url'  => $url,
					'args' => $args,
				);
				return $this->response;
			}
		);
		// is_wp_error() is a real function defined in AbstractGuardLMSTestCase, so
		// Brain Monkey cannot (and need not) redefine it.
		Functions\when( 'wp_remote_retrieve_response_code' )->alias(
			function () {
				return $this->response['code'];
			}
		);
		Functions\when( 'wp_remote_retrieve_body' )->alias(
			function () {
				return $this->response['body'];
			}
		);
	}

	/**
	 * Seed a connected site with the given real-time configuration.
	 *
	 * @param array $sdk Values for the nested sdk array.
	 * @return void
	 */
	private function seedConnected( array $sdk = array() ): void {
		$this->store['guardlms_settings']    = array(
			'baseurl'     => 'https://backend.test',
			'connectedat' => 1750000000,
			'sdk'         => $sdk,
		);
		$this->store['guardlms_credentials'] = array( 'apikey' => 'push-key-abc' );
	}

	/**
	 * A successful `fetch` response body.
	 *
	 * @param array $overrides Payload overrides.
	 * @return string
	 */
	private function payloadBody( array $overrides = array() ): string {
		return (string) json_encode(
			array(
				'message' => 'ok',
				'data'    => array_merge(
					array(
						'key'                   => 'glms_' . str_repeat( 'a', 56 ),
						'key_status'            => 'issued',
						'key_prefix'            => 'glms_aaa',
						'sdk_url'               => 'https://backend.test/sdk/guardlms.min.js?v=deadbeef1234',
						'errors_endpoint'       => 'https://backend.test/api/sdk/errors/collect',
						'analytics_endpoint'    => 'https://backend.test/api/sdk/analytics/collect',
						'enabled'               => true,
						'subscription_active'   => true,
						'analytics_allowed'     => true,
						'sample_rate'           => 1.0,
						'analytics_sample_rate' => 1.0,
						'max_breadcrumbs'       => 50,
						'max_errors_per_minute' => 60,
						'ignored_errors'        => array(),
						'allowed_domains'       => array(),
						'allowed_domains_match' => true,
					),
					$overrides
				),
			)
		);
	}

	// --- AC D8: the request shape --------------------------------------------

	/**
	 * AC D8. GuardLMS_Http::post()'s signature is `string $json_body`. Passing an
	 * array is a bug PHP would only surface at the boundary, so this asserts the
	 * TYPE that actually reaches the transport, not just that the request worked.
	 */
	public function test_the_request_body_is_a_json_string_not_an_array(): void {
		$this->seedConnected();
		$this->response = array(
			'code' => 200,
			'body' => $this->payloadBody(),
		);

		GuardLMS_Sdk_Client::resolve( 'fetch' );

		$this->assertCount( 1, $this->requests );
		$body = $this->requests[0]['args']['body'];

		$this->assertIsString( $body );
		$this->assertIsNotArray( $body );
		$this->assertSame(
			array(
				'siteurl'  => 'https://site.test',
				'platform' => 'wordpress',
				'action'   => 'fetch',
			),
			json_decode( $body, true )
		);
	}

	public function test_the_request_carries_the_push_key_as_a_bearer_token(): void {
		$this->seedConnected();
		$this->response = array(
			'code' => 200,
			'body' => $this->payloadBody(),
		);

		GuardLMS_Sdk_Client::resolve( 'fetch' );

		$headers = $this->requests[0]['args']['headers'];
		$this->assertSame( 'Bearer push-key-abc', $headers['Authorization'] );
		$this->assertSame( 'application/json', $headers['Content-Type'] );
	}

	public function test_the_request_targets_the_configured_base_url(): void {
		$this->seedConnected();
		$this->response = array(
			'code' => 200,
			'body' => $this->payloadBody(),
		);

		GuardLMS_Sdk_Client::resolve( 'fetch' );

		$this->assertSame( 'https://backend.test/api/integrations/sdk-key', $this->requests[0]['url'] );
	}

	public function test_the_request_falls_back_to_the_default_host_when_the_base_url_is_blank(): void {
		$this->seedConnected();
		$this->store['guardlms_settings']['baseurl'] = '';
		$this->response                              = array(
			'code' => 200,
			'body' => $this->payloadBody(),
		);

		GuardLMS_Sdk_Client::resolve( 'fetch' );

		$this->assertSame(
			GUARDLMS_DEFAULT_BASEURL . '/api/integrations/sdk-key',
			$this->requests[0]['url']
		);
	}

	public function test_transport_hardening_is_inherited_from_guardlms_http(): void {
		$this->seedConnected();
		$this->response = array(
			'code' => 200,
			'body' => $this->payloadBody(),
		);

		GuardLMS_Sdk_Client::resolve( 'fetch' );

		$args = $this->requests[0]['args'];
		$this->assertSame( 0, $args['redirection'] );
		$this->assertTrue( $args['reject_unsafe_urls'] );
	}

	public function test_an_interactive_call_uses_the_five_second_timeout(): void {
		$this->seedConnected();
		$this->response = array(
			'code' => 200,
			'body' => $this->payloadBody(),
		);

		GuardLMS_Sdk_Client::resolve( 'fetch', GuardLMS_Sdk_Client::INTERACTIVE_TIMEOUT );

		$this->assertSame( 5, $this->requests[0]['args']['timeout'] );
	}

	public function test_a_background_call_keeps_the_thirty_second_default(): void {
		$this->seedConnected();
		$this->response = array(
			'code' => 200,
			'body' => $this->payloadBody(),
		);

		GuardLMS_Sdk_Client::resolve( 'fetch' );

		$this->assertSame( 30, $this->requests[0]['args']['timeout'] );
	}

	// --- guards ---------------------------------------------------------------

	public function test_an_unconnected_site_makes_no_request(): void {
		$this->store['guardlms_settings'] = array( 'baseurl' => 'https://backend.test' );

		$result = GuardLMS_Sdk_Client::resolve( 'fetch' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'guardlms_sdknotconnected', $result->get_error_code() );
		$this->assertSame( array(), $this->requests );
	}

	public function test_an_unknown_action_is_rejected_before_any_request(): void {
		$this->seedConnected();

		$result = GuardLMS_Sdk_Client::resolve( 'delete-everything' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'guardlms_sdkaction', $result->get_error_code() );
		$this->assertSame( array(), $this->requests );
	}

	/**
	 * Revoking on a site that never held an SDK key would be a pointless request
	 * that can itself 429 or fail, on the one code path that must always succeed.
	 */
	public function test_revoke_without_a_stored_key_is_a_no_op(): void {
		$this->seedConnected();

		$result = GuardLMS_Sdk_Client::resolve( 'revoke' );

		$this->assertSame( GuardLMS_Sdk_Client::NOOP, $result );
		$this->assertSame( array(), $this->requests );
	}

	public function test_revoke_with_a_stored_key_does_issue_the_request(): void {
		$this->seedConnected();
		$this->store['guardlms_sdk_credentials'] = array( 'sdkkey' => 'glms_live' );
		$this->response                                = array(
			'code' => 200,
			'body' => (string) json_encode( array( 'data' => array( 'key_status' => 'revoked' ) ) ),
		);

		$result = GuardLMS_Sdk_Client::resolve( 'revoke' );

		$this->assertIsArray( $result );
		$this->assertCount( 1, $this->requests );
		$this->assertSame( 'revoke', json_decode( $this->requests[0]['args']['body'], true )['action'] );
	}

	// --- §5.3 row 2: 404/405 --------------------------------------------------

	/**
	 * §5.3 row 2. A backend that predates this feature raises NO admin error at
	 * all - the section is hidden instead. Recording a refresh_error here would
	 * put an unfixable message in front of every admin on an older install.
	 *
	 * @dataProvider unsupportedStatusProvider
	 * @param int $status HTTP status code.
	 * @return void
	 */
	public function test_a_404_or_405_marks_the_backend_unsupported_without_an_error( int $status ): void {
		$this->seedConnected();
		$this->response = array(
			'code' => $status,
			'body' => 'Not Found',
		);

		$result = GuardLMS_Sdk_Client::resolve( 'fetch' );

		$this->assertSame( GuardLMS_Sdk_Client::UNSUPPORTED, $result );
		$this->assertFalse( GuardLMS_Sdk_Config::get( 'backend_supported' ) );
		$this->assertSame( '', GuardLMS_Sdk_Config::get( 'refresh_error' ) );
	}

	/**
	 * @return array<string,array{0:int}>
	 */
	public function unsupportedStatusProvider(): array {
		return array(
			'404 Not Found'          => array( 404 ),
			'405 Method Not Allowed' => array( 405 ),
		);
	}

	// --- failure handling -----------------------------------------------------

	public function test_a_transport_failure_records_the_message_and_returns_the_error(): void {
		$this->seedConnected();
		$transport_error = new WP_Error( 'http_request_failed', 'cURL error 28: timed out' );
		$this->response  = $transport_error;

		$result = GuardLMS_Sdk_Client::resolve( 'fetch' );

		$this->assertSame( $transport_error, $result );
		$this->assertSame( 'cURL error 28: timed out', GuardLMS_Sdk_Config::get( 'refresh_error' ) );
		// backend_supported is untouched: a timeout says nothing about the
		// backend's version, and hiding the section here would lose the error.
		$this->assertTrue( GuardLMS_Sdk_Config::get( 'backend_supported' ) );
	}

	public function test_a_500_records_an_error_and_does_not_hide_the_section(): void {
		$this->seedConnected();
		$this->response = array(
			'code' => 500,
			'body' => 'Server Error',
		);

		$result = GuardLMS_Sdk_Client::resolve( 'fetch' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'guardlms_sdkhttp', $result->get_error_code() );
		$this->assertStringContainsString( '500', GuardLMS_Sdk_Config::get( 'refresh_error' ) );
		$this->assertTrue( GuardLMS_Sdk_Config::get( 'backend_supported' ) );
	}

	public function test_an_unparseable_body_records_an_error(): void {
		$this->seedConnected();
		$this->response = array(
			'code' => 200,
			'body' => 'not json at all',
		);

		$result = GuardLMS_Sdk_Client::resolve( 'fetch' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'guardlms_sdkparse', $result->get_error_code() );
		$this->assertNotSame( '', GuardLMS_Sdk_Config::get( 'refresh_error' ) );
	}

	public function test_a_body_without_a_data_envelope_records_an_error(): void {
		$this->seedConnected();
		$this->response = array(
			'code' => 200,
			'body' => (string) json_encode( array( 'message' => 'ok' ) ),
		);

		$result = GuardLMS_Sdk_Client::resolve( 'fetch' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'guardlms_sdkparse', $result->get_error_code() );
	}

	/**
	 * A failed refresh must never wipe the working configuration the site is
	 * already serving - that would turn a transient backend blip into a real
	 * monitoring outage.
	 */
	public function test_a_failure_leaves_the_previously_stored_configuration_intact(): void {
		$this->seedConnected();
		$this->response = array(
			'code' => 200,
			'body' => $this->payloadBody(),
		);
		GuardLMS_Sdk_Client::resolve( 'fetch' );
		$before = GuardLMS_Sdk_Config::get( 'sdk_url' );

		$this->response = new WP_Error( 'http_request_failed', 'timed out' );
		GuardLMS_Sdk_Client::resolve( 'fetch' );

		$this->assertSame( $before, GuardLMS_Sdk_Config::get( 'sdk_url' ) );
		$this->assertSame( 'glms_' . str_repeat( 'a', 56 ), GuardLMS_Credentials::get_sdk_key() );
	}

	// --- success --------------------------------------------------------------

	public function test_a_successful_fetch_persists_the_key_and_the_configuration(): void {
		$this->seedConnected();
		$this->response = array(
			'code' => 200,
			'body' => $this->payloadBody(),
		);

		$result = GuardLMS_Sdk_Client::resolve( 'fetch' );

		$this->assertIsArray( $result );
		$this->assertSame( 'glms_' . str_repeat( 'a', 56 ), GuardLMS_Credentials::get_sdk_key() );
		$this->assertSame(
			'https://backend.test/sdk/guardlms.min.js?v=deadbeef1234',
			GuardLMS_Sdk_Config::get( 'sdk_url' )
		);
		$this->assertGreaterThan( 0, GuardLMS_Sdk_Config::get( 'refreshed_at' ) );
	}

	/**
	 * AC D5. The key lands in the SDK credentials option and never in guardlms_settings,
	 * asserted on the raw stored option values.
	 */
	public function test_the_key_never_reaches_the_settings_option(): void {
		$this->seedConnected();
		$this->response = array(
			'code' => 200,
			'body' => $this->payloadBody(),
		);

		GuardLMS_Sdk_Client::resolve( 'fetch' );

		$key = 'glms_' . str_repeat( 'a', 56 );
		$this->assertSame( $key, $this->store['guardlms_sdk_credentials']['sdkkey'] );
		$this->assertStringNotContainsString( $key, (string) json_encode( $this->store['guardlms_settings'] ) );
	}

	// --- AC D9: the synchronous bootstrap ------------------------------------

	/**
	 * AC D9. Exactly one synchronous fetch on the first render of a connected
	 * site that has never refreshed.
	 */
	public function test_bootstrap_performs_exactly_one_fetch_on_a_never_refreshed_site(): void {
		$this->seedConnected();
		$this->response = array(
			'code' => 200,
			'body' => $this->payloadBody(),
		);

		$this->assertTrue( GuardLMS_Sdk_Client::maybe_bootstrap() );

		$this->assertCount( 1, $this->requests );
		$this->assertSame( 'fetch', json_decode( $this->requests[0]['args']['body'], true )['action'] );
		$this->assertSame( 5, $this->requests[0]['args']['timeout'] );
	}

	/**
	 * AC D9. THE LOCK IS SET BEFORE THE REQUEST IS ISSUED. Asserted by making the
	 * transport throw mid-flight and confirming the lock survived: if the lock
	 * were set only after a successful response, a backend that hangs would mean
	 * every page reload starts a fresh request - the exact storm it exists to
	 * prevent. A test that only checks "the lock is set afterwards" passes
	 * against that broken ordering, which is why this one throws.
	 */
	public function test_bootstrap_sets_the_lock_before_the_request_is_issued(): void {
		$this->seedConnected();
		Functions\when( 'wp_remote_post' )->alias(
			function () {
				// Observed from inside the in-flight request.
				$this->requests[] = array(
					'lock_present' => array_key_exists( GuardLMS_Sdk_Client::BOOTSTRAP_LOCK, $this->transients ),
				);
				throw new RuntimeException( 'transport exploded mid-flight' );
			}
		);

		try {
			GuardLMS_Sdk_Client::maybe_bootstrap();
			$this->fail( 'The transport was expected to throw.' );
		} catch ( RuntimeException $e ) {
			$this->assertSame( 'transport exploded mid-flight', $e->getMessage() );
		}

		// Set before the call...
		$this->assertTrue( $this->requests[0]['lock_present'] );
		// ...and still present after it blew up.
		$this->assertArrayHasKey( GuardLMS_Sdk_Client::BOOTSTRAP_LOCK, $this->transients );
	}

	public function test_a_second_render_inside_the_lock_window_performs_no_fetch(): void {
		$this->seedConnected();
		$this->response = new WP_Error( 'http_request_failed', 'timed out' );

		$this->assertTrue( GuardLMS_Sdk_Client::maybe_bootstrap() );
		$this->assertCount( 1, $this->requests );

		// The first attempt failed, so refreshed_at is still 0 and only the lock
		// stands between this site and a request per page load.
		$this->assertSame( 0, GuardLMS_Sdk_Config::get( 'refreshed_at' ) );
		$this->assertFalse( GuardLMS_Sdk_Client::maybe_bootstrap() );
		$this->assertFalse( GuardLMS_Sdk_Client::maybe_bootstrap() );
		$this->assertCount( 1, $this->requests );
	}

	public function test_bootstrap_does_nothing_once_a_refresh_has_succeeded(): void {
		$this->seedConnected( array( 'refreshed_at' => 1750000000 ) );

		$this->assertFalse( GuardLMS_Sdk_Client::maybe_bootstrap() );
		$this->assertSame( array(), $this->requests );
	}

	public function test_bootstrap_does_nothing_on_an_unconnected_site(): void {
		$this->store['guardlms_settings'] = array( 'baseurl' => 'https://backend.test' );

		$this->assertFalse( GuardLMS_Sdk_Client::maybe_bootstrap() );
		$this->assertSame( array(), $this->requests );
	}

	/**
	 * AC D9. No cron event is required for the key to arrive: the bootstrap is
	 * the whole path, and it schedules nothing.
	 */
	public function test_bootstrap_schedules_no_cron_event(): void {
		$this->seedConnected();
		$this->response = array(
			'code' => 200,
			'body' => $this->payloadBody(),
		);
		Functions\expect( 'wp_schedule_single_event' )->never();
		Functions\expect( 'wp_schedule_event' )->never();

		GuardLMS_Sdk_Client::maybe_bootstrap();

		$this->assertSame( 'glms_' . str_repeat( 'a', 56 ), GuardLMS_Credentials::get_sdk_key() );
	}
}
