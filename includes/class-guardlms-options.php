<?php
/**
 * GuardLMS settings accessor (autoloaded, non-secret option).
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
 * Read/write helper for the autoloaded `guardlms_settings` option.
 *
 * Holds public, non-secret configuration only. The public verification token
 * lives here (rendered on every front-end wp_head, so it must stay autoloaded).
 * The secret push key lives in GuardLMS_Credentials, not here.
 */
class GuardLMS_Options {

	const OPTION = 'guardlms_settings';

	/**
	 * Default settings and their types.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'enabled'              => true,
			'baseurl'              => GUARDLMS_DEFAULT_BASEURL,
			'pushpath'             => GUARDLMS_DEFAULT_PUSHPATH,
			'sendconfig'           => false,
			'verificationtoken'    => '',
			'connected_siteurl'    => '',
			'lastpush'             => 0,
			'lastpushstatus'       => 0,
			'keyexpiresat'         => 0,
			'last_plugincount'     => 0,
			// Phase 2 keyless Connect flow.
			'connectstate'         => '',
			'connectstateexpires'  => 0,
			'connectstate_baseurl' => '',
			'websiteid'            => 0,
			'connectedat'          => 0,
			// Unix timestamp of the first authenticated call GuardLMS rejected
			// with 401/403 since the last accepted one. 0 = the key still works.
			'authrejectedat'       => 0,
		);
	}

	/**
	 * The stored settings layered over the defaults, without wp-config overrides.
	 *
	 * This is the value that may be written back to the database. Use all() to read
	 * the settings the plugin actually runs on.
	 *
	 * @return array
	 */
	public static function stored() {
		$stored = get_option( self::OPTION, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		return array_merge( self::defaults(), $stored );
	}

	/**
	 * The effective settings: wp-config constants over stored values over defaults.
	 *
	 * GUARDLMS_BASEURL and GUARDLMS_PUSHPATH let a host pin the endpoint the same way
	 * GUARDLMS_PUSH_KEY pins the key. A pinned value is never written back to the
	 * database, so removing the constant restores the stored value.
	 *
	 * @return array
	 */
	public static function all() {
		$settings = self::stored();

		if ( defined( 'GUARDLMS_BASEURL' ) && '' !== trim( (string) GUARDLMS_BASEURL ) ) {
			$settings['baseurl'] = rtrim( trim( (string) GUARDLMS_BASEURL ), '/' );
		}

		if ( defined( 'GUARDLMS_PUSHPATH' ) && '' !== trim( (string) GUARDLMS_PUSHPATH ) ) {
			$settings['pushpath'] = trim( (string) GUARDLMS_PUSHPATH );
		}

		return $settings;
	}

	/**
	 * Whether a setting is pinned by a wp-config constant and cannot be edited.
	 *
	 * @param string $key Setting key.
	 * @return bool
	 */
	public static function is_pinned( string $key ) {
		if ( 'baseurl' === $key ) {
			return defined( 'GUARDLMS_BASEURL' ) && '' !== trim( (string) GUARDLMS_BASEURL );
		}

		if ( 'pushpath' === $key ) {
			return defined( 'GUARDLMS_PUSHPATH' ) && '' !== trim( (string) GUARDLMS_PUSHPATH );
		}

		return false;
	}

	/**
	 * Fetch a single setting.
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
	 * Set a single setting and persist.
	 *
	 * @param string $key   Setting key.
	 * @param mixed  $value Value to store.
	 * @return void
	 */
	public static function set( string $key, $value ) {
		self::update( array( $key => $value ) );
	}

	/**
	 * Merge partial values into the stored settings and persist.
	 *
	 * @param array $values Partial settings to merge.
	 * @return void
	 */
	public static function update( array $values ) {
		// Merge into the stored settings, never into the effective ones, so a value
		// pinned by a wp-config constant does not leak into the database.
		$merged = array_merge( self::stored(), $values );
		update_option( self::OPTION, $merged, 'yes' );
	}

	/**
	 * Seed the option with defaults on activation (autoloaded).
	 *
	 * @return void
	 */
	public static function ensure_option() {
		add_option( self::OPTION, self::defaults(), '', 'yes' );
	}

	/**
	 * Delete the settings option.
	 *
	 * @return void
	 */
	public static function delete() {
		delete_option( self::OPTION );
	}
}
