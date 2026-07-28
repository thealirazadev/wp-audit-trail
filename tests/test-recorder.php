<?php
/**
 * Buffering, flush timing, and the spill path.
 *
 * @package WP_Audit_Trail
 */

/**
 * Covers WPAT_Recorder.
 *
 * The recorder commits its own transaction, so these tests cannot rely on the suite rolling the
 * outer transaction back; each one truncates the log itself.
 */
class WPAT_Test_Recorder extends WP_UnitTestCase {

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
	 * Builds a minimal event.
	 *
	 * @param string $type     Event type.
	 * @param int    $severity Severity.
	 * @return array Event.
	 */
	private function event( $type, $severity ) {
		return array(
			'event_type'  => $type,
			'severity'    => $severity,
			'actor_id'    => 7,
			'actor_login' => 'tester',
			'object_type' => 'user',
			'object_id'   => '7',
			'summary'     => $type,
		);
	}

	/**
	 * Reads every row in chain order.
	 *
	 * @return array[] Rows.
	 */
	private function rows() {
		global $wpdb;

		$table = WPAT_Migrations::events_table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test assertion read.
		return $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id ASC", ARRAY_A );
	}

	/**
	 * Info events cost no queries until the buffer is flushed.
	 */
	public function test_info_events_are_buffered_until_flush() {
		WPAT_Recorder::instance()->record( $this->event( 'auth.login', 1 ) );
		WPAT_Recorder::instance()->record( $this->event( 'auth.logout', 1 ) );

		$this->assertCount( 0, $this->rows() );

		WPAT_Recorder::instance()->flush();

		$rows = $this->rows();
		$this->assertCount( 2, $rows );
		$this->assertSame( 'auth.login', $rows[0]['event_type'] );
		$this->assertSame( 'auth.logout', $rows[1]['event_type'] );
	}

	/**
	 * A warning flushes the whole buffer at capture time, in capture order.
	 */
	public function test_warning_flushes_the_buffer_immediately() {
		WPAT_Recorder::instance()->record( $this->event( 'auth.login', 1 ) );
		WPAT_Recorder::instance()->record( $this->event( 'auth.login_failed', 3 ) );

		$rows = $this->rows();
		$this->assertCount( 2, $rows );
		$this->assertSame( 'auth.login', $rows[0]['event_type'] );
		$this->assertSame( 'auth.login_failed', $rows[1]['event_type'] );
	}

	/**
	 * Rows link to each other and the hashes recompute independently.
	 */
	public function test_rows_are_chained_and_sealed() {
		for ( $i = 0; $i < 3; $i++ ) {
			WPAT_Recorder::instance()->record( $this->event( 'auth.login', 1 ) );
		}

		WPAT_Recorder::instance()->flush();

		$rows = $this->rows();
		$prev = WPAT_Chain::GENESIS;

		foreach ( $rows as $row ) {
			$this->assertSame( $prev, $row['prev_hash'] );
			$this->assertSame( hash( 'sha256', $prev . '|' . WPAT_Chain::canonical_json( $row ) ), $row['entry_hash'] );
			$prev = $row['entry_hash'];
		}

		$head = WPAT_Chain::read_head();
		$this->assertTrue( WPAT_Chain::verify_head( $head ) );
		$this->assertSame( 3, $head['entry_count'] );
		$this->assertSame( (int) $rows[2]['id'], $head['last_id'] );
		$this->assertSame( $rows[2]['entry_hash'], $head['last_hash'] );
	}

	/**
	 * Values longer than their column are truncated rather than rejected by the database.
	 */
	public function test_long_values_are_capped() {
		$event                 = $this->event( 'auth.login_failed', 3 );
		$event['object_label'] = str_repeat( 'x', 400 );
		$event['summary']      = str_repeat( 'y', 400 );

		WPAT_Recorder::instance()->record( $event );

		$rows = $this->rows();
		$this->assertCount( 1, $rows );
		$this->assertSame( 255, strlen( $rows[0]['object_label'] ) );
		$this->assertSame( 255, strlen( $rows[0]['summary'] ) );
	}

	/**
	 * An event without a type never reaches the chain.
	 */
	public function test_invalid_events_are_not_chained() {
		WPAT_Recorder::instance()->record( array( 'severity' => 3 ) );

		$this->assertCount( 0, $this->rows() );
	}

	/**
	 * A missing table spills the event and increments the dropped counter instead of fataling.
	 */
	public function test_missing_table_spills_instead_of_fataling() {
		global $wpdb;

		$table = WPAT_Migrations::events_table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Simulating an unwritable log.
		$wpdb->query( "RENAME TABLE {$table} TO {$table}_hidden" );

		WPAT_Recorder::instance()->record( $this->event( 'auth.login_failed', 3 ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Restoring the log.
		$wpdb->query( "RENAME TABLE {$table}_hidden TO {$table}" );

		$this->assertSame( 1, (int) get_option( 'wpat_dropped_events' ) );
		$this->assertCount( 0, $this->rows() );
	}

	/**
	 * A lock held by another connection makes the recorder spill rather than fork the chain.
	 */
	public function test_lock_contention_spills() {
		global $wpdb;

		$holder = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
		$lock   = 'wpat_chain_' . $wpdb->prefix;
		$got    = $holder->get_var( $holder->prepare( 'SELECT GET_LOCK(%s, %d)', $lock, 5 ) );

		$this->assertSame( '1', (string) $got );

		WPAT_Recorder::instance()->record( $this->event( 'auth.login_failed', 3 ) );

		$holder->get_var( $holder->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );

		$this->assertCount( 0, $this->rows() );
		$this->assertSame( 1, (int) get_option( 'wpat_dropped_events' ) );
	}
}
