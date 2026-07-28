<?php
/**
 * WordPress hook subscriptions.
 *
 * @package WP_Audit_Trail
 */

defined( 'ABSPATH' ) || exit;

/**
 * Maps WordPress hooks onto audit events.
 *
 * Callbacks stay thin on purpose: normalize the hook arguments, hand the event to the recorder,
 * and do nothing else. No queries and no business logic belong here.
 */
class WPAT_Listeners {

	/**
	 * Registers every listener this build ships.
	 *
	 * @return void
	 */
	public function boot() {
		add_action( 'wp_login', array( $this, 'on_login' ), 10, 2 );
		add_action( 'wp_login_failed', array( $this, 'on_login_failed' ), 10, 1 );
		add_action( 'wp_logout', array( $this, 'on_logout' ), 10, 1 );
	}

	/**
	 * Records a successful login.
	 *
	 * The actor is passed explicitly because the current user is not established yet when this
	 * hook fires.
	 *
	 * @param string  $user_login Login name.
	 * @param WP_User $user       Authenticated user.
	 * @return void
	 */
	public function on_login( $user_login, $user = null ) {
		$user_id = ( $user instanceof WP_User ) ? (int) $user->ID : 0;

		WPAT_Recorder::instance()->record(
			array(
				'event_type'   => 'auth.login',
				'severity'     => 1,
				'actor_id'     => $user_id,
				'actor_login'  => (string) $user_login,
				'object_type'  => 'user',
				'object_id'    => (string) $user_id,
				'object_label' => (string) $user_login,
				/* translators: %s: user login name. */
				'summary'      => sprintf( __( '%s logged in', 'wp-audit-trail' ), $user_login ),
				'payload'      => null,
			)
		);
	}

	/**
	 * Records a failed login attempt.
	 *
	 * Severity 3 means this is written at capture time, so it survives the request ending in a
	 * login error page.
	 *
	 * @param string $username Attempted login name, attacker-controlled.
	 * @return void
	 */
	public function on_login_failed( $username ) {
		$attempted = mb_substr( sanitize_text_field( (string) $username ), 0, 60 );

		WPAT_Recorder::instance()->record(
			array(
				'event_type'   => 'auth.login_failed',
				'severity'     => 3,
				'actor_id'     => 0,
				'actor_login'  => '',
				'object_type'  => 'user',
				'object_id'    => $attempted,
				'object_label' => $attempted,
				/* translators: %s: attempted login name. */
				'summary'      => sprintf( __( 'Failed login for %s', 'wp-audit-trail' ), $attempted ),
				'payload'      => array( 'username' => $attempted ),
			)
		);
	}

	/**
	 * Records a logout.
	 *
	 * @param int $user_id User who logged out.
	 * @return void
	 */
	public function on_logout( $user_id = 0 ) {
		$user_id = (int) $user_id;
		$user    = $user_id > 0 ? get_userdata( $user_id ) : null;
		$login   = ( $user instanceof WP_User ) ? (string) $user->user_login : '';

		WPAT_Recorder::instance()->record(
			array(
				'event_type'   => 'auth.logout',
				'severity'     => 1,
				'actor_id'     => $user_id,
				'actor_login'  => $login,
				'object_type'  => 'user',
				'object_id'    => (string) $user_id,
				'object_label' => $login,
				/* translators: %s: user login name. */
				'summary'      => sprintf( __( '%s logged out', 'wp-audit-trail' ), $login ),
				'payload'      => null,
			)
		);
	}
}
