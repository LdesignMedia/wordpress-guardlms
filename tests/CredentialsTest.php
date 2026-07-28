<?php
/**
 * Unit tests for GuardLMS_Credentials (AC6: secret option, non-autoloaded,
 * wp-config constant override).
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
require_once GUARDLMS_PLUGIN_DIR . 'includes/class-guardlms-credentials.php';

/**
 * @covers GuardLMS_Credentials
 */
final class CredentialsTest extends AbstractGuardLMSTestCase {

	public function test_get_key_returns_trimmed_db_value(): void {
		Functions\when( 'get_option' )->justReturn( array( 'apikey' => '  secret-token  ' ) );

		$this->assertSame( 'secret-token', GuardLMS_Credentials::get_key() );
	}

	public function test_get_key_empty_when_option_missing_or_malformed(): void {
		Functions\when( 'get_option' )->justReturn( array() );
		$this->assertSame( '', GuardLMS_Credentials::get_key() );

		Functions\when( 'get_option' )->justReturn( 'not-an-array' );
		$this->assertSame( '', GuardLMS_Credentials::get_key() );
	}

	public function test_has_key_reflects_presence_of_value(): void {
		Functions\when( 'get_option' )->justReturn( array( 'apikey' => 'present' ) );
		$this->assertTrue( GuardLMS_Credentials::has_key() );

		Functions\when( 'get_option' )->justReturn( array( 'apikey' => '' ) );
		$this->assertFalse( GuardLMS_Credentials::has_key() );
	}

	public function test_set_key_stores_trimmed_value_non_autoloaded(): void {
		$captured = null;
		$autoload = null;
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'update_option' )->alias(
			static function ( $name, $value, $auto ) use ( &$captured, &$autoload ) {
				$captured = array( $name, $value );
				$autoload = $auto;
				return true;
			}
		);

		GuardLMS_Credentials::set_key( '  fresh-key  ' );

		$this->assertSame( 'guardlms_credentials', $captured[0] );
		$this->assertSame( array( 'apikey' => 'fresh-key' ), $captured[1] );
		$this->assertSame( 'no', $autoload );
	}

	/**
	 * set_key() merges rather than replaces. A reconnect refreshes the push key
	 * and must not silently discard the SDK key stored beside it - which is what
	 * an unconditional update_option( array( 'apikey' => ... ) ) would do.
	 */
	public function test_set_key_preserves_the_sdk_key_stored_beside_it(): void {
		$captured = null;
		Functions\when( 'get_option' )->justReturn(
			array(
				'apikey' => 'old-push-key',
				'sdkkey' => 'glms_live_sdk_key',
			)
		);
		Functions\when( 'update_option' )->alias(
			static function ( $name, $value ) use ( &$captured ) {
				$captured = $value;
				return true;
			}
		);

		GuardLMS_Credentials::set_key( 'new-push-key' );

		$this->assertSame(
			array(
				'apikey' => 'new-push-key',
				'sdkkey' => 'glms_live_sdk_key',
			),
			$captured
		);
	}

	public function test_get_sdk_key_returns_trimmed_value_and_empty_when_absent(): void {
		Functions\when( 'get_option' )->justReturn( array( 'sdkkey' => '  glms_abc  ' ) );
		$this->assertSame( 'glms_abc', GuardLMS_Credentials::get_sdk_key() );

		Functions\when( 'get_option' )->justReturn( array( 'apikey' => 'push' ) );
		$this->assertSame( '', GuardLMS_Credentials::get_sdk_key() );

		Functions\when( 'get_option' )->justReturn( 'not-an-array' );
		$this->assertSame( '', GuardLMS_Credentials::get_sdk_key() );
	}

	public function test_set_sdk_key_merges_and_stays_non_autoloaded(): void {
		$captured = null;
		$autoload = null;
		Functions\when( 'get_option' )->justReturn( array( 'apikey' => 'push-key' ) );
		Functions\when( 'update_option' )->alias(
			static function ( $name, $value, $auto ) use ( &$captured, &$autoload ) {
				$captured = $value;
				$autoload = $auto;
				return true;
			}
		);

		GuardLMS_Credentials::set_sdk_key( '  glms_new  ' );

		$this->assertSame(
			array(
				'apikey' => 'push-key',
				'sdkkey' => 'glms_new',
			),
			$captured
		);
		$this->assertSame( 'no', $autoload );
	}

	public function test_delete_sdk_key_removes_only_the_sdk_key(): void {
		$captured = null;
		Functions\when( 'get_option' )->justReturn(
			array(
				'apikey' => 'push-key',
				'sdkkey' => 'glms_abc',
			)
		);
		Functions\when( 'update_option' )->alias(
			static function ( $name, $value ) use ( &$captured ) {
				$captured = $value;
				return true;
			}
		);

		GuardLMS_Credentials::delete_sdk_key();

		$this->assertSame( array( 'apikey' => 'push-key' ), $captured );
	}

	/**
	 * disconnect() deletes the whole credentials option and THEN clears the SDK
	 * config, which calls delete_sdk_key(). If that wrote unconditionally it
	 * would recreate the option delete() just removed, leaving an empty
	 * guardlms_credentials row behind on every disconnect.
	 */
	public function test_delete_sdk_key_is_a_no_op_when_the_option_is_absent(): void {
		Functions\when( 'get_option' )->justReturn( false );
		Functions\expect( 'update_option' )->never();

		GuardLMS_Credentials::delete_sdk_key();

		$this->assertSame( '', GuardLMS_Credentials::get_sdk_key() );
	}

	public function test_ensure_option_seeds_empty_key_autoloaded_no(): void {
		Functions\expect( 'add_option' )
			->once()
			->with( 'guardlms_credentials', array( 'apikey' => '' ), '', 'no' );

		GuardLMS_Credentials::ensure_option();
	}

	public function test_delete_removes_the_option(): void {
		Functions\expect( 'delete_option' )
			->once()
			->with( 'guardlms_credentials' );

		GuardLMS_Credentials::delete();
	}

	/**
	 * The wp-config GUARDLMS_PUSH_KEY constant must win over (and ignore) the DB
	 * value and be trimmed. Defining a constant is global + irreversible, so this
	 * runs in an isolated process.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_push_key_constant_overrides_db_and_is_trimmed(): void {
		define( 'GUARDLMS_PUSH_KEY', '  constant-key  ' );

		// Even if the DB holds a different value, the constant wins.
		Functions\when( 'get_option' )->justReturn( array( 'apikey' => 'db-key' ) );

		$this->assertSame( 'constant-key', GuardLMS_Credentials::get_key() );
		$this->assertTrue( GuardLMS_Credentials::has_key() );
	}
}
