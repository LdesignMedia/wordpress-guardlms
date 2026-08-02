<?php
/**
 * Guard against a renamed cache key with a surviving old-name reader.
 *
 * A key written under one literal and read under another is silent by
 * construction: nothing errors, the read simply never finds anything, and the
 * invalidation it was meant to perform quietly becomes a no-op. The only
 * structural defence is one constant per key and no bare literals at the call
 * sites, which is what this file enforces.
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

require_once __DIR__ . '/AbstractGuardLMSTestCase.php';
require_once GUARDLMS_PLUGIN_DIR . 'includes/class-guardlms-options.php';
require_once GUARDLMS_PLUGIN_DIR . 'includes/class-guardlms-credentials.php';
require_once GUARDLMS_PLUGIN_DIR . 'includes/class-guardlms-sdk-client.php';
require_once GUARDLMS_PLUGIN_DIR . 'includes/admin/class-guardlms-settings.php';
require_once GUARDLMS_PLUGIN_DIR . 'includes/admin/class-guardlms-connect-page.php';
require_once GUARDLMS_PLUGIN_DIR . 'includes/admin/class-guardlms-realtime-page.php';

/**
 * @coversNothing
 */
final class CacheKeysTest extends AbstractGuardLMSTestCase {

	/**
	 * Every plugin PHP file under includes/.
	 *
	 * @return string[]
	 */
	private function sourceFiles(): array {
		$paths = array();
		$files = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( GUARDLMS_PLUGIN_DIR . 'includes' )
		);

		foreach ( $files as $file ) {
			if ( 'php' === $file->getExtension() ) {
				$paths[] = $file->getPathname();
			}
		}

		sort( $paths );

		return $paths;
	}

	/**
	 * The full inventory of persistent keys this plugin writes or clears.
	 *
	 * Two options and four transients. Recorded here so the list is reviewable in
	 * one place rather than reconstructed by grep every time.
	 *
	 * @return array<string,string> Key name => the constant that owns it.
	 */
	private function inventory(): array {
		return array(
			// Options.
			'guardlms_settings'           => 'GuardLMS_Options::OPTION',
			'guardlms_credentials'        => 'GuardLMS_Credentials::OPTION',
			// Transients.
			'guardlms_connect_notice'     => 'GuardLMS_Connect_Page::NOTICE_TRANSIENT',
			'guardlms_sdk_notice'         => 'GuardLMS_Realtime_Page::NOTICE_TRANSIENT',
			'guardlms_sdk_bootstrap_lock' => 'GuardLMS_Sdk_Client::BOOTSTRAP_LOCK',
			'guardlms_push_result_'       => 'GuardLMS_Settings::PUSH_NOTICE_PREFIX',
		);
	}

	/**
	 * Each key resolves through its declared constant, so the inventory above is
	 * checked against the code rather than merely asserted.
	 */
	public function test_every_inventoried_key_is_defined_by_its_constant(): void {
		foreach ( $this->inventory() as $key => $constant ) {
			$this->assertTrue( defined( $constant ), "Constant {$constant} does not exist." );
			$this->assertSame( $key, constant( $constant ), "Constant {$constant} no longer names '{$key}'." );
		}
	}

	/**
	 * ADD-1's failure mode: two constants holding the same literal. A rename then
	 * updates one and leaves the other silently pointing at a row nothing writes.
	 */
	public function test_no_two_constants_hold_the_same_key_literal(): void {
		$byLiteral = array();

		foreach ( $this->sourceFiles() as $path ) {
			$source = (string) file_get_contents( $path );
			preg_match_all( "/const\s+(\w+)\s*=\s*'(guardlms_[a-z0-9_]*)'/", $source, $matches, PREG_SET_ORDER );

			foreach ( $matches as $match ) {
				$byLiteral[ $match[2] ][] = basename( $path ) . '::' . $match[1];
			}
		}

		foreach ( $byLiteral as $literal => $owners ) {
			$this->assertCount(
				1,
				$owners,
				"'{$literal}' is declared by more than one constant: " . implode( ', ', $owners )
			);
		}

		$this->assertNotEmpty( $byLiteral, 'The constant scan found nothing, so it cannot prove anything.' );
	}

	/**
	 * No transient call may name its key with a bare literal. A literal at a call
	 * site is what survives a rename of the constant.
	 */
	public function test_no_transient_call_uses_a_bare_string_literal(): void {
		$offenders = array();

		foreach ( $this->sourceFiles() as $path ) {
			$lines = file( $path, FILE_IGNORE_NEW_LINES );

			foreach ( $lines as $number => $line ) {
				if ( ! preg_match( '/\b(?:set|get|delete)_transient\s*\(/', $line ) ) {
					continue;
				}
				if ( preg_match( "/\b(?:set|get|delete)_transient\s*\(\s*'/", $line ) ) {
					$offenders[] = basename( $path ) . ':' . ( $number + 1 ) . ' ' . trim( $line );
				}
			}
		}

		$this->assertSame( array(), $offenders, "Bare transient key literal:\n" . implode( "\n", $offenders ) );
	}

	/**
	 * Same rule for the option calls: they go through GuardLMS_Options and
	 * GuardLMS_Credentials, which each own exactly one key.
	 *
	 * Scoped to `guardlms_`-prefixed keys: reads of WordPress core options
	 * (date_format, active_plugins) are not this plugin's to own.
	 *
	 * uninstall.php is deliberately exempt and is covered by its own test in
	 * OptionsTest: it runs in an isolated bootstrap where no plugin class exists,
	 * so its keys MUST be literals.
	 */
	public function test_no_option_call_outside_the_accessors_uses_a_bare_literal(): void {
		$owners    = array(
			'class-guardlms-options.php',
			'class-guardlms-credentials.php',
		);
		$offenders = array();

		foreach ( $this->sourceFiles() as $path ) {
			if ( in_array( basename( $path ), $owners, true ) ) {
				continue;
			}

			$lines = file( $path, FILE_IGNORE_NEW_LINES );
			foreach ( $lines as $number => $line ) {
				if ( preg_match( "/\b(?:update|add|delete|get)_option\s*\(\s*'guardlms_/", $line ) ) {
					$offenders[] = basename( $path ) . ':' . ( $number + 1 ) . ' ' . trim( $line );
				}
			}
		}

		$this->assertSame( array(), $offenders, "Bare option key literal:\n" . implode( "\n", $offenders ) );
	}

	/**
	 * The SDK key has exactly one writer and one reader, both in
	 * GuardLMS_Credentials. A second writer elsewhere is how a credential ends up
	 * in the autoloaded settings option by accident.
	 */
	public function test_the_sdk_key_has_a_single_owner(): void {
		$offenders = array();

		foreach ( $this->sourceFiles() as $path ) {
			if ( 'class-guardlms-credentials.php' === basename( $path ) ) {
				continue;
			}
			if ( false !== strpos( (string) file_get_contents( $path ), "'sdkkey'" ) ) {
				$offenders[] = basename( $path );
			}
		}

		$this->assertSame( array(), $offenders, 'The sdkkey literal appears outside GuardLMS_Credentials.' );
	}

	/**
	 * The page-cache purge has one implementation. Two copies would drift, and a
	 * toggle that purges a different set of caches than a connect does is a
	 * "sometimes it takes effect" bug nobody can reproduce.
	 */
	public function test_page_cache_purging_has_a_single_implementation(): void {
		$flushers  = array( 'w3tc_flush_all', 'litespeed_purge_all', 'rocket_clean_domain', 'wp_cache_clear_cache' );
		$offenders = array();

		foreach ( $this->sourceFiles() as $path ) {
			if ( 'class-guardlms-connect-manager.php' === basename( $path ) ) {
				continue;
			}
			$source = (string) file_get_contents( $path );

			foreach ( $flushers as $flusher ) {
				if ( false !== strpos( $source, $flusher ) ) {
					$offenders[] = basename( $path ) . ' calls ' . $flusher;
				}
			}
		}

		$this->assertSame( array(), $offenders, 'Cache flushing must live only in GuardLMS_Connect_Manager::purge_caches().' );
	}
}
