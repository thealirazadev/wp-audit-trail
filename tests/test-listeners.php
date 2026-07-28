<?php
/**
 * Authentication event capture through real WordPress hooks.
 *
 * @package WP_Audit_Trail
 */

/**
 * Covers WPAT_Listeners.
 */
class WPAT_Test_Listeners extends WP_UnitTestCase {

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
	 * A login is recorded with the actor snapshot taken at the hook.
	 */
	public function test_login_is_recorded() {
		$user_id = self::factory()->user->create( array( 'user_login' => 'jane_logs_in' ) );
		$user    = get_userdata( $user_id );

		do_action( 'wp_login', $user->user_login, $user );
		WPAT_Recorder::instance()->flush();

		$rows = $this->rows();

		$this->assertCount( 1, $rows );
		$this->assertSame( 'auth.login', $rows[0]['event_type'] );
		$this->assertSame( 1, (int) $rows[0]['severity'] );
		$this->assertSame( $user_id, (int) $rows[0]['actor_id'] );
		$this->assertSame( 'jane_logs_in', $rows[0]['actor_login'] );
		$this->assertSame( 'user', $rows[0]['object_type'] );
		$this->assertSame( (string) $user_id, $rows[0]['object_id'] );
		$this->assertSame( 'jane_logs_in', $rows[0]['object_label'] );
		$this->assertNull( $rows[0]['payload'] );
	}

	/**
	 * A failed login is written at capture time, so it survives a request that dies next.
	 */
	public function test_failed_login_is_written_without_waiting_for_shutdown() {
		do_action( 'wp_login_failed', 'ghost', new WP_Error( 'invalid_username', 'nope' ) );

		$rows = $this->rows();

		$this->assertCount( 1, $rows );
		$this->assertSame( 'auth.login_failed', $rows[0]['event_type'] );
		$this->assertSame( 3, (int) $rows[0]['severity'] );
		$this->assertSame( 0, (int) $rows[0]['actor_id'] );
		$this->assertSame( '', $rows[0]['actor_login'] );
		$this->assertSame( 'user', $rows[0]['object_type'] );
		$this->assertSame( 'ghost', $rows[0]['object_id'] );
		$this->assertSame( '{"username":"ghost"}', $rows[0]['payload'] );
	}

	/**
	 * An attacker-supplied login name is sanitized and capped to the column width.
	 */
	public function test_failed_login_name_is_sanitized_and_capped() {
		do_action( 'wp_login_failed', '<script>alert(1)</script>' . str_repeat( 'a', 200 ), null );

		$rows = $this->rows();

		$this->assertCount( 1, $rows );
		$this->assertStringNotContainsString( '<script>', $rows[0]['object_id'] );
		$this->assertLessThanOrEqual( 60, strlen( $rows[0]['object_id'] ) );
	}

	/**
	 * A logout is recorded against the user who logged out.
	 */
	public function test_logout_is_recorded() {
		$user_id = self::factory()->user->create( array( 'user_login' => 'jane_logs_out' ) );

		do_action( 'wp_logout', $user_id );
		WPAT_Recorder::instance()->flush();

		$rows = $this->rows();

		$this->assertCount( 1, $rows );
		$this->assertSame( 'auth.logout', $rows[0]['event_type'] );
		$this->assertSame( 1, (int) $rows[0]['severity'] );
		$this->assertSame( $user_id, (int) $rows[0]['actor_id'] );
		$this->assertSame( 'jane_logs_out', $rows[0]['actor_login'] );
	}

	/**
	 * Auth events written through the hooks form a chain that verifies.
	 */
	public function test_captured_events_verify() {
		$user_id = self::factory()->user->create( array( 'user_login' => 'jane_round_trip' ) );
		$user    = get_userdata( $user_id );

		do_action( 'wp_login', $user->user_login, $user );
		do_action( 'wp_login_failed', 'ghost', null );
		do_action( 'wp_logout', $user_id );
		WPAT_Recorder::instance()->flush();

		$result = ( new WPAT_Verifier() )->run();

		$this->assertTrue( $result['passed'] );
		$this->assertSame( 3, $result['report']['verified_rows'] );
	}
}
