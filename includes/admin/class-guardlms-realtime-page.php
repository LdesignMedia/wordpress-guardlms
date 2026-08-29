<?php
/**
 * GuardLMS admin real-time monitoring section.
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
 * Renders the "Real-time monitoring" block on Settings -> GuardLMS and handles
 * its three admin-post actions.
 *
 * The block lives on the DEFAULT page, not behind ?advanced=1: it is the
 * user-facing opt-in, and hiding it would make the feature undiscoverable. Its
 * form posts to options.php in the same option group as the advanced form.
 * GuardLMS_Settings::sanitize() overlays only the keys a submit actually
 * carried, so the two forms cannot clobber each other's fields.
 */
class GuardLMS_Realtime_Page {

	/**
	 * Admin page slug the real-time actions return to.
	 *
	 * @var string
	 */
	const PAGE = 'guardlms';

	/**
	 * admin-post action that refreshes the real-time settings (`fetch`).
	 *
	 * @var string
	 */
	const REFRESH_ACTION = 'guardlms_sdk_refresh';

	/**
	 * admin-post action that sends a test error from the admin's own browser.
	 *
	 * @var string
	 */
	const SELFTEST_ACTION = 'guardlms_sdk_selftest';

	/**
	 * admin-post action that replaces the SDK key (`rotate`).
	 *
	 * @var string
	 */
	const ROTATE_ACTION = 'guardlms_sdk_rotate';

	/**
	 * Transient key holding the one-shot real-time result notice.
	 *
	 * @var string
	 */
	const NOTICE_TRANSIENT = 'guardlms_sdk_notice';

	/**
	 * Render the whole real-time monitoring block.
	 *
	 * @return void
	 */
	public static function render(): void {
		$sdk = GuardLMS_Sdk_Config::all();

		// §5.3 row 2: a backend that answers 404/405 predates this feature. The
		// section is hidden ENTIRELY and no error is shown, because there is
		// nothing the site owner can do about it.
		if ( ! GuardLMS_Sdk_Status::is_section_visible( $sdk ) ) {
			return;
		}

		if ( ! GuardLMS_Connect_Manager::is_connected() ) {
			return;
		}

		$sdk_key   = GuardLMS_Credentials::get_sdk_key();
		$reporting = (bool) GuardLMS_Options::get( 'enabled' );
		$headline  = GuardLMS_Sdk_Status::headline( $sdk, $sdk_key, $reporting );
		$injecting = GuardLMS_Sdk_Status::should_inject( $sdk, $sdk_key, $reporting );
		?>
		<hr>
		<h2><?php esc_html_e( 'Real-time monitoring', 'guardlms' ); ?></h2>
		<p>
			<?php esc_html_e( 'Catches JavaScript errors in your visitors\' browsers and reports them to GuardLMS, so a broken page is visible to you before anyone reports it.', 'guardlms' ); ?>
		</p>

		<?php
		self::render_headline( $headline, $sdk, $injecting );
		self::render_advisories( $sdk );
		self::render_form( $sdk, $headline );
		self::render_buttons( $sdk_key, $injecting );
	}

	/**
	 * Render exactly ONE headline sentence, chosen by the §5.3 precedence chain.
	 *
	 * @param string $headline  One of the GuardLMS_Sdk_Status::STATE_* constants.
	 * @param array  $sdk       Effective real-time configuration.
	 * @param bool   $injecting Whether the front end is actually injecting the SDK.
	 * @return void
	 */
	private static function render_headline( string $headline, array $sdk, bool $injecting ): void {
		if ( GuardLMS_Sdk_Status::STATE_OK === $headline ) {
			if ( empty( $sdk['enabled'] ) ) {
				self::notice(
					'info',
					__( 'Real-time monitoring is ready. Switch it on below to start collecting browser errors.', 'guardlms' )
				);
				return;
			}

			// Belt to the status chain's braces. The chain already guarantees
			// STATE_OK implies injection, but this is the one sentence a site
			// owner will trust without checking, so it is gated on the predicate
			// itself rather than on a state that is supposed to imply it.
			if ( ! $injecting ) {
				self::notice(
					'warning',
					__( 'Real-time monitoring is switched on but is not running on this site. Use Refresh now, and check that GuardLMS reporting is enabled.', 'guardlms' )
				);
				return;
			}

			self::notice( 'success', __( 'Real-time monitoring is active on this site.', 'guardlms' ) );
			return;
		}

		if ( GuardLMS_Sdk_Status::STATE_REPORTING_OFF === $headline ) {
			self::notice(
				'warning',
				__( 'GuardLMS reporting is switched off for this site, so real-time monitoring is not running. Re-enable it in the advanced settings.', 'guardlms' )
			);
			return;
		}

		if ( GuardLMS_Sdk_Status::STATE_NOT_CONFIGURED === $headline ) {
			self::notice(
				'warning',
				__( 'Real-time monitoring is not fully configured yet, so nothing is being collected. Use Refresh now to fetch the settings again.', 'guardlms' )
			);
			return;
		}

		if ( GuardLMS_Sdk_Status::STATE_DASHBOARD_OFF === $headline ) {
			self::notice(
				'warning',
				__( 'Real-time monitoring is turned off in the GuardLMS dashboard.', 'guardlms' )
			);
			return;
		}

		if ( GuardLMS_Sdk_Status::STATE_NO_SUBSCRIPTION === $headline ) {
			self::notice(
				'warning',
				__( 'No active GuardLMS subscription - real-time data is not being collected.', 'guardlms' )
			);
			return;
		}

		if ( GuardLMS_Sdk_Status::STATE_REFRESH_FAILED === $headline ) {
			self::render_refresh_failed( $sdk );
			return;
		}

		if ( GuardLMS_Sdk_Status::STATE_NO_KEY === $headline ) {
			self::notice(
				'warning',
				__( 'Real-time settings have not been fetched yet. Use Refresh now to fetch them.', 'guardlms' )
			);
		}
	}

	/**
	 * Render §5.3 row 7: the failure message plus the last SUCCESSFUL refresh.
	 *
	 * @param array $sdk Effective real-time configuration.
	 * @return void
	 */
	private static function render_refresh_failed( array $sdk ): void {
		$refreshed = (int) $sdk['refreshed_at'];

		// Never render an epoch date or a blank: a site whose very first refresh
		// failed has no last-success timestamp, and "1 January 1970" reads as a
		// bug rather than as "this has never worked".
		$last = $refreshed > 0
			? sprintf(
				/* translators: %s: formatted date and time of the last successful refresh. */
				__( 'Last successful refresh: %s', 'guardlms' ),
				wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $refreshed )
			)
			: __( 'No successful refresh yet.', 'guardlms' );

		self::notice(
			'error',
			sprintf(
				/* translators: 1: the failure message from GuardLMS, 2: last successful refresh sentence. */
				__( 'Could not refresh the real-time settings: %1$s %2$s', 'guardlms' ),
				(string) $sdk['refresh_error'],
				$last
			)
		);
	}

	/**
	 * Render the non-exclusive advisories, in addition to the headline.
	 *
	 * @param array $sdk Effective real-time configuration.
	 * @return void
	 */
	private static function render_advisories( array $sdk ): void {
		$advisories = GuardLMS_Sdk_Status::advisories( $sdk );

		if ( in_array( GuardLMS_Sdk_Status::ADVISORY_NO_ANALYTICS, $advisories, true ) ) {
			$baseurl = rtrim( (string) GuardLMS_Options::get( 'baseurl' ), '/' );
			$message = __( 'Analytics is not included in your GuardLMS plan - error monitoring is still active.', 'guardlms' );

			if ( '' !== $baseurl ) {
				GuardLMS_Admin_Notice::render(
					'info',
					$message,
					array(
						'inline'        => true,
						'link_url'      => $baseurl . '/billing',
						'link_text'     => __( 'View plans', 'guardlms' ),
						'link_external' => true,
					)
				);
			} else {
				self::notice( 'info', $message );
			}
		}

		if ( in_array( GuardLMS_Sdk_Status::ADVISORY_DOMAIN_MISMATCH, $advisories, true ) ) {
			$allowed = is_array( $sdk['allowed_domains'] ) ? $sdk['allowed_domains'] : array();
			$host    = (string) wp_parse_url( home_url(), PHP_URL_HOST );

			if ( empty( $allowed ) ) {
				// A mismatch with nothing to name it against. Printing the usual
				// sentence here renders "only accepts data from ; this site
				// reports as example.com", which reads as a plugin bug and tells
				// the admin nothing about where to look.
				self::notice(
					'warning',
					sprintf(
						/* translators: %s: this site's hostname. */
						__( 'GuardLMS is not accepting data from %s. Check Allowed domains in the GuardLMS dashboard.', 'guardlms' ),
						$host
					)
				);
			} else {
				self::notice(
					'warning',
					sprintf(
						/* translators: 1: comma-separated allowed hostnames, 2: this site's hostname. */
						__( 'GuardLMS only accepts data from %1$s; this site reports as %2$s. Update Allowed domains in the GuardLMS dashboard.', 'guardlms' ),
						implode( ', ', $allowed ),
						$host
					)
				);
			}
		}
	}

	/**
	 * Render the two opt-in checkboxes and their save button.
	 *
	 * @param array  $sdk      Effective real-time configuration.
	 * @param string $headline The resolved headline state.
	 * @return void
	 */
	private static function render_form( array $sdk, string $headline ): void {
		$analytics_allowed = ! empty( $sdk['analytics_allowed'] );

		// Nothing to switch on before a key exists: offering the toggle would
		// let an admin enable a feature that provably cannot run yet.
		$can_enable = GuardLMS_Sdk_Status::STATE_NO_KEY !== $headline;
		?>
		<form action="options.php" method="post">
			<?php settings_fields( GuardLMS_Settings::GROUP ); ?>
			<table class="form-table" role="presentation">
				<tbody>
					<tr>
						<th scope="row"><?php esc_html_e( 'Error monitoring', 'guardlms' ); ?></th>
						<td>
							<input type="hidden" name="guardlms_settings[sdk][enabled]" value="0">
							<label>
								<input type="checkbox" name="guardlms_settings[sdk][enabled]" value="1"
									<?php checked( ! empty( $sdk['enabled'] ) ); ?>
									<?php disabled( ! $can_enable ); ?>>
								<?php esc_html_e( 'Report JavaScript errors from visitors\' browsers to GuardLMS.', 'guardlms' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Analytics', 'guardlms' ); ?></th>
						<td>
							<input type="hidden" name="guardlms_settings[sdk][analytics]" value="0">
							<label>
								<input type="checkbox" name="guardlms_settings[sdk][analytics]" value="1"
									<?php checked( ! empty( $sdk['analytics'] ) && $analytics_allowed ); ?>
									<?php disabled( ! $analytics_allowed || ! $can_enable ); ?>>
								<?php esc_html_e( 'Also collect anonymous page-view analytics.', 'guardlms' ); ?>
							</label>
							<p class="description">
								<?php esc_html_e( 'No visitor is identified: GuardLMS is never told who is logged in, and IP addresses are not collected by the script.', 'guardlms' ); ?>
							</p>
						</td>
					</tr>
				</tbody>
			</table>
			<?php submit_button( __( 'Save real-time settings', 'guardlms' ), 'primary', 'guardlms_sdk_save', false ); ?>
		</form>
		<?php
	}

	/**
	 * Render the Refresh / Send a test error / Replace SDK key buttons.
	 *
	 * @param string $sdk_key   The stored SDK key ('' when none).
	 * @param bool   $injecting Whether the front end is actually injecting the SDK.
	 * @return void
	 */
	private static function render_buttons( string $sdk_key, bool $injecting ): void {
		$action_url = admin_url( 'admin-post.php' );
		?>
		<div class="guardlms-actions">
			<form action="<?php echo esc_url( $action_url ); ?>" method="post">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::REFRESH_ACTION ); ?>">
				<?php
				wp_nonce_field( self::REFRESH_ACTION );
				submit_button( __( 'Refresh now', 'guardlms' ), 'secondary', 'guardlms_sdk_refresh_submit', false );
				?>
			</form>

			<?php if ( $injecting ) : ?>
				<?php // Offered only while the SDK is genuinely on the page: the probe's only failure message blames another plugin, which is a fabricated accusation whenever the plugin itself chose not to inject. ?>
				<form action="<?php echo esc_url( $action_url ); ?>" method="post">
					<input type="hidden" name="action" value="<?php echo esc_attr( self::SELFTEST_ACTION ); ?>">
					<?php
					wp_nonce_field( self::SELFTEST_ACTION );
					submit_button( __( 'Send a test error', 'guardlms' ), 'secondary', 'guardlms_sdk_selftest_submit', false );
					?>
				</form>
			<?php endif; ?>

			<?php if ( '' !== $sdk_key ) : ?>
				<form action="<?php echo esc_url( $action_url ); ?>" method="post"
					onsubmit="return confirm(<?php echo esc_attr( wp_json_encode( __( 'This replaces the key this site currently serves. Pages cached before the change keep sending the old key until the cache clears. Continue?', 'guardlms' ) ) ); ?>);">
					<input type="hidden" name="action" value="<?php echo esc_attr( self::ROTATE_ACTION ); ?>">
					<?php
					wp_nonce_field( self::ROTATE_ACTION );
					submit_button( __( 'Replace SDK key', 'guardlms' ), 'delete', 'guardlms_sdk_rotate_submit', false );
					?>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Handle the "Refresh now" admin-post action.
	 *
	 * @return void
	 */
	public static function handle_refresh(): void {
		self::guard( self::REFRESH_ACTION );

		// Take the bootstrap lock before refreshing. This handler redirects into
		// the settings page, whose render calls maybe_bootstrap(); without the
		// lock a site that has never refreshed successfully issues a SECOND 5s
		// request on the way back, so one click costs 10s against a dead backend.
		set_transient(
			GuardLMS_Sdk_Client::BOOTSTRAP_LOCK,
			1,
			GuardLMS_Sdk_Client::BOOTSTRAP_LOCK_TTL
		);

		$result = GuardLMS_Sdk_Client::resolve( 'fetch', GuardLMS_Sdk_Client::INTERACTIVE_TIMEOUT );

		if ( is_wp_error( $result ) ) {
			self::notify( 'error', $result->get_error_message() );
		} elseif ( GuardLMS_Sdk_Client::UNSUPPORTED === $result ) {
			// §5.3 row 2 raises no admin error, but a button click needs an
			// answer, so this one place says so plainly.
			self::notify( 'info', __( 'This GuardLMS installation does not support real-time monitoring yet.', 'guardlms' ) );
		} else {
			self::notify( 'success', __( 'Real-time settings refreshed.', 'guardlms' ) );
		}

		self::redirect_back();
	}

	/**
	 * Handle the "Send a test error" admin-post action.
	 *
	 * Redirects the admin to the site front page carrying the self-test flag.
	 * The probe then runs in that admin's own browser and reports there.
	 *
	 * @return void
	 */
	public static function handle_selftest(): void {
		self::guard( self::SELFTEST_ACTION );

		// Refuse when the SDK is not on the page. The probe can only report
		// "another plugin may be deferring or blocking it", which is a
		// fabricated accusation whenever the plugin itself chose not to inject -
		// and the real reason is already rendered above this button.
		$sdk = GuardLMS_Sdk_Config::all();
		if ( ! GuardLMS_Sdk_Status::should_inject( $sdk, GuardLMS_Credentials::get_sdk_key(), (bool) GuardLMS_Options::get( 'enabled' ) ) ) {
			self::notify(
				'warning',
				__( 'Real-time monitoring is not running on this site yet, so there is nothing to test. The status above explains why.', 'guardlms' )
			);
			self::redirect_back();
		}

		wp_safe_redirect(
			add_query_arg( array( GuardLMS_Sdk_Injector::SELFTEST_FLAG => 1 ), home_url( '/' ) )
		);
		exit;
	}

	/**
	 * Handle the "Replace SDK key" admin-post action.
	 *
	 * The ONLY path that sends `rotate`. Everything automatic sends `fetch`, so
	 * no bug or retry loop can churn this site's credential.
	 *
	 * @return void
	 */
	public static function handle_rotate(): void {
		self::guard( self::ROTATE_ACTION );

		$result = GuardLMS_Sdk_Client::resolve( 'rotate', GuardLMS_Sdk_Client::INTERACTIVE_TIMEOUT );

		if ( is_wp_error( $result ) ) {
			self::notify( 'error', $result->get_error_message() );
		} elseif ( GuardLMS_Sdk_Client::UNSUPPORTED === $result ) {
			self::notify( 'info', __( 'This GuardLMS installation does not support real-time monitoring yet.', 'guardlms' ) );
		} else {
			self::notify( 'success', __( 'A new SDK key was issued for this site.', 'guardlms' ) );
		}

		self::redirect_back();
	}

	/**
	 * Render (and clear) the one-shot real-time result notice, if present.
	 *
	 * @return void
	 */
	public static function render_notice(): void {
		$notice = get_transient( self::NOTICE_TRANSIENT );
		if ( false === $notice || ! is_array( $notice ) ) {
			return;
		}
		delete_transient( self::NOTICE_TRANSIENT );

		$type    = isset( $notice['type'] ) ? (string) $notice['type'] : 'success';
		$message = isset( $notice['message'] ) ? (string) $notice['message'] : '';

		GuardLMS_Admin_Notice::render( $type, $message, array( 'dismissible' => true ) );
	}

	/**
	 * Verify the nonce and capability for a real-time admin-post action.
	 *
	 * @param string $action The admin-post action / nonce name.
	 * @return void
	 */
	private static function guard( string $action ): void {
		check_admin_referer( $action );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'guardlms' ) );
		}
	}

	/**
	 * Queue the one-shot result notice for the next settings-page render.
	 *
	 * @param string $type    Notice type (success, error, warning, info).
	 * @param string $message Message to display.
	 * @return void
	 */
	private static function notify( string $type, string $message ): void {
		set_transient(
			self::NOTICE_TRANSIENT,
			array(
				'type'    => $type,
				'message' => $message,
			),
			MINUTE_IN_SECONDS
		);
	}

	/**
	 * Return the admin to the GuardLMS settings page.
	 *
	 * @return void
	 */
	private static function redirect_back(): void {
		wp_safe_redirect( GuardLMS_Settings::url() );
		exit;
	}

	/**
	 * Print an inline admin notice.
	 *
	 * @param string $type    Notice type (success, error, warning, info).
	 * @param string $message Message to display.
	 * @return void
	 */
	private static function notice( string $type, string $message ): void {
		GuardLMS_Admin_Notice::render( $type, $message, array( 'inline' => true ) );
	}
}
