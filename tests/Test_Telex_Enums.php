<?php
/**
 * Tests for all Telex backed enums — ProjectType, AuthStatus, AuditAction, InstallStatus.
 *
 * @package Dispatch_For_Telex
 */

/**
 * Unit tests for all Telex enum types.
 */
class Test_Telex_Enums extends WP_UnitTestCase {

	// -------------------------------------------------------------------------
	// ProjectType
	// -------------------------------------------------------------------------

	/**
	 * Asserts from_api() returns Block when passed null.
	 *
	 * @return void
	 */
	public function test_project_type_from_api_null_returns_block(): void {
		$this->assertSame( ProjectType::Block, ProjectType::from_api( null ) );
	}

	/**
	 * Asserts from_api() returns Block for the literal 'block' string.
	 *
	 * @return void
	 */
	public function test_project_type_from_api_block_string_returns_block(): void {
		$this->assertSame( ProjectType::Block, ProjectType::from_api( 'block' ) );
	}

	/**
	 * Asserts from_api() returns Theme when the string contains 'theme' (case-insensitive).
	 *
	 * @return void
	 */
	public function test_project_type_from_api_theme_lowercase(): void {
		$this->assertSame( ProjectType::Theme, ProjectType::from_api( 'theme' ) );
	}

	/**
	 * Asserts from_api() returns Theme for mixed-case 'Theme'.
	 *
	 * @return void
	 */
	public function test_project_type_from_api_theme_mixed_case(): void {
		$this->assertSame( ProjectType::Theme, ProjectType::from_api( 'Theme' ) );
	}

	/**
	 * Asserts from_api() returns Theme for a compound string containing 'theme'.
	 *
	 * @return void
	 */
	public function test_project_type_from_api_compound_theme_string(): void {
		$this->assertSame( ProjectType::Theme, ProjectType::from_api( 'wordpress_theme' ) );
	}

	/**
	 * Asserts from_api() defaults to Block for unrecognised strings.
	 *
	 * @return void
	 */
	public function test_project_type_from_api_unknown_string_returns_block(): void {
		$this->assertSame( ProjectType::Block, ProjectType::from_api( 'plugin' ) );
	}

	/**
	 * Asserts install_capability() returns 'install_plugins' for Block.
	 *
	 * @return void
	 */
	public function test_project_type_block_install_capability(): void {
		$this->assertSame( 'install_plugins', ProjectType::Block->install_capability() );
	}

	/**
	 * Asserts install_capability() returns 'install_themes' for Theme.
	 *
	 * @return void
	 */
	public function test_project_type_theme_install_capability(): void {
		$this->assertSame( 'install_themes', ProjectType::Theme->install_capability() );
	}

	/**
	 * Asserts remove_capability() returns 'delete_plugins' for Block.
	 *
	 * @return void
	 */
	public function test_project_type_block_remove_capability(): void {
		$this->assertSame( 'delete_plugins', ProjectType::Block->remove_capability() );
	}

	/**
	 * Asserts remove_capability() returns 'delete_themes' for Theme.
	 *
	 * @return void
	 */
	public function test_project_type_theme_remove_capability(): void {
		$this->assertSame( 'delete_themes', ProjectType::Theme->remove_capability() );
	}

	/**
	 * Asserts label() returns a non-empty string for both cases.
	 *
	 * @return void
	 */
	public function test_project_type_label_is_non_empty(): void {
		$this->assertNotEmpty( ProjectType::Block->label() );
		$this->assertNotEmpty( ProjectType::Theme->label() );
	}

	/**
	 * Asserts label() returns distinct strings for Block and Theme.
	 *
	 * @return void
	 */
	public function test_project_type_block_and_theme_labels_are_distinct(): void {
		$this->assertNotSame( ProjectType::Block->label(), ProjectType::Theme->label() );
	}

	/**
	 * Asserts ProjectType backed values match the expected strings.
	 *
	 * @return void
	 */
	public function test_project_type_backed_values(): void {
		$this->assertSame( 'block', ProjectType::Block->value );
		$this->assertSame( 'theme', ProjectType::Theme->value );
	}

	/**
	 * Asserts ProjectType can be reconstructed from its string value.
	 *
	 * @return void
	 */
	public function test_project_type_from_string(): void {
		$this->assertSame( ProjectType::Block, ProjectType::from( 'block' ) );
		$this->assertSame( ProjectType::Theme, ProjectType::from( 'theme' ) );
	}

	// -------------------------------------------------------------------------
	// AuthStatus
	// -------------------------------------------------------------------------

	/**
	 * Asserts all AuthStatus backed values match their expected strings.
	 *
	 * @return void
	 */
	public function test_auth_status_backed_values(): void {
		$this->assertSame( 'connected', AuthStatus::Connected->value );
		$this->assertSame( 'disconnected', AuthStatus::Disconnected->value );
	}

	/**
	 * Asserts AuthStatus cases are exhaustive — exactly 2 cases exist.
	 *
	 * @return void
	 */
	public function test_auth_status_case_count(): void {
		$this->assertCount( 2, AuthStatus::cases() );
	}

	/**
	 * Asserts AuthStatus can be reconstructed from its string value.
	 *
	 * @return void
	 */
	public function test_auth_status_from_string(): void {
		$this->assertSame( AuthStatus::Connected, AuthStatus::from( 'connected' ) );
		$this->assertSame( AuthStatus::Disconnected, AuthStatus::from( 'disconnected' ) );
	}

	// -------------------------------------------------------------------------
	// AuditAction
	// -------------------------------------------------------------------------

	/**
	 * Asserts all AuditAction backed values match their expected strings.
	 *
	 * @return void
	 */
	public function test_audit_action_backed_values(): void {
		$this->assertSame( 'install', AuditAction::Install->value );
		$this->assertSame( 'remove', AuditAction::Remove->value );
		$this->assertSame( 'connect', AuditAction::Connect->value );
		$this->assertSame( 'disconnect', AuditAction::Disconnect->value );
		$this->assertSame( 'update', AuditAction::Update->value );
	}

	/**
	 * Asserts AuditAction cases are exhaustive — exactly 7 cases exist.
	 *
	 * @return void
	 */
	public function test_audit_action_case_count(): void {
		$this->assertCount( 7, AuditAction::cases() );
	}

	/**
	 * Asserts AuditAction can be reconstructed from its string value.
	 *
	 * @return void
	 */
	public function test_audit_action_from_string(): void {
		$this->assertSame( AuditAction::Connect, AuditAction::from( 'connect' ) );
		$this->assertSame( AuditAction::Disconnect, AuditAction::from( 'disconnect' ) );
	}
}
