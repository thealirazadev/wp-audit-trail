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
		add_action( 'admin_notices', array( $this, 'chain_key_notice' ) );

		WPAT_Recorder::instance()->boot();
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

		if ( ! WPAT_Chain::has_dedicated_key() ) {
			set_transient( 'wpat_chain_key_suggestion', WPAT_Chain::generate_key(), HOUR_IN_SECONDS );
		}
	}

	/**
	 * Shows the generated chain key once, right after activation.
	 *
	 * The value is deleted as soon as it has been displayed: it is a secret, and the rules forbid
	 * echoing it anywhere beyond this one-time notice.
	 *
	 * @return void
	 */
	public function chain_key_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$key = get_transient( 'wpat_chain_key_suggestion' );

		if ( ! is_string( $key ) || '' === $key ) {
			return;
		}

		delete_transient( 'wpat_chain_key_suggestion' );

		$line = sprintf( "define( 'WPAT_CHAIN_KEY', '%s' );", $key );

		echo '<div class="notice notice-warning"><p>';
		echo esc_html__( 'WP Audit Trail: add this line to wp-config.php so the audit chain seal does not depend on your WordPress salts. It is shown only once.', 'wp-audit-trail' );
		echo '</p><p><code>' . esc_html( $line ) . '</code></p><p>';
		echo esc_html__( 'Without it the plugin falls back to wp_salt( auth ), which works, but rotating salts will make verification report a seal failure.', 'wp-audit-trail' );
		echo '</p></div>';
	}

	/**
	 * Runs on deactivation. Never touches logged data.
	 *
	 * @return void
	 */
	public function deactivate() {
	}
}
