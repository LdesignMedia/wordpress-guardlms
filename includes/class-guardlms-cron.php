<?php
/**
 * GuardLMS cron scheduling and handlers.
 *
 * @package    GuardLMS
 * @copyright  2026 Luuk Verhoeven, ldesignmedia.nl <info@ldesignmedia.nl>
 * @author     Hamza Tamyachte
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/*
 * GuardLMS for WordPress
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
 * Registers the WP-Cron schedules and runs the push handlers.
 */
class GuardLMS_Cron {

	/**
	 * Recurring daily push hook.
	 *
	 * @var string
	 */
	const DAILY_HOOK = 'guardlms_daily_push';

	/**
	 * One-off initial push hook.
	 *
	 * @var string
	 */
	const INITIAL_HOOK = 'guardlms_initial_push';

	/**
	 * Schedule the recurring daily push at a jittered offset.
	 *
	 * @return void
	 */
	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::DAILY_HOOK ) ) {
			wp_schedule_event( time() + wp_rand( 0, DAY_IN_SECONDS ), 'daily', self::DAILY_HOOK );
		}
	}

	/**
	 * Clear all GuardLMS scheduled events.
	 *
	 * @return void
	 */
	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::DAILY_HOOK );
		wp_clear_scheduled_hook( self::INITIAL_HOOK );
	}

	/**
	 * Daily push handler.
	 *
	 * @return void
	 */
	public static function run_daily(): void {
		if ( ! GuardLMS_Options::get( 'enabled' ) ) {
			return;
		}

		$result = GuardLMS_Pusher::push();

		if ( is_wp_error( $result ) ) {
			// The message never contains the API key.
			error_log( 'GuardLMS daily push failed: ' . $result->get_error_message() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}

	/**
	 * Initial one-off push handler.
	 *
	 * @return void
	 */
	public static function run_initial(): void {
		$result = GuardLMS_Pusher::push();

		if ( is_wp_error( $result ) ) {
			// The message never contains the API key.
			error_log( 'GuardLMS initial push failed: ' . $result->get_error_message() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}
}
