<?php
/**
 * Admin notice tests for the GuardLMS settings integration.
 *
 * Covers the two site-wide notices the plugin can raise (site URL changed and
 * key expiring soon) against the WordPress.org "do not hijack the dashboard"
 * guideline: they must be limited to users who can act on them, dismissible,
 * and point at the screen where the fix lives.
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

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;

require_once __DIR__ . '/AbstractGuardLMSTestCase.php';
require_once GUARDLMS_PLUGIN_DIR . 'includes/class-guardlms-options.php';
require_once GUARDLMS_PLUGIN_DIR . 'includes/class-guardlms-credentials.php';
require_once GUARDLMS_PLUGIN_DIR . 'includes/class-guardlms-http.php';
require_once GUARDLMS_PLUGIN_DIR . 'includes/class-guardlms-sdk-config.php';
require_once GUARDLMS_PLUGIN_DIR . 'includes/class-guardlms-sdk-status.php';
require_once GUARDLMS_PLUGIN_DIR . 'includes/class-guardlms-sdk-client.php';
require_once GUARDLMS_PLUGIN_DIR . 'includes/class-guardlms-sdk-injector.php';
require_once GUARDLMS_PLUGIN_DIR . 'includes/class-guardlms-api-client.php';
require_once GUARDLMS_PLUGIN_DIR . 'includes/class-guardlms-connect-manager.php';
require_once GUARDLMS_PLUGIN_DIR . 'includes/admin/class-guardlms-settings.php';

// WordPress time constant the expiry window is expressed in.
if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}

/**
 * @covers GuardLMS_Settings::maybe_notice
 * @covers GuardLMS_Settings::render_url_changed_notice
 * @covers GuardLMS_Settings::render_expiry_notice
 */
final class SettingsNoticesTest extends AbstractGuardLMSTestCase {

	private const SETTINGS_URL = 'https://site.test/wp-admin/options-general.php?page=guardlms';

	/**
	 * In-memory option store keyed by option name.
	 *
	 * @var array<string,mixed>
	 */
	private $store = array();

	protected function setUp(): void {
		parent::setUp();
		$this->store = array();

		Functions\when( 'get_option' )->alias(
			function ( $name, $default = false ) {
				return array_key_exists( $name, $this->store ) ? $this->store[ $name ] : $default;
			}
		);
		Functions\when( 'update_option' )->alias(
			function ( $name, $value ) {
				$this->store[ $name ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_option' )->alias(
			function ( $name ) {
				unset( $this->store[ $name ] );
				return true;
			}
		);
		Functions\when( 'home_url' )->justReturn( 'https://www.site.test/' );
		Functions\when( 'admin_url' )->justReturn( 'https://site.test/wp-admin/options-general.php' );
		Functions\when( 'add_query_arg' )->alias(
			static function ( array $args, string $url ) {
				return $url . '?' . http_build_query( $args );
			}
		);
		Functions\when( 'esc_url' )->returnArg( 1 );
		Functions\when( 'wp_date' )->alias(
			static function ( $format, $timestamp = null ) {
				return gmdate( 'Y-m-d', (int) $timestamp );
			}
		);
	}

	/**
	 * Seed a connected site whose key expires at the given time.
	 *
	 * @param int $expires_at Unix timestamp of the key expiry.
	 * @return void
	 */
	private function seedConnected( int $expires_at ): void {
		$this->store['guardlms_settings']    = array(
			'connected_siteurl' => 'https://www.site.test',
			'keyexpiresat'      => $expires_at,
		);
		$this->store['guardlms_credentials'] = array( 'apikey' => 'push-key-abc' );
	}

	/**
	 * @param callable $renderer Notice renderer to capture.
	 * @return string
	 */
	private function render( callable $renderer ): string {
		ob_start();
		$renderer();

		return (string) ob_get_clean();
	}

	// -- Registration ---------------------------------------------------------

	public function test_expiry_notice_is_registered_when_the_key_expires_within_30_days(): void {
		$this->seedConnected( time() + 10 * DAY_IN_SECONDS );

		Actions\expectAdded( 'admin_notices' )
			->once()
			->with( array( 'GuardLMS_Settings', 'render_expiry_notice' ) );

		GuardLMS_Settings::maybe_notice();
	}

	public function test_no_notice_is_registered_when_the_key_is_far_from_expiry(): void {
		$this->seedConnected( time() + 90 * DAY_IN_SECONDS );

		Actions\expectAdded( 'admin_notices' )->never();

		GuardLMS_Settings::maybe_notice();
	}

	public function test_url_change_notice_is_registered_when_the_site_moved(): void {
		$this->seedConnected( time() + 90 * DAY_IN_SECONDS );
		$this->store['guardlms_settings']['connected_siteurl'] = 'https://old.site.test';

		Actions\expectAdded( 'admin_notices' )
			->once()
			->with( array( 'GuardLMS_Settings', 'render_url_changed_notice' ) );

		GuardLMS_Settings::maybe_notice();
	}

	// -- Capability gate ------------------------------------------------------

	public function test_expiry_notice_is_hidden_from_users_who_cannot_manage_options(): void {
		$this->seedConnected( time() + 10 * DAY_IN_SECONDS );
		Functions\when( 'current_user_can' )->justReturn( false );

		$html = $this->render( array( 'GuardLMS_Settings', 'render_expiry_notice' ) );

		$this->assertSame( '', $html );
	}

	public function test_url_change_notice_is_hidden_from_users_who_cannot_manage_options(): void {
		Functions\when( 'current_user_can' )->justReturn( false );

		$html = $this->render( array( 'GuardLMS_Settings', 'render_url_changed_notice' ) );

		$this->assertSame( '', $html );
	}

	// -- Rendering ------------------------------------------------------------

	public function test_expiry_notice_is_dismissible_and_links_to_the_settings_screen(): void {
		$this->seedConnected( 1800000000 );
		Functions\when( 'current_user_can' )->justReturn( true );

		$html = $this->render( array( 'GuardLMS_Settings', 'render_expiry_notice' ) );

		$this->assertStringContainsString( 'notice notice-warning is-dismissible', $html );
		$this->assertStringContainsString( '2027-01-15', $html );
		$this->assertStringContainsString( 'href="' . self::SETTINGS_URL . '"', $html );
		$this->assertStringContainsString( '>Reconnect<', $html );
	}

	public function test_expiry_notice_renders_nothing_once_the_key_is_gone(): void {
		// Registered on admin_init, but the key was disconnected later in the
		// same request: there is nothing left to warn about.
		Functions\when( 'current_user_can' )->justReturn( true );

		$html = $this->render( array( 'GuardLMS_Settings', 'render_expiry_notice' ) );

		$this->assertSame( '', $html );
	}

	public function test_url_change_notice_is_dismissible_and_links_to_the_settings_screen(): void {
		Functions\when( 'current_user_can' )->justReturn( true );

		$html = $this->render( array( 'GuardLMS_Settings', 'render_url_changed_notice' ) );

		$this->assertStringContainsString( 'notice notice-warning is-dismissible', $html );
		$this->assertStringContainsString( 'site URL changed', $html );
		$this->assertStringContainsString( 'href="' . self::SETTINGS_URL . '"', $html );
		$this->assertStringContainsString( '>Reconnect<', $html );
	}
}
