<?php
/**
 * Shared utility helpers for the Dispatch plugin.
 *
 * @package Dispatch_For_Telex
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Static utility functions shared across multiple Dispatch classes.
 *
 * Centralising these here eliminates duplication between Telex_Installer
 * and Telex_Updater and ensures any future bug fix applies everywhere.
 */
final class Telex_Utils {

	/**
	 * Returns the plugin file path (e.g. 'my-plugin/my-plugin.php') for a given slug.
	 *
	 * Loads get_plugins() once per request via WP's internal caching — safe to call
	 * in a loop without incurring repeated filesystem reads.
	 *
	 * @param string $slug The WordPress plugin directory slug.
	 * @return string Plugin file path relative to the plugins directory, or empty string if not found.
	 */
	public static function find_plugin_file( string $slug ): string {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		foreach ( get_plugins() as $file => $_data ) {
			if ( str_starts_with( $file, $slug . '/' ) ) {
				return $file;
			}
		}
		return '';
	}

	/**
	 * Returns the absolute filesystem path to the main plugin file for a given slug.
	 *
	 * Used by health checks to read plugin headers (Requires PHP, etc.).
	 *
	 * @param string $slug The WordPress plugin directory slug.
	 * @return string Absolute path to the main plugin file, or empty string if not found.
	 */
	public static function find_plugin_file_path( string $slug ): string {
		$relative = self::find_plugin_file( $slug );
		return '' !== $relative ? WP_PLUGIN_DIR . '/' . $relative : '';
	}
}
