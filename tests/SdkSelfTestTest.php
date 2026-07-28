<?php
/**
 * Tests for the three real-time admin-post handlers.
 *
 * The self-test is the interesting one: it exists so an admin can tell "the SDK
 * is loading" from "an optimization plugin ate it", and it must do that WITHOUT
 * an unauthenticated endpoint, a front-end write path or a new option key.
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
require_once GUARDLMS_PLUGIN_DIR . 'includes/class-guardlms-sdk-injector.php';
require_once GUARDLMS_PLUGIN_DIR . 'includes/class-guardlms-api-client.php';
require_once GUARDLMS_PLUGIN_DIR . 'includes/class-guardlms-connect-manager.php';
require_once GUARDLMS_PLUGIN_DIR . 'includes/admin/class-guardlms-settings.php';
require_once GUARDLMS_PLUGIN_DIR . 'includes/admin/class-guardlms-realtime-page.php';

/**
 * Thrown by the exit-replacing stubs so a handler's control flow can be asserted.
 */
final class GuardLMS_Test_Exit extends RuntimeException {

	/**
	 * The redirect target, when the handler redirected.
	 *
	 * @var string
	 */
	public $location = '';

	/**
	 * @param string $location Redirect target.
	 */
	public function __construct( string $location = '' ) {
		parent::__construct( 'exit' );
		$this->location = $location;
	}
}

/**
 * @covers GuardLMS_Realtime_Page
 */
final class SdkSelfTestTest extends AbstractGuardLMSTestCase {

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
	 * Captured wp_remote_post() calls.
	 *
	 * @var array[]
	 */
	private $requests = array();

	/**
	 * Nonce checks performed, as action names.
	 *
	 * @var string[]
	 */
	private $nonceChecks = array();

	protected function setUp(): void {
		parent::setUp();
		$this->store       = array();
		$this->transients  = array();
		$this->requests    = array();
		$this->nonceChecks = array();

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
		Functions\when( 'check_admin_referer' )->alias(
			function ( $action ) {
				$this->nonceChecks[] = (string) $action;
				return true;
			}
		);
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_die' )->alias(
			static function ( $message = '' ) {
				throw new GuardLMS_Test_Exit( (string) $message );
			}
		);
		Functions\when( 'wp_safe_redirect' )->alias(
			static function ( $location ) {
				throw new GuardLMS_Test_Exit( (string) $location );
			}
		);
		Functions\when( 'add_query_arg' )->alias(
			static function ( $args, $url ) {
				$sep   = ( false !== strpos( (string) $url, '?' ) ) ? '&' : '?';
				$pairs = array();
				foreach ( (array) $args as $key => $value ) {
					$pairs[] = $key . '=' . $value;
				}
				return $url . $sep . implode( '&', $pairs );
			}
		);
		Functions\when( 'admin_url' )->justReturn( 'https://site.test/wp-admin/options-general.php' );
		Functions\when( 'home_url' )->justReturn( 'https://site.test/' );
		Functions\when( 'esc_url_raw' )->returnArg( 1 );
		Functions\when( 'sanitize_text_field' )->returnArg( 1 );
		Functions\when( 'wp_json_encode' )->alias(
			static function ( $data ) {
				return json_encode( $data );
			}
		);
		Functions\when( 'wp_remote_post' )->alias(
			function ( $url, $args ) {
				$this->requests[] = array(
					'url'  => $url,
					'body' => $args['body'],
					'args' => $args,
				);
				return array( 'stubbed' => true );
			}
		);
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn(
			(string) json_encode(
				array(
					'data' => array(
						'key'             => 'glms_rotated',
						'key_status'      => 'rotated',
						'sdk_url'         => 'https://backend.test/sdk/guardlms.min.js?v=abc',
						'errors_endpoint' => 'https://backend.test/api/sdk/errors/collect',
					),
				)
			)
		);

		// A healthy, injecting site: the self-test handler now refuses when the
		// SDK is not actually on the page.
		$this->store['guardlms_settings']        = array(
			'enabled'     => true,
			'baseurl'     => 'https://backend.test',
			'connectedat' => 1750000000,
			'websiteid'   => 42,
			'sdk'         => array(
				'enabled'             => true,
				'sdk_url'             => 'https://backend.test/sdk/guardlms.min.js?v=abc',
				'errors_endpoint'     => 'https://backend.test/api/sdk/errors/collect',
				'backend_enabled'     => true,
				'subscription_active' => true,
				'refreshed_at'        => 1750000000,
			),
		);
		$this->store['guardlms_credentials']     = array( 'apikey' => 'push-key' );
		$this->store['guardlms_sdk_credentials'] = array( 'sdkkey' => 'glms_existing' );
	}

	/**
	 * Run a handler and return the redirect target it exited on.
	 *
	 * @param callable $handler Handler to invoke.
	 * @return string
	 */
	private function runHandler( callable $handler ): string {
		try {
			$handler();
		} catch ( GuardLMS_Test_Exit $exit ) {
			return $exit->location;
		}

		$this->fail( 'The handler did not redirect or die.' );
	}

	// --- the self-test handler ------------------------------------------------

	public function test_the_selftest_handler_redirects_to_the_front_page_with_the_flag(): void {
		$location = $this->runHandler( array( 'GuardLMS_Realtime_Page', 'handle_selftest' ) );

		$this->assertStringContainsString( 'https://site.test/', $location );
		$this->assertStringContainsString( GuardLMS_Sdk_Injector::SELFTEST_FLAG . '=1', $location );
	}

	/**
	 * AC D10. The whole self-test flow writes nothing to the options table.
	 */
	public function test_the_selftest_handler_writes_nothing(): void {
		Functions\expect( 'update_option' )->never();
		Functions\expect( 'add_option' )->never();
		Functions\expect( 'set_transient' )->never();

		$this->runHandler( array( 'GuardLMS_Realtime_Page', 'handle_selftest' ) );

		$this->assertSame( array(), $this->requests );
	}

	public function test_the_selftest_handler_checks_its_nonce(): void {
		$this->runHandler( array( 'GuardLMS_Realtime_Page', 'handle_selftest' ) );

		$this->assertSame( array( GuardLMS_Realtime_Page::SELFTEST_ACTION ), $this->nonceChecks );
	}

	/**
	 * The probe's only failure message is "another plugin may be deferring or
	 * blocking it". When the plugin itself chose not to inject, that is an
	 * accusation against an innocent third party - and the real reason is already
	 * on screen. The handler refuses instead of sending the admin to the front
	 * end to be misinformed.
	 *
	 * @dataProvider notInjectingProvider
	 * @param array $sdk_overrides Overrides for the nested sdk array.
	 * @return void
	 */
	public function test_the_selftest_handler_refuses_when_the_sdk_is_not_injected( array $sdk_overrides ): void {
		$this->store['guardlms_settings']['sdk'] = array_merge(
			$this->store['guardlms_settings']['sdk'],
			$sdk_overrides
		);

		$location = $this->runHandler( array( 'GuardLMS_Realtime_Page', 'handle_selftest' ) );

		// Back to the settings page, not out to the front end.
		$this->assertStringNotContainsString( GuardLMS_Sdk_Injector::SELFTEST_FLAG, $location );
		$this->assertStringContainsString( 'page=guardlms', $location );

		$notice = $this->transients[ GuardLMS_Realtime_Page::NOTICE_TRANSIENT ];
		$this->assertSame( 'warning', $notice['type'] );
		$this->assertStringContainsString( 'nothing to test', $notice['message'] );
	}

	/**
	 * @return array<string,array{0:array}>
	 */
	public function notInjectingProvider(): array {
		return array(
			// The likeliest first run of all: key fetched, box not yet ticked.
			'admin has not opted in' => array( array( 'enabled' => false ) ),
			'subscription expired'   => array( array( 'subscription_active' => false ) ),
			'dashboard switch off'   => array( array( 'backend_enabled' => false ) ),
			'no endpoint stored'     => array( array( 'errors_endpoint' => '' ) ),
		);
	}

	public function test_the_selftest_handler_refuses_when_reporting_is_switched_off(): void {
		$this->store['guardlms_settings']['enabled'] = false;

		$location = $this->runHandler( array( 'GuardLMS_Realtime_Page', 'handle_selftest' ) );

		$this->assertStringNotContainsString( GuardLMS_Sdk_Injector::SELFTEST_FLAG, $location );
	}

	// --- capability guards ----------------------------------------------------

	/**
	 * @dataProvider handlerProvider
	 * @param string $method Handler method name.
	 * @return void
	 */
	public function test_every_handler_dies_without_manage_options( string $method ): void {
		Functions\when( 'current_user_can' )->justReturn( false );

		$message = $this->runHandler( array( 'GuardLMS_Realtime_Page', $method ) );

		$this->assertStringContainsString( 'do not have permission', $message );
		$this->assertSame( array(), $this->requests );
	}

	/**
	 * @dataProvider handlerProvider
	 * @param string $method Handler method name.
	 * @param string $action Expected nonce action.
	 * @return void
	 */
	public function test_every_handler_checks_a_nonce_before_acting( string $method, string $action ): void {
		$this->runHandler( array( 'GuardLMS_Realtime_Page', $method ) );

		$this->assertSame( array( $action ), $this->nonceChecks );
	}

	/**
	 * @return array<string,array{0:string,1:string}>
	 */
	public function handlerProvider(): array {
		return array(
			'refresh'  => array( 'handle_refresh', GuardLMS_Realtime_Page::REFRESH_ACTION ),
			'selftest' => array( 'handle_selftest', GuardLMS_Realtime_Page::SELFTEST_ACTION ),
			'rotate'   => array( 'handle_rotate', GuardLMS_Realtime_Page::ROTATE_ACTION ),
		);
	}

	// --- refresh --------------------------------------------------------------

	public function test_the_refresh_handler_sends_fetch_with_the_short_timeout(): void {
		$this->runHandler( array( 'GuardLMS_Realtime_Page', 'handle_refresh' ) );

		$this->assertCount( 1, $this->requests );
		$this->assertSame( 'fetch', json_decode( $this->requests[0]['body'], true )['action'] );
		$this->assertSame( 5, $this->requests[0]['args']['timeout'] );
	}

	/**
	 * The refresh handler redirects into the settings page, whose render calls
	 * maybe_bootstrap(). On a site that has never refreshed successfully, that
	 * fires a SECOND blocking 5s request unless this handler has already taken
	 * the lock - so one click costs 10 seconds against a dead backend.
	 */
	public function test_the_refresh_handler_takes_the_bootstrap_lock(): void {
		$this->runHandler( array( 'GuardLMS_Realtime_Page', 'handle_refresh' ) );

		$this->assertArrayHasKey( GuardLMS_Sdk_Client::BOOTSTRAP_LOCK, $this->transients );
	}

	/**
	 * The consequence, asserted end to end: after a failed refresh the following
	 * settings-page render issues no further request.
	 */
	public function test_a_failed_refresh_does_not_cost_a_second_request_on_the_way_back(): void {
		// The site this matters for: connected, never refreshed successfully, so
		// maybe_bootstrap() would otherwise fire on the redirected-to render.
		$this->store['guardlms_settings']['sdk']['refreshed_at'] = 0;

		Functions\when( 'wp_remote_post' )->alias(
			function ( $url, $args ) {
				$this->requests[] = array(
					'url'  => $url,
					'body' => $args['body'],
					'args' => $args,
				);
				return new WP_Error( 'http_request_failed', 'timed out' );
			}
		);

		$this->runHandler( array( 'GuardLMS_Realtime_Page', 'handle_refresh' ) );
		$this->assertCount( 1, $this->requests );

		// The site still has refreshed_at === 0, so only the lock stands between
		// it and a second blocking call on the redirected-to render.
		$this->assertSame( 0, (int) GuardLMS_Sdk_Config::get( 'refreshed_at' ) );
		$this->assertFalse( GuardLMS_Sdk_Client::maybe_bootstrap() );
		$this->assertCount( 1, $this->requests );
	}

	public function test_the_refresh_handler_reports_success(): void {
		$this->runHandler( array( 'GuardLMS_Realtime_Page', 'handle_refresh' ) );

		$notice = $this->transients[ GuardLMS_Realtime_Page::NOTICE_TRANSIENT ];
		$this->assertSame( 'success', $notice['type'] );
	}

	public function test_the_refresh_handler_reports_a_failure(): void {
		Functions\when( 'wp_remote_post' )->justReturn( new WP_Error( 'http_request_failed', 'timed out' ) );

		$this->runHandler( array( 'GuardLMS_Realtime_Page', 'handle_refresh' ) );

		$notice = $this->transients[ GuardLMS_Realtime_Page::NOTICE_TRANSIENT ];
		$this->assertSame( 'error', $notice['type'] );
		$this->assertSame( 'timed out', $notice['message'] );
	}

	/**
	 * §5.3 row 2 shows nothing on a page render, but a button click still needs
	 * an answer - silence after an explicit action reads as a broken button.
	 */
	public function test_the_refresh_handler_explains_an_unsupported_backend(): void {
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 404 );

		$this->runHandler( array( 'GuardLMS_Realtime_Page', 'handle_refresh' ) );

		$notice = $this->transients[ GuardLMS_Realtime_Page::NOTICE_TRANSIENT ];
		$this->assertSame( 'info', $notice['type'] );
		$this->assertStringContainsString( 'does not support real-time monitoring yet', $notice['message'] );
		$this->assertFalse( GuardLMS_Sdk_Config::get( 'backend_supported' ) );
	}

	// --- rotate ---------------------------------------------------------------

	/**
	 * `rotate` must be reachable ONLY from this explicit, nonce-guarded button.
	 * Every automatic path sends `fetch`, so no bug or retry loop can churn a
	 * site's credential.
	 */
	public function test_the_rotate_handler_is_the_only_source_of_a_rotate_action(): void {
		$this->runHandler( array( 'GuardLMS_Realtime_Page', 'handle_rotate' ) );

		$this->assertSame( 'rotate', json_decode( $this->requests[0]['body'], true )['action'] );
		$this->assertSame( 'glms_rotated', GuardLMS_Credentials::get_sdk_key() );
	}

	/**
	 * A source scan, because the guarantee above is about what does NOT exist.
	 * Only the rotate handler may name the rotate action.
	 */
	public function test_no_automatic_code_path_sends_rotate(): void {
		$files = array(
			'includes/class-guardlms-cron.php',
			'includes/class-guardlms-connect-manager.php',
			'includes/class-guardlms-sdk-injector.php',
			'includes/admin/class-guardlms-settings.php',
		);

		foreach ( $files as $file ) {
			$source = php_strip_whitespace( GUARDLMS_PLUGIN_DIR . $file );
			$this->assertStringNotContainsString(
				"'rotate'",
				$source,
				"{$file} references the rotate action; only the admin button may."
			);
		}

		// And the one place that does, does.
		$page = php_strip_whitespace( GUARDLMS_PLUGIN_DIR . 'includes/admin/class-guardlms-realtime-page.php' );
		$this->assertStringContainsString( "resolve( 'rotate'", $page );
	}
}
