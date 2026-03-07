<?php
/**
 * Tests for Telex_Activator — first-run activation and version migration.
 *
 * @package Dispatch_For_Telex
 */

/**
 * Unit tests for the Telex_Activator class.
 */
class Test_Telex_Activator extends WP_UnitTestCase {

	/**
	 * Create the audit log table once for the test class (activate() calls it).
	 *
	 * @return void
	 */
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		Telex_Audit_Log::create_table();
	}

	/**
	 * Reset all plugin options before each test.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();
		delete_option( 'telex_version' );
		delete_option( 'telex_installed_at' );
		Telex_Cache::unschedule_warmup();
	}

	// -------------------------------------------------------------------------
	// activate
	// -------------------------------------------------------------------------

	/**
	 * Asserts activate() records the plugin version in wp_options.
	 *
	 * @return void
	 */
	public function test_activate_sets_version_option(): void {
		Telex_Activator::activate();
		$this->assertSame( TELEX_PLUGIN_VERSION, get_option( 'telex_version' ) );
	}

	/**
	 * Asserts activate() sets the installed_at option on first activation.
	 *
	 * @return void
	 */
	public function test_activate_sets_installed_at_on_first_run(): void {
		Telex_Activator::activate();
		$installed_at = get_option( 'telex_installed_at' );
		$this->assertNotFalse( $installed_at );
		$this->assertNotEmpty( $installed_at );
	}

	/**
	 * Asserts activate() does not overwrite an existing installed_at timestamp.
	 *
	 * @return void
	 */
	public function test_activate_does_not_overwrite_installed_at(): void {
		$original = '2024-01-01T00:00:00+00:00';
		update_option( 'telex_installed_at', $original, false );

		Telex_Activator::activate();

		$this->assertSame( $original, get_option( 'telex_installed_at' ) );
	}

	/**
	 * Asserts activate() creates the audit log table.
	 *
	 * @return void
	 */
	public function test_activate_creates_audit_log_table(): void {
		global $wpdb;

		Telex_Activator::activate();

		$table = Telex_Audit_Log::table_name();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is from $wpdb->prefix.
		$exists = $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" );

		$this->assertSame( $table, $exists );
	}

	/**
	 * Asserts activate() schedules the cache warmup cron event.
	 *
	 * @return void
	 */
	public function test_activate_schedules_cache_warmup(): void {
		Telex_Activator::activate();
		$this->assertNotFalse( wp_next_scheduled( 'telex_cache_warm' ) );
	}

	// -------------------------------------------------------------------------
	// maybe_upgrade
	// -------------------------------------------------------------------------

	/**
	 * Asserts maybe_upgrade() does nothing when the stored version matches current.
	 *
	 * @return void
	 */
	public function test_maybe_upgrade_skips_when_version_matches(): void {
		update_option( 'telex_version', TELEX_PLUGIN_VERSION, false );

		// If maybe_upgrade ran, it would update the option. Capture it as-is.
		Telex_Activator::maybe_upgrade();

		$this->assertSame( TELEX_PLUGIN_VERSION, get_option( 'telex_version' ) );
	}

	/**
	 * Asserts maybe_upgrade() updates the stored version when it is outdated.
	 *
	 * @return void
	 */
	public function test_maybe_upgrade_updates_version_when_outdated(): void {
		update_option( 'telex_version', '0.1.0', false );

		Telex_Activator::maybe_upgrade();

		$this->assertSame( TELEX_PLUGIN_VERSION, get_option( 'telex_version' ) );
	}

	/**
	 * Asserts maybe_upgrade() updates the stored version when the option is missing entirely.
	 *
	 * @return void
	 */
	public function test_maybe_upgrade_runs_when_version_option_missing(): void {
		// Ensure the option is absent.
		delete_option( 'telex_version' );

		Telex_Activator::maybe_upgrade();

		$this->assertSame( TELEX_PLUGIN_VERSION, get_option( 'telex_version' ) );
	}

	/**
	 * Asserts maybe_upgrade() schedules cache warmup when it was unregistered.
	 *
	 * @return void
	 */
	public function test_maybe_upgrade_schedules_cache_warmup(): void {
		update_option( 'telex_version', '0.1.0', false );
		Telex_Cache::unschedule_warmup();

		Telex_Activator::maybe_upgrade();

		$this->assertNotFalse( wp_next_scheduled( 'telex_cache_warm' ) );
	}
}
