<?php
/**
 * GuardLMS Connect manager (keyless OAuth-style connect state machine).
 *
 * @package    GuardLMS
 * @copyright  2026 Luuk Verhoeven, ldesignmedia.nl <info@ldesignmedia.nl>
 * @author     Hamza Tamyachte
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
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
	 * HTTP statuses that mean "GuardLMS refused this site's key".
	 *
	 * 401 is the key itself being gone or expired server-side; 403 is a key that
	 * still authenticates but is no longer bound to a website (the binding is
	 * cleared when the website is removed from the dashboard) or has lost the
	 * ability the endpoint requires. Neither recovers without a reconnect, and
	 * both otherwise leave the plugin reporting "Connected" forever.
	 *
	 * A reverse proxy answering 403 on its own would raise this state falsely.
	 * That is deliberate and cheap: the state only adds a notice and a
	 * Reconnect prompt, changes nothing about the stored key, and clears itself
	 * on the next accepted call.
	 *
	 * @var int[]
	 */
	const REJECTED_STATUSES = array( 401, 403 );

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
			'keyexpiresat'      => isset( $data['expires_at'] ) ? (int) strtotime( (string) $data['expires_at'] ) : 0,
			'connectedat'       => time(),
			'connected_siteurl' => $siteurl,
			// The fresh key clears whatever refusal the previous one collected;
			// reconnecting IS the recovery path this state points the admin at.
			'authrejectedat'    => 0,
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

		// Real-time monitoring settings ride along on the exchange, so a fresh
		// connect needs no second round trip and no cron run before the admin can
		// switch it on. Guarded, so an older backend that returns no `sdk` block
		// is a silent no-op rather than a fatal.
		if ( isset( $data['sdk'] ) && is_array( $data['sdk'] ) ) {
			GuardLMS_Sdk_Config::store_payload( $data['sdk'] );
		}

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
	 * Whether GuardLMS refused this site's key on its last authenticated call.
	 *
	 * A site in this state still holds a key and still reads as connected
	 * everywhere the key is used, which is exactly why the state is tracked:
	 * without it the admin screen keeps promising a live connection (with a
	 * key expiry a year out) while every push and settings refresh is refused.
	 *
	 * @return bool
	 */
	public static function is_auth_rejected(): bool {
		return self::auth_rejected_at() > 0;
	}

	/**
	 * When the first refused call since the last accepted one happened.
	 *
	 * @return int Unix timestamp, or 0 when the key is not in the refused state.
	 */
	public static function auth_rejected_at(): int {
		return max( 0, (int) GuardLMS_Options::get( 'authrejectedat' ) );
	}

	/**
	 * Whether an HTTP status from an authenticated call means the key was refused.
	 *
	 * @param int $status HTTP status code.
	 * @return bool
	 */
	public static function is_rejected_status( int $status ): bool {
		return in_array( $status, self::REJECTED_STATUSES, true );
	}

	/**
	 * Record that GuardLMS refused this site's key.
	 *
	 * Keeps the FIRST refusal's timestamp: the admin needs to know since when
	 * the site stopped reporting, not when the most recent retry ran.
	 *
	 * The key is deliberately NOT deleted. A backend outage that answers 401 for
	 * an hour must not cost the site its credential, and reconnecting is the
	 * admin's call, not the plugin's.
	 *
	 * @return void
	 */
	public static function note_auth_rejected(): void {
		if ( self::is_auth_rejected() ) {
			return;
		}

		GuardLMS_Options::set( 'authrejectedat', time() );
	}

	/**
	 * Record that GuardLMS accepted this site's key, clearing any refused state.
	 *
	 * @return void
	 */
	public static function note_auth_accepted(): void {
		if ( ! self::is_auth_rejected() ) {
			return;
		}

		GuardLMS_Options::set( 'authrejectedat', 0 );
	}

	/**
	 * The admin-facing explanation of a refused key, for the status block and
	 * for the WP_Error a refused push or settings refresh returns.
	 *
	 * @param int $status The HTTP status GuardLMS answered with.
	 * @return string
	 */
	public static function auth_rejected_message( int $status ): string {
		return sprintf(
			/* translators: %d: the HTTP status code returned by GuardLMS. */
			__( 'GuardLMS no longer accepts this site\'s connection key (HTTP %d). The key was revoked, or the website it belonged to was removed from the GuardLMS dashboard. This site has stopped reporting: use Reconnect to issue a new key.', 'guardlms' ),
			$status
		);
	}

	/**
	 * Tear down the connection: drop the key and clear the connection options.
	 *
	 * @return void
	 */
	public static function disconnect(): void {
		// Revoke the real-time credential FIRST: the revoke call authenticates
		// with the push key, so it is unsendable once the credentials option is
		// gone. Its result is deliberately ignored - a backend that is down or
		// too old must never leave an admin unable to disconnect.
		GuardLMS_Sdk_Client::resolve( 'revoke' );

		GuardLMS_Credentials::delete();
		GuardLMS_Sdk_Config::clear();

		GuardLMS_Options::update(
			array(
				'connectedat'       => 0,
				'websiteid'         => 0,
				'verificationtoken' => '',
				'keyexpiresat'      => 0,
				'connected_siteurl' => '',
				// Written last on purpose: the revoke call above authenticates
				// with the very key that may be refused, so it can set this
				// flag on its way out. A disconnected site has no key to warn
				// about.
				'authrejectedat'    => 0,
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
