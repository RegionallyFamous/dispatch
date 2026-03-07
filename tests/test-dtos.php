<?php
/**
 * Tests for Telex DTOs — Telex_Project, Telex_Build_File, Telex_Api_Credentials.
 *
 * @package Dispatch_For_Telex
 */

/**
 * Unit tests for the Telex DTO classes.
 */
class Test_Telex_DTOs extends WP_UnitTestCase {

	// -------------------------------------------------------------------------
	// Telex_Project
	// -------------------------------------------------------------------------

	/**
	 * Asserts from_api() correctly maps all standard fields.
	 *
	 * @return void
	 */
	public function test_project_from_api_maps_all_fields(): void {
		$data = [
			'publicId'       => 'proj-abc',
			'name'           => 'My Block Plugin',
			'slug'           => 'my-block-plugin',
			'projectType'    => 'block',
			'currentVersion' => 42,
			'isShared'       => true,
			'isOwner'        => false,
		];

		$project = Telex_Project::from_api( $data );

		$this->assertSame( 'proj-abc', $project->public_id );
		$this->assertSame( 'My Block Plugin', $project->name );
		$this->assertSame( 'my-block-plugin', $project->slug );
		$this->assertSame( ProjectType::Block, $project->type );
		$this->assertSame( 42, $project->current_version );
		$this->assertTrue( $project->is_shared );
		$this->assertFalse( $project->is_owner );
	}

	/**
	 * Asserts from_api() maps a theme projectType to ProjectType::Theme.
	 *
	 * @return void
	 */
	public function test_project_from_api_maps_theme_type(): void {
		$project = Telex_Project::from_api(
			[
				'publicId'    => 'proj-t',
				'name'        => 'My Theme',
				'slug'        => 'my-theme',
				'projectType' => 'theme',
			]
		);

		$this->assertSame( ProjectType::Theme, $project->type );
	}

	/**
	 * Asserts from_api() uses safe defaults when optional fields are absent.
	 *
	 * @return void
	 */
	public function test_project_from_api_uses_defaults_for_missing_fields(): void {
		$project = Telex_Project::from_api( [ 'publicId' => 'proj-min' ] );

		$this->assertSame( 'proj-min', $project->public_id );
		$this->assertSame( '', $project->name );
		$this->assertSame( '', $project->slug );
		$this->assertSame( ProjectType::Block, $project->type );
		$this->assertSame( 0, $project->current_version );
		$this->assertFalse( $project->is_shared );
		$this->assertFalse( $project->is_owner );
	}

	/**
	 * Asserts to_array() serialises the DTO back to the expected associative array.
	 *
	 * @return void
	 */
	public function test_project_to_array_round_trips(): void {
		$data = [
			'publicId'       => 'proj-rt',
			'name'           => 'Round Trip',
			'slug'           => 'round-trip',
			'projectType'    => 'block',
			'currentVersion' => 7,
			'isShared'       => false,
			'isOwner'        => true,
		];

		$project = Telex_Project::from_api( $data );
		$result  = $project->to_array();

		$this->assertSame( 'proj-rt', $result['publicId'] );
		$this->assertSame( 'Round Trip', $result['name'] );
		$this->assertSame( 'round-trip', $result['slug'] );
		$this->assertSame( 'block', $result['projectType'] );
		$this->assertSame( 7, $result['currentVersion'] );
		$this->assertFalse( $result['isShared'] );
		$this->assertTrue( $result['isOwner'] );
	}

	/**
	 * Asserts Telex_Project is readonly — writing to a property throws an Error.
	 *
	 * @return void
	 */
	public function test_project_is_readonly(): void {
		$project = Telex_Project::from_api( [ 'publicId' => 'proj-ro' ] );

		$this->expectException( \Error::class );
		// @phpstan-ignore-next-line — deliberate illegal write to test readonly enforcement.
		$project->name = 'mutated';
	}

	/**
	 * Asserts two Telex_Project instances with identical data compare as equal.
	 *
	 * @return void
	 */
	public function test_project_equality(): void {
		$data = [
			'publicId'       => 'proj-eq',
			'name'           => 'Equal',
			'currentVersion' => 1,
		];
		$a    = Telex_Project::from_api( $data );
		$b    = Telex_Project::from_api( $data );

		$this->assertEquals( $a, $b );
	}

	// -------------------------------------------------------------------------
	// Telex_Build_File
	// -------------------------------------------------------------------------

	/**
	 * Asserts from_array() maps the path field.
	 *
	 * @return void
	 */
	public function test_build_file_from_array_maps_path(): void {
		$file = Telex_Build_File::from_array( [ 'path' => 'index.js' ] );
		$this->assertSame( 'index.js', $file->path );
	}

	/**
	 * Asserts from_array() maps size and sha256 when present.
	 *
	 * @return void
	 */
	public function test_build_file_from_array_maps_size_and_sha256(): void {
		$file = Telex_Build_File::from_array(
			[
				'path'   => 'main.css',
				'size'   => 1024,
				'sha256' => 'abc123',
			]
		);

		$this->assertSame( 1024, $file->size );
		$this->assertSame( 'abc123', $file->sha256 );
	}

	/**
	 * Asserts from_array() uses safe defaults for missing size and sha256.
	 *
	 * @return void
	 */
	public function test_build_file_from_array_uses_defaults_for_missing_fields(): void {
		$file = Telex_Build_File::from_array( [ 'path' => 'index.php' ] );

		$this->assertSame( 0, $file->size );
		$this->assertSame( '', $file->sha256 );
	}

	/**
	 * Asserts Telex_Build_File is readonly — writing to a property throws an Error.
	 *
	 * @return void
	 */
	public function test_build_file_is_readonly(): void {
		$file = Telex_Build_File::from_array( [ 'path' => 'style.css' ] );

		$this->expectException( \Error::class );
		// @phpstan-ignore-next-line — deliberate illegal write.
		$file->path = 'mutated';
	}

	// -------------------------------------------------------------------------
	// Telex_Api_Credentials
	// -------------------------------------------------------------------------

	/**
	 * Asserts the constructor stores token and base_url correctly.
	 *
	 * @return void
	 */
	public function test_api_credentials_stores_token_and_base_url(): void {
		$creds = new Telex_Api_Credentials( 'tok-123', 'https://api.example.com' );

		$this->assertSame( 'tok-123', $creds->token );
		$this->assertSame( 'https://api.example.com', $creds->base_url );
	}

	/**
	 * Asserts the default timeout is 15 seconds.
	 *
	 * @return void
	 */
	public function test_api_credentials_default_timeout_is_15(): void {
		$creds = new Telex_Api_Credentials( 'tok', 'https://api.example.com' );
		$this->assertSame( 15, $creds->timeout );
	}

	/**
	 * Asserts a custom timeout can be specified.
	 *
	 * @return void
	 */
	public function test_api_credentials_accepts_custom_timeout(): void {
		$creds = new Telex_Api_Credentials( 'tok', 'https://api.example.com', 30 );
		$this->assertSame( 30, $creds->timeout );
	}

	/**
	 * Asserts Telex_Api_Credentials is readonly — writing to a property throws an Error.
	 *
	 * @return void
	 */
	public function test_api_credentials_is_readonly(): void {
		$creds = new Telex_Api_Credentials( 'tok', 'https://api.example.com' );

		$this->expectException( \Error::class );
		// @phpstan-ignore-next-line — deliberate illegal write.
		$creds->token = 'mutated';
	}
}
