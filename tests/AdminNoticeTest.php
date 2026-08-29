<?php
/**
 * Tests for the shared admin notice renderer.
 *
 * Every notice the plugin prints goes through GuardLMS_Admin_Notice::render(),
 * so the markup, escaping and the optional trailing link are pinned down once
 * here rather than in each caller's test.
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
require_once GUARDLMS_PLUGIN_DIR . 'includes/admin/class-guardlms-admin-notice.php';

/**
 * @covers GuardLMS_Admin_Notice
 */
final class AdminNoticeTest extends AbstractGuardLMSTestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'esc_url' )->returnArg( 1 );
	}

	/**
	 * @param string $type    Notice type.
	 * @param string $message Message.
	 * @param array  $args    Renderer options.
	 * @return string
	 */
	private function render( string $type, string $message, array $args = array() ): string {
		ob_start();
		GuardLMS_Admin_Notice::render( $type, $message, $args );

		return (string) ob_get_clean();
	}

	public function test_a_plain_notice_is_neither_dismissible_nor_inline(): void {
		$html = $this->render( 'success', 'Saved.' );

		$this->assertSame( '<div class="notice notice-success"><p>Saved.</p></div>', $html );
	}

	public function test_the_message_is_html_escaped(): void {
		$html = $this->render( 'error', '<b>x</b> & y' );

		$this->assertStringContainsString( '&lt;b&gt;x&lt;/b&gt; &amp; y', $html );
		$this->assertStringNotContainsString( '<b>', $html );
	}

	public function test_dismissible_adds_the_core_class(): void {
		$html = $this->render( 'warning', 'Heads up.', array( 'dismissible' => true ) );

		$this->assertStringContainsString( 'class="notice notice-warning is-dismissible"', $html );
	}

	public function test_inline_adds_the_core_class(): void {
		$html = $this->render( 'info', 'FYI.', array( 'inline' => true ) );

		$this->assertStringContainsString( 'class="notice notice-info inline"', $html );
	}

	public function test_a_link_is_appended_after_the_message(): void {
		$html = $this->render(
			'warning',
			'Key expires soon.',
			array(
				'link_url'  => 'https://site.test/wp-admin/options-general.php?page=guardlms',
				'link_text' => 'Reconnect',
			)
		);

		$this->assertStringContainsString(
			'<p>Key expires soon. <a href="https://site.test/wp-admin/options-general.php?page=guardlms">Reconnect</a></p>',
			$html
		);
	}

	public function test_an_external_link_opens_in_a_new_tab_safely(): void {
		$html = $this->render(
			'info',
			'Not in your plan.',
			array(
				'link_url'      => 'https://dashboard.guardlms.com/billing',
				'link_text'     => 'View plans',
				'link_external' => true,
			)
		);

		$this->assertStringContainsString( 'target="_blank" rel="noopener noreferrer"', $html );
	}

	public function test_a_link_without_text_is_not_rendered(): void {
		$html = $this->render( 'info', 'Nothing to click.', array( 'link_url' => 'https://site.test/' ) );

		$this->assertStringNotContainsString( '<a ', $html );
	}

	public function test_an_unknown_type_falls_back_to_info(): void {
		$html = $this->render( 'bogus" onclick="x', 'Careful.' );

		$this->assertStringContainsString( 'class="notice notice-info"', $html );
		$this->assertStringNotContainsString( 'onclick', $html );
	}
}
