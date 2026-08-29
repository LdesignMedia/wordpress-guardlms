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

	public function test_admin_stylesheet_is_enqueued_on_the_plugin_screen(): void {
		Functions\expect( 'wp_enqueue_style' )
			->once()
			->with(
				'guardlms-admin',
				GUARDLMS_PLUGIN_URL . 'assets/admin.css',
				array(),
				GUARDLMS_VERSION
			);

		GuardLMS_Settings::enqueue_assets( 'settings_page_guardlms' );
	}

	public function test_admin_stylesheet_is_not_enqueued_on_other_screens(): void {
		Functions\expect( 'wp_enqueue_style' )->never();

		GuardLMS_Settings::enqueue_assets( 'index.php' );
		GuardLMS_Settings::enqueue_assets( 'plugins.php' );
		GuardLMS_Settings::enqueue_assets( '' );
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
