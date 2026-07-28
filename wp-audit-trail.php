<?php
/**
 * Plugin Name:       WP Audit Trail
 * Plugin URI:        https://github.com/thealirazadev/wp-audit-trail
 * Description:       Tamper-evident audit logging for WordPress. Security-relevant events are recorded into a hash-chained, append-only table with a sealed head, so silent tampering is detectable.
 * Version:           0.1.0
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Author:            Ali Raza
 * Author URI:        https://github.com/thealirazadev
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wp-audit-trail
 * Domain Path:       /languages
 *
 * @package WP_Audit_Trail
 */

defined( 'ABSPATH' ) || exit;

define( 'WPAT_VERSION', '0.1.0' );
define( 'WPAT_PATH', plugin_dir_path( __FILE__ ) );
define( 'WPAT_URL', plugin_dir_url( __FILE__ ) );
define( 'WPAT_FILE', __FILE__ );

require_once WPAT_PATH . 'includes/wpat-functions.php';
require_once WPAT_PATH . 'includes/class-wpat-migrations.php';
require_once WPAT_PATH . 'includes/class-wpat-chain.php';
require_once WPAT_PATH . 'includes/class-wpat-recorder.php';
require_once WPAT_PATH . 'includes/class-wpat-plugin.php';

/**
 * Blocks activation on multisite and prepares the install.
 *
 * Multisite is a documented v1 non-goal: a network-wide chain versus per-site chains is an
 * unresolved design decision, and shipping either silently would produce a log whose
 * verification semantics nobody agreed to.
 *
 * @return void
 */
function wpat_activate() {
	if ( is_multisite() ) {
		deactivate_plugins( plugin_basename( WPAT_FILE ) );
		wp_die(
			esc_html__( 'WP Audit Trail does not support multisite in this version. The hash chain is per-site and a network-wide chain needs a design decision that has not been made yet. Activate it on a single-site install instead.', 'wp-audit-trail' ),
			esc_html__( 'Multisite is not supported', 'wp-audit-trail' ),
			array( 'back_link' => true )
		);
	}

	WPAT_Plugin::instance()->activate();
}
register_activation_hook( __FILE__, 'wpat_activate' );

/**
 * Cleans up scheduled work on deactivation. Logged data is left untouched.
 *
 * @return void
 */
function wpat_deactivate() {
	WPAT_Plugin::instance()->deactivate();
}
register_deactivation_hook( __FILE__, 'wpat_deactivate' );

WPAT_Plugin::instance()->boot();
