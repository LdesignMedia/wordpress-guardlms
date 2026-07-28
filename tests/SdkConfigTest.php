<?php
/**
 * Unit tests for GuardLMS_Sdk_Config.
 *
 * The nested-array shallow-merge trap is the reason this class exists at all, so
 * it gets the most coverage: GuardLMS_Options::all() is array_merge(defaults,
 * stored) and does NOT recurse, so a stored `sdk` array written by an older
 * plugin version would shadow every key added later.
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

/**
 * @covers GuardLMS_Sdk_Config
 */
final class SdkConfigTest extends AbstractGuardLMSTestCase {

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
		Functions\when( 'delete_option' )->alias(
			function ( $name ) {
				unset( $this->store[ $name ] );
				return true;
			}
		);
		// Honour the protocol allowlist, as the real esc_url_raw() does: a scheme
		// outside $protocols yields ''. A returnArg() stub would silently make
		// every https-only assertion below pass against an http URL.
		Functions\when( 'esc_url_raw' )->alias(
			static function ( $url, $protocols = null ) {
				$scheme = strtolower( (string) parse_url( (string) $url, PHP_URL_SCHEME ) );
				if ( is_array( $protocols ) && ! in_array( $scheme, $protocols, true ) ) {
					return '';
				}
				return $url;
			}
		);
		Functions\when( 'sanitize_text_field' )->returnArg( 1 );
	}

	/**
	 * Seed the nested sdk array inside guardlms_settings.
	 *
	 * @param array $sdk Values to store under the `sdk` key.
	 * @return void
	 */
	private function seedSdk( array $sdk ): void {
		$this->store['guardlms_settings'] = array( 'sdk' => $sdk );
	}

	/**
	 * The stored nested sdk array, as written back.
	 *
	 * @return array
	 */
	private function storedSdk(): array {
		return $this->store['guardlms_settings']['sdk'] ?? array();
	}

	// --- defaults -------------------------------------------------------------

	public function test_defaults_are_opt_out(): void {
		$defaults = GuardLMS_Sdk_Config::defaults();

		$this->assertFalse( $defaults['enabled'] );
		$this->assertFalse( $defaults['analytics'] );
	}

	/**
	 * `backend_supported` must default to TRUE. It goes false only after a 404/405
	 * proves the backend predates the feature; defaulting it false would hide the
	 * whole real-time section on every site that has simply not refreshed yet.
	 */
	public function test_backend_supported_defaults_to_true(): void {
		$this->assertTrue( GuardLMS_Sdk_Config::defaults()['backend_supported'] );
	}

	/**
	 * `allowed_domains_match` must default to TRUE, so a site that has never
	 * refreshed is not accused of a domain mismatch it cannot yet know about.
	 */
	public function test_allowed_domains_match_defaults_to_true(): void {
		$this->assertTrue( GuardLMS_Sdk_Config::defaults()['allowed_domains_match'] );
	}

	public function test_all_returns_defaults_on_a_site_that_never_stored_anything(): void {
		$this->assertSame( GuardLMS_Sdk_Config::defaults(), GuardLMS_Sdk_Config::all() );
	}

	// --- AC D7: the shallow-merge trap ---------------------------------------

	/**
	 * AC D7. A stored `sdk` array written by an earlier plugin version does not
	 * contain keys added later. Because GuardLMS_Options::all() merges only the
	 * TOP level, those keys would resolve to null unless GuardLMS_Sdk_Config::all()
	 * re-merges the nested array itself. This is the exact regression that would
	 * ship green: every reader would silently see null instead of the default.
	 */
	public function test_a_stored_sdk_array_missing_a_new_key_still_resolves_it_to_its_default(): void {
		// An 0.2.0-era array: the admin's choices plus a couple of payload values,
		// and nothing else.
		$this->seedSdk(
			array(
				'enabled'         => true,
				'analytics'       => false,
				'sdk_url'         => 'https://cdn.test/guardlms.min.js?v=abc123',
				'errors_endpoint' => 'https://api.test/collect',
			)
		);

		$all = GuardLMS_Sdk_Config::all();

		// The stored values survive.
		$this->assertTrue( $all['enabled'] );
		$this->assertSame( 'https://cdn.test/guardlms.min.js?v=abc123', $all['sdk_url'] );

		// Every key the stored array never had resolves to its DEFAULT, not null.
		foreach ( GuardLMS_Sdk_Config::defaults() as $key => $default ) {
			$this->assertArrayHasKey( $key, $all, "Key '{$key}' vanished from all()." );
			if ( ! in_array( $key, array( 'enabled', 'analytics', 'sdk_url', 'errors_endpoint' ), true ) ) {
				$this->assertSame( $default, $all[ $key ], "Key '{$key}' did not fall back to its default." );
			}
		}

		$this->assertNotNull( $all['max_breadcrumbs'] );
		$this->assertSame( 50, $all['max_breadcrumbs'] );
		$this->assertTrue( $all['backend_supported'] );
	}

	/**
	 * The trap is real: prove GuardLMS_Options::all() genuinely does not recurse,
	 * so this test fails loudly if the merge in all() is ever removed.
	 */
	public function test_guardlms_options_all_does_not_recurse_into_the_nested_array(): void {
		$this->seedSdk( array( 'enabled' => true ) );

		$raw = GuardLMS_Options::all()['sdk'];

		$this->assertSame( array( 'enabled' => true ), $raw );
		$this->assertArrayNotHasKey( 'max_breadcrumbs', $raw );
	}

	public function test_all_tolerates_a_non_array_stored_value(): void {
		$this->store['guardlms_settings'] = array( 'sdk' => 'corrupted' );

		$this->assertSame( GuardLMS_Sdk_Config::defaults(), GuardLMS_Sdk_Config::all() );
	}

	// --- get / update ---------------------------------------------------------

	public function test_get_reads_a_single_key_with_a_fallback(): void {
		$this->seedSdk( array( 'sdk_url' => 'https://cdn.test/x.js' ) );

		$this->assertSame( 'https://cdn.test/x.js', GuardLMS_Sdk_Config::get( 'sdk_url' ) );
		$this->assertSame( 'fallback', GuardLMS_Sdk_Config::get( 'no_such_key', 'fallback' ) );
	}

	public function test_update_merges_and_leaves_other_settings_alone(): void {
		$this->store['guardlms_settings'] = array(
			'verificationtoken' => 'verify-xyz',
			'sdk'               => array( 'enabled' => true ),
		);

		GuardLMS_Sdk_Config::update( array( 'refresh_error' => 'boom' ) );

		$this->assertTrue( $this->storedSdk()['enabled'] );
		$this->assertSame( 'boom', $this->storedSdk()['refresh_error'] );
		// Sibling settings keys untouched.
		$this->assertSame( 'verify-xyz', $this->store['guardlms_settings']['verificationtoken'] );
	}

	// --- store_payload --------------------------------------------------------

	/**
	 * The full backend payload as PR-B's SdkPluginKeyService::payloadFor() emits it.
	 *
	 * @return array
	 */
	private function payload(): array {
		return array(
			'key'                    => 'glms_' . str_repeat( 'a', 56 ),
			'key_status'             => 'issued',
			'key_prefix'             => 'glms_aaa',
			'sdk_url'                => 'https://app.guardlms.test/sdk/guardlms.min.js?v=deadbeef1234',
			'errors_endpoint'        => 'https://app.guardlms.test/api/sdk/errors/collect',
			'analytics_endpoint'     => 'https://app.guardlms.test/api/sdk/analytics/collect',
			'enabled'                => true,
			'subscription_active'    => true,
			'analytics_allowed'      => true,
			'sample_rate'            => 1.0,
			'analytics_sample_rate'  => 0.5,
			'max_breadcrumbs'        => 50,
			'max_errors_per_minute'  => 60,
			'batch_interval_seconds' => 10,
			'ignored_errors'         => array( 'Custom noise' ),
			'allowed_domains'        => array( 'site.test' ),
			'allowed_domains_match'  => true,
		);
	}

	public function test_store_payload_persists_every_backend_owned_value(): void {
		GuardLMS_Sdk_Config::store_payload( $this->payload() );

		$sdk = GuardLMS_Sdk_Config::all();

		$this->assertSame( 'https://app.guardlms.test/sdk/guardlms.min.js?v=deadbeef1234', $sdk['sdk_url'] );
		$this->assertSame( 'https://app.guardlms.test/api/sdk/errors/collect', $sdk['errors_endpoint'] );
		$this->assertSame( 'https://app.guardlms.test/api/sdk/analytics/collect', $sdk['analytics_endpoint'] );
		$this->assertTrue( $sdk['backend_enabled'] );
		$this->assertTrue( $sdk['subscription_active'] );
		$this->assertTrue( $sdk['analytics_allowed'] );
		$this->assertSame( 1.0, $sdk['sample_rate'] );
		$this->assertSame( 0.5, $sdk['analytics_sample_rate'] );
		$this->assertSame( 50, $sdk['max_breadcrumbs'] );
		$this->assertSame( 60, $sdk['max_errors_per_minute'] );
		$this->assertSame( array( 'Custom noise' ), $sdk['ignored_errors'] );
		$this->assertSame( array( 'site.test' ), $sdk['allowed_domains'] );
		$this->assertTrue( $sdk['allowed_domains_match'] );
		$this->assertSame( 'glms_aaa', $sdk['key_prefix'] );
		$this->assertGreaterThan( 0, $sdk['refreshed_at'] );
		$this->assertSame( '', $sdk['refresh_error'] );
	}

	/**
	 * AC D5. The key goes to its own credentials option and NOWHERE else. Asserted on
	 * the raw stored option values, not through an accessor that could be lying.
	 */
	public function test_store_payload_routes_the_key_to_credentials_and_never_to_settings(): void {
		$payload = $this->payload();

		GuardLMS_Sdk_Config::store_payload( $payload );

		$this->assertSame( $payload['key'], $this->store['guardlms_sdk_credentials']['sdkkey'] );

		// The raw settings option must not contain the key anywhere, at any depth.
		$serialized = wp_json_encode_fallback( $this->store['guardlms_settings'] );
		$this->assertStringNotContainsString( $payload['key'], $serialized );
	}

	/**
	 * The `key_status: 'exists'` branch - the normal case on every refresh after
	 * the first - carries no key. Storing null over the live key would revoke the
	 * site's monitoring on its own daily cron run.
	 */
	public function test_store_payload_without_a_key_leaves_the_stored_key_intact(): void {
		$this->store['guardlms_sdk_credentials'] = array( 'sdkkey' => 'glms_existing' );

		$payload = $this->payload();
		unset( $payload['key'] );
		GuardLMS_Sdk_Config::store_payload( $payload );

		$this->assertSame( 'glms_existing', $this->store['guardlms_sdk_credentials']['sdkkey'] );
	}

	public function test_store_payload_with_a_null_key_leaves_the_stored_key_intact(): void {
		$this->store['guardlms_sdk_credentials'] = array( 'sdkkey' => 'glms_existing' );

		$payload        = $this->payload();
		$payload['key'] = null;
		GuardLMS_Sdk_Config::store_payload( $payload );

		$this->assertSame( 'glms_existing', $this->store['guardlms_sdk_credentials']['sdkkey'] );
	}

	/**
	 * `batch_interval_seconds` is drifted three ways between the migration
	 * default, the controller seed and the SDK default, and is enforced nowhere.
	 * Storing it would be the first step towards emitting it, which could only
	 * regress flush latency from 2s to 10-20s.
	 */
	public function test_store_payload_never_stores_batch_interval(): void {
		GuardLMS_Sdk_Config::store_payload( $this->payload() );

		$this->assertArrayNotHasKey( 'batch_interval_seconds', GuardLMS_Sdk_Config::all() );
		$this->assertStringNotContainsString(
			'batch_interval',
			wp_json_encode_fallback( $this->store['guardlms_settings'] )
		);
	}

	public function test_store_payload_clears_a_previous_refresh_error_and_restores_support(): void {
		$this->seedSdk(
			array(
				'refresh_error'     => 'Connection timed out',
				'backend_supported' => false,
			)
		);

		GuardLMS_Sdk_Config::store_payload( $this->payload() );

		$this->assertSame( '', GuardLMS_Sdk_Config::get( 'refresh_error' ) );
		$this->assertTrue( GuardLMS_Sdk_Config::get( 'backend_supported' ) );
	}

	public function test_store_payload_omits_keys_the_backend_did_not_send(): void {
		// An older/partial backend that answers with only the key and a URL.
		GuardLMS_Sdk_Config::store_payload(
			array(
				'key'     => 'glms_partial',
				'sdk_url' => 'https://cdn.test/x.js',
			)
		);

		$sdk = GuardLMS_Sdk_Config::all();

		$this->assertSame( 'https://cdn.test/x.js', $sdk['sdk_url'] );
		// Untouched keys keep their defaults rather than becoming null.
		$this->assertSame( 50, $sdk['max_breadcrumbs'] );
		$this->assertFalse( $sdk['backend_enabled'] );
	}

	public function test_store_payload_clamps_sample_rates_and_rejects_junk(): void {
		$payload                          = $this->payload();
		$payload['sample_rate']           = 4.2;
		$payload['analytics_sample_rate'] = -3;
		$payload['max_breadcrumbs']       = -10;
		GuardLMS_Sdk_Config::store_payload( $payload );

		$sdk = GuardLMS_Sdk_Config::all();
		$this->assertSame( 1.0, $sdk['sample_rate'] );
		$this->assertSame( 0.0, $sdk['analytics_sample_rate'] );
		$this->assertSame( 0, $sdk['max_breadcrumbs'] );

		// Non-numeric values leave the previous value alone rather than casting
		// to zero, which would silently disable error reporting.
		$payload                = $this->payload();
		$payload['sample_rate'] = 'not-a-number';
		GuardLMS_Sdk_Config::store_payload( $payload );
		$this->assertSame( 1.0, GuardLMS_Sdk_Config::get( 'sample_rate' ) );
	}

	public function test_store_payload_drops_non_scalar_and_empty_list_entries(): void {
		$payload                   = $this->payload();
		$payload['ignored_errors'] = array( 'Real entry', '', array( 'nested' ), '  ', 'Real entry' );
		GuardLMS_Sdk_Config::store_payload( $payload );

		$this->assertSame( array( 'Real entry' ), GuardLMS_Sdk_Config::get( 'ignored_errors' ) );
	}

	public function test_store_payload_ignores_a_non_array_list(): void {
		$payload                    = $this->payload();
		$payload['allowed_domains'] = 'site.test';
		GuardLMS_Sdk_Config::store_payload( $payload );

		$this->assertSame( array(), GuardLMS_Sdk_Config::get( 'allowed_domains' ) );
	}

	// --- URL validation (F7) --------------------------------------------------

	/**
	 * An http:// address is worse than useless: a browser on an HTTPS site blocks
	 * it as mixed content, so the bundle never loads and no error surfaces
	 * anywhere the plugin can see. Storing it would leave the settings page
	 * claiming monitoring is active while nothing is ever collected.
	 */
	public function test_store_payload_rejects_a_non_https_sdk_url(): void {
		$payload            = $this->payload();
		$payload['sdk_url'] = 'http://app.guardlms.test/sdk/guardlms.min.js?v=abc';

		GuardLMS_Sdk_Config::store_payload( $payload );

		$this->assertSame( '', GuardLMS_Sdk_Config::get( 'sdk_url' ) );
	}

	/**
	 * And it says so, rather than leaving an empty value with no explanation.
	 */
	public function test_a_rejected_url_records_an_explanatory_error(): void {
		$payload                    = $this->payload();
		$payload['errors_endpoint'] = 'http://app.guardlms.test/api/sdk/errors/collect';

		GuardLMS_Sdk_Config::store_payload( $payload );

		$this->assertStringContainsString( 'HTTPS', GuardLMS_Sdk_Config::get( 'refresh_error' ) );
	}

	public function test_an_all_https_payload_records_no_error(): void {
		GuardLMS_Sdk_Config::store_payload( $this->payload() );

		$this->assertSame( '', GuardLMS_Sdk_Config::get( 'refresh_error' ) );
	}

	/**
	 * An explicit null leaves the value empty while refreshed_at is still
	 * stamped. That combination used to read as a healthy site, so the status
	 * chain now has to catch it - here we pin the storage half of that.
	 */
	public function test_an_explicitly_null_url_leaves_the_value_empty(): void {
		$payload            = $this->payload();
		$payload['sdk_url'] = null;

		GuardLMS_Sdk_Config::store_payload( $payload );

		$this->assertSame( '', GuardLMS_Sdk_Config::get( 'sdk_url' ) );
		$this->assertGreaterThan( 0, GuardLMS_Sdk_Config::get( 'refreshed_at' ) );
	}

	// --- record_error / mark_unsupported / clear ------------------------------

	/**
	 * A failure must not move `refreshed_at`. The admin needs to see when the last
	 * SUCCESSFUL refresh happened, which is exactly what a failure does not change.
	 */
	public function test_record_error_preserves_the_last_successful_refresh_time(): void {
		GuardLMS_Sdk_Config::store_payload( $this->payload() );
		$refreshed = GuardLMS_Sdk_Config::get( 'refreshed_at' );

		GuardLMS_Sdk_Config::record_error( 'Connection timed out' );

		$this->assertSame( 'Connection timed out', GuardLMS_Sdk_Config::get( 'refresh_error' ) );
		$this->assertSame( $refreshed, GuardLMS_Sdk_Config::get( 'refreshed_at' ) );
	}

	public function test_mark_unsupported_sets_only_that_flag(): void {
		GuardLMS_Sdk_Config::store_payload( $this->payload() );

		GuardLMS_Sdk_Config::mark_unsupported();

		$this->assertFalse( GuardLMS_Sdk_Config::get( 'backend_supported' ) );
		// No error is recorded: §5.3 row 2 shows the admin nothing at all.
		$this->assertSame( '', GuardLMS_Sdk_Config::get( 'refresh_error' ) );
	}

	public function test_clear_resets_to_defaults_and_drops_the_sdk_key(): void {
		GuardLMS_Sdk_Config::store_payload( $this->payload() );
		$this->store['guardlms_credentials']['apikey'] = 'push-key';

		GuardLMS_Sdk_Config::clear();

		$this->assertSame( GuardLMS_Sdk_Config::defaults(), GuardLMS_Sdk_Config::all() );
		$this->assertSame( '', GuardLMS_Credentials::get_sdk_key() );
		// The push key survives: clear() owns the SDK key only.
		$this->assertSame( 'push-key', $this->store['guardlms_credentials']['apikey'] );
	}
}

/**
 * json_encode a structure for substring assertions without depending on the
 * wp_json_encode stub being present in this test's Brain Monkey scope.
 *
 * @param mixed $data Structure to encode.
 * @return string
 */
function wp_json_encode_fallback( $data ) {
	return (string) json_encode( $data );
}
