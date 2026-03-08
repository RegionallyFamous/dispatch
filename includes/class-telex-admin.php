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

		// Admin bar update badge.
		add_action( 'admin_bar_menu', self::add_admin_bar_badge( ... ), 999 );

		// Site Health integration.
		add_filter( 'debug_information', self::site_health_info( ... ) );
		add_filter( 'site_status_tests', self::site_health_tests( ... ) );

		// Heartbeat API — piggyback on WP's existing polling for auth status.
		add_filter( 'heartbeat_received', self::heartbeat_received( ... ), 10, 2 );
		add_filter( 'heartbeat_nopriv_received', self::heartbeat_nopriv_deny( ... ), 10, 2 );

		// Command Palette — enqueue the global "Open Dispatch" command on all admin pages.
		add_action( 'admin_enqueue_scripts', self::enqueue_commands_script( ... ) );

		// GDPR / privacy framework.
		Telex_Privacy::init();
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
			__( 'Projects', 'dispatch' ),
			__( 'Dispatch', 'dispatch' ),
			'manage_options',
			'telex',
			self::render_page( ... ),
			'dashicons-layout',
			59
		);

		add_action( "load-{$hook}", self::add_screen_options( ... ) );

		// Override the auto-generated first submenu entry so the submenu head
		// reads "Projects" instead of "Dispatch". No callback is provided here:
		// WordPress resolves both the menu and this submenu to the same hook
		// name, so passing a callback would cause render_page() to fire twice.
		add_submenu_page(
			'telex',
			__( 'Projects', 'dispatch' ),
			__( 'Projects', 'dispatch' ),
			'manage_options',
			'telex'
		);

		add_submenu_page(
			'telex',
			__( 'Settings', 'dispatch' ),
			__( 'Settings', 'dispatch' ),
			'manage_options',
			'telex-settings',
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

			$is_network = is_multisite() ? '1' : '0';

			printf(
				'<div id="telex-projects-app" data-rest-url="%s" data-nonce="%s" data-per-page="%d" data-disconnect-url="%s" data-is-network="%s"></div>',
				esc_attr( rest_url( 'telex/v1' ) ),
				esc_attr( wp_create_nonce( 'wp_rest' ) ),
				absint( $per_page ),
				esc_attr( $disconnect_url ),
				esc_attr( $is_network )
			);
		}

		echo '</div>';
	}

	/**
	 * Renders the Settings sub-page (webhook config + audit log).
	 *
	 * @return void
	 */
	public static function render_audit_log_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'dispatch' ) );
		}

		// Enqueue the admin JS bundle so the React panels can mount.
		self::enqueue_assets();

		require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';

		// ---------------------------------------------------------------------------
		// Phase 1 — render everything that doesn't need DB data, then flush to the
		// browser so users see the skeleton immediately while queries run below.
		// ---------------------------------------------------------------------------

		echo '<div class="wrap">';
		echo '<div class="telex-page-header">';
		echo '<h1>' . esc_html__( 'Settings', 'dispatch' ) . '</h1>';
		echo '</div>';

		echo '<div class="telex-settings-body">';

		// Webhook / auto-deploy panel — skeleton lives inside the mount point so
		// React replaces it without a layout shift when the bundle boots.
		if ( Telex_Auth::is_connected() ) {
			printf(
				'<div id="telex-webhook-app" data-rest-url="%s" data-nonce="%s" data-webhook-url="%s">%s</div>',
				esc_attr( rest_url( 'telex/v1' ) ),
				esc_attr( wp_create_nonce( 'wp_rest' ) ),
				esc_attr( rest_url( 'telex/v1/deploy' ) ),
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- hardcoded static HTML with no user input.
				self::render_settings_panel_skeletons()
			);
		}

		// Audit log skeleton — shown while the DB queries run below.
		echo '<div class="telex-audit-log-skeleton" id="telex-audit-log-skeleton" aria-hidden="true">';
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- hardcoded static HTML with no user input.
		echo self::render_audit_log_skeleton();
		echo '</div>';

		// Flush skeleton HTML to the browser before hitting the DB.
		// ob_flush() pushes the current buffer; flush() tells PHP to send it.
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		if ( ob_get_level() > 0 ) {
			ob_flush();
		}
		flush();

		// ---------------------------------------------------------------------------
		// Phase 2 — run DB queries now that the skeleton is already on-screen.
		// ---------------------------------------------------------------------------

		$table = new Telex_Audit_Log_Table();
		$table->prepare_items();

		$export_url = wp_nonce_url(
			add_query_arg(
				[
					'page'   => 'telex-settings',
					'action' => 'telex_export_csv',
				],
				admin_url( 'admin.php' )
			),
			'telex_export_csv'
		);

		// Real card — starts invisible; the inline script below reveals it.
		echo '<div class="telex-audit-log-card telex-audit-log-card--pending" id="telex-audit-log-card" aria-hidden="true">';
		echo '<div class="telex-audit-log-card__header">';
		echo '<div class="telex-audit-log-card__title">';
		echo '<h3>' . esc_html__( 'Audit Log', 'dispatch' ) . '</h3>';
		printf(
			'<p>%s</p>',
			esc_html__( 'A full history of installs, updates, removals, and account changes.', 'dispatch' )
		);
		echo '</div>';
		printf(
			'<a href="%s" class="button button-secondary">%s</a>',
			esc_url( $export_url ),
			esc_html__( 'Export CSV', 'dispatch' )
		);
		echo '</div>';

		echo '<form method="get">';
		printf( '<input type="hidden" name="page" value="%s" />', esc_attr( 'telex-settings' ) );
		$table->display();
		echo '</form>';

		echo '</div>'; // .telex-audit-log-card

		// Swap skeleton → real card. Runs as soon as this <script> is parsed,
		// which is after the table HTML is fully in the DOM.
		echo '<script>(function(){';
		echo 'var s=document.getElementById("telex-audit-log-skeleton");';
		echo 'var c=document.getElementById("telex-audit-log-card");';
		echo 'if(s)s.remove();';
		echo 'if(c){c.removeAttribute("aria-hidden");c.classList.remove("telex-audit-log-card--pending");}';
		echo '})();</script>';

		echo '</div>'; // .telex-settings-body
		echo '</div>'; // .wrap
	}

	/**
	 * Returns skeleton HTML for the three React panels that mount inside
	 * #telex-webhook-app (Webhook, Notifications, Snapshots). React's render()
	 * replaces this markup when the bundle boots.
	 *
	 * @return string
	 */
	private static function render_settings_panel_skeletons(): string {
		$panel = static function ( string $title, string $body ): string {
			return sprintf(
				'<div class="telex-settings-panel-skeleton" aria-hidden="true">
					<div class="telex-settings-panel-skeleton__header">
						<div class="telex-skeleton telex-skeleton--panel-title"></div>
						<div class="telex-skeleton telex-skeleton--panel-desc"></div>
					</div>
					<div class="telex-panel-skeleton">%s</div>
				</div>',
				$body
			);
		};

		$webhook_body =
			'<div class="telex-skeleton telex-skeleton--input-group"></div>' .
			'<div class="telex-skeleton telex-skeleton--input-group"></div>';

		$notifications_body =
			'<div class="telex-skeleton telex-skeleton--checkbox-row"></div>' .
			'<div class="telex-skeleton telex-skeleton--checkbox-row"></div>' .
			'<div class="telex-skeleton telex-skeleton--checkbox-row"></div>' .
			'<div class="telex-skeleton telex-skeleton--input-group"></div>';

		$snapshots_body =
			'<div class="telex-skeleton telex-skeleton--input-group"></div>' .
			'<div class="telex-skeleton telex-skeleton--table-row"></div>' .
			'<div class="telex-skeleton telex-skeleton--table-row"></div>' .
			'<div class="telex-skeleton telex-skeleton--table-row"></div>';

		return $panel( '', $webhook_body ) .
			$panel( '', $notifications_body ) .
			$panel( '', $snapshots_body );
	}

	/**
	 * Returns skeleton HTML for the audit log card shown while DB queries run.
	 *
	 * @return string
	 */
	private static function render_audit_log_skeleton(): string {
		$header_row =
			'<div class="telex-audit-log-skeleton__header">' .
				'<div class="telex-audit-log-skeleton__title">' .
					'<div class="telex-skeleton telex-skeleton--panel-title"></div>' .
					'<div class="telex-skeleton telex-skeleton--panel-desc"></div>' .
				'</div>' .
				'<div class="telex-skeleton telex-skeleton--button"></div>' .
			'</div>';

		$col_headers =
			'<div class="telex-audit-log-skeleton__cols">' .
				'<div class="telex-skeleton telex-skeleton--col-head"></div>' .
				'<div class="telex-skeleton telex-skeleton--col-head"></div>' .
				'<div class="telex-skeleton telex-skeleton--col-head"></div>' .
				'<div class="telex-skeleton telex-skeleton--col-head"></div>' .
			'</div>';

		$rows = '';
		for ( $i = 0; $i < 6; $i++ ) {
			$rows .=
				'<div class="telex-audit-log-skeleton__row">' .
					'<div class="telex-skeleton telex-skeleton--cell-date"></div>' .
					'<div class="telex-skeleton telex-skeleton--cell-badge"></div>' .
					'<div class="telex-skeleton telex-skeleton--cell-id"></div>' .
					'<div class="telex-skeleton telex-skeleton--cell-user"></div>' .
				'</div>';
		}

		return $header_row . $col_headers . $rows;
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

	/**
	 * Enqueues the global Command Palette script on all wp-admin pages.
	 *
	 * Registers the "Open Dispatch" command so it surfaces in ⌘K / Ctrl+K
	 * on every admin screen, not just the Dispatch page.
	 *
	 * @return void
	 */
	public static function enqueue_commands_script(): void {
		$asset_file  = TELEX_PLUGIN_DIR . 'build/commands.asset.php';
		$script_file = TELEX_PLUGIN_DIR . 'build/commands.js';

		if ( ! file_exists( $asset_file ) || ! file_exists( $script_file ) ) {
			return;
		}

		$asset = include $asset_file;

		wp_enqueue_script(
			'telex-commands',
			plugins_url( 'build/commands.js', TELEX_PLUGIN_FILE ),
			$asset['dependencies'],
			$asset['version'],
			[
				'strategy'  => 'defer',
				'in_footer' => true,
			]
		);

		wp_add_inline_script(
			'telex-commands',
			'window.telexCommands = ' . wp_json_encode(
				[
					'adminUrl' => esc_url( admin_url() ),
				]
			) . ';',
			'before'
		);

		// Mount point for the React commands tree (renders nothing visible).
		add_action(
			'admin_footer',
			static function (): void {
				echo '<div id="telex-commands-root" hidden></div>';
			}
		);
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
		// Persistent warning when file modifications are disabled at the server level.
		if ( ! wp_is_file_mod_allowed( 'plugin_updates' ) ) {
			printf(
				'<div class="notice notice-warning"><p>%s</p></div>',
				wp_kses_post(
					sprintf(
						/* translators: %s: constant name */
						__( '<strong>Dispatch for Telex</strong>: The <code>%s</code> constant is set on this server. Installing and removing projects is disabled. Contact your host or remove the constant from <code>wp-config.php</code> to enable file modifications.', 'dispatch' ),
						'DISALLOW_FILE_MODS'
					)
				)
			);
		}

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
	 * Sanitises a CSV cell value to prevent spreadsheet formula injection.
	 *
	 * Cells whose first character is a formula trigger ( =, +, -, @, TAB, CR )
	 * will be interpreted as formulas by Excel, LibreOffice Calc, and Google
	 * Sheets. Prefixing with a TAB character defuses this while keeping the
	 * value human-readable in any viewer.
	 *
	 * @param string $value Raw cell value.
	 * @return string Safe cell value.
	 */
	private static function csv_safe( string $value ): string {
		if ( '' === $value ) {
			return $value;
		}
		if ( in_array( $value[0], [ '=', '+', '-', '@', "\t", "\r" ], true ) ) {
			return "\t" . $value;
		}
		return $value;
	}

	/**
	 * Streams the audit log as a CSV download if the correct action + nonce are present.
	 *
	 * @return void
	 */
	public static function maybe_export_csv(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if (
			! isset( $_GET['page'], $_GET['action'] ) ||
			'telex-settings' !== $_GET['page'] ||
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

		$events = Telex_Audit_Log::get_recent( 10000 );

		// Batch-fetch all user records in one query to avoid N+1 get_userdata() calls.
		$user_ids  = array_filter(
			array_unique( array_column( $events, 'user_id' ) ),
			static fn( $id ) => (int) $id > 0
		);
		$users_map = [];
		if ( ! empty( $user_ids ) ) {
			$user_objects = get_users(
				[
					'include' => array_map( 'intval', $user_ids ),
					'fields'  => [ 'ID', 'user_login' ],
				]
			);
			foreach ( $user_objects as $u ) {
				$users_map[ (int) $u->ID ] = $u->user_login;
			}
		}

		$filename = 'dispatch-audit-' . gmdate( 'Y-m-d' ) . '.csv';

		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$out = fopen( 'php://output', 'w' );
		if ( false === $out ) {
			wp_die( esc_html__( 'Could not open output stream for CSV export.', 'dispatch' ) );
		}

		// BOM for Excel UTF-8 compatibility.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		fwrite( $out, "\xEF\xBB\xBF" );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fputcsv
		fputcsv( $out, [ 'Date (UTC)', 'Action', 'Project ID', 'User' ] );

		foreach ( $events as $event ) {
			$uid  = (int) ( $event['user_id'] ?? 0 );
			$user = $uid > 0
				? ( $users_map[ $uid ] ?? sprintf( '#%d', $uid ) )
				: '(system)';

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fputcsv
			fputcsv(
				$out,
				[
					self::csv_safe( (string) ( $event['created_at'] ?? '' ) ),
					self::csv_safe( (string) ( $event['action'] ?? '' ) ),
					self::csv_safe( (string) ( $event['public_id'] ?? '' ) ),
					self::csv_safe( $user ),
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
			// Refresh the REST nonce on every Heartbeat tick so long-lived
			// admin sessions don't silently fail with 403s after 24 hours.
			'telex_nonce'    => wp_create_nonce( 'wp_rest' ),
			// Dirty flag: incremented by REST mutation endpoints so all open
			// admin tabs silently re-fetch the project list when another user
			// installs, updates, or removes a project.
			'data_version'   => (string) ( get_transient( 'telex_data_version' ) ? get_transient( 'telex_data_version' ) : '' ),
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
	// Admin bar update badge
	// -------------------------------------------------------------------------

	/**
	 * Adds a Dispatch update-count bubble to the WP admin bar.
	 *
	 * Uses only already-cached data — no API call is made here.
	 * The node only renders when the update count is > 0 and the current user
	 * has manage_options. Clicking navigates to the Dispatch page.
	 *
	 * @param \WP_Admin_Bar $wp_admin_bar The WP_Admin_Bar instance.
	 * @return void
	 */
	public static function add_admin_bar_badge( \WP_Admin_Bar $wp_admin_bar ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Count updates from cache only — never trigger a live API call here.
		$cached = Telex_Cache::get_projects();
		if ( ! is_array( $cached ) ) {
			return;
		}

		$installed    = Telex_Tracker::get_all();
		$update_count = 0;

		foreach ( $cached as $project ) {
			$id    = $project['publicId'] ?? '';
			$local = $installed[ $id ] ?? null;
			if ( null !== $local && ( (int) ( $project['currentVersion'] ?? 0 ) ) > (int) $local['version'] ) {
				++$update_count;
			}
		}

		if ( $update_count <= 0 ) {
			return;
		}

		$wp_admin_bar->add_node(
			[
				'id'    => 'dispatch-updates',
				'title' => sprintf(
					'%s <span class="ab-label update-count">%d</span>',
					esc_html__( 'Dispatch', 'dispatch' ),
					$update_count
				),
				'href'  => esc_url( admin_url( 'admin.php?page=telex#updates' ) ),
				'meta'  => [
					'title' => sprintf(
						/* translators: %d: number of available updates */
						_n(
							'%d Dispatch update available',
							'%d Dispatch updates available',
							$update_count,
							'dispatch'
						),
						$update_count
					),
				],
			]
		);
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
		$cache_key = 'telex_api_reachability';
		$cached    = get_transient( $cache_key );

		if ( is_array( $cached ) ) {
			return $cached;
		}

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
			$result = [
				'label'       => __( 'Telex API is unreachable', 'dispatch' ),
				'status'      => 'critical',
				'badge'       => [
					'label' => __( 'Dispatch', 'dispatch' ),
					'color' => 'red',
				],
				'description' => '<p>' . esc_html( $error_detail ) . '</p>',
				'test'        => 'telex_api_reachable',
			];
		} else {
			$result = [
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

		set_transient( $cache_key, $result, 5 * MINUTE_IN_SECONDS );

		return $result;
	}
}
