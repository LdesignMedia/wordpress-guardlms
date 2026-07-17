<?php
/**
 * Front-end ownership meta tag injector.
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
 * Renders the `guardlms-verification` ownership meta tag in the site <head>.
 *
 * The backend fetches the site homepage and looks for
 * `<meta name="guardlms-verification" content="TOKEN">` to prove ownership.
 * The token is a PUBLIC value the admin pastes from the GuardLMS dashboard and
 * is stored in the autoloaded `guardlms_settings` option, so this class adds no
 * extra database read on the front end.
 */
class GuardLMS_Head_Injector {

	/**
	 * Build the ownership meta tag markup.
	 *
	 * Returns an empty string unless reporting is enabled AND a non-empty
	 * verification token is configured.
	 *
	 * @return string Escaped `<meta>` markup with trailing newline, or ''.
	 */
	public static function meta_tag() {
		if ( ! GuardLMS_Options::get( 'enabled' ) ) {
			return '';
		}

		$token = trim( (string) GuardLMS_Options::get( 'verificationtoken' ) );
		if ( '' === $token ) {
			return '';
		}

		return '<meta name="guardlms-verification" content="' . esc_attr( $token ) . '">' . "\n";
	}

	/**
	 * Echo the ownership meta tag. Hooked to `wp_head`.
	 *
	 * @return void
	 */
	public static function render() {
		echo wp_kses(
			self::meta_tag(),
			array(
				'meta' => array(
					'name'    => array(),
					'content' => array(),
				),
			)
		);
	}
}
