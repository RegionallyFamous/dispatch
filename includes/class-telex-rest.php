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
 *   GET    /telex/v1/projects/(?P<id>[a-zA-Z0-9_-]+)         (single project detail)
 *   GET    /telex/v1/projects/(?P<id>[a-zA-Z0-9_-]+)/build   (build readiness)
 *   POST   /telex/v1/projects/(?P<id>[a-zA-Z0-9_-]+)/install
 *   POST   /telex/v1/projects/(?P<id>[a-zA-Z0-9_-]+)/deploy-network  (multisite)
 *   DELETE /telex/v1/projects/(?P<id>[a-zA-Z0-9_-]+)
 *   POST   /telex/v1/auth/device    (start device flow)
 *   GET    /telex/v1/auth/device    (poll device flow)
 *   DELETE /telex/v1/auth/device    (cancel device flow)
 *   DELETE /telex/v1/auth           (disconnect)
 *   GET    /telex/v1/auth/status    (connection status)
 *   POST   /telex/v1/deploy         (webhook auto-deploy, HMAC-signed)
 *   POST   /telex/v1/settings/deploy-secret  (regenerate deploy secret)
 */
class Telex_REST {

	private const NAMESPACE          = 'telex/v1';
	private const DEPLOY_SECRET_KEY  = 'dispatch_deploy_secret';
	private const DEPLOY_REPLAY_SECS = 300; // 5-minute replay window

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

		// Single project detail.
		register_rest_route(
			self::NAMESPACE,
			'/projects/(?P<id>[a-zA-Z0-9_\-]+)',
			[
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ self::class, 'get_project' ],
					'permission_callback' => [ self::class, 'require_manage_options' ],
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

		// Multisite network deploy.
		register_rest_route(
			self::NAMESPACE,
			'/projects/(?P<id>[a-zA-Z0-9_\-]+)/deploy-network',
			[
				[
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => [ self::class, 'deploy_network' ],
					'permission_callback' => [ self::class, 'require_network_admin' ],
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

		// Webhook auto-deploy (public endpoint, HMAC-validated).
		register_rest_route(
			self::NAMESPACE,
			'/deploy',
			[
				[
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => [ self::class, 'webhook_deploy' ],
					'permission_callback' => '__return_true',
				],
			]
		);

		// Regenerate deploy secret.
		register_rest_route(
			self::NAMESPACE,
			'/settings/deploy-secret',
			[
				[
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => [ self::class, 'regenerate_deploy_secret' ],
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

		// Prune tracker entries whose plugin/theme directory no longer exists on disk.
		// Inexpensive is_dir() calls; safe on every request.
		Telex_Tracker::reconcile();

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

		// The bulk list() API does not include currentVersion. For installed projects we
		// need the accurate version to detect updates. Use per-project cache when warm
		// (seeded by Telex_Updater on every WP update-check); fall back to individual
		// get() calls only for the installed subset — typically 1–5 projects.
		$remote_versions = [];
		if ( ! empty( $installed ) ) {
			$vc = Telex_Auth::get_client();
			if ( $vc ) {
				foreach ( array_keys( $installed ) as $installed_id ) {
					$cp = Telex_Cache::get_project( $installed_id );
					if ( is_array( $cp ) && isset( $cp['currentVersion'] ) ) {
						$remote_versions[ $installed_id ] = (int) $cp['currentVersion'];
					} else {
						try {
							$rp                               = $vc->projects->get( $installed_id );
							$remote_versions[ $installed_id ] = (int) ( $rp['currentVersion'] ?? 0 );
							Telex_Cache::set_project( $installed_id, $rp );
						} catch ( \Exception ) {
							$remote_versions[ $installed_id ] = 0;
						}
					}
				}
			}
		}

		// Decorate each project with local install status.
		$projects = array_map(
			static function ( array $project ) use ( $installed, $remote_versions ): array {
				$id             = $project['publicId'] ?? '';
				$remote_version = $remote_versions[ $id ] ?? (int) ( $project['currentVersion'] ?? 0 );
				$local          = $installed[ $id ] ?? null;

				// Inject currentVersion so the JS side can compare without a separate call.
				if ( $remote_version > 0 ) {
					$project['currentVersion'] = $remote_version;
				}

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

		// ETag derived only from data content, not the from_cache flag, so clients
		// receive a 304 whenever the project list hasn't changed regardless of cache state.
		$etag          = '"' . md5( wp_json_encode( [ $projects, $installed ] ) ) . '"';
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

		// Pending: merge server hints then explicitly set authorized=false so a
		// non-standard API key can never accidentally override it.
		$pending               = array_merge( [ 'interval' => null ], $result );
		$pending['authorized'] = false;
		return rest_ensure_response( $pending );
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

	// -------------------------------------------------------------------------
	// Single project endpoint
	// -------------------------------------------------------------------------

	/**
	 * GET /telex/v1/projects/{id} — returns a single project with local install state.
	 *
	 * The response shape mirrors a single item from GET /projects so the React
	 * ProjectDetailModal can consume it without any special-casing.
	 *
	 * @param \WP_REST_Request $request The incoming REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function get_project( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		if ( ! Telex_Auth::is_connected() ) {
			return new \WP_Error( 'telex_not_connected', __( "You're not connected. Head to Dispatch to link your account.", 'dispatch' ), [ 'status' => 401 ] );
		}

		$client = Telex_Auth::get_client();
		if ( ! $client ) {
			return new \WP_Error( 'telex_client', __( 'Could not initialise Telex client.', 'dispatch' ), [ 'status' => 500 ] );
		}

		$public_id = sanitize_text_field( (string) $request->get_param( 'id' ) );

		try {
			$project = $client->projects->get( $public_id );
		} catch ( \Exception $e ) {
			return new \WP_Error( 'telex_api', $e->getMessage(), [ 'status' => 502 ] );
		}

		$installed = Telex_Tracker::get( $public_id );
		$id        = $project['publicId'] ?? '';
		$version   = (int) ( $project['currentVersion'] ?? 0 );
		$local     = $installed ?? null;

		$project['_installed']    = null !== $local;
		$project['_needs_update'] = null !== $local && $version > (int) ( $local['version'] ?? 0 );
		$project['_local']        = $local;

		return rest_ensure_response( $project );
	}

	// -------------------------------------------------------------------------
	// Multisite network deploy
	// -------------------------------------------------------------------------

	/**
	 * POST /telex/v1/projects/{id}/deploy-network — installs/updates a project on every subsite.
	 *
	 * @param \WP_REST_Request $request The incoming REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function deploy_network( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		if ( ! is_multisite() ) {
			return new \WP_Error( 'telex_not_multisite', __( 'Network deploy is only available on multisite installs.', 'dispatch' ), [ 'status' => 400 ] );
		}

		$public_id = sanitize_text_field( $request->get_param( 'id' ) );

		$sites = get_sites(
			[
				'number'   => 200,
				'public'   => 1,
				'deleted'  => 0,
				'spam'     => 0,
				'archived' => 0,
			]
		);

		$succeeded = [];
		$failed    = [];

		foreach ( $sites as $site ) {
			switch_to_blog( (int) $site->blog_id );

			$result = Telex_Installer::install( $public_id );

			if ( is_wp_error( $result ) ) {
				$failed[] = [
					'id'     => (int) $site->blog_id,
					'domain' => $site->domain . $site->path,
					'error'  => $result->get_error_message(),
				];
			} else {
				$succeeded[] = [
					'id'     => (int) $site->blog_id,
					'domain' => $site->domain . $site->path,
				];
			}

			restore_current_blog();
		}

		return rest_ensure_response(
			[
				'succeeded' => $succeeded,
				'failed'    => $failed,
			]
		);
	}

	// -------------------------------------------------------------------------
	// Webhook auto-deploy
	// -------------------------------------------------------------------------

	/**
	 * Returns the deploy secret, generating one on first call.
	 *
	 * @return string 64-char hex secret.
	 */
	public static function get_deploy_secret(): string {
		$secret = get_option( self::DEPLOY_SECRET_KEY );
		if ( ! $secret ) {
			$secret = bin2hex( random_bytes( 32 ) );
			update_option( self::DEPLOY_SECRET_KEY, $secret, false );
		}
		return (string) $secret;
	}

	/**
	 * POST /telex/v1/settings/deploy-secret — regenerates the deploy secret.
	 *
	 * @param \WP_REST_Request $request The incoming REST request.
	 * @return \WP_REST_Response
	 */
	public static function regenerate_deploy_secret( \WP_REST_Request $request ): \WP_REST_Response { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		$secret = bin2hex( random_bytes( 32 ) );
		update_option( self::DEPLOY_SECRET_KEY, $secret, false );
		return rest_ensure_response( [ 'secret' => $secret ] );
	}

	/**
	 * POST /telex/v1/deploy — HMAC-signed webhook that triggers a project install/update.
	 *
	 * Expected payload:  { "project_id": "...", "build_version": 42, "timestamp": 1234567890 }
	 * Expected header:   X-Telex-Signature: sha256=<hex-digest>
	 *
	 * The signature is computed over the raw request body with the deploy secret as the key.
	 * Requests older than DEPLOY_REPLAY_SECS are rejected to prevent replay attacks.
	 *
	 * @param \WP_REST_Request $request The incoming REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function webhook_deploy( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		// --- Signature verification -------------------------------------------
		$sig_header = $request->get_header( 'X-Telex-Signature' );
		if ( ! $sig_header || ! str_starts_with( $sig_header, 'sha256=' ) ) {
			return new \WP_Error( 'telex_no_signature', 'Missing or malformed X-Telex-Signature header.', [ 'status' => 401 ] );
		}

		$provided_sig = substr( $sig_header, 7 );
		$secret       = self::get_deploy_secret();
		$body         = $request->get_body();
		$expected_sig = hash_hmac( 'sha256', $body, $secret );

		if ( ! hash_equals( $expected_sig, $provided_sig ) ) {
			return new \WP_Error( 'telex_bad_signature', 'Signature verification failed.', [ 'status' => 401 ] );
		}

		// --- Replay protection ------------------------------------------------
		$timestamp = (int) $request->get_param( 'timestamp' );
		if ( $timestamp && abs( time() - $timestamp ) > self::DEPLOY_REPLAY_SECS ) {
			return new \WP_Error( 'telex_replay', 'Request timestamp is too old.', [ 'status' => 400 ] );
		}

		// --- Install ----------------------------------------------------------
		$public_id = sanitize_text_field( (string) ( $request->get_param( 'project_id' ) ?? '' ) );
		if ( ! $public_id ) {
			return new \WP_Error( 'telex_missing_id', 'project_id is required.', [ 'status' => 400 ] );
		}

		$result = Telex_Installer::install( $public_id );

		if ( is_wp_error( $result ) ) {
			return new \WP_Error(
				$result->get_error_code(),
				$result->get_error_message(),
				[ 'status' => self::http_status_for_error( $result->get_error_code() ) ]
			);
		}

		return rest_ensure_response(
			[
				'success'    => true,
				'project_id' => $public_id,
			]
		);
	}

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

	/**
	 * Permission callback: requires manage_network_plugins (super-admin on multisite).
	 *
	 * @return bool|\WP_Error
	 */
	public static function require_network_admin(): bool|\WP_Error {
		if ( ! is_user_logged_in() ) {
			return new \WP_Error( 'telex_unauthorized', __( 'You need to be logged in to do that.', 'dispatch' ), [ 'status' => 401 ] );
		}
		return current_user_can( 'manage_network_plugins' )
			? true
			: new \WP_Error( 'telex_forbidden', __( "You don't have permission to do that.", 'dispatch' ), [ 'status' => 403 ] );
	}
}
