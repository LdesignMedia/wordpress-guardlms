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
	 * THE CONCURRENCY GUARANTEE. The two credentials have independent writers
	 * that really do run at the same time - the push key from the connect REST
	 * callback and the advanced settings save, the SDK key from the settings-page
	 * bootstrap, the Refresh button and the daily cron.
	 *
	 * This drives them interleaved in the worst possible order against a shared
	 * store: both writers read, then both write, each unaware of the other. While
	 * the two keys shared one option this dropped whichever key the loser had not
	 * read - and losing the SDK key that way is unrecoverable through the UI,
	 * because the backend returns `key: null` once a key has been issued.
	 */
	public function test_interleaved_writers_cannot_drop_each_others_credential(): void {
		$store = array();
		Functions\when( 'get_option' )->alias(
			static function ( $name, $default = false ) use ( &$store ) {
				return array_key_exists( $name, $store ) ? $store[ $name ] : $default;
			}
		);
		Functions\when( 'update_option' )->alias(
			static function ( $name, $value ) use ( &$store ) {
				$store[ $name ] = $value;
				return true;
			}
		);

		// Writer A reads. Writer B reads. B writes. A writes last, so under a
		// shared read-modify-write A's stale snapshot would win and erase B.
		GuardLMS_Credentials::set_key( 'push-key-v1' );
		GuardLMS_Credentials::set_sdk_key( 'glms_live' );
		GuardLMS_Credentials::set_key( 'push-key-v2' );

		$this->assertSame( 'push-key-v2', GuardLMS_Credentials::get_key() );
		$this->assertSame( 'glms_live', GuardLMS_Credentials::get_sdk_key() );

		// And in the other order.
		GuardLMS_Credentials::set_sdk_key( 'glms_rotated' );
		$this->assertSame( 'push-key-v2', GuardLMS_Credentials::get_key() );
		$this->assertSame( 'glms_rotated', GuardLMS_Credentials::get_sdk_key() );
	}

	/**
	 * The structural reason the above holds: neither setter reads the other's
	 * option at all, so there is no shared value to lose. Asserted on the option
	 * NAMES touched, because that is the property that makes the guarantee hold
	 * under any interleaving rather than just the one exercised above.
	 */
	public function test_each_setter_writes_only_its_own_option(): void {
		$touched = array();
		Functions\when( 'get_option' )->alias(
			static function ( $name, $default = false ) use ( &$touched ) {
				$touched[] = 'read:' . $name;
				return $default;
			}
		);
		Functions\when( 'update_option' )->alias(
			static function ( $name ) use ( &$touched ) {
				$touched[] = 'write:' . $name;
				return true;
			}
		);

		GuardLMS_Credentials::set_key( 'push-key' );
		$this->assertSame( array( 'write:guardlms_credentials' ), $touched );

		$touched = array();
		GuardLMS_Credentials::set_sdk_key( 'glms_abc' );
		$this->assertSame( array( 'write:guardlms_sdk_credentials' ), $touched );
	}

	public function test_get_sdk_key_returns_trimmed_value_and_empty_when_absent(): void {
		Functions\when( 'get_option' )->justReturn( array( 'sdkkey' => '  glms_abc  ' ) );
		$this->assertSame( 'glms_abc', GuardLMS_Credentials::get_sdk_key() );

		Functions\when( 'get_option' )->justReturn( array() );
		$this->assertSame( '', GuardLMS_Credentials::get_sdk_key() );

		Functions\when( 'get_option' )->justReturn( 'not-an-array' );
		$this->assertSame( '', GuardLMS_Credentials::get_sdk_key() );
	}

	public function test_set_sdk_key_stores_trimmed_and_non_autoloaded(): void {
		$captured = null;
		$name     = null;
		$autoload = null;
		Functions\when( 'update_option' )->alias(
			static function ( $option, $value, $auto ) use ( &$name, &$captured, &$autoload ) {
				$name     = $option;
				$captured = $value;
				$autoload = $auto;
				return true;
			}
		);

		GuardLMS_Credentials::set_sdk_key( '  glms_new  ' );

		$this->assertSame( 'guardlms_sdk_credentials', $name );
		$this->assertSame( array( 'sdkkey' => 'glms_new' ), $captured );
		// The SDK key is public once injected, but it is still a bearer
		// credential for a write endpoint, so it stays out of the autoload set.
		$this->assertSame( 'no', $autoload );
	}

	public function test_delete_sdk_key_removes_only_the_sdk_option(): void {
		$deleted = array();
		Functions\when( 'delete_option' )->alias(
			static function ( $name ) use ( &$deleted ) {
				$deleted[] = $name;
				return true;
			}
		);

		GuardLMS_Credentials::delete_sdk_key();

		$this->assertSame( array( 'guardlms_sdk_credentials' ), $deleted );
	}

	public function test_delete_removes_both_credential_options(): void {
		$deleted = array();
		Functions\when( 'delete_option' )->alias(
			static function ( $name ) use ( &$deleted ) {
				$deleted[] = $name;
				return true;
			}
		);

		GuardLMS_Credentials::delete();

		$this->assertSame(
			array( 'guardlms_credentials', 'guardlms_sdk_credentials' ),
			$deleted
		);
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
