<?php
/**
 * Plugin Name:       GuardLMS
 * Plugin URI:        https://app.guardlms.com
 * Description:       Reports this site's WordPress core, plugin, theme and environment inventory to GuardLMS for CVE and security monitoring.
 * Version:           0.1.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            LdesignMedia
 * Author URI:        https://ldesignmedia.nl
 * License:           GPL-3.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:       guardlms
 * Domain Path:       /languages
 *
 * @package    GuardLMS
 * @copyright  2026 Luuk Verhoeven, ldesignmedia.nl <info@ldesignmedia.nl>
 * @author     Hamza Tamyachte
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
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

defined( 'ABSPATH' ) || exit;

// Plugin version and paths.
define( 'GUARDLMS_VERSION', '0.1.0' );
define( 'GUARDLMS_PLUGIN_FILE', __FILE__ );
define( 'GUARDLMS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'GUARDLMS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// GuardLMS service defaults (SSRF-pinned; override is explicit opt-in and https-only).
define( 'GUARDLMS_DEFAULT_BASEURL', 'https://app.guardlms.com' );
define( 'GUARDLMS_DEFAULT_PUSHPATH', '/api/externalpush/wordpress' );

// WP-Cron hook names.
define( 'GUARDLMS_DAILY_HOOK', 'guardlms_daily_push' );
define( 'GUARDLMS_INITIAL_HOOK', 'guardlms_initial_push' );

require_once GUARDLMS_PLUGIN_DIR . 'includes/class-guardlms-plugin.php';

register_activation_hook( GUARDLMS_PLUGIN_FILE, array( 'GuardLMS_Plugin', 'activate' ) );
register_deactivation_hook( GUARDLMS_PLUGIN_FILE, array( 'GuardLMS_Plugin', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'GuardLMS_Plugin', 'boot' ) );
