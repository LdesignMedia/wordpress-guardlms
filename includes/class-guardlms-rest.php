<?php
/**
 * GuardLMS REST route for the browser-redirect Connect callback.
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
 * Registers and handles the `guardlms/v1/connect-callback` REST route.
 *
 * AUTH MODEL: the route is public (`permission_callback => __return_true`) on
 * purpose. It is an OAuth-style browser-redirect callback: a top-level
 * navigation cannot carry the X-WP-Nonce that WP REST cookie auth requires, so a
 * capability check here is not implementable. The SECURITY CONTROL is the
 * single-use `state` created inside GuardLMS_Connect_Manager::start_connect()
 * (which IS gated by manage_options + a nonce), stored server-side with a 900s
 * TTL, hash_equals compared, and consumed before the exchange. Do NOT add a
 * current_user_can() check here.
 */
class GuardLMS_Rest {

	/**
	 * REST namespace for GuardLMS routes.
	 *
	 * @var string
	 */
	const REST_NAMESPACE = 'guardlms/v1';

	/**
	 * Connect callback route (relative to the namespace).
	 *
	 * @var string
	 */
	const CALLBACK_ROUTE = '/connect-callback';

	/**
	 * Register the connect-callback route. Hooked to `rest_api_init`.
	 *
	 * @return void
	 */
	public static function register(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			self::CALLBACK_ROUTE,
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => array( __CLASS__, 'handle_callback' ),
			)
		);
	}

	/**
	 * Handle the connect callback: validate state, exchange, and redirect back.
	 *
	 * Always redirects the browser to the admin connect page (never emits JSON)
	 * and exits.
	 *
	 * @param WP_REST_Request $request The incoming REST request.
	 * @return void
	 */
	public static function handle_callback( $request ) {
		$code  = self::sanitize_code( (string) $request->get_param( 'code' ) );
		$state = self::sanitize_state( (string) $request->get_param( 'state' ) );

		$result = GuardLMS_Connect_Manager::complete_connect( $code, $state );

		$notice = null;
		if ( is_wp_error( $result ) ) {
			$flag = 'error';
			// The route is public, so only surface an error notice for a request
			// that actually carried both connect parameters. An unauthenticated GET
			// with missing/blank params must not write a transient or plant a notice
			// that a legitimate admin would then see on the Connect page.
			if ( '' !== $code && '' !== $state ) {
				$notice = array(
					'type'    => 'error',
					'message' => $result->get_error_message(),
				);
			}
		} else {
			$flag   = 'success';
			$notice = array(
				'type'    => 'success',
				'message' => __( 'Connected to GuardLMS successfully.', 'guardlms' ),
			);
		}

		if ( null !== $notice ) {
			set_transient( GuardLMS_Connect_Page::NOTICE_TRANSIENT, $notice, MINUTE_IN_SECONDS );
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'             => GuardLMS_Connect_Page::PAGE,
					'guardlms_connect' => $flag,
				),
				admin_url( 'options-general.php' )
			)
		);
		exit;
	}

	/**
	 * Sanitize the one-time code: alphanumeric, capped at 64 characters.
	 *
	 * @param string $value Raw code parameter.
	 * @return string
	 */
	private static function sanitize_code( string $value ): string {
		return substr( (string) preg_replace( '/[^A-Za-z0-9]/', '', $value ), 0, 64 );
	}

	/**
	 * Sanitize the state: alphanumeric (hex), capped at 40 characters.
	 *
	 * @param string $value Raw state parameter.
	 * @return string
	 */
	private static function sanitize_state( string $value ): string {
		return substr( (string) preg_replace( '/[^A-Za-z0-9]/', '', $value ), 0, 40 );
	}
}
