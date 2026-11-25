<?php
/**
 * Payload redaction.
 *
 * @package WP_Audit_Trail
 */

defined( 'ABSPATH' ) || exit;

/**
 * Masks secret-looking values in an event payload before it is chained.
 *
 * A chained row can never be edited without breaking verification, so a secret that reaches the
 * log stays there for as long as the row does. Redaction therefore runs on every payload inside
 * the recorder, not in each listener, and it fails closed: a pattern that cannot be evaluated
 * counts as a match.
 */
class WPAT_Redactor {

	/**
	 * Replacement written in place of a redacted value.
	 */
	const MASK = '[redacted]';

	/**
	 * Deepest nesting level walked. Anything below it is replaced wholesale.
	 */
	const MAX_DEPTH = 16;

	/**
	 * Default key patterns.
	 *
	 * The first matches anywhere in the key, for words that are unambiguous on their own. The
	 * second matches only a whole segment of the key (split on `_`, `-`, `.`, or a space), so
	 * `user_pass`, `api_key`, and `auth` are redacted while `author` and `monkey` are not.
	 *
	 * @var string[]
	 */
	const DEFAULT_PATTERNS = array(
		'/password|passwd|passphrase|secret|token|apikey|authorization|cookie|nonce|salt/i',
		'/(?:^|[_\-.\s])(?:pass|pwd|key|auth)(?:$|[_\-.\s])/i',
	);

	/**
	 * Returns the active key patterns.
	 *
	 * @return string[] Regular expressions matched against payload keys.
	 */
	public static function patterns() {
		/**
		 * Filters the regular expressions that mark a payload key as secret.
		 *
		 * Add patterns rather than replacing the list: removing a default can let a secret into
		 * an append-only log. A non-array return value falls back to the defaults.
		 *
		 * @param string[] $patterns Regular expressions matched against payload keys.
		 */
		$patterns = apply_filters( 'wpat_redact_patterns', self::DEFAULT_PATTERNS );

		if ( ! is_array( $patterns ) ) {
			return self::DEFAULT_PATTERNS;
		}

		return array_values( array_filter( $patterns, 'is_string' ) );
	}

	/**
	 * Whether a payload key names a secret.
	 *
	 * @param string|int $key Payload key.
	 * @return bool True when the value under this key must be masked.
	 */
	public static function is_secret_key( $key ) {
		if ( is_int( $key ) ) {
			return false;
		}

		foreach ( self::patterns() as $pattern ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A broken filtered pattern must not emit warnings mid-request; it is treated as a match below.
			$matched = @preg_match( $pattern, (string) $key );

			// False means the pattern itself is invalid; fail closed.
			if ( 1 === $matched || false === $matched ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Returns the payload with every value under a secret-looking key replaced by the mask.
	 *
	 * Keys are kept, so the log still records that a secret field was present or changed. Lists
	 * and nested arrays are walked; objects are converted to arrays first.
	 *
	 * @param mixed $payload Event payload.
	 * @param int   $depth   Current depth, for recursion.
	 * @return mixed Redacted payload.
	 */
	public static function redact( $payload, $depth = 0 ) {
		if ( is_object( $payload ) ) {
			$decoded = json_decode( (string) wp_json_encode( $payload ), true );
			$payload = is_array( $decoded ) ? $decoded : self::MASK;
		}

		if ( ! is_array( $payload ) ) {
			return $payload;
		}

		if ( $depth >= self::MAX_DEPTH ) {
			return self::MASK;
		}

		$out = array();

		foreach ( $payload as $key => $value ) {
			$out[ $key ] = self::is_secret_key( $key ) ? self::MASK : self::redact( $value, $depth + 1 );
		}

		return $out;
	}
}
