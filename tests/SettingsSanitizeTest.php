<?php
/**
 * Unit tests for GuardLMS_Settings::sanitize() key handling.
 *
 * The connection fields only render in advanced mode
 * (options-general.php?page=guardlms&advanced=1), so a save from the plain page
 * must leave every field it did not render untouched.
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
require_once GUARDLMS_PLUGIN_DIR . 'includes/admin/class-guardlms-settings.php';

/**
 * @covers GuardLMS_Settings::sanitize
 */
final class SettingsSanitizeTest extends AbstractGuardLMSTestCase {

	/**
	 * Stored settings every test in this file starts from.
	 *
	 * @var array
	 */
	private const STORED = array(
		'enabled'           => true,
		'baseurl'           => 'https://stored.example',
		'pushpath'          => '/api/externalpush/custom',
		'sendconfig'        => true,
		'verificationtoken' => 'keep-me',
		'connected_siteurl' => 'https://site.test',
		'lastpush'          => 1234,
	);

	/**
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$_POST['option_page'] = GuardLMS_Settings::GROUP;

		Functions\when( 'get_option' )->justReturn( self::STORED );
		Functions\when( 'wp_unslash' )->returnArg( 1 );
		Functions\when( 'sanitize_key' )->returnArg( 1 );
		Functions\when( 'sanitize_text_field' )->returnArg( 1 );
		Functions\when( 'add_settings_error' )->justReturn( true );
		Functions\when( 'home_url' )->justReturn( 'https://site.test' );
		Functions\when( 'esc_url_raw' )->alias(
			static function ( $url, $protocols = null ) {
				// strpos, not str_starts_with: the plugin supports PHP 7.4.
				return 0 === strpos( (string) $url, 'https://' ) ? (string) $url : '';
			}
		);
	}

	/**
	 * @return void
	 */
	protected function tearDown(): void {
		unset( $_POST['option_page'] );
		parent::tearDown();
	}

	public function test_plain_save_without_connection_fields_keeps_them(): void {
		// The non-advanced page renders no fields at all.
		$clean = GuardLMS_Settings::sanitize( array() );

		$this->assertSame( self::STORED['baseurl'], $clean['baseurl'] );
		$this->assertSame( self::STORED['pushpath'], $clean['pushpath'] );
		$this->assertSame( self::STORED['verificationtoken'], $clean['verificationtoken'] );
		$this->assertTrue( $clean['enabled'] );
		$this->assertTrue( $clean['sendconfig'] );
		// Keys the form never manages survive too.
		$this->assertSame( 1234, $clean['lastpush'] );
	}

	public function test_advanced_save_applies_submitted_values(): void {
		$clean = GuardLMS_Settings::sanitize(
			array(
				'enabled'           => '1',
				'sendconfig'        => '0',
				'baseurl'           => 'https://guardlms.example.com',
				'pushpath'          => '/api/externalpush/wordpress',
				'verificationtoken' => 'fresh-token',
			)
		);

		$this->assertTrue( $clean['enabled'] );
		$this->assertFalse( $clean['sendconfig'] );
		$this->assertSame( 'https://guardlms.example.com', $clean['baseurl'] );
		$this->assertSame( '/api/externalpush/wordpress', $clean['pushpath'] );
		$this->assertSame( 'fresh-token', $clean['verificationtoken'] );
	}

	public function test_non_https_baseurl_keeps_the_previous_value(): void {
		$clean = GuardLMS_Settings::sanitize( array( 'baseurl' => 'http://insecure.example' ) );

		$this->assertSame( self::STORED['baseurl'], $clean['baseurl'] );
	}

	public function test_host_and_path_ignores_the_scheme(): void {
		// home_url() follows the scheme of the current request, so the clone guard
		// must not treat https://site.test as a different site than http://site.test.
		$this->assertSame(
			GuardLMS_Settings::host_and_path( 'http://site.test' ),
			GuardLMS_Settings::host_and_path( 'https://site.test/' )
		);
		$this->assertNotSame(
			GuardLMS_Settings::host_and_path( 'https://site.test' ),
			GuardLMS_Settings::host_and_path( 'https://clone.test' )
		);
	}

	public function test_programmatic_write_passes_through_untouched(): void {
		$_POST['option_page'] = 'some_other_group';

		$input = array( 'lastpush' => 999 );

		$this->assertSame( $input, GuardLMS_Settings::sanitize( $input ) );
	}
}
