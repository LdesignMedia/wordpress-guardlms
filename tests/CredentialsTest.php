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
