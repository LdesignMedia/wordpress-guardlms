<?php
/**
 * Real-time monitoring key/settings client (POST /api/integrations/sdk-key).
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
 * Fetches, rotates and revokes this site's real-time monitoring credential.
 *
 * Authenticated with the server-to-server push key, so the endpoint is reachable
 * without reconnecting. The plugin NEVER auto-rotates: the scheduled refresh and
 * the settings-page bootstrap always send `fetch`, which is a pure read that
 * returns `key: null` once a key has been issued. `rotate` is reachable only
 * from an explicit, nonce-guarded admin button.
 */
class GuardLMS_Sdk_Client {

	/**
	 * Fixed endpoint path on the GuardLMS host.
	 *
	 * @var string
	 */
	const PATH = '/api/integrations/sdk-key';

	/**
	 * Transient guarding the one-shot synchronous bootstrap fetch.
	 *
	 * @var string
	 */
	const BOOTSTRAP_LOCK = 'guardlms_sdk_bootstrap_lock';

	/**
	 * Lifetime of the bootstrap lock, in seconds.
	 *
	 * @var int
	 */
	const BOOTSTRAP_LOCK_TTL = 300;

	/**
	 * Timeout for a request made while an admin waits on a page render, in seconds.
	 *
	 * @var int
	 */
	const INTERACTIVE_TIMEOUT = 5;

	/**
	 * Return value meaning "this backend predates the real-time feature".
	 *
	 * Not a WP_Error: HTTP 404/405 is §5.3 row 2, which raises no admin error at
	 * all and hides the real-time section entirely.
	 *
	 * @var string
	 */
	const UNSUPPORTED = 'unsupported';

	/**
	 * Return value meaning "there was nothing to do".
	 *
	 * @var string
	 */
	const NOOP = 'noop';

	/**
	 * Resolve the site's real-time credential and settings.
	 *
	 * @param string $action  One of `fetch`, `rotate`, `revoke`.
	 * @param int    $timeout Transport timeout in seconds.
	 * @return array|string|WP_Error The decoded `data` payload, one of the
	 *                               UNSUPPORTED / NOOP sentinels, or WP_Error.
	 */
	public static function resolve( string $action = 'fetch', int $timeout = 30 ) {
		if ( ! in_array( $action, array( 'fetch', 'rotate', 'revoke' ), true ) ) {
			return new WP_Error(
				'guardlms_sdkaction',
				__( 'Unknown GuardLMS real-time action.', 'guardlms' )
			);
		}

		$pushkey = GuardLMS_Credentials::get_key();
		if ( '' === $pushkey ) {
			return new WP_Error(
				'guardlms_sdknotconnected',
				__( 'This site is not connected to GuardLMS, so real-time settings cannot be requested.', 'guardlms' )
			);
		}

		// Nothing to revoke means no request: a disconnect on a site that never
		// opted in must not fire a pointless call that can 429 or error.
		if ( 'revoke' === $action && '' === GuardLMS_Credentials::get_sdk_key() ) {
			return self::NOOP;
		}

		$baseurl = rtrim( (string) GuardLMS_Options::get( 'baseurl' ), '/' );
		if ( '' === $baseurl ) {
			$baseurl = rtrim( GUARDLMS_DEFAULT_BASEURL, '/' );
		}

		$body = wp_json_encode(
			array(
				'siteurl'  => rtrim( home_url(), '/' ),
				'platform' => 'wordpress',
				'action'   => $action,
			)
		);

		// GuardLMS_Http::post() takes a pre-encoded JSON STRING, not an array.
		$response = GuardLMS_Http::post(
			$baseurl . self::PATH,
			(string) $body,
			array( 'Authorization' => 'Bearer ' . $pushkey ),
			$timeout
		);

		if ( is_wp_error( $response ) ) {
			GuardLMS_Sdk_Config::record_error( $response->get_error_message() );

			return $response;
		}

		$status = (int) $response['code'];

		// 404/405: the backend does not implement this endpoint yet. The site
		// owner cannot act on that, so it is recorded and shown to nobody.
		if ( 404 === $status || 405 === $status ) {
			GuardLMS_Sdk_Config::mark_unsupported();

			return self::UNSUPPORTED;
		}

		// A refused key is a connection problem, not a real-time problem. Saying
		// "HTTP 401" here sends the admin looking at the real-time settings,
		// which is the one thing that cannot fix it - so name the actual cause
		// and the actual remedy.
		if ( GuardLMS_Connect_Manager::is_rejected_status( $status ) ) {
			GuardLMS_Connect_Manager::note_auth_rejected();

			$message = GuardLMS_Connect_Manager::auth_rejected_message( $status );
			GuardLMS_Sdk_Config::record_error( $message );

			return new WP_Error( 'guardlms_sdkrejected', $message, array( 'code' => $status ) );
		}

		if ( $status < 200 || $status >= 300 ) {
			$message = sprintf(
				/* translators: %d: the HTTP status code returned by GuardLMS. */
				__( 'GuardLMS rejected the real-time settings request (HTTP %d).', 'guardlms' ),
				$status
			);
			GuardLMS_Sdk_Config::record_error( $message );

			return new WP_Error( 'guardlms_sdkhttp', $message, array( 'code' => $status ) );
		}

		$decoded = json_decode( (string) $response['body'], true );
		if ( ! is_array( $decoded ) || ! isset( $decoded['data'] ) || ! is_array( $decoded['data'] ) ) {
			$message = __( 'GuardLMS returned an unexpected real-time settings response.', 'guardlms' );
			GuardLMS_Sdk_Config::record_error( $message );

			return new WP_Error( 'guardlms_sdkparse', $message );
		}

		GuardLMS_Sdk_Config::store_payload( $decoded['data'] );

		// The key just authenticated, so clear any refusal an earlier call
		// recorded - a reconnect elsewhere, or a backend that has come back,
		// must not leave the admin screen stuck on "reconnect required".
		GuardLMS_Connect_Manager::note_auth_accepted();

		return $decoded['data'];
	}

	/**
	 * Fetch the real-time settings synchronously when this site has never had any.
	 *
	 * Deliberately NOT cron-driven: wp_schedule_single_event() is dead on any
	 * site running DISABLE_WP_CRON or receiving no traffic, and "I turned it on
	 * and nothing happened" is the failure this exists to prevent. An
	 * admin-initiated page render is the right place for a blocking call, and
	 * five seconds is short enough not to need a spinner.
	 *
	 * @return bool Whether a request was issued on this call.
	 */
	public static function maybe_bootstrap() {
		if ( ! GuardLMS_Connect_Manager::is_connected() ) {
			return false;
		}

		if ( 0 !== (int) GuardLMS_Sdk_Config::get( 'refreshed_at' ) ) {
			return false;
		}

		if ( false !== get_transient( self::BOOTSTRAP_LOCK ) ) {
			return false;
		}

		// Set the lock FIRST and unconditionally, BEFORE the request is issued.
		// Setting it after a successful response would mean a backend that hangs
		// makes every page reload start a fresh request - precisely the storm the
		// lock exists to prevent.
		set_transient( self::BOOTSTRAP_LOCK, 1, self::BOOTSTRAP_LOCK_TTL );

		self::resolve( 'fetch', self::INTERACTIVE_TIMEOUT );

		return true;
	}
}
