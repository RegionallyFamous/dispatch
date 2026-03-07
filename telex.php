<?php
/**
 * Plugin Name:       Dispatch for Telex
 * Plugin URI:        https://telex.automattic.ai
 * Description:       Telex builds the block. Dispatch ships it. Install, update, and remove Telex-generated blocks and themes from wp-admin — no zip files, no upload forms.
 * Version:           1.1.1
 * Requires at least: 6.7
 * Tested up to:      6.8
 * Requires PHP:      8.2
 * Author:            Regionally Famous
 * Author URI:        https://regionallyfamous.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       dispatch
 *
 * @package Dispatch_For_Telex
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Guard against double-loading.
if ( defined( 'TELEX_LOADED' ) ) {
	return;
}
define( 'TELEX_LOADED', true );

define( 'TELEX_PLUGIN_VERSION', '1.1.1' );
define( 'TELEX_PLUGIN_FILE', __FILE__ );
define( 'TELEX_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'TELEX_PUBLIC_URL', 'https://telex.automattic.ai' );
define( 'TELEX_API_BASE_URL', TELEX_PUBLIC_URL . '/api' );
define( 'TELEX_DEVICE_AUTH_URL', TELEX_PUBLIC_URL . '/auth/device' );

// =============================================================================
// PRE-FLIGHT CHECKS
//
// Every hard requirement is verified before a single class file is loaded.
// On failure: show an actionable admin notice and deactivate cleanly.
// A white screen or PHP fatal is never an acceptable outcome.
// =============================================================================

/**
 * Registers an admin error notice and schedules immediate deactivation.
 *
 * Stored as a variable rather than a named function to avoid polluting the
 * global function namespace. Unset after the pre-flight block completes.
 *
 * @param string $message HTML-safe message shown inside the notice (wp_kses_post applied).
 */
$telex_bail = static function ( string $message ): void {
	add_action(
		'admin_notices',
		static function () use ( $message ): void {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				wp_kses_post( '<strong>Dispatch for Telex</strong> cannot start: ' . $message )
			);
		}
	);

	// Deactivate on the very next admin_init so the plugin does not sit in a
	// permanently broken state. Also suppress the "Plugin activated." banner
	// that would otherwise appear alongside the error notice.
	add_action(
		'admin_init',
		static function (): void {
			deactivate_plugins( plugin_basename( TELEX_PLUGIN_FILE ) );
			if ( isset( $_GET['activate'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				unset( $_GET['activate'] );
			}
		}
	);
};

// -----------------------------------------------------------------------------
// 1. PHP version
// Backed enums, readonly classes, named arguments, and first-class callables
// all require PHP 8.2+. WordPress checks Requires PHP before activation, but
// that can be bypassed via WP-CLI or direct DB manipulation.
// -----------------------------------------------------------------------------
if ( version_compare( PHP_VERSION, '8.2', '<' ) ) {
	$telex_bail(
		sprintf(
			/* translators: %s: current PHP version */
			__( 'Dispatch needs PHP 8.2 or later. Your server is running PHP&nbsp;%s — ask your host to upgrade.', 'dispatch' ),
			esc_html( PHP_VERSION )
		)
	);
	unset( $telex_bail );
	return;
}

// -----------------------------------------------------------------------------
// 2. WordPress version
// Checked here as defence-in-depth; WP normally enforces Requires At Least
// from the plugin header before activation.
// -----------------------------------------------------------------------------
global $wp_version;
if ( version_compare( $wp_version, '6.7', '<' ) ) {
	$telex_bail(
		sprintf(
			/* translators: %s: current WordPress version */
			__( 'Dispatch needs WordPress 6.7 or later. You\'re on&nbsp;%s — time to update!', 'dispatch' ),
			esc_html( $wp_version )
		)
	);
	unset( $telex_bail );
	return;
}

// -----------------------------------------------------------------------------
// 3. OpenSSL extension
// Required by Telex_Auth for AES-256-GCM token encryption/decryption and
// for generating random IVs via openssl_random_pseudo_bytes().
// -----------------------------------------------------------------------------
if ( ! extension_loaded( 'openssl' ) ) {
	$telex_bail(
		__( 'The PHP <code>openssl</code> extension is missing. Ask your host to enable it — Dispatch needs it for secure token storage.', 'dispatch' )
	);
	unset( $telex_bail );
	return;
}

// -----------------------------------------------------------------------------
// 4. ZipArchive class
// Required by Telex_Installer to package build files before handing them
// to the WordPress Upgrader API.
// -----------------------------------------------------------------------------
if ( ! class_exists( 'ZipArchive' ) ) {
	$telex_bail(
		__( 'The PHP <code>zip</code> extension is missing. Ask your host to enable it — Dispatch needs it to install projects.', 'dispatch' )
	);
	unset( $telex_bail );
	return;
}

// -----------------------------------------------------------------------------
// 5. Composer autoloader
// Not present when the plugin is installed by cloning the repository without
// running composer install.
// -----------------------------------------------------------------------------
if ( ! file_exists( TELEX_PLUGIN_DIR . 'vendor/autoload.php' ) ) {
	$telex_bail(
		sprintf(
			/* translators: %s: composer install command */
			__( 'Composer dependencies are missing. Run %s in the plugin directory, or install from the pre-built release ZIP rather than cloning the repository directly.', 'dispatch' ),
			'<code>composer install --no-dev --optimize-autoloader</code>'
		)
	);
	unset( $telex_bail );
	return;
}

// -----------------------------------------------------------------------------
// 6. Required plugin files
// Guards against partial uploads via FTP, truncated ZIP extractions, or
// any other incomplete installation. Every file loaded below is listed here.
// -----------------------------------------------------------------------------
$telex_required = [
	'includes/Enums/AuditAction.php',
	'includes/Enums/AuthStatus.php',
	'includes/Enums/InstallStatus.php',
	'includes/Enums/ProjectType.php',
	'includes/class-telex-activator.php',
	'includes/class-telex-admin.php',
	'includes/class-telex-audit-log.php',
	'includes/class-telex-audit-log-table.php',
	'includes/class-telex-auth.php',
	'includes/class-telex-cache.php',
	'includes/class-telex-circuit-breaker.php',
	'includes/class-telex-dtos.php',
	'includes/class-telex-fatal-handler.php',
	'includes/class-telex-installer.php',
	'includes/class-telex-rest.php',
	'includes/class-telex-tracker.php',
	'includes/class-telex-updater.php',
	'includes/class-telex-wp-http-client.php',
];

$telex_missing = array_values(
	array_filter(
		$telex_required,
		static fn( string $path ) => ! file_exists( TELEX_PLUGIN_DIR . $path )
	)
);

if ( ! empty( $telex_missing ) ) {
	$telex_bail(
		sprintf(
			/* translators: %s: comma-separated list of missing file paths */
			__( 'The plugin installation is incomplete — the following files are missing. Please reinstall from the release ZIP: <code>%s</code>', 'dispatch' ),
			esc_html( implode( '</code>, <code>', $telex_missing ) )
		)
	);
	unset( $telex_bail, $telex_required, $telex_missing );
	return;
}

unset( $telex_bail, $telex_required, $telex_missing );

// =============================================================================
// BOOTSTRAP
//
// All pre-flight checks passed. Load dependencies and register hooks.
// The plugins_loaded and rest_api_init callbacks are wrapped in try/catch as
// a last-resort safety net for any unexpected runtime errors.
// =============================================================================

require_once TELEX_PLUGIN_DIR . 'vendor/autoload.php';

// Enums (loaded before any class that uses them).
require_once TELEX_PLUGIN_DIR . 'includes/Enums/ProjectType.php';
require_once TELEX_PLUGIN_DIR . 'includes/Enums/InstallStatus.php';
require_once TELEX_PLUGIN_DIR . 'includes/Enums/AuthStatus.php';
require_once TELEX_PLUGIN_DIR . 'includes/Enums/AuditAction.php';

// DTOs (readonly classes — loaded after enums, before services).
require_once TELEX_PLUGIN_DIR . 'includes/class-telex-dtos.php';

// Core service classes.
require_once TELEX_PLUGIN_DIR . 'includes/class-telex-audit-log.php';
require_once TELEX_PLUGIN_DIR . 'includes/class-telex-circuit-breaker.php';
require_once TELEX_PLUGIN_DIR . 'includes/class-telex-auth.php';
require_once TELEX_PLUGIN_DIR . 'includes/class-telex-cache.php';
require_once TELEX_PLUGIN_DIR . 'includes/class-telex-tracker.php';
require_once TELEX_PLUGIN_DIR . 'includes/class-telex-wp-http-client.php';
require_once TELEX_PLUGIN_DIR . 'includes/class-telex-installer.php';
require_once TELEX_PLUGIN_DIR . 'includes/class-telex-updater.php';
require_once TELEX_PLUGIN_DIR . 'includes/class-telex-audit-log-table.php';
require_once TELEX_PLUGIN_DIR . 'includes/class-telex-admin.php';
require_once TELEX_PLUGIN_DIR . 'includes/class-telex-rest.php';
require_once TELEX_PLUGIN_DIR . 'includes/class-telex-fatal-handler.php';

// Activation handler.
require_once TELEX_PLUGIN_DIR . 'includes/class-telex-activator.php';
register_activation_hook( __FILE__, Telex_Activator::activate( ... ) );

// WP-CLI commands — only load in CLI context and only if the file is present.
if ( defined( 'WP_CLI' ) && WP_CLI && file_exists( TELEX_PLUGIN_DIR . 'includes/class-telex-cli.php' ) ) {
	require_once TELEX_PLUGIN_DIR . 'includes/class-telex-cli.php';
	WP_CLI::add_command( 'telex', 'Telex_CLI' );
}

// Bootstrap all services after all plugins have loaded.
// Wrapped in try/catch: maybe_upgrade() writes to the DB, Auth::init() adds
// multisite filters — any unexpected exception here must not produce a fatal.
add_action(
	'plugins_loaded',
	static function (): void {
		try {
			Telex_Fatal_Handler::register();
			Telex_Activator::maybe_upgrade();
			Telex_Auth::init();
			Telex_Admin::init();
			Telex_Updater::init();
		} catch ( \Throwable $e ) {
			add_action(
				'admin_notices',
				static function () use ( $e ): void {
					printf(
						'<div class="notice notice-error"><p>%s</p></div>',
						wp_kses_post(
							sprintf(
								/* translators: %s: error message */
								__( '<strong>Dispatch for Telex</strong> encountered an unexpected error during startup and could not initialise: %s', 'dispatch' ),
								'<code>' . esc_html( $e->getMessage() ) . '</code>'
							)
						)
					);
				}
			);
		}
	}
);

// REST API routes.
// Wrapped in try/catch: route registration is non-critical — a failure here
// should surface as an admin notice, not a fatal that breaks the entire admin.
add_action(
	'rest_api_init',
	static function (): void {
		try {
			Telex_REST::register_routes();
		} catch ( \Throwable $e ) {
			add_action(
				'admin_notices',
				static function () use ( $e ): void {
					printf(
						'<div class="notice notice-warning"><p>%s</p></div>',
						wp_kses_post(
							sprintf(
								/* translators: %s: error message */
								__( '<strong>Dispatch for Telex</strong> could not register its REST API routes: %s', 'dispatch' ),
								'<code>' . esc_html( $e->getMessage() ) . '</code>'
							)
						)
					);
				}
			);
		}
	}
);
