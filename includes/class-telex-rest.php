<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers all Telex REST API endpoints under the telex/v1 namespace.
 *
 * Routes:
 *   GET    /telex/v1/projects
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

	public static function register_routes(): void {
		// Projects.
		register_rest_route(
			namespace: self::NAMESPACE,
			route:     '/projects',
			args:      [
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => self::get_projects(...),
					'permission_callback' => self::require_manage_options(...),
				],
			]
		);

		register_rest_route(
			namespace: self::NAMESPACE,
			route:     '/projects/(?P<id>[a-zA-Z0-9_\-]+)/install',
			args:      [
				[
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => self::install_project(...),
					'permission_callback' => self::require_install_cap(...),
					'args'                => [
						'id'       => [ 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ],
						'activate' => [ 'type' => 'boolean', 'default' => false ],
					],
				],
			]
		);

		register_rest_route(
			namespace: self::NAMESPACE,
			route:     '/projects/(?P<id>[a-zA-Z0-9_\-]+)',
			args:      [
				[
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => self::remove_project(...),
					'permission_callback' => self::require_remove_cap(...),
					'args'                => [
						'id' => [ 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ],
					],
				],
			]
		);

		// Installed projects (local tracker data only — no API call needed).
		register_rest_route(
			namespace: self::NAMESPACE,
			route:     '/installed',
			args:      [
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => self::get_installed(...),
					'permission_callback' => self::require_manage_options(...),
				],
			]
		);

		// Auth — device flow.
		register_rest_route(
			namespace: self::NAMESPACE,
			route:     '/auth/device',
			args:      [
				[
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => self::start_device_flow(...),
					'permission_callback' => self::require_manage_options(...),
				],
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => self::poll_device_flow(...),
					'permission_callback' => self::require_manage_options(...),
				],
				[
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => self::cancel_device_flow(...),
					'permission_callback' => self::require_manage_options(...),
				],
			]
		);

		// Auth — disconnect & status.
		register_rest_route(
			namespace: self::NAMESPACE,
			route:     '/auth',
			args:      [
				[
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => self::disconnect(...),
					'permission_callback' => self::require_manage_options(...),
				],
			]
		);

		register_rest_route(
			namespace: self::NAMESPACE,
			route:     '/auth/status',
			args:      [
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => self::get_auth_status(...),
					'permission_callback' => self::require_manage_options(...),
				],
			]
		);
	}

	// -------------------------------------------------------------------------
	// Projects endpoints
	// -------------------------------------------------------------------------

	public static function get_projects( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$_rl_retry = Telex_Auth::check_rate_limit( 'get_projects' );
		if ( $_rl_retry > 0 ) {
			return new \WP_Error( 'telex_rate_limit', __( 'Too many requests. Please wait a moment.', 'telex' ), [ 'status' => 429, 'headers' => [ 'Retry-After' => (string) $_rl_retry ] ] );
		}

		$installed   = Telex_Tracker::get_all();
		$from_cache  = false;

		// Try stale-while-revalidate first — serves instantly and schedules background refresh.
		$stale_or_live = Telex_Cache::get_or_revalidate();
		if ( null !== $stale_or_live ) {
			$projects   = $stale_or_live;
			$from_cache = true;
		} else {
			// No cached data at all — must hit the API synchronously.
			$client = Telex_Auth::get_client();
			if ( ! $client ) {
				return new \WP_Error( 'telex_not_connected', __( 'Not connected to Telex.', 'telex' ), [ 'status' => 401 ] );
			}

			try {
				$response = $client->projects->list( [ 'perPage' => 100 ] );
				$projects = $response['projects'] ?? [];
				Telex_Cache::set_projects( $projects );
				Telex_Circuit_Breaker::record_success();
			} catch ( \Telex\Sdk\Exceptions\AuthenticationException ) {
				Telex_Circuit_Breaker::record_failure();
				Telex_Auth::disconnect();
				return new \WP_Error( 'telex_token_expired', __( 'Your token has expired. Please reconnect.', 'telex' ), [ 'status' => 401 ] );
			} catch ( \Exception $e ) {
				Telex_Circuit_Breaker::record_failure();
				return new \WP_Error( 'telex_api', $e->getMessage(), [ 'status' => 500 ] );
			}
		}

		// Decorate each project with local install status to eliminate a second round-trip.
		$projects = array_map( static function ( array $project ) use ( $installed ): array {
			$id             = $project['publicId'] ?? '';
			$remote_version = (int) ( $project['currentVersion'] ?? 0 );
			$local          = $installed[ $id ] ?? null;

			$project['_installed']    = null !== $local;
			$project['_needs_update'] = null !== $local && $remote_version > (int) $local['version'];
			$project['_local']        = $local;

			return $project;
		}, $projects );

		$body = [
			'projects'   => $projects,
			'installed'  => $installed,
			'total'      => count( $projects ),
			'from_cache' => $from_cache,
		];

		// ETag for conditional GET — allows clients to skip parsing unchanged responses.
		$etag             = '"' . md5( wp_json_encode( $body ) ) . '"';
		$if_none_match    = trim( $request->get_header( 'If-None-Match' ) ?? '' );

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
	 * Returns only locally tracked project data — no API call, instant response.
	 */
	public static function get_installed( \WP_REST_Request $request ): \WP_REST_Response {
		return rest_ensure_response( [
			'installed' => Telex_Tracker::get_all(),
		] );
	}

	public static function install_project( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$_rl_retry = Telex_Auth::check_rate_limit( 'install' );
		if ( $_rl_retry > 0 ) {
			return new \WP_Error( 'telex_rate_limit', __( 'Too many requests. Please wait a moment.', 'telex' ), [ 'status' => 429, 'headers' => [ 'Retry-After' => (string) $_rl_retry ] ] );
		}

		$public_id = $request->get_param( 'id' );
		$activate  = (bool) $request->get_param( 'activate' );

		$result = Telex_Installer::install( $public_id, $activate );

		if ( is_wp_error( $result ) ) {
			return new \WP_Error(
				$result->get_error_code(),
				$result->get_error_message(),
				[ 'status' => 500 ]
			);
		}

		return rest_ensure_response( [
			'success' => true,
			/* translators: %s: project public ID */
			'message' => sprintf( __( 'Project %s installed successfully.', 'telex' ), $public_id ),
		] );
	}

	public static function remove_project( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$_rl_retry = Telex_Auth::check_rate_limit( 'remove' );
		if ( $_rl_retry > 0 ) {
			return new \WP_Error( 'telex_rate_limit', __( 'Too many requests. Please wait a moment.', 'telex' ), [ 'status' => 429, 'headers' => [ 'Retry-After' => (string) $_rl_retry ] ] );
		}

		$public_id = $request->get_param( 'id' );
		$result    = Telex_Installer::remove( $public_id );

		if ( is_wp_error( $result ) ) {
			return new \WP_Error(
				$result->get_error_code(),
				$result->get_error_message(),
				[ 'status' => 500 ]
			);
		}

		return rest_ensure_response( [ 'success' => true ] );
	}

	// -------------------------------------------------------------------------
	// Auth / device flow endpoints
	// -------------------------------------------------------------------------

	public static function start_device_flow( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$_rl_retry = Telex_Auth::check_rate_limit( 'device_start' );
		if ( $_rl_retry > 0 ) {
			return new \WP_Error( 'telex_rate_limit', __( 'Too many requests. Please wait a moment.', 'telex' ), [ 'status' => 429, 'headers' => [ 'Retry-After' => (string) $_rl_retry ] ] );
		}

		$response = wp_remote_post(
			TELEX_DEVICE_AUTH_URL . '/code',
			[
				'headers' => [ 'Content-Type' => 'application/json' ],
				'body'    => '{}',
				'timeout' => 15,
			]
		);

		if ( is_wp_error( $response ) ) {
			return new \WP_Error( 'telex_network', $response->get_error_message(), [ 'status' => 502 ] );
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code || empty( $body['device_code'] ) ) {
			return new \WP_Error(
				'telex_device_start',
				$body['message'] ?? __( 'Failed to start device flow.', 'telex' ),
				[ 'status' => 500 ]
			);
		}

		set_transient( Telex_Auth::TRANSIENT_DEVICE, $body['device_code'], (int) $body['expires_in'] );

		return rest_ensure_response( [
			'user_code'                  => $body['user_code'],
			'verification_uri'           => $body['verification_uri'],
			'verification_uri_complete'  => $body['verification_uri_complete'],
			'expires_in'                 => $body['expires_in'],
			'interval'                   => $body['interval'] ?? 5,
		] );
	}

	public static function poll_device_flow( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$_rl_retry = Telex_Auth::check_rate_limit( 'device_poll' );
		if ( $_rl_retry > 0 ) {
			return new \WP_Error( 'telex_rate_limit', __( 'Too many requests. Please wait a moment.', 'telex' ), [ 'status' => 429, 'headers' => [ 'Retry-After' => (string) $_rl_retry ] ] );
		}

		$device_code = get_transient( Telex_Auth::TRANSIENT_DEVICE );
		if ( empty( $device_code ) ) {
			return new \WP_Error( 'telex_no_device_flow', __( 'No active device flow.', 'telex' ), [ 'status' => 400 ] );
		}

		$response = wp_remote_post(
			TELEX_DEVICE_AUTH_URL . '/token',
			[
				'headers' => [ 'Content-Type' => 'application/json' ],
				'body'    => wp_json_encode( [ 'device_code' => $device_code ] ),
				'timeout' => 15,
			]
		);

		if ( is_wp_error( $response ) ) {
			return new \WP_Error( 'telex_network', $response->get_error_message(), [ 'status' => 502 ] );
		}

		$http_code = (int) wp_remote_retrieve_response_code( $response );
		$body      = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 === $http_code && ! empty( $body['access_token'] ) ) {
			Telex_Auth::store_token( $body['access_token'] );
			delete_transient( Telex_Auth::TRANSIENT_DEVICE );
			Telex_Audit_Log::log( AuditAction::Connect );
			return rest_ensure_response( [ 'authorized' => true ] );
		}

		$error = $body['error'] ?? '';

		if ( 'authorization_pending' === $error ) {
			return rest_ensure_response( [ 'authorized' => false, 'status' => 'pending' ] );
		}

		// RFC 8628 §3.5: slow_down — client MUST increase interval by 5 seconds.
		if ( 'slow_down' === $error ) {
			$new_interval = ( $body['interval'] ?? 5 ) + 5;
			return rest_ensure_response( [
				'authorized' => false,
				'status'     => 'slow_down',
				'interval'   => $new_interval,
			] );
		}

		delete_transient( Telex_Auth::TRANSIENT_DEVICE );
		return new \WP_Error(
			'telex_device_' . $error,
			$body['error_description'] ?? $error ?: __( 'Device code expired or invalid.', 'telex' ),
			[ 'status' => 400 ]
		);
	}

	public static function cancel_device_flow( \WP_REST_Request $request ): \WP_REST_Response {
		delete_transient( Telex_Auth::TRANSIENT_DEVICE );
		return rest_ensure_response( [ 'cancelled' => true ] );
	}

	public static function disconnect( \WP_REST_Request $request ): \WP_REST_Response {
		Telex_Auth::disconnect();
		Telex_Audit_Log::log( AuditAction::Disconnect );
		return rest_ensure_response( [ 'disconnected' => true ] );
	}

	public static function get_auth_status( \WP_REST_Request $request ): \WP_REST_Response {
		return rest_ensure_response( [
			'status'       => Telex_Auth::get_status()->value,
			'is_connected' => Telex_Auth::is_connected(),
		] );
	}

	// -------------------------------------------------------------------------
	// Permission callbacks
	// -------------------------------------------------------------------------

	public static function require_manage_options(): bool|WP_Error {
		return current_user_can( 'manage_options' )
			? true
			: new \WP_Error( 'telex_unauthorized', __( 'You do not have permission to do this.', 'telex' ), [ 'status' => 403 ] );
	}

	public static function require_install_cap( \WP_REST_Request $request ): bool|\WP_Error {
		// We don't know the type until we fetch the project; gate on generic install_plugins.
		// The Installer itself re-checks with the correct cap per project type.
		return current_user_can( 'install_plugins' ) || current_user_can( 'install_themes' )
			? true
			: new \WP_Error( 'telex_unauthorized', __( 'You do not have permission to install projects.', 'telex' ), [ 'status' => 403 ] );
	}

	public static function require_remove_cap( \WP_REST_Request $request ): bool|\WP_Error {
		return current_user_can( 'delete_plugins' ) || current_user_can( 'delete_themes' )
			? true
			: new \WP_Error( 'telex_unauthorized', __( 'You do not have permission to remove projects.', 'telex' ), [ 'status' => 403 ] );
	}
}
