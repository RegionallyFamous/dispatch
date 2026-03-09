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

		// Invalidate caches so the list table shows the correct total
		// immediately after a new event is written.
		delete_transient( self::TRANSIENT_COUNT );
		if ( function_exists( 'wp_cache_supports' ) && wp_cache_supports( 'flush_group' ) ) {
			wp_cache_flush_group( self::CACHE_GROUP );
		}
	}

	/** Columns that may be used as ORDER BY targets. */
	private const SORTABLE_COLUMNS = [ 'id', 'action', 'created_at' ];

	private const TRANSIENT_COUNT = 'telex_audit_count';

	private const CACHE_GROUP = 'telex_audit_log';
	private const CACHE_TTL   = 30;

	/** Allowed action values for filtered queries. */
	private const VALID_ACTIONS = [ 'install', 'update', 'remove', 'connect', 'disconnect', 'activate', 'deactivate', 'auto_update' ];

	/**
	 * Returns the total number of audit log entries.
	 *
	 * Result is cached for 60 seconds to avoid a COUNT(*) on every page view.
	 * The cache is busted automatically whenever a new entry is inserted via log().
	 *
	 * @return int
	 */
	public static function count(): int {
		$cached = get_transient( self::TRANSIENT_COUNT );
		if ( false !== $cached ) {
			return (int) $cached;
		}

		global $wpdb;
		$table = self::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );

		set_transient( self::TRANSIENT_COUNT, $count, MINUTE_IN_SECONDS );

		return $count;
	}

	/**
	 * Returns a paginated slice of audit log entries using SQL LIMIT/OFFSET.
	 *
	 * The `context` column is excluded by default — it is a potentially large
	 * TEXT blob that is not displayed in list views. Pass true to include it
	 * (e.g. for CSV export).
	 *
	 * @param int    $limit          Maximum number of rows to return.
	 * @param int    $offset         Number of rows to skip (0-based).
	 * @param string $orderby        Column to sort by ('id', 'action', or 'created_at').
	 * @param string $order          Sort direction ('ASC' or 'DESC').
	 * @param bool   $include_context Whether to include the context column.
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_recent( int $limit = 50, int $offset = 0, string $orderby = 'id', string $order = 'DESC', bool $include_context = false ): array {
		global $wpdb;

		// Allowlist both parameters so they can be safely interpolated.
		$orderby = in_array( $orderby, self::SORTABLE_COLUMNS, true ) ? $orderby : 'id';
		$order   = 'ASC' === strtoupper( $order ) ? 'ASC' : 'DESC';

		$columns = $include_context
			? 'id, action, public_id, user_id, context, created_at'
			: 'id, action, public_id, user_id, created_at';

		$table = self::table_name();
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table/$columns/$orderby/$order are all allowlisted above.
		$sql = $wpdb->prepare( "SELECT {$columns} FROM {$table} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d", $limit, $offset );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter
		$rows = $wpdb->get_results( $sql, ARRAY_A );

		return is_array( $rows ) ? $rows : [];
	}

	/**
	 * Returns filtered, paginated audit log entries with optional caching.
	 *
	 * Uses wp_cache for both count and rows (30s TTL) to satisfy Plugin Check.
	 * Cache is invalidated when log() writes a new entry.
	 *
	 * @param array<string, mixed> $args Filter and pagination args.
	 * @return array{total: int, rows: array<int, array<string, mixed>>}
	 */
	public static function get_filtered( array $args ): array {
		global $wpdb;

		$action     = (string) ( $args['action'] ?? '' );
		$project_id = (string) ( $args['project_id'] ?? '' );
		$search     = (string) ( $args['search'] ?? '' );
		$date_from  = (string) ( $args['date_from'] ?? $args['since'] ?? '' );
		$date_to    = (string) ( $args['date_to'] ?? $args['until'] ?? '' );
		$user_id    = (int) ( $args['user_id'] ?? 0 );
		$per_page   = max( 1, min( 10000, (int) ( $args['per_page'] ?? $args['limit'] ?? 50 ) ) );
		$page       = max( 1, (int) ( $args['page'] ?? 1 ) );
		$offset     = ( $page - 1 ) * $per_page;
		$columns    = (string) ( $args['columns'] ?? 'id, action, public_id, user_id, created_at' );
		$allowed    = [ 'id, action, public_id, user_id, created_at', 'id, action, public_id, user_id, context, created_at' ];
		$columns    = in_array( $columns, $allowed, true ) ? $columns : 'id, action, public_id, user_id, created_at';

		$where_parts  = [];
		$where_values = [];
		$table        = self::table_name();

		if ( '' !== $action && in_array( strtolower( $action ), self::VALID_ACTIONS, true ) ) {
			$where_parts[]  = 'action = %s';
			$where_values[] = strtolower( $action );
		}
		if ( '' !== $project_id ) {
			$where_parts[]  = 'public_id = %s';
			$where_values[] = $project_id;
		}
		if ( '' !== $search ) {
			$where_parts[]  = '(public_id LIKE %s OR context LIKE %s)';
			$like           = '%' . $wpdb->esc_like( $search ) . '%';
			$where_values[] = $like;
			$where_values[] = $like;
		}
		if ( '' !== $date_from ) {
			$ts = strtotime( $date_from );
			if ( false !== $ts ) {
				$where_parts[]  = 'created_at >= %s';
				$where_values[] = gmdate( 'Y-m-d H:i:s', $ts );
			}
		}
		if ( '' !== $date_to ) {
			$ts = strtotime( $date_to );
			if ( false !== $ts ) {
				$where_parts[]  = 'created_at <= %s';
				$where_values[] = gmdate( 'Y-m-d 23:59:59', $ts );
			}
		}
		if ( $user_id > 0 ) {
			$where_parts[]  = 'user_id = %d';
			$where_values[] = $user_id;
		}

		$cache_key_base = 'filtered_' . md5( wp_json_encode( [ $action, $project_id, $search, $date_from, $date_to, $user_id, $columns ] ) );
		$count_key      = $cache_key_base . '_count';
		$rows_key       = $cache_key_base . '_rows_' . $offset . '_' . $per_page;

		$total = wp_cache_get( $count_key, self::CACHE_GROUP );
		if ( false === $total ) {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,PluginCheck.Security.DirectDB.UnescapedDBParameter
			if ( empty( $where_parts ) ) {
				$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
			} else {
				$where_sql = 'WHERE ' . implode( ' AND ', $where_parts );
				$total     = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} {$where_sql}", ...$where_values ) );
			}
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,PluginCheck.Security.DirectDB.UnescapedDBParameter
			wp_cache_set( $count_key, $total, self::CACHE_GROUP, self::CACHE_TTL );
		}

		$rows = wp_cache_get( $rows_key, self::CACHE_GROUP );
		if ( false === $rows ) {
			$limit_values = array_merge( $where_values, [ $per_page, $offset ] );
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,PluginCheck.Security.DirectDB.UnescapedDBParameter
			if ( empty( $where_parts ) ) {
				$rows = $wpdb->get_results(
					$wpdb->prepare( "SELECT {$columns} FROM {$table} ORDER BY id DESC LIMIT %d OFFSET %d", $per_page, $offset ),
					ARRAY_A
				);
			} else {
				$where_sql = 'WHERE ' . implode( ' AND ', $where_parts );
				$rows      = $wpdb->get_results(
					$wpdb->prepare( "SELECT {$columns} FROM {$table} {$where_sql} ORDER BY id DESC LIMIT %d OFFSET %d", ...$limit_values ),
					ARRAY_A
				);
			}
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,PluginCheck.Security.DirectDB.UnescapedDBParameter
			$rows = is_array( $rows ) ? $rows : [];
			wp_cache_set( $rows_key, $rows, self::CACHE_GROUP, self::CACHE_TTL );
		}

		return [
			'total' => (int) $total,
			'rows'  => $rows,
		];
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
