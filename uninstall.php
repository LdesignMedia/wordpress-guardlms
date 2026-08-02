<?php
/**
 * GuardLMS uninstall cleanup.
 *
 * Removes every GuardLMS option and scheduled hook on single-site and on every
 * subsite of a multisite network. Runs in an isolated WordPress bootstrap, so
 * plugin classes are NOT available here — option keys are hardcoded on purpose.
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

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$guardlms_option_keys = array( 'guardlms_settings', 'guardlms_credentials', 'guardlms_sdk_credentials' );

// Single-site options and network-level (site meta) options.
foreach ( $guardlms_option_keys as $guardlms_key ) {
	delete_option( $guardlms_key );
	delete_site_option( $guardlms_key );
}

// Multisite: clean each subsite's options and scheduled hooks.
if ( is_multisite() ) {
	$guardlms_site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	foreach ( $guardlms_site_ids as $guardlms_site_id ) {
		switch_to_blog( (int) $guardlms_site_id );

		foreach ( $guardlms_option_keys as $guardlms_key ) {
			delete_option( $guardlms_key );
		}

		wp_clear_scheduled_hook( 'guardlms_daily_push' );
		wp_clear_scheduled_hook( 'guardlms_initial_push' );

		restore_current_blog();
	}
}

// Clear scheduled hooks for the current (or single) site.
wp_clear_scheduled_hook( 'guardlms_daily_push' );
wp_clear_scheduled_hook( 'guardlms_initial_push' );
