<?php
/**
 * PHPStan bootstrap — defines plugin constants so static analysis
 * can evaluate code paths that reference them.
 *
 * This file is NOT loaded at runtime; it is only used by PHPStan.
 *
 * @package Dispatch_For_Telex
 */

define( 'TELEX_LOADED', true );
define( 'TELEX_PLUGIN_VERSION', '1.0.1' );
define( 'TELEX_PLUGIN_FILE', __DIR__ . '/telex.php' );
define( 'TELEX_PLUGIN_DIR', __DIR__ . '/' );
define( 'TELEX_PUBLIC_URL', 'https://telex.automattic.ai' );
define( 'TELEX_API_BASE_URL', 'https://telex.automattic.ai/api/v1' );
define( 'TELEX_DEVICE_AUTH_URL', 'https://telex.automattic.ai/oauth/device' );
