<?php
/**
 * GuardLMS admin settings page (Settings API).
 *
 * @package GuardLMS
 * @license GPL-3.0-or-later
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
	const OPTION   = 'guardlms_settings';
	const PAGE     = 'guardlms';
	const PUSH_ACT = 'guardlms_push_now';

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

		add_options_page(
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
			return is_array( $input ) ? $input : GuardLMS_Options::all();
		}

		if ( ! is_array( $input ) ) {
			$input = array();
		}

		// Start from the current stored settings and overlay only the form-managed
		// fields, so any key the form does not manage (webserver, connected_siteurl,
		// lastpush, lastpushstatus, keyexpiresat, last_plugincount) survives untouched.
		$clean = GuardLMS_Options::all();

		// Enabled (checkbox: present only when checked).
		$clean['enabled'] = ! empty( $input['enabled'] );

		// Base URL (HTTPS only; non-https or invalid keeps the previous value).
		$baseurl_raw = isset( $input['baseurl'] ) ? trim( wp_unslash( (string) $input['baseurl'] ) ) : '';
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

		// Push path.
		$pushpath          = isset( $input['pushpath'] ) ? sanitize_text_field( wp_unslash( $input['pushpath'] ) ) : '';
		$clean['pushpath'] = ( '' === $pushpath ) ? GUARDLMS_DEFAULT_PUSHPATH : $pushpath;

		// Send configuration (checkbox).
		$clean['sendconfig'] = ! empty( $input['sendconfig'] );

		// Verification token (public, pasted from the dashboard).
		$clean['verificationtoken'] = isset( $input['verificationtoken'] )
			? sanitize_text_field( wp_unslash( $input['verificationtoken'] ) )
			: '';

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

		self::render_push_notice();

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
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

			<form action="options.php" method="post">
				<?php
				settings_fields( self::GROUP );
				do_settings_sections( self::PAGE );
				submit_button();
				?>
			</form>

			<hr>

			<h2><?php esc_html_e( 'Register this site', 'guardlms' ); ?></h2>
			<p>
				<?php esc_html_e( 'Register this URL in GuardLMS:', 'guardlms' ); ?>
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
		</div>
		<?php
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
			'guardlms_push_result_' . get_current_user_id(),
			array(
				'type'    => $type,
				'message' => $message,
			),
			MINUTE_IN_SECONDS
		);

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'          => self::PAGE,
					'guardlms_push' => $type,
				),
				admin_url( 'options-general.php' )
			)
		);
		exit;
	}

	/**
	 * Emit admin notices on `admin_init`: clone guard + key-expiry warning.
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

		// (a) Clone guard: the site URL changed since the key was bound.
		if ( '' !== $connected_url && $current_url !== $connected_url ) {
			GuardLMS_Credentials::delete();
			GuardLMS_Options::set( 'connected_siteurl', '' );

			add_action(
				'admin_notices',
				static function () {
					printf(
						'<div class="notice notice-warning"><p>%s</p></div>',
						esc_html__( 'GuardLMS detected that this site URL changed and has disconnected the stored API key. Re-enter your key to reconnect.', 'guardlms' )
					);
				}
			);
		}

		// (b) Key expiry warning (within 30 days).
		$expires = (int) GuardLMS_Options::get( 'keyexpiresat' );
		if ( $expires > 0 && $expires < time() + 30 * DAY_IN_SECONDS ) {
			add_action(
				'admin_notices',
				static function () use ( $expires ) {
					$when = wp_date( get_option( 'date_format' ), $expires );
					printf(
						'<div class="notice notice-warning"><p>%s</p></div>',
						esc_html(
							sprintf(
								/* translators: %s: key expiry date. */
								__( 'Your GuardLMS API key expires on %s. Please reconnect to obtain a fresh key before it expires.', 'guardlms' ),
								$when
							)
						)
					);
				}
			);
		}
	}

	/**
	 * Render the transient-backed "Push now" result notice, if any.
	 *
	 * @return void
	 */
	private static function render_push_notice() {
		$key    = 'guardlms_push_result_' . get_current_user_id();
		$result = get_transient( $key );
		if ( false === $result || ! is_array( $result ) ) {
			return;
		}
		delete_transient( $key );

		$class   = ( isset( $result['type'] ) && 'success' === $result['type'] ) ? 'notice-success' : 'notice-error';
		$message = isset( $result['message'] ) ? (string) $result['message'] : '';

		printf(
			'<div class="notice %1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( $class ),
			esc_html( $message )
		);
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
		printf(
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
	 * Render the push path field.
	 *
	 * @return void
	 */
	public static function field_pushpath() {
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
