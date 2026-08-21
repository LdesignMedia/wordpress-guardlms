<?php
/**
 * Unit tests for GuardLMS_Options (AC6: autoloaded, non-secret settings option).
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

/**
 * @covers GuardLMS_Options
 */
final class OptionsTest extends AbstractGuardLMSTestCase {

	public function test_defaults_have_expected_shape_and_types(): void {
		$defaults = GuardLMS_Options::defaults();

		$this->assertTrue( $defaults['enabled'] );
		$this->assertSame( GUARDLMS_DEFAULT_BASEURL, $defaults['baseurl'] );
		$this->assertSame( GUARDLMS_DEFAULT_PUSHPATH, $defaults['pushpath'] );
		$this->assertFalse( $defaults['sendconfig'] );
		$this->assertSame( '', $defaults['verificationtoken'] );
		$this->assertSame( '', $defaults['connected_siteurl'] );
		$this->assertSame( 0, $defaults['lastpush'] );
		$this->assertSame( 0, $defaults['lastpushstatus'] );
		$this->assertSame( 0, $defaults['keyexpiresat'] );
		$this->assertSame( 0, $defaults['last_plugincount'] );
	}

	public function test_all_layers_stored_values_over_defaults(): void {
		Functions\when( 'get_option' )->justReturn(
			array(
				'baseurl' => 'https://custom.example',
				'enabled' => false,
			)
		);

		$all = GuardLMS_Options::all();

		// Stored values win.
		$this->assertSame( 'https://custom.example', $all['baseurl'] );
		$this->assertFalse( $all['enabled'] );
		// Untouched keys keep their defaults.
		$this->assertSame( GUARDLMS_DEFAULT_PUSHPATH, $all['pushpath'] );
		$this->assertSame( 0, $all['lastpush'] );
	}

	public function test_stored_legacy_default_baseurl_is_remapped(): void {
		// Releases before 0.2.1 persisted the never-provisioned app. host on
		// activation and settings save; it must read as the live default.
		Functions\when( 'get_option' )->justReturn( array( 'baseurl' => GUARDLMS_LEGACY_BASEURL ) );

		$this->assertSame( GUARDLMS_DEFAULT_BASEURL, GuardLMS_Options::stored()['baseurl'] );
		$this->assertSame( GUARDLMS_DEFAULT_BASEURL, GuardLMS_Options::get( 'baseurl' ) );
	}

	public function test_stored_legacy_default_baseurl_with_trailing_slash_is_remapped(): void {
		Functions\when( 'get_option' )->justReturn( array( 'baseurl' => GUARDLMS_LEGACY_BASEURL . '/' ) );

		$this->assertSame( GUARDLMS_DEFAULT_BASEURL, GuardLMS_Options::get( 'baseurl' ) );
	}

	public function test_all_falls_back_to_defaults_when_stored_is_not_array(): void {
		Functions\when( 'get_option' )->justReturn( false );

		$this->assertSame( GuardLMS_Options::defaults(), GuardLMS_Options::all() );
	}

	public function test_get_returns_value_for_known_key_and_default_for_unknown(): void {
		Functions\when( 'get_option' )->justReturn( array( 'baseurl' => 'https://stored.example' ) );

		$this->assertSame( 'https://stored.example', GuardLMS_Options::get( 'baseurl' ) );
		$this->assertSame( 'fallback', GuardLMS_Options::get( 'not_a_key', 'fallback' ) );
		$this->assertNull( GuardLMS_Options::get( 'webserver' ) );
	}

	public function test_update_merges_partial_values_and_preserves_others(): void {
		Functions\when( 'get_option' )->justReturn( array( 'verificationtoken' => 'keep-me' ) );

		$captured = null;
		$autoload = null;
		Functions\when( 'update_option' )->alias(
			static function ( $name, $value, $auto ) use ( &$captured, &$autoload ) {
				$captured = array( $name, $value );
				$autoload = $auto;
				return true;
			}
		);

		GuardLMS_Options::update( array( 'lastpush' => 123 ) );

		$this->assertSame( 'guardlms_settings', $captured[0] );
		$this->assertSame( 123, $captured[1]['lastpush'] );
		// Pre-existing stored key survives the partial merge.
		$this->assertSame( 'keep-me', $captured[1]['verificationtoken'] );
		// Defaults still fill unrelated keys.
		$this->assertSame( GUARDLMS_DEFAULT_BASEURL, $captured[1]['baseurl'] );
		// Autoloaded option.
		$this->assertSame( 'yes', $autoload );
	}

	public function test_set_delegates_to_update(): void {
		Functions\when( 'get_option' )->justReturn( array() );

		$captured = null;
		Functions\when( 'update_option' )->alias(
			static function ( $name, $value ) use ( &$captured ) {
				$captured = $value;
				return true;
			}
		);

		GuardLMS_Options::set( 'lastpushstatus', 200 );

		$this->assertSame( 200, $captured['lastpushstatus'] );
	}

	public function test_ensure_option_adds_defaults_autoloaded_yes(): void {
		Functions\expect( 'add_option' )
			->once()
			->with( 'guardlms_settings', GuardLMS_Options::defaults(), '', 'yes' );

		GuardLMS_Options::ensure_option();
	}

	public function test_delete_removes_the_option(): void {
		Functions\expect( 'delete_option' )
			->once()
			->with( 'guardlms_settings' );

		GuardLMS_Options::delete();
	}

	// --- AC D12: uninstall coverage of the 0.2.0 values -----------------------

	/**
	 * AC D12. uninstall.php runs in an isolated WordPress bootstrap where plugin
	 * classes are unavailable, so its option keys are hardcoded and cannot follow
	 * a refactor. 0.2.0 adds two values - the real-time configuration and the SDK
	 * key - and both live INSIDE the two options already listed, so uninstall.php
	 * genuinely needs no change. This test proves that rather than assuming it:
	 * the day a third option key is introduced, this fails.
	 */
	public function test_uninstall_deletes_exactly_the_options_the_plugin_writes(): void {
		$uninstall = (string) file_get_contents( GUARDLMS_PLUGIN_DIR . 'uninstall.php' );

		$this->assertSame(
			1,
			preg_match( '/\$guardlms_option_keys\s*=\s*array\(([^)]*)\);/', $uninstall, $matches ),
			'Could not find the hardcoded option-key list in uninstall.php.'
		);

		preg_match_all( "/'([^']+)'/", $matches[1], $keys );
		sort( $keys[1] );

		$this->assertSame( array( 'guardlms_credentials', 'guardlms_sdk_credentials', 'guardlms_settings' ), $keys[1] );
	}

	/**
	 * The other half of AC D12: the plugin must not have grown an option key that
	 * uninstall.php does not delete. Scanned from source, because the failure
	 * mode is an orphaned row nobody notices until a support ticket.
	 */
	public function test_no_plugin_class_writes_an_option_outside_the_uninstall_list(): void {
		$known = array( 'guardlms_settings', 'guardlms_credentials', 'guardlms_sdk_credentials' );
		$found = array();

		$files = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( GUARDLMS_PLUGIN_DIR . 'includes' )
		);

		foreach ( $files as $file ) {
			if ( 'php' !== $file->getExtension() ) {
				continue;
			}
			$source = (string) file_get_contents( $file->getPathname() );

			// const OPTION = '...'; and update_option( 'literal', ... ).
			preg_match_all( "/const\s+OPTION\s*=\s*'([^']+)'/", $source, $consts );
			preg_match_all( "/(?:update_option|add_option|delete_option)\(\s*'([^']+)'/", $source, $calls );

			$found = array_merge( $found, $consts[1], $calls[1] );
		}

		$found = array_values( array_unique( $found ) );
		sort( $found );

		$this->assertSame(
			array(),
			array_diff( $found, $known ),
			'A plugin class writes an option key that uninstall.php does not delete.'
		);
		// Sanity: the scan actually found something, so an empty result cannot
		// make this pass vacuously.
		$this->assertNotEmpty( $found );
	}

	// --- AC D13: downgrade safety --------------------------------------------

	/**
	 * AC D13. Reinstalling 0.1.x leaves the stored real-time values in place but
	 * unread: 0.1.x has no injector and no reader for the nested `sdk` array or
	 * the `sdkkey` credential. Simulated by asking the 0.1.x-era accessors to
	 * read a 0.2.0-era store: they must return the 0.1.x values untouched and
	 * raise no notice.
	 */
	public function test_a_0_1_x_downgrade_reads_the_0_2_0_store_without_notices(): void {
		$store = array(
			'guardlms_settings'    => array(
				'enabled'           => true,
				'verificationtoken' => 'verify-xyz',
				'sdk'               => array(
					'enabled'      => true,
					'sdk_url'      => 'https://cdn.test/guardlms.min.js?v=abc',
					'refreshed_at' => 1750000000,
				),
			),
			'guardlms_credentials'     => array( 'apikey' => 'push-key' ),
				'guardlms_sdk_credentials' => array( 'sdkkey' => 'glms_live' ),
		);

		Functions\when( 'get_option' )->alias(
			static function ( $name, $default = false ) use ( $store ) {
				return array_key_exists( $name, $store ) ? $store[ $name ] : $default;
			}
		);

		// The 0.1.x accessors, which know nothing about `sdk` or `sdkkey`.
		$this->assertTrue( GuardLMS_Options::get( 'enabled' ) );
		$this->assertSame( 'verify-xyz', GuardLMS_Options::get( 'verificationtoken' ) );
		$this->assertSame( 'push-key', GuardLMS_Credentials::get_key() );
		$this->assertTrue( GuardLMS_Credentials::has_key() );

		// The 0.2.0 values are simply carried, unread, so a re-upgrade restores
		// the previous state with no refetch.
		$this->assertSame( 'glms_live', $store['guardlms_sdk_credentials']['sdkkey'] );
		$this->assertTrue( $store['guardlms_settings']['sdk']['enabled'] );
	}
}
