<?php
/**
 * Admin stylesheet tests for the GuardLMS settings screen.
 *
 * The plugin screen styling must go through wp_enqueue_style() (WordPress.org
 * review requirement) and load only on the plugin's own screen, never on
 * unrelated admin pages.
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

use Brain\Monkey\Functions;

require_once __DIR__ . '/AbstractGuardLMSTestCase.php';
require_once GUARDLMS_PLUGIN_DIR . 'includes/admin/class-guardlms-admin-notice.php';
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

/**
 * @covers GuardLMS_Settings::enqueue_assets
 */
final class SettingsAssetsTest extends AbstractGuardLMSTestCase {

	/**
	 * Hook suffix the add_options_page() stub hands back.
	 *
	 * Deliberately NOT the stock "settings_page_guardlms": the plugin must use
	 * whatever WordPress returned (a menu-editor plugin or a future move to a
	 * top-level menu changes it), not re-derive it from the slug.
	 */
	private const HOOK_SUFFIX = 'toplevel_page_guardlms';

	/**
	 * Run GuardLMS_Settings::register() once with the Settings API stubbed.
	 *
	 * register() is idempotent per process, so the first call in this file
	 * decides the hook suffix every later test sees.
	 */
	private function registerPage(): void {
		Functions\when( 'add_options_page' )->justReturn( self::HOOK_SUFFIX );
		Functions\when( 'register_setting' )->justReturn( null );
		Functions\when( 'add_settings_section' )->justReturn( null );
		Functions\when( 'add_settings_field' )->justReturn( null );

		GuardLMS_Settings::register();
	}

	public function test_admin_assets_are_enqueued_on_the_screen_wordpress_registered(): void {
		$this->registerPage();

		Functions\expect( 'wp_enqueue_style' )
			->once()
			->with(
				'guardlms-admin',
				GUARDLMS_PLUGIN_URL . 'assets/admin.css',
				array(),
				GUARDLMS_VERSION
			);
		Functions\expect( 'wp_enqueue_script' )
			->once()
			->with(
				'guardlms-admin',
				GUARDLMS_PLUGIN_URL . 'assets/admin.js',
				array(),
				GUARDLMS_VERSION,
				true
			);

		GuardLMS_Settings::enqueue_assets( self::HOOK_SUFFIX );
	}

	public function test_admin_assets_are_not_enqueued_on_other_screens(): void {
		$this->registerPage();

		Functions\expect( 'wp_enqueue_style' )->never();
		Functions\expect( 'wp_enqueue_script' )->never();

		GuardLMS_Settings::enqueue_assets( 'settings_page_guardlms' );
		GuardLMS_Settings::enqueue_assets( 'index.php' );
		GuardLMS_Settings::enqueue_assets( 'plugins.php' );
		GuardLMS_Settings::enqueue_assets( '' );
	}

	/**
	 * The admin script owns the rotate confirmation, driven by a data attribute
	 * on the form rather than an inline onsubmit handler.
	 */
	public function test_admin_script_wires_the_confirm_data_attribute(): void {
		$js_file = GUARDLMS_PLUGIN_DIR . 'assets/admin.js';

		$this->assertFileExists( $js_file );

		$js = (string) file_get_contents( $js_file );

		$this->assertStringContainsString( 'data-guardlms-confirm', $js );
		$this->assertStringContainsString( 'preventDefault', $js );
	}

	/**
	 * The stylesheet ships the classes the settings and connect markup rely on.
	 *
	 * Guards against the CSS file drifting away from the rendered HTML now that
	 * the two no longer live in the same PHP file.
	 */
	public function test_admin_stylesheet_defines_the_classes_the_markup_uses(): void {
		$css_file = GUARDLMS_PLUGIN_DIR . 'assets/admin.css';

		$this->assertFileExists( $css_file );

		$css = (string) file_get_contents( $css_file );

		foreach ( array(
			'.guardlms-title',
			'.guardlms-logo',
			'.guardlms-status',
			'.guardlms-badge',
			'.guardlms-badge-connected',
			'.guardlms-badge-disconnected',
			'.guardlms-badge-rejected',
			'.guardlms-actions',
			'.guardlms-details',
		) as $selector ) {
			$this->assertStringContainsString( $selector, $css, "Missing selector {$selector} in assets/admin.css" );
		}
	}
}
