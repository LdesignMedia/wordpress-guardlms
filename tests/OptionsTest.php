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
}
