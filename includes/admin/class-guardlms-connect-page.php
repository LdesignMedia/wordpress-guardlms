<?php
/**
 * GuardLMS admin Connect page (one-click keyless connect / disconnect).
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
 * Renders the connect block on the Settings -> GuardLMS page and handles its
 * admin-post actions. Connecting redirects to the external GuardLMS consent
 * screen; disconnecting clears the stored credentials.
 *
 * There is no separate menu entry: GuardLMS_Settings::render_page() calls
 * render_status() and render_buttons(), so a site owner sees one page with one
 * button.
 */
class GuardLMS_Connect_Page {

	/**
	 * Admin page slug the connect actions return to. Same page as
	 * GuardLMS_Settings::PAGE, kept literal so this class stays loadable on its own.
	 *
	 * @var string
	 */
	const PAGE = 'guardlms';

	/**
	 * admin-post action that starts a connect attempt.
	 *
	 * @var string
	 */
	const START_ACTION = 'guardlms_connect_start';

	/**
	 * admin-post action that disconnects the site.
	 *
	 * @var string
	 */
	const DISCONNECT_ACTION = 'guardlms_disconnect';

	/**
	 * Transient key holding the one-shot connect result notice.
	 *
	 * @var string
	 */
	const NOTICE_TRANSIENT = 'guardlms_connect_notice';

	/**
	 * Render the connection status: either the connected details table or the
	 * short "what happens when you connect" intro.
	 *
	 * @return void
	 */
	public static function render_status(): void {
		$connected = GuardLMS_Connect_Manager::is_connected();
		// Holding a key is not the same as having a working connection. When
		// GuardLMS has refused that key, "Connected" is the single most
		// misleading thing this page can say, so the refused state wins.
		$rejected = $connected && GuardLMS_Connect_Manager::is_auth_rejected();

		if ( $rejected ) {
			$badge_class = 'guardlms-badge-rejected';
			$badge_text  = __( 'Reconnect required', 'guardlms' );
		} elseif ( $connected ) {
			$badge_class = 'guardlms-badge-connected';
			$badge_text  = __( 'Connected', 'guardlms' );
		} else {
			$badge_class = 'guardlms-badge-disconnected';
			$badge_text  = __( 'Not connected', 'guardlms' );
		}
		?>
		<div>
			<p class="guardlms-status">
				<strong><?php esc_html_e( 'Status:', 'guardlms' ); ?></strong>
				<span class="guardlms-badge <?php echo esc_attr( $badge_class ); ?>">
					<?php echo esc_html( $badge_text ); ?>
				</span>
			</p>

			<?php if ( $rejected ) : ?>
				<div class="notice notice-error inline">
					<p>
						<?php
						esc_html_e(
							'GuardLMS no longer accepts this site\'s connection key. The key was revoked, or the website it belonged to was removed from the GuardLMS dashboard. This site has stopped reporting: use Reconnect below to issue a new key.',
							'guardlms'
						);
						?>
					</p>
					<?php
					$rejected_at = GuardLMS_Connect_Manager::auth_rejected_at();
					if ( $rejected_at > 0 ) :
						?>
						<p>
							<?php
							printf(
								/* translators: %s: formatted date and time. */
								esc_html__( 'First refused: %s', 'guardlms' ),
								esc_html(
									wp_date(
										get_option( 'date_format' ) . ' ' . get_option( 'time_format' ),
										$rejected_at
									)
								)
							);
							?>
						</p>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<?php if ( ! $connected ) : ?>
				<p>
					<?php esc_html_e( 'Connect this site to GuardLMS to start security monitoring. You will be sent to GuardLMS to sign in or create a free account, then returned here automatically, with no API key to copy.', 'guardlms' ); ?>
				</p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render the connection dates. Rendered under the buttons: the action comes
	 * first, the dates are reference information.
	 *
	 * @return void
	 */
	public static function render_details(): void {
		if ( ! GuardLMS_Connect_Manager::is_connected() ) {
			return;
		}

		$connectat = (int) GuardLMS_Options::get( 'connectedat' );
		$expiresat = (int) GuardLMS_Options::get( 'keyexpiresat' );
		$lastpush  = (int) GuardLMS_Options::get( 'lastpush' );
		$format    = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
		?>
		<table class="widefat striped" style="max-width:640px;margin-top:1em">
			<tbody>
				<tr>
					<th scope="row"><?php esc_html_e( 'Connected at', 'guardlms' ); ?></th>
					<td>
						<?php
						echo $connectat > 0
							? esc_html( wp_date( $format, $connectat ) )
							: esc_html__( 'Unknown', 'guardlms' );
						?>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Expires at', 'guardlms' ); ?></th>
					<td>
						<?php
						echo $expiresat > 0
							? esc_html( wp_date( $format, $expiresat ) )
							: esc_html__( 'Unknown', 'guardlms' );
						?>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Last push', 'guardlms' ); ?></th>
					<td>
						<?php
						echo $lastpush > 0
							? esc_html( wp_date( $format, $lastpush ) )
							: esc_html__( 'No successful push yet.', 'guardlms' );
						?>
					</td>
				</tr>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Render the Connect / Reconnect button, plus Disconnect when connected.
	 *
	 * @return void
	 */
	public static function render_buttons(): void {
		$connected = GuardLMS_Connect_Manager::is_connected();
		?>
			<div style="margin-top:1em">
				<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post" style="display:inline-block">
					<input type="hidden" name="action" value="<?php echo esc_attr( self::START_ACTION ); ?>">
					<?php
					wp_nonce_field( self::START_ACTION );
					submit_button(
						$connected ? __( 'Reconnect', 'guardlms' ) : __( 'Connect', 'guardlms' ),
						'primary',
						'guardlms_connect_start_submit',
						false
					);
					?>
				</form>

				<?php if ( $connected ) : ?>
					<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post" style="display:inline-block;margin-left:8px">
						<input type="hidden" name="action" value="<?php echo esc_attr( self::DISCONNECT_ACTION ); ?>">
						<?php
						wp_nonce_field( self::DISCONNECT_ACTION );
						submit_button(
							__( 'Disconnect', 'guardlms' ),
							'delete',
							'guardlms_disconnect_submit',
							false
						);
						?>
					</form>
				<?php endif; ?>
			</div>
		<?php
	}

	/**
	 * Handle the "Connect"/"Reconnect" admin-post action.
	 *
	 * Hooked to `admin_post_guardlms_connect_start`. Redirects to the external
	 * GuardLMS consent URL, so wp_redirect() (not wp_safe_redirect) is required.
	 *
	 * @return void
	 */
	public static function handle_start(): void {
		self::guard( self::START_ACTION );

		$consent_url = GuardLMS_Connect_Manager::start_connect();

		// Deliberately wp_redirect(): the target is the external GuardLMS consent
		// host, which wp_safe_redirect() would reject as an off-site host.
		wp_redirect( $consent_url ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- External GuardLMS consent URL by design; see class docblock.
		exit;
	}

	/**
	 * Handle the "Disconnect" admin-post action.
	 *
	 * Hooked to `admin_post_guardlms_disconnect`.
	 *
	 * @return void
	 */
	public static function handle_disconnect(): void {
		self::guard( self::DISCONNECT_ACTION );

		GuardLMS_Connect_Manager::disconnect();

		set_transient(
			self::NOTICE_TRANSIENT,
			array(
				'type'    => 'success',
				'message' => __( 'Disconnected from GuardLMS.', 'guardlms' ),
			),
			MINUTE_IN_SECONDS
		);

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'             => self::PAGE,
					'guardlms_connect' => 'disconnected',
				),
				admin_url( 'options-general.php' )
			)
		);
		exit;
	}

	/**
	 * Verify the nonce for an admin-post action and the caller's capability.
	 *
	 * Dies on a missing/invalid nonce (via check_admin_referer) or when the user
	 * lacks manage_options. Shared by the connect/disconnect handlers.
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
	 * Render (and clear) the one-shot connect result notice, if present.
	 *
	 * Called by GuardLMS_Settings::render_page(), which owns the admin page.
	 *
	 * @return void
	 */
	public static function render_notice(): void {
		$notice = get_transient( self::NOTICE_TRANSIENT );
		if ( false === $notice || ! is_array( $notice ) ) {
			return;
		}
		delete_transient( self::NOTICE_TRANSIENT );

		$class   = ( isset( $notice['type'] ) && 'error' === $notice['type'] ) ? 'notice-error' : 'notice-success';
		$message = isset( $notice['message'] ) ? (string) $notice['message'] : '';

		printf(
			'<div class="notice %1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( $class ),
			esc_html( $message )
		);
	}
}
