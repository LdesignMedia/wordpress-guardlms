<?php
/**
 * PHPUnit bootstrap for GuardLMS unit tests.
 *
 * Loads the Composer autoloader (Brain Monkey / Mockery) and defines the plugin
 * constants that the include files reference, so classes under includes/ can be
 * loaded and exercised without a full WordPress runtime. Individual test cases
 * call Brain\Monkey\setUp()/tearDown() and require the specific class under test.
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

$guardlms_autoload = dirname( __DIR__ ) . '/vendor/autoload.php';

if ( ! file_exists( $guardlms_autoload ) ) {
	fwrite( STDERR, "GuardLMS tests require Composer dependencies. Run `composer install` first.\n" );
	exit( 1 );
}

require $guardlms_autoload;

// Root guard used by the runtime include files.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

// Plugin paths.
if ( ! defined( 'GUARDLMS_PLUGIN_DIR' ) ) {
	define( 'GUARDLMS_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
}
if ( ! defined( 'GUARDLMS_PLUGIN_FILE' ) ) {
	define( 'GUARDLMS_PLUGIN_FILE', dirname( __DIR__ ) . '/guardlms.php' );
}
if ( ! defined( 'GUARDLMS_PLUGIN_URL' ) ) {
	define( 'GUARDLMS_PLUGIN_URL', 'https://example.test/wp-content/plugins/guardlms/' );
}

// Version and service defaults referenced by the option/collector classes.
// The version is PARSED from guardlms.php rather than hardcoded: a stale copy
// here would silently make every appVersion assertion test the fixture instead
// of the plugin.
if ( ! defined( 'GUARDLMS_VERSION' ) ) {
	$guardlms_header = (string) file_get_contents( dirname( __DIR__ ) . '/guardlms.php' );
	if ( ! preg_match( '/^\s*\*\s*Version:\s*(\S+)\s*$/m', $guardlms_header, $guardlms_matches ) ) {
		fwrite( STDERR, "Could not parse the Version header from guardlms.php.\n" );
		exit( 1 );
	}
	define( 'GUARDLMS_VERSION', $guardlms_matches[1] );
	unset( $guardlms_header, $guardlms_matches );
}
if ( ! defined( 'GUARDLMS_DEFAULT_BASEURL' ) ) {
	define( 'GUARDLMS_DEFAULT_BASEURL', 'https://dashboard.guardlms.com' );
}
if ( ! defined( 'GUARDLMS_DEFAULT_PUSHPATH' ) ) {
	define( 'GUARDLMS_DEFAULT_PUSHPATH', '/api/externalpush/wordpress' );
}
if ( ! defined( 'GUARDLMS_LEGACY_BASEURL' ) ) {
	define( 'GUARDLMS_LEGACY_BASEURL', 'https://app.guardlms.com' );
}

// WP-Cron hook names.
if ( ! defined( 'GUARDLMS_DAILY_HOOK' ) ) {
	define( 'GUARDLMS_DAILY_HOOK', 'guardlms_daily_push' );
}
if ( ! defined( 'GUARDLMS_INITIAL_HOOK' ) ) {
	define( 'GUARDLMS_INITIAL_HOOK', 'guardlms_initial_push' );
}
