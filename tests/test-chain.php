<?php
/**
 * Canonical serialization, hashing, and the sealed head.
 *
 * @package WP_Audit_Trail
 */

/**
 * Covers WPAT_Chain.
 */
class WPAT_Test_Chain extends WP_UnitTestCase {

	/**
	 * A fixed event whose canonical form is frozen by this test file.
	 *
	 * @var array
	 */
	private $golden_event = array(
		'occurred_at'  => '2026-07-27 09:12:44',
		'actor_id'     => 3,
		'actor_login'  => 'editor_jane',
		'actor_ip'     => '203.0.113.9',
		'actor_ua'     => 'Mozilla/5.0 (X11)',
		'event_type'   => 'auth.login',
		'severity'     => 1,
		'object_type'  => 'user',
		'object_id'    => '3',
		'object_label' => 'editor_jane',
		'summary'      => 'editor_jane logged in',
		'payload'      => null,
	);

	/**
	 * The exact canonical JSON for the golden event, written out by hand.
	 *
	 * @var string
	 */
	private $golden_json = '{"occurred_at":"2026-07-27 09:12:44","actor_id":3,"actor_login":"editor_jane","actor_ip":"203.0.113.9","actor_ua":"Mozilla/5.0 (X11)","event_type":"auth.login","severity":1,"object_type":"user","object_id":"3","object_label":"editor_jane","summary":"editor_jane logged in","payload":null}';

	/**
	 * Genesis is 64 zeros.
	 */
	public function test_genesis_is_sixty_four_zeros() {
		$this->assertSame( str_repeat( '0', 64 ), WPAT_Chain::GENESIS );
	}

	/**
	 * Field order, casts, and JSON flags are frozen.
	 */
	public function test_canonical_json_matches_the_golden_vector() {
		$this->assertSame( $this->golden_json, WPAT_Chain::canonical_json( $this->golden_event ) );
	}

	/**
	 * The entry hash is sha256 over prev_hash, a pipe, and the canonical form.
	 */
	public function test_entry_hash_matches_an_independently_computed_hash() {
		$expected = hash( 'sha256', WPAT_Chain::GENESIS . '|' . $this->golden_json );

		$this->assertSame( $expected, WPAT_Chain::entry_hash( WPAT_Chain::GENESIS, $this->golden_event ) );
	}

	/**
	 * A database row (all columns as strings) canonicalizes identically to the PHP event.
	 */
	public function test_canonical_json_is_stable_across_string_and_int_values() {
		$as_row              = $this->golden_event;
		$as_row['actor_id']  = '3';
		$as_row['severity']  = '1';
		$as_row['payload']   = null;
		$as_row['id']        = '17';
		$as_row['prev_hash'] = WPAT_Chain::GENESIS;

		$this->assertSame( $this->golden_json, WPAT_Chain::canonical_json( $as_row ) );
	}

	/**
	 * An empty payload column and a null payload are the same canonical value.
	 */
	public function test_empty_payload_canonicalizes_as_null() {
		$event            = $this->golden_event;
		$event['payload'] = '';

		$this->assertSame( $this->golden_json, WPAT_Chain::canonical_json( $event ) );
	}

	/**
	 * Floats cannot be chained because their encoding is not byte-stable.
	 */
	public function test_floats_are_rejected() {
		$event             = $this->golden_event;
		$event['actor_ip'] = 1.5;

		$result = WPAT_Chain::canonical_json( $event );

		$this->assertWPError( $result );
		$this->assertSame( 'wpat_invalid_input', $result->get_error_code() );
	}

	/**
	 * Payloads reach the chain already encoded, never as arrays.
	 */
	public function test_arrays_are_rejected() {
		$event            = $this->golden_event;
		$event['payload'] = array( 'username' => 'admin' );

		$this->assertWPError( WPAT_Chain::canonical_json( $event ) );
	}

	/**
	 * A freshly built head verifies.
	 */
	public function test_built_head_verifies() {
		$head = WPAT_Chain::build_head( 42, str_repeat( 'a', 64 ), 42 );

		$this->assertTrue( WPAT_Chain::verify_head( $head ) );
		$this->assertSame( 42, $head['last_id'] );
		$this->assertSame( 42, $head['entry_count'] );
	}

	/**
	 * Editing any sealed field invalidates the signature.
	 */
	public function test_tampered_head_fails_verification() {
		$head = WPAT_Chain::build_head( 42, str_repeat( 'a', 64 ), 42 );

		$moved_id                   = $head;
		$moved_id['last_id']        = 41;
		$moved_hash                 = $head;
		$moved_hash['last_hash']    = str_repeat( 'b', 64 );
		$moved_count                = $head;
		$moved_count['entry_count'] = 41;
		$moved_sig                  = $head;
		$moved_sig['sig']           = str_repeat( '0', 64 );

		$this->assertFalse( WPAT_Chain::verify_head( $moved_id ) );
		$this->assertFalse( WPAT_Chain::verify_head( $moved_hash ) );
		$this->assertFalse( WPAT_Chain::verify_head( $moved_count ) );
		$this->assertFalse( WPAT_Chain::verify_head( $moved_sig ) );
		$this->assertFalse( WPAT_Chain::verify_head( 'not an array' ) );
	}

	/**
	 * The signing key comes from the constant when defined, and from the auth salt otherwise.
	 */
	public function test_chain_key_resolution_order() {
		if ( WPAT_Chain::has_dedicated_key() ) {
			$this->assertSame( WPAT_CHAIN_KEY, WPAT_Chain::chain_key() );
			return;
		}

		$this->assertSame( wp_salt( 'auth' ), WPAT_Chain::chain_key() );
	}

	/**
	 * The suggested key is 256 bits of hex.
	 */
	public function test_generated_key_shape() {
		$key = WPAT_Chain::generate_key();

		$this->assertSame( 64, strlen( $key ) );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $key );
		$this->assertNotSame( $key, WPAT_Chain::generate_key() );
	}
}
