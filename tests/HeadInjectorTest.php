<?php
/**
 * Unit tests for GuardLMS_Head_Injector::meta_tag() (AC8: ownership meta tag
 * rendered only when enabled AND a verification token is present; escaped).
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
require_once GUARDLMS_PLUGIN_DIR . 'includes/class-guardlms-head-injector.php';

/**
 * @covers GuardLMS_Head_Injector
 */
final class HeadInjectorTest extends AbstractGuardLMSTestCase {

	/**
	 * @param array $settings Stored guardlms_settings value.
	 * @return void
	 */
	private function stubSettings( array $settings ): void {
		Functions\when( 'get_option' )->justReturn( $settings );
	}

	public function test_empty_when_disabled(): void {
		$this->stubSettings(
			array(
				'enabled'           => false,
				'verificationtoken' => 'has-a-token',
			)
		);

		$this->assertSame( '', GuardLMS_Head_Injector::meta_tag() );
	}

	public function test_empty_when_enabled_but_no_token(): void {
		$this->stubSettings(
			array(
				'enabled'           => true,
				'verificationtoken' => '',
			)
		);

		$this->assertSame( '', GuardLMS_Head_Injector::meta_tag() );
	}

	public function test_empty_when_token_is_only_whitespace(): void {
		$this->stubSettings(
			array(
				'enabled'           => true,
				'verificationtoken' => '   ',
			)
		);

		$this->assertSame( '', GuardLMS_Head_Injector::meta_tag() );
	}

	public function test_renders_meta_tag_when_enabled_and_token_present(): void {
		$this->stubSettings(
			array(
				'enabled'           => true,
				'verificationtoken' => 'abc123token',
			)
		);

		$this->assertSame(
			'<meta name="guardlms-verification" content="abc123token">' . "\n",
			GuardLMS_Head_Injector::meta_tag()
		);
	}

	public function test_token_is_attribute_escaped(): void {
		$this->stubSettings(
			array(
				'enabled'           => true,
				'verificationtoken' => 'a"><script>x',
			)
		);

		$tag = GuardLMS_Head_Injector::meta_tag();

		// The raw quote/angle-bracket must not appear unescaped in the attribute.
		$this->assertStringNotContainsString( '"><script>', $tag );
		$this->assertStringContainsString( '&quot;', $tag );
		$this->assertStringContainsString( '&lt;script&gt;', $tag );
	}

	public function test_render_echoes_the_meta_tag(): void {
		$this->stubSettings(
			array(
				'enabled'           => true,
				'verificationtoken' => 'echoed-token',
			)
		);
		Functions\when( 'wp_kses' )->returnArg( 1 );

		ob_start();
		GuardLMS_Head_Injector::render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'guardlms-verification', $output );
		$this->assertStringContainsString( 'echoed-token', $output );
	}
}
