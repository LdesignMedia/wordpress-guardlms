<?php
/**
 * GuardLMS Connect manager (keyless OAuth-style connect state machine).
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
 * Drives the keyless Connect flow: mints the state, builds the consent URL, and
 * completes the server-to-server exchange after the backend redirects back.
 *
 * The single-use `state` (40 hex chars, 900s TTL, hash_equals compared, cleared
 * before the exchange) is the security control for the public REST callback. The
 * GuardLMS base URL is BOUND into the state record at start time so that a later
 * settings edit cannot redirect the exchange to a different host.
 */
class GuardLMS_Connect_Manager {

	/**
	 * Lifetime of a pending connect state, in seconds.
	 *
	 * @var int
	 */
	const STATE_TTL = 900;

	/**
	 * Begin a connect attempt: mint + store the state and return the consent URL.
	 *
	 * @return string The GuardLMS consent URL to redirect the admin browser to.
	 */
	public static function start_connect(): string {
		$state   = bin2hex( random_bytes( 20 ) );
		$baseurl = rtrim( (string) GuardLMS_Options::get( 'baseurl' ), '/' );

		// Never build a hostless consent URL: fall back to the default host if the
		// base URL setting has been cleared, so the redirect always targets GuardLMS.
		if ( '' === $baseurl ) {
			$baseurl = rtrim( GUARDLMS_DEFAULT_BASEURL, '/' );
		}

		GuardLMS_Options::update(
			array(
				'connectstate'         => $state,
				'connectstateexpires'  => time() + self::STATE_TTL,
				'connectstate_baseurl' => $baseurl,
			)
		);

		$callback = rest_url( 'guardlms/v1/connect-callback' );

		// add_query_arg() does not URL-encode values, so pre-encode anything that
		// carries reserved characters. The callback especially must survive intact:
		// on plain permalinks it is itself a `?rest_route=...` URL.
		$consent = add_query_arg(
			array(
				'siteurl'  => rawurlencode( rtrim( home_url(), '/' ) ),
				'state'    => $state,
				'callback' => rawurlencode( $callback ),
			),
			$baseurl . '/connect/wordpress'
		);

		return esc_url_raw( $consent );
	}

	/**
	 * Complete a connect attempt after the backend redirects back with code+state.
	 *
	 * Validates the single-use state (existence, hash_equals, TTL), exchanges the
	 * code for credentials against the BOUND base URL, then stores the key and
	 * connection options, queues an immediate push, and purges page caches.
	 *
	 * @param string $code  One-time connect code from the backend.
	 * @param string $state State echoed back by the backend.
	 * @return true|WP_Error True on success, WP_Error on any failure.
	 */
	public static function complete_connect( string $code, string $state ) {
		$was_connected  = self::is_connected();
		$stored_state   = (string) GuardLMS_Options::get( 'connectstate' );
		$stored_expires = (int) GuardLMS_Options::get( 'connectstateexpires' );
		$stored_baseurl = (string) GuardLMS_Options::get( 'connectstate_baseurl' );

		// Validate BEFORE touching the stored state. The REST callback is public, so
		// clearing on a bogus/forged state would let an unauthenticated GET wipe a
		// legitimate admin's live pending connect — a remote DoS of the one-time action.
		if ( '' === $stored_state || '' === $state || ! hash_equals( $stored_state, $state ) ) {
			return new WP_Error(
				'guardlms_connectstate',
				__( 'The GuardLMS connection could not be verified (invalid or already-used request). Please try connecting again.', 'guardlms' )
			);
		}

		if ( $stored_expires < time() ) {
			return new WP_Error(
				'guardlms_connectstate',
				__( 'The GuardLMS connection request expired. Please try connecting again.', 'guardlms' )
			);
		}

		// Single use: only a CONFIRMED, matching state is consumed here, so a replay of
		// the same state now finds an empty stored value and fails the check above.
		GuardLMS_Options::update(
			array(
				'connectstate'        => '',
				'connectstateexpires' => 0,
			)
		);

		$siteurl = rtrim( home_url(), '/' );
		$data    = GuardLMS_Api_Client::exchange( $code, $siteurl, $state, $stored_baseurl );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		// Store the secret key out-of-band; it is never logged or echoed.
		GuardLMS_Credentials::set_key( (string) $data['token'] );

		$updates = array(
			'websiteid'         => isset( $data['website_id'] ) ? (int) $data['website_id'] : 0,
			'keyexpiresat'      => isset( $data['expires_at'] ) ? (int) ( strtotime( (string) $data['expires_at'] ) ?: 0 ) : 0,
			'connectedat'       => time(),
			'connected_siteurl' => $siteurl,
			// A first-time connect enables reporting; a reconnect (key refresh)
			// preserves the admin's current on/off choice rather than overriding it.
			'enabled'           => $was_connected ? (bool) GuardLMS_Options::get( 'enabled' ) : true,
		);

		if ( isset( $data['pushpath'] ) && '' !== $data['pushpath'] ) {
			$updates['pushpath'] = (string) $data['pushpath'];
		}
		if ( isset( $data['verification_token'] ) && '' !== $data['verification_token'] ) {
			$updates['verificationtoken'] = (string) $data['verification_token'];
		}

		GuardLMS_Options::update( $updates );

		// Push inventory immediately (also renders the ownership meta tag for the
		// backend's homepage/DNS ownership check).
		wp_schedule_single_event( time() + 5, GUARDLMS_INITIAL_HOOK );

		self::purge_caches();

		return true;
	}

	/**
	 * Whether this site currently holds a live GuardLMS connection.
	 *
	 * @return bool
	 */
	public static function is_connected(): bool {
		return GuardLMS_Credentials::has_key() && (int) GuardLMS_Options::get( 'connectedat' ) > 0;
	}

	/**
	 * Tear down the connection: drop the key and clear the connection options.
	 *
	 * @return void
	 */
	public static function disconnect(): void {
		GuardLMS_Credentials::delete();

		GuardLMS_Options::update(
			array(
				'connectedat'       => 0,
				'websiteid'         => 0,
				'verificationtoken' => '',
				'keyexpiresat'      => 0,
				'connected_siteurl' => '',
			)
		);
	}

	/**
	 * Purge common page caches so the ownership meta tag becomes visible promptly.
	 *
	 * Each cache plugin's flush is called only when its function is available.
	 *
	 * @return void
	 */
	public static function purge_caches(): void {
		if ( function_exists( 'w3tc_flush_all' ) ) {
			w3tc_flush_all();
		}
		if ( function_exists( 'litespeed_purge_all' ) ) {
			litespeed_purge_all();
		}
		if ( function_exists( 'rocket_clean_domain' ) ) {
			rocket_clean_domain();
		}
		if ( function_exists( 'wp_cache_clear_cache' ) ) {
			wp_cache_clear_cache();
		}
	}
}
