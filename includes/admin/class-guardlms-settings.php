<?php
/**
 * GuardLMS admin settings page (Settings API).
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
 * Registers and renders the GuardLMS settings screen under Settings -> GuardLMS.
 *
 * The public verification token and non-secret connection settings live in the
 * `guardlms_settings` option. The API key is handled out-of-band and persisted
 * through GuardLMS_Credentials so it never lands in the settings array.
 */
class GuardLMS_Settings {

	/**
	 * Settings API option group and stored option name.
	 */
	const GROUP    = 'guardlms';
	const PAGE     = 'guardlms';
	const PUSH_ACT = 'guardlms_push_now';

	/**
	 * Hook suffix WordPress assigned to the options page in register().
	 *
	 * Kept from add_options_page() rather than re-derived from the slug: a
	 * menu-editor plugin or a later move to another parent menu changes it,
	 * and `admin_enqueue_scripts` hands the real one back for comparison.
	 *
	 * @var string
	 */
	private static $hook_suffix = '';

	/**
	 * The settings option name.
	 *
	 * Deliberately an alias of GuardLMS_Options::OPTION rather than a second copy
	 * of the literal: two constants holding the same string is exactly the shape
	 * where a rename updates one and leaves the other silently pointing at a row
	 * nothing writes any more.
	 *
	 * @var string
	 */
	const OPTION = GuardLMS_Options::OPTION;

	/**
	 * Prefix of the per-user "Push now" result transient.
	 *
	 * One name, one place. The full key is this prefix plus the user id.
	 *
	 * @var string
	 */
	const PUSH_NOTICE_PREFIX = 'guardlms_push_result_';

	/**
	 * Register the options page, setting, sections and fields.
	 *
	 * Wired to both `admin_menu` and `admin_init`; the body is idempotent so it
	 * runs its work only once per request regardless of which hook fires first.
	 *
	 * @return void
	 */
	public static function register() {
		static $initialized = false;
		if ( $initialized ) {
			return;
		}
		$initialized = true;

		// False when the current user lacks the capability: the screen is not
		// theirs, so the stylesheet never matching is the correct outcome.
		self::$hook_suffix = (string) add_options_page(
			__( 'GuardLMS', 'guardlms' ),
			__( 'GuardLMS', 'guardlms' ),
			'manage_options',
			self::PAGE,
			array( __CLASS__, 'render_page' )
		);

		register_setting(
			self::GROUP,
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
			)
		);

		add_settings_section(
			'guardlms_connection',
			__( 'Connection', 'guardlms' ),
			array( __CLASS__, 'section_connection' ),
			self::PAGE
		);
		add_settings_section(
			'guardlms_data',
			__( 'Data collection', 'guardlms' ),
			array( __CLASS__, 'section_data' ),
			self::PAGE
		);

		add_settings_field(
			'guardlms_enabled',
			__( 'Enabled', 'guardlms' ),
			array( __CLASS__, 'field_enabled' ),
			self::PAGE,
			'guardlms_connection'
		);
		add_settings_field(
			'guardlms_baseurl',
			__( 'Base URL', 'guardlms' ),
			array( __CLASS__, 'field_baseurl' ),
			self::PAGE,
			'guardlms_connection'
		);
		add_settings_field(
			'guardlms_pushpath',
			__( 'Push path', 'guardlms' ),
			array( __CLASS__, 'field_pushpath' ),
			self::PAGE,
			'guardlms_connection'
		);
		add_settings_field(
			'guardlms_apikey',
			__( 'API key', 'guardlms' ),
			array( __CLASS__, 'field_apikey' ),
			self::PAGE,
			'guardlms_connection'
		);
		add_settings_field(
			'guardlms_verificationtoken',
			__( 'Verification token', 'guardlms' ),
			array( __CLASS__, 'field_verificationtoken' ),
			self::PAGE,
			'guardlms_connection'
		);
		add_settings_field(
			'guardlms_sendconfig',
			__( 'Send configuration', 'guardlms' ),
			array( __CLASS__, 'field_sendconfig' ),
			self::PAGE,
			'guardlms_data'
		);
	}

	/**
	 * Enqueue the plugin screen stylesheet: brand header, status badge, buttons.
	 *
	 * Hooked to `admin_enqueue_scripts`. Loads on the GuardLMS settings screen
	 * only, so no other admin page carries the extra request. The stylesheet
	 * deliberately mirrors styles.css in the Moodle plugin so both connectors
	 * look the same.
	 *
	 * @param string $hook_suffix Hook suffix of the current admin screen.
	 * @return void
	 */
	public static function enqueue_assets( $hook_suffix ) {
		if ( '' === self::$hook_suffix || self::$hook_suffix !== $hook_suffix ) {
			return;
		}

		wp_enqueue_style(
			'guardlms-admin',
			GUARDLMS_PLUGIN_URL . 'assets/admin.css',
			array(),
			GUARDLMS_VERSION
		);
	}

	/**
	 * URL of the GuardLMS settings screen, optionally with extra query args.
	 *
	 * The one place that knows where the screen lives; every redirect and
	 * notice link goes through here.
	 *
	 * @param array $args Extra query args merged after `page`.
	 * @return string
	 */
	public static function url( array $args = array() ) {
		return add_query_arg(
			array_merge( array( 'page' => self::PAGE ), $args ),
			admin_url( 'options-general.php' )
		);
	}

	/**
	 * Whether the advanced view is requested for this page load.
	 *
	 * The connection fields are deliberately URL-only, so a site owner sees a page
	 * with a single Connect button and cannot break a working connection by hand:
	 * options-general.php?page=guardlms&advanced=1
	 *
	 * @return bool
	 */
	public static function is_advanced() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return false;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only view switch; it reveals fields the capability check above already allows.
		$advanced = isset( $_GET['advanced'] ) ? sanitize_text_field( wp_unslash( $_GET['advanced'] ) ) : '';

		return '' !== $advanced && '0' !== $advanced;
	}

	/**
	 * Sanitize the submitted settings and persist the API key out-of-band.
	 *
	 * @param mixed $input Raw (slashed) POST data for the option.
	 * @return array Clean settings array to store in `guardlms_settings`.
	 */
	public static function sanitize( $input ) {
		// This method is registered as the `sanitize_option_guardlms_settings`
		// filter, so EVERY programmatic update_option() (GuardLMS_Options::set /
		// GuardLMS_Options::update) re-enters it. Only rebuild from form fields on
		// the genuine Settings-API save for THIS option group; otherwise pass the
		// value through untouched so programmatic writes are not clobbered.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- The Settings API verifies the nonce (check_admin_referer) before triggering this filter; option_page only distinguishes the real form save from programmatic re-entry.
		$option_page = isset( $_POST['option_page'] ) ? sanitize_key( wp_unslash( $_POST['option_page'] ) ) : '';
		if ( self::GROUP !== $option_page ) {
			return is_array( $input ) ? $input : GuardLMS_Options::stored();
		}

		if ( ! is_array( $input ) ) {
			$input = array();
		}

		// Start from the current STORED settings (not the effective ones, so a
		// wp-config-pinned base URL is not written to the database) and overlay only
		// the keys this submit actually carried. A screen that renders no connection
		// fields, the default non-advanced page, therefore cannot reset them, and
		// keys the form never manages (webserver, connected_siteurl, lastpush,
		// lastpushstatus, keyexpiresat, last_plugincount) survive untouched.
		$clean = GuardLMS_Options::stored();

		// Checkboxes ship a hidden 0 companion input, so the key is present whenever
		// the field was rendered and absent when it was not.
		if ( array_key_exists( 'enabled', $input ) ) {
			$clean['enabled'] = ! empty( $input['enabled'] );
		}

		if ( array_key_exists( 'sendconfig', $input ) ) {
			$clean['sendconfig'] = ! empty( $input['sendconfig'] );
		}

		// Base URL (HTTPS only; non-https or invalid keeps the previous value).
		if ( array_key_exists( 'baseurl', $input ) ) {
			$baseurl_raw = trim( wp_unslash( (string) $input['baseurl'] ) );
			if ( '' === $baseurl_raw ) {
				$clean['baseurl'] = GUARDLMS_DEFAULT_BASEURL;
			} else {
				$baseurl = esc_url_raw( $baseurl_raw, array( 'https' ) );
				if ( '' === $baseurl ) {
					add_settings_error(
						self::OPTION,
						'guardlms_baseurl_https',
						esc_html__( 'The GuardLMS base URL must use HTTPS. The previous value was kept.', 'guardlms' )
					);
					// Keep the existing value already present in $clean['baseurl'].
				} else {
					$clean['baseurl'] = $baseurl;
				}
			}
		}

		// Push path.
		if ( array_key_exists( 'pushpath', $input ) ) {
			$pushpath          = sanitize_text_field( wp_unslash( (string) $input['pushpath'] ) );
			$clean['pushpath'] = ( '' === $pushpath ) ? GUARDLMS_DEFAULT_PUSHPATH : $pushpath;
		}

		// Verification token (public, written by the connect flow, editable for support).
		if ( array_key_exists( 'verificationtoken', $input ) ) {
			$clean['verificationtoken'] = sanitize_text_field( wp_unslash( (string) $input['verificationtoken'] ) );
		}

		// Real-time monitoring opt-in. Present only when the real-time form was the
		// one submitted, so the advanced form's save cannot rewrite it and vice
		// versa. Both checkboxes ship a hidden 0 companion.
		if ( array_key_exists( 'sdk', $input ) && is_array( $input['sdk'] ) ) {
			$sdk = GuardLMS_Sdk_Config::all();

			$sdk['enabled'] = ! empty( $input['sdk']['enabled'] );
			// Analytics needs BOTH the admin's opt-in and the plan entitlement.
			// The checkbox is rendered disabled without the entitlement, but a
			// disabled input is a display state, not a security control.
			$sdk['analytics'] = ! empty( $input['sdk']['analytics'] ) && ! empty( $sdk['analytics_allowed'] );

			$clean['sdk'] = $sdk;

			// The cache purge deliberately does NOT happen here. sanitize() runs
			// BEFORE update_option() writes, so purging at this point empties the
			// cache while the old value is still live - any request landing in
			// that window re-caches pre-toggle HTML and the purge achieves
			// nothing. It is hooked to update_option_guardlms_settings instead,
			// which fires after the write. See maybe_purge_on_toggle().
		}

		// API key: persisted via GuardLMS_Credentials, never stored in this option.
		$apikey = isset( $input['apikey'] ) ? trim( wp_unslash( (string) $input['apikey'] ) ) : '';
		if ( '' !== $apikey ) {
			GuardLMS_Credentials::set_key( $apikey );
			// Bind the current site URL so a clone cannot push as this site.
			$clean['connected_siteurl'] = rtrim( home_url(), '/' );
		}
		// When no key is submitted, connected_siteurl is left as loaded above.

		return $clean;
	}

	/**
	 * Render the settings screen.
	 *
	 * @return void
	 */
	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'guardlms' ) );
		}

		// Fetch the real-time settings synchronously for a site that connected
		// before this feature existed, BEFORE anything is rendered, so the key is
		// present by the time the page describes its own state. Never
		// cron-dependent: see GuardLMS_Sdk_Client::maybe_bootstrap().
		GuardLMS_Sdk_Client::maybe_bootstrap();

		self::render_push_notice();
		GuardLMS_Connect_Page::render_notice();
		GuardLMS_Realtime_Page::render_notice();

		$advanced   = self::is_advanced();
		$home       = home_url();
		$lastpush   = (int) GuardLMS_Options::get( 'lastpush' );
		$laststatus = (int) GuardLMS_Options::get( 'lastpushstatus' );

		if ( $lastpush > 0 ) {
			$when        = wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $lastpush );
			$status_text = sprintf(
				/* translators: 1: formatted date, 2: HTTP status code. */
				__( 'Last push: %1$s (HTTP %2$d)', 'guardlms' ),
				$when,
				$laststatus
			);
		} else {
			$status_text = __( 'No successful push yet.', 'guardlms' );
		}
		?>
		<div class="wrap">
			<h1 class="guardlms-title">
				<img class="guardlms-logo"
					src="<?php echo esc_url( GUARDLMS_PLUGIN_URL . 'assets/logo.png' ); ?>"
					alt="<?php esc_attr_e( 'GuardLMS', 'guardlms' ); ?>">
				<?php echo esc_html( get_admin_page_title() ); ?>
			</h1>

			<?php
			// The whole end-user surface: status, the action, then the dates.
			GuardLMS_Connect_Page::render_status();
			GuardLMS_Connect_Page::render_buttons();
			GuardLMS_Connect_Page::render_details();

			// The real-time opt-in belongs on the DEFAULT page, not behind
			// ?advanced=1: it is the feature the site owner is here to switch on.
			GuardLMS_Realtime_Page::render();
			?>

			<?php if ( $advanced ) : ?>
				<hr>

				<h2><?php esc_html_e( 'Advanced settings', 'guardlms' ); ?></h2>
				<div class="notice notice-warning inline">
					<p>
						<?php esc_html_e( 'These settings are only needed for a self-hosted GuardLMS instance or for support. Changing them on a normal site breaks the connection.', 'guardlms' ); ?>
					</p>
				</div>

				<form action="options.php" method="post">
					<?php
					settings_fields( self::GROUP );
					do_settings_sections( self::PAGE );
					submit_button();
					?>
				</form>

				<hr>

				<h2><?php esc_html_e( 'Manual push', 'guardlms' ); ?></h2>
				<p>
					<?php esc_html_e( 'Site URL registered in GuardLMS:', 'guardlms' ); ?>
					<code><?php echo esc_html( $home ); ?></code>
				</p>
				<p><?php echo esc_html( $status_text ); ?></p>

				<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
					<input type="hidden" name="action" value="<?php echo esc_attr( self::PUSH_ACT ); ?>">
					<?php
					wp_nonce_field( self::PUSH_ACT );
					submit_button( __( 'Push now', 'guardlms' ), 'secondary', 'guardlms_push_now_submit', false );
					?>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Purge page caches when the real-time toggle actually changed.
	 *
	 * Hooked to `update_option_guardlms_settings`, which fires AFTER the new
	 * value is committed - so the caches refill from HTML that already reflects
	 * the new setting. Flipping the toggle changes what every cached page must
	 * contain, and stale cached HTML is the single most common cause of "I
	 * turned it on and nothing happened".
	 *
	 * @param mixed $old_value The previous option value.
	 * @param mixed $value     The value just written.
	 * @return void
	 */
	public static function maybe_purge_on_toggle( $old_value, $value ) {
		$was = is_array( $old_value ) && isset( $old_value['sdk']['enabled'] )
			? ! empty( $old_value['sdk']['enabled'] )
			: false;
		$now = is_array( $value ) && isset( $value['sdk']['enabled'] )
			? ! empty( $value['sdk']['enabled'] )
			: false;

		if ( $was !== $now ) {
			GuardLMS_Connect_Manager::purge_caches();
		}
	}

	/**
	 * Handle the "Push now" admin-post action.
	 *
	 * Hooked to `admin_post_guardlms_push_now`.
	 *
	 * @return void
	 */
	public static function handle_push_now() {
		check_admin_referer( self::PUSH_ACT );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'guardlms' ) );
		}

		$result = GuardLMS_Pusher::push();

		if ( is_wp_error( $result ) ) {
			$type    = 'error';
			$message = $result->get_error_message();
		} else {
			$type    = 'success';
			$message = __( 'Site information pushed to GuardLMS successfully.', 'guardlms' );
		}

		set_transient(
			self::PUSH_NOTICE_PREFIX . get_current_user_id(),
			array(
				'type'    => $type,
				'message' => $message,
			),
			MINUTE_IN_SECONDS
		);

		// Push now only exists on the advanced view, so return the admin to it.
		wp_safe_redirect(
			self::url(
				array(
					'advanced'      => 1,
					'guardlms_push' => $type,
				)
			)
		);
		exit;
	}

	/**
	 * A site URL reduced to host and path, so two URLs can be compared without
	 * their scheme deciding the outcome.
	 *
	 * @param string $url Absolute site URL.
	 * @return string
	 */
	public static function host_and_path( string $url ) {
		return preg_replace( '#^[a-z][a-z0-9+.-]*://#i', '', rtrim( trim( $url ), '/' ) );
	}

	/**
	 * Queue admin notices on `admin_init`: clone guard + key-expiry warning.
	 *
	 * Both notices are kept within the WordPress.org "do not hijack the
	 * dashboard" guideline: rendered for users who can act on them only,
	 * dismissible, and pointing at the screen where the fix lives.
	 *
	 * @return void
	 */
	public static function maybe_notice() {
		$current_url   = rtrim( home_url(), '/' );
		$connected_url = (string) GuardLMS_Options::get( 'connected_siteurl' );

		// Bind the site URL when a key exists but was never bound through the form
		// save path (e.g. the key comes from the GUARDLMS_PUSH_KEY wp-config
		// constant). Without this, the clone guard and the Pusher 422 "registered
		// URL" message have nothing to compare against.
		if ( '' === $connected_url && GuardLMS_Credentials::has_key() ) {
			GuardLMS_Options::set( 'connected_siteurl', $current_url );
			$connected_url = $current_url;
		}

		// (a) Clone guard: the site URL changed since the key was bound. Compared
		// without the scheme, because home_url() follows the scheme of the current
		// request: an admin opening the site over https after the key was bound over
		// http is the same site, not a clone, and must not lose its key.
		if ( '' !== $connected_url && self::host_and_path( $current_url ) !== self::host_and_path( $connected_url ) ) {
			GuardLMS_Credentials::delete();
			// The expiry belongs to the key just dropped: clear it in the same
			// write so branch (b) below cannot warn about a key that is gone.
			GuardLMS_Options::update(
				array(
					'connected_siteurl' => '',
					'keyexpiresat'      => 0,
				)
			);

			add_action( 'admin_notices', array( __CLASS__, 'render_url_changed_notice' ) );
		}

		// (b) Key expiry warning (within 30 days).
		$expires = (int) GuardLMS_Options::get( 'keyexpiresat' );
		if ( $expires > 0 && $expires < time() + 30 * DAY_IN_SECONDS ) {
			add_action( 'admin_notices', array( __CLASS__, 'render_expiry_notice' ) );
		}
	}

	/**
	 * Render the "site URL changed, key dropped" notice.
	 *
	 * @return void
	 */
	public static function render_url_changed_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// The site is disconnected at this point, so the screen offers
		// "Connect", not "Reconnect".
		self::render_dismissible_warning(
			__( 'GuardLMS detected that this site URL changed and has disconnected the site. Connect it again from the GuardLMS settings.', 'guardlms' ),
			__( 'Connect', 'guardlms' )
		);
	}

	/**
	 * Render the "key expires soon" notice.
	 *
	 * Re-checks the key and its expiry at render time: the key may have been
	 * disconnected between `admin_init` and `admin_notices` in the same
	 * request, and a warning about a key that no longer exists only confuses.
	 *
	 * @return void
	 */
	public static function render_expiry_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$expires = (int) GuardLMS_Options::get( 'keyexpiresat' );
		if ( $expires <= 0 || ! GuardLMS_Credentials::has_key() ) {
			return;
		}

		self::render_dismissible_warning(
			sprintf(
				/* translators: %s: key expiry date. */
				__( 'Your GuardLMS API key expires on %s. Please reconnect to obtain a fresh key before it expires.', 'guardlms' ),
				wp_date( get_option( 'date_format' ), $expires )
			),
			__( 'Reconnect', 'guardlms' )
		);
	}

	/**
	 * Print one dismissible warning notice with a link to the settings screen.
	 *
	 * @param string $message   Plain-text message; escaped by the renderer.
	 * @param string $link_text Label of the link to the settings screen.
	 * @return void
	 */
	private static function render_dismissible_warning( $message, $link_text ) {
		GuardLMS_Admin_Notice::render(
			'warning',
			$message,
			array(
				'dismissible' => true,
				'link_url'    => self::url(),
				'link_text'   => $link_text,
			)
		);
	}

	/**
	 * Render the transient-backed "Push now" result notice, if any.
	 *
	 * @return void
	 */
	private static function render_push_notice() {
		$key    = self::PUSH_NOTICE_PREFIX . get_current_user_id();
		$result = get_transient( $key );
		if ( false === $result || ! is_array( $result ) ) {
			return;
		}
		delete_transient( $key );

		$type    = ( isset( $result['type'] ) && 'success' === $result['type'] ) ? 'success' : 'error';
		$message = isset( $result['message'] ) ? (string) $result['message'] : '';

		GuardLMS_Admin_Notice::render( $type, $message, array( 'dismissible' => true ) );
	}

	/**
	 * Connection section description.
	 *
	 * @return void
	 */
	public static function section_connection() {
		printf( '<p>%s</p>', esc_html__( 'Connection settings for the GuardLMS service.', 'guardlms' ) );
	}

	/**
	 * Data-collection section description.
	 *
	 * @return void
	 */
	public static function section_data() {
		printf( '<p>%s</p>', esc_html__( 'Control what optional information is included in each push.', 'guardlms' ) );
	}

	/**
	 * Render the "enabled" checkbox field.
	 *
	 * @return void
	 */
	public static function field_enabled() {
		// The hidden 0 keeps the key present in the POST when the box is unchecked,
		// which is how sanitize() tells "unchecked" from "field not rendered".
		printf(
			'<input type="hidden" name="guardlms_settings[enabled]" value="0">' .
			'<label><input type="checkbox" name="guardlms_settings[enabled]" value="1" %s> %s</label>',
			checked( (bool) GuardLMS_Options::get( 'enabled' ), true, false ),
			esc_html__( 'Enable GuardLMS reporting and ownership verification.', 'guardlms' )
		);
	}

	/**
	 * Render the base URL field.
	 *
	 * @return void
	 */
	public static function field_baseurl() {
		if ( GuardLMS_Options::is_pinned( 'baseurl' ) ) {
			self::render_pinned_field( 'baseurl', 'GUARDLMS_BASEURL' );
			return;
		}

		printf(
			'<input type="url" class="regular-text" name="guardlms_settings[baseurl]" value="%1$s" placeholder="%2$s">',
			esc_attr( (string) GuardLMS_Options::get( 'baseurl' ) ),
			esc_attr( GUARDLMS_DEFAULT_BASEURL )
		);
		printf(
			'<p class="description">%s</p>',
			esc_html__( 'HTTPS only. Leave as the default unless GuardLMS instructs otherwise.', 'guardlms' )
		);
	}

	/**
	 * Render a setting that is pinned by a wp-config constant as read-only text.
	 *
	 * @param string $key      Setting key to display.
	 * @param string $constant Name of the wp-config constant that pins it.
	 * @return void
	 */
	private static function render_pinned_field( string $key, string $constant ) {
		printf( '<code>%s</code>', esc_html( (string) GuardLMS_Options::get( $key ) ) );
		printf(
			'<p class="description">%s</p>',
			esc_html(
				sprintf(
					/* translators: %s: wp-config.php constant name. */
					__( 'Defined in wp-config.php (%s) and cannot be changed here.', 'guardlms' ),
					$constant
				)
			)
		);
	}

	/**
	 * Render the push path field.
	 *
	 * @return void
	 */
	public static function field_pushpath() {
		if ( GuardLMS_Options::is_pinned( 'pushpath' ) ) {
			self::render_pinned_field( 'pushpath', 'GUARDLMS_PUSHPATH' );
			return;
		}

		printf(
			'<input type="text" class="regular-text" name="guardlms_settings[pushpath]" value="%1$s" placeholder="%2$s">',
			esc_attr( (string) GuardLMS_Options::get( 'pushpath' ) ),
			esc_attr( GUARDLMS_DEFAULT_PUSHPATH )
		);
	}

	/**
	 * Render the API key field (write-only password input).
	 *
	 * @return void
	 */
	public static function field_apikey() {
		if ( defined( 'GUARDLMS_PUSH_KEY' ) && '' !== trim( (string) GUARDLMS_PUSH_KEY ) ) {
			printf(
				'<p class="description">%s</p>',
				esc_html__( 'The API key is defined in wp-config.php (GUARDLMS_PUSH_KEY) and cannot be changed here.', 'guardlms' )
			);
			return;
		}

		$placeholder = GuardLMS_Credentials::has_key()
			? __( 'A key is stored — leave blank to keep it', 'guardlms' )
			: __( 'Paste your GuardLMS API key', 'guardlms' );

		printf(
			'<input type="password" class="regular-text" name="guardlms_settings[apikey]" value="" autocomplete="new-password" placeholder="%s">',
			esc_attr( $placeholder )
		);
		printf(
			'<p class="description">%s</p>',
			esc_html__( 'Stored securely and never displayed. Leave blank to keep the existing key.', 'guardlms' )
		);
	}

	/**
	 * Render the send-configuration checkbox field.
	 *
	 * @return void
	 */
	public static function field_sendconfig() {
		printf(
			'<input type="hidden" name="guardlms_settings[sendconfig]" value="0">' .
			'<label><input type="checkbox" name="guardlms_settings[sendconfig]" value="1" %s> %s</label>',
			checked( (bool) GuardLMS_Options::get( 'sendconfig' ), true, false ),
			esc_html__( 'Include a small, non-secret configuration summary (WP_DEBUG, registration, default role, etc.) in each push.', 'guardlms' )
		);
	}

	/**
	 * Render the verification token field.
	 *
	 * @return void
	 */
	public static function field_verificationtoken() {
		printf(
			'<input type="text" class="regular-text" name="guardlms_settings[verificationtoken]" value="%s">',
			esc_attr( (string) GuardLMS_Options::get( 'verificationtoken' ) )
		);
		printf(
			'<p class="description">%s</p>',
			esc_html__( 'Paste the verification token from your GuardLMS dashboard to prove site ownership.', 'guardlms' )
		);
	}
}
