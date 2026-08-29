<?php
/**
 * GuardLMS shared admin notice renderer.
 *
 * @package    GuardLMS
 * @copyright  2026 Luuk Verhoeven, ldesignmedia.nl <info@ldesignmedia.nl>
 * @author     Luuk Verhoeven
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
 * Prints WordPress admin notices through one markup and one escaping path.
 *
 * Every notice the plugin shows (site-wide on `admin_notices` or in place on
 * its own screen) goes through render(), so a markup or escaping fix lands
 * everywhere at once.
 */
class GuardLMS_Admin_Notice {

	/**
	 * Notice types WordPress core styles.
	 *
	 * @var string[]
	 */
	const TYPES = array( 'success', 'error', 'warning', 'info' );

	/**
	 * Print one admin notice.
	 *
	 * @param string $type    One of TYPES; anything else renders as "info".
	 * @param string $message Plain-text message, escaped here.
	 * @param array  $args    {
	 *     Optional. Rendering options.
	 *
	 *     @type bool   $dismissible   Add the core is-dismissible class. Default false.
	 *     @type bool   $inline        Add the core inline class so the notice
	 *                                 stays where it is printed instead of being
	 *                                 moved to the top of the screen. Default false.
	 *     @type string $link_url      URL of a trailing link. Default ''.
	 *     @type string $link_text     Text of the trailing link; without it no
	 *                                 link is rendered. Default ''.
	 *     @type bool   $link_external Open the link in a new tab with
	 *                                 rel="noopener noreferrer". Default false.
	 * }
	 * @return void
	 */
	public static function render( string $type, string $message, array $args = array() ): void {
		$args = array_merge(
			array(
				'dismissible'   => false,
				'inline'        => false,
				'link_url'      => '',
				'link_text'     => '',
				'link_external' => false,
			),
			$args
		);

		$classes = array( 'notice', 'notice-' . ( in_array( $type, self::TYPES, true ) ? $type : 'info' ) );
		if ( $args['dismissible'] ) {
			$classes[] = 'is-dismissible';
		}
		if ( $args['inline'] ) {
			$classes[] = 'inline';
		}

		$link = '';
		if ( '' !== (string) $args['link_text'] && '' !== (string) $args['link_url'] ) {
			$link = sprintf(
				' <a href="%1$s"%2$s>%3$s</a>',
				esc_url( (string) $args['link_url'] ),
				$args['link_external'] ? ' target="_blank" rel="noopener noreferrer"' : '',
				esc_html( (string) $args['link_text'] )
			);
		}

		printf(
			'<div class="%1$s"><p>%2$s%3$s</p></div>',
			esc_attr( implode( ' ', $classes ) ),
			esc_html( $message ),
			$link // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Assembled above from escaped parts only.
		);
	}
}
