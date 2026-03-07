<?php
/**
 * Plugin Name:       Dispatch for Telex
 * Plugin URI:        https://telex.automattic.ai
 * Description:       Install blocks and themes from your Telex projects.
 * Version:           1.0.0
 * Requires at least: 6.7
 * Tested up to:      6.8
 * Requires PHP:      8.2
 * Author:            Regionally Famous
 * Author URI:        https://regionallyfamous.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       telex
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Guard against double-loading.
if ( defined( 'TELEX_LOADED' ) ) {
	return;
}
define( 'TELEX_LOADED', true );

define( 'TELEX_PLUGIN_VERSION', '1.0.0' );
define( 'TELEX_PLUGIN_FILE', __FILE__ );
define( 'TELEX_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'TELEX_PUBLIC_URL', 'https://telex.automattic.ai' );
define( 'TELEX_API_BASE_URL', TELEX_PUBLIC_URL . '/api' );
define( 'TELEX_DEVICE_AUTH_URL', TELEX_PUBLIC_URL . '/auth/device' );

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
require_once TELEX_PLUGIN_DIR . 'includes/class-telex-admin.php';
require_once TELEX_PLUGIN_DIR . 'includes/class-telex-rest.php';
require_once TELEX_PLUGIN_DIR . 'includes/class-telex-fatal-handler.php';

// Activation handler.
require_once TELEX_PLUGIN_DIR . 'includes/class-telex-activator.php';
register_activation_hook( __FILE__, Telex_Activator::activate(...) );

// WP-CLI commands — only load in CLI context.
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once TELEX_PLUGIN_DIR . 'includes/class-telex-cli.php';
	WP_CLI::add_command( 'telex', 'Telex_CLI' );
}

// Bootstrap all services after all plugins have loaded.
add_action( 'plugins_loaded', static function (): void {
	Telex_Fatal_Handler::register();

	// Version upgrade detection.
	Telex_Activator::maybe_upgrade();

	Telex_Auth::init();
	Telex_Admin::init();
	Telex_Updater::init();
} );

// REST API routes.
add_action( 'rest_api_init', Telex_REST::register_routes(...) );
