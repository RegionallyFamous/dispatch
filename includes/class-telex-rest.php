<?php
/**
 * REST API endpoints for the Dispatch plugin (telex/v1 namespace).
 *
 * @package Dispatch_For_Telex
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers all Telex REST API endpoints under the telex/v1 namespace.
 *
 * Routes:
 *   GET    /telex/v1/projects
 *   GET    /telex/v1/projects/(?P<id>[a-zA-Z0-9_-]+)/build   (build readiness)
 *   POST   /telex/v1/projects/(?P<id>[a-zA-Z0-9_-]+)/install
 *   DELETE /telex/v1/projects/(?P<id>[a-zA-Z0-9_-]+)
 *   POST   /telex/v1/auth/device    (start device flow)
 *   GET    /telex/v1/auth/device    (poll device flow)
 *   DELETE /telex/v1/auth/device    (cancel device flow)
 *   DELETE /telex/v1/auth           (disconnect)
 *   GET    /telex/v1/auth/status    (connection status)
 */
class Telex_REST {

	private const NAMESPACE = 'telex/v1';

	/**
	 * Registers all telex/v1 REST routes.
	 *
	 * @return void
	 */
	public static function register_routes(): void {
		// Projects.
		register_rest_route(
			self::NAMESPACE,
			'/projects',
			[
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ self::class, 'get_projects' ],
					'permission_callback' => [ self::class, 'require_manage_options' ],
					'args'                => [
						'force_refresh' => [
							'type'    => 'boolean',
							'default' => false,
						],
					],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/projects/(?P<id>[a-zA-Z0-9_\-]+)/install',
			[
				[
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => [ self::class, 'install_project' ],
					'permission_callback' => [ self::class, 'require_install_cap' ],
					'args'                => [
						'id'       => [
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_text_field',
						],
						'activate' => [
							'type'    => 'boolean',
							'default' => false,
						],
					],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/projects/(?P<id>[a-zA-Z0-9_\-]+)',
			[
				[
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => [ self::class, 'remove_project' ],
					'permission_callback' => [ self::class, 'require_remove_cap' ],
					'args'                => [
						'id' => [
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_text_field',
						],
					],
				],
			]
		);

		// Build-readiness polling endpoint — used by the frontend between "building" and "installing".
		register_rest_route(
			self::NAMESPACE,
			'/projects/(?P<id>[a-zA-Z0-9_\-]+)/build',
			[
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ self::class, 'get_build_status' ],
					'permission_callback' => [ self::class, 'require_install_cap' ],
					'args'                => [
						'id' => [
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_text_field',
						],
					],
				],
			]
		);

		// Installed projects (local tracker data only — no API call needed).
		register_rest_route(
			self::NAMESPACE,
			'/installed',
			[
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ self::class, 'get_installed' ],
					'permission_callback' => [ self::class, 'require_manage_options' ],
				],
			]
		);

		// Auth — device flow.
		register_rest_route(
			self::NAMESPACE,
			'/auth/device',
			[
				[
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => [ self::class, 'start_device_flow' ],
					'permission_callback' => [ self::class, 'require_manage_options' ],
				],
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ self::class, 'poll_device_flow' ],
					'permission_callback' => [ self::class, 'require_manage_options' ],
				],
				[
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => [ self::class, 'cancel_device_flow' ],
					'permission_callback' => [ self::class, 'require_manage_options' ],
				],
			]
		);

		// Auth — disconnect & status.
		register_rest_route(
			self::NAMESPACE,
			'/auth',
			[
				[
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => [ self::class, 'disconnect' ],
					'permission_callback' => [ self::class, 'require_manage_options' ],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/auth/status',
			[
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ self::class, 'get_auth_status' ],
					'permission_callback' => [ self::class, 'require_manage_options' ],
				],
			]
		);
	}

	// -------------------------------------------------------------------------
	// Projects endpoints
	// -------------------------------------------------------------------------

	/**
	 * GET /telex/v1/projects — returns all Telex projects decorated with local install state.
	 *
	 * @param \WP_REST_Request $request The incoming REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function get_projects( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$_rl_retry = Telex_Auth::check_rate_limit( 'get_projects' );
		if ( $_rl_retry > 0 ) {
			return new \WP_Error(
				'telex_rate_limit',
				__( 'Slow down! Give it a moment and try again.', 'dispatch' ),
				[
					'status'  => 429,
					'headers' => [ 'Retry-After' => (string) $_rl_retry ],
				]
			);
		}

		$installed  = Telex_Tracker::get_all();
		$from_cache = false;

		// When the user explicitly requests fresh data (e.g. the Refresh button), skip the cache.
		if ( (bool) $request->get_param( 'force_refresh' ) ) {
			Telex_Cache::bust_all();
		}

		// Try stale-while-revalidate first — serves instantly and schedules background refresh.
		$stale_or_live = Telex_Cache::get_or_revalidate();
		if ( null !== $stale_or_live ) {
			$projects   = $stale_or_live;
			$from_cache = true;
		} else {
			// No cached data at all — must hit the API synchronously.
			$client = Telex_Auth::get_client();
			if ( ! $client ) {
				return new \WP_Error( 'telex_not_connected', __( "You're not connected. Head to Dispatch to link your account.", 'dispatch' ), [ 'status' => 401 ] );
			}

			try {
				$response = $client->projects->list( [ 'perPage' => 100 ] );
				$projects = $response['projects'] ?? [];
				Telex_Cache::set_projects( $projects );
				Telex_Circuit_Breaker::record_success();
			} catch ( \Telex\Sdk\Exceptions\AuthenticationException ) {
				Telex_Circuit_Breaker::record_failure();
				Telex_Auth::disconnect();
				return new \WP_Error( 'telex_token_expired', __( 'Your session expired — head to Dispatch to reconnect.', 'dispatch' ), [ 'status' => 401 ] );
			} catch ( \Exception $e ) {
				Telex_Circuit_Breaker::record_failure();
				return new \WP_Error( 'telex_api', $e->getMessage(), [ 'status' => 500 ] );
			}
		}

		// Decorate each project with local install status to eliminate a second round-trip.
		$projects = array_map(
			static function ( array $project ) use ( $installed ): array {
				$id             = $project['publicId'] ?? '';
				$remote_version = (int) ( $project['currentVersion'] ?? 0 );
				$local          = $installed[ $id ] ?? null;

				$project['_installed']    = null !== $local;
				$project['_needs_update'] = null !== $local && $remote_version > (int) $local['version'];
				$project['_local']        = $local;

				return $project;
			},
			$projects
		);

		$body = [
			'projects'   => $projects,
			'installed'  => $installed,
			'total'      => count( $projects ),
			'from_cache' => $from_cache,
		];

		// ETag for conditional GET — allows clients to skip parsing unchanged responses.
		$etag          = '"' . md5( wp_json_encode( $body ) ) . '"';
		$if_none_match = trim( $request->get_header( 'If-None-Match' ) ?? '' );

		$response = rest_ensure_response( $body );
		$response->header( 'ETag', $etag );
		$response->header( 'Cache-Control', 'private, no-store' );
		$response->header( 'Vary', 'Accept-Encoding' );

		// Return 304 Not Modified if the client has an up-to-date copy.
		if ( $if_none_match && hash_equals( $etag, $if_none_match ) ) {
			$response->set_status( 304 );
			$response->set_data( null );
		}

		return $response;
	}

	/**
	 * GET /telex/v1/installed — returns locally tracked project data (no API call).
	 *
	 * @param \WP_REST_Request $_request The incoming REST request (unused).
	 * @return \WP_REST_Response
	 */
	public static function get_installed( \WP_REST_Request $_request ): \WP_REST_Response { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		return rest_ensure_response(
			[
				'installed' => Telex_Tracker::get_all(),
			]
		);
	}

	/**
	 * POST /telex/v1/projects/{id}/install — installs a Telex project.
	 *
	 * @param \WP_REST_Request $request The incoming REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function install_project( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$_rl_retry = Telex_Auth::check_rate_limit( 'install' );
		if ( $_rl_retry > 0 ) {
			return new \WP_Error(
				'telex_rate_limit',
				__( 'Slow down! Give it a moment and try again.', 'dispatch' ),
				[
					'status'  => 429,
					'headers' => [ 'Retry-After' => (string) $_rl_retry ],
				]
			);
		}

		$public_id = $request->get_param( 'id' );
		$activate  = (bool) $request->get_param( 'activate' );

		// Check whether the build is ready before handing off to the installer.
		// If it isn't, ask Telex to queue one and tell the client to poll.
		$client = Telex_Auth::get_client();
		if ( ! $client ) {
			return new \WP_Error( 'telex_not_connected', __( "You're not connected. Head to Dispatch to link your account.", 'dispatch' ), [ 'status' => 401 ] );
		}

		try {
			$build = $client->projects->getBuild( $public_id );
		} catch ( \Telex\Sdk\Exceptions\TelexException $e ) {
			return new \WP_Error( 'telex_api', $e->getMessage(), [ 'status' => 502 ] );
		}

		if ( isset( $build['status'] ) && 'not_ready' === $build['status'] ) {
			// Best-effort trigger — not all Telex plans expose this endpoint.
			try {
				$client->projects->triggerBuild( $public_id );
			} catch ( \Exception $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement -- intentionally silent; polling will still work.
			}

			return rest_ensure_response(
				[
					'status'        => 'building',
					'poll_interval' => 5,
				]
			);
		}

		$result = Telex_Installer::install( $public_id, $activate );

		if ( is_wp_error( $result ) ) {
			return new \WP_Error(
				$result->get_error_code(),
				$result->get_error_message(),
				[ 'status' => self::http_status_for_error( $result->get_error_code() ) ]
			);
		}

		return rest_ensure_response(
			[
				'status'  => 'installed',
				/* translators: %s: project public ID */
				'message' => sprintf( __( 'Project %s installed successfully.', 'dispatch' ), $public_id ),
			]
		);
	}

	/**
	 * GET /telex/v1/projects/{id}/build — reports whether a project's build is ready to install.
	 *
	 * Called by the frontend while it is waiting for a build to finish.
	 * Returns { ready: bool, poll_interval: int } so the client knows when to retry.
	 *
	 * @param \WP_REST_Request $request The incoming REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function get_build_status( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$client = Telex_Auth::get_client();
		if ( ! $client ) {
			return new \WP_Error( 'telex_not_connected', __( "You're not connected. Head to Dispatch to link your account.", 'dispatch' ), [ 'status' => 401 ] );
		}

		$public_id = $request->get_param( 'id' );

		try {
			$build = $client->projects->getBuild( $public_id );
		} catch ( \Telex\Sdk\Exceptions\TelexException $e ) {
			return new \WP_Error( 'telex_api', $e->getMessage(), [ 'status' => 502 ] );
		}

		$ready = ! ( isset( $build['status'] ) && 'not_ready' === $build['status'] )
			&& ! empty( $build['files'] );

		return rest_ensure_response(
			[
				'ready'         => $ready,
				'poll_interval' => 5,
			]
		);
	}

	/**
	 * DELETE /telex/v1/projects/{id} — removes an installed Telex project.
	 *
	 * @param \WP_REST_Request $request The incoming REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function remove_project( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$_rl_retry = Telex_Auth::check_rate_limit( 'remove' );
		if ( $_rl_retry > 0 ) {
			return new \WP_Error(
				'telex_rate_limit',
				__( 'Slow down! Give it a moment and try again.', 'dispatch' ),
				[
					'status'  => 429,
					'headers' => [ 'Retry-After' => (string) $_rl_retry ],
				]
			);
		}

		$public_id = $request->get_param( 'id' );
		$result    = Telex_Installer::remove( $public_id );

		if ( is_wp_error( $result ) ) {
			return new \WP_Error(
				$result->get_error_code(),
				$result->get_error_message(),
				[ 'status' => self::http_status_for_error( $result->get_error_code() ) ]
			);
		}

		return rest_ensure_response( [ 'success' => true ] );
	}

	// -------------------------------------------------------------------------
	// Auth / device flow endpoints
	// -------------------------------------------------------------------------

	/**
	 * POST /telex/v1/auth/device — initiates the OAuth 2.0 Device Authorization flow.
	 *
	 * @param \WP_REST_Request $request The incoming REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function start_device_flow( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		$_rl_retry = Telex_Auth::check_rate_limit( 'device_start' );
		if ( $_rl_retry > 0 ) {
			return new \WP_Error(
				'telex_rate_limit',
				__( 'Slow down! Give it a moment and try again.', 'dispatch' ),
				[
					'status'  => 429,
					'headers' => [ 'Retry-After' => (string) $_rl_retry ],
				]
			);
		}

		$result = Telex_Auth::start_device_flow();
		if ( is_wp_error( $result ) ) {
			return new \WP_Error(
				$result->get_error_code(),
				$result->get_error_message(),
				[ 'status' => 502 ]
			);
		}

		return rest_ensure_response( $result );
	}

	/**
	 * GET /telex/v1/auth/device — polls for device flow completion.
	 *
	 * @param \WP_REST_Request $request The incoming REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function poll_device_flow( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		$_rl_retry = Telex_Auth::check_rate_limit( 'device_poll' );
		if ( $_rl_retry > 0 ) {
			return new \WP_Error(
				'telex_rate_limit',
				__( 'Slow down! Give it a moment and try again.', 'dispatch' ),
				[
					'status'  => 429,
					'headers' => [ 'Retry-After' => (string) $_rl_retry ],
				]
			);
		}

		$device_code = get_transient( Telex_Auth::TRANSIENT_DEVICE );
		if ( empty( $device_code ) ) {
			return new \WP_Error( 'telex_no_device_flow', __( 'No sign-in in progress. Start again from the Dispatch page.', 'dispatch' ), [ 'status' => 400 ] );
		}

		$result = Telex_Auth::poll_device_flow( (string) $device_code );

		if ( is_wp_error( $result ) ) {
			return new \WP_Error(
				$result->get_error_code(),
				$result->get_error_message(),
				[ 'status' => 400 ]
			);
		}

		if ( true === $result ) {
			Telex_Audit_Log::log( AuditAction::Connect );
			return rest_ensure_response( [ 'authorized' => true ] );
		}

		// Pending array: status is 'pending' or 'slow_down'; slow_down includes an 'interval' hint.
		return rest_ensure_response( array_merge( [ 'authorized' => false ], $result ) );
	}

	/**
	 * DELETE /telex/v1/auth/device — cancels an in-progress device flow.
	 *
	 * @param \WP_REST_Request $request The incoming REST request.
	 * @return \WP_REST_Response
	 */
	public static function cancel_device_flow( \WP_REST_Request $request ): \WP_REST_Response { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		delete_transient( Telex_Auth::TRANSIENT_DEVICE );
		return rest_ensure_response( [ 'cancelled' => true ] );
	}

	/**
	 * DELETE /telex/v1/auth — disconnects the site from Telex.
	 *
	 * @param \WP_REST_Request $request The incoming REST request.
	 * @return \WP_REST_Response
	 */
	public static function disconnect( \WP_REST_Request $request ): \WP_REST_Response { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		Telex_Auth::disconnect();
		Telex_Audit_Log::log( AuditAction::Disconnect );
		return rest_ensure_response( [ 'disconnected' => true ] );
	}

	/**
	 * GET /telex/v1/auth/status — returns the current connection status.
	 *
	 * @param \WP_REST_Request $request The incoming REST request.
	 * @return \WP_REST_Response
	 */
	public static function get_auth_status( \WP_REST_Request $request ): \WP_REST_Response { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		return rest_ensure_response(
			[
				'status'       => Telex_Auth::get_status()->value,
				'is_connected' => Telex_Auth::is_connected(),
			]
		);
	}

	// -------------------------------------------------------------------------
	// Permission callbacks
	// -------------------------------------------------------------------------

	// -------------------------------------------------------------------------
	// Internal helpers
	// -------------------------------------------------------------------------

	/**
	 * Maps a Telex WP_Error code to an appropriate HTTP status code.
	 *
	 * Installer and auth methods return WP_Error codes that indicate client-side
	 * problems (bad build state, capability failures, API unavailability). Sending
	 * all of these as 500 is incorrect and masks the real cause from the frontend.
	 *
	 * @param string $code The WP_Error code.
	 * @return int HTTP status code.
	 */
	private static function http_status_for_error( string $code ): int {
		return match ( $code ) {
			'telex_not_connected'  => 401,
			'telex_caps'           => 403,
			'telex_forbidden'      => 403,
			'telex_not_installed'  => 404,
			'telex_active_theme'   => 409,
			'telex_not_ready'      => 503,
			'telex_no_files',
			'telex_path',
			'telex_ext',
			'telex_blocked_ext'    => 422,
			'telex_api',
			'telex_download'       => 502,
			default                => 500,
		};
	}

	/**
	 * Permission callback: requires manage_options capability.
	 *
	 * @return bool|\WP_Error
	 */
	public static function require_manage_options(): bool|\WP_Error {
		if ( ! is_user_logged_in() ) {
			return new \WP_Error( 'telex_unauthorized', __( 'You need to be logged in to do that.', 'dispatch' ), [ 'status' => 401 ] );
		}
		return current_user_can( 'manage_options' )
			? true
			: new \WP_Error( 'telex_forbidden', __( "You don't have permission to do that.", 'dispatch' ), [ 'status' => 403 ] );
	}

	/**
	 * Permission callback: requires install_plugins or install_themes capability.
	 *
	 * @param \WP_REST_Request $request The incoming REST request.
	 * @return bool|\WP_Error
	 */
	public static function require_install_cap( \WP_REST_Request $request ): bool|\WP_Error { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		if ( ! is_user_logged_in() ) {
			return new \WP_Error( 'telex_unauthorized', __( 'You need to be logged in to do that.', 'dispatch' ), [ 'status' => 401 ] );
		}
		// We don't know the type until we fetch the project; gate on generic install_plugins.
		// The Installer itself re-checks with the correct cap per project type.
		return current_user_can( 'install_plugins' ) || current_user_can( 'install_themes' )
			? true
			: new \WP_Error( 'telex_forbidden', __( "You don't have permission to install projects.", 'dispatch' ), [ 'status' => 403 ] );
	}

	/**
	 * Permission callback: requires delete_plugins or delete_themes capability.
	 *
	 * @param \WP_REST_Request $request The incoming REST request.
	 * @return bool|\WP_Error
	 */
	public static function require_remove_cap( \WP_REST_Request $request ): bool|\WP_Error { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		if ( ! is_user_logged_in() ) {
			return new \WP_Error( 'telex_unauthorized', __( 'You need to be logged in to do that.', 'dispatch' ), [ 'status' => 401 ] );
		}
		return current_user_can( 'delete_plugins' ) || current_user_can( 'delete_themes' )
			? true
			: new \WP_Error( 'telex_forbidden', __( "You don't have permission to remove projects.", 'dispatch' ), [ 'status' => 403 ] );
	}
}
