<?php
/**
 * Real-time monitoring status resolver (the §5.3 precedence chain).
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
 * Turns the stored real-time configuration into exactly ONE headline state plus
 * any number of advisories.
 *
 * Several failure states co-occur - most commonly a first-ever failed bootstrap,
 * where "no key", "refresh failed" and possibly "backend too old" are all true
 * at once. Showing three sentences teaches an admin nothing, so the headline is
 * chosen by a fixed precedence chain:
 *
 *     backend too old -> dashboard off -> no subscription -> refresh failed -> no key
 *
 * (The Moodle plugin inserts "requires Moodle 4.4" second. WordPress has no
 * equivalent gate, so that row cannot occur here.)
 *
 * Advisories are non-exclusive and render IN ADDITION to the headline, because a
 * domain mismatch or a missing analytics entitlement is meaningful even on an
 * otherwise healthy site.
 */
class GuardLMS_Sdk_Status {

	/**
	 * Everything is configured and injection is happening. §5.3 - no row.
	 *
	 * @var string
	 */
	const STATE_OK = 'ok';

	/**
	 * No SDK key stored yet. §5.3 row 1.
	 *
	 * @var string
	 */
	const STATE_NO_KEY = 'nokey';

	/**
	 * Backend answered 404/405 - it predates this feature. §5.3 row 2.
	 *
	 * @var string
	 */
	const STATE_BACKEND_TOO_OLD = 'backendtooold';

	/**
	 * No active GuardLMS subscription. §5.3 row 4.
	 *
	 * @var string
	 */
	const STATE_NO_SUBSCRIPTION = 'nosubscription';

	/**
	 * Real-time monitoring switched off in the GuardLMS dashboard. §5.3 row 5.
	 *
	 * @var string
	 */
	const STATE_DASHBOARD_OFF = 'dashboardoff';

	/**
	 * The last refresh attempt failed. §5.3 row 7.
	 *
	 * @var string
	 */
	const STATE_REFRESH_FAILED = 'refreshfailed';

	/**
	 * Analytics is not included in the site's plan. §5.3 row 3 (advisory).
	 *
	 * @var string
	 */
	const ADVISORY_NO_ANALYTICS = 'noanalytics';

	/**
	 * The dashboard's Allowed domains list does not contain this site's host.
	 * §5.3 row 6 (advisory).
	 *
	 * @var string
	 */
	const ADVISORY_DOMAIN_MISMATCH = 'domainmismatch';

	/**
	 * Resolve the single headline state for the current configuration.
	 *
	 * @param array  $sdk     Effective configuration from GuardLMS_Sdk_Config::all().
	 * @param string $sdk_key The stored SDK key ('' when none).
	 * @return string One of the STATE_* constants.
	 */
	public static function headline( array $sdk, string $sdk_key ) {
		// Row 2 - wins over everything. The section is hidden, so no other
		// sentence can be rendered anyway.
		if ( empty( $sdk['backend_supported'] ) ) {
			return self::STATE_BACKEND_TOO_OLD;
		}

		// `backend_enabled` and `subscription_active` are backend-owned and both
		// default to false, so on a site that has never refreshed they mean
		// "unknown", not "off". Reading them before a successful refresh would
		// tell a brand-new site its dashboard switch is off, which is a
		// fabricated diagnosis. Rows 5 and 4 therefore need a refresh first.
		$refreshed = (int) $sdk['refreshed_at'] > 0;

		// Row 5.
		if ( $refreshed && empty( $sdk['backend_enabled'] ) ) {
			return self::STATE_DASHBOARD_OFF;
		}

		// Row 4.
		if ( $refreshed && empty( $sdk['subscription_active'] ) ) {
			return self::STATE_NO_SUBSCRIPTION;
		}

		// Row 7.
		if ( '' !== trim( (string) $sdk['refresh_error'] ) ) {
			return self::STATE_REFRESH_FAILED;
		}

		// Row 1.
		if ( '' === trim( $sdk_key ) ) {
			return self::STATE_NO_KEY;
		}

		return self::STATE_OK;
	}

	/**
	 * Resolve the non-exclusive advisories for the current configuration.
	 *
	 * @param array $sdk Effective configuration from GuardLMS_Sdk_Config::all().
	 * @return string[] Zero or more ADVISORY_* constants.
	 */
	public static function advisories( array $sdk ) {
		$advisories = array();

		// Row 3. Like rows 4 and 5, `analytics_allowed` only means "not in your
		// plan" once a refresh has actually answered.
		if ( (int) $sdk['refreshed_at'] > 0 && empty( $sdk['analytics_allowed'] ) ) {
			$advisories[] = self::ADVISORY_NO_ANALYTICS;
		}

		// Row 6. Defaults to a match, so a site that has never refreshed is not
		// accused of a mismatch it cannot yet know about.
		if ( empty( $sdk['allowed_domains_match'] ) ) {
			$advisories[] = self::ADVISORY_DOMAIN_MISMATCH;
		}

		return $advisories;
	}

	/**
	 * Whether the real-time section should render at all.
	 *
	 * @param array $sdk Effective configuration from GuardLMS_Sdk_Config::all().
	 * @return bool
	 */
	public static function is_section_visible( array $sdk ) {
		return ! empty( $sdk['backend_supported'] );
	}

	/**
	 * Whether the plugin should inject the SDK on front-end pages.
	 *
	 * The single implementation of "would this batch be accepted?", so that
	 * "enabled" never lies: the plugin refuses to inject when it already knows
	 * the backend would reject the batch.
	 *
	 * Deliberately NOT keyed off headline(): a failed refresh (row 7) leaves a
	 * perfectly usable key and endpoint behind, and §5.3 suppresses injection
	 * only for rows 4 and 5. Going dark because one refresh timed out would be
	 * the very silence this design exists to remove.
	 *
	 * @param array  $sdk     Effective configuration from GuardLMS_Sdk_Config::all().
	 * @param string $sdk_key The stored SDK key ('' when none).
	 * @return bool
	 */
	public static function should_inject( array $sdk, string $sdk_key ) {
		if ( empty( $sdk['enabled'] ) || empty( $sdk['backend_supported'] ) ) {
			return false;
		}

		// Row 5 then row 4: refuse to send into a black hole.
		if ( empty( $sdk['backend_enabled'] ) || empty( $sdk['subscription_active'] ) ) {
			return false;
		}

		if ( '' === trim( (string) $sdk['sdk_url'] ) || '' === trim( (string) $sdk['errors_endpoint'] ) ) {
			return false;
		}

		return '' !== trim( $sdk_key );
	}

	/**
	 * Whether the analytics block belongs in the emitted SDK configuration.
	 *
	 * Requires BOTH the plan entitlement and the admin's opt-in; errors still
	 * flow when analytics is unavailable.
	 *
	 * @param array $sdk Effective configuration from GuardLMS_Sdk_Config::all().
	 * @return bool
	 */
	public static function should_send_analytics( array $sdk ) {
		return ! empty( $sdk['analytics'] )
			&& ! empty( $sdk['analytics_allowed'] )
			&& '' !== trim( (string) $sdk['analytics_endpoint'] );
	}
}
