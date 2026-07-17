<?php
/**
 * Unit tests for GuardLMS_Api_Client::exchange() (Phase 2 Connect: server-to-
 * server code exchange).
 *
 * The client calls GuardLMS_Http::post(), a thin static wrapper over
 * wp_remote_post(). Since Brain Monkey stubs functions (not static methods), the
 * real wrapper runs and wp_remote_post() is stubbed with canned responses — the
 * same [code, body] contract the client consumes.
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
require_once GUARDLMS_PLUGIN_DIR . 'includes/class-guardlms-http.php';
require_once GUARDLMS_PLUGIN_DIR . 'includes/class-guardlms-api-client.php';

/**
 * @covers GuardLMS_Api_Client
 */
final class ApiClientTest extends AbstractGuardLMSTestCase {

	protected function setUp(): void {
		parent::setUp();

		// Real JSON encoding so body assertions round-trip.
		Functions\when( 'wp_json_encode' )->alias(
			static function ( $data ) {
				return json_encode( $data );
			}
		);
	}

	/**
	 * Stub the transport with a canned HTTP status + body and capture the request.
	 *
	 * @param int    $code     HTTP status code to return.
	 * @param string $body     Response body to return.
	 * @param array  $captured Reference filled with the request url/body.
	 * @return void
	 */
	private function stubTransport( int $code, string $body, array &$captured ): void {
		Functions\when( 'wp_remote_post' )->alias(
			static function ( $url, $args ) use ( &$captured ) {
				$captured = array(
					'url'  => $url,
					'body' => $args['body'],
				);
				return array( 'stubbed' => true );
			}
		);
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( $code );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( $body );
	}

	/**
	 * A well-formed exchange envelope the backend returns on success.
	 *
	 * @return array
	 */
	private function successEnvelope(): array {
		return array(
			'data' => array(
				'token'              => 'push-key-abc',
				'pushpath'           => '/api/externalpush/wordpress',
				'verification_token' => 'verify-xyz',
				'website_id'         => 42,
				'expires_at'         => '2027-01-01T00:00:00+00:00',
			),
		);
	}

	public function test_posts_to_exchange_endpoint_with_expected_json_body(): void {
		$captured = array();
		$this->stubTransport( 200, (string) json_encode( $this->successEnvelope() ), $captured );

		$result = GuardLMS_Api_Client::exchange(
			'code123',
			'https://site.test',
			'statehex',
			'https://backend.test'
		);

		// POSTs to the fixed exchange endpoint on the given base URL.
		$this->assertSame( 'https://backend.test/api/integrations/exchange', $captured['url'] );

		// Body carries exactly code + siteurl + state.
		$this->assertSame(
			array(
				'code'    => 'code123',
				'siteurl' => 'https://site.test',
				'state'   => 'statehex',
			),
			json_decode( $captured['body'], true )
		);

		// 2xx returns the decoded data envelope.
		$this->assertIsArray( $result );
		$this->assertSame( 'push-key-abc', $result['token'] );
		$this->assertSame( 42, $result['website_id'] );
	}

	public function test_trailing_slash_on_baseurl_is_normalized(): void {
		$captured = array();
		$this->stubTransport( 200, (string) json_encode( $this->successEnvelope() ), $captured );

		GuardLMS_Api_Client::exchange( 'c', 's', 'st', 'https://backend.test/' );

		$this->assertSame( 'https://backend.test/api/integrations/exchange', $captured['url'] );
	}

	public function test_parses_full_data_envelope_on_2xx(): void {
		$captured = array();
		$this->stubTransport( 201, (string) json_encode( $this->successEnvelope() ), $captured );

		$result = GuardLMS_Api_Client::exchange( 'c', 's', 'st', 'https://backend.test' );

		$this->assertSame( '/api/externalpush/wordpress', $result['pushpath'] );
		$this->assertSame( 'verify-xyz', $result['verification_token'] );
		$this->assertSame( '2027-01-01T00:00:00+00:00', $result['expires_at'] );
	}

	public function test_missing_token_returns_wp_error(): void {
		$captured = array();
		$body     = (string) json_encode( array( 'data' => array( 'website_id' => 9 ) ) );
		$this->stubTransport( 200, $body, $captured );

		$result = GuardLMS_Api_Client::exchange( 'c', 's', 'st', 'https://backend.test' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'guardlms_exchangetoken', $result->get_error_code() );
	}

	public function test_empty_token_returns_wp_error(): void {
		$captured = array();
		$body     = (string) json_encode( array( 'data' => array( 'token' => '' ) ) );
		$this->stubTransport( 200, $body, $captured );

		$result = GuardLMS_Api_Client::exchange( 'c', 's', 'st', 'https://backend.test' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'guardlms_exchangetoken', $result->get_error_code() );
	}

	public function test_non_2xx_returns_http_wp_error_with_status(): void {
		$captured = array();
		$this->stubTransport( 500, '{"error":"boom"}', $captured );

		$result = GuardLMS_Api_Client::exchange( 'c', 's', 'st', 'https://backend.test' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'guardlms_exchangehttp', $result->get_error_code() );
		$this->assertSame( array( 'code' => 500 ), $result->get_error_data() );
	}

	public function test_unparseable_body_returns_parse_wp_error(): void {
		$captured = array();
		$this->stubTransport( 200, 'not-json-at-all', $captured );

		$result = GuardLMS_Api_Client::exchange( 'c', 's', 'st', 'https://backend.test' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'guardlms_exchangeparse', $result->get_error_code() );
	}

	public function test_body_without_data_key_returns_parse_wp_error(): void {
		$captured = array();
		$this->stubTransport( 200, '{"token":"loose"}', $captured );

		$result = GuardLMS_Api_Client::exchange( 'c', 's', 'st', 'https://backend.test' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'guardlms_exchangeparse', $result->get_error_code() );
	}

	public function test_transport_wp_error_is_passed_through(): void {
		$transport_error = new WP_Error( 'http_request_failed', 'could not resolve host' );
		Functions\when( 'wp_remote_post' )->justReturn( $transport_error );

		$result = GuardLMS_Api_Client::exchange( 'c', 's', 'st', 'https://backend.test' );

		$this->assertSame( $transport_error, $result );
	}
}
