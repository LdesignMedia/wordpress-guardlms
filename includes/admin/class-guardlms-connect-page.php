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
 * Renders the Settings -> GuardLMS Connect page and handles its admin-post
 * actions. Connecting redirects to the external GuardLMS consent screen;
 * disconnecting clears the stored credentials.
 */
class GuardLMS_Connect_Page {

	/**
	 * Admin page slug (submenu under Settings).
	 *
	 * @var string
	 */
	const PAGE = 'guardlms-connect';

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
	 * Register the Connect submenu page. Hooked to `admin_menu`.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_submenu_page(
			'options-general.php',
			__( 'GuardLMS Connect', 'guardlms' ),
			__( 'GuardLMS Connect', 'guardlms' ),
			'manage_options',
			self::PAGE,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Render the Connect page: connection status and connect/disconnect buttons.
	 *
	 * @return void
	 */
	public static function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'guardlms' ) );
		}

		self::render_notice();

		$connected = GuardLMS_Connect_Manager::is_connected();
		$websiteid = (int) GuardLMS_Options::get( 'websiteid' );
		$connectat = (int) GuardLMS_Options::get( 'connectedat' );
		$expiresat = (int) GuardLMS_Options::get( 'keyexpiresat' );
		$lastpush  = (int) GuardLMS_Options::get( 'lastpush' );
		$format    = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

			<?php if ( $connected ) : ?>
				<p>
					<strong><?php esc_html_e( 'Status:', 'guardlms' ); ?></strong>
					<?php esc_html_e( 'Connected to GuardLMS.', 'guardlms' ); ?>
				</p>
				<table class="widefat striped" style="max-width:640px">
					<tbody>
						<tr>
							<th scope="row"><?php esc_html_e( 'Website ID', 'guardlms' ); ?></th>
							<td>
								<?php
								echo $websiteid > 0
									? esc_html( (string) $websiteid )
									: esc_html__( 'Unknown', 'guardlms' );
								?>
							</td>
						</tr>
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
							<th scope="row"><?php esc_html_e( 'Key expires', 'guardlms' ); ?></th>
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
			<?php else : ?>
				<p>
					<strong><?php esc_html_e( 'Status:', 'guardlms' ); ?></strong>
					<?php esc_html_e( 'Not connected.', 'guardlms' ); ?>
				</p>
				<p>
					<?php esc_html_e( 'Connect this site to GuardLMS to start security monitoring. You will be sent to GuardLMS to sign in or create a free account, then returned here automatically — no API key to copy.', 'guardlms' ); ?>
				</p>
			<?php endif; ?>

			<div style="margin-top:1em">
				<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post" style="display:inline-block">
					<input type="hidden" name="action" value="<?php echo esc_attr( self::START_ACTION ); ?>">
					<?php
					wp_nonce_field( self::START_ACTION );
					submit_button(
						$connected ? __( 'Reconnect', 'guardlms' ) : __( 'Connect to GuardLMS', 'guardlms' ),
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
	 * @return void
	 */
	private static function render_notice(): void {
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
