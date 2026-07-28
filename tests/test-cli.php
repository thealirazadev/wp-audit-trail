<?php
/**
 * WP-CLI verify command behavior.
 *
 * @package WP_Audit_Trail
 */

/**
 * Covers WPAT_Cli through its output shim, without a WP-CLI harness.
 */
class WPAT_Test_Cli extends WP_UnitTestCase {

	/**
	 * Lines written to standard output.
	 *
	 * @var string[]
	 */
	private $out = array();

	/**
	 * Messages written to error output.
	 *
	 * @var string[]
	 */
	private $err = array();

	/**
	 * Empties the log and the captured output before each test.
	 */
	public function set_up() {
		parent::set_up();

		global $wpdb;

		WPAT_Recorder::instance()->flush();

		$table = WPAT_Migrations::events_table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test fixture reset.
		$wpdb->query( "TRUNCATE TABLE {$table}" );
		delete_option( 'wpat_chain_head' );

		$this->out = array();
		$this->err = array();
	}

	/**
	 * Builds a handler wired to the capture buffers.
	 *
	 * @return WPAT_Cli Handler.
	 */
	private function handler() {
		return new WPAT_Cli(
			function ( $text, $newline = true ) {
				unset( $newline );
				$this->out[] = $text;
			},
			function ( $message ) {
				$this->err[] = $message;
			}
		);
	}

	/**
	 * Writes a run of chained events.
	 *
	 * @param int $count How many.
	 * @return void
	 */
	private function seed( $count ) {
		for ( $i = 0; $i < $count; $i++ ) {
			WPAT_Recorder::instance()->record(
				array(
					'event_type'  => 'auth.login',
					'severity'    => 1,
					'actor_id'    => 7,
					'actor_login' => 'tester',
					'object_type' => 'user',
					'object_id'   => (string) $i,
					'summary'     => 'seeded event ' . $i,
				)
			);
		}

		WPAT_Recorder::instance()->flush();
	}

	/**
	 * An intact chain exits 0 and says so.
	 */
	public function test_verify_passes_with_exit_code_zero() {
		$this->seed( 3 );

		$code = $this->handler()->verify();

		$this->assertSame( 0, $code );
		$this->assertSame( array(), $this->err );
		$this->assertStringContainsString( 'Verifying 3 rows in 1 chunks', implode( "\n", $this->out ) );
		$this->assertStringContainsString( 'Chain intact. 3 rows verified', implode( "\n", $this->out ) );
	}

	/**
	 * A tampered chain exits 1 and names the row on error output.
	 */
	public function test_verify_fails_with_exit_code_one() {
		global $wpdb;

		$this->seed( 3 );

		$table = WPAT_Migrations::events_table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Simulating database-level tampering.
		$id = (int) $wpdb->get_var( "SELECT id FROM {$table} ORDER BY id ASC LIMIT 1" );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Simulating database-level tampering.
		$wpdb->update( $table, array( 'summary' => 'rewritten' ), array( 'id' => $id ), array( '%s' ), array( '%d' ) );

		$code = $this->handler()->verify();

		$this->assertSame( 1, $code );
		$this->assertStringContainsString( 'Row ' . $id . ' does not match the chain', implode( "\n", $this->err ) );
		$this->assertStringContainsString( 'kind:     link', implode( "\n", $this->out ) );
	}

	/**
	 * An unknown format is a usage error, exit code 2.
	 */
	public function test_invalid_format_is_a_usage_error() {
		$code = $this->handler()->verify( array(), array( 'format' => 'yaml' ) );

		$this->assertSame( 2, $code );
		$this->assertStringContainsString( 'Invalid --format value', implode( "\n", $this->err ) );
	}

	/**
	 * JSON output is exactly one document and nothing else.
	 */
	public function test_json_format_prints_one_document() {
		$this->seed( 2 );

		$code = $this->handler()->verify( array(), array( 'format' => 'json' ) );

		$this->assertSame( 0, $code );
		$this->assertCount( 1, $this->out );

		$report = json_decode( $this->out[0], true );

		$this->assertIsArray( $report );
		$this->assertSame( 2, $report['verified_rows'] );
		$this->assertSame( 2, $report['entry_count'] );
	}
}
