<?php
/**
 * GuardLMS settings accessor (autoloaded, non-secret option).
 *
 * @package GuardLMS
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
		);
	}

	/**
	 * The full settings array: stored values layered over defaults.
	 *
	 * Defaults fill any missing keys; stored values win where present.
	 *
	 * @return array
	 */
	public static function all() {
		$stored = get_option( self::OPTION, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		return array_merge( self::defaults(), $stored );
	}

	/**
	 * Fetch a single setting.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Value returned when the key is absent.
	 * @return mixed
	 */
	public static function get( string $key, $default = null ) {
		$all = self::all();

		return array_key_exists( $key, $all ) ? $all[ $key ] : $default;
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
		$merged = array_merge( self::all(), $values );
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
