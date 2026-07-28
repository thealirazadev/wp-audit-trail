<?php
/**
 * Plugin orchestrator.
 *
 * @package WP_Audit_Trail
 */

defined( 'ABSPATH' ) || exit;

/**
 * Wires the plugin's parts together and owns the activation lifecycle.
 */
class WPAT_Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var WPAT_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Whether boot() has already run.
	 *
	 * @var bool
	 */
	private $booted = false;

	/**
	 * Returns the shared instance.
	 *
	 * @return WPAT_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Registers runtime hooks. Safe to call more than once.
	 *
	 * @return void
	 */
	public function boot() {
		if ( $this->booted ) {
			return;
		}

		$this->booted = true;

		add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );
		add_action( 'plugins_loaded', array( 'WPAT_Migrations', 'run' ) );
	}

	/**
	 * Loads the plugin text domain.
	 *
	 * @return void
	 */
	public function load_textdomain() {
		load_plugin_textdomain( 'wp-audit-trail', false, dirname( plugin_basename( WPAT_FILE ) ) . '/languages' );
	}

	/**
	 * Runs on activation.
	 *
	 * @return void
	 */
	public function activate() {
		add_option( 'wpat_dropped_events', 0 );
		WPAT_Migrations::run();
	}

	/**
	 * Runs on deactivation. Never touches logged data.
	 *
	 * @return void
	 */
	public function deactivate() {
	}
}
