<?php
/**
 * Unit tests for GuardLMS_Sdk_Status - the §5.3 precedence chain.
 *
 * The chain exists because several failure states co-occur. Testing each row in
 * isolation would pass against an implementation that renders all of them at
 * once, so every test here that matters asserts a row wins WHILE the rows it
 * outranks are also true.
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
require_once GUARDLMS_PLUGIN_DIR . 'includes/class-guardlms-sdk-config.php';
require_once GUARDLMS_PLUGIN_DIR . 'includes/class-guardlms-sdk-status.php';

/**
 * @covers GuardLMS_Sdk_Status
 */
final class SdkStatusTest extends AbstractGuardLMSTestCase {

	/**
	 * A healthy, fully refreshed configuration, over which each test breaks one thing.
	 *
	 * @param array $overrides Values to override.
	 * @return array
	 */
	private function healthy( array $overrides = array() ): array {
		return array_merge(
			GuardLMS_Sdk_Config::defaults(),
			array(
				'enabled'             => true,
				'sdk_url'             => 'https://cdn.test/guardlms.min.js?v=abc123',
				'errors_endpoint'     => 'https://api.test/collect',
				'analytics_endpoint'  => 'https://api.test/analytics',
				'backend_enabled'     => true,
				'subscription_active' => true,
				'analytics_allowed'   => true,
				'refreshed_at'        => 1750000000,
			),
			$overrides
		);
	}

	// --- headline: the happy path --------------------------------------------

	public function test_a_healthy_configuration_has_no_failure_headline(): void {
		$this->assertSame(
			GuardLMS_Sdk_Status::STATE_OK,
			GuardLMS_Sdk_Status::headline( $this->healthy(), 'glms_key' )
		);
	}

	// --- headline: each row in isolation --------------------------------------

	public function test_row_1_no_key(): void {
		$this->assertSame(
			GuardLMS_Sdk_Status::STATE_NO_KEY,
			GuardLMS_Sdk_Status::headline( $this->healthy(), '' )
		);
	}

	public function test_row_1_treats_a_whitespace_only_key_as_missing(): void {
		$this->assertSame(
			GuardLMS_Sdk_Status::STATE_NO_KEY,
			GuardLMS_Sdk_Status::headline( $this->healthy(), '   ' )
		);
	}

	public function test_row_2_backend_too_old(): void {
		$this->assertSame(
			GuardLMS_Sdk_Status::STATE_BACKEND_TOO_OLD,
			GuardLMS_Sdk_Status::headline( $this->healthy( array( 'backend_supported' => false ) ), 'glms_key' )
		);
	}

	public function test_row_4_subscription_inactive(): void {
		$this->assertSame(
			GuardLMS_Sdk_Status::STATE_NO_SUBSCRIPTION,
			GuardLMS_Sdk_Status::headline( $this->healthy( array( 'subscription_active' => false ) ), 'glms_key' )
		);
	}

	public function test_row_5_dashboard_master_switch_off(): void {
		$this->assertSame(
			GuardLMS_Sdk_Status::STATE_DASHBOARD_OFF,
			GuardLMS_Sdk_Status::headline( $this->healthy( array( 'backend_enabled' => false ) ), 'glms_key' )
		);
	}

	public function test_row_7_refresh_failed(): void {
		$this->assertSame(
			GuardLMS_Sdk_Status::STATE_REFRESH_FAILED,
			GuardLMS_Sdk_Status::headline( $this->healthy( array( 'refresh_error' => 'Connection timed out' ) ), 'glms_key' )
		);
	}

	// --- headline: the precedence chain 2 -> 5 -> 4 -> 7 -> 1 -----------------

	/**
	 * UX2. Row 2 outranks everything. Asserted while rows 5, 4, 7 and 1 are ALL
	 * true, which is the realistic shape of a first-ever bootstrap against an old
	 * backend.
	 */
	public function test_row_2_wins_over_every_other_row(): void {
		$sdk = $this->healthy(
			array(
				'backend_supported'   => false,
				'backend_enabled'     => false,
				'subscription_active' => false,
				'refresh_error'       => 'Connection timed out',
			)
		);

		$this->assertSame( GuardLMS_Sdk_Status::STATE_BACKEND_TOO_OLD, GuardLMS_Sdk_Status::headline( $sdk, '' ) );
		$this->assertFalse( GuardLMS_Sdk_Status::is_section_visible( $sdk ) );
	}

	/**
	 * UX5. Row 5 wins over rows 4, 7 and 1 when all four are true.
	 */
	public function test_row_5_wins_over_rows_4_7_and_1(): void {
		$sdk = $this->healthy(
			array(
				'backend_enabled'     => false,
				'subscription_active' => false,
				'refresh_error'       => 'Connection timed out',
			)
		);

		$this->assertSame( GuardLMS_Sdk_Status::STATE_DASHBOARD_OFF, GuardLMS_Sdk_Status::headline( $sdk, '' ) );
	}

	/**
	 * UX4. Row 4 wins over rows 7 and 1.
	 */
	public function test_row_4_wins_over_rows_7_and_1(): void {
		$sdk = $this->healthy(
			array(
				'subscription_active' => false,
				'refresh_error'       => 'Connection timed out',
			)
		);

		$this->assertSame( GuardLMS_Sdk_Status::STATE_NO_SUBSCRIPTION, GuardLMS_Sdk_Status::headline( $sdk, '' ) );
	}

	/**
	 * UX7. Row 7 wins over row 1 when both are true - the shape of a first-ever
	 * bootstrap against an unreachable backend, which is the single most likely
	 * real failure.
	 */
	public function test_row_7_wins_over_row_1(): void {
		$sdk = $this->healthy(
			array(
				'refresh_error' => 'Connection timed out',
				'refreshed_at'  => 0,
			)
		);

		$this->assertSame( GuardLMS_Sdk_Status::STATE_REFRESH_FAILED, GuardLMS_Sdk_Status::headline( $sdk, '' ) );
	}

	// --- headline: the "never refreshed" trap --------------------------------

	/**
	 * `backend_enabled` and `subscription_active` are backend-owned and both
	 * default to FALSE. Read literally, the §5.3 chain would tell a site that has
	 * never refreshed that its dashboard master switch is off - a diagnosis the
	 * plugin has no evidence for and which sends the admin to the wrong place.
	 * Before a successful refresh those flags mean "unknown", not "off".
	 */
	public function test_a_site_that_never_refreshed_reports_no_key_not_dashboard_off(): void {
		$sdk = GuardLMS_Sdk_Config::defaults();

		$this->assertSame( 0, $sdk['refreshed_at'] );
		$this->assertFalse( $sdk['backend_enabled'] );
		$this->assertFalse( $sdk['subscription_active'] );

		$this->assertSame( GuardLMS_Sdk_Status::STATE_NO_KEY, GuardLMS_Sdk_Status::headline( $sdk, '' ) );
	}

	/**
	 * The same gate must not swallow a genuine "off" once a refresh HAS answered.
	 */
	public function test_dashboard_off_is_reported_once_a_refresh_has_answered(): void {
		$sdk = $this->healthy(
			array(
				'backend_enabled' => false,
				'refreshed_at'    => 1750000000,
			)
		);

		$this->assertSame( GuardLMS_Sdk_Status::STATE_DASHBOARD_OFF, GuardLMS_Sdk_Status::headline( $sdk, 'glms_key' ) );
	}

	// --- advisories -----------------------------------------------------------

	/**
	 * UX3. The analytics advisory renders ALONGSIDE whatever headline the chain
	 * selected, not instead of it.
	 */
	public function test_analytics_advisory_renders_alongside_a_failure_headline(): void {
		$sdk = $this->healthy(
			array(
				'analytics_allowed' => false,
				'refresh_error'     => 'Connection timed out',
			)
		);

		$this->assertSame( GuardLMS_Sdk_Status::STATE_REFRESH_FAILED, GuardLMS_Sdk_Status::headline( $sdk, 'glms_key' ) );
		$this->assertContains( GuardLMS_Sdk_Status::ADVISORY_NO_ANALYTICS, GuardLMS_Sdk_Status::advisories( $sdk ) );
	}

	/**
	 * UX6. The domain advisory renders alongside the headline too.
	 */
	public function test_domain_advisory_renders_alongside_a_failure_headline(): void {
		$sdk = $this->healthy(
			array(
				'allowed_domains_match' => false,
				'allowed_domains'       => array( 'example.com' ),
				'subscription_active'   => false,
			)
		);

		$this->assertSame( GuardLMS_Sdk_Status::STATE_NO_SUBSCRIPTION, GuardLMS_Sdk_Status::headline( $sdk, 'glms_key' ) );
		$this->assertContains( GuardLMS_Sdk_Status::ADVISORY_DOMAIN_MISMATCH, GuardLMS_Sdk_Status::advisories( $sdk ) );
	}

	public function test_both_advisories_can_render_together(): void {
		$sdk = $this->healthy(
			array(
				'analytics_allowed'     => false,
				'allowed_domains_match' => false,
			)
		);

		$this->assertSame(
			array(
				GuardLMS_Sdk_Status::ADVISORY_NO_ANALYTICS,
				GuardLMS_Sdk_Status::ADVISORY_DOMAIN_MISMATCH,
			),
			GuardLMS_Sdk_Status::advisories( $sdk )
		);
	}

	public function test_a_healthy_configuration_raises_no_advisories(): void {
		$this->assertSame( array(), GuardLMS_Sdk_Status::advisories( $this->healthy() ) );
	}

	/**
	 * A site that has never refreshed knows nothing about its plan, so it must not
	 * claim analytics is missing from it.
	 */
	public function test_no_analytics_advisory_before_the_first_refresh(): void {
		$this->assertSame( array(), GuardLMS_Sdk_Status::advisories( GuardLMS_Sdk_Config::defaults() ) );
	}

	// --- should_inject --------------------------------------------------------

	public function test_should_inject_on_a_healthy_enabled_site(): void {
		$this->assertTrue( GuardLMS_Sdk_Status::should_inject( $this->healthy(), 'glms_key' ) );
	}

	/**
	 * Every condition that suppresses injection, one at a time.
	 *
	 * @dataProvider suppressionProvider
	 * @param array  $overrides Config overrides.
	 * @param string $key       SDK key.
	 * @return void
	 */
	public function test_should_inject_is_false_for_each_suppression_condition( array $overrides, string $key ): void {
		$this->assertFalse( GuardLMS_Sdk_Status::should_inject( $this->healthy( $overrides ), $key ) );
	}

	/**
	 * @return array<string,array{0:array,1:string}>
	 */
	public function suppressionProvider(): array {
		return array(
			'admin opted out'      => array( array( 'enabled' => false ), 'glms_key' ),
			'backend too old'      => array( array( 'backend_supported' => false ), 'glms_key' ),
			'dashboard switch off' => array( array( 'backend_enabled' => false ), 'glms_key' ),
			'subscription expired' => array( array( 'subscription_active' => false ), 'glms_key' ),
			'no sdk url'           => array( array( 'sdk_url' => '' ), 'glms_key' ),
			'no errors endpoint'   => array( array( 'errors_endpoint' => '' ), 'glms_key' ),
			'no key'               => array( array(), '' ),
			'whitespace key'       => array( array(), '   ' ),
		);
	}

	/**
	 * §5.3 suppresses injection for rows 4 and 5 only. A failed refresh (row 7)
	 * leaves a perfectly usable key and endpoint behind, so going dark because
	 * one refresh timed out would be the exact silence this design removes.
	 */
	public function test_a_failed_refresh_does_not_stop_injection(): void {
		$sdk = $this->healthy( array( 'refresh_error' => 'Connection timed out' ) );

		$this->assertSame( GuardLMS_Sdk_Status::STATE_REFRESH_FAILED, GuardLMS_Sdk_Status::headline( $sdk, 'glms_key' ) );
		$this->assertTrue( GuardLMS_Sdk_Status::should_inject( $sdk, 'glms_key' ) );
	}

	// --- should_send_analytics ------------------------------------------------

	public function test_analytics_needs_both_the_opt_in_and_the_entitlement(): void {
		$this->assertFalse(
			GuardLMS_Sdk_Status::should_send_analytics( $this->healthy( array( 'analytics' => false ) ) )
		);
		$this->assertFalse(
			GuardLMS_Sdk_Status::should_send_analytics(
				$this->healthy(
					array(
						'analytics'         => true,
						'analytics_allowed' => false,
					)
				)
			)
		);
		$this->assertTrue(
			GuardLMS_Sdk_Status::should_send_analytics(
				$this->healthy(
					array(
						'analytics'         => true,
						'analytics_allowed' => true,
					)
				)
			)
		);
	}

	public function test_analytics_is_suppressed_without_an_endpoint(): void {
		$this->assertFalse(
			GuardLMS_Sdk_Status::should_send_analytics(
				$this->healthy(
					array(
						'analytics'          => true,
						'analytics_allowed'  => true,
						'analytics_endpoint' => '',
					)
				)
			)
		);
	}

	// --- is_section_visible ---------------------------------------------------

	public function test_the_section_is_visible_unless_the_backend_is_too_old(): void {
		$this->assertTrue( GuardLMS_Sdk_Status::is_section_visible( $this->healthy() ) );
		$this->assertTrue( GuardLMS_Sdk_Status::is_section_visible( GuardLMS_Sdk_Config::defaults() ) );
		$this->assertFalse(
			GuardLMS_Sdk_Status::is_section_visible( $this->healthy( array( 'backend_supported' => false ) ) )
		);
	}
}
