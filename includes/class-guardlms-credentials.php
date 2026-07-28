<?php
/**
 * GuardLMS credentials accessor (non-autoloaded, secret option).
 *
 * @package    GuardLMS
 * @copyright  2026 Luuk Verhoeven, ldesignmedia.nl <info@ldesignmedia.nl>
 * @author     Hamza Tamyachte
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
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

defined( 'ABSPATH' ) || exit;

/**
 * Read/write helper for the secret `guardlms_credentials` option.
 *
 * Never autoloaded. Holds two bearer credentials:
 *
 * - `apikey` - the server-to-server push key. A wp-config `GUARDLMS_PUSH_KEY`
 *   constant takes precedence over the stored value.
 * - `sdkkey` - the real-time monitoring (JavaScript SDK) key. Public once
 *   injected into a page, but still a bearer credential for a write endpoint,
 *   so it is kept under the same "credentials live here, are never logged,
 *   never exported" rule rather than in the autoloaded settings option.
 *
 * Neither key is ever logged.
 *
 * ONE OPTION PER CREDENTIAL, DELIBERATELY. The two keys have independent
 * writers that genuinely run concurrently - the push key is written by the
 * connect REST callback and the advanced settings save, the SDK key by the
 * settings-page bootstrap, the Refresh button and the daily cron. Holding both
 * in one option would force every write through a read-merge-write over the
 * whole array, and an interleaving would silently drop whichever key the loser
 * had not read yet. Losing the SDK key that way leaves a state with no route
 * back: the backend returns `key: null` once a key has been issued, so a
 * refresh cannot recover it. Separate rows make each write a single atomic
 * UPDATE that cannot touch the other credential at all.
 */
class GuardLMS_Credentials {

	/**
	 * Option holding the server-to-server push key. Never autoloaded.
	 *
	 * @var string
	 */
	const OPTION = 'guardlms_credentials';

	/**
	 * Option holding the real-time monitoring (SDK) key. Never autoloaded.
	 *
	 * @var string
	 */
	const SDK_OPTION = 'guardlms_sdk_credentials';

	/**
	 * Resolve the active push key.
	 *
	 * A `GUARDLMS_PUSH_KEY` wp-config constant, when defined, wins over the DB.
	 *
	 * @return string
	 */
	public static function get_key() {
		if ( defined( 'GUARDLMS_PUSH_KEY' ) ) {
			return trim( (string) GUARDLMS_PUSH_KEY );
		}

		$creds  = get_option( self::OPTION, array( 'apikey' => '' ) );
		$apikey = is_array( $creds ) && isset( $creds['apikey'] ) ? (string) $creds['apikey'] : '';

		return trim( $apikey );
	}

	/**
	 * Store the push key (non-autoloaded).
	 *
	 * A single atomic write of a single-member array. No read-modify-write, so a
	 * concurrent SDK-key write cannot be lost - see the class docblock.
	 *
	 * @param string $key Push key to store.
	 * @return void
	 */
	public static function set_key( string $key ) {
		update_option( self::OPTION, array( 'apikey' => trim( $key ) ), 'no' );
	}

	/**
	 * Whether a non-empty push key is available.
	 *
	 * @return bool
	 */
	public static function has_key() {
		return '' !== self::get_key();
	}

	/**
	 * Resolve the stored real-time monitoring (SDK) key.
	 *
	 * @return string
	 */
	public static function get_sdk_key() {
		$creds = get_option( self::SDK_OPTION, array() );
		$sdkey = is_array( $creds ) && isset( $creds['sdkkey'] ) ? (string) $creds['sdkkey'] : '';

		return trim( $sdkey );
	}

	/**
	 * Store the real-time monitoring (SDK) key (non-autoloaded).
	 *
	 * A single atomic write to its own option row, so a concurrent push-key
	 * write cannot drop it - see the class docblock.
	 *
	 * @param string $key SDK key to store.
	 * @return void
	 */
	public static function set_sdk_key( string $key ) {
		update_option( self::SDK_OPTION, array( 'sdkkey' => trim( $key ) ), 'no' );
	}

	/**
	 * Drop the stored SDK key, leaving the push key untouched.
	 *
	 * @return void
	 */
	public static function delete_sdk_key() {
		delete_option( self::SDK_OPTION );
	}

	/**
	 * Seed both credential options on activation, non-autoloaded.
	 *
	 * @return void
	 */
	public static function ensure_option() {
		add_option( self::OPTION, array( 'apikey' => '' ), '', 'no' );
		add_option( self::SDK_OPTION, array( 'sdkkey' => '' ), '', 'no' );
	}

	/**
	 * Delete both credential options.
	 *
	 * @return void
	 */
	public static function delete() {
		delete_option( self::OPTION );
		delete_option( self::SDK_OPTION );
	}
}
