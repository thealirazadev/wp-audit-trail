<?php
/**
 * Schema migrations.
 *
 * @package WP_Audit_Trail
 */

defined( 'ABSPATH' ) || exit;

/**
 * Applies numbered dbDelta steps keyed by the `wpat_db_version` option.
 *
 * Applied steps are never edited. A schema change is always a new step with a higher number.
 */
class WPAT_Migrations {

	/**
	 * Schema version this build expects.
	 */
	const DB_VERSION = 1;

	/**
	 * Option holding the applied schema version.
	 */
	const VERSION_OPTION = 'wpat_db_version';

	/**
	 * Returns the fully prefixed events table name.
	 *
	 * @return string Table name.
	 */
	public static function events_table() {
		global $wpdb;

		return $wpdb->prefix . 'wpat_events';
	}

	/**
	 * Runs any steps the installed version has not seen yet.
	 *
	 * @return void
	 */
	public static function run() {
		$installed = (int) get_option( self::VERSION_OPTION, 0 );

		if ( $installed >= self::DB_VERSION ) {
			return;
		}

		if ( $installed < 1 ) {
			self::step_1();
		}

		update_option( self::VERSION_OPTION, self::DB_VERSION, false );
	}

	/**
	 * Step 1: create the append-only events table.
	 *
	 * Note that dbDelta is whitespace-sensitive: two spaces after PRIMARY KEY, one KEY per line,
	 * and no backticks around index names, or it will recreate indexes on every run.
	 *
	 * @return void
	 */
	private static function step_1() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::events_table();
		$collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			occurred_at datetime NOT NULL,
			actor_id bigint(20) unsigned NOT NULL DEFAULT 0,
			actor_login varchar(60) NOT NULL DEFAULT '',
			actor_ip varchar(45) NOT NULL DEFAULT '',
			actor_ua varchar(255) NOT NULL DEFAULT '',
			event_type varchar(64) NOT NULL,
			severity tinyint(3) unsigned NOT NULL,
			object_type varchar(32) NOT NULL DEFAULT '',
			object_id varchar(64) NOT NULL DEFAULT '',
			object_label varchar(255) NOT NULL DEFAULT '',
			summary varchar(255) NOT NULL DEFAULT '',
			payload longtext NULL,
			prev_hash char(64) NOT NULL,
			entry_hash char(64) NOT NULL,
			PRIMARY KEY  (id),
			KEY occurred_at (occurred_at),
			KEY event_type (event_type),
			KEY actor_id (actor_id),
			KEY severity (severity),
			KEY object_ref (object_type, object_id(20))
		) {$collate};";

		dbDelta( $sql );
	}
}
