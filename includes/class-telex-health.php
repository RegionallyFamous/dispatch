<?php
/**
 * Health checks for installed Telex projects.
 *
 * @package Dispatch_For_Telex
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Runs per-project health checks and caches results for the Health Dashboard tab.
 *
 * Checks per installed project:
 *   active          — is_plugin_active() or active theme check
 *   php_compat      — declared PHP requirement vs running PHP version
 *   block_registered — block.json exists and block name is in WP_Block_Type_Registry
 *   in_error_log    — error-log line count mentioning the plugin namespace (0 = clean)
 */
class Telex_Health {

	private const TRANSIENT_KEY = 'telex_health_cache';
	private const CACHE_TTL     = 5 * MINUTE_IN_SECONDS;

	/**
	 * Clears the cached health results, forcing a fresh scan on next request.
	 *
	 * @return void
	 */
	public static function bust_cache(): void {
		delete_transient( self::TRANSIENT_KEY );
	}

	/**
	 * Returns health results for all installed projects.
	 *
	 * Results are cached for 5 minutes to avoid repeated filesystem reads.
	 *
	 * @return array{checked_at: string, projects: list<array{public_id: string, active: bool, php_compat: bool, block_registered: bool, in_error_log: int, status: string}>}
	 */
	public static function check_all(): array {
		$cached = get_transient( self::TRANSIENT_KEY );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$installed = Telex_Tracker::get_all();
		$results   = [];

		foreach ( $installed as $public_id => $info ) {
			$results[] = self::check_project( $public_id, $info );
		}

		$data = [
			'checked_at' => gmdate( 'c' ),
			'projects'   => $results,
		];

		set_transient( self::TRANSIENT_KEY, $data, self::CACHE_TTL );

		return $data;
	}

	/**
	 * Runs health checks for a single installed project.
	 *
	 * @param string               $public_id Project public ID.
	 * @param array<string, mixed> $info      Tracker info (slug, type, version, path).
	 * @return array{public_id: string, active: bool, php_compat: bool, block_registered: bool, in_error_log: int, status: string}
	 */
	private static function check_project( string $public_id, array $info ): array {
		$type   = (string) ( $info['type'] ?? '' );
		$path   = (string) ( $info['path'] ?? '' );
		$slug   = (string) ( $info['slug'] ?? $public_id );
		$active = false;

		// Active check.
		if ( 'theme' === $type ) {
			$active = get_stylesheet() === $slug || get_template() === $slug;
		} else {
			$plugin_file = Telex_Utils::find_plugin_file( $slug );
			if ( '' !== $plugin_file ) {
				$active = is_plugin_active( $plugin_file );
			}
		}

		// PHP compatibility check.
		$php_compat = true;
		if ( '' !== $path && file_exists( $path ) ) {
			$header_path = 'theme' === $type
				? trailingslashit( $path ) . 'style.css'
				: Telex_Utils::find_plugin_file_path( $slug );

			if ( '' !== $header_path && file_exists( $header_path ) ) {
				$headers  = get_file_data( $header_path, [ 'RequiresPHP' => 'Requires PHP' ] );
				$requires = $headers['RequiresPHP'] ?? '';
				if ( '' !== $requires ) {
					$php_compat = version_compare( PHP_VERSION, $requires, '>=' );
				}
			}
		}

		// Block registration check (blocks only).
		$block_registered = true;
		if ( 'theme' !== $type ) {
			$block_json_paths = self::find_block_json_files( $slug );
			if ( ! empty( $block_json_paths ) ) {
				$registry         = \WP_Block_Type_Registry::get_instance();
				$block_registered = false;
				foreach ( $block_json_paths as $block_json ) {
					$decoded = json_decode( (string) file_get_contents( $block_json ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
					$name    = is_array( $decoded ) ? ( $decoded['name'] ?? '' ) : '';
					if ( '' !== $name && $registry->is_registered( $name ) ) {
						$block_registered = true;
						break;
					}
				}
			}
		}

		// Error log check.
		$in_error_log = self::count_error_log_lines( $slug );

		// Overall status.
		$status = 'ok';
		if ( ! $active || ! $php_compat || ( ! $block_registered && 'theme' !== $type ) ) {
			$status = 'error';
		} elseif ( $in_error_log > 0 ) {
			$status = 'warning';
		}

		return [
			'public_id'        => $public_id,
			'slug'             => $slug,
			'active'           => $active,
			'php_compat'       => $php_compat,
			'block_registered' => $block_registered,
			'in_error_log'     => $in_error_log,
			'status'           => $status,
		];
	}

	/**
	 * Finds all block.json files within a plugin slug's directory.
	 *
	 * @param string $slug Plugin slug.
	 * @return string[]
	 */
	private static function find_block_json_files( string $slug ): array {
		$dir = WP_PLUGIN_DIR . '/' . $slug;
		if ( ! is_dir( $dir ) ) {
			return [];
		}

		$files = [];
		$it    = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $dir, \RecursiveDirectoryIterator::SKIP_DOTS )
		);
		foreach ( $it as $file ) {
			if ( $file instanceof \SplFileInfo && 'block.json' === $file->getFilename() ) {
				$files[] = $file->getPathname();
			}
		}
		return $files;
	}

	/**
	 * Counts lines in WP_DEBUG_LOG that mention the plugin slug.
	 *
	 * Returns 0 when debug logging is disabled or the log is absent.
	 * Returns only a count to avoid exposing log content via the API.
	 *
	 * @param string $slug Plugin slug.
	 * @return int
	 */
	private static function count_error_log_lines( string $slug ): int {
		if ( ! defined( 'WP_DEBUG_LOG' ) || ! WP_DEBUG_LOG ) {
			return 0;
		}

		$log_path = is_string( WP_DEBUG_LOG ) ? WP_DEBUG_LOG : WP_CONTENT_DIR . '/debug.log';

		if ( ! file_exists( $log_path ) || ! is_readable( $log_path ) ) {
			return 0;
		}

		// Read only the last 2000 lines to avoid loading massive log files.
		$lines        = self::tail_file( $log_path, 2000 );
		$slug_escaped = preg_quote( $slug, '/' );
		$count        = 0;

		foreach ( $lines as $line ) {
			if ( preg_match( '/' . $slug_escaped . '/i', $line ) ) {
				++$count;
			}
		}

		return $count;
	}

	/**
	 * Returns the last N lines of a file without loading the whole thing into memory.
	 *
	 * @param string $file  Absolute file path.
	 * @param int    $lines Number of lines to read from the end.
	 * @return string[]
	 */
	private static function tail_file( string $file, int $lines ): array {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$fh = fopen( $file, 'r' );
		if ( false === $fh ) {
			return [];
		}

		$buffer     = '';
		$chunk_size = 4096;
		$found      = 0;

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fseek
		fseek( $fh, 0, SEEK_END );
		$pos = ftell( $fh );

		while ( $pos > 0 && $found < $lines ) {
			$read = min( $chunk_size, $pos );
			$pos -= $read;
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fseek
			fseek( $fh, $pos );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
			$chunk  = fread( $fh, $read );
			$buffer = $chunk . $buffer;
			$found  = substr_count( $buffer, "\n" );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $fh );

		$all = explode( "\n", $buffer );
		return array_slice( $all, -$lines );
	}
}
