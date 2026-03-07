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
}
