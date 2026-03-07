<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WordPress admin UI for Telex.
 *
 * The projects list is rendered by the React app (src/admin/index.js),
 * which fetches data from the REST API. PHP only renders the shell div.
 * The device flow is also handled by React (src/device-flow/index.js).
 */
class Telex_Admin {

	private const SCREEN_OPTION_PER_PAGE = 'telex_projects_per_page';
	private const NOTICE_TRANSIENT       = 'telex_admin_notice_'; // + user_id

	// -------------------------------------------------------------------------
	// Init
	// -------------------------------------------------------------------------

	public static function init(): void {
		if ( is_multisite() ) {
			add_action( 'network_admin_menu', self::register_menu(...) );
		} else {
			add_action( 'admin_menu', self::register_menu(...) );
		}

		add_action( 'admin_init', self::handle_legacy_actions(...) );
		add_filter( 'set-screen-option', self::save_screen_option(...), 10, 3 );

		// Site Health integration.
		add_filter( 'debug_information', self::site_health_info(...) );
		add_filter( 'site_status_tests', self::site_health_tests(...) );

		// Heartbeat API — piggyback on WP's existing polling for auth status.
		add_filter( 'heartbeat_received', self::heartbeat_received(...), 10, 2 );
		add_filter( 'heartbeat_nopriv_received', self::heartbeat_nopriv_deny(...), 10, 2 );
	}

	// -------------------------------------------------------------------------
	// Menu
	// -------------------------------------------------------------------------

	public static function register_menu(): void {
		$hook = add_menu_page(
			__( 'Telex', 'telex' ),
			__( 'Telex', 'telex' ),
			'manage_options',
			'telex',
			self::render_page(...),
			'dashicons-layout',
			59
		);

		add_action( "load-{$hook}", self::add_screen_options(...) );
	}

	public static function add_screen_options(): void {
		add_screen_option( 'per_page', [
			'label'   => __( 'Projects per page', 'telex' ),
			'default' => 24,
			'option'  => self::SCREEN_OPTION_PER_PAGE,
		] );
	}

	public static function save_screen_option( bool|int $status, string $option, int $value ): bool|int {
		if ( self::SCREEN_OPTION_PER_PAGE === $option ) {
			return $value;
		}
		return $status;
	}

	// -------------------------------------------------------------------------
	// Legacy GET/POST action handler (redirect → React UI handles most actions)
	// -------------------------------------------------------------------------

	public static function handle_legacy_actions(): void {
		if ( ! isset( $_GET['page'] ) || 'telex' !== $_GET['page'] ) {
			return;
		}

		// Disconnect (GET-based with nonce, kept for compatibility).
		if ( ( $_GET['telex_action'] ?? '' ) === 'disconnect' ) {
			check_admin_referer( 'telex_disconnect' );

			if ( ! current_user_can( 'manage_options' ) ) {
				return;
			}

			Telex_Auth::disconnect();
			self::set_notice( 'info', __( 'Disconnected from Telex.', 'telex' ) );
			wp_safe_redirect( admin_url( 'admin.php?page=telex' ) );
			exit;
		}
	}

	// -------------------------------------------------------------------------
	// Page render — React shell
	// -------------------------------------------------------------------------

	public static function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		self::enqueue_assets();

		$per_page = (int) ( get_user_option( self::SCREEN_OPTION_PER_PAGE ) ?: 24 );

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Telex', 'telex' ) . '</h1>';

		// Transient-based notices (post-redirect-get pattern).
		self::render_notices();

		if ( ! Telex_Auth::is_connected() ) {
			// Device flow UI rendered by React.
			printf(
				'<div id="telex-device-flow-app" data-rest-url="%s" data-nonce="%s"></div>',
				esc_attr( rest_url( 'telex/v1' ) ),
				esc_attr( wp_create_nonce( 'wp_rest' ) )
			);
		} else {
			// Disconnect URL for the React app's "Disconnect" button.
			$disconnect_url = wp_nonce_url(
				add_query_arg( [ 'page' => 'telex', 'telex_action' => 'disconnect' ], admin_url( 'admin.php' ) ),
				'telex_disconnect'
			);

			printf(
				'<div id="telex-projects-app" data-rest-url="%s" data-nonce="%s" data-per-page="%d" data-disconnect-url="%s"></div>',
				esc_attr( rest_url( 'telex/v1' ) ),
				esc_attr( wp_create_nonce( 'wp_rest' ) ),
				$per_page,
				esc_attr( $disconnect_url )
			);
		}

		echo '</div>';
	}

	// -------------------------------------------------------------------------
	// Asset enqueueing
	// -------------------------------------------------------------------------

	private static function enqueue_assets(): void {
		$is_connected = Telex_Auth::is_connected();
		$handle       = $is_connected ? 'telex-admin' : 'telex-device-flow';
		$asset_file   = TELEX_PLUGIN_DIR . 'build/' . ( $is_connected ? 'admin' : 'device-flow' ) . '.asset.php';

		if ( ! file_exists( $asset_file ) ) {
			// Build not run yet — skip (dev environment).
			return;
		}

		$asset = include $asset_file;

		wp_enqueue_script(
			$handle,
			plugins_url( 'build/' . ( $is_connected ? 'admin' : 'device-flow' ) . '.js', TELEX_PLUGIN_FILE ),
			$asset['dependencies'],
			$asset['version'],
			[ 'strategy' => 'defer', 'in_footer' => true ]
		);

		wp_enqueue_style(
			'telex-admin',
			plugins_url( 'assets/css/admin.css', TELEX_PLUGIN_FILE ),
			[],
			filemtime( TELEX_PLUGIN_DIR . 'assets/css/admin.css' ) ?: TELEX_PLUGIN_VERSION
		);

		wp_set_script_translations( $handle, 'telex', TELEX_PLUGIN_DIR . 'languages' );
	}

	// -------------------------------------------------------------------------
	// Transient-based notices (post-redirect-get)
	// -------------------------------------------------------------------------

	private static function notice_key(): string {
		return self::NOTICE_TRANSIENT . get_current_user_id();
	}

	public static function set_notice( string $type, string $message ): void {
		set_transient( self::notice_key(), [ 'type' => $type, 'message' => $message ], MINUTE_IN_SECONDS );
	}

	private static function render_notices(): void {
		$notice = get_transient( self::notice_key() );
		if ( ! is_array( $notice ) ) {
			return;
		}

		delete_transient( self::notice_key() );

		$type    = in_array( $notice['type'], [ 'success', 'error', 'warning', 'info' ], true )
			? $notice['type']
			: 'info';
		$message = (string) $notice['message'];

		printf(
			'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
			esc_attr( $type ),
			esc_html( $message )
		);
	}

	// -------------------------------------------------------------------------
	// WP Heartbeat — lightweight auth status polling
	// -------------------------------------------------------------------------

	/**
	 * Piggyback on WP Heartbeat to push auth/connection status to the admin UI
	 * without a separate polling loop.
	 *
	 * The JS device-flow component sends `{ telex_poll: true }` in heartbeat data.
	 *
	 * @param array<string, mixed> $response
	 * @param array<string, mixed> $data
	 * @return array<string, mixed>
	 */
	public static function heartbeat_received( array $response, array $data ): array {
		if ( empty( $data['telex_poll'] ) || ! current_user_can( 'manage_options' ) ) {
			return $response;
		}

		$response['telex'] = [
			'is_connected'    => Telex_Auth::is_connected(),
			'circuit_status'  => Telex_Circuit_Breaker::status(),
		];

		return $response;
	}

	/**
	 * Ensure unauthenticated heartbeat requests never leak Telex data.
	 *
	 * @param array<string, mixed> $response
	 * @param array<string, mixed> $data
	 * @return array<string, mixed>
	 */
	public static function heartbeat_nopriv_deny( array $response, array $data ): array {
		unset( $response['telex'] );
		return $response;
	}

	// -------------------------------------------------------------------------
	// Site Health
	// -------------------------------------------------------------------------

	/** @param array<string, mixed> $info */
	public static function site_health_info( array $info ): array {
		$info['telex'] = [
			'label'  => __( 'Telex', 'telex' ),
			'fields' => [
				'version'    => [
					'label' => __( 'Plugin version', 'telex' ),
					'value' => TELEX_PLUGIN_VERSION,
				],
				'connected'  => [
					'label' => __( 'Connection status', 'telex' ),
					'value' => Telex_Auth::is_connected()
						? __( 'Connected', 'telex' )
						: __( 'Not connected', 'telex' ),
				],
				'installed'       => [
					'label' => __( 'Installed projects', 'telex' ),
					'value' => count( Telex_Tracker::get_all() ),
				],
				'circuit_breaker' => [
					'label' => __( 'API circuit breaker', 'telex' ),
					'value' => Telex_Circuit_Breaker::status(),
				],
			],
		];
		return $info;
	}

	/** @param array<string, mixed> $tests */
	public static function site_health_tests( array $tests ): array {
		$tests['async']['telex_api_reachable'] = [
			'label'             => __( 'Telex API is reachable', 'telex' ),
			'test'              => rest_url( 'telex/v1/site-health/api-reachable' ),
			'has_rest'          => true,
			'async_direct_test' => self::run_api_reachability_test(...),
		];
		return $tests;
	}

	public static function run_api_reachability_test(): array {
		$response = wp_remote_head( TELEX_PUBLIC_URL, [ 'timeout' => 10, 'redirection' => 3 ] );

		if ( is_wp_error( $response ) ) {
			return [
				'label'       => __( 'Telex API is unreachable', 'telex' ),
				'status'      => 'critical',
				'badge'       => [ 'label' => __( 'Telex', 'telex' ), 'color' => 'red' ],
				'description' => '<p>' . esc_html( $response->get_error_message() ) . '</p>',
				'test'        => 'telex_api_reachable',
			];
		}

		return [
			'label'       => __( 'Telex API is reachable', 'telex' ),
			'status'      => 'good',
			'badge'       => [ 'label' => __( 'Telex', 'telex' ), 'color' => 'blue' ],
			'description' => '<p>' . esc_html__( 'Your site can reach the Telex API.', 'telex' ) . '</p>',
			'test'        => 'telex_api_reachable',
		];
	}
}
