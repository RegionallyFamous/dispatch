<?php
/**
 * Telex project installer and removal handler.
 *
 * @package Dispatch_For_Telex
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Installs and removes Telex projects (blocks and themes) via the WP upgrader API.
 */
class Telex_Installer {

	/**
	 * File extensions that must never appear in a downloaded build.
	 *
	 * Includes PHP execution aliases (phtml, php3–php8, phps, pht) that some
	 * Apache / nginx / LiteSpeed configs treat as PHP, PHAR archives that the
	 * PHP runtime can execute directly, legacy SSI types, and server-side
	 * scripting languages / shell interpreters that should never live inside a
	 * WordPress plugin or theme directory.
	 */
	private const BLOCKED_EXTENSIONS = [
		// PHP execution aliases.
		'phtml',
		'php3',
		'php4',
		'php5',
		'php7',
		'php8',
		'phps',
		'pht',
		// PHP archive — executable by the PHP runtime.
		'phar',
		// Server-Side Includes.
		'shtml',
		'shtm',
		// Other server-side scripting / shell interpreters.
		'cgi',
		'pl',
		'py',
		'rb',
		'sh',
		'bash',
	];

	/**
	 * Transient key for storing the expected file count while `upgrader_source_selection` runs.
	 * Allows the filter to verify ZIP integrity before files are moved into place.
	 */
	private const TRANSIENT_EXPECTED = 'telex_expected_files_';

	// -------------------------------------------------------------------------
	// Install / update
	// -------------------------------------------------------------------------

	/**
	 * Downloads, validates, and installs a Telex project via the WP Upgrader API.
	 *
	 * @param string                      $public_id         The Telex project public ID.
	 * @param bool                        $activate          Whether to activate the plugin immediately after install.
	 * @param array<string, mixed>|null   $pre_fetched_build Optional build data already fetched by the REST controller.
	 *                                                       When provided, the installer skips its own getBuild() call,
	 *                                                       avoiding a duplicate round-trip that can race with the
	 *                                                       Telex API's build-readiness state.
	 * @param \Telex\Sdk\TelexClient|null $client            Optional pre-configured SDK client (used in tests).
	 *                                                       Falls back to Telex_Auth::get_client() when null.
	 * @return true|\WP_Error
	 */
	public static function install( string $public_id, bool $activate = false, ?array $pre_fetched_build = null, ?\Telex\Sdk\TelexClient $client = null ): true|\WP_Error {
		$client ??= Telex_Auth::get_client();
		if ( ! $client ) {
			return new \WP_Error( 'telex_not_connected', __( "You're not connected. Link your account from the Dispatch page.", 'dispatch' ) );
		}

		try {
			$project = $client->projects->get( $public_id );
			$type    = ProjectType::from_api( $project['projectType'] ?? null );

			if ( ! current_user_can( $type->install_capability() ) ) {
				return new \WP_Error( 'telex_caps', __( "You don't have permission to install this type of project.", 'dispatch' ) );
			}

			// Reuse pre-fetched build data when available to avoid a duplicate
			// API call that can race with the build-readiness state on Telex's side.
			$build = $pre_fetched_build ?? $client->projects->getBuild( $public_id );

			if ( isset( $build['status'] ) && 'not_ready' === $build['status'] ) {
				return new \WP_Error( 'telex_not_ready', __( "This build isn't ready yet — give it a second and try again.", 'dispatch' ) );
			}

			if ( empty( $build['files'] ) ) {
				return new \WP_Error( 'telex_no_files', __( 'This build looks empty. Contact the project author.', 'dispatch' ) );
			}

			$build_files = array_map( Telex_Build_File::from_array( ... ), $build['files'] );
			$tmp_dir     = self::download_files( $client, $public_id, $build_files, $project['slug'] );
			if ( is_wp_error( $tmp_dir ) ) {
				return $tmp_dir;
			}

			$zip_path = self::create_zip( $tmp_dir, $project['slug'] );
			if ( is_wp_error( $zip_path ) ) {
				self::cleanup( $tmp_dir );
				return $zip_path;
			}

			// Store expected file list before upgrader runs so the
			// upgrader_source_selection filter can verify integrity.
			// Keyed on slug because verify_source resolves the slug from the path.
			set_transient(
				self::TRANSIENT_EXPECTED . md5( $project['slug'] ),
				array_column( $build['files'], 'path' ),
				MINUTE_IN_SECONDS
			);

			add_filter( 'upgrader_source_selection', self::verify_source( ... ), 10, 4 );
			$result = self::run_upgrader( $zip_path, $type );
			remove_filter( 'upgrader_source_selection', self::verify_source( ... ), 10 );

			delete_transient( self::TRANSIENT_EXPECTED . md5( $project['slug'] ) );
			self::cleanup( $tmp_dir );

			global $wp_filesystem;
			if ( $wp_filesystem ) {
				$wp_filesystem->delete( $zip_path );
			} else {
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				@unlink( $zip_path );
			}

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			// The Telex API sometimes returns the currently-deployed version in
			// projects.get() rather than the latest-build version. To ensure we
			// always track the version that was actually installed, we take the
			// higher of the API value and the per-project cache value (which was
			// populated when the "update available" notice was computed and
			// accurately reflects the build the user intended to install).
			$api_version    = (int) ( $project['currentVersion'] ?? 0 );
			$cached_project = Telex_Cache::get_project( $public_id );
			$cached_version = $cached_project ? (int) ( $cached_project['currentVersion'] ?? 0 ) : 0;
			$version        = max( $api_version, $cached_version );

			// Determine action BEFORE overwriting the tracker entry.
			$action = Telex_Tracker::is_installed( $public_id ) ? AuditAction::Update : AuditAction::Install;

			Telex_Tracker::track( $public_id, $version, $type->value, $project['slug'] );
			Telex_Cache::bust_project( $public_id );

			// Optional post-install activation.
			if ( $activate && ProjectType::Block === $type ) {
				$plugin_file = self::find_plugin_file( $project['slug'] );
				if ( $plugin_file ) {
					$activated = activate_plugin( $plugin_file );
					if ( is_wp_error( $activated ) ) {
						// Log activation failure but don't roll back the install.
						wp_trigger_error( __METHOD__, 'Plugin activation failed: ' . $activated->get_error_message(), E_USER_WARNING );
					}
				}
			}

			Telex_Audit_Log::log(
				$action,
				$public_id,
				[
					'slug'    => $project['slug'],
					'version' => $version,
					'type'    => $type->value,
				]
			);

			return true;

		} catch ( \Telex\Sdk\Exceptions\TelexException $e ) {
			return new \WP_Error( 'telex_api', $e->getMessage() );
		} catch ( \Exception $e ) {
			return new \WP_Error(
				'telex_install',
				sprintf(
				/* translators: %s: error message */
					__( 'Installation failed: %s', 'dispatch' ),
					$e->getMessage()
				)
			);
		}
	}

	// -------------------------------------------------------------------------
	// Remove
	// -------------------------------------------------------------------------

	/**
	 * Removes an installed Telex project from this WordPress site.
	 *
	 * @param string $public_id The Telex project public ID.
	 * @return true|\WP_Error
	 */
	public static function remove( string $public_id ): true|\WP_Error {
		$tracked = Telex_Tracker::get( $public_id );
		if ( ! $tracked ) {
			return new \WP_Error( 'telex_not_installed', __( "This project isn't installed on your site.", 'dispatch' ) );
		}

		$type = ProjectType::from( $tracked['type'] );
		$slug = $tracked['slug'];

		if ( ! current_user_can( $type->remove_capability() ) ) {
			return new \WP_Error( 'telex_caps', __( "You don't have permission to remove this type of project.", 'dispatch' ) );
		}

		if ( ProjectType::Theme === $type ) {
			$theme = wp_get_theme( $slug );
			if ( $theme->exists() ) {
				if ( wp_get_theme()->get_stylesheet() === $slug ) {
					return new \WP_Error( 'telex_active_theme', __( "That's your active theme! Switch themes first, then remove this one.", 'dispatch' ) );
				}
				$result = delete_theme( $slug );
				if ( is_wp_error( $result ) ) {
					return $result;
				}
			}
		} else {
			$plugin_dir = WP_PLUGIN_DIR . '/' . $slug;
			if ( is_dir( $plugin_dir ) ) {
				$plugin_file = self::find_plugin_file( $slug );
				if ( $plugin_file && is_plugin_active( $plugin_file ) ) {
					deactivate_plugins( $plugin_file );
				}
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
				require_once ABSPATH . 'wp-admin/includes/file.php';
				$result = delete_plugins( [ '' !== $plugin_file ? $plugin_file : $slug . '/' . $slug . '.php' ] );
				if ( is_wp_error( $result ) ) {
					return $result;
				}
			}
		}

		Telex_Tracker::untrack( $public_id );
		Telex_Cache::bust_project( $public_id );

		Telex_Audit_Log::log(
			AuditAction::Remove,
			$public_id,
			[
				'slug' => $slug,
				'type' => $type->value,
			]
		);

		return true;
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Downloads all build files into a temporary directory.
	 *
	 * @param \Telex\Sdk\TelexClient $client    An authenticated SDK client.
	 * @param string                 $public_id The Telex project public ID.
	 * @param Telex_Build_File[]     $files     List of build file descriptors.
	 * @param string                 $slug      The WordPress plugin/theme slug.
	 * @return string|\WP_Error The temp directory path, or a WP_Error on failure.
	 */
	private static function download_files(
		\Telex\Sdk\TelexClient $client,
		string $public_id,
		array $files,
		string $slug
	): string|\WP_Error {
		// Use WP_Filesystem for all file operations.
		global $wp_filesystem;
		if ( ! $wp_filesystem ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			WP_Filesystem();
		}

		// Use tempnam() to atomically create a unique placeholder file, then
		// immediately replace it with a directory. This avoids the TOCTOU race
		// in the wp_tempnam() + unlink() + mkdir() sequence.
		$tmp_placeholder = tempnam( sys_get_temp_dir(), 'telex_' );
		if ( false === $tmp_placeholder ) {
			return new \WP_Error( 'telex_mkdir', __( 'Could not create temporary directory.', 'dispatch' ) );
		}

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		@unlink( $tmp_placeholder );
		$tmp_dir = $tmp_placeholder . '_dir';

		$project_dir = $tmp_dir . '/' . $slug;

		if ( ! wp_mkdir_p( $project_dir ) ) {
			return new \WP_Error( 'telex_mkdir', __( 'Could not create temporary directory.', 'dispatch' ) );
		}

		foreach ( $files as $file ) {
			$path = $file->path;

			// Path traversal check.
			if ( str_contains( $path, '..' ) || str_starts_with( $path, '/' ) || str_starts_with( $path, '\\' ) ) {
				self::cleanup( $tmp_dir );
				return new \WP_Error(
					'telex_path',
					sprintf(
					/* translators: %s: file path */
						__( 'Unsafe file path rejected: %s', 'dispatch' ),
						$path
					)
				);
			}

			// Extension blocklist — also checked in upgrader_source_selection, but defense-in-depth.
			$extension = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
			if ( in_array( $extension, self::BLOCKED_EXTENSIONS, true ) ) {
				self::cleanup( $tmp_dir );
				return new \WP_Error(
					'telex_ext',
					sprintf(
					/* translators: %s: file path */
						__( 'Blocked file extension in: %s', 'dispatch' ),
						$path
					)
				);
			}

			$file_path = $project_dir . '/' . $path;
			$dir       = dirname( $file_path );

			if ( ! wp_mkdir_p( $dir ) ) {
				self::cleanup( $tmp_dir );
				return new \WP_Error(
					'telex_mkdir',
					sprintf(
					/* translators: %s: directory path */
						__( 'Could not create directory: %s', 'dispatch' ),
						$dir
					)
				);
			}

			// ZipSlip protection: resolved path must stay within project directory.
			$real_dir         = realpath( $dir );
			$real_project_dir = realpath( $project_dir );

			if ( false === $real_dir || false === $real_project_dir || ! str_starts_with( $real_dir, $real_project_dir ) ) {
				self::cleanup( $tmp_dir );
				return new \WP_Error(
					'telex_path',
					sprintf(
					/* translators: %s: file path */
						__( 'File path escapes project directory: %s', 'dispatch' ),
						$path
					)
				);
			}

			try {
				$content = $client->projects->getBuildFile( $public_id, $file->path );
			} catch ( \Exception $e ) {
				self::cleanup( $tmp_dir );
				return new \WP_Error(
					'telex_download',
					sprintf(
					/* translators: %s: file path */
						__( 'Failed to download file: %s', 'dispatch' ),
						$file->path
					)
				);
			}

			// Verify SHA-256 checksum against the build manifest.
			if ( '' !== $file->sha256 && hash( 'sha256', $content ) !== $file->sha256 ) {
				self::cleanup( $tmp_dir );
				return new \WP_Error(
					'telex_checksum',
					sprintf(
					/* translators: %s: file path */
						__( 'Checksum mismatch for file: %s — the download may be corrupted.', 'dispatch' ),
						$file->path
					)
				);
			}

			$wp_filesystem->put_contents( $file_path, $content, FS_CHMOD_FILE );
		}

		return $tmp_dir;
	}

	/**
	 * Creates a ZIP archive from the contents of a source directory.
	 *
	 * @param string $source_dir Path to the directory to zip.
	 * @param string $slug       The plugin/theme slug (used for the ZIP entry root).
	 * @return string|\WP_Error Path to the created ZIP, or a WP_Error on failure.
	 */
	private static function create_zip( string $source_dir, string $slug ): string|\WP_Error { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		$zip_path = wp_tempnam( 'telex_' ) . '.zip';

		$zip = new \ZipArchive();
		if ( true !== $zip->open( $zip_path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE ) ) {
			return new \WP_Error( 'telex_zip', __( 'Could not create zip archive.', 'dispatch' ) );
		}

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $source_dir, \RecursiveDirectoryIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::LEAVES_ONLY
		);

		foreach ( $iterator as $file ) {
			if ( $file->isFile() ) {
				$relative = substr( $file->getPathname(), strlen( $source_dir ) + 1 );
				$zip->addFile( $file->getPathname(), $relative );
			}
		}

		$zip->close();

		return $zip_path;
	}

	/**
	 * Runs the WordPress Upgrader to install a ZIP package.
	 *
	 * @param string      $zip_path Path to the ZIP file to install.
	 * @param ProjectType $type     Whether to use Plugin_Upgrader or Theme_Upgrader.
	 * @return true|\WP_Error
	 */
	private static function run_upgrader( string $zip_path, ProjectType $type ): true|\WP_Error {
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader-skin.php';

		$skin = new \WP_Ajax_Upgrader_Skin();

		$upgrader = match ( $type ) {
			ProjectType::Theme => new \Theme_Upgrader( $skin ),
			ProjectType::Block => new \Plugin_Upgrader( $skin ),
		};

		$result = $upgrader->install( $zip_path, [ 'overwrite_package' => true ] );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( true !== $result ) {
			$errors = $skin->get_errors();
			if ( is_wp_error( $errors ) && $errors->has_errors() ) {
				return $errors;
			}
			return new \WP_Error( 'telex_install', __( 'Installation failed. Check your connection and try again.', 'dispatch' ) );
		}

		return true;
	}

	/**
	 * `upgrader_source_selection` filter — fires after WP unpacks the ZIP into a temp
	 * directory but before it moves files into the plugin/theme directory.
	 * Returning a WP_Error here aborts the install cleanly.
	 *
	 * @param string|\WP_Error    $source    Temp dir path or existing error.
	 * @param string              $_remote     Remote package URL (unused).
	 * @param object              $_upgrader   Upgrader instance (unused; WP_Upgrader at runtime).
	 * @param array<string,mixed> $_hook_extra Extra args passed to upgrader (unused).
	 * @return string|\WP_Error
	 */
	public static function verify_source( // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- hook signature is fixed by WP_Upgrader
		string|\WP_Error $source,
		string $_remote,
		object $_upgrader,
		array $_hook_extra
	): string|\WP_Error {
		if ( is_wp_error( $source ) ) {
			return $source;
		}

		// Verify file count matches the list recorded before extraction.
		// This catches truncated downloads and tampered archives.
		$source_parts = explode( '/', rtrim( $source, '/' ) );
		$slug_guess   = end( $source_parts );
		$expected     = get_transient( self::TRANSIENT_EXPECTED . md5( $slug_guess ) );
		if ( is_array( $expected ) ) {
			$count_iter   = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( $source, \RecursiveDirectoryIterator::SKIP_DOTS ),
				\RecursiveIteratorIterator::LEAVES_ONLY
			);
			$actual_count = iterator_count( $count_iter );
			if ( count( $expected ) !== $actual_count ) {
				return new \WP_Error(
					'telex_integrity',
					sprintf(
						/* translators: 1: expected count, 2: actual count */
						__( 'Package integrity check failed: expected %1$d files, found %2$d.', 'dispatch' ),
						count( $expected ),
						$actual_count
					)
				);
			}
		}

		// Scan unpacked files for blocked extensions.
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $source, \RecursiveDirectoryIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::LEAVES_ONLY
		);

		foreach ( $iterator as $file ) {
			if ( ! $file->isFile() ) {
				continue;
			}
			$ext = strtolower( pathinfo( $file->getPathname(), PATHINFO_EXTENSION ) );
			if ( in_array( $ext, self::BLOCKED_EXTENSIONS, true ) ) {
				return new \WP_Error(
					'telex_blocked_ext',
					sprintf(
						/* translators: %s: file path */
						__( 'Blocked file extension found in package: %s', 'dispatch' ),
						$file->getPathname()
					)
				);
			}
		}

		return $source;
	}

	/**
	 * Finds the main plugin file path for a given directory slug.
	 *
	 * @param string $slug The WordPress plugin directory slug.
	 * @return string Plugin file path relative to plugins dir, or empty string.
	 */
	private static function find_plugin_file( string $slug ): string {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		foreach ( get_plugins() as $file => $data ) {
			if ( str_starts_with( $file, $slug . '/' ) ) {
				return $file;
			}
		}
		return '';
	}

	/**
	 * Recursively removes a temporary directory.
	 *
	 * @param string $dir Path to the directory to remove.
	 * @return void
	 */
	private static function cleanup( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}

		global $wp_filesystem;
		if ( $wp_filesystem ) {
			$wp_filesystem->delete( $dir, true );
			return;
		}

		// Fallback if WP_Filesystem isn't initialised (rare edge case).
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $dir, \RecursiveDirectoryIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $iterator as $item ) {
			if ( $item->isDir() ) {
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				@rmdir( $item->getPathname() );
			} else {
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				@unlink( $item->getPathname() );
			}
		}

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		@rmdir( $dir );
	}
}
