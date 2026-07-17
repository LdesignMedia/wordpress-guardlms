<?php
/**
 * GuardLMS inventory pusher.
 *
 * @package GuardLMS
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
 * Builds the inventory payload and pushes it to the GuardLMS endpoint.
 */
class GuardLMS_Pusher {

	/**
	 * Collect the inventory and push it to GuardLMS.
	 *
	 * @return true|WP_Error True on a 2xx response, or WP_Error on any failure.
	 */
	public static function push() {
		$baseurl  = (string) GuardLMS_Options::get( 'baseurl' );
		$pushpath = (string) GuardLMS_Options::get( 'pushpath' );
		$key      = GuardLMS_Credentials::get_key();

		if ( '' === $key || '' === $baseurl ) {
			return new WP_Error(
				'guardlms_notconfigured',
				__( 'GuardLMS is not configured: a push key and base URL are required.', 'guardlms' )
			);
		}

		// get_plugins() is undefined in cron/CLI context, so load it before collecting.
		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		global $wp_version;
		if ( empty( $wp_version ) ) {
			return new WP_Error(
				'guardlms_degraded',
				__( 'GuardLMS refused to push: the WordPress core version is unavailable.', 'guardlms' )
			);
		}

		$payload     = GuardLMS_Collector::build_payload( (bool) GuardLMS_Options::get( 'sendconfig' ) );
		$plugincount = isset( $payload['wordpress']['plugincount'] ) ? (int) $payload['wordpress']['plugincount'] : 0;

		// Sanity delta: an implausible drop to zero would blind CVE detection, so never
		// overwrite a previously good inventory with an empty one.
		if ( (int) GuardLMS_Options::get( 'last_plugincount' ) > 0 && 0 === $plugincount ) {
			return new WP_Error(
				'guardlms_degraded',
				__( 'GuardLMS refused to push: the plugin inventory unexpectedly dropped to zero.', 'guardlms' )
			);
		}

		$endpoint = rtrim( $baseurl, '/' ) . '/' . ltrim( $pushpath, '/' );

		$response = GuardLMS_Http::post(
			$endpoint,
			(string) wp_json_encode( $payload ),
			array( 'Authorization' => 'Bearer ' . $key )
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) $response['code'];

		if ( $code < 200 || $code >= 300 ) {
			if ( 422 === $code ) {
				$registered = (string) GuardLMS_Options::get( 'connected_siteurl' );
				$actual     = rtrim( home_url(), '/' );

				return new WP_Error(
					'guardlms_pushhttp',
					sprintf(
						/* translators: 1: the site URL this install reports, 2: the site URL registered in GuardLMS. */
						__( 'GuardLMS rejected the push (HTTP 422): the site URL "%1$s" does not match the registered website "%2$s". Register "%1$s" in the GuardLMS dashboard or correct the mismatch.', 'guardlms' ),
						$actual,
						$registered
					),
					array( 'code' => $code )
				);
			}

			return new WP_Error(
				'guardlms_pushhttp',
				sprintf(
					/* translators: %d: the HTTP status code returned by GuardLMS. */
					__( 'GuardLMS rejected the push (HTTP %d).', 'guardlms' ),
					$code
				),
				array( 'code' => $code )
			);
		}

		GuardLMS_Options::update(
			array(
				'lastpush'         => time(),
				'lastpushstatus'   => $code,
				'last_plugincount' => $plugincount,
			)
		);

		return true;
	}
}
