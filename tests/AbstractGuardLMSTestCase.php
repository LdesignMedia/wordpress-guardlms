<?php
/**
 * Shared base test case for GuardLMS unit tests.
 *
 * Provides Brain Monkey setUp/tearDown, minimal WP_Error / is_wp_error() stubs
 * (Brain Monkey stubs functions, not the WP_Error class), common translation and
 * escaping stubs, a recursive "no secrets" assertion, and a fixture for the
 * `wp-admin/includes/plugin.php` file that GuardLMS_Collector / GuardLMS_Pusher
 * `require_once` before calling get_plugins().
 *
 * This file deliberately does NOT end in `Test.php`, so PHPUnit's suffix scan
 * skips it. Every concrete test requires it explicitly.
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

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/*
 * Minimal WP_Error stub. WordPress core is not loaded in unit tests, and Brain
 * Monkey only intercepts functions, so the class is provided here. It mirrors
 * just the surface the plugin relies on (code, message, data).
 */
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {

		/** @var array<string,string[]> */
		public $errors = array();

		/** @var array<string,mixed> */
		public $error_data = array();

		/**
		 * @param string $code    Error code.
		 * @param string $message Error message.
		 * @param mixed  $data    Optional error data.
		 */
		public function __construct( $code = '', $message = '', $data = '' ) {
			if ( '' === $code ) {
				return;
			}

			$this->errors[ $code ][] = $message;

			if ( '' !== $data ) {
				$this->error_data[ $code ] = $data;
			}
		}

		/**
		 * @return string
		 */
		public function get_error_code() {
			$codes = array_keys( $this->errors );

			return $codes ? (string) $codes[0] : '';
		}

		/**
		 * @param string $code Optional specific code.
		 * @return string
		 */
		public function get_error_message( $code = '' ) {
			if ( '' === $code ) {
				$code = $this->get_error_code();
			}

			return isset( $this->errors[ $code ][0] ) ? (string) $this->errors[ $code ][0] : '';
		}

		/**
		 * @param string $code Optional specific code.
		 * @return mixed
		 */
		public function get_error_data( $code = '' ) {
			if ( '' === $code ) {
				$code = $this->get_error_code();
			}

			return $this->error_data[ $code ] ?? null;
		}
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	/**
	 * @param mixed $thing Value to test.
	 * @return bool
	 */
	function is_wp_error( $thing ) {
		return $thing instanceof WP_Error;
	}
}

/**
 * Lightweight stand-in for a WP_Theme instance used by the collector tests.
 *
 * WP_Theme exposes header values through get(); this reproduces just that.
 */
final class GuardLMS_Test_Theme {

	/** @var array<string,string> */
	private $data;

	/**
	 * @param array<string,string> $data Header values (Name, Version, ...).
	 */
	public function __construct( array $data ) {
		$this->data = $data;
	}

	/**
	 * @param string $key Header key.
	 * @return string|false
	 */
	public function get( $key ) {
		return $this->data[ $key ] ?? false;
	}
}

/**
 * Base test case wiring Brain Monkey and shared stubs.
 */
abstract class AbstractGuardLMSTestCase extends TestCase {

	/**
	 * Secret-looking tokens that must never appear anywhere in a payload.
	 *
	 * @var string[]
	 */
	protected const SECRET_TOKENS = array( 'apikey', 'password', 'secret', 'salt', 'DB_' );

	/**
	 * Ensure the admin plugin.php include the collector/pusher require_once exists.
	 *
	 * ABSPATH points at the plugin root in tests/bootstrap.php, so the real WP
	 * core file is absent. An empty stub lets `require_once` succeed while the
	 * actual get_plugins()/get_mu_plugins()/get_dropins() functions come from
	 * Brain Monkey. The file is gitignored, never shipped.
	 */
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();

		$stub = ABSPATH . 'wp-admin/includes/plugin.php';
		if ( ! file_exists( $stub ) ) {
			$dir = dirname( $stub );
			if ( ! is_dir( $dir ) ) {
				mkdir( $dir, 0777, true );
			}
			file_put_contents(
				$stub,
				"<?php\n// Test fixture. get_plugins()/get_mu_plugins()/get_dropins() are stubbed via Brain Monkey.\n"
			);
		}
	}

	/**
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// Translation passthrough: __( $text, $domain ) returns $text.
		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'esc_html__' )->returnArg( 1 );

		// The echoing variants, escaping for real so output assertions are
		// meaningful.
		Functions\when( 'esc_html_e' )->alias(
			static function ( $text ) {
				echo htmlspecialchars( (string) $text, ENT_QUOTES );
			}
		);
		Functions\when( 'esc_attr_e' )->alias(
			static function ( $text ) {
				echo htmlspecialchars( (string) $text, ENT_QUOTES );
			}
		);

		// Real escaping so "is it escaped?" assertions are meaningful.
		Functions\when( 'esc_attr' )->alias(
			static function ( $text ) {
				return htmlspecialchars( (string) $text, ENT_QUOTES );
			}
		);
		Functions\when( 'esc_html' )->alias(
			static function ( $text ) {
				return htmlspecialchars( (string) $text, ENT_QUOTES );
			}
		);
	}

	/**
	 * @return void
	 */
	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Assert no secret-looking key or scalar value appears anywhere in $data.
	 *
	 * Walks the structure recursively and checks every array key and every
	 * scalar value against SECRET_TOKENS (case-insensitive).
	 *
	 * @param mixed  $data Structure to scan.
	 * @param string $path Current path (for failure messages).
	 * @return void
	 */
	protected function assertNoSecrets( $data, string $path = 'payload' ): void {
		if ( is_array( $data ) ) {
			foreach ( $data as $key => $value ) {
				if ( is_string( $key ) ) {
					$this->assertNotSecretToken( $key, $path . " (key '{$key}')" );
				}
				$this->assertNoSecrets( $value, $path . '.' . $key );
			}
			return;
		}

		if ( is_string( $data ) ) {
			$this->assertNotSecretToken( $data, $path );
		}
	}

	/**
	 * @param string $subject Value to check.
	 * @param string $path    Location (for failure message).
	 * @return void
	 */
	private function assertNotSecretToken( string $subject, string $path ): void {
		foreach ( self::SECRET_TOKENS as $token ) {
			$this->assertFalse(
				stripos( $subject, $token ) !== false,
				"Secret-looking token '{$token}' found at {$path}: {$subject}"
			);
		}
	}
}
