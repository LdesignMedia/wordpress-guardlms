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
 * Stores the push API key ONLY, and is never autoloaded. A wp-config
 * `GUARDLMS_PUSH_KEY` constant takes precedence over the stored value. The key
 * is never logged.
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
