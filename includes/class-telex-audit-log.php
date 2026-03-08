<?php
/**
 * Audit log — persists security-relevant events to a custom DB table.
 *
 * @package Dispatch_For_Telex
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Persists security-relevant events to a custom DB table.
 *
 * Table: {prefix}telex_audit_log
 * Created on plugin activation via Telex_Activator.
 */
class Telex_Audit_Log {

	/**
	 * Returns the fully-qualified audit log table name.
	 *
	 * @return string
	 */
	public static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'telex_audit_log';
	}

	/**
	 * Writes an audit event to the database.
	 *
	 * @param AuditAction          $action    The type of action being recorded.
	 * @param string               $public_id The Telex project public ID (if applicable).
	 * @param array<string, mixed> $context   Additional context data to serialize.
	 * @return void
	 */
	public static function log( AuditAction $action, string $public_id = '', array $context = [] ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert(
			self::table_name(),
			[
				'action'     => $action->value,
				'public_id'  => sanitize_text_field( $public_id ),
				'user_id'    => get_current_user_id(),
				'context'    => wp_json_encode( $context ),
				'created_at' => current_time( 'mysql', true ),
			],
			[ '%s', '%s', '%d', '%s', '%s' ]
		);
	}

	/** Columns that may be used as ORDER BY targets. */
	private const SORTABLE_COLUMNS = [ 'id', 'action', 'created_at' ];

	/**
	 * Returns the total number of audit log entries.
	 *
	 * @return int
	 */
	public static function count(): int {
		global $wpdb;
		$table = self::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
	}

	/**
	 * Returns a paginated slice of audit log entries using SQL LIMIT/OFFSET.
	 *
	 * @param int    $limit   Maximum number of rows to return.
	 * @param int    $offset  Number of rows to skip (0-based).
	 * @param string $orderby Column to sort by ('id', 'action', or 'created_at').
	 * @param string $order   Sort direction ('ASC' or 'DESC').
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_recent( int $limit = 50, int $offset = 0, string $orderby = 'id', string $order = 'DESC' ): array {
		global $wpdb;

		// Allowlist both parameters so they can be safely interpolated.
		$orderby = in_array( $orderby, self::SORTABLE_COLUMNS, true ) ? $orderby : 'id';
		$order   = 'ASC' === strtoupper( $order ) ? 'ASC' : 'DESC';

		$table = self::table_name();
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table/$orderby/$order are all allowlisted above.
		$sql = $wpdb->prepare( "SELECT * FROM {$table} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d", $limit, $offset );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- $orderby is validated against SORTABLE_COLUMNS; $order is hardcoded to ASC/DESC.
		$rows = $wpdb->get_results( $sql, ARRAY_A );

		return is_array( $rows ) ? $rows : [];
	}

	/**
	 * Creates the audit log table using dbDelta (safe to call on every activation).
	 *
	 * @return void
	 */
	public static function create_table(): void {
		global $wpdb;

		$table   = self::table_name();
		$charset = $wpdb->get_charset_collate();
		$sql     = "CREATE TABLE IF NOT EXISTS {$table} (
			id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			action     VARCHAR(32)     NOT NULL,
			public_id  VARCHAR(128)    NOT NULL DEFAULT '',
			user_id    BIGINT UNSIGNED NOT NULL DEFAULT 0,
			context    TEXT            NOT NULL DEFAULT '',
			created_at DATETIME        NOT NULL,
			PRIMARY KEY (id),
			KEY idx_action (action),
			KEY idx_public_id (public_id),
			KEY idx_created_at (created_at)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Drops the audit log table (called on plugin uninstall).
	 *
	 * @return void
	 */
	public static function drop_table(): void {
		global $wpdb;
		// Table name is built entirely from $wpdb->prefix — never user input.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DROP TABLE IF EXISTS ' . esc_sql( self::table_name() ) );
	}
}
