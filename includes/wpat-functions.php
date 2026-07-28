<?php
/**
 * Shared helper functions.
 *
 * @package WP_Audit_Trail
 */

defined( 'ABSPATH' ) || exit;

/**
 * Writes one structured log line to the PHP error log.
 *
 * Format is `wpat.{area}.{action} {json context}` so lines are greppable and machine-parsable.
 * Never pass secrets, payload bodies, or the chain key in the context.
 *
 * @param string $event_key Dotted key, for example `flush.spilled`.
 * @param array  $context   Context values; must be JSON encodable.
 * @return void
 */
function wpat_log( $event_key, array $context = array() ) {
	$json = wp_json_encode( $context );

	if ( false === $json ) {
		$json = '{"wpat_context_encode_failed":true}';
	}

	// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Structured logging is the documented error channel for this plugin.
	error_log( sprintf( 'wpat.%s %s', $event_key, $json ) );
}

/**
 * Returns the plugin settings merged over the documented defaults.
 *
 * @return array Settings array.
 */
function wpat_settings() {
	$defaults = array(
		'retention_days'   => 90,
		'digest_enabled'   => false,
		'digest_recipient' => get_option( 'admin_email' ),
		'scan_enabled'     => false,
		'scan_time_budget' => 20,
	);

	$stored = get_option( 'wpat_settings', array() );

	if ( ! is_array( $stored ) ) {
		$stored = array();
	}

	return array_merge( $defaults, $stored );
}

/**
 * Returns the client IP for the current request.
 *
 * Only `REMOTE_ADDR` is trusted. `X-Forwarded-For` is attacker-controlled, so sites behind a
 * reverse proxy must supply the real client IP through the `wpat_client_ip` filter instead.
 *
 * @return string IP address, or an empty string in CLI and cron contexts.
 */
function wpat_client_ip() {
	$ip = '';

	if ( isset( $_SERVER['REMOTE_ADDR'] ) ) {
		$candidate = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
		$valid     = filter_var( $candidate, FILTER_VALIDATE_IP );

		if ( false !== $valid ) {
			$ip = $valid;
		}
	}

	/**
	 * Filters the client IP recorded on audit events.
	 *
	 * @param string $ip Validated REMOTE_ADDR, or an empty string.
	 */
	$filtered = apply_filters( 'wpat_client_ip', $ip );

	return is_string( $filtered ) ? substr( $filtered, 0, 45 ) : '';
}
