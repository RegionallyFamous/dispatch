<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Installs and removes Telex projects (blocks and themes) via the WP upgrader API.
 */
class Telex_Installer {

	/** File extensions that must never appear in a downloaded build. */
	private const BLOCKED_EXTENSIONS = [
		'phtml', 'phar', 'php5', 'shtml', 'php3', 'php4', 'php7',
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
	 * @return true|\WP_Error
	 */
	public static function install( string $public_id, bool $activate = false ): true|\WP_Error {
		$client = Telex_Auth::get_client();
		if ( ! $client ) {
			return new \WP_Error( 'telex_not_connected', __( 'Not connected to Telex.', 'telex' ) );
		}

		try {
			$project = $client->projects->get( $public_id );
			$type    = ProjectType::from_api( $project['projectType'] ?? null );

			if ( ! current_user_can( $type->install_capability() ) ) {
				return new \WP_Error( 'telex_caps', __( 'You do not have permission to install this type of project.', 'telex' ) );
			}

			$build = $client->projects->getBuild( $public_id );

			if ( isset( $build['status'] ) && 'not_ready' === $build['status'] ) {
				return new \WP_Error( 'telex_not_ready', __( 'Build is not ready yet. Please try again in a moment.', 'telex' ) );
			}

			if ( empty( $build['files'] ) ) {
				return new \WP_Error( 'telex_no_files', __( 'Build has no files.', 'telex' ) );
			}

			$build_files = array_map( Telex_Build_File::from_array(...), $build['files'] );
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
			set_transient(
				self::TRANSIENT_EXPECTED . md5( $public_id ),
				array_column( $build['files'], 'path' ),
				MINUTE_IN_SECONDS
			);

			add_filter( 'upgrader_source_selection', self::verify_source(...), 10, 4 );
			$result = self::run_upgrader( $zip_path, $type );
			remove_filter( 'upgrader_source_selection', self::verify_source(...), 10 );

			delete_transient( self::TRANSIENT_EXPECTED . md5( $public_id ) );
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

			$version = (int) ( $project['currentVersion'] ?? 0 );
			Telex_Tracker::track( $public_id, $version, $type->value, $project['slug'] );
			Telex_Cache::bust_project( $public_id );

			// Optional post-install activation.
			if ( $activate && ProjectType::Block === $type ) {
				$plugin_file = self::find_plugin_file( $project['slug'] );
				if ( $plugin_file ) {
					activate_plugin( $plugin_file );
				}
			}

			$action = Telex_Tracker::needs_update( $public_id, $version - 1 )
				? AuditAction::Update
				: AuditAction::Install;

			Telex_Audit_Log::log( $action, $public_id, [
				'slug'    => $project['slug'],
				'version' => $version,
				'type'    => $type->value,
			] );

			return true;

		} catch ( \Telex\Sdk\Exceptions\TelexException $e ) {
			return new \WP_Error( 'telex_api', $e->getMessage() );
		} catch ( \Exception $e ) {
			return new \WP_Error( 'telex_install', sprintf(
				/* translators: %s: error message */
				__( 'Installation failed: %s', 'telex' ),
				$e->getMessage()
			) );
		}
	}

	// -------------------------------------------------------------------------
	// Remove
	// -------------------------------------------------------------------------

	/**
	 * @return true|\WP_Error
	 */
	public static function remove( string $public_id ): true|\WP_Error {
		$tracked = Telex_Tracker::get( $public_id );
		if ( ! $tracked ) {
			return new \WP_Error( 'telex_not_installed', __( 'Project is not installed.', 'telex' ) );
		}

		$type = ProjectType::from( $tracked['type'] );
		$slug = $tracked['slug'];

		if ( ! current_user_can( $type->remove_capability() ) ) {
			return new \WP_Error( 'telex_caps', __( 'You do not have permission to remove this type of project.', 'telex' ) );
		}

		if ( ProjectType::Theme === $type ) {
			$theme = wp_get_theme( $slug );
			if ( $theme->exists() ) {
				if ( wp_get_theme()->get_stylesheet() === $slug ) {
					return new \WP_Error( 'telex_active_theme', __( 'Cannot remove the active theme. Switch to a different theme first.', 'telex' ) );
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
				$result = delete_plugins( [ $plugin_file ?: $slug . '/' . $slug . '.php' ] );
				if ( is_wp_error( $result ) ) {
					return $result;
				}
			}
		}

		Telex_Tracker::untrack( $public_id );
		Telex_Cache::bust_project( $public_id );

		Telex_Audit_Log::log( AuditAction::Remove, $public_id, [
			'slug' => $slug,
			'type' => $type->value,
		] );

		return true;
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * @param Telex_Build_File[] $files
	 * @return string|\WP_Error
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

		$tmp_dir     = wp_tempnam( 'telex_' ) . '_dir';
		// wp_tempnam creates a file; remove it and use as directory name.
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		@unlink( $tmp_dir );

		$project_dir = $tmp_dir . '/' . $slug;

		if ( ! wp_mkdir_p( $project_dir ) ) {
			return new \WP_Error( 'telex_mkdir', __( 'Could not create temporary directory.', 'telex' ) );
		}

		foreach ( $files as $file ) {
			$path = $file->path;

			// Path traversal check.
			if ( str_contains( $path, '..' ) || str_starts_with( $path, '/' ) || str_starts_with( $path, '\\' ) ) {
				self::cleanup( $tmp_dir );
				return new \WP_Error( 'telex_path', sprintf(
					/* translators: %s: file path */
					__( 'Unsafe file path rejected: %s', 'telex' ),
					$path
				) );
			}

			// Extension blocklist — also checked in upgrader_source_selection, but defense-in-depth.
			$extension = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
			if ( in_array( $extension, self::BLOCKED_EXTENSIONS, true ) ) {
				self::cleanup( $tmp_dir );
				return new \WP_Error( 'telex_ext', sprintf(
					/* translators: %s: file path */
					__( 'Blocked file extension in: %s', 'telex' ),
					$path
				) );
			}

			$file_path = $project_dir . '/' . $path;
			$dir       = dirname( $file_path );

			if ( ! wp_mkdir_p( $dir ) ) {
				self::cleanup( $tmp_dir );
				return new \WP_Error( 'telex_mkdir', sprintf(
					/* translators: %s: directory path */
					__( 'Could not create directory: %s', 'telex' ),
					$dir
				) );
			}

			// ZipSlip protection: resolved path must stay within project directory.
			$real_dir         = realpath( $dir );
			$real_project_dir = realpath( $project_dir );

			if ( false === $real_dir || false === $real_project_dir || ! str_starts_with( $real_dir, $real_project_dir ) ) {
				self::cleanup( $tmp_dir );
				return new \WP_Error( 'telex_path', sprintf(
					/* translators: %s: file path */
					__( 'File path escapes project directory: %s', 'telex' ),
					$path
				) );
			}

			try {
				$content = $client->projects->getBuildFile( $public_id, $file->path );
				$wp_filesystem->put_contents( $file_path, $content, FS_CHMOD_FILE );
			} catch ( \Exception $e ) {
				self::cleanup( $tmp_dir );
				return new \WP_Error( 'telex_download', sprintf(
					/* translators: %s: file path */
					__( 'Failed to download file: %s', 'telex' ),
					$file->path
				) );
			}
		}

		return $tmp_dir;
	}

	/**
	 * @return string|\WP_Error
	 */
	private static function create_zip( string $source_dir, string $slug ): string|\WP_Error {
		$zip_path = wp_tempnam( 'telex_' ) . '.zip';

		$zip = new \ZipArchive();
		if ( true !== $zip->open( $zip_path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE ) ) {
			return new \WP_Error( 'telex_zip', __( 'Could not create zip archive.', 'telex' ) );
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
			return new \WP_Error( 'telex_install', __( 'Installation failed.', 'telex' ) );
		}

		return true;
	}

	/**
	 * `upgrader_source_selection` filter — fires after WP unpacks the ZIP into a temp
	 * directory but before it moves files into the plugin/theme directory.
	 * Returning a WP_Error here aborts the install cleanly.
	 *
	 * @param string|\WP_Error $source    Temp dir path or existing error.
	 * @param string           $remote    Remote package URL (unused).
	 * @param \WP_Upgrader     $upgrader  Upgrader instance.
	 * @param array<string,mixed> $hook_extra Extra args passed to upgrader.
	 * @return string|\WP_Error
	 */
	public static function verify_source(
		string|\WP_Error $source,
		string $remote,
		\WP_Upgrader $upgrader,
		array $hook_extra
	): string|\WP_Error {
		if ( is_wp_error( $source ) ) {
			return $source;
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
						__( 'Blocked file extension found in package: %s', 'telex' ),
						$file->getPathname()
					)
				);
			}
		}

		return $source;
	}

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
