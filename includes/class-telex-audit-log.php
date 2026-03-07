<?php

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

	public static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'telex_audit_log';
	}

	/**
	 * @param array<string, mixed> $context
	 */
	public static function log( AuditAction $action, string $public_id = '', array $context = [] ): void {
		global $wpdb;

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

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_recent( int $limit = 50 ): array {
		global $wpdb;

		$table = self::table_name();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", $limit ),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : [];
	}

	public static function create_table(): void {
		global $wpdb;

		$table      = self::table_name();
		$charset    = $wpdb->get_charset_collate();
		$sql        = "CREATE TABLE IF NOT EXISTS {$table} (
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

	public static function drop_table(): void {
		global $wpdb;
		$table = self::table_name();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
	}
}
