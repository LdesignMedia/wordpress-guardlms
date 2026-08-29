<?php
/**
 * Rendering tests for GuardLMS_Connect_Page::render_status().
 *
 * The connection status is the one line on the whole screen a site owner
 * reads, so "Connected" has to mean the connection works. It did not: a site
 * whose key GuardLMS had deleted kept reading Connected, with a key expiry a
 * year out, while every push and every settings refresh was refused.
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
require_once GUARDLMS_PLUGIN_DIR . 'includes/class-guardlms-http.php';
require_once GUARDLMS_PLUGIN_DIR . 'includes/class-guardlms-sdk-config.php';
require_once GUARDLMS_PLUGIN_DIR . 'includes/class-guardlms-sdk-client.php';
require_once GUARDLMS_PLUGIN_DIR . 'includes/class-guardlms-api-client.php';
require_once GUARDLMS_PLUGIN_DIR . 'includes/class-guardlms-connect-manager.php';
require_once GUARDLMS_PLUGIN_DIR . 'includes/admin/class-guardlms-settings.php';
require_once GUARDLMS_PLUGIN_DIR . 'includes/admin/class-guardlms-connect-page.php';

/**
 * @covers GuardLMS_Connect_Page::render_status
 */
final class ConnectPageRenderTest extends AbstractGuardLMSTestCase {

	/**
	 * In-memory option store keyed by option name.
	 *
	 * @var array<string,mixed>
	 */
	private $store = array();

	protected function setUp(): void {
		parent::setUp();
		$this->store = array();

		Functions\when( 'get_option' )->alias(
			function ( $name, $default = false ) {
				return array_key_exists( $name, $this->store ) ? $this->store[ $name ] : $default;
			}
		);
		Functions\when( 'update_option' )->alias(
			function ( $name, $value ) {
				$this->store[ $name ] = $value;
				return true;
			}
		);
		Functions\when( 'wp_date' )->alias(
			static function ( $format, $timestamp = null ) {
				return gmdate( 'Y-m-d H:i', (int) $timestamp );
			}
		);
	}

	/**
	 * Seed a connected site, optionally one whose key GuardLMS has refused.
	 *
	 * @param int $rejected_at Unix timestamp of the first refusal, 0 for none.
	 * @return void
	 */
	private function seedConnected( int $rejected_at = 0 ): void {
		$this->store['guardlms_settings']    = array(
			'connectedat'    => 1750000000,
			'keyexpiresat'   => 1800000000,
			'authrejectedat' => $rejected_at,
		);
		$this->store['guardlms_credentials'] = array( 'apikey' => 'push-key-abc' );
	}

	private function render(): string {
		ob_start();
		GuardLMS_Connect_Page::render_status();

		return (string) ob_get_clean();
	}

	public function test_a_working_connection_still_reads_as_connected(): void {
		$this->seedConnected();

		$html = $this->render();

		$this->assertStringContainsString( 'Connected', $html );
		$this->assertStringContainsString( 'guardlms-badge-connected', $html );
		$this->assertStringNotContainsString( 'Reconnect required', $html );
	}

	public function test_a_site_that_never_connected_says_so(): void {
		$html = $this->render();

		$this->assertStringContainsString( 'Not connected', $html );
		$this->assertStringContainsString( 'guardlms-badge-disconnected', $html );
	}

	/**
	 * The regression this class exists for: holding a key is not the same as
	 * having a connection, so a refused key must not render as Connected.
	 */
	public function test_a_refused_key_does_not_render_as_connected(): void {
		$this->seedConnected( 1754000000 );

		$html = $this->render();

		$this->assertStringContainsString( 'Reconnect required', $html );
		$this->assertStringContainsString( 'guardlms-badge-rejected', $html );
		$this->assertStringNotContainsString( 'guardlms-badge-connected', $html );
	}

	public function test_a_refused_key_explains_the_cause_and_the_remedy(): void {
		$this->seedConnected( 1754000000 );

		$html = $this->render();

		$this->assertStringContainsString( 'removed from the GuardLMS dashboard', $html );
		$this->assertStringContainsString( 'stopped reporting', $html );
		$this->assertStringContainsString( 'Reconnect', $html );
	}

	public function test_a_refused_key_says_since_when_the_site_stopped_reporting(): void {
		$this->seedConnected( 1754000000 );

		$html = $this->render();

		$this->assertStringContainsString( 'First refused:', $html );
		$this->assertStringContainsString( gmdate( 'Y-m-d H:i', 1754000000 ), $html );
	}

	/**
	 * A site with no key at all has nothing to reconnect a refused key for, so
	 * a stale flag must not resurrect the warning on a disconnected screen.
	 */
	public function test_a_disconnected_site_never_shows_the_refused_warning(): void {
		$this->store['guardlms_settings'] = array(
			'connectedat'    => 0,
			'authrejectedat' => 1754000000,
		);

		$html = $this->render();

		$this->assertStringContainsString( 'Not connected', $html );
		$this->assertStringNotContainsString( 'Reconnect required', $html );
	}
}
