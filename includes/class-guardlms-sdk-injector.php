<?php
/**
 * Front-end injector for the GuardLMS JavaScript SDK.
 *
 * @package    GuardLMS
 * @copyright  2026 Luuk Verhoeven, ldesignmedia.nl <info@ldesignmedia.nl>
 * @author     Hamza Tamyachte
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * GuardLMS - WordPress site security reporting for GuardLMS.
 * Copyright (C) 2026 LdesignMedia.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Enqueues the GuardLMS SDK and its GuardLMS.init() call on front-end pages.
 *
 * SCOPE. Front end only, matching the product requirement. wp-admin is excluded
 * by is_admin(); wp-login.php never fires wp_enqueue_scripts at all. (The Moodle
 * plugin's scope differs deliberately - it injects on /admin/* too, because
 * Moodle has no front-end/back-end split.)
 *
 * ORDERING, STATED ACCURATELY. This is hooked to wp_enqueue_scripts at priority
 * 1 with $in_footer = false and no defer, because the SDK's value is installing
 * window.onerror before anything else runs. But wp_enqueue_scripts callbacks
 * only decide the order among ENQUEUED scripts: the actual output happens later,
 * at wp_print_head_scripts on wp_head priority 9. Any plugin printing a raw
 * <script> on wp_head at priority 0-8 (consent managers, tag managers) still
 * runs first. The honest claim is "earliest among enqueued scripts", not
 * "first script on the page".
 */
class GuardLMS_Sdk_Injector {

	/**
	 * Script handle for the SDK bundle.
	 *
	 * @var string
	 */
	const HANDLE = 'guardlms-sdk';

	/**
	 * Script handle for the admin self-test probe.
	 *
	 * Deliberately NOT the SDK handle: an optimizer that defers the SDK must not
	 * also defer the probe whose whole job is to notice that deferral.
	 *
	 * @var string
	 */
	const SELFTEST_HANDLE = 'guardlms-sdk-selftest';

	/**
	 * Query flag that requests the self-test probe on a front-end page.
	 *
	 * @var string
	 */
	const SELFTEST_FLAG = 'guardlms_selftest';

	/**
	 * Breadcrumb types the SDK may record.
	 *
	 * `click` and `form` are absent on purpose and are not admin-configurable.
	 * On an LMS page the selectors they capture carry question ids, answer-option
	 * ids and field names; combined with the page URL they reconstruct what a
	 * learner answered. This array REPLACES the SDK's defaults rather than
	 * merging with them, so new SDK defaults will not reach this plugin: revisit
	 * this list whenever the SDK's defaults change.
	 *
	 * @var string[]
	 */
	const BREADCRUMB_TYPES = array( 'navigation', 'network', 'console', 'user' );

	/**
	 * Keys scrubbed out of URLs, stack traces and breadcrumb messages.
	 *
	 * Matching is a case-insensitive SUBSTRING test applied to the page URL,
	 * referrer, source file, stack trace and breadcrumb messages, so:
	 *  - 'nonce'  covers _wpnonce, wpnonce and wp_rest_nonce in one entry;
	 *  - 'token'  already covers logintoken;
	 *  - 'sesskey' matches no SDK default at all, which is why Moodle's CSRF
	 *    token would otherwise ship in every error's pageUrl.
	 * A bare 'key' is deliberately absent - substring matching would redact half
	 * the payload. Like BREADCRUMB_TYPES, this REPLACES the SDK defaults.
	 *
	 * @var string[]
	 */
	const REDACTED_KEYS = array(
		'password',
		'secret',
		'token',
		'apiKey',
		'api_key',
		'authorization',
		'sesskey',
		'nonce',
	);

	/**
	 * Errors dropped before they are ever sent.
	 *
	 * The SDK matches these against `error.message` ONLY, so an entry shaped like
	 * a URL or a filename filter would silently do nothing.
	 *
	 * @var string[]
	 */
	const IGNORE_ERRORS = array(
		'Script error.',
		'ResizeObserver loop limit exceeded',
		'ResizeObserver loop completed with undelivered notifications',
		'Non-Error promise rejection captured',
		'NetworkError when attempting to fetch resource.',
		'The operation is insecure.',
		'AbortError',
	);

	/**
	 * Enqueue the SDK (and the self-test probe when an admin asked for it).
	 *
	 * Hooked to `wp_enqueue_scripts` at priority 1.
	 *
	 * @return void
	 */
	public static function enqueue() {
		if ( is_admin() || is_feed() || wp_doing_cron() ) {
			return;
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return;
		}

		self::enqueue_sdk();
		self::maybe_enqueue_selftest();
	}

	/**
	 * Enqueue the SDK bundle and its init call, when every condition holds.
	 *
	 * @return void
	 */
	private static function enqueue_sdk() {
		if ( ! GuardLMS_Options::get( 'enabled' ) ) {
			return;
		}

		$sdk = GuardLMS_Sdk_Config::all();

		// Short-circuit on the AUTOLOADED opt-in flag BEFORE touching the
		// non-autoloaded credentials option, so a site that never opted in pays
		// no extra database read at all on the front end.
		if ( empty( $sdk['enabled'] ) ) {
			return;
		}

		$sdk_key = GuardLMS_Credentials::get_sdk_key();

		if ( ! GuardLMS_Sdk_Status::should_inject( $sdk, $sdk_key ) ) {
			return;
		}

		// $ver is null because the cache-buster rides inside sdk_url as
		// ?v={content-hash}, derived by the backend from the bundle's bytes.
		// $in_footer is false and no defer strategy is applied: deferring means
		// missing exactly the early errors the SDK exists to catch. ('defer'
		// would also require WP 6.3, and this plugin supports 6.0.)
		wp_enqueue_script( self::HANDLE, esc_url_raw( (string) $sdk['sdk_url'] ), array(), null, false );

		// JSON_HEX_TAG hex-escapes < and >, so a poisoned endpoint containing
		// </script> cannot terminate the inline block. The remaining flags close
		// the same class of breakout via &, ' and ".
		wp_add_inline_script(
			self::HANDLE,
			'GuardLMS.init(' . wp_json_encode( self::build_config( $sdk, $sdk_key ), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ) . ');',
			'after'
		);
	}

	/**
	 * Build the exact GuardLMS.init() configuration this plugin emits.
	 *
	 * @param array  $sdk     Effective configuration from GuardLMS_Sdk_Config::all().
	 * @param string $sdk_key The stored SDK key.
	 * @return array
	 */
	public static function build_config( array $sdk, string $sdk_key ) {
		$config = array(
			'apiKey'                        => $sdk_key,
			'endpoint'                      => (string) $sdk['errors_endpoint'],
			// The `^wordpress-` prefix is load-bearing for the backend alert that
			// catches a plugin shipping a wrong privacy config. Do not reformat.
			'appVersion'                    => self::app_version(),
			'releaseStage'                  => 'production',
			// Errors are already double-capped client- and server-side, and a
			// sampled error stream makes "did my fix work?" unanswerable, so the
			// backend's value is passed through unchanged.
			'sampleRate'                    => (float) $sdk['sample_rate'],
			'maxBreadcrumbs'                => (int) $sdk['max_breadcrumbs'],
			// Mirrors the server-side per-minute cap, so the client stops before
			// the server starts rejecting.
			'maxErrorsPerMinute'            => (int) $sdk['max_errors_per_minute'],
			// An IP is a GDPR identifier and the server sees it at the transport
			// layer anyway, so the client never volunteers it.
			'collectUserIp'                 => false,
			'interactionBreadcrumbsEnabled' => false,
			'enabledBreadcrumbTypes'        => self::BREADCRUMB_TYPES,
			'redactedKeys'                  => self::REDACTED_KEYS,
			'ignoreErrors'                  => self::ignore_errors( $sdk ),
		);

		// GuardLMS.setUser() is NEVER called and no user object is emitted.
		// Attaching {id,email,name} to telemetry from a learning platform turns
		// it into processing of learning behaviour tied to a named individual,
		// which an admin checkbox cannot lawfully authorise. The SDK's own
		// anonymous id already delivers session stitching without identity.
		if ( GuardLMS_Sdk_Status::should_send_analytics( $sdk ) ) {
			$config['analytics'] = array(
				'enabled'          => true,
				'endpoint'         => (string) $sdk['analytics_endpoint'],
				'sampleRate'       => (float) $sdk['analytics_sample_rate'],
				'trackScrollDepth' => true,
			);
		}

		// `batchInterval` is deliberately absent: its stored value is drifted
		// three ways and enforced nowhere, so emitting it could only regress
		// flush latency from the SDK's 2s default to 10-20s.
		return $config;
	}

	/**
	 * The `wordpress-<core>/guardlms-<plugin>` version string.
	 *
	 * @return string
	 */
	private static function app_version() {
		$core = trim( (string) get_bloginfo( 'version' ) );
		if ( '' === $core ) {
			$core = 'unknown';
		}

		return 'wordpress-' . $core . '/guardlms-' . GUARDLMS_VERSION;
	}

	/**
	 * The ignoreErrors list: this plugin's baseline plus the dashboard's additions.
	 *
	 * The baseline is always present so the noise floor cannot be configured
	 * away, and any extra patterns an admin set in the dashboard are appended
	 * (the dashboard owns that field; the plugin only reads it).
	 *
	 * @param array $sdk Effective configuration from GuardLMS_Sdk_Config::all().
	 * @return string[]
	 */
	private static function ignore_errors( array $sdk ) {
		$extra = isset( $sdk['ignored_errors'] ) && is_array( $sdk['ignored_errors'] )
			? $sdk['ignored_errors']
			: array();

		return array_values( array_unique( array_merge( self::IGNORE_ERRORS, $extra ) ) );
	}

	/**
	 * Keep optimizer plugins from deferring or delaying the SDK.
	 *
	 * Hooked to `script_loader_tag`. Optimization plugins routinely rewrite
	 * enqueued scripts to load late, which defeats the whole point of installing
	 * the error handlers first. This strips any defer/async they added and sets
	 * the opt-out attributes the common ones honour. It cannot win against every
	 * optimizer; the authenticated self-test (see maybe_enqueue_selftest) is what
	 * actually tells an admin the SDK failed to load, and a wp_head priority-0
	 * raw <script> is the documented escape hatch if a specific optimizer still
	 * wins on a given site.
	 *
	 * @param string $tag    The full <script> tag.
	 * @param string $handle The script handle being filtered.
	 * @return string
	 */
	public static function filter_script_tag( $tag, $handle ) {
		if ( self::HANDLE !== $handle ) {
			return $tag;
		}

		$tag = (string) $tag;

		// Drop defer/async, valued or bare.
		$stripped = preg_replace( '#\s+(?:defer|async)(?:=(?:"[^"]*"|\'[^\']*\'|[^\s>]+))?#i', '', $tag );
		if ( null !== $stripped ) {
			$tag = $stripped;
		}

		// Add the opt-out markers, once.
		if ( false === strpos( $tag, 'data-no-optimize' ) ) {
			$tag = preg_replace(
				'#<script\s#i',
				'<script data-no-optimize="1" data-no-defer="1" data-cfasync="false" data-wpmeteor-nooptimize="true" ',
				$tag,
				1
			);
		}

		return (string) $tag;
	}

	/**
	 * Enqueue the admin self-test probe when this page load asked for it.
	 *
	 * The probe reports in the browser of the admin who clicked the button, so
	 * there is no unauthenticated endpoint, no front-end write path and no new
	 * option key. It is registered on its own footer-printed handle so that an
	 * optimizer deferring the SDK does not also defer its own detector.
	 *
	 * @return void
	 */
	private static function maybe_enqueue_selftest() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display flag; the capability check below is the actual gate and nothing is written.
		if ( ! isset( $_GET[ self::SELFTEST_FLAG ] ) ) {
			return;
		}

		if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		wp_register_script( self::SELFTEST_HANDLE, false, array(), null, true );
		wp_enqueue_script( self::SELFTEST_HANDLE );
		wp_add_inline_script( self::SELFTEST_HANDLE, self::selftest_script() );
	}

	/**
	 * The self-test probe source.
	 *
	 * @return string
	 */
	private static function selftest_script() {
		$websiteid = (int) GuardLMS_Options::get( 'websiteid' );
		$baseurl   = rtrim( (string) GuardLMS_Options::get( 'baseurl' ), '/' );
		$dashboard = ( $websiteid > 0 && '' !== $baseurl ) ? $baseurl . '/websites/' . $websiteid : '';

		$strings = array(
			'missing'   => __( 'GuardLMS did not load on this page. Another plugin may be deferring or blocking it. Look for a "delay JavaScript" or "defer JavaScript" option in your optimization plugin and exclude guardlms.min.js from it.', 'guardlms' ),
			'sent'      => __( 'GuardLMS self-test error sent. It should appear in your GuardLMS dashboard within a minute.', 'guardlms' ),
			'dashboard' => __( 'Open the GuardLMS dashboard', 'guardlms' ),
			'url'       => $dashboard,
		);

		return 'window.guardlmsSelfTest=' . wp_json_encode( $strings, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ) . ';'
			. '(function(s){'
			. 'var b=document.createElement("div");'
			. 'b.id="guardlms-selftest";'
			. 'b.style.cssText="position:fixed;left:0;right:0;top:0;z-index:2147483647;padding:12px 16px;background:#fff;border-bottom:4px solid #2271b1;font:14px/1.5 sans-serif;color:#1d2327";'
			. 'if(typeof window.GuardLMS==="undefined"){b.style.borderBottomColor="#d63638";b.textContent=s.missing;}'
			. 'else{try{window.GuardLMS.notify(new Error("GuardLMS self-test"));}catch(e){}'
			. 'b.textContent=s.sent;'
			. 'if(s.url){var a=document.createElement("a");a.href=s.url;a.textContent=" "+s.dashboard;a.style.marginLeft="8px";b.appendChild(a);}}'
			. 'document.body.appendChild(b);'
			. '}(window.guardlmsSelfTest));';
	}
}
