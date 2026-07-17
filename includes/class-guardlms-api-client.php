<?php
/**
 * GuardLMS Connect API client (server-to-server code exchange).
 *
 * @package GuardLMS
 * @license GPL-3.0-or-later
 */

/**
 * GuardLMS - WordPress site security reporting for GuardLMS.
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
 * Exchanges a one-time connect code for a push key against the GuardLMS backend.
 */
class GuardLMS_Api_Client {

	/**
	 * Fixed exchange endpoint path on the GuardLMS host.
	 *
	 * @var string
	 */
	const EXCHANGE_PATH = '/api/integrations/exchange';

	/**
	 * Exchange a one-time connect code for the site's push credentials.
	 *
	 * POSTs a JSON body of `{ code, siteurl, state }` to
	 * `{baseurl}/api/integrations/exchange` and returns the decoded `data`
	 * envelope on success. The `$baseurl` is the one BOUND to the connect
	 * attempt, never a later-edited setting.
	 *
	 * @param string $code    One-time code returned by the backend consent screen.
	 * @param string $siteurl This site's canonical URL (rtrim'd home_url()).
	 * @param string $state   The connect state echoed back for correlation.
	 * @param string $baseurl The GuardLMS base URL bound to this attempt.
	 * @return array|WP_Error The `data` array (must contain `token`) or WP_Error.
	 */
	public static function exchange( string $code, string $siteurl, string $state, string $baseurl ) {
		$endpoint = rtrim( $baseurl, '/' ) . self::EXCHANGE_PATH;

		$body = wp_json_encode(
			array(
				'code'    => $code,
				'siteurl' => $siteurl,
				'state'   => $state,
			)
		);

		$response = GuardLMS_Http::post( $endpoint, (string) $body );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status = (int) $response['code'];
		if ( $status < 200 || $status >= 300 ) {
			return new WP_Error(
				'guardlms_exchangehttp',
				sprintf(
					/* translators: %d: the HTTP status code returned by GuardLMS. */
					__( 'GuardLMS rejected the connect exchange (HTTP %d).', 'guardlms' ),
					$status
				),
				array( 'code' => $status )
			);
		}

		$decoded = json_decode( (string) $response['body'], true );
		if ( ! is_array( $decoded ) || ! isset( $decoded['data'] ) || ! is_array( $decoded['data'] ) ) {
			return new WP_Error(
				'guardlms_exchangeparse',
				__( 'GuardLMS returned an unexpected connect response.', 'guardlms' )
			);
		}

		$data = $decoded['data'];
		if ( empty( $data['token'] ) ) {
			return new WP_Error(
				'guardlms_exchangetoken',
				__( 'GuardLMS did not return a push key in the connect response.', 'guardlms' )
			);
		}

		return $data;
	}
}
