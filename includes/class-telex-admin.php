<?php
/**
 * WordPress admin UI for Telex (menus, Site Health, Heartbeat).
 *
 * @package Dispatch_For_Telex
 */

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

	/**
	 * Registers all admin hooks. Called from plugins_loaded.
	 *
	 * @return void
	 */
	public static function init(): void {
		if ( is_multisite() ) {
			add_action( 'network_admin_menu', self::register_menu( ... ) );
		} else {
			add_action( 'admin_menu', self::register_menu( ... ) );
		}

		add_action( 'admin_init', self::handle_legacy_actions( ... ) );
		add_action( 'admin_init', self::maybe_export_csv( ... ) );
		add_filter( 'set-screen-option', self::save_screen_option( ... ), 10, 3 );

		// Site Health integration.
		add_filter( 'debug_information', self::site_health_info( ... ) );
		add_filter( 'site_status_tests', self::site_health_tests( ... ) );

		// Heartbeat API — piggyback on WP's existing polling for auth status.
		add_filter( 'heartbeat_received', self::heartbeat_received( ... ), 10, 2 );
		add_filter( 'heartbeat_nopriv_received', self::heartbeat_nopriv_deny( ... ), 10, 2 );
	}

	// -------------------------------------------------------------------------
	// Menu
	// -------------------------------------------------------------------------

	/**
	 * Registers the Telex admin menu page and sub-pages.
	 *
	 * @return void
	 */
	public static function register_menu(): void {
		$hook = add_menu_page(
			__( 'Dispatch', 'dispatch' ),
			__( 'Dispatch', 'dispatch' ),
			'manage_options',
			'telex',
			self::render_page( ... ),
			'dashicons-layout',
			59
		);

		add_action( "load-{$hook}", self::add_screen_options( ... ) );

		add_submenu_page(
			'telex',
			__( 'Audit Log', 'dispatch' ),
			__( 'Audit Log', 'dispatch' ),
			'manage_options',
			'telex-audit-log',
			[ self::class, 'render_audit_log_page' ]
		);
	}

	/**
	 * Adds the "Projects per page" screen option on the Telex admin page.
	 *
	 * @return void
	 */
	public static function add_screen_options(): void {
		add_screen_option(
			'per_page',
			[
				'label'   => __( 'Projects per page', 'dispatch' ),
				'default' => 24,
				'option'  => self::SCREEN_OPTION_PER_PAGE,
			]
		);
	}

	/**
	 * Persists the "Projects per page" screen option when saved.
	 *
	 * @param bool|int $status The current status (false to discard, int to save).
	 * @param string   $option The option name being saved.
	 * @param int      $value  The value entered by the user.
	 * @return bool|int
	 */
	public static function save_screen_option( bool|int $status, string $option, int $value ): bool|int {
		if ( self::SCREEN_OPTION_PER_PAGE === $option ) {
			return max( 1, min( 100, $value ) );
		}
		return $status;
	}

	// -------------------------------------------------------------------------
	// Legacy GET/POST action handler (redirect → React UI handles most actions)
	// -------------------------------------------------------------------------

	/**
	 * Handles legacy GET-based actions (e.g., disconnect via nonce URL).
	 *
	 * @return void
	 */
	public static function handle_legacy_actions(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- nonce is checked below.
		$page   = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';
		$action = isset( $_GET['telex_action'] ) ? sanitize_text_field( wp_unslash( $_GET['telex_action'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( 'telex' !== $page ) {
			return;
		}

		// Disconnect (GET-based with nonce, kept for compatibility).
		if ( 'disconnect' === $action ) {
			check_admin_referer( 'telex_disconnect' );

			if ( ! current_user_can( 'manage_options' ) ) {
				return;
			}

			Telex_Auth::disconnect();
			self::set_notice( 'info', __( 'Disconnected! You can reconnect from this page anytime.', 'dispatch' ) );
			wp_safe_redirect( admin_url( 'admin.php?page=telex' ) );
			exit;
		}
	}

	// -------------------------------------------------------------------------
	// Page render — React shell
	// -------------------------------------------------------------------------

	/**
	 * Renders the Telex admin page shell div (React app mounts here).
	 *
	 * @return void
	 */
	public static function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		self::enqueue_assets();

		$per_page_opt = get_user_option( self::SCREEN_OPTION_PER_PAGE );
		$per_page     = (int) ( false !== $per_page_opt && '' !== $per_page_opt ? $per_page_opt : 24 );

		$badge_class = Telex_Auth::is_connected() ? 'telex-connection-badge telex-connection-badge--connected' : 'telex-connection-badge telex-connection-badge--disconnected';
		$badge_label = Telex_Auth::is_connected() ? __( 'Connected', 'dispatch' ) : __( 'Not connected', 'dispatch' );

		echo '<div class="wrap">';
		echo '<div class="telex-page-header">';
		echo '<h1>' . esc_html__( 'Dispatch', 'dispatch' ) . '</h1>';
		printf(
			'<span class="%s">%s</span>',
			esc_attr( $badge_class ),
			esc_html( $badge_label )
		);
		echo '</div>';

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
				add_query_arg(
					[
						'page'         => 'telex',
						'telex_action' => 'disconnect',
					],
					admin_url( 'admin.php' )
				),
				'telex_disconnect'
			);

			// Webhook / auto-deploy data.
			$webhook_url    = rest_url( 'telex/v1/deploy' );
			$webhook_secret = Telex_REST::get_deploy_secret();
			$is_network     = is_multisite() ? '1' : '0';

			printf(
				'<div id="telex-projects-app" data-rest-url="%s" data-nonce="%s" data-per-page="%d" data-disconnect-url="%s" data-webhook-url="%s" data-webhook-secret="%s" data-is-network="%s"></div>',
				esc_attr( rest_url( 'telex/v1' ) ),
				esc_attr( wp_create_nonce( 'wp_rest' ) ),
				absint( $per_page ),
				esc_attr( $disconnect_url ),
				esc_attr( $webhook_url ),
				esc_attr( $webhook_secret ),
				esc_attr( $is_network )
			);
		}

		echo '</div>';
	}

	/**
	 * Renders the Audit Log sub-page.
	 *
	 * @return void
	 */
	public static function render_audit_log_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'dispatch' ) );
		}

		require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';

		$table = new Telex_Audit_Log_Table();
		$table->prepare_items();

		$export_url = wp_nonce_url(
			add_query_arg(
				[
					'page'   => 'telex-audit-log',
					'action' => 'telex_export_csv',
				],
				admin_url( 'admin.php' )
			),
			'telex_export_csv'
		);

		echo '<div class="wrap">';
		echo '<h1 class="wp-heading-inline">' . esc_html__( 'Dispatch Audit Log', 'dispatch' ) . '</h1>';
		printf(
			' <a href="%s" class="page-title-action">%s</a>',
			esc_url( $export_url ),
			esc_html__( 'Export CSV', 'dispatch' )
		);
		echo '<hr class="wp-header-end">';
		echo '<p class="description">' . esc_html__( 'A full history of what\'s happened — installs, updates, removals, and account changes. Read-only.', 'dispatch' ) . '</p>';
		echo '<form method="get">';
		printf( '<input type="hidden" name="page" value="%s" />', esc_attr( 'telex-audit-log' ) );
		$table->display();
		echo '</form>';
		echo '</div>';
	}

	// -------------------------------------------------------------------------
	// Asset enqueueing
	// -------------------------------------------------------------------------

	/**
	 * Enqueues the JS and CSS assets for the admin page.
	 *
	 * @return void
	 */
	private static function enqueue_assets(): void {
		$is_connected = Telex_Auth::is_connected();
		$handle       = $is_connected ? 'telex-admin' : 'telex-device-flow';
		$bundle       = $is_connected ? 'admin' : 'device-flow';
		$asset_file   = TELEX_PLUGIN_DIR . 'build/' . $bundle . '.asset.php';
		$script_file  = TELEX_PLUGIN_DIR . 'build/' . $bundle . '.js';

		if ( ! file_exists( $asset_file ) || ! file_exists( $script_file ) ) {
			// JS bundle has not been compiled yet. Surface an actionable notice
			// instead of silently rendering a blank page.
			add_action(
				'admin_notices',
				static function (): void {
					printf(
						'<div class="notice notice-error"><p>%s</p></div>',
						wp_kses_post(
							'<strong>Dispatch for Telex</strong>: The plugin\'s JavaScript bundle is missing. ' .
							'Run <code>npm install &amp;&amp; npm run build</code> in the plugin directory, then reload this page.'
						)
					);
				}
			);
			return;
		}

		$asset = include $asset_file;

		wp_enqueue_script(
			$handle,
			plugins_url( 'build/' . $bundle . '.js', TELEX_PLUGIN_FILE ),
			$asset['dependencies'],
			$asset['version'],
			[
				'strategy'  => 'defer',
				'in_footer' => true,
			]
		);

		$css_mtime = filemtime( TELEX_PLUGIN_DIR . 'assets/css/admin.css' );
		wp_enqueue_style(
			'telex-admin',
			plugins_url( 'assets/css/admin.css', TELEX_PLUGIN_FILE ),
			[],
			false !== $css_mtime ? (string) $css_mtime : TELEX_PLUGIN_VERSION
		);

		wp_set_script_translations( $handle, 'dispatch', TELEX_PLUGIN_DIR . 'languages' );
	}

	// -------------------------------------------------------------------------
	// Transient-based notices (post-redirect-get)
	// -------------------------------------------------------------------------

	/**
	 * Returns the transient key for the current user's admin notice.
	 *
	 * @return string
	 */
	private static function notice_key(): string {
		return self::NOTICE_TRANSIENT . get_current_user_id();
	}

	/**
	 * Stores an admin notice in a transient for the current user.
	 *
	 * @param string $type    Notice type: 'success', 'error', 'warning', or 'info'.
	 * @param string $message The notice message (plain text, escaped at render time).
	 * @return void
	 */
	public static function set_notice( string $type, string $message ): void {
		set_transient(
			self::notice_key(),
			[
				'type'    => $type,
				'message' => $message,
			],
			MINUTE_IN_SECONDS
		);
	}

	/**
	 * Renders any queued admin notice and clears it from the transient.
	 *
	 * @return void
	 */
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
	// CSV export
	// -------------------------------------------------------------------------

	/**
	 * Streams the audit log as a CSV download if the correct action + nonce are present.
	 *
	 * @return void
	 */
	public static function maybe_export_csv(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if (
			! isset( $_GET['page'], $_GET['action'] ) ||
			'telex-audit-log' !== $_GET['page'] ||
			'telex_export_csv' !== $_GET['action']
		) {
			return;
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to export the audit log.', 'dispatch' ) );
		}

		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'telex_export_csv' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'dispatch' ) );
		}

		$events   = Telex_Audit_Log::get_recent( 10000 );
		$filename = 'dispatch-audit-' . gmdate( 'Y-m-d' ) . '.csv';

		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );

		$out = fopen( 'php://output', 'w' );

		// BOM for Excel UTF-8 compatibility.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		fwrite( $out, "\xEF\xBB\xBF" );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fputcsv
		fputcsv( $out, [ 'Date (UTC)', 'Action', 'Project ID', 'User' ] );

		foreach ( $events as $event ) {
			$user = $event['user_id']
				? ( get_userdata( (int) $event['user_id'] )->user_login ?? '' )
				: '(system)';

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fputcsv
			fputcsv(
				$out,
				[
					$event['created_at'] ?? '',
					$event['action'] ?? '',
					$event['public_id'] ?? '',
					$user,
				]
			);
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $out );
		exit;
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
	 * @param array<string, mixed> $response The Heartbeat response data to be sent back to the client.
	 * @param array<string, mixed> $data     The data sent by the Heartbeat client.
	 * @return array<string, mixed>
	 */
	public static function heartbeat_received( array $response, array $data ): array {
		if ( empty( $data['telex_poll'] ) || ! current_user_can( 'manage_options' ) ) {
			return $response;
		}

		$is_connected = Telex_Auth::is_connected();

		$response['telex'] = [
			'is_connected'   => $is_connected,
			'circuit_status' => Telex_Circuit_Breaker::status(),
			'update_count'   => 0,
		];

		// Count available updates using the cached project list — no API call.
		if ( $is_connected ) {
			$cached = Telex_Cache::get_projects();
			if ( is_array( $cached ) ) {
				$installed = Telex_Tracker::get_all();
				$count     = 0;
				foreach ( $cached as $project ) {
					$id    = $project['publicId'] ?? '';
					$local = $installed[ $id ] ?? null;
					if ( null !== $local && ( (int) ( $project['currentVersion'] ?? 0 ) ) > (int) $local['version'] ) {
						++$count;
					}
				}
				$response['telex']['update_count'] = $count;
			}
		}

		return $response;
	}

	/**
	 * Ensure unauthenticated heartbeat requests never leak Telex data.
	 *
	 * @param array<string, mixed> $response The Heartbeat response data.
	 * @param array<string, mixed> $_data    The incoming Heartbeat data (unused).
	 * @return array<string, mixed>
	 */
	public static function heartbeat_nopriv_deny( array $response, array $_data ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		unset( $response['telex'] );
		return $response;
	}

	// -------------------------------------------------------------------------
	// Site Health
	// -------------------------------------------------------------------------

	/**
	 * Adds Telex debug information to the Site Health info screen.
	 *
	 * @param array<string, mixed> $info The existing debug info sections.
	 * @return array<string, mixed>
	 */
	public static function site_health_info( array $info ): array {
		$info['telex'] = [
			'label'  => __( 'Dispatch', 'dispatch' ),
			'fields' => [
				'version'         => [
					'label' => __( 'Plugin version', 'dispatch' ),
					'value' => TELEX_PLUGIN_VERSION,
				],
				'connected'       => [
					'label' => __( 'Connection status', 'dispatch' ),
					'value' => Telex_Auth::is_connected()
						? __( 'Connected', 'dispatch' )
						: __( 'Not connected', 'dispatch' ),
				],
				'installed'       => [
					'label' => __( 'Installed projects', 'dispatch' ),
					'value' => count( Telex_Tracker::get_all() ),
				],
				'circuit_breaker' => [
					'label' => __( 'API circuit breaker', 'dispatch' ),
					'value' => Telex_Circuit_Breaker::status(),
				],
			],
		];
		return $info;
	}

	/**
	 * Registers the Telex API reachability test with Site Health.
	 *
	 * @param array<string, mixed> $tests The existing site health tests.
	 * @return array<string, mixed>
	 */
	public static function site_health_tests( array $tests ): array {
		$tests['async']['telex_api_reachable'] = [
			'label'             => __( 'Telex API is reachable', 'dispatch' ),
			'test'              => rest_url( 'telex/v1/site-health/api-reachable' ),
			'has_rest'          => true,
			'async_direct_test' => self::run_api_reachability_test( ... ),
		];
		return $tests;
	}

	/**
	 * Performs the Telex API reachability test and returns a Site Health result array.
	 *
	 * @return array<string, mixed>
	 */
	public static function run_api_reachability_test(): array {
		$client = new Telex_WP_Http_Client( timeout: 10 );
		// Test the API endpoint, not the marketing homepage, so outages are accurately detected.
		$request = new \Nyholm\Psr7\Request( 'GET', TELEX_API_BASE_URL . '/v1/health' );

		try {
			$client->sendRequest( $request );
			$reachable    = true;
			$error_detail = '';
		} catch ( \Psr\Http\Client\ClientExceptionInterface $e ) {
			$reachable    = false;
			$error_detail = $e->getMessage();
		}

		if ( ! $reachable ) {
			return [
				'label'       => __( 'Telex API is unreachable', 'dispatch' ),
				'status'      => 'critical',
				'badge'       => [
					'label' => __( 'Dispatch', 'dispatch' ),
					'color' => 'red',
				],
				'description' => '<p>' . esc_html( $error_detail ) . '</p>',
				'test'        => 'telex_api_reachable',
			];
		}

		return [
			'label'       => __( 'Telex API is reachable', 'dispatch' ),
			'status'      => 'good',
			'badge'       => [
				'label' => __( 'Dispatch', 'dispatch' ),
				'color' => 'blue',
			],
			'description' => '<p>' . esc_html__( 'Your site can talk to the Telex API. All good!', 'dispatch' ) . '</p>',
			'test'        => 'telex_api_reachable',
		];
	}
}
