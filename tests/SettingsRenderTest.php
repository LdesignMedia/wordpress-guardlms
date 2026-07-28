<?php
/**
 * Render and save tests for the real-time monitoring admin section.
 *
 * Covers the D2 "user friendly" acceptance criteria UX0-UX7 (the §5.3 precedence
 * chain as the admin actually sees it) plus AC D6 (the toggle purges page
 * caches) and the sanitize() overlay behaviour that keeps the two forms on the
 * page from clobbering each other.
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
require_once GUARDLMS_PLUGIN_DIR . 'includes/class-guardlms-http.php';
require_once GUARDLMS_PLUGIN_DIR . 'includes/class-guardlms-sdk-config.php';
require_once GUARDLMS_PLUGIN_DIR . 'includes/class-guardlms-sdk-status.php';
require_once GUARDLMS_PLUGIN_DIR . 'includes/class-guardlms-sdk-client.php';
require_once GUARDLMS_PLUGIN_DIR . 'includes/class-guardlms-sdk-injector.php';
require_once GUARDLMS_PLUGIN_DIR . 'includes/class-guardlms-api-client.php';
require_once GUARDLMS_PLUGIN_DIR . 'includes/class-guardlms-connect-manager.php';
require_once GUARDLMS_PLUGIN_DIR . 'includes/admin/class-guardlms-settings.php';
require_once GUARDLMS_PLUGIN_DIR . 'includes/admin/class-guardlms-realtime-page.php';

/**
 * @covers GuardLMS_Realtime_Page
 * @covers GuardLMS_Settings::sanitize
 */
final class SettingsRenderTest extends AbstractGuardLMSTestCase {

	/**
	 * In-memory option store keyed by option name.
	 *
	 * @var array<string,mixed>
	 */
	private $store = array();

	/**
	 * Number of GuardLMS_Connect_Manager::purge_caches() cache flushes observed.
	 *
	 * @var int
	 */
	private $purges = 0;

	protected function setUp(): void {
		parent::setUp();
		$this->store  = array();
		$this->purges = 0;

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
		Functions\when( 'esc_url' )->returnArg( 1 );
		Functions\when( 'esc_url_raw' )->returnArg( 1 );
		Functions\when( 'sanitize_text_field' )->returnArg( 1 );
		Functions\when( 'admin_url' )->justReturn( 'https://site.test/wp-admin/admin-post.php' );
		Functions\when( 'home_url' )->justReturn( 'https://www.site.test/' );
		Functions\when( 'wp_parse_url' )->alias(
			static function ( $url, $component = -1 ) {
				return parse_url( $url, $component );
			}
		);
		Functions\when( 'wp_json_encode' )->alias(
			static function ( $data ) {
				return json_encode( $data );
			}
		);
		Functions\when( 'wp_date' )->alias(
			static function ( $format, $timestamp = null ) {
				return gmdate( 'Y-m-d H:i', (int) $timestamp );
			}
		);
		Functions\when( 'settings_fields' )->justReturn( null );
		Functions\when( 'wp_nonce_field' )->justReturn( null );
		Functions\when( 'submit_button' )->alias(
			static function ( $text = '', $type = '', $name = '' ) {
				printf( '<input type="submit" name="%s" value="%s">', (string) $name, (string) $text );
			}
		);
		Functions\when( 'checked' )->alias(
			static function ( $checked, $current = true, $echo = true ) {
				$out = ( (string) $checked === (string) $current || ( $checked && $current ) ) ? " checked='checked'" : '';
				if ( $echo ) {
					echo $out; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Test stub.
				}
				return $out;
			}
		);
		Functions\when( 'disabled' )->alias(
			static function ( $disabled, $current = true, $echo = true ) {
				$out = ( $disabled && $current ) ? " disabled='disabled'" : '';
				if ( $echo ) {
					echo $out; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Test stub.
				}
				return $out;
			}
		);
	}

	/**
	 * Seed a connected site with the given real-time configuration overrides.
	 *
	 * @param array  $overrides Overrides for the nested sdk array.
	 * @param string $sdk_key   SDK key to store ('' stores none).
	 * @return void
	 */
	private function seed( array $overrides = array(), string $sdk_key = 'glms_testkey' ): void {
		$this->store['guardlms_settings']    = array(
			'enabled'     => true,
			'baseurl'     => 'https://backend.test',
			'connectedat' => 1750000000,
			'websiteid'   => 42,
			'sdk'         => array_merge(
				array(
					'enabled'               => true,
					'analytics'             => false,
					'sdk_url'               => 'https://backend.test/sdk/guardlms.min.js?v=abc123',
					'errors_endpoint'       => 'https://backend.test/api/sdk/errors/collect',
					'analytics_endpoint'    => 'https://backend.test/api/sdk/analytics/collect',
					'backend_enabled'       => true,
					'subscription_active'   => true,
					'analytics_allowed'     => true,
					'allowed_domains_match' => true,
					'refreshed_at'          => 1750000000,
				),
				$overrides
			),
		);
		$this->store['guardlms_credentials'] = array( 'apikey' => 'push-key' );

		if ( '' !== $sdk_key ) {
			$this->store['guardlms_credentials']['sdkkey'] = $sdk_key;
		}
	}

	/**
	 * Capture the rendered real-time block.
	 *
	 * @return string
	 */
	private function render(): string {
		ob_start();
		GuardLMS_Realtime_Page::render();

		return (string) ob_get_clean();
	}

	// --- UX0: the success path ------------------------------------------------

	/**
	 * UX0. From a connected site with a key, enabling real-time monitoring is a
	 * toggle and a save, with nothing typed or pasted. Asserted structurally: the
	 * section renders, exposes exactly one checkbox for the opt-in, and offers no
	 * text/password input at all.
	 */
	public function test_ux0_the_opt_in_needs_only_a_checkbox_and_a_save(): void {
		$this->seed( array( 'enabled' => false ) );

		$html = $this->render();

		$this->assertStringContainsString( 'Real-time monitoring', $html );
		$this->assertStringContainsString( 'name="guardlms_settings[sdk][enabled]" value="1"', $html );
		$this->assertStringContainsString( 'Save real-time settings', $html );

		// Nothing to type or paste anywhere in the block.
		$this->assertDoesNotMatchRegularExpression( '/<input[^>]+type="text"/', $html );
		$this->assertDoesNotMatchRegularExpression( '/<input[^>]+type="password"/', $html );
		$this->assertDoesNotMatchRegularExpression( '/<textarea/', $html );

		// The toggle is usable, not disabled.
		$this->assertDoesNotMatchRegularExpression(
			'/name="guardlms_settings\[sdk\]\[enabled\]" value="1"[^>]*disabled/',
			$html
		);
	}

	public function test_an_active_site_says_so_plainly(): void {
		$this->seed();

		$html = $this->render();

		$this->assertStringContainsString( 'Real-time monitoring is active on this site.', $html );
	}

	public function test_a_ready_but_switched_off_site_invites_the_toggle(): void {
		$this->seed( array( 'enabled' => false ) );

		$this->assertStringContainsString( 'Switch it on below', $this->render() );
	}

	// --- the section is hidden when it should be -----------------------------

	/**
	 * UX2. Row 2 outranks everything: the section is ABSENT and no error renders,
	 * asserted while rows 1 and 7 are also true.
	 */
	public function test_ux2_a_backend_that_is_too_old_hides_the_section_entirely(): void {
		$this->seed(
			array(
				'backend_supported' => false,
				'refresh_error'     => 'Connection timed out',
			),
			''
		);

		$html = $this->render();

		$this->assertSame( '', $html );
		$this->assertStringNotContainsString( 'Connection timed out', $html );
		$this->assertStringNotContainsString( 'Real-time monitoring', $html );
	}

	public function test_an_unconnected_site_renders_no_real_time_section(): void {
		$this->seed();
		unset( $this->store['guardlms_credentials'] );

		$this->assertSame( '', $this->render() );
	}

	// --- UX1 / UX4 / UX5 / UX7: exactly one headline -------------------------

	/**
	 * UX1. Row 1 in isolation.
	 */
	public function test_ux1_a_missing_key_offers_a_refresh(): void {
		$this->seed( array( 'refreshed_at' => 0 ), '' );

		$html = $this->render();

		$this->assertStringContainsString( 'have not been fetched yet', $html );
		$this->assertStringContainsString( 'Refresh now', $html );
	}

	/**
	 * The toggle is disabled while no key exists, so an admin cannot switch on a
	 * feature that provably cannot run.
	 */
	public function test_the_toggle_is_disabled_while_no_key_exists(): void {
		$this->seed( array( 'refreshed_at' => 0 ), '' );

		$this->assertMatchesRegularExpression(
			'/name="guardlms_settings\[sdk\]\[enabled\]" value="1"[^>]*disabled/',
			$this->render()
		);
	}

	/**
	 * UX4. Row 4 renders its own sentence and wins over rows 7 and 1.
	 */
	public function test_ux4_an_inactive_subscription_wins_over_rows_7_and_1(): void {
		$this->seed(
			array(
				'subscription_active' => false,
				'refresh_error'       => 'Connection timed out',
			),
			''
		);

		$html = $this->render();

		$this->assertStringContainsString( 'No active GuardLMS subscription', $html );
		$this->assertStringNotContainsString( 'Connection timed out', $html );
		$this->assertStringNotContainsString( 'have not been fetched yet', $html );
	}

	/**
	 * UX5. Row 5 wins over rows 4, 7 and 1 with all four true.
	 */
	public function test_ux5_the_dashboard_switch_wins_over_rows_4_7_and_1(): void {
		$this->seed(
			array(
				'backend_enabled'     => false,
				'subscription_active' => false,
				'refresh_error'       => 'Connection timed out',
			),
			''
		);

		$html = $this->render();

		$this->assertStringContainsString( 'turned off in the GuardLMS dashboard', $html );
		$this->assertStringNotContainsString( 'No active GuardLMS subscription', $html );
		$this->assertStringNotContainsString( 'Connection timed out', $html );
		$this->assertStringNotContainsString( 'have not been fetched yet', $html );
	}

	/**
	 * UX7. Row 7 wins over row 1, and when refreshed_at is 0 the last-success
	 * field renders the "no successful refresh yet" string - NEVER an epoch date
	 * and never a blank. This is the shape of a first-ever failed bootstrap,
	 * which is the single most likely real failure.
	 */
	public function test_ux7_a_first_ever_failure_never_renders_an_epoch_or_a_blank(): void {
		$this->seed(
			array(
				'refresh_error' => 'cURL error 28: timed out',
				'refreshed_at'  => 0,
			),
			''
		);

		$html = $this->render();

		$this->assertStringContainsString( 'cURL error 28: timed out', $html );
		$this->assertStringContainsString( 'No successful refresh yet.', $html );
		// Row 7 outranks row 1.
		$this->assertStringNotContainsString( 'have not been fetched yet', $html );

		// The epoch, in any of the shapes wp_date() could produce for 0.
		$this->assertStringNotContainsString( '1970', $html );
		$this->assertStringNotContainsString( 'Last successful refresh:', $html );
	}

	public function test_ux7_a_later_failure_reports_the_last_success(): void {
		$this->seed(
			array(
				'refresh_error' => 'cURL error 28: timed out',
				'refreshed_at'  => 1750000000,
			)
		);

		$html = $this->render();

		$this->assertStringContainsString( 'cURL error 28: timed out', $html );
		$this->assertStringContainsString( 'Last successful refresh: ' . gmdate( 'Y-m-d H:i', 1750000000 ), $html );
		$this->assertStringNotContainsString( 'No successful refresh yet.', $html );
	}

	/**
	 * A failed refresh does NOT suppress injection, so the page must not claim
	 * monitoring stopped. §5.3 suppresses only rows 4 and 5.
	 */
	public function test_a_failed_refresh_does_not_claim_monitoring_stopped(): void {
		$this->seed( array( 'refresh_error' => 'timed out' ) );

		$html = $this->render();

		$this->assertStringNotContainsString( 'not being collected', $html );
		$this->assertTrue(
			GuardLMS_Sdk_Status::should_inject( GuardLMS_Sdk_Config::all(), 'glms_testkey' )
		);
	}

	// --- UX3 / UX6: advisories render ALONGSIDE the headline ------------------

	/**
	 * UX3. The analytics advisory renders in addition to whatever headline the
	 * chain selected, and the analytics checkbox renders disabled.
	 */
	public function test_ux3_the_analytics_advisory_renders_alongside_the_headline(): void {
		$this->seed(
			array(
				'analytics_allowed' => false,
				'refresh_error'     => 'cURL error 28: timed out',
			)
		);

		$html = $this->render();

		// Both, not one.
		$this->assertStringContainsString( 'cURL error 28: timed out', $html );
		$this->assertStringContainsString( 'not included in your GuardLMS plan', $html );
		$this->assertStringContainsString( 'error monitoring is still active', $html );

		$this->assertMatchesRegularExpression(
			'/name="guardlms_settings\[sdk\]\[analytics\]" value="1"[^>]*disabled/',
			$html
		);
	}

	public function test_ux3_the_advisory_carries_an_upgrade_link(): void {
		$this->seed( array( 'analytics_allowed' => false ) );

		$html = $this->render();

		$this->assertStringContainsString( 'https://backend.test/billing', $html );
		$this->assertStringContainsString( 'View plans', $html );
	}

	/**
	 * UX6. The mismatch sentence names BOTH the allowed host and this site's
	 * host, and renders alongside the selected headline. Naming only one leaves
	 * the admin guessing which end is wrong.
	 */
	public function test_ux6_the_domain_advisory_names_both_hosts_alongside_the_headline(): void {
		$this->seed(
			array(
				'allowed_domains_match' => false,
				'allowed_domains'       => array( 'example.com' ),
				'subscription_active'   => false,
			)
		);

		$html = $this->render();

		$this->assertStringContainsString( 'No active GuardLMS subscription', $html );
		$this->assertStringContainsString( 'example.com', $html );
		// home_url() is https://www.site.test/, the classic www mismatch.
		$this->assertStringContainsString( 'www.site.test', $html );
		$this->assertStringContainsString( 'Update Allowed domains', $html );
	}

	public function test_a_healthy_site_renders_no_advisories(): void {
		$this->seed();

		$html = $this->render();

		$this->assertStringNotContainsString( 'not included in your GuardLMS plan', $html );
		$this->assertStringNotContainsString( 'Update Allowed domains', $html );
	}

	// --- the action buttons ---------------------------------------------------

	public function test_the_key_dependent_buttons_are_hidden_without_a_key(): void {
		$this->seed( array( 'refreshed_at' => 0 ), '' );

		$html = $this->render();

		$this->assertStringContainsString( 'Refresh now', $html );
		$this->assertStringNotContainsString( 'Send a test error', $html );
		$this->assertStringNotContainsString( 'Replace SDK key', $html );
	}

	public function test_all_three_buttons_render_once_a_key_exists(): void {
		$this->seed();

		$html = $this->render();

		$this->assertStringContainsString( 'guardlms_sdk_refresh', $html );
		$this->assertStringContainsString( 'guardlms_sdk_selftest', $html );
		$this->assertStringContainsString( 'guardlms_sdk_rotate', $html );
		// Rotation is destructive enough to warrant a confirmation.
		$this->assertStringContainsString( 'onsubmit="return confirm(', $html );
	}

	// --- AC D6: sanitize() and the cache purge -------------------------------

	/**
	 * Drive GuardLMS_Settings::sanitize() as the Settings API would.
	 *
	 * @param array $input POST payload for the option.
	 * @return array
	 */
	private function save( array $input ): array {
		$_POST['option_page'] = 'guardlms';
		Functions\when( 'sanitize_key' )->returnArg( 1 );
		Functions\when( 'wp_unslash' )->returnArg( 1 );
		Functions\when( 'w3tc_flush_all' )->alias(
			function () {
				++$this->purges;
			}
		);
		Functions\when( 'litespeed_purge_all' )->justReturn( null );
		Functions\when( 'rocket_clean_domain' )->justReturn( null );
		Functions\when( 'wp_cache_clear_cache' )->justReturn( null );

		$clean = GuardLMS_Settings::sanitize( $input );
		unset( $_POST['option_page'] );

		return $clean;
	}

	/**
	 * AC D6. Flipping the toggle purges page caches exactly once - the single
	 * most common cause of "I turned it on and nothing happened" is a cached
	 * page still serving pre-toggle HTML.
	 */
	public function test_d6_switching_the_toggle_on_purges_page_caches_once(): void {
		$this->seed( array( 'enabled' => false ) );

		$clean = $this->save( array( 'sdk' => array( 'enabled' => '1' ) ) );

		$this->assertTrue( $clean['sdk']['enabled'] );
		$this->assertSame( 1, $this->purges );
	}

	public function test_d6_switching_the_toggle_off_also_purges_once(): void {
		$this->seed( array( 'enabled' => true ) );

		$clean = $this->save( array( 'sdk' => array( 'enabled' => '0' ) ) );

		$this->assertFalse( $clean['sdk']['enabled'] );
		$this->assertSame( 1, $this->purges );
	}

	/**
	 * Re-saving without changing the toggle must NOT purge: flushing a site's
	 * whole page cache on every settings save is a real performance event.
	 */
	public function test_saving_without_changing_the_toggle_does_not_purge(): void {
		$this->seed( array( 'enabled' => true ) );

		$this->save( array( 'sdk' => array( 'enabled' => '1' ) ) );

		$this->assertSame( 0, $this->purges );
	}

	/**
	 * The plan gate is enforced on save, not only by the disabled attribute: a
	 * disabled input is a display state, and the POST can be forged.
	 */
	public function test_analytics_cannot_be_enabled_without_the_plan_entitlement(): void {
		$this->seed( array( 'analytics_allowed' => false ) );

		$clean = $this->save(
			array(
				'sdk' => array(
					'enabled'   => '1',
					'analytics' => '1',
				),
			)
		);

		$this->assertFalse( $clean['sdk']['analytics'] );
	}

	public function test_analytics_is_stored_when_the_plan_allows_it(): void {
		$this->seed( array( 'analytics_allowed' => true ) );

		$clean = $this->save(
			array(
				'sdk' => array(
					'enabled'   => '1',
					'analytics' => '1',
				),
			)
		);

		$this->assertTrue( $clean['sdk']['analytics'] );
	}

	/**
	 * The save must overlay ONLY the admin's two flags. The backend-owned values
	 * live in the same nested array, and a save that rebuilt the array from the
	 * form would blank the key prefix, endpoints and refresh timestamp - turning
	 * a settings save into a silent monitoring outage.
	 */
	public function test_saving_the_toggle_preserves_every_backend_owned_value(): void {
		$this->seed( array( 'enabled' => false ) );
		$before = $this->store['guardlms_settings']['sdk'];

		$clean = $this->save( array( 'sdk' => array( 'enabled' => '1' ) ) );

		foreach ( $before as $key => $value ) {
			if ( 'enabled' === $key ) {
				continue;
			}
			$this->assertSame( $value, $clean['sdk'][ $key ], "Key '{$key}' was lost on save." );
		}
	}

	/**
	 * The advanced form and the real-time form both post to the same option
	 * group. A submit that carries no `sdk` key must leave the real-time settings
	 * completely untouched, or saving an advanced setting would silently switch
	 * real-time monitoring off.
	 */
	public function test_a_submit_without_sdk_fields_leaves_the_real_time_settings_alone(): void {
		$this->seed( array( 'enabled' => true ) );

		$clean = $this->save( array( 'baseurl' => 'https://backend.test' ) );

		$this->assertTrue( $clean['sdk']['enabled'] );
		$this->assertSame( 0, $this->purges );
	}

	/**
	 * sanitize() is registered as the sanitize_option filter, so EVERY
	 * programmatic update_option() re-enters it. A programmatic write must pass
	 * straight through without triggering a cache purge.
	 */
	public function test_a_programmatic_write_does_not_re_enter_the_toggle_logic(): void {
		$this->seed( array( 'enabled' => true ) );
		Functions\when( 'sanitize_key' )->returnArg( 1 );
		Functions\when( 'w3tc_flush_all' )->alias(
			function () {
				++$this->purges;
			}
		);

		// No option_page in $_POST: this is GuardLMS_Options::update() re-entering.
		$passthrough = GuardLMS_Settings::sanitize( array( 'sdk' => array( 'enabled' => false ) ) );

		$this->assertSame( array( 'sdk' => array( 'enabled' => false ) ), $passthrough );
		$this->assertSame( 0, $this->purges );
	}
}
