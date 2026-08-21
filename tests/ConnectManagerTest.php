<?php
/**
 * Unit tests for GuardLMS_Connect_Manager (Phase 2 keyless Connect state machine).
 *
 * Exercises the full start/complete/disconnect lifecycle. The single-use `state`
 * is the security control for the public REST callback, so its validation
 * (mismatch / expiry / single-use replay) and the base-URL binding get the most
 * coverage. Options are backed by a small in-memory store so a replay of the same
 * callback URL genuinely re-reads the consumed state.
 *
 * GuardLMS_Api_Client::exchange() is a static method Brain Monkey cannot stub, so
 * the real client runs and wp_remote_post() beneath it returns canned responses.
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
require_once GUARDLMS_PLUGIN_DIR . 'includes/class-guardlms-api-client.php';
require_once GUARDLMS_PLUGIN_DIR . 'includes/class-guardlms-sdk-config.php';
require_once GUARDLMS_PLUGIN_DIR . 'includes/class-guardlms-sdk-status.php';
require_once GUARDLMS_PLUGIN_DIR . 'includes/class-guardlms-sdk-client.php';
require_once GUARDLMS_PLUGIN_DIR . 'includes/class-guardlms-connect-manager.php';

/**
 * @covers GuardLMS_Connect_Manager
 */
final class ConnectManagerTest extends AbstractGuardLMSTestCase {

	/**
	 * In-memory option store keyed by option name (guardlms_settings, etc.).
	 *
	 * @var array<string,mixed>
	 */
	private $store = array();

	/**
	 * wp_schedule_single_event() calls captured as (timestamp, hook) tuples.
	 *
	 * @var array[]
	 */
	private $scheduled = array();

	/**
	 * Number of times the transport (exchange) was invoked.
	 *
	 * @var int
	 */
	private $exchangeCalls = 0;

	protected function setUp(): void {
		parent::setUp();
		$this->store         = array( 'guardlms_settings' => array() );
		$this->scheduled     = array();
		$this->exchangeCalls = 0;

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
		Functions\when( 'delete_option' )->alias(
			function ( $name ) {
				unset( $this->store[ $name ] );
				return true;
			}
		);
		Functions\when( 'wp_schedule_single_event' )->alias(
			function ( $timestamp, $hook ) {
				$this->scheduled[] = array( $timestamp, $hook );
				return true;
			}
		);
	}

	/**
	 * Overwrite the guardlms_settings store with the given values (over defaults).
	 *
	 * @param array $settings Settings to seed.
	 * @return void
	 */
	private function seedSettings( array $settings ): void {
		$this->store['guardlms_settings'] = $settings;
	}

	/**
	 * Current guardlms_settings as written back through GuardLMS_Options::update().
	 *
	 * @return array
	 */
	private function settings(): array {
		return isset( $this->store['guardlms_settings'] ) && is_array( $this->store['guardlms_settings'] )
			? $this->store['guardlms_settings']
			: array();
	}

	/**
	 * The canned exchange `data` envelope the backend returns on success.
	 *
	 * @return array
	 */
	private function exchangeData(): array {
		return array(
			'token'              => 'push-key-abc',
			'pushpath'           => '/api/externalpush/wordpress',
			'verification_token' => 'verify-xyz',
			'website_id'         => 42,
			'expires_at'         => '2027-01-01T00:00:00+00:00',
		);
	}

	/**
	 * Mock every cache-plugin flush purge_caches() may call.
	 *
	 * Brain Monkey stubs persist as real PHP function symbols for the rest of the
	 * process, so once any test defines these, function_exists() stays true and a
	 * later complete_connect() will call them. Mocking them here keeps that call
	 * safe regardless of test order.
	 *
	 * @return void
	 */
	private function stubCachePlugins(): void {
		Functions\when( 'w3tc_flush_all' )->justReturn( null );
		Functions\when( 'litespeed_purge_all' )->justReturn( null );
		Functions\when( 'rocket_clean_domain' )->justReturn( null );
		Functions\when( 'wp_cache_clear_cache' )->justReturn( null );
	}

	/**
	 * Stub the exchange transport with a 200 + canned data envelope and capture
	 * the outgoing request so base-URL binding can be asserted.
	 *
	 * @param array $captured Reference filled with the exchange url/body.
	 * @param array $extra    Extra keys merged into the returned `data` envelope.
	 * @return void
	 */
	private function stubExchangeSuccess( array &$captured, array $extra = array() ): void {
		$this->stubCachePlugins();
		Functions\when( 'wp_json_encode' )->alias(
			static function ( $data ) {
				return json_encode( $data );
			}
		);
		Functions\when( 'wp_remote_post' )->alias(
			function ( $url, $args ) use ( &$captured ) {
				++$this->exchangeCalls;
				$captured = array(
					'url'  => $url,
					'body' => $args['body'],
				);
				return array( 'stubbed' => true );
			}
		);
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn(
			(string) json_encode( array( 'data' => array_merge( $this->exchangeData(), $extra ) ) )
		);
	}

	// --- start_connect() -----------------------------------------------------

	public function test_start_connect_stores_state_and_builds_consent_url(): void {
		$this->seedSettings( array( 'baseurl' => 'https://backend.test' ) );

		Functions\when( 'home_url' )->justReturn( 'https://site.test/' );
		Functions\when( 'rest_url' )->justReturn( 'https://site.test/wp-json/guardlms/v1/connect-callback' );
		Functions\when( 'esc_url_raw' )->returnArg( 1 );
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

		$before = time();
		$url    = GuardLMS_Connect_Manager::start_connect();
		$after  = time();

		$saved = $this->settings();

		// State is a freshly minted 40-hex token, bound with expiry + base URL.
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{40}$/', $saved['connectstate'] );
		$this->assertGreaterThanOrEqual( $before + GuardLMS_Connect_Manager::STATE_TTL, $saved['connectstateexpires'] );
		$this->assertLessThanOrEqual( $after + GuardLMS_Connect_Manager::STATE_TTL, $saved['connectstateexpires'] );
		$this->assertSame( 'https://backend.test', $saved['connectstate_baseurl'] );

		// Consent URL targets the wordpress consent path on the configured host.
		$this->assertStringStartsWith( 'https://backend.test/connect/wordpress?', $url );
		// siteurl is the rtrim'd, url-encoded home URL.
		$this->assertStringContainsString( 'siteurl=' . rawurlencode( 'https://site.test' ), $url );
		// The exact minted state travels in the URL.
		$this->assertStringContainsString( 'state=' . $saved['connectstate'], $url );
		// The REST callback is passed url-encoded (survives on plain permalinks).
		$this->assertStringContainsString(
			'callback=' . rawurlencode( 'https://site.test/wp-json/guardlms/v1/connect-callback' ),
			$url
		);
	}

	// --- complete_connect(): happy path --------------------------------------

	public function test_complete_connect_valid_state_stores_credentials_and_options(): void {
		$state = bin2hex( random_bytes( 20 ) );
		$this->seedSettings(
			array(
				'connectstate'         => $state,
				'connectstateexpires'  => time() + 300,
				'connectstate_baseurl' => 'https://backend.test',
				// A later settings edit points baseurl elsewhere; the exchange must
				// still use the BOUND base URL, not this one.
				'baseurl'              => 'https://evil.test',
			)
		);

		Functions\when( 'home_url' )->justReturn( 'https://site.test/' );
		$captured = array();
		$this->stubExchangeSuccess( $captured );

		$result = GuardLMS_Connect_Manager::complete_connect( 'code123', $state );

		$this->assertTrue( $result );

		// Exchange hit the BOUND base URL and carried code + siteurl + state.
		$this->assertSame( 'https://backend.test/api/integrations/exchange', $captured['url'] );
		$this->assertSame(
			array(
				'code'    => 'code123',
				'siteurl' => 'https://site.test',
				'state'   => $state,
			),
			json_decode( $captured['body'], true )
		);

		// Secret key stored out-of-band in the credentials option.
		$this->assertSame( array( 'apikey' => 'push-key-abc' ), $this->store['guardlms_credentials'] );

		// Connection options persisted.
		$saved = $this->settings();
		$this->assertSame( 42, $saved['websiteid'] );
		$this->assertSame( strtotime( '2027-01-01T00:00:00+00:00' ), $saved['keyexpiresat'] );
		$this->assertGreaterThan( 0, $saved['connectedat'] );
		$this->assertSame( 'https://site.test', $saved['connected_siteurl'] );
		$this->assertTrue( $saved['enabled'] );
		$this->assertSame( '/api/externalpush/wordpress', $saved['pushpath'] );
		$this->assertSame( 'verify-xyz', $saved['verificationtoken'] );

		// State was consumed.
		$this->assertSame( '', $saved['connectstate'] );
		$this->assertSame( 0, $saved['connectstateexpires'] );

		// Immediate one-off push scheduled on the initial hook.
		$this->assertCount( 1, $this->scheduled );
		$this->assertSame( GUARDLMS_INITIAL_HOOK, $this->scheduled[0][1] );
		$this->assertGreaterThan( time(), $this->scheduled[0][0] );
	}

	public function test_complete_connect_reconnect_preserves_disabled_reporting(): void {
		// Already connected but reporting turned OFF. A reconnect (key refresh) must
		// preserve the admin's choice, not silently re-enable daily reporting.
		$state = bin2hex( random_bytes( 20 ) );
		$this->store['guardlms_credentials'] = array( 'apikey' => 'old-key' );
		$this->seedSettings(
			array(
				'connectstate'         => $state,
				'connectstateexpires'  => time() + 300,
				'connectstate_baseurl' => 'https://backend.test',
				'connectedat'          => time() - 100,
				'enabled'              => false,
			)
		);

		Functions\when( 'home_url' )->justReturn( 'https://site.test/' );
		$captured = array();
		$this->stubExchangeSuccess( $captured );

		$result = GuardLMS_Connect_Manager::complete_connect( 'code123', $state );

		$this->assertTrue( $result );
		// enabled stays false across the reconnect.
		$this->assertFalse( $this->settings()['enabled'] );
	}

	// --- complete_connect(): state machine failures --------------------------

	public function test_complete_connect_mismatched_state_errors_and_keeps_pending_state(): void {
		$pending = bin2hex( random_bytes( 20 ) );
		$expires = time() + 300;
		$this->seedSettings(
			array(
				'connectstate'         => $pending,
				'connectstateexpires'  => $expires,
				'connectstate_baseurl' => 'https://backend.test',
			)
		);
		Functions\expect( 'wp_remote_post' )->never();

		$result = GuardLMS_Connect_Manager::complete_connect( 'code123', bin2hex( random_bytes( 20 ) ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'guardlms_connectstate', $result->get_error_code() );
		// No credentials were written.
		$this->assertArrayNotHasKey( 'guardlms_credentials', $this->store );
		$this->assertCount( 0, $this->scheduled );
		// LOW-1 DoS fix: the state is validated BEFORE it is cleared, so a bogus /
		// forged state on the public callback must NOT wipe the legitimate admin's
		// live pending connect. The stored state (and its expiry) stay intact.
		$this->assertSame( $pending, $this->settings()['connectstate'] );
		$this->assertSame( $expires, $this->settings()['connectstateexpires'] );
	}

	public function test_complete_connect_empty_stored_state_errors(): void {
		// No prior start_connect(): stored state is empty.
		$this->seedSettings( array( 'connectstate' => '' ) );
		Functions\expect( 'wp_remote_post' )->never();

		$result = GuardLMS_Connect_Manager::complete_connect( 'code123', bin2hex( random_bytes( 20 ) ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'guardlms_connectstate', $result->get_error_code() );
	}

	public function test_complete_connect_expired_state_errors_without_exchange(): void {
		$state   = bin2hex( random_bytes( 20 ) );
		$expires = time() - 10;
		$this->seedSettings(
			array(
				'connectstate'         => $state,
				'connectstateexpires'  => $expires,
				'connectstate_baseurl' => 'https://backend.test',
			)
		);
		Functions\expect( 'wp_remote_post' )->never();

		$result = GuardLMS_Connect_Manager::complete_connect( 'code123', $state );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'guardlms_connectstate', $result->get_error_code() );
		$this->assertStringContainsString( 'expired', $result->get_error_message() );
		// The state is consumed only after BOTH the match and expiry checks pass, so
		// an expired (but matching) state is left intact rather than cleared here.
		$this->assertSame( $state, $this->settings()['connectstate'] );
		$this->assertSame( $expires, $this->settings()['connectstateexpires'] );
	}

	public function test_complete_connect_is_single_use_and_rejects_replay(): void {
		$state = bin2hex( random_bytes( 20 ) );
		$this->seedSettings(
			array(
				'connectstate'         => $state,
				'connectstateexpires'  => time() + 300,
				'connectstate_baseurl' => 'https://backend.test',
			)
		);

		Functions\when( 'home_url' )->justReturn( 'https://site.test/' );
		$captured = array();
		$this->stubExchangeSuccess( $captured );

		// First use succeeds and consumes the (confirmed, matching) state.
		$first = GuardLMS_Connect_Manager::complete_connect( 'code123', $state );
		$this->assertTrue( $first );

		// Replay of the very same callback URL must fail: the matching state was
		// consumed on the first success, so the second call now reads an empty
		// stored state and is rejected before any exchange runs.
		$second = GuardLMS_Connect_Manager::complete_connect( 'code123', $state );
		$this->assertInstanceOf( WP_Error::class, $second );
		$this->assertSame( 'guardlms_connectstate', $second->get_error_code() );

		$this->assertSame( 1, $this->exchangeCalls );
	}

	public function test_complete_connect_propagates_exchange_wp_error(): void {
		$state = bin2hex( random_bytes( 20 ) );
		$this->seedSettings(
			array(
				'connectstate'         => $state,
				'connectstateexpires'  => time() + 300,
				'connectstate_baseurl' => 'https://backend.test',
			)
		);

		Functions\when( 'home_url' )->justReturn( 'https://site.test/' );
		Functions\when( 'wp_json_encode' )->alias(
			static function ( $data ) {
				return json_encode( $data );
			}
		);
		$transport_error = new WP_Error( 'http_request_failed', 'timed out' );
		Functions\when( 'wp_remote_post' )->justReturn( $transport_error );

		$result = GuardLMS_Connect_Manager::complete_connect( 'code123', $state );

		$this->assertSame( $transport_error, $result );
		// Failed exchange stores no credentials and schedules no push.
		$this->assertArrayNotHasKey( 'guardlms_credentials', $this->store );
		$this->assertCount( 0, $this->scheduled );
		$this->assertSame( 0, (int) $this->settings()['connectedat'] );
	}

	// --- is_connected() ------------------------------------------------------

	public function test_is_connected_true_when_key_and_connectedat_present(): void {
		$this->store['guardlms_credentials'] = array( 'apikey' => 'push-key-abc' );
		$this->seedSettings( array( 'connectedat' => 1700000000 ) );

		$this->assertTrue( GuardLMS_Connect_Manager::is_connected() );
	}

	public function test_is_connected_false_without_key(): void {
		$this->store['guardlms_credentials'] = array( 'apikey' => '' );
		$this->seedSettings( array( 'connectedat' => 1700000000 ) );

		$this->assertFalse( GuardLMS_Connect_Manager::is_connected() );
	}

	public function test_is_connected_false_when_connectedat_zero(): void {
		$this->store['guardlms_credentials'] = array( 'apikey' => 'push-key-abc' );
		$this->seedSettings( array( 'connectedat' => 0 ) );

		$this->assertFalse( GuardLMS_Connect_Manager::is_connected() );
	}

	// --- refused-key state ---------------------------------------------------

	public function test_a_site_whose_key_still_works_is_not_in_the_refused_state(): void {
		$this->seedSettings( array( 'connectedat' => 1700000000 ) );

		$this->assertFalse( GuardLMS_Connect_Manager::is_auth_rejected() );
		$this->assertSame( 0, GuardLMS_Connect_Manager::auth_rejected_at() );
	}

	public function test_only_the_statuses_that_mean_a_refused_key_count(): void {
		$this->assertTrue( GuardLMS_Connect_Manager::is_rejected_status( 401 ) );
		$this->assertTrue( GuardLMS_Connect_Manager::is_rejected_status( 403 ) );
		// A backend outage, a rate limit and a URL mismatch are all recoverable
		// without a new key, so none of them may prompt a reconnect.
		$this->assertFalse( GuardLMS_Connect_Manager::is_rejected_status( 500 ) );
		$this->assertFalse( GuardLMS_Connect_Manager::is_rejected_status( 429 ) );
		$this->assertFalse( GuardLMS_Connect_Manager::is_rejected_status( 422 ) );
	}

	public function test_the_first_refusal_timestamp_survives_later_refusals(): void {
		$this->seedSettings( array( 'connectedat' => 1700000000 ) );

		GuardLMS_Connect_Manager::note_auth_rejected();
		$first = GuardLMS_Connect_Manager::auth_rejected_at();
		$this->assertGreaterThan( 0, $first );

		GuardLMS_Connect_Manager::note_auth_rejected();

		// "Since when did this site stop reporting" is the question the admin
		// screen answers, so a retry must not reset the clock.
		$this->assertSame( $first, GuardLMS_Connect_Manager::auth_rejected_at() );
	}

	public function test_an_accepted_call_clears_the_refused_state(): void {
		$this->seedSettings(
			array(
				'connectedat'    => 1700000000,
				'authrejectedat' => 1750000000,
			)
		);

		GuardLMS_Connect_Manager::note_auth_accepted();

		$this->assertFalse( GuardLMS_Connect_Manager::is_auth_rejected() );
	}

	public function test_the_refused_message_names_the_cause_and_the_remedy(): void {
		$message = GuardLMS_Connect_Manager::auth_rejected_message( 401 );

		$this->assertStringContainsString( '401', $message );
		$this->assertStringContainsString( 'Reconnect', $message );
	}

	// --- disconnect() --------------------------------------------------------

	public function test_disconnect_clears_key_and_connection_options(): void {
		$this->store['guardlms_credentials'] = array( 'apikey' => 'push-key-abc' );
		$this->seedSettings(
			array(
				'connectedat'       => 1700000000,
				'websiteid'         => 42,
				'verificationtoken' => 'verify-xyz',
				'keyexpiresat'      => 1800000000,
				'connected_siteurl' => 'https://site.test',
				'authrejectedat'    => 1750000000,
			)
		);

		GuardLMS_Connect_Manager::disconnect();

		// Secret key option removed.
		$this->assertArrayNotHasKey( 'guardlms_credentials', $this->store );
		$this->assertFalse( GuardLMS_Connect_Manager::is_connected() );

		// Connection options reset.
		$saved = $this->settings();
		$this->assertSame( 0, $saved['connectedat'] );
		$this->assertSame( 0, $saved['websiteid'] );
		$this->assertSame( '', $saved['verificationtoken'] );
		$this->assertSame( 0, $saved['keyexpiresat'] );
		$this->assertSame( '', $saved['connected_siteurl'] );
		// The revoke call above authenticates with the very key that was
		// refused, so it can re-stamp the flag on its way out. A site with no
		// key must not be told to reconnect a refused one.
		$this->assertSame( 0, $saved['authrejectedat'] );
	}

	// --- disconnect(): SDK revocation ----------------------------------------

	/**
	 * AC D11. The revoke call authenticates with the PUSH key, so it is
	 * unsendable once the credentials option is gone. Asserting only that both
	 * happen would pass against the broken order, so this asserts the observable
	 * consequence: the push key was still readable when the request went out.
	 */
	public function test_disconnect_revokes_before_deleting_the_push_key(): void {
		$this->store['guardlms_credentials']     = array( 'apikey' => 'push-key-abc' );
			$this->store['guardlms_sdk_credentials'] = array( 'sdkkey' => 'glms_live' );
		$this->seedSettings( array( 'connectedat' => 1700000000 ) );

		$seen = array();
		Functions\when( 'wp_json_encode' )->alias(
			static function ( $data ) {
				return json_encode( $data );
			}
		);
		Functions\when( 'esc_url_raw' )->returnArg( 1 );
		Functions\when( 'sanitize_text_field' )->returnArg( 1 );
		Functions\when( 'home_url' )->justReturn( 'https://site.test/' );
		Functions\when( 'wp_remote_post' )->alias(
			function ( $url, $args ) use ( &$seen ) {
				$seen[] = array(
					'url'  => $url,
					'auth' => $args['headers']['Authorization'] ?? '',
					'body' => $args['body'],
					// The credentials option must still exist at this point.
					'creds_present' => array_key_exists( 'guardlms_credentials', $this->store ),
				);
				return array( 'stubbed' => true );
			}
		);
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn(
			(string) json_encode( array( 'data' => array( 'key_status' => 'revoked' ) ) )
		);

		GuardLMS_Connect_Manager::disconnect();

		$this->assertCount( 1, $seen, 'disconnect() did not call the revoke endpoint.' );
		$this->assertSame( 'https://dashboard.guardlms.com/api/integrations/sdk-key', $seen[0]['url'] );
		$this->assertSame( 'revoke', json_decode( $seen[0]['body'], true )['action'] );
		// The push key was present and usable when the revoke went out.
		$this->assertSame( 'Bearer push-key-abc', $seen[0]['auth'] );
		$this->assertTrue( $seen[0]['creds_present'] );

		// ...and only afterwards was everything torn down.
		$this->assertArrayNotHasKey( 'guardlms_credentials', $this->store );
		$this->assertFalse( GuardLMS_Connect_Manager::is_connected() );
	}

	/**
	 * AC D11. A backend that is down, too old or throwing must never leave an
	 * admin unable to disconnect.
	 */
	public function test_disconnect_completes_when_the_revoke_call_fails(): void {
		$this->store['guardlms_credentials']     = array( 'apikey' => 'push-key-abc' );
			$this->store['guardlms_sdk_credentials'] = array( 'sdkkey' => 'glms_live' );
		$this->seedSettings(
			array(
				'connectedat' => 1700000000,
				'websiteid'   => 42,
				'sdk'         => array(
					'enabled'      => true,
					'sdk_url'      => 'https://cdn.test/x.js',
					'refreshed_at' => 1700000000,
				),
			)
		);

		Functions\when( 'wp_json_encode' )->alias(
			static function ( $data ) {
				return json_encode( $data );
			}
		);
		Functions\when( 'esc_url_raw' )->returnArg( 1 );
		Functions\when( 'sanitize_text_field' )->returnArg( 1 );
		Functions\when( 'home_url' )->justReturn( 'https://site.test/' );
		Functions\when( 'wp_remote_post' )->justReturn( new WP_Error( 'http_request_failed', 'timed out' ) );

		GuardLMS_Connect_Manager::disconnect();

		$this->assertArrayNotHasKey( 'guardlms_credentials', $this->store );
		$this->assertFalse( GuardLMS_Connect_Manager::is_connected() );
		// The real-time configuration is reset to defaults, not left half-torn-down.
		$this->assertSame( GuardLMS_Sdk_Config::defaults(), $this->settings()['sdk'] );
		$this->assertSame( 0, $this->settings()['websiteid'] );
	}

	/**
	 * delete() removes the whole credentials option; clear() then calls
	 * delete_sdk_key(). If that wrote unconditionally it would recreate the row
	 * delete() just removed, leaving an empty guardlms_credentials behind on
	 * every disconnect.
	 */
	public function test_disconnect_does_not_recreate_the_credentials_option(): void {
		$this->store['guardlms_credentials'] = array( 'apikey' => 'push-key-abc' );
		$this->seedSettings( array( 'connectedat' => 1700000000 ) );
		Functions\when( 'esc_url_raw' )->returnArg( 1 );
		Functions\when( 'home_url' )->justReturn( 'https://site.test/' );
		// No SDK key stored, so revoke is a no-op and no request is made.
		Functions\expect( 'wp_remote_post' )->never();

		GuardLMS_Connect_Manager::disconnect();

		$this->assertArrayNotHasKey( 'guardlms_credentials', $this->store );
	}

	// --- complete_connect(): the sdk block ------------------------------------

	/**
	 * The exchange carries the real-time settings, so a fresh connect needs no
	 * second round trip before the admin can switch the feature on.
	 */
	public function test_complete_connect_persists_the_sdk_block_from_the_exchange(): void {
		$state = bin2hex( random_bytes( 20 ) );
		$this->seedSettings(
			array(
				'connectstate'         => $state,
				'connectstateexpires'  => time() + 300,
				'connectstate_baseurl' => 'https://backend.test',
			)
		);

		Functions\when( 'home_url' )->justReturn( 'https://site.test/' );
		Functions\when( 'esc_url_raw' )->returnArg( 1 );
		Functions\when( 'sanitize_text_field' )->returnArg( 1 );
		$captured = array();
		$this->stubExchangeSuccess( $captured, array( 'sdk' => $this->sdkBlock() ) );

		$this->assertTrue( GuardLMS_Connect_Manager::complete_connect( 'code123', $state ) );

		// The key went to credentials, beside (not over) the push key.
		$this->assertSame( 'push-key-abc', $this->store['guardlms_credentials']['apikey'] );
		$this->assertSame( 'glms_fromexchange', $this->store['guardlms_sdk_credentials']['sdkkey'] );

		// The rest went to the nested settings array.
		$sdk = $this->settings()['sdk'];
		$this->assertSame( 'https://backend.test/sdk/guardlms.min.js?v=abc123', $sdk['sdk_url'] );
		$this->assertTrue( $sdk['backend_enabled'] );
		$this->assertTrue( $sdk['subscription_active'] );
		$this->assertGreaterThan( 0, $sdk['refreshed_at'] );

		// Opt-in stays OFF: connecting must not silently start shipping browser
		// telemetry the admin never agreed to.
		$this->assertFalse( $sdk['enabled'] );
		$this->assertFalse( $sdk['analytics'] );
	}

	/**
	 * A backend that predates PR-B returns no `sdk` key at all. That must be a
	 * silent no-op, not a fatal on the one code path that installs the push key.
	 */
	public function test_complete_connect_without_an_sdk_block_does_not_fatal(): void {
		$state = bin2hex( random_bytes( 20 ) );
		$this->seedSettings(
			array(
				'connectstate'         => $state,
				'connectstateexpires'  => time() + 300,
				'connectstate_baseurl' => 'https://backend.test',
			)
		);

		Functions\when( 'home_url' )->justReturn( 'https://site.test/' );
		$captured = array();
		$this->stubExchangeSuccess( $captured );

		$this->assertTrue( GuardLMS_Connect_Manager::complete_connect( 'code123', $state ) );
		$this->assertSame( 'push-key-abc', $this->store['guardlms_credentials']['apikey'] );
		$this->assertArrayNotHasKey( 'sdkkey', $this->store['guardlms_credentials'] );
		$this->assertArrayNotHasKey( 'sdk', $this->settings() );
	}

	/**
	 * `sdk: null` is the documented shape when the backend's SDK issuance threw
	 * but the push key was minted successfully. Connect must still succeed.
	 */
	public function test_complete_connect_with_a_null_sdk_block_still_succeeds(): void {
		$state = bin2hex( random_bytes( 20 ) );
		$this->seedSettings(
			array(
				'connectstate'         => $state,
				'connectstateexpires'  => time() + 300,
				'connectstate_baseurl' => 'https://backend.test',
			)
		);

		Functions\when( 'home_url' )->justReturn( 'https://site.test/' );
		$captured = array();
		$this->stubExchangeSuccess( $captured, array( 'sdk' => null ) );

		$this->assertTrue( GuardLMS_Connect_Manager::complete_connect( 'code123', $state ) );
		$this->assertArrayNotHasKey( 'sdkkey', $this->store['guardlms_credentials'] );
	}

	/**
	 * The sdk payload as PR-B's exchange emits it.
	 *
	 * @return array
	 */
	private function sdkBlock(): array {
		return array(
			'key'                   => 'glms_fromexchange',
			'key_status'            => 'issued',
			'key_prefix'            => 'glms_fro',
			'sdk_url'               => 'https://backend.test/sdk/guardlms.min.js?v=abc123',
			'errors_endpoint'       => 'https://backend.test/api/sdk/errors/collect',
			'analytics_endpoint'    => 'https://backend.test/api/sdk/analytics/collect',
			'enabled'               => true,
			'subscription_active'   => true,
			'analytics_allowed'     => false,
			'sample_rate'           => 1.0,
			'analytics_sample_rate' => 1.0,
			'max_breadcrumbs'       => 50,
			'max_errors_per_minute' => 60,
			'ignored_errors'        => array(),
			'allowed_domains'       => array(),
			'allowed_domains_match' => true,
		);
	}

	// --- purge_caches() ------------------------------------------------------

	public function test_purge_caches_flushes_each_available_cache_plugin(): void {
		// Defining these via Brain Monkey makes function_exists() true so every
		// branch is taken.
		Functions\expect( 'w3tc_flush_all' )->once();
		Functions\expect( 'litespeed_purge_all' )->once();
		Functions\expect( 'rocket_clean_domain' )->once();
		Functions\expect( 'wp_cache_clear_cache' )->once();

		GuardLMS_Connect_Manager::purge_caches();
	}
}
