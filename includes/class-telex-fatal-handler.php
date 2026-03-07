<?php
/**
 * PHP fatal error handler for the Dispatch plugin.
 *
 * @package Dispatch_For_Telex
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers a shutdown function to log PHP fatals to a dedicated file.
 */
class Telex_Fatal_Handler {

	/**
	 * Register the shutdown handler.
	 *
	 * @return void
	 */
	public static function register(): void {
		register_shutdown_function( self::handle( ... ) );
	}

	/**
	 * Shutdown handler — logs fatal errors originating from this plugin.
	 *
	 * @return void
	 */
	public static function handle(): void {
		$error = error_get_last();

		if ( ! $error || ! in_array( $error['type'], [ E_ERROR, E_PARSE, E_COMPILE_ERROR, E_CORE_ERROR ], true ) ) {
			return;
		}

		// Only log errors originating from our plugin directory.
		if ( ! str_starts_with( $error['file'], TELEX_PLUGIN_DIR ) ) {
			return;
		}

		$telex_log_file = WP_CONTENT_DIR . '/telex-fatal.log';
		$message        = sprintf(
			"[%s] %s in %s on line %d\n",
			gmdate( 'Y-m-d H:i:s' ),
			$error['message'],
			$error['file'],
			$error['line']
		);

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		@file_put_contents( $telex_log_file, $message, FILE_APPEND | LOCK_EX );
	}
}
