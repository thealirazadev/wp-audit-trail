<?php
/**
 * Chain verification.
 *
 * @package WP_Audit_Trail
 */

defined( 'ABSPATH' ) || exit;

/**
 * Walks the chain in chunks and classifies any failure.
 *
 * Shared by the admin screen and WP-CLI, which is why the walk is expressed as start/step over a
 * plain state array: the admin runs the steps across several requests, the CLI runs them in a
 * loop. Verification only ever reads, and never takes the write lock.
 */
class WPAT_Verifier {

	/**
	 * Rows verified per step.
	 */
	const CHUNK = 1000;

	/**
	 * Builds the state for a fresh run.
	 *
	 * @return array Run state.
	 */
	public function start() {
		return array(
			'cursor'        => 0,
			'running_hash'  => WPAT_Chain::GENESIS,
			'verified_rows' => 0,
			'total_rows'    => $this->total_rows(),
			'anchors_seen'  => 0,
			'started_at'    => microtime( true ),
		);
	}

	/**
	 * Verifies up to one chunk of rows.
	 *
	 * @param array $state State from start() or a previous step().
	 * @return array {
	 *     Step outcome.
	 *
	 *     @type string     $status running, passed, or failed.
	 *     @type array      $state  State to pass to the next step.
	 *     @type array|null $report Terminal report when status is passed or failed.
	 * }
	 */
	public function step( array $state ) {
		global $wpdb;

		$table = WPAT_Migrations::events_table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Table name is built from $wpdb->prefix; verification must read current rows.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE id > %d ORDER BY id ASC LIMIT %d", (int) $state['cursor'], self::CHUNK ), ARRAY_A );

		if ( ! is_array( $rows ) ) {
			return $this->fail( $state, 'link', 0, '', '', __( 'The audit log could not be read.', 'wp-audit-trail' ) );
		}

		foreach ( $rows as $row ) {
			$id = (int) $row['id'];

			if ( ! hash_equals( (string) $state['running_hash'], (string) $row['prev_hash'] ) ) {
				return $this->fail( $state, 'link', $id, (string) $state['running_hash'], (string) $row['prev_hash'], $this->link_message( $id ) );
			}

			$expected = WPAT_Chain::entry_hash( (string) $row['prev_hash'], $row );

			if ( is_wp_error( $expected ) ) {
				return $this->fail( $state, 'link', $id, '', (string) $row['entry_hash'], $this->link_message( $id ) );
			}

			if ( ! hash_equals( $expected, (string) $row['entry_hash'] ) ) {
				return $this->fail( $state, 'link', $id, $expected, (string) $row['entry_hash'], $this->link_message( $id ) );
			}

			$state['running_hash'] = $expected;
			$state['cursor']       = $id;
			++$state['verified_rows'];

			if ( 'audit.anchor' === (string) $row['event_type'] ) {
				++$state['anchors_seen'];
			}
		}

		if ( count( $rows ) === self::CHUNK ) {
			return array(
				'status' => 'running',
				'state'  => $state,
				'report' => null,
			);
		}

		return $this->finalize( $state );
	}

	/**
	 * Runs a whole verification.
	 *
	 * @param callable|null $progress Optional callback invoked after each chunk with the state.
	 * @return array {
	 *     Verification outcome.
	 *
	 *     @type bool  $passed True when the chain is intact.
	 *     @type array $report Report body.
	 * }
	 */
	public function run( $progress = null ) {
		$state = $this->start();

		do {
			$step  = $this->step( $state );
			$state = $step['state'];

			if ( is_callable( $progress ) ) {
				call_user_func( $progress, $state );
			}
		} while ( 'running' === $step['status'] );

		return array(
			'passed' => 'passed' === $step['status'],
			'report' => $step['report'],
		);
	}

	/**
	 * Checks the sealed head and the entry count once the walk has completed.
	 *
	 * @param array $state Run state.
	 * @return array Step outcome.
	 */
	private function finalize( array $state ) {
		$head          = WPAT_Chain::read_head();
		$verified_rows = (int) $state['verified_rows'];

		if ( null === $head ) {
			if ( 0 === $verified_rows ) {
				return $this->pass( $state, 0, WPAT_Chain::GENESIS, '' );
			}

			return $this->fail(
				$state,
				'seal',
				(int) $state['cursor'],
				'',
				'',
				__( 'The sealed chain head is missing while the log holds rows. The head was deleted or restored separately from the log.', 'wp-audit-trail' )
			);
		}

		if ( ! WPAT_Chain::verify_head( $head ) ) {
			return $this->fail(
				$state,
				'seal',
				(int) $state['cursor'],
				'',
				(string) ( isset( $head['sig'] ) ? $head['sig'] : '' ),
				__( 'The chain head signature does not match. Either the head was forged, or the signing key changed: define WPAT_CHAIN_KEY in wp-config.php if you were relying on WordPress salts.', 'wp-audit-trail' )
			);
		}

		if ( (int) $head['last_id'] !== (int) $state['cursor'] || ! hash_equals( (string) $head['last_hash'], (string) $state['running_hash'] ) ) {
			return $this->fail(
				$state,
				'seal',
				(int) $state['cursor'],
				(string) $head['last_hash'],
				(string) $state['running_hash'],
				__( 'The sealed head does not point at the last row of the log. Rows were removed from the end of the chain.', 'wp-audit-trail' )
			);
		}

		if ( (int) $head['entry_count'] !== $verified_rows ) {
			return $this->fail(
				$state,
				'count',
				(int) $state['cursor'],
				(string) (int) $head['entry_count'],
				(string) $verified_rows,
				__( 'The sealed entry count does not match the number of rows in the log. Rows were deleted.', 'wp-audit-trail' )
			);
		}

		return $this->pass( $state, (int) $head['entry_count'], (string) $head['last_hash'], (string) $head['sealed_at'] );
	}

	/**
	 * Builds a passing outcome.
	 *
	 * @param array  $state       Run state.
	 * @param int    $entry_count Lifetime entry count.
	 * @param string $head_hash   Sealed head hash.
	 * @param string $sealed_at   Seal timestamp.
	 * @return array Step outcome.
	 */
	private function pass( array $state, $entry_count, $head_hash, $sealed_at ) {
		return array(
			'status' => 'passed',
			'state'  => $state,
			'report' => array(
				'verified_rows' => (int) $state['verified_rows'],
				'anchors_seen'  => (int) $state['anchors_seen'],
				'entry_count'   => (int) $entry_count,
				'head_hash'     => $head_hash,
				'sealed_at'     => $sealed_at,
				'duration_ms'   => $this->duration_ms( $state ),
			),
		);
	}

	/**
	 * Builds a failing outcome.
	 *
	 * @param array  $state    Run state.
	 * @param string $kind     link, seal, or count.
	 * @param int    $bad_id   Row id the failure was detected at.
	 * @param string $expected Expected value.
	 * @param string $actual   Stored value.
	 * @param string $message  Human-readable explanation.
	 * @return array Step outcome.
	 */
	private function fail( array $state, $kind, $bad_id, $expected, $actual, $message ) {
		return array(
			'status' => 'failed',
			'state'  => $state,
			'report' => array(
				'kind'          => $kind,
				'first_bad_id'  => (int) $bad_id,
				'expected'      => $expected,
				'actual'        => $actual,
				'message'       => $message,
				'verified_rows' => (int) $state['verified_rows'],
				'duration_ms'   => $this->duration_ms( $state ),
			),
		);
	}

	/**
	 * Returns the standard link failure message.
	 *
	 * @param int $id Row id.
	 * @return string Message.
	 */
	private function link_message( $id ) {
		return sprintf(
			/* translators: %d: audit log row id. */
			__( 'Row %d does not match the chain. The row or an earlier row was altered or removed.', 'wp-audit-trail' ),
			$id
		);
	}

	/**
	 * Elapsed milliseconds since the run started.
	 *
	 * @param array $state Run state.
	 * @return int Milliseconds.
	 */
	private function duration_ms( array $state ) {
		return (int) round( ( microtime( true ) - (float) $state['started_at'] ) * 1000 );
	}

	/**
	 * Counts rows currently in the log.
	 *
	 * @return int Row count.
	 */
	private function total_rows() {
		global $wpdb;

		$table = WPAT_Migrations::events_table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Table name is built from $wpdb->prefix; verification must read current rows.
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
	}
}
