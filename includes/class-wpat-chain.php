<?php
/**
 * Hash chain primitives.
 *
 * @package WP_Audit_Trail
 */

defined( 'ABSPATH' ) || exit;

/**
 * Canonical serialization and entry hashing for the audit chain.
 *
 * The canonical form is a compatibility contract with every row ever written. Changing the field
 * order, the casts, or the JSON flags invalidates verification of all existing history.
 */
class WPAT_Chain {

	/**
	 * The prev_hash of the first row in the chain.
	 */
	const GENESIS = '0000000000000000000000000000000000000000000000000000000000000000';

	/**
	 * Canonical field order. Frozen; see docs/rules.md.
	 *
	 * @var string[]
	 */
	const FIELDS = array(
		'occurred_at',
		'actor_id',
		'actor_login',
		'actor_ip',
		'actor_ua',
		'event_type',
		'severity',
		'object_type',
		'object_id',
		'object_label',
		'summary',
		'payload',
	);

	/**
	 * Fields serialized as JSON integers. Everything else is a string, except a null payload.
	 *
	 * @var string[]
	 */
	const INT_FIELDS = array( 'actor_id', 'severity' );

	/**
	 * Serializes an event into its canonical JSON form.
	 *
	 * Values are cast explicitly because the same event is serialized twice in its lifetime: once
	 * from PHP values at insert, and once from a database row where $wpdb returns every column as
	 * a string. Without the casts those two serializations would differ and verification of an
	 * untouched chain would fail. `payload` is the already-encoded JSON string, embedded as a
	 * string, so the canonical form never depends on re-encoding it.
	 *
	 * @param array $event Event fields; missing fields are treated as empty.
	 * @return string|WP_Error Canonical JSON, or an error when a value cannot be canonicalized.
	 */
	public static function canonical_json( array $event ) {
		$ordered = array();

		foreach ( self::FIELDS as $field ) {
			$value = array_key_exists( $field, $event ) ? $event[ $field ] : null;

			if ( is_float( $value ) ) {
				// Float encoding is not byte-stable across PHP versions, so it can never be chained.
				return new WP_Error(
					'wpat_invalid_input',
					sprintf( 'Audit event field %s is a float, which cannot be canonicalized.', $field )
				);
			}

			if ( is_array( $value ) || is_object( $value ) ) {
				return new WP_Error(
					'wpat_invalid_input',
					sprintf( 'Audit event field %s must be a scalar or null.', $field )
				);
			}

			if ( in_array( $field, self::INT_FIELDS, true ) ) {
				$ordered[ $field ] = (int) $value;
				continue;
			}

			if ( 'payload' === $field ) {
				$ordered[ $field ] = ( null === $value || '' === $value ) ? null : (string) $value;
				continue;
			}

			$ordered[ $field ] = (string) $value;
		}

		$json = wp_json_encode( $ordered, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

		if ( false === $json ) {
			return new WP_Error( 'wpat_invalid_input', 'Audit event could not be JSON encoded.' );
		}

		return $json;
	}

	/**
	 * Computes the entry hash linking an event to its predecessor.
	 *
	 * @param string $prev_hash entry_hash of the previous row, or self::GENESIS.
	 * @param array  $event     Event fields.
	 * @return string|WP_Error 64-character lowercase hex hash, or an error from canonicalization.
	 */
	public static function entry_hash( $prev_hash, array $event ) {
		$canonical = self::canonical_json( $event );

		if ( is_wp_error( $canonical ) ) {
			return $canonical;
		}

		return hash( 'sha256', $prev_hash . '|' . $canonical );
	}
}
