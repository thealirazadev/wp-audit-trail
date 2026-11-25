<?php
/**
 * Payload redaction.
 *
 * @package WP_Audit_Trail
 */

/**
 * Covers WPAT_Redactor.
 */
class WPAT_Test_Redactor extends WP_UnitTestCase {

	/**
	 * Removes any pattern filter a test added.
	 */
	public function tear_down() {
		remove_all_filters( 'wpat_redact_patterns' );
		parent::tear_down();
	}

	/**
	 * Secret-looking keys are masked at any depth while their keys survive.
	 */
	public function test_secret_keys_are_masked_at_any_depth() {
		$out = WPAT_Redactor::redact(
			array(
				'changed' => array(
					'user_pass'  => array(
						'before' => 'old-secret',
						'after'  => 'new-secret',
					),
					'user_email' => 'b@example.com',
				),
				'api_key' => 'abc123',
				'list'    => array( array( 'session_token' => 't0k3n' ) ),
			)
		);

		$this->assertSame( WPAT_Redactor::MASK, $out['changed']['user_pass'] );
		$this->assertSame( 'b@example.com', $out['changed']['user_email'] );
		$this->assertSame( WPAT_Redactor::MASK, $out['api_key'] );
		$this->assertSame( WPAT_Redactor::MASK, $out['list'][0]['session_token'] );
	}

	/**
	 * Every key family named in the docs is caught, in any case.
	 */
	public function test_documented_key_families_are_secret() {
		foreach ( array( 'password', 'PASSWORD', 'user_pass', 'pass', 'secret', 'client_secret', 'token', 'access_token', 'key', 'api_key', 'auth', 'auth_header', 'Authorization', 'cookie', 'wp_cookie', 'nonce', 'salt' ) as $key ) {
			$this->assertTrue( WPAT_Redactor::is_secret_key( $key ), $key );
		}
	}

	/**
	 * Ordinary fields that merely contain a short pattern word are left alone.
	 */
	public function test_ordinary_keys_are_kept() {
		foreach ( array( 'username', 'author', 'monkey', 'keyword', 'passage', 'user_email', 'roles', 0, 1 ) as $key ) {
			$this->assertFalse( WPAT_Redactor::is_secret_key( $key ), (string) $key );
		}

		$payload = array(
			'username' => 'bob',
			'author'   => 3,
		);
		$this->assertSame( $payload, WPAT_Redactor::redact( $payload ) );
	}

	/**
	 * Scalars and null pass through untouched.
	 */
	public function test_non_array_payloads_pass_through() {
		$this->assertNull( WPAT_Redactor::redact( null ) );
		$this->assertSame( 'plain', WPAT_Redactor::redact( 'plain' ) );
	}

	/**
	 * Objects are converted and redacted like arrays.
	 */
	public function test_objects_are_redacted() {
		$out = WPAT_Redactor::redact( (object) array( 'secret' => 'x' ) );

		$this->assertSame( array( 'secret' => WPAT_Redactor::MASK ), $out );
	}

	/**
	 * Nesting up to MAX_DEPTH is walked, and anything deeper is replaced wholesale.
	 */
	public function test_nesting_past_max_depth_is_masked() {
		$payload = 'leaf';
		for ( $i = 0; $i < WPAT_Redactor::MAX_DEPTH; $i++ ) {
			$payload = array( 'level' => $payload );
		}

		$this->assertSame( $payload, WPAT_Redactor::redact( $payload ) );

		$out = WPAT_Redactor::redact( array( 'level' => $payload ) );
		for ( $i = 0; $i < WPAT_Redactor::MAX_DEPTH; $i++ ) {
			$out = $out['level'];
		}

		$this->assertSame( WPAT_Redactor::MASK, $out );
	}

	/**
	 * The filter can add a pattern.
	 */
	public function test_filter_adds_patterns() {
		add_filter(
			'wpat_redact_patterns',
			static function ( $patterns ) {
				$patterns[] = '/^iban$/i';
				return $patterns;
			}
		);

		$this->assertTrue( WPAT_Redactor::is_secret_key( 'iban' ) );
		$this->assertTrue( WPAT_Redactor::is_secret_key( 'password' ) );
	}

	/**
	 * An invalid pattern counts as a match, so a broken filter cannot leak a value.
	 */
	public function test_invalid_pattern_fails_closed() {
		add_filter(
			'wpat_redact_patterns',
			static function () {
				return array( '/[unclosed/' );
			}
		);

		$this->assertTrue( WPAT_Redactor::is_secret_key( 'username' ) );
	}

	/**
	 * A non-array filter result falls back to the defaults.
	 */
	public function test_non_array_filter_result_uses_defaults() {
		add_filter( 'wpat_redact_patterns', '__return_false' );

		$this->assertSame( WPAT_Redactor::DEFAULT_PATTERNS, WPAT_Redactor::patterns() );
	}
}
