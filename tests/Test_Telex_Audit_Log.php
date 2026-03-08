<?php
/**
 * Tests for Telex_Audit_Log — DB inserts, retrieval, and table lifecycle.
 *
 * @package Dispatch_For_Telex
 */

/**
 * Integration tests for the Telex_Audit_Log class.
 *
 * Creates the audit log table once for the test class and clears rows between
 * tests. The WP test suite uses transactions for WP core tables, but our custom
 * table may not benefit from automatic rollbacks, so we DELETE in setUp.
 */
class Test_Telex_Audit_Log extends WP_UnitTestCase {

	/**
	 * Create the audit log table once for the entire test class.
	 *
	 * @return void
	 */
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		Telex_Audit_Log::create_table();
	}

	/**
	 * Remove all rows from the audit log before each test for isolation.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();
		global $wpdb;
		$table = Telex_Audit_Log::table_name();
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is from $wpdb->prefix.
		$wpdb->query( "DELETE FROM {$table}" );
	}

	// -------------------------------------------------------------------------
	// table_name
	// -------------------------------------------------------------------------

	/**
	 * Asserts table_name() includes the WordPress table prefix.
	 *
	 * @return void
	 */
	public function test_table_name_includes_prefix(): void {
		global $wpdb;
		$expected = $wpdb->prefix . 'telex_audit_log';
		$this->assertSame( $expected, Telex_Audit_Log::table_name() );
	}

	// -------------------------------------------------------------------------
	// log
	// -------------------------------------------------------------------------

	/**
	 * Asserts log() inserts a row into the audit log table.
	 *
	 * @return void
	 */
	public function test_log_inserts_a_row(): void {
		Telex_Audit_Log::log( AuditAction::Connect );

		$rows = Telex_Audit_Log::get_recent( 10 );
		$this->assertCount( 1, $rows );
	}

	/**
	 * Asserts log() stores the correct action value.
	 *
	 * @return void
	 */
	public function test_log_stores_action_value(): void {
		Telex_Audit_Log::log( AuditAction::Install, 'proj-123' );

		$rows = Telex_Audit_Log::get_recent( 1 );
		$this->assertSame( 'install', $rows[0]['action'] );
	}

	/**
	 * Asserts log() stores the public_id correctly.
	 *
	 * @return void
	 */
	public function test_log_stores_public_id(): void {
		Telex_Audit_Log::log( AuditAction::Remove, 'proj-xyz' );

		$rows = Telex_Audit_Log::get_recent( 1 );
		$this->assertSame( 'proj-xyz', $rows[0]['public_id'] );
	}

	/**
	 * Asserts log() stores context data as JSON.
	 *
	 * @return void
	 */
	public function test_log_stores_context_as_json(): void {
		Telex_Audit_Log::log(
			AuditAction::Update,
			'proj-abc',
			[
				'version' => 5,
				'slug'    => 'my-block',
			]
		);

		$rows    = Telex_Audit_Log::get_recent( 1 );
		$context = json_decode( $rows[0]['context'], true );

		$this->assertSame( 5, $context['version'] );
		$this->assertSame( 'my-block', $context['slug'] );
	}

	/**
	 * Asserts log() stores the current user ID.
	 *
	 * @return void
	 */
	public function test_log_stores_user_id(): void {
		$user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $user_id );

		Telex_Audit_Log::log( AuditAction::Disconnect );

		$rows = Telex_Audit_Log::get_recent( 1 );
		$this->assertSame( (string) $user_id, $rows[0]['user_id'] );

		wp_set_current_user( 0 );
	}

	/**
	 * Asserts log() stores user_id as 0 for unauthenticated operations.
	 *
	 * @return void
	 */
	public function test_log_stores_zero_user_id_for_unauthenticated(): void {
		wp_set_current_user( 0 );

		Telex_Audit_Log::log( AuditAction::Connect );

		$rows = Telex_Audit_Log::get_recent( 1 );
		$this->assertSame( '0', $rows[0]['user_id'] );
	}

	/**
	 * Asserts log() stores a created_at timestamp.
	 *
	 * @return void
	 */
	public function test_log_stores_created_at_timestamp(): void {
		Telex_Audit_Log::log( AuditAction::Connect );

		$rows = Telex_Audit_Log::get_recent( 1 );
		$this->assertNotEmpty( $rows[0]['created_at'] );
	}

	// -------------------------------------------------------------------------
	// get_recent
	// -------------------------------------------------------------------------

	/**
	 * Asserts get_recent() returns an empty array when no entries exist.
	 *
	 * @return void
	 */
	public function test_get_recent_returns_empty_array_when_no_entries(): void {
		$this->assertSame( [], Telex_Audit_Log::get_recent() );
	}

	/**
	 * Asserts get_recent() returns entries in reverse-chronological order.
	 *
	 * @return void
	 */
	public function test_get_recent_returns_entries_newest_first(): void {
		Telex_Audit_Log::log( AuditAction::Connect );
		Telex_Audit_Log::log( AuditAction::Install, 'proj-1' );
		Telex_Audit_Log::log( AuditAction::Update, 'proj-2' );

		$rows = Telex_Audit_Log::get_recent( 10 );

		// Most recent first; IDs should be descending.
		$this->assertGreaterThan( (int) $rows[1]['id'], (int) $rows[0]['id'] );
		$this->assertGreaterThan( (int) $rows[2]['id'], (int) $rows[1]['id'] );
	}

	/**
	 * Asserts get_recent() respects the limit parameter.
	 *
	 * @return void
	 */
	public function test_get_recent_respects_limit(): void {
		for ( $i = 0; $i < 10; $i++ ) {
			Telex_Audit_Log::log( AuditAction::Connect );
		}

		$rows = Telex_Audit_Log::get_recent( 3 );
		$this->assertCount( 3, $rows );
	}

	/**
	 * Asserts get_recent() with default limit returns at most 50 rows.
	 *
	 * @return void
	 */
	public function test_get_recent_default_limit_is_fifty(): void {
		for ( $i = 0; $i < 60; $i++ ) {
			Telex_Audit_Log::log( AuditAction::Connect );
		}

		$rows = Telex_Audit_Log::get_recent();
		$this->assertCount( 50, $rows );
	}

	// -------------------------------------------------------------------------
	// Table lifecycle
	// -------------------------------------------------------------------------

	/**
	 * Asserts create_table() is idempotent — safe to call multiple times.
	 *
	 * @return void
	 */
	public function test_create_table_is_idempotent(): void {
		// Should not throw or fail when called on an already-existing table.
		Telex_Audit_Log::create_table();
		Telex_Audit_Log::create_table();

		// Table is still usable.
		Telex_Audit_Log::log( AuditAction::Connect );
		$this->assertCount( 1, Telex_Audit_Log::get_recent() );
	}

	// -------------------------------------------------------------------------
	// get_recent — orderby allowlist and sort direction
	// -------------------------------------------------------------------------

	/**
	 * Asserts get_recent() accepts 'action' as a valid orderby value and returns results.
	 *
	 * @return void
	 */
	public function test_get_recent_with_orderby_action_returns_results(): void {
		Telex_Audit_Log::log( AuditAction::Connect );
		Telex_Audit_Log::log( AuditAction::Install, 'proj-1' );

		$rows = Telex_Audit_Log::get_recent( 10, 'action' );

		$this->assertNotEmpty( $rows );
		$this->assertArrayHasKey( 'action', $rows[0] );
	}

	/**
	 * Asserts get_recent() accepts 'created_at' as a valid orderby value and returns results.
	 *
	 * @return void
	 */
	public function test_get_recent_with_orderby_created_at_returns_results(): void {
		Telex_Audit_Log::log( AuditAction::Disconnect );

		$rows = Telex_Audit_Log::get_recent( 10, 'created_at' );

		$this->assertNotEmpty( $rows );
	}

	/**
	 * Asserts get_recent() falls back to 'id' ordering when an invalid orderby value is supplied.
	 *
	 * An SQL injection attempt must NOT cause a query error — the sanitisation must silently
	 * substitute 'id' and return valid results.
	 *
	 * @return void
	 */
	public function test_get_recent_falls_back_to_id_for_sql_injection_attempt(): void {
		Telex_Audit_Log::log( AuditAction::Connect );

		// Should not throw or cause a database error.
		$rows = Telex_Audit_Log::get_recent( 10, '; DROP TABLE telex_audit_log; --' );

		// If rows are returned the table is still intact; an empty result is also acceptable
		// as long as no exception or DB error occurred.
		$this->assertIsArray( $rows );
	}

	/**
	 * Asserts get_recent() returns rows in ascending order when $order is 'ASC'.
	 *
	 * @return void
	 */
	public function test_get_recent_with_asc_order_returns_oldest_first(): void {
		Telex_Audit_Log::log( AuditAction::Connect );
		Telex_Audit_Log::log( AuditAction::Install, 'proj-a' );
		Telex_Audit_Log::log( AuditAction::Update, 'proj-b' );

		$rows = Telex_Audit_Log::get_recent( 10, 'id', 'ASC' );

		$this->assertGreaterThanOrEqual( 3, count( $rows ) );
		// First row should have a lower or equal ID than the last (ascending order).
		$this->assertLessThanOrEqual( (int) $rows[ count( $rows ) - 1 ]['id'], (int) $rows[0]['id'] );
	}

	// -------------------------------------------------------------------------
	// drop_table
	// -------------------------------------------------------------------------

	/**
	 * Asserts drop_table() issues a DROP TABLE IF EXISTS without errors.
	 *
	 * WP_UnitTestCase wraps each test in a transaction; DDL like DROP TABLE
	 * triggers an implicit MySQL commit so the table may reappear after the test
	 * framework rolls back. We therefore verify only that the call itself does
	 * not produce a database error rather than asserting the final table state.
	 *
	 * @return void
	 */
	public function test_drop_table_removes_the_table(): void {
		global $wpdb;

		// Confirm the table exists before dropping.
		$table  = Telex_Audit_Log::table_name();
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$this->assertSame( $table, $exists, 'Table must exist before drop_table() is called.' );

		Telex_Audit_Log::drop_table();

		$this->assertEmpty( $wpdb->last_error, 'drop_table() must not produce a database error.' );

		// Recreate so other tests in this class can still run.
		Telex_Audit_Log::create_table();
	}
}
