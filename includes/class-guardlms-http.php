<?php
/**
 * GuardLMS HTTP transport wrapper.
 *
 * @package    GuardLMS
 * @copyright  2026 Luuk Verhoeven, ldesignmedia.nl <info@ldesignmedia.nl>
 * @author     Hamza Tamyachte
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/*
 * GuardLMS for WordPress
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
 * Thin wrapper around wp_remote_post() with GuardLMS transport defaults.
 */
class GuardLMS_Http {

	/**
	 * POST a JSON body to the given URL.
	 *
	 * Hardened defaults: no redirects are followed and unsafe URLs are
	 * rejected so the server-to-server call cannot be steered elsewhere.
	 *
	 * @param string $url       Absolute request URL.
	 * @param string $json_body Pre-encoded JSON request body.
	 * @param array  $headers   Additional request headers (merged over the JSON defaults).
	 * @param int    $timeout   Transport timeout in seconds. The 30s default suits a
	 *                          background push; a call made while an admin waits on a
	 *                          page render passes a short value instead.
	 * @return array|WP_Error   array( 'code' => int, 'body' => string ) on transport
	 *                          success, or WP_Error on transport failure.
	 */
	public static function post( string $url, string $json_body, array $headers = array(), int $timeout = 30 ) {
		$default_headers = array(
			'Content-Type' => 'application/json',
			'Accept'       => 'application/json',
		);

		$args = array(
			'timeout'            => max( 1, $timeout ),
			'redirection'        => 0,
			'reject_unsafe_urls' => true,
			'headers'            => array_merge( $default_headers, $headers ),
			'body'               => $json_body,
		);

		$response = wp_remote_post( $url, $args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return array(
			'code' => (int) wp_remote_retrieve_response_code( $response ),
			'body' => (string) wp_remote_retrieve_body( $response ),
		);
	}
}
