<?php
/**
 * Unit tests for GuardLMS_Http::post() (AC3: hardened transport defaults).
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

/**
 * @covers GuardLMS_Http
 */
final class HttpTest extends AbstractGuardLMSTestCase {

	public function test_post_sends_hardened_args_and_merged_headers(): void {
		$captured = null;
		Functions\when( 'wp_remote_post' )->alias(
			static function ( $url, $args ) use ( &$captured ) {
				$captured = array(
					'url'  => $url,
					'args' => $args,
				);
				return array( 'sentinel' => true );
			}
		);
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 201 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( 'created' );

		$result = GuardLMS_Http::post(
			'https://app.guardlms.com/api/externalpush/wordpress',
			'{"platform":"wordpress"}',
			array( 'Authorization' => 'Bearer TOKEN-123' )
		);

		$this->assertSame( 'https://app.guardlms.com/api/externalpush/wordpress', $captured['url'] );

		$args = $captured['args'];
		// SSRF / redirect hardening.
		$this->assertSame( 0, $args['redirection'] );
		$this->assertTrue( $args['reject_unsafe_urls'] );
		$this->assertSame( 30, $args['timeout'] );

		// Authorization header passes through; JSON defaults are set.
		$this->assertSame( 'Bearer TOKEN-123', $args['headers']['Authorization'] );
		$this->assertSame( 'application/json', $args['headers']['Content-Type'] );
		$this->assertSame( 'application/json', $args['headers']['Accept'] );

		$this->assertSame( '{"platform":"wordpress"}', $args['body'] );

		// Success returns the [code, body] shape.
		$this->assertSame(
			array(
				'code' => 201,
				'body' => 'created',
			),
			$result
		);
	}

	public function test_post_returns_wp_error_on_transport_failure(): void {
		$error = new WP_Error( 'http_request_failed', 'could not resolve host' );
		Functions\when( 'wp_remote_post' )->justReturn( $error );

		$result = GuardLMS_Http::post( 'https://app.guardlms.com/x', '{}' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( $error, $result );
	}
}
