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
 */
class GuardLMS_Credentials {

	const OPTION = 'guardlms_credentials';

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
	 * Merges rather than replaces, so storing a fresh push key does not silently
	 * discard the SDK key stored beside it.
	 *
	 * @param string $key Push key to store.
	 * @return void
	 */
	public static function set_key( string $key ) {
		self::write( array( 'apikey' => trim( $key ) ) );
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
		$creds = get_option( self::OPTION, array() );
		$sdkey = is_array( $creds ) && isset( $creds['sdkkey'] ) ? (string) $creds['sdkkey'] : '';

		return trim( $sdkey );
	}

	/**
	 * Store the real-time monitoring (SDK) key (non-autoloaded).
	 *
	 * @param string $key SDK key to store.
	 * @return void
	 */
	public static function set_sdk_key( string $key ) {
		self::write( array( 'sdkkey' => trim( $key ) ) );
	}

	/**
	 * Drop the stored SDK key, leaving the push key in place.
	 *
	 * Deliberately a no-op when the option or the key is absent, so calling this
	 * after delete() cannot recreate the option that delete() just removed.
	 *
	 * @return void
	 */
	public static function delete_sdk_key() {
		$stored = get_option( self::OPTION, array() );
		if ( ! is_array( $stored ) || ! array_key_exists( 'sdkkey', $stored ) ) {
			return;
		}

		unset( $stored['sdkkey'] );
		update_option( self::OPTION, $stored, 'no' );
	}

	/**
	 * Merge values into the stored credentials, keeping the option non-autoloaded.
	 *
	 * @param array $values Partial credential values to merge.
	 * @return void
	 */
	private static function write( array $values ) {
		$stored = get_option( self::OPTION, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		update_option( self::OPTION, array_merge( $stored, $values ), 'no' );
	}

	/**
	 * Seed the option on activation, non-autoloaded.
	 *
	 * @return void
	 */
	public static function ensure_option() {
		add_option( self::OPTION, array( 'apikey' => '' ), '', 'no' );
	}

	/**
	 * Delete the credentials option.
	 *
	 * @return void
	 */
	public static function delete() {
		delete_option( self::OPTION );
	}
}
