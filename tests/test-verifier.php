<?php
/**
 * Chain verification outcomes.
 *
 * @package WP_Audit_Trail
 */

/**
 * Covers WPAT_Verifier.
 */
class WPAT_Test_Verifier extends WP_UnitTestCase {

	/**
	 * Empties the log before each test.
	 */
	public function set_up() {
		parent::set_up();

		global $wpdb;

		WPAT_Recorder::instance()->flush();

		$table = WPAT_Migrations::events_table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test fixture reset.
		$wpdb->query( "TRUNCATE TABLE {$table}" );
		delete_option( 'wpat_chain_head' );
		update_option( 'wpat_dropped_events', 0, false );
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
	 * Returns all row ids in chain order.
	 *
	 * @return int[] Ids.
	 */
	private function ids() {
		global $wpdb;

		$table = WPAT_Migrations::events_table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test assertion read.
		return array_map( 'intval', $wpdb->get_col( "SELECT id FROM {$table} ORDER BY id ASC" ) );
	}

	/**
	 * An empty log with no head verifies.
	 */
	public function test_empty_log_passes() {
		$result = ( new WPAT_Verifier() )->run();

		$this->assertTrue( $result['passed'] );
		$this->assertSame( 0, $result['report']['verified_rows'] );
	}

	/**
	 * An untouched chain verifies and reports its head.
	 */
	public function test_intact_chain_passes() {
		$this->seed( 5 );

		$result = ( new WPAT_Verifier() )->run();

		$this->assertTrue( $result['passed'] );
		$this->assertSame( 5, $result['report']['verified_rows'] );
		$this->assertSame( 5, $result['report']['entry_count'] );
		$this->assertSame( 0, $result['report']['anchors_seen'] );
		$this->assertSame( WPAT_Chain::read_head()['last_hash'], $result['report']['head_hash'] );
	}

	/**
	 * Editing any column of any row is reported as a link failure naming that row.
	 */
	public function test_edited_row_fails_as_link() {
		global $wpdb;

		$this->seed( 5 );
		$ids   = $this->ids();
		$table = WPAT_Migrations::events_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Simulating database-level tampering.
		$wpdb->update( $table, array( 'summary' => 'rewritten by an attacker' ), array( 'id' => $ids[2] ), array( '%s' ), array( '%d' ) );

		$result = ( new WPAT_Verifier() )->run();

		$this->assertFalse( $result['passed'] );
		$this->assertSame( 'link', $result['report']['kind'] );
		$this->assertSame( $ids[2], $result['report']['first_bad_id'] );
		$this->assertNotSame( $result['report']['expected'], $result['report']['actual'] );
	}

	/**
	 * Tampering with the very first row is caught.
	 */
	public function test_first_row_tampering_is_caught() {
		global $wpdb;

		$this->seed( 3 );
		$ids   = $this->ids();
		$table = WPAT_Migrations::events_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Simulating database-level tampering.
		$wpdb->update( $table, array( 'actor_login' => 'someone_else' ), array( 'id' => $ids[0] ), array( '%s' ), array( '%d' ) );

		$result = ( new WPAT_Verifier() )->run();

		$this->assertFalse( $result['passed'] );
		$this->assertSame( 'link', $result['report']['kind'] );
		$this->assertSame( $ids[0], $result['report']['first_bad_id'] );
	}

	/**
	 * Deleting a row from the middle breaks the link at the row that followed it.
	 */
	public function test_deleted_middle_row_fails_as_link() {
		global $wpdb;

		$this->seed( 5 );
		$ids   = $this->ids();
		$table = WPAT_Migrations::events_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Simulating database-level tampering.
		$wpdb->delete( $table, array( 'id' => $ids[2] ), array( '%d' ) );

		$result = ( new WPAT_Verifier() )->run();

		$this->assertFalse( $result['passed'] );
		$this->assertSame( 'link', $result['report']['kind'] );
		$this->assertSame( $ids[3], $result['report']['first_bad_id'] );
	}

	/**
	 * Truncating the newest rows leaves the links intact, so the seal is what catches it.
	 */
	public function test_deleted_newest_row_fails_as_seal() {
		global $wpdb;

		$this->seed( 4 );
		$ids   = $this->ids();
		$table = WPAT_Migrations::events_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Simulating database-level tampering.
		$wpdb->delete( $table, array( 'id' => $ids[3] ), array( '%d' ) );

		$result = ( new WPAT_Verifier() )->run();

		$this->assertFalse( $result['passed'] );
		$this->assertSame( 'seal', $result['report']['kind'] );
	}

	/**
	 * A forged head, or a rotated signing key, is a seal failure with intact links.
	 */
	public function test_bad_signature_fails_as_seal() {
		$this->seed( 3 );

		$head        = WPAT_Chain::read_head();
		$head['sig'] = str_repeat( 'f', 64 );
		update_option( 'wpat_chain_head', $head, false );

		$result = ( new WPAT_Verifier() )->run();

		$this->assertFalse( $result['passed'] );
		$this->assertSame( 'seal', $result['report']['kind'] );
	}

	/**
	 * A head that is missing while rows exist is a seal failure.
	 */
	public function test_missing_head_fails_as_seal() {
		$this->seed( 2 );
		delete_option( 'wpat_chain_head' );

		$result = ( new WPAT_Verifier() )->run();

		$this->assertFalse( $result['passed'] );
		$this->assertSame( 'seal', $result['report']['kind'] );
	}

	/**
	 * A correctly signed head whose lifetime count disagrees with the log is a count failure.
	 */
	public function test_wrong_entry_count_fails_as_count() {
		$this->seed( 3 );

		$head = WPAT_Chain::read_head();
		WPAT_Chain::write_head( WPAT_Chain::build_head( $head['last_id'], $head['last_hash'], 99 ) );

		$result = ( new WPAT_Verifier() )->run();

		$this->assertFalse( $result['passed'] );
		$this->assertSame( 'count', $result['report']['kind'] );
		$this->assertSame( '99', $result['report']['expected'] );
		$this->assertSame( '3', $result['report']['actual'] );
	}

	/**
	 * The walk continues correctly across a chunk boundary, and still names the tampered row.
	 */
	public function test_chunk_boundary_is_exact() {
		global $wpdb;

		$this->seed( WPAT_Verifier::CHUNK + 1 );

		$verifier = new WPAT_Verifier();
		$result   = $verifier->run();

		$this->assertTrue( $result['passed'] );
		$this->assertSame( WPAT_Verifier::CHUNK + 1, $result['report']['verified_rows'] );

		$ids   = $this->ids();
		$last  = $ids[ WPAT_Verifier::CHUNK ];
		$table = WPAT_Migrations::events_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Simulating database-level tampering.
		$wpdb->update( $table, array( 'summary' => 'tampered past the chunk boundary' ), array( 'id' => $last ), array( '%s' ), array( '%d' ) );

		$second = ( new WPAT_Verifier() )->run();

		$this->assertFalse( $second['passed'] );
		$this->assertSame( 'link', $second['report']['kind'] );
		$this->assertSame( $last, $second['report']['first_bad_id'] );
	}

	/**
	 * Stepping reports progress before it reports a verdict.
	 */
	public function test_step_reports_running_until_the_last_chunk() {
		$this->seed( WPAT_Verifier::CHUNK + 1 );

		$verifier = new WPAT_Verifier();
		$state    = $verifier->start();

		$this->assertSame( WPAT_Verifier::CHUNK + 1, $state['total_rows'] );

		$first = $verifier->step( $state );

		$this->assertSame( 'running', $first['status'] );
		$this->assertSame( WPAT_Verifier::CHUNK, $first['state']['verified_rows'] );
		$this->assertNull( $first['report'] );

		$second = $verifier->step( $first['state'] );

		$this->assertSame( 'passed', $second['status'] );
		$this->assertSame( WPAT_Verifier::CHUNK + 1, $second['state']['verified_rows'] );
	}
}
