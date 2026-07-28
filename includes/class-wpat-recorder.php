<?php
/**
 * Event buffer and single-writer chain flush.
 *
 * @package WP_Audit_Trail
 */

defined( 'ABSPATH' ) || exit;

/**
 * The only code path that writes chain rows.
 *
 * Everything about this class exists to keep two invariants true: the chain never forks (all
 * inserts happen inside one named lock, in one transaction, by one writer), and an event that
 * cannot be chained is never lost quietly (it is spilled to the error log and counted).
 */
class WPAT_Recorder {

	/**
	 * Severity at and above which the buffer is flushed at capture time.
	 */
	const SYNC_SEVERITY = 3;

	/**
	 * Seconds to wait for the chain lock on each attempt.
	 */
	const LOCK_TIMEOUT = 5;

	/**
	 * Singleton instance.
	 *
	 * @var WPAT_Recorder|null
	 */
	private static $instance = null;

	/**
	 * Events captured in this request and not yet written.
	 *
	 * @var array[]
	 */
	private $buffer = array();

	/**
	 * Guards against re-entering flush() from inside a flush.
	 *
	 * @var bool
	 */
	private $flushing = false;

	/**
	 * Returns the shared instance.
	 *
	 * @return WPAT_Recorder
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Registers the deferred flush.
	 *
	 * @return void
	 */
	public function boot() {
		add_action( 'shutdown', array( $this, 'flush' ), 100 );
	}

	/**
	 * Captures one event.
	 *
	 * Warning and critical events flush the whole buffer immediately so they survive a request
	 * that dies straight afterwards, which is exactly what a failed login does. Lower severities
	 * wait for shutdown, so a quiet page view costs no audit queries at all.
	 *
	 * @param array $event Event fields; see WPAT_Chain::FIELDS plus an optional array payload.
	 * @return void
	 */
	public function record( array $event ) {
		try {
			$normalized = $this->normalize( $event );

			if ( null === $normalized ) {
				return;
			}

			$this->buffer[] = $normalized;

			if ( $normalized['severity'] >= self::SYNC_SEVERITY ) {
				$this->flush();
			}
		} catch ( Throwable $e ) {
			// Auditing matters; the site staying up matters more.
			wpat_log( 'record.failed', array( 'error' => $e->getMessage() ) );
		}
	}

	/**
	 * Writes every buffered event, in capture order, and empties the buffer.
	 *
	 * @return void
	 */
	public function flush() {
		if ( $this->flushing || empty( $this->buffer ) ) {
			return;
		}

		$this->flushing = true;
		$events         = $this->buffer;
		$this->buffer   = array();

		try {
			$result = $this->write( $events );

			if ( is_wp_error( $result ) ) {
				$this->spill( $events, $result->get_error_code(), $result->get_error_message() );
			} else {
				wpat_log( 'flush.committed', array( 'events' => count( $events ) ) );
			}
		} catch ( Throwable $e ) {
			$this->spill( $events, 'wpat_server_error', $e->getMessage() );
		} finally {
			$this->flushing = false;
		}
	}

	/**
	 * Fills in actor, timing, and length limits, and encodes the payload.
	 *
	 * @param array $event Raw event from a listener.
	 * @return array|null Normalized event, or null when it is unusable.
	 */
	private function normalize( array $event ) {
		if ( empty( $event['event_type'] ) || empty( $event['severity'] ) ) {
			wpat_log( 'record.invalid', array( 'event_type' => isset( $event['event_type'] ) ? (string) $event['event_type'] : '' ) );

			return null;
		}

		if ( array_key_exists( 'actor_id', $event ) ) {
			$actor_id    = (int) $event['actor_id'];
			$actor_login = isset( $event['actor_login'] ) ? (string) $event['actor_login'] : '';
		} else {
			$user        = wp_get_current_user();
			$actor_id    = $user instanceof WP_User ? (int) $user->ID : 0;
			$actor_login = $user instanceof WP_User ? (string) $user->user_login : '';
		}

		$payload = null;

		if ( isset( $event['payload'] ) && null !== $event['payload'] ) {
			$payload = is_string( $event['payload'] ) ? $event['payload'] : wp_json_encode( $event['payload'] );

			if ( false === $payload ) {
				$payload = '"[payload encode failed]"';
			}
		}

		return array(
			'occurred_at'  => isset( $event['occurred_at'] ) ? (string) $event['occurred_at'] : gmdate( 'Y-m-d H:i:s' ),
			'actor_id'     => $actor_id,
			'actor_login'  => $this->cap( $actor_login, 60 ),
			'actor_ip'     => $this->cap( isset( $event['actor_ip'] ) ? (string) $event['actor_ip'] : wpat_client_ip(), 45 ),
			'actor_ua'     => $this->cap( $this->user_agent(), 255 ),
			'event_type'   => $this->cap( (string) $event['event_type'], 64 ),
			'severity'     => (int) $event['severity'],
			'object_type'  => $this->cap( isset( $event['object_type'] ) ? (string) $event['object_type'] : '', 32 ),
			'object_id'    => $this->cap( isset( $event['object_id'] ) ? (string) $event['object_id'] : '', 64 ),
			'object_label' => $this->cap( isset( $event['object_label'] ) ? (string) $event['object_label'] : '', 255 ),
			'summary'      => $this->cap( isset( $event['summary'] ) ? (string) $event['summary'] : '', 255 ),
			'payload'      => $payload,
		);
	}

	/**
	 * Returns the request user agent, or an empty string in CLI and cron contexts.
	 *
	 * @return string User agent.
	 */
	private function user_agent() {
		if ( ! isset( $_SERVER['HTTP_USER_AGENT'] ) ) {
			return '';
		}

		return sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) );
	}

	/**
	 * Truncates a value to its column width, counting bytes as the column does.
	 *
	 * @param string $value  Value.
	 * @param int    $length Maximum characters.
	 * @return string Truncated value.
	 */
	private function cap( $value, $length ) {
		return mb_substr( (string) $value, 0, $length );
	}

	/**
	 * Chains and inserts a batch under the write lock.
	 *
	 * @param array[] $events Normalized events in capture order.
	 * @return true|WP_Error True on commit, error on lock timeout or database failure.
	 * @throws RuntimeException When hashing or an insert fails; caught locally to roll back.
	 */
	private function write( array $events ) {
		global $wpdb;

		$lock = 'wpat_chain_' . $wpdb->prefix;

		if ( ! $this->acquire_lock( $lock ) ) {
			return new WP_Error( 'wpat_chain_write_failed', 'Timed out waiting for the audit chain lock.' );
		}

		$suppressed = $wpdb->suppress_errors( true );
		$table      = WPAT_Migrations::events_table();

		$wpdb->query( 'START TRANSACTION' );

		try {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Table name is built from $wpdb->prefix; the audit log is deliberately uncached.
			$tail = $wpdb->get_row( "SELECT id, entry_hash FROM {$table} ORDER BY id DESC LIMIT 1", ARRAY_A );

			$prev_hash = ( is_array( $tail ) && ! empty( $tail['entry_hash'] ) ) ? (string) $tail['entry_hash'] : WPAT_Chain::GENESIS;
			$head      = WPAT_Chain::read_head();
			$count     = ( is_array( $head ) && isset( $head['entry_count'] ) ) ? (int) $head['entry_count'] : 0;
			$last_id   = 0;

			foreach ( $events as $event ) {
				$entry_hash = WPAT_Chain::entry_hash( $prev_hash, $event );

				if ( is_wp_error( $entry_hash ) ) {
					throw new RuntimeException( $entry_hash->get_error_message() );
				}

				$row = array_merge(
					$event,
					array(
						'prev_hash'  => $prev_hash,
						'entry_hash' => $entry_hash,
					)
				);

				$inserted = $wpdb->insert(
					$table,
					$row,
					array( '%s', '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
				);

				if ( false === $inserted ) {
					throw new RuntimeException( 'Audit event insert failed.' );
				}

				$prev_hash = $entry_hash;
				$last_id   = (int) $wpdb->insert_id;
				++$count;
			}

			WPAT_Chain::write_head( WPAT_Chain::build_head( $last_id, $prev_hash, $count ) );

			$wpdb->query( 'COMMIT' );
		} catch ( Throwable $e ) {
			$wpdb->query( 'ROLLBACK' );
			// The head option participates in the transaction, so its cached copy is now stale.
			wp_cache_delete( 'wpat_chain_head', 'options' );
			$this->release_lock( $lock );
			$wpdb->suppress_errors( $suppressed );

			return new WP_Error( 'wpat_chain_write_failed', $e->getMessage() );
		}

		$this->release_lock( $lock );
		$wpdb->suppress_errors( $suppressed );

		return true;
	}

	/**
	 * Takes the named lock, retrying once before giving up.
	 *
	 * @param string $lock Lock name.
	 * @return bool True when held.
	 */
	private function acquire_lock( $lock ) {
		global $wpdb;

		for ( $attempt = 0; $attempt < 2; $attempt++ ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Named lock, not cacheable.
			$got = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $lock, self::LOCK_TIMEOUT ) );

			if ( '1' === (string) $got ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Releases the named lock.
	 *
	 * @param string $lock Lock name.
	 * @return void
	 */
	private function release_lock( $lock ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Named lock, not cacheable.
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
	}

	/**
	 * Writes unchainable events to the error log and counts them.
	 *
	 * The full event, payload included, goes to the log on purpose: at this point it is the only
	 * remaining copy of the record. Payloads have already been through redaction before reaching
	 * the recorder, so no secret is added to the log by spilling one.
	 *
	 * @param array[] $events  Events that could not be chained.
	 * @param string  $code    Error code.
	 * @param string  $message Error detail for the log.
	 * @return void
	 */
	private function spill( array $events, $code, $message ) {
		foreach ( $events as $event ) {
			wpat_log(
				'flush.spilled',
				array(
					'code'    => $code,
					'message' => $message,
					'event'   => $event,
				)
			);
		}

		$dropped = (int) get_option( 'wpat_dropped_events', 0 );
		update_option( 'wpat_dropped_events', $dropped + count( $events ), false );
	}
}
