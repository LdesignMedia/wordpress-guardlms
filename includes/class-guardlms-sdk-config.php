<?php
/**
 * Real-time monitoring (JavaScript SDK) configuration store.
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
 * Read/write helper for the nested `guardlms_settings['sdk']` array.
 *
 * STORAGE SPLIT. Everything here is non-secret and lives in the AUTOLOADED
 * `guardlms_settings` option, because GuardLMS_Sdk_Injector reads it on every
 * front-end page. The SDK key itself is the one exception: it is a bearer
 * credential for a write endpoint, so it lives in the non-autoloaded
 * `guardlms_credentials` option (GuardLMS_Credentials::get_sdk_key()) and is
 * never written into `guardlms_settings`. That keeps it out of everything that
 * dumps the settings option wholesale - Site Health, `wp option get`, and the
 * staging/migration plugins that copy autoloaded options.
 *
 * SHALLOW-MERGE TRAP. GuardLMS_Options::all() is array_merge(defaults, stored)
 * and does NOT recurse, so a nested `sdk` array written by an older plugin
 * version would shadow this class's defaults wholesale and resolve every key
 * added later to null. all() below therefore re-merges the nested array itself.
 */
class GuardLMS_Sdk_Config {

	/**
	 * Key of the nested array inside the `guardlms_settings` option.
	 *
	 * @var string
	 */
	const SETTINGS_KEY = 'sdk';

	/**
	 * Defaults for every real-time monitoring value.
	 *
	 * Opt-out by default: `enabled` and `analytics` are the admin's explicit
	 * choice and both start false. The remaining values are backend-owned and
	 * arrive through store_payload(); their defaults are only what a site that
	 * has never refreshed reports.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			// Admin-owned opt-in flags.
			'enabled'               => false,
			'analytics'             => false,

			// Backend-owned values (payload of POST /api/integrations/sdk-key).
			'sdk_url'               => '',
			'errors_endpoint'       => '',
			'analytics_endpoint'    => '',
			'backend_enabled'       => false,
			'subscription_active'   => false,
			'analytics_allowed'     => false,
			'sample_rate'           => 1.0,
			'analytics_sample_rate' => 1.0,
			'max_breadcrumbs'       => 50,
			'max_errors_per_minute' => 60,
			'ignored_errors'        => array(),
			'allowed_domains'       => array(),
			'allowed_domains_match' => true,
			'key_prefix'            => '',

			// Local bookkeeping.
			'refreshed_at'          => 0,
			'refresh_error'         => '',
			// False only after the backend answered 404/405, meaning it predates
			// this feature. Defaults to true so a site that has never refreshed
			// renders "not yet fetched" rather than hiding the section.
			'backend_supported'     => true,
		);
	}

	/**
	 * The effective real-time configuration: stored values over defaults.
	 *
	 * @return array
	 */
	public static function all() {
		$stored = GuardLMS_Options::get( self::SETTINGS_KEY, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		// Re-merge the nested array. GuardLMS_Options::all() merges only the top
		// level, so without this a stored `sdk` array written before a key was
		// added would resolve that key to null instead of its default.
		return array_merge( self::defaults(), $stored );
	}

	/**
	 * Fetch a single real-time setting.
	 *
	 * @param string $key      Setting key.
	 * @param mixed  $fallback Value returned when the key is absent.
	 * @return mixed
	 */
	public static function get( string $key, $fallback = null ) {
		$all = self::all();

		return array_key_exists( $key, $all ) ? $all[ $key ] : $fallback;
	}

	/**
	 * Merge partial values into the stored real-time configuration and persist.
	 *
	 * @param array $values Partial configuration to merge.
	 * @return void
	 */
	public static function update( array $values ) {
		GuardLMS_Options::set( self::SETTINGS_KEY, array_merge( self::all(), $values ) );
	}

	/**
	 * Persist the backend's sdk payload from a connect exchange or a refresh.
	 *
	 * The plaintext `key` is routed to GuardLMS_Credentials and deliberately
	 * never reaches `guardlms_settings`. A payload that omits the key (the
	 * `key_status: 'exists'` branch, which is the normal case on every refresh
	 * after the first) leaves the stored key untouched.
	 *
	 * @param array $payload Decoded `data` array from the backend.
	 * @return void
	 */
	public static function store_payload( array $payload ) {
		$values = array(
			'refreshed_at'      => time(),
			'refresh_error'     => '',
			// A payload proves the endpoint exists, so a site that previously
			// saw 404/405 recovers as soon as the backend is upgraded.
			'backend_supported' => true,
		);

		$strings = array(
			'sdk_url'            => 'sdk_url',
			'errors_endpoint'    => 'errors_endpoint',
			'analytics_endpoint' => 'analytics_endpoint',
		);
		foreach ( $strings as $from => $to ) {
			if ( isset( $payload[ $from ] ) ) {
				$values[ $to ] = esc_url_raw( trim( (string) $payload[ $from ] ) );
			}
		}

		if ( isset( $payload['key_prefix'] ) ) {
			$values['key_prefix'] = sanitize_text_field( (string) $payload['key_prefix'] );
		}

		$booleans = array(
			'enabled'               => 'backend_enabled',
			'subscription_active'   => 'subscription_active',
			'analytics_allowed'     => 'analytics_allowed',
			'allowed_domains_match' => 'allowed_domains_match',
		);
		foreach ( $booleans as $from => $to ) {
			if ( isset( $payload[ $from ] ) ) {
				$values[ $to ] = (bool) $payload[ $from ];
			}
		}

		$rates = array(
			'sample_rate'           => 'sample_rate',
			'analytics_sample_rate' => 'analytics_sample_rate',
		);
		foreach ( $rates as $from => $to ) {
			if ( isset( $payload[ $from ] ) && is_numeric( $payload[ $from ] ) ) {
				$values[ $to ] = min( 1.0, max( 0.0, (float) $payload[ $from ] ) );
			}
		}

		$counts = array(
			'max_breadcrumbs'       => 'max_breadcrumbs',
			'max_errors_per_minute' => 'max_errors_per_minute',
		);
		foreach ( $counts as $from => $to ) {
			if ( isset( $payload[ $from ] ) && is_numeric( $payload[ $from ] ) ) {
				$values[ $to ] = max( 0, (int) $payload[ $from ] );
			}
		}

		$lists = array(
			'ignored_errors'  => 'ignored_errors',
			'allowed_domains' => 'allowed_domains',
		);
		foreach ( $lists as $from => $to ) {
			if ( isset( $payload[ $from ] ) && is_array( $payload[ $from ] ) ) {
				$values[ $to ] = self::clean_string_list( $payload[ $from ] );
			}
		}

		// `batch_interval_seconds` is deliberately NOT stored or emitted. Its
		// value is drifted three ways between the migration default, the
		// controller seed and the SDK default, and it is enforced nowhere, so
		// emitting it could only regress flush latency from the SDK's 2s.
		self::update( $values );

		if ( isset( $payload['key'] ) && '' !== trim( (string) $payload['key'] ) ) {
			GuardLMS_Credentials::set_sdk_key( (string) $payload['key'] );
		}
	}

	/**
	 * Record a refresh failure so the settings page can render §5.3 row 7.
	 *
	 * `refreshed_at` is deliberately left alone: the admin needs to see when the
	 * last SUCCESSFUL refresh happened, which is exactly what a failure does not
	 * change.
	 *
	 * @param string $message Human-readable failure message.
	 * @return void
	 */
	public static function record_error( string $message ) {
		self::update( array( 'refresh_error' => $message ) );
	}

	/**
	 * Record that the backend does not implement the real-time endpoint (404/405).
	 *
	 * This is §5.3 row 2: the section is hidden entirely and NO admin error is
	 * raised, because there is nothing the site owner can do about it.
	 *
	 * @return void
	 */
	public static function mark_unsupported() {
		self::update( array( 'backend_supported' => false ) );
	}

	/**
	 * Reset every real-time value to its default and drop the stored SDK key.
	 *
	 * @return void
	 */
	public static function clear() {
		GuardLMS_Options::set( self::SETTINGS_KEY, self::defaults() );
		GuardLMS_Credentials::delete_sdk_key();
	}

	/**
	 * Reduce an arbitrary decoded JSON list to a clean list of non-empty strings.
	 *
	 * @param array $list Raw list from the backend payload.
	 * @return string[]
	 */
	private static function clean_string_list( array $list ) {
		$clean = array();

		foreach ( $list as $entry ) {
			if ( ! is_scalar( $entry ) ) {
				continue;
			}
			$value = trim( (string) $entry );
			if ( '' !== $value ) {
				$clean[] = $value;
			}
		}

		return array_values( array_unique( $clean ) );
	}
}
