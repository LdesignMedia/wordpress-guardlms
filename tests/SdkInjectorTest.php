<?php
/**
 * Unit tests for GuardLMS_Sdk_Injector.
 *
 * Covers PR-D acceptance criteria D1-D4 and the cross-cutting X1 (setUser is
 * never called). The enqueue/inline-script calls are captured through Brain
 * Monkey so the emitted configuration can be asserted as a decoded structure
 * rather than as a string match.
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
require_once GUARDLMS_PLUGIN_DIR . 'includes/class-guardlms-sdk-config.php';
require_once GUARDLMS_PLUGIN_DIR . 'includes/class-guardlms-sdk-status.php';
require_once GUARDLMS_PLUGIN_DIR . 'includes/class-guardlms-sdk-injector.php';

/**
 * @covers GuardLMS_Sdk_Injector
 */
final class SdkInjectorTest extends AbstractGuardLMSTestCase {

	/**
	 * In-memory option store keyed by option name.
	 *
	 * @var array<string,mixed>
	 */
	private $store = array();

	/**
	 * get_option() call count per option name.
	 *
	 * @var array<string,int>
	 */
	private $reads = array();

	/**
	 * wp_enqueue_script() calls, as ordered argument arrays.
	 *
	 * @var array[]
	 */
	private $enqueued = array();

	/**
	 * wp_register_script() calls, as ordered argument arrays.
	 *
	 * @var array[]
	 */
	private $registered = array();

	/**
	 * wp_add_inline_script() calls, as ordered argument arrays.
	 *
	 * @var array[]
	 */
	private $inline = array();

	protected function setUp(): void {
		parent::setUp();
		$this->store      = array();
		$this->reads      = array();
		$this->enqueued   = array();
		$this->registered = array();
		$this->inline     = array();

		Functions\when( 'get_option' )->alias(
			function ( $name, $default = false ) {
				$this->reads[ $name ] = ( $this->reads[ $name ] ?? 0 ) + 1;
				return array_key_exists( $name, $this->store ) ? $this->store[ $name ] : $default;
			}
		);
		Functions\when( 'update_option' )->alias(
			function ( $name, $value ) {
				$this->store[ $name ] = $value;
				return true;
			}
		);
		Functions\when( 'wp_enqueue_script' )->alias(
			function ( ...$args ) {
				$this->enqueued[] = $args;
				return null;
			}
		);
		Functions\when( 'wp_register_script' )->alias(
			function ( ...$args ) {
				$this->registered[] = $args;
				return true;
			}
		);
		Functions\when( 'wp_add_inline_script' )->alias(
			function ( ...$args ) {
				$this->inline[] = $args;
				return true;
			}
		);
		Functions\when( 'wp_json_encode' )->alias(
			static function ( $data, $flags = 0 ) {
				return json_encode( $data, (int) $flags );
			}
		);
		Functions\when( 'esc_url_raw' )->returnArg( 1 );
		Functions\when( 'get_bloginfo' )->justReturn( '6.8.1' );

		// Request-context guards default to "an ordinary front-end page view".
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'is_feed' )->justReturn( false );
		Functions\when( 'wp_doing_cron' )->justReturn( false );
		Functions\when( 'is_user_logged_in' )->justReturn( false );
		Functions\when( 'current_user_can' )->justReturn( false );

		unset( $_GET[ GuardLMS_Sdk_Injector::SELFTEST_FLAG ] );
	}

	protected function tearDown(): void {
		unset( $_GET[ GuardLMS_Sdk_Injector::SELFTEST_FLAG ] );
		parent::tearDown();
	}

	/**
	 * Seed a connected, fully refreshed, opted-in site.
	 *
	 * @param array  $overrides Overrides for the nested sdk array.
	 * @param string $sdk_key   SDK key to store ('' stores none).
	 * @return void
	 */
	private function seedHealthy( array $overrides = array(), string $sdk_key = 'glms_testkey' ): void {
		$this->store['guardlms_settings'] = array(
			'enabled' => true,
			'sdk'     => array_merge(
				array(
					'enabled'               => true,
					'analytics'             => false,
					'sdk_url'               => 'https://app.guardlms.test/sdk/guardlms.min.js?v=deadbeef1234',
					'errors_endpoint'       => 'https://app.guardlms.test/api/sdk/errors/collect',
					'analytics_endpoint'    => 'https://app.guardlms.test/api/sdk/analytics/collect',
					'backend_enabled'       => true,
					'subscription_active'   => true,
					'analytics_allowed'     => true,
					'sample_rate'           => 1.0,
					'analytics_sample_rate' => 0.5,
					'max_breadcrumbs'       => 50,
					'max_errors_per_minute' => 60,
					'refreshed_at'          => 1750000000,
				),
				$overrides
			),
		);

		if ( '' !== $sdk_key ) {
			$this->store['guardlms_credentials'] = array(
				'apikey' => 'push-key',
				'sdkkey' => $sdk_key,
			);
		}
	}

	/**
	 * The handles passed to wp_enqueue_script(), in call order.
	 *
	 * @return string[]
	 */
	private function enqueuedHandles(): array {
		return array_map(
			static function ( array $args ) {
				return (string) $args[0];
			},
			$this->enqueued
		);
	}

	/**
	 * The decoded GuardLMS.init() payload from the captured inline script.
	 *
	 * @return array
	 */
	private function emittedConfig(): array {
		$this->assertNotEmpty( $this->inline, 'No inline script was added.' );
		$source = $this->inline[0][1];

		$this->assertStringStartsWith( 'GuardLMS.init(', $source );
		$this->assertStringEndsWith( ');', $source );

		$json    = substr( $source, strlen( 'GuardLMS.init(' ), -2 );
		$decoded = json_decode( $json, true );

		$this->assertIsArray( $decoded, 'The emitted init payload is not valid JSON: ' . $json );

		return $decoded;
	}

	// --- AC D1: suppression, and the non-autoloaded read ----------------------

	/**
	 * AC D1. With the opt-in off, nothing is enqueued AND the non-autoloaded
	 * credentials option is never read. The whole storage split is justified by
	 * "a site that never opted in pays nothing", so this is a call-count
	 * assertion, not a behaviour one: a reordered guard would still emit no
	 * script while quietly adding a database read to every front-end request.
	 */
	public function test_disabled_site_enqueues_nothing_and_never_reads_the_credentials_option(): void {
		$this->seedHealthy( array( 'enabled' => false ) );

		GuardLMS_Sdk_Injector::enqueue();

		$this->assertSame( array(), $this->enqueued );
		$this->assertSame( array(), $this->inline );
		$this->assertArrayNotHasKey( 'guardlms_credentials', $this->reads );
	}

	public function test_plugin_wide_disable_short_circuits_before_the_credentials_option(): void {
		$this->seedHealthy();
		$this->store['guardlms_settings']['enabled'] = false;

		GuardLMS_Sdk_Injector::enqueue();

		$this->assertSame( array(), $this->enqueued );
		$this->assertArrayNotHasKey( 'guardlms_credentials', $this->reads );
	}

	/**
	 * The read DOES happen on an opted-in site - proving the assertion above is
	 * about ordering and not just about the option never being read at all.
	 */
	public function test_an_opted_in_site_does_read_the_credentials_option(): void {
		$this->seedHealthy();

		GuardLMS_Sdk_Injector::enqueue();

		$this->assertArrayHasKey( 'guardlms_credentials', $this->reads );
		$this->assertNotEmpty( $this->enqueued );
	}

	/**
	 * @dataProvider suppressionProvider
	 * @param array  $overrides Overrides for the nested sdk array.
	 * @param string $sdk_key   SDK key to store.
	 * @return void
	 */
	public function test_each_suppression_condition_emits_nothing( array $overrides, string $sdk_key ): void {
		$this->seedHealthy( $overrides, $sdk_key );

		GuardLMS_Sdk_Injector::enqueue();

		$this->assertSame( array(), $this->enqueued );
		$this->assertSame( array(), $this->inline );
	}

	/**
	 * @return array<string,array{0:array,1:string}>
	 */
	public function suppressionProvider(): array {
		return array(
			'admin opted out'      => array( array( 'enabled' => false ), 'glms_testkey' ),
			'backend too old'      => array( array( 'backend_supported' => false ), 'glms_testkey' ),
			'dashboard switch off' => array( array( 'backend_enabled' => false ), 'glms_testkey' ),
			'subscription expired' => array( array( 'subscription_active' => false ), 'glms_testkey' ),
			'empty sdk url'        => array( array( 'sdk_url' => '' ), 'glms_testkey' ),
			'empty errors url'     => array( array( 'errors_endpoint' => '' ), 'glms_testkey' ),
			'no key stored'        => array( array(), '' ),
		);
	}

	public function test_no_injection_in_wp_admin(): void {
		$this->seedHealthy();
		Functions\when( 'is_admin' )->justReturn( true );

		GuardLMS_Sdk_Injector::enqueue();

		$this->assertSame( array(), $this->enqueued );
	}

	public function test_no_injection_in_a_feed(): void {
		$this->seedHealthy();
		Functions\when( 'is_feed' )->justReturn( true );

		GuardLMS_Sdk_Injector::enqueue();

		$this->assertSame( array(), $this->enqueued );
	}

	public function test_no_injection_during_cron(): void {
		$this->seedHealthy();
		Functions\when( 'wp_doing_cron' )->justReturn( true );

		GuardLMS_Sdk_Injector::enqueue();

		$this->assertSame( array(), $this->enqueued );
	}

	// --- AC D2/D3: the emitted script and configuration -----------------------

	/**
	 * AC D3. The enqueued URL carries the backend's ?v= cache-buster verbatim,
	 * and $ver is null so WordPress does not append a second, weaker one.
	 */
	public function test_the_enqueued_url_carries_the_cache_buster_and_no_wp_version(): void {
		$this->seedHealthy();

		GuardLMS_Sdk_Injector::enqueue();

		$this->assertCount( 1, $this->enqueued );
		list( $handle, $src, $deps, $ver, $in_footer ) = $this->enqueued[0];

		$this->assertSame( 'guardlms-sdk', $handle );
		$this->assertSame( 'https://app.guardlms.test/sdk/guardlms.min.js?v=deadbeef1234', $src );
		$this->assertStringContainsString( '?v=deadbeef1234', $src );
		$this->assertSame( array(), $deps );
		$this->assertNull( $ver );
		// Head, not footer: the SDK's value is installing window.onerror before
		// anything else runs.
		$this->assertFalse( $in_footer );
	}

	public function test_the_inline_script_is_attached_after_the_sdk_handle(): void {
		$this->seedHealthy();

		GuardLMS_Sdk_Injector::enqueue();

		$this->assertCount( 1, $this->inline );
		$this->assertSame( 'guardlms-sdk', $this->inline[0][0] );
		$this->assertSame( 'after', $this->inline[0][2] );
	}

	/**
	 * AC D2. The exact configuration §4.1 mandates.
	 */
	public function test_the_emitted_configuration_matches_the_mandated_shape(): void {
		$this->seedHealthy();

		GuardLMS_Sdk_Injector::enqueue();
		$config = $this->emittedConfig();

		$this->assertSame( 'glms_testkey', $config['apiKey'] );
		$this->assertSame( 'https://app.guardlms.test/api/sdk/errors/collect', $config['endpoint'] );
		$this->assertSame( 'wordpress-6.8.1/guardlms-' . GUARDLMS_VERSION, $config['appVersion'] );
		$this->assertMatchesRegularExpression( '/^wordpress-/', $config['appVersion'] );
		$this->assertSame( 'production', $config['releaseStage'] );
		// json_decode turns the JSON number 1.0 back into int(1), so compare numerically.
		$this->assertEqualsWithDelta( 1.0, $config['sampleRate'], 0.0001 );
		$this->assertSame( 50, $config['maxBreadcrumbs'] );
		$this->assertSame( 60, $config['maxErrorsPerMinute'] );
		$this->assertFalse( $config['collectUserIp'] );
		$this->assertFalse( $config['interactionBreadcrumbsEnabled'] );
	}

	/**
	 * AC D2 / Scenario 2. The single most important privacy knob: no click or
	 * form breadcrumbs. On an LMS page those selectors carry question ids and
	 * answer-option ids, which combined with pageUrl reconstruct what a learner
	 * answered.
	 */
	public function test_click_and_form_breadcrumbs_are_never_enabled(): void {
		$this->seedHealthy();

		GuardLMS_Sdk_Injector::enqueue();
		$config = $this->emittedConfig();

		$this->assertSame(
			array( 'navigation', 'network', 'console', 'user' ),
			$config['enabledBreadcrumbTypes']
		);
		$this->assertNotContains( 'click', $config['enabledBreadcrumbTypes'] );
		$this->assertNotContains( 'form', $config['enabledBreadcrumbTypes'] );
		$this->assertFalse( $config['interactionBreadcrumbsEnabled'] );
	}

	/**
	 * AC D2. `sesskey` matches NO SDK default (it contains neither 'apikey' nor
	 * 'token' nor 'secret'), and `nonce` covers _wpnonce/wpnonce/wp_rest_nonce in
	 * one entry. Matching is a case-insensitive substring test.
	 */
	public function test_redacted_keys_include_sesskey_and_nonce(): void {
		$this->seedHealthy();

		GuardLMS_Sdk_Injector::enqueue();
		$config = $this->emittedConfig();

		$this->assertContains( 'sesskey', $config['redactedKeys'] );
		$this->assertContains( 'nonce', $config['redactedKeys'] );
		$this->assertContains( 'password', $config['redactedKeys'] );
		$this->assertContains( 'authorization', $config['redactedKeys'] );

		// A bare 'key' would redact half the payload under substring matching.
		$this->assertNotContains( 'key', $config['redactedKeys'] );
	}

	/**
	 * AC D2. `batchInterval` must be absent: its stored value is drifted three
	 * ways and enforced nowhere, so emitting it could only move flush latency
	 * from the SDK's 2s default to 10-20s.
	 */
	public function test_batch_interval_is_never_emitted(): void {
		$this->seedHealthy();

		GuardLMS_Sdk_Injector::enqueue();

		$this->assertArrayNotHasKey( 'batchInterval', $this->emittedConfig() );
		$this->assertStringNotContainsString( 'batchInterval', $this->inline[0][1] );
	}

	/**
	 * AC X1. No user object is emitted and setUser is never called - not in the
	 * payload and not anywhere in the injector's source.
	 */
	public function test_no_user_is_ever_identified(): void {
		$this->seedHealthy();

		GuardLMS_Sdk_Injector::enqueue();
		$config = $this->emittedConfig();

		$this->assertArrayNotHasKey( 'user', $config );
		$this->assertStringNotContainsString( 'setUser', $this->inline[0][1] );

		// php_strip_whitespace() drops comments, so the docblock explaining WHY
		// setUser is never called cannot itself satisfy the scan.
		$source = php_strip_whitespace( GUARDLMS_PLUGIN_DIR . 'includes/class-guardlms-sdk-injector.php' );
		$this->assertStringNotContainsString( 'setUser', $source );
		$this->assertDoesNotMatchRegularExpression( '/->\s*setUser\s*\(/', $source );
		$this->assertDoesNotMatchRegularExpression( '/\.\s*setUser\s*\(/', $source );
		$this->assertDoesNotMatchRegularExpression( "/\\[\\s*['\"]setUser['\"]\\s*\\]/", $source );
	}

	public function test_ignore_errors_carries_the_baseline_plus_the_dashboard_entries(): void {
		$this->seedHealthy( array( 'ignored_errors' => array( 'Custom site noise', 'Script error.' ) ) );

		GuardLMS_Sdk_Injector::enqueue();
		$ignore = $this->emittedConfig()['ignoreErrors'];

		foreach ( GuardLMS_Sdk_Injector::IGNORE_ERRORS as $baseline ) {
			$this->assertContains( $baseline, $ignore );
		}
		$this->assertContains( 'Custom site noise', $ignore );
		// The dashboard repeating a baseline entry must not duplicate it.
		$this->assertSame( array_values( array_unique( $ignore ) ), $ignore );
	}

	// --- the analytics block --------------------------------------------------

	public function test_the_analytics_block_is_absent_when_the_admin_did_not_opt_in(): void {
		$this->seedHealthy( array( 'analytics' => false ) );

		GuardLMS_Sdk_Injector::enqueue();

		$this->assertArrayNotHasKey( 'analytics', $this->emittedConfig() );
	}

	/**
	 * The plan gate is enforced at emit time, not only in the admin UI. A
	 * disabled checkbox is a display state; the entitlement is the control.
	 */
	public function test_the_analytics_block_is_absent_when_the_plan_disallows_it(): void {
		$this->seedHealthy(
			array(
				'analytics'         => true,
				'analytics_allowed' => false,
			)
		);

		GuardLMS_Sdk_Injector::enqueue();
		$config = $this->emittedConfig();

		$this->assertArrayNotHasKey( 'analytics', $config );
		// Errors still flow.
		$this->assertSame( 'https://app.guardlms.test/api/sdk/errors/collect', $config['endpoint'] );
	}

	public function test_the_analytics_block_is_emitted_when_allowed_and_opted_in(): void {
		$this->seedHealthy(
			array(
				'analytics'         => true,
				'analytics_allowed' => true,
			)
		);

		GuardLMS_Sdk_Injector::enqueue();

		$this->assertSame(
			array(
				'enabled'          => true,
				'endpoint'         => 'https://app.guardlms.test/api/sdk/analytics/collect',
				'sampleRate'       => 0.5,
				'trackScrollDepth' => true,
			),
			$this->emittedConfig()['analytics']
		);
	}

	// --- AC D4: the </script> breakout ---------------------------------------

	/**
	 * AC D4. A poisoned value containing </script> must not be able to terminate
	 * the inline block. JSON_HEX_TAG hex-escapes < and >, which makes the
	 * breakout structurally impossible rather than merely unlikely.
	 */
	public function test_a_poisoned_endpoint_cannot_break_out_of_the_script_block(): void {
		$this->seedHealthy(
			array(
				'errors_endpoint' => 'https://evil.test/collect?x=</script><script>alert(1)</script>',
			)
		);

		GuardLMS_Sdk_Injector::enqueue();
		$source = $this->inline[0][1];

		$this->assertStringNotContainsString( '</script>', $source );
		$this->assertStringNotContainsString( '<script', $source );
		// Escaped, not merely deleted: the angle brackets are present in the
		// hex-escaped form JSON_HEX_TAG produces.
		$this->assertStringContainsString( trim( json_encode( '<', JSON_HEX_TAG ), '"' ), $source );

		// The value survives intact once the browser parses the JSON, so the
		// escaping is not silently corrupting real endpoints.
		$this->assertSame(
			'https://evil.test/collect?x=</script><script>alert(1)</script>',
			$this->emittedConfig()['endpoint']
		);
	}

	public function test_a_poisoned_api_key_cannot_break_out_either(): void {
		$this->seedHealthy( array(), '</script><script>alert(1)</script>' );

		GuardLMS_Sdk_Injector::enqueue();

		$this->assertStringNotContainsString( '</script>', $this->inline[0][1] );
	}

	public function test_quotes_and_ampersands_are_escaped_too(): void {
		$this->seedHealthy( array( 'errors_endpoint' => 'https://api.test/c?a=1&b=\'x\'&c="y"' ) );

		GuardLMS_Sdk_Injector::enqueue();
		$source = $this->inline[0][1];

		$this->assertStringNotContainsString( '&', $source );
		$this->assertStringNotContainsString( "'", $source );
		$this->assertSame( 'https://api.test/c?a=1&b=\'x\'&c="y"', $this->emittedConfig()['endpoint'] );
	}

	// --- script_loader_tag ----------------------------------------------------

	public function test_the_tag_filter_leaves_other_handles_alone(): void {
		$tag = '<script defer src="https://other.test/x.js"></script>';

		$this->assertSame( $tag, GuardLMS_Sdk_Injector::filter_script_tag( $tag, 'some-other-handle' ) );
	}

	public function test_the_tag_filter_strips_defer_and_async_from_our_handle(): void {
		$filtered = GuardLMS_Sdk_Injector::filter_script_tag(
			'<script defer async src="https://app.guardlms.test/sdk/guardlms.min.js"></script>',
			'guardlms-sdk'
		);

		// Match the ATTRIBUTE, not the substring: the opt-out markers the filter
		// adds legitimately contain "defer" inside data-no-defer.
		$this->assertDoesNotMatchRegularExpression( '/\s(?:defer|async)[\s=>]/', $filtered );
		$this->assertStringContainsString( 'src="https://app.guardlms.test/sdk/guardlms.min.js"', $filtered );
	}

	public function test_the_tag_filter_strips_valued_defer_attributes(): void {
		$filtered = GuardLMS_Sdk_Injector::filter_script_tag(
			'<script defer="defer" src="https://app.guardlms.test/sdk/guardlms.min.js"></script>',
			'guardlms-sdk'
		);

		$this->assertDoesNotMatchRegularExpression( '/\sdefer[\s=>]/', $filtered );
	}

	public function test_the_tag_filter_adds_optimizer_opt_out_attributes_once(): void {
		$once  = GuardLMS_Sdk_Injector::filter_script_tag(
			'<script src="https://app.guardlms.test/sdk/guardlms.min.js"></script>',
			'guardlms-sdk'
		);
		$twice = GuardLMS_Sdk_Injector::filter_script_tag( $once, 'guardlms-sdk' );

		$this->assertStringContainsString( 'data-no-optimize="1"', $once );
		$this->assertStringContainsString( 'data-cfasync="false"', $once );
		$this->assertSame( 1, substr_count( $twice, 'data-no-optimize' ) );
		$this->assertSame( $once, $twice );
	}

	// --- AC D10: the self-test probe -----------------------------------------

	public function test_the_probe_is_absent_without_the_query_flag(): void {
		$this->seedHealthy();
		Functions\when( 'is_user_logged_in' )->justReturn( true );
		Functions\when( 'current_user_can' )->justReturn( true );

		GuardLMS_Sdk_Injector::enqueue();

		$this->assertSame( array(), $this->registered );
	}

	public function test_the_probe_is_absent_for_an_anonymous_visitor(): void {
		$this->seedHealthy();
		$_GET[ GuardLMS_Sdk_Injector::SELFTEST_FLAG ] = '1';

		GuardLMS_Sdk_Injector::enqueue();

		$this->assertSame( array(), $this->registered );
	}

	public function test_the_probe_is_absent_for_a_logged_in_user_without_manage_options(): void {
		$this->seedHealthy();
		$_GET[ GuardLMS_Sdk_Injector::SELFTEST_FLAG ] = '1';
		Functions\when( 'is_user_logged_in' )->justReturn( true );
		Functions\when( 'current_user_can' )->justReturn( false );

		GuardLMS_Sdk_Injector::enqueue();

		$this->assertSame( array(), $this->registered );
	}

	/**
	 * AC D10. Registered on a handle DISTINCT from the SDK's and printed in the
	 * footer, so an optimizer that defers the SDK does not also defer the probe
	 * whose entire job is to notice that deferral.
	 */
	public function test_the_probe_is_registered_on_its_own_footer_handle_for_an_admin(): void {
		$this->seedHealthy();
		$this->store['guardlms_settings']['websiteid'] = 42;
		$this->store['guardlms_settings']['baseurl']   = 'https://app.guardlms.test';
		$_GET[ GuardLMS_Sdk_Injector::SELFTEST_FLAG ]  = '1';
		Functions\when( 'is_user_logged_in' )->justReturn( true );
		Functions\when( 'current_user_can' )->justReturn( true );

		GuardLMS_Sdk_Injector::enqueue();

		$this->assertCount( 1, $this->registered );
		list( $handle, $src, $deps, $ver, $in_footer ) = $this->registered[0];

		$this->assertSame( 'guardlms-sdk-selftest', $handle );
		$this->assertNotSame( 'guardlms-sdk', $handle );
		$this->assertFalse( $src );
		$this->assertTrue( $in_footer );

		// The probe rides its own handle, never the SDK's.
		$probe = null;
		foreach ( $this->inline as $call ) {
			if ( 'guardlms-sdk-selftest' === $call[0] ) {
				$probe = $call[1];
			}
		}
		$this->assertNotNull( $probe );
		$this->assertStringContainsString( 'typeof window.GuardLMS==="undefined"', $probe );
		$this->assertStringContainsString( 'GuardLMS self-test', $probe );
		// JSON escapes forward slashes, so assert on the encoded form.
		$this->assertStringContainsString( json_encode( 'https://app.guardlms.test/websites/42' ), $probe );
	}

	/**
	 * AC D10. The whole self-test flow writes nothing to the options table.
	 */
	public function test_the_probe_writes_nothing_to_the_options_table(): void {
		$this->seedHealthy();
		$_GET[ GuardLMS_Sdk_Injector::SELFTEST_FLAG ] = '1';
		Functions\when( 'is_user_logged_in' )->justReturn( true );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\expect( 'update_option' )->never();
		Functions\expect( 'add_option' )->never();
		Functions\expect( 'set_transient' )->never();

		GuardLMS_Sdk_Injector::enqueue();

		$this->assertCount( 1, $this->registered );
	}

	/**
	 * The probe must still run when the SDK did NOT load - detecting exactly that
	 * is its purpose, so gating it behind a successful injection would make it
	 * useless in the only case it exists for.
	 */
	public function test_the_probe_runs_even_when_the_sdk_was_suppressed(): void {
		$this->seedHealthy( array( 'enabled' => false ) );
		$_GET[ GuardLMS_Sdk_Injector::SELFTEST_FLAG ] = '1';
		Functions\when( 'is_user_logged_in' )->justReturn( true );
		Functions\when( 'current_user_can' )->justReturn( true );

		GuardLMS_Sdk_Injector::enqueue();

		// The probe's own handle is enqueued; the SDK's is not.
		$this->assertNotContains( 'guardlms-sdk', $this->enqueuedHandles() );
		$this->assertContains( 'guardlms-sdk-selftest', $this->enqueuedHandles() );
		$this->assertCount( 1, $this->registered );
	}

	public function test_the_probe_omits_the_dashboard_link_when_the_site_id_is_unknown(): void {
		$this->seedHealthy();
		$_GET[ GuardLMS_Sdk_Injector::SELFTEST_FLAG ] = '1';
		Functions\when( 'is_user_logged_in' )->justReturn( true );
		Functions\when( 'current_user_can' )->justReturn( true );

		GuardLMS_Sdk_Injector::enqueue();

		$probe = null;
		foreach ( $this->inline as $call ) {
			if ( 'guardlms-sdk-selftest' === $call[0] ) {
				$probe = $call[1];
			}
		}
		$this->assertStringContainsString( '"url":""', $probe );
	}

	// --- build_config edge cases ---------------------------------------------

	public function test_app_version_falls_back_when_core_reports_no_version(): void {
		$this->seedHealthy();
		Functions\when( 'get_bloginfo' )->justReturn( '' );

		GuardLMS_Sdk_Injector::enqueue();

		$this->assertSame( 'wordpress-unknown/guardlms-' . GUARDLMS_VERSION, $this->emittedConfig()['appVersion'] );
	}

	/**
	 * The key set the plugin emits is fixed by §4.1. A silent addition (or loss)
	 * is exactly the drift the SDK README's structural test is meant to catch on
	 * the other side, so pin it here too.
	 */
	public function test_the_emitted_option_key_set_is_exactly_the_mandated_one(): void {
		$this->seedHealthy();

		GuardLMS_Sdk_Injector::enqueue();

		$this->assertSame(
			array(
				'apiKey',
				'endpoint',
				'appVersion',
				'releaseStage',
				'sampleRate',
				'maxBreadcrumbs',
				'maxErrorsPerMinute',
				'collectUserIp',
				'interactionBreadcrumbsEnabled',
				'enabledBreadcrumbTypes',
				'redactedKeys',
				'ignoreErrors',
			),
			array_keys( $this->emittedConfig() )
		);
	}
}
