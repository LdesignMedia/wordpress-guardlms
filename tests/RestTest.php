<?php
/**
 * Unit tests for GuardLMS_Rest (Phase 2 public Connect callback route).
 *
 * The route is intentionally public (permission_callback => __return_true); the
 * single-use `state` is the security control. handle_callback() runs the real
 * complete_connect() (with wp_remote_post() stubbed) and always redirects the
 * browser back to the admin Connect page.
 *
 * handle_callback() ends in wp_safe_redirect() + exit. exit would terminate the
 * PHPUnit process, so wp_safe_redirect() is stubbed to throw BEFORE exit is
 * reached; the redirect target is asserted from the thrown exception.
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
require_once GUARDLMS_PLUGIN_DIR . 'includes/admin/class-guardlms-admin-notice.php';
require_once GUARDLMS_PLUGIN_DIR . 'includes/class-guardlms-options.php';
require_once GUARDLMS_PLUGIN_DIR . 'includes/class-guardlms-credentials.php';
require_once GUARDLMS_PLUGIN_DIR . 'includes/class-guardlms-http.php';
require_once GUARDLMS_PLUGIN_DIR . 'includes/class-guardlms-api-client.php';
require_once GUARDLMS_PLUGIN_DIR . 'includes/class-guardlms-connect-manager.php';
require_once GUARDLMS_PLUGIN_DIR . 'includes/admin/class-guardlms-settings.php';
require_once GUARDLMS_PLUGIN_DIR . 'includes/admin/class-guardlms-connect-page.php';
require_once GUARDLMS_PLUGIN_DIR . 'includes/class-guardlms-rest.php';

// WordPress time constant the callback uses for the notice transient TTL.
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}

/**
 * Thrown by the stubbed wp_safe_redirect() so the following exit is never reached.
 */
if ( ! class_exists( 'GuardLMS_Test_Redirect' ) ) {
	final class GuardLMS_Test_Redirect extends \Exception {

		/** @var string */
		public $location;

		/**
		 * @param string $location Redirect target captured from wp_safe_redirect().
		 */
		public function __construct( string $location ) {
			parent::__construct( 'redirect' );
			$this->location = $location;
		}
	}
}

/**
 * Minimal stand-in for WP_REST_Request exposing just get_param().
 */
if ( ! class_exists( 'GuardLMS_Test_Rest_Request' ) ) {
	final class GuardLMS_Test_Rest_Request {

		/** @var array<string,string> */
		private $params;

		/**
		 * @param array<string,string> $params Query parameters.
		 */
		public function __construct( array $params ) {
			$this->params = $params;
		}

		/**
		 * @param string $key Parameter name.
		 * @return string|null
		 */
		public function get_param( $key ) {
			return $this->params[ $key ] ?? null;
		}
	}
}

/**
 * @covers GuardLMS_Rest
 */
final class RestTest extends AbstractGuardLMSTestCase {

	/**
	 * In-memory option store keyed by option name.
	 *
	 * @var array<string,mixed>
	 */
	private $store = array();

	/**
	 * set_transient() calls captured by key.
	 *
	 * @var array<string,mixed>
	 */
	private $transients = array();

	protected function setUp(): void {
		parent::setUp();
		$this->store      = array( 'guardlms_settings' => array() );
		$this->transients = array();

		Functions\when( 'get_option' )->alias(
			function ( $name, $default = false ) {
				return array_key_exists( $name, $this->store ) ? $this->store[ $name ] : $default;
			}
		);
		Functions\when( 'update_option' )->alias(
			function ( $name, $value, $autoload = null ) {
				$this->store[ $name ] = $value;
				return true;
			}
		);
		Functions\when( 'set_transient' )->alias(
			function ( $key, $value, $ttl = 0 ) {
				$this->transients[ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'admin_url' )->alias(
			static function ( $path = '' ) {
				return 'https://site.test/wp-admin/' . $path;
			}
		);
		Functions\when( 'add_query_arg' )->alias(
			static function ( $args, $url ) {
				$sep   = ( false !== strpos( $url, '?' ) ) ? '&' : '?';
				$pairs = array();
				foreach ( $args as $key => $value ) {
					$pairs[] = $key . '=' . $value;
				}
				return $url . $sep . implode( '&', $pairs );
			}
		);
		Functions\when( 'wp_safe_redirect' )->alias(
			static function ( $location ) {
				throw new GuardLMS_Test_Redirect( (string) $location );
			}
		);
	}

	/**
	 * Seed the guardlms_settings store.
	 *
	 * @param array $settings Settings to seed.
	 * @return void
	 */
	private function seedSettings( array $settings ): void {
		$this->store['guardlms_settings'] = $settings;
	}

	/**
	 * Stub the exchange transport to succeed with a canned data envelope.
	 *
	 * @return void
	 */
	private function stubExchangeSuccess(): void {
		Functions\when( 'home_url' )->justReturn( 'https://site.test/' );
		Functions\when( 'wp_json_encode' )->alias(
			static function ( $data ) {
				return json_encode( $data );
			}
		);
		Functions\when( 'wp_schedule_single_event' )->justReturn( true );
		// purge_caches() calls these when defined; Brain Monkey function symbols
		// persist across tests, so mock them to keep the call safe in any order.
		Functions\when( 'w3tc_flush_all' )->justReturn( null );
		Functions\when( 'litespeed_purge_all' )->justReturn( null );
		Functions\when( 'rocket_clean_domain' )->justReturn( null );
		Functions\when( 'wp_cache_clear_cache' )->justReturn( null );
		Functions\when( 'wp_remote_post' )->justReturn( array( 'stubbed' => true ) );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn(
			(string) json_encode(
				array(
					'data' => array(
						'token'              => 'push-key-abc',
						'pushpath'           => '/api/externalpush/wordpress',
						'verification_token' => 'verify-xyz',
						'website_id'         => 42,
						'expires_at'         => '2027-01-01T00:00:00+00:00',
					),
				)
			)
		);
	}

	/**
	 * Invoke handle_callback() and return the redirect target it exits with.
	 *
	 * @param GuardLMS_Test_Rest_Request $request Fake request.
	 * @return string Redirect location.
	 */
	private function captureRedirect( GuardLMS_Test_Rest_Request $request ): string {
		try {
			GuardLMS_Rest::handle_callback( $request );
		} catch ( GuardLMS_Test_Redirect $redirect ) {
			return $redirect->location;
		}

		$this->fail( 'handle_callback() did not redirect.' );
	}

	// --- register() ----------------------------------------------------------

	public function test_register_registers_public_get_callback(): void {
		$captured = array();
		Functions\when( 'register_rest_route' )->alias(
			static function ( $namespace, $route, $args ) use ( &$captured ) {
				$captured = array(
					'namespace' => $namespace,
					'route'     => $route,
					'args'      => $args,
				);
				return true;
			}
		);

		GuardLMS_Rest::register();

		$this->assertSame( 'guardlms/v1', $captured['namespace'] );
		$this->assertSame( '/connect-callback', $captured['route'] );
		$this->assertSame( 'GET', $captured['args']['methods'] );
		// Public by design: no capability check gates the OAuth redirect callback.
		$this->assertSame( '__return_true', $captured['args']['permission_callback'] );
		$this->assertSame( array( 'GuardLMS_Rest', 'handle_callback' ), $captured['args']['callback'] );
	}

	// --- handle_callback(): success ------------------------------------------

	public function test_handle_callback_success_redirects_with_success_flag(): void {
		$state = bin2hex( random_bytes( 20 ) );
		$this->seedSettings(
			array(
				'connectstate'         => $state,
				'connectstateexpires'  => time() + 300,
				'connectstate_baseurl' => 'https://backend.test',
			)
		);
		$this->stubExchangeSuccess();

		// The public route performs no capability check; assert it is never called.
		Functions\expect( 'current_user_can' )->never();

		$request  = new GuardLMS_Test_Rest_Request(
			array(
				'code'  => 'code123',
				'state' => $state,
			)
		);
		$location = $this->captureRedirect( $request );

		// Redirected back to the admin Connect page with a success flag.
		$this->assertStringContainsString( 'page=' . GuardLMS_Connect_Page::PAGE, $location );
		$this->assertStringContainsString( 'guardlms_connect=success', $location );
		$this->assertStringContainsString( 'https://site.test/wp-admin/options-general.php', $location );

		// No success notice: the page already shows the connection status, and a
		// notice repeating it reads as a second, different message.
		$this->assertArrayNotHasKey( GuardLMS_Connect_Page::NOTICE_TRANSIENT, $this->transients );

		// The connection actually completed (credentials stored).
		$this->assertSame( array( 'apikey' => 'push-key-abc' ), $this->store['guardlms_credentials'] );
	}

	// --- handle_callback(): error --------------------------------------------

	public function test_handle_callback_error_redirects_with_error_flag(): void {
		// Stored state differs from the one echoed back -> complete_connect errors.
		$this->seedSettings(
			array(
				'connectstate'         => bin2hex( random_bytes( 20 ) ),
				'connectstateexpires'  => time() + 300,
				'connectstate_baseurl' => 'https://backend.test',
			)
		);
		// The exchange must never run on a bad state.
		Functions\expect( 'wp_remote_post' )->never();

		$request  = new GuardLMS_Test_Rest_Request(
			array(
				'code'  => 'code123',
				'state' => bin2hex( random_bytes( 20 ) ),
			)
		);
		$location = $this->captureRedirect( $request );

		$this->assertStringContainsString( 'page=' . GuardLMS_Connect_Page::PAGE, $location );
		$this->assertStringContainsString( 'guardlms_connect=error', $location );

		// An error notice is queued, carrying the failure message.
		$this->assertArrayHasKey( GuardLMS_Connect_Page::NOTICE_TRANSIENT, $this->transients );
		$this->assertSame( 'error', $this->transients[ GuardLMS_Connect_Page::NOTICE_TRANSIENT ]['type'] );
		$this->assertNotEmpty( $this->transients[ GuardLMS_Connect_Page::NOTICE_TRANSIENT ]['message'] );

		// No credentials were stored.
		$this->assertArrayNotHasKey( 'guardlms_credentials', $this->store );
	}

	public function test_handle_callback_missing_params_writes_no_notice(): void {
		// The route is public: an unauthenticated hit with blank code/state must not
		// write a transient or plant a notice a legitimate admin would later see.
		$this->seedSettings(
			array(
				'connectstate'         => bin2hex( random_bytes( 20 ) ),
				'connectstateexpires'  => time() + 300,
				'connectstate_baseurl' => 'https://backend.test',
			)
		);
		Functions\expect( 'wp_remote_post' )->never();

		$request  = new GuardLMS_Test_Rest_Request(
			array(
				'code'  => '',
				'state' => '',
			)
		);
		$location = $this->captureRedirect( $request );

		// Still redirects with an error flag, but writes NO transient.
		$this->assertStringContainsString( 'guardlms_connect=error', $location );
		$this->assertArrayNotHasKey( GuardLMS_Connect_Page::NOTICE_TRANSIENT, $this->transients );
	}
}
