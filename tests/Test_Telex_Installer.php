<?php
/**
 * Tests for Telex_Installer — connection guards, extension blocklist, and path validation.
 *
 * @package Dispatch_For_Telex
 */

/**
 * Unit tests for the Telex_Installer class.
 */
class Test_Telex_Installer extends WP_UnitTestCase {

	/**
	 * Temporary directory created during tests.
	 *
	 * @var string
	 */
	private string $tmp_dir = '';

	/**
	 * Reset auth state before each test.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();
		delete_option( 'telex_auth_token' );
		delete_option( 'telex_installed_projects' );
		$this->tmp_dir = '';
	}

	/**
	 * Remove any temporary directory created during a test.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		if ( '' !== $this->tmp_dir && is_dir( $this->tmp_dir ) ) {
			$this->remove_dir( $this->tmp_dir );
		}
		parent::tearDown();
	}

	// -------------------------------------------------------------------------
	// install — connection guard
	// -------------------------------------------------------------------------

	/**
	 * Asserts install() returns a WP_Error when the plugin is not connected.
	 *
	 * @return void
	 */
	public function test_install_returns_error_when_not_connected(): void {
		$result = Telex_Installer::install( 'proj-abc' );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'telex_not_connected', $result->get_error_code() );
	}

	/**
	 * Asserts install() returns the error code 'telex_not_connected', not a generic one.
	 *
	 * @return void
	 */
	public function test_install_error_message_is_not_empty(): void {
		$result = Telex_Installer::install( 'proj-abc' );
		$this->assertNotEmpty( $result->get_error_message() );
	}

	// -------------------------------------------------------------------------
	// remove — tracking guard
	// -------------------------------------------------------------------------

	/**
	 * Asserts remove() returns a WP_Error when the project is not tracked.
	 *
	 * @return void
	 */
	public function test_remove_returns_error_when_not_installed(): void {
		$result = Telex_Installer::remove( 'proj-not-tracked' );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'telex_not_installed', $result->get_error_code() );
	}

	/**
	 * Asserts remove() returns a capabilities error when user lacks delete_plugins cap.
	 *
	 * @return void
	 */
	public function test_remove_returns_caps_error_when_user_lacks_permission(): void {
		Telex_Tracker::track( 'proj-block', 1, 'block', 'some-block' );
		// Default user (0) has no caps.
		wp_set_current_user( 0 );

		$result = Telex_Installer::remove( 'proj-block' );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'telex_caps', $result->get_error_code() );
	}

	/**
	 * Asserts remove() returns a capabilities error for theme removal without the right cap.
	 *
	 * @return void
	 */
	public function test_remove_returns_caps_error_for_theme_without_permission(): void {
		Telex_Tracker::track( 'proj-theme', 1, 'theme', 'some-theme' );
		wp_set_current_user( 0 );

		$result = Telex_Installer::remove( 'proj-theme' );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'telex_caps', $result->get_error_code() );
	}

	// -------------------------------------------------------------------------
	// BLOCKED_EXTENSIONS constant
	// -------------------------------------------------------------------------

	/**
	 * Asserts the BLOCKED_EXTENSIONS constant includes all expected dangerous extensions.
	 *
	 * @return void
	 */
	public function test_blocked_extensions_constant_includes_all_dangerous_extensions(): void {
		$reflection = new ReflectionClass( Telex_Installer::class );
		$constants  = $reflection->getConstants( ReflectionClassConstant::IS_PRIVATE );

		$this->assertArrayHasKey( 'BLOCKED_EXTENSIONS', $constants );

		$blocked = $constants['BLOCKED_EXTENSIONS'];
		foreach ( [ 'phar', 'phtml', 'php5', 'php3', 'php4', 'php7', 'shtml' ] as $ext ) {
			$this->assertContains( $ext, $blocked, "Extension '$ext' must be blocked." );
		}
	}

	// -------------------------------------------------------------------------
	// verify_source — blocked extensions
	// -------------------------------------------------------------------------

	/**
	 * Asserts verify_source() passes a directory containing only safe files.
	 *
	 * @return void
	 */
	public function test_verify_source_allows_clean_directory(): void {
		$this->tmp_dir = $this->create_temp_dir();

		file_put_contents( $this->tmp_dir . '/index.js', '// safe' );
		file_put_contents( $this->tmp_dir . '/style.css', '/* safe */' );
		file_put_contents( $this->tmp_dir . '/readme.txt', 'Safe.' );

		$result = Telex_Installer::verify_source( $this->tmp_dir, '', new stdClass(), [] );

		$this->assertSame( $this->tmp_dir, $result );
	}

	/**
	 * Asserts verify_source() rejects a directory containing a .phar file.
	 *
	 * @return void
	 */
	public function test_verify_source_rejects_phar_file(): void {
		$this->tmp_dir = $this->create_temp_dir();

		file_put_contents( $this->tmp_dir . '/exploit.phar', '<?php' );

		$result = Telex_Installer::verify_source( $this->tmp_dir, '', new stdClass(), [] );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'telex_blocked_ext', $result->get_error_code() );
	}

	/**
	 * Asserts verify_source() rejects a directory containing a .phtml file.
	 *
	 * @return void
	 */
	public function test_verify_source_rejects_phtml_file(): void {
		$this->tmp_dir = $this->create_temp_dir();

		file_put_contents( $this->tmp_dir . '/template.phtml', '<?php echo "bad"; ?>' );

		$result = Telex_Installer::verify_source( $this->tmp_dir, '', new stdClass(), [] );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'telex_blocked_ext', $result->get_error_code() );
	}

	/**
	 * Asserts verify_source() rejects a directory containing a .php5 file.
	 *
	 * @return void
	 */
	public function test_verify_source_rejects_php5_file(): void {
		$this->tmp_dir = $this->create_temp_dir();

		file_put_contents( $this->tmp_dir . '/script.php5', '<?php' );

		$result = Telex_Installer::verify_source( $this->tmp_dir, '', new stdClass(), [] );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'telex_blocked_ext', $result->get_error_code() );
	}

	/**
	 * Asserts verify_source() rejects blocked files found in subdirectories.
	 *
	 * @return void
	 */
	public function test_verify_source_rejects_blocked_file_in_subdirectory(): void {
		$this->tmp_dir = $this->create_temp_dir();
		$sub           = $this->tmp_dir . '/subdir';

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Test helper; no WP_Filesystem needed outside WordPress context.
		mkdir( $sub );
		file_put_contents( $sub . '/exploit.phar', '<?php' );

		$result = Telex_Installer::verify_source( $this->tmp_dir, '', new stdClass(), [] );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'telex_blocked_ext', $result->get_error_code() );
	}

	/**
	 * Asserts verify_source() passes through a pre-existing WP_Error source unchanged.
	 *
	 * @return void
	 */
	public function test_verify_source_passes_through_existing_wp_error(): void {
		$existing_error = new WP_Error( 'some_prior_error', 'Something went wrong.' );
		$result         = Telex_Installer::verify_source( $existing_error, '', new stdClass(), [] );

		$this->assertSame( $existing_error, $result );
	}

	/**
	 * Asserts verify_source() is case-insensitive when checking extensions.
	 *
	 * @return void
	 */
	public function test_verify_source_extension_check_is_case_insensitive(): void {
		$this->tmp_dir = $this->create_temp_dir();

		file_put_contents( $this->tmp_dir . '/exploit.PHAR', '<?php' );

		$result = Telex_Installer::verify_source( $this->tmp_dir, '', new stdClass(), [] );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'telex_blocked_ext', $result->get_error_code() );
	}

	// -------------------------------------------------------------------------
	// install — with injected mock client
	// -------------------------------------------------------------------------

	/**
	 * Asserts install() returns telex_not_ready when the build status is 'not_ready'.
	 *
	 * @return void
	 */
	public function test_install_returns_not_ready_when_build_building(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$client = $this->make_mock_client(
			project: [
				'publicId'       => 'proj-build',
				'name'           => 'Build Block',
				'slug'           => 'build-block',
				'projectType'    => 'block',
				'currentVersion' => 1,
			],
			build: [ 'status' => 'not_ready' ]
		);

		$result = Telex_Installer::install( 'proj-build', false, null, $client );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'telex_not_ready', $result->get_error_code() );
	}

	/**
	 * Asserts install() returns telex_no_files when the build has an empty files array.
	 *
	 * @return void
	 */
	public function test_install_returns_no_files_when_build_files_empty(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$client = $this->make_mock_client(
			project: [
				'publicId'       => 'proj-empty',
				'name'           => 'Empty Block',
				'slug'           => 'empty-block',
				'projectType'    => 'block',
				'currentVersion' => 1,
			],
			build: [ 'files' => [] ]
		);

		$result = Telex_Installer::install( 'proj-empty', false, null, $client );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'telex_no_files', $result->get_error_code() );
	}

	/**
	 * Asserts install() returns telex_caps when the user lacks install_plugins capability.
	 *
	 * @return void
	 */
	public function test_install_returns_caps_error_for_user_without_install_permission(): void {
		wp_set_current_user( 0 ); // No capabilities.

		$client = $this->make_mock_client(
			project: [
				'publicId'       => 'proj-caps',
				'name'           => 'Caps Block',
				'slug'           => 'caps-block',
				'projectType'    => 'block',
				'currentVersion' => 1,
			],
			build: [
				'files' => [
					[
						'path'   => 'index.js',
						'sha256' => '',
					],
				],
			]
		);

		$result = Telex_Installer::install( 'proj-caps', false, null, $client );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'telex_caps', $result->get_error_code() );
	}

	/**
	 * Asserts install() returns telex_checksum when the downloaded file content does not
	 * match the expected SHA-256 hash from the build manifest.
	 *
	 * @return void
	 */
	public function test_install_returns_checksum_mismatch_when_file_hash_wrong(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$expected_sha = hash( 'sha256', 'correct-content' );

		$client = $this->make_mock_client(
			project: [
				'publicId'       => 'proj-sha',
				'name'           => 'SHA Block',
				'slug'           => 'sha-block',
				'projectType'    => 'block',
				'currentVersion' => 1,
			],
			build: [
				'files' => [
					[
						'path'   => 'index.js',
						'size'   => 10,
						'sha256' => $expected_sha,
					],
				],
			],
			file_content: 'tampered-content' // SHA-256 will not match $expected_sha.
		);

		$result = Telex_Installer::install( 'proj-sha', false, null, $client );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'telex_checksum', $result->get_error_code() );
	}

	// -------------------------------------------------------------------------
	// remove — happy paths
	// -------------------------------------------------------------------------

	/**
	 * Asserts remove() untracks a block project even when the plugin directory is absent.
	 *
	 * WordPress's delete_plugins() is only invoked when is_dir() returns true; when the
	 * directory is already gone, remove() still untracks the entry and returns true.
	 *
	 * @return void
	 */
	public function test_remove_untracks_plugin_project_when_directory_missing(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		Telex_Tracker::track( 'proj-gone-plugin', 1, 'block', 'definitely-absent-plugin-' . wp_generate_uuid4() );

		$result = Telex_Installer::remove( 'proj-gone-plugin' );

		$this->assertTrue( $result );
		$this->assertNull( Telex_Tracker::get( 'proj-gone-plugin' ) );
	}

	/**
	 * Asserts remove() untracks a theme project even when the theme directory is absent.
	 *
	 * @return void
	 */
	public function test_remove_untracks_theme_project_when_directory_missing(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		Telex_Tracker::track( 'proj-gone-theme', 1, 'theme', 'definitely-absent-theme-' . wp_generate_uuid4() );

		$result = Telex_Installer::remove( 'proj-gone-theme' );

		$this->assertTrue( $result );
		$this->assertNull( Telex_Tracker::get( 'proj-gone-theme' ) );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Creates a mock TelexClient that returns configurable project, build, and file data.
	 *
	 * @param array<string,mixed> $project      Data returned by projects->get().
	 * @param array<string,mixed> $build        Data returned by projects->getBuild().
	 * @param string              $file_content Content returned by projects->getBuildFile().
	 * @return \Telex\Sdk\TelexClient
	 */
	private function make_mock_client( array $project, array $build, string $file_content = '' ): \Telex\Sdk\TelexClient {
		return new \Telex\Sdk\TelexClient(
			[
				'token'      => 'test-token',
				'baseUrl'    => 'https://telex.app',
				'httpClient' => new class( $project, $build, $file_content ) implements \Psr\Http\Client\ClientInterface {
					/**
					 * Raw project data to return.
					 *
					 * @var array<string,mixed>
					 */
					private array $project;
					/**
					 * Raw build data to return.
					 *
					 * @var array<string,mixed>
					 */
					private array $build;
					/**
					 * Raw file content to return.
					 *
					 * @var string
					 */
					private string $file_content;

					/**
					 * Stores the stub data for subsequent sendRequest() calls.
					 *
					 * @param array<string,mixed> $project      Raw project data.
					 * @param array<string,mixed> $build        Raw build data.
					 * @param string              $file_content Raw file content.
					 */
					public function __construct( array $project, array $build, string $file_content ) {
						$this->project      = $project;
						$this->build        = $build;
						$this->file_content = $file_content;
					}

					/**
					 * Routes the request to the matching stub response based on the URL path.
					 *
					 * @param \Psr\Http\Message\RequestInterface $request Outgoing PSR-7 request.
					 * @return \Psr\Http\Message\ResponseInterface
					 */
					public function sendRequest( \Psr\Http\Message\RequestInterface $request ): \Psr\Http\Message\ResponseInterface {
						$path = $request->getUri()->getPath();
						if ( str_contains( $path, '/build/file' ) ) {
							return new \Nyholm\Psr7\Response( 200, [], $this->file_content );
						}
						if ( str_contains( $path, '/build' ) ) {
							return new \Nyholm\Psr7\Response( 200, [], (string) json_encode( $this->build ) );
						}
						return new \Nyholm\Psr7\Response( 200, [], (string) json_encode( $this->project ) );
					}
				},
			]
		);
	}

	/**
	 * Creates a temporary directory and returns its path.
	 *
	 * @return string Path to the created directory.
	 */
	private function create_temp_dir(): string {
		$dir = sys_get_temp_dir() . '/telex_test_' . wp_generate_uuid4();
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Test helper; no WP_Filesystem needed outside WordPress context.
		mkdir( $dir, 0755, true );
		return $dir;
	}

	/**
	 * Recursively removes a directory.
	 *
	 * @param string $dir Path to the directory to remove.
	 * @return void
	 */
	private function remove_dir( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$items = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $items as $item ) {
			if ( $item->isDir() ) {
				rmdir( $item->getPathname() );
			} else {
				unlink( $item->getPathname() );
			}
		}
		rmdir( $dir );
	}
}
