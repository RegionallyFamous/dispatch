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
 *   POST   /telex/v1/projects/(?P<id>[a-zA-Z0-9_-]+)/activate
 *   POST   /telex/v1/projects/(?P<id>[a-zA-Z0-9_-]+)/deactivate
 *   GET    /telex/v1/projects/(?P<id>[a-zA-Z0-9_-]+)/note
 *   PUT    /telex/v1/projects/(?P<id>[a-zA-Z0-9_-]+)/note
 *   POST   /telex/v1/projects/(?P<id>[a-zA-Z0-9_-]+)/deploy-network  (multisite)
 *   DELETE /telex/v1/projects/(?P<id>[a-zA-Z0-9_-]+)
 *   POST   /telex/v1/auth/device    (start device flow)
 *   GET    /telex/v1/auth/device    (poll device flow)
 *   DELETE /telex/v1/auth/device    (cancel device flow)
 *   DELETE /telex/v1/auth           (disconnect)
 *   GET    /telex/v1/auth/status    (connection status)
 *   POST   /telex/v1/circuit/reset  (circuit breaker reset)
 *   GET    /telex/v1/audit-log      (paginated audit log)
 *   GET    /telex/v1/sites          (multisite site list)
 *   POST   /telex/v1/deploy         (webhook auto-deploy, HMAC-signed)
 *   POST   /telex/v1/settings/deploy-secret  (regenerate deploy secret)
 */
class Telex_REST {

	private const NAMESPACE              = 'telex/v1';
	private const DEPLOY_SECRET_KEY      = 'dispatch_deploy_secret';
	private const DEPLOY_REPLAY_SECS     = 300; // 5-minute replay window for past timestamps.
	private const DEPLOY_CLOCK_SKEW_SECS = 30;  // Max future clock skew accepted.
	private const WEBHOOK_MAX_BODY_BYTES = 1 * 1024 * 1024; // 1 MB hard cap before HMAC.

	/**
	 * Build a 429 WP_Error with the Retry-After value encoded in both the
	 * HTTP header and the JSON body so JavaScript clients can read it without
	 * accessing raw response headers.
	 *
	 * @param int $retry_after Seconds until the client may retry.
	 * @return \WP_Error
	 */
	private static function rate_limit_error( int $retry_after ): \WP_Error {
		return new \WP_Error(
			'telex_rate_limit',
			__( 'Slow down! Give it a moment and try again.', 'dispatch' ),
			[
				'status'     => 429,
				'retryAfter' => $retry_after,
				'headers'    => [ 'Retry-After' => (string) $retry_after ],
			]
		);
	}

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

		// Deploy secret — read (GET) and regenerate (POST).
		register_rest_route(
			self::NAMESPACE,
			'/settings/deploy-secret',
			[
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ self::class, 'get_deploy_secret_endpoint' ],
					'permission_callback' => [ self::class, 'require_manage_options' ],
				],
				[
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => [ self::class, 'regenerate_deploy_secret' ],
					'permission_callback' => [ self::class, 'require_manage_options' ],
				],
			]
		);

		// Circuit breaker reset.
		register_rest_route(
			self::NAMESPACE,
			'/circuit/reset',
			[
				[
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => [ self::class, 'reset_circuit' ],
					'permission_callback' => [ self::class, 'require_manage_options' ],
				],
			]
		);

		// Audit log — paginated read for Activity tab.
		register_rest_route(
			self::NAMESPACE,
			'/audit-log',
			[
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ self::class, 'get_audit_log' ],
					'permission_callback' => [ self::class, 'require_manage_options' ],
					'args'                => [
						'per_page'   => [
							'type'    => 'integer',
							'default' => 25,
							'minimum' => 1,
							'maximum' => 100,
						],
						'page'       => [
							'type'    => 'integer',
							'default' => 1,
							'minimum' => 1,
						],
						'action'     => [
							'type'              => 'string',
							'default'           => '',
							'sanitize_callback' => 'sanitize_text_field',
						],
						'project_id' => [
							'type'              => 'string',
							'default'           => '',
							'sanitize_callback' => 'sanitize_text_field',
						],
						'search'     => [
							'type'              => 'string',
							'default'           => '',
							'sanitize_callback' => 'sanitize_text_field',
						],
						'date_from'  => [
							'type'              => 'string',
							'default'           => '',
							'sanitize_callback' => 'sanitize_text_field',
						],
						'date_to'    => [
							'type'              => 'string',
							'default'           => '',
							'sanitize_callback' => 'sanitize_text_field',
						],
						'user_id'    => [
							'type'    => 'integer',
							'default' => 0,
							'minimum' => 0,
						],
					],
				],
			]
		);

		// Activate / deactivate installed projects.
		register_rest_route(
			self::NAMESPACE,
			'/projects/(?P<id>[a-zA-Z0-9_\-]+)/activate',
			[
				[
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => [ self::class, 'activate_project' ],
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

		register_rest_route(
			self::NAMESPACE,
			'/projects/(?P<id>[a-zA-Z0-9_\-]+)/deactivate',
			[
				[
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => [ self::class, 'deactivate_project' ],
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

		// Per-project notes (stored in wp_options, no API call).
		register_rest_route(
			self::NAMESPACE,
			'/projects/(?P<id>[a-zA-Z0-9_\-]+)/note',
			[
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ self::class, 'get_project_note' ],
					'permission_callback' => [ self::class, 'require_manage_options' ],
					'args'                => [
						'id' => [
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_text_field',
						],
					],
				],
				[
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => [ self::class, 'update_project_note' ],
					'permission_callback' => [ self::class, 'require_manage_options' ],
					'args'                => [
						'id'   => [
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_text_field',
						],
						'note' => [
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_textarea_field',
						],
					],
				],
			]
		);

		// Multisite sites list (for network deploy selector).
		if ( is_multisite() ) {
			register_rest_route(
				self::NAMESPACE,
				'/sites',
				[
					[
						'methods'             => \WP_REST_Server::READABLE,
						'callback'            => [ self::class, 'get_sites_list' ],
						'permission_callback' => [ self::class, 'require_network_admin' ],
						'args'                => [
							'per_page' => [
								'type'    => 'integer',
								'default' => 25,
								'minimum' => 1,
								'maximum' => 100,
							],
							'page'     => [
								'type'    => 'integer',
								'default' => 1,
								'minimum' => 1,
							],
							'search'   => [
								'type'              => 'string',
								'default'           => '',
								'sanitize_callback' => 'sanitize_text_field',
							],
						],
					],
				]
			);
		}

		// Users list — for the Activity tab user-filter dropdown.
		register_rest_route(
			self::NAMESPACE,
			'/users',
			[
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ self::class, 'get_users_list' ],
					'permission_callback' => [ self::class, 'require_manage_options' ],
				],
			]
		);

		// Version pinning — lock a project at its current version.
		register_rest_route(
			self::NAMESPACE,
			'/projects/(?P<id>[a-zA-Z0-9_\-]+)/pin',
			[
				[
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => [ self::class, 'pin_project' ],
					'permission_callback' => [ self::class, 'require_manage_options' ],
					'args'                => [
						'id'     => [
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_text_field',
						],
						'reason' => [
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_text_field',
						],
					],
				],
				[
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => [ self::class, 'unpin_project' ],
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

		// Auto-update setting per project.
		register_rest_route(
			self::NAMESPACE,
			'/projects/(?P<id>[a-zA-Z0-9_\-]+)/auto-update',
			[
				[
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => [ self::class, 'set_auto_update' ],
					'permission_callback' => [ self::class, 'require_manage_options' ],
					'args'                => [
						'id'   => [
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_text_field',
						],
						'mode' => [
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_text_field',
							'enum'              => [ 'off', 'immediate', 'delayed_24h' ],
						],
					],
				],
			]
		);

		// Project groups (user-scoped collections).
		register_rest_route(
			self::NAMESPACE,
			'/groups',
			[
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ self::class, 'get_groups' ],
					'permission_callback' => [ self::class, 'require_manage_options' ],
				],
				[
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => [ self::class, 'create_group' ],
					'permission_callback' => [ self::class, 'require_manage_options' ],
					'args'                => [
						'name' => [
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_text_field',
						],
					],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/groups/(?P<id>[a-zA-Z0-9_\-]+)',
			[
				[
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => [ self::class, 'update_group' ],
					'permission_callback' => [ self::class, 'require_manage_options' ],
					'args'                => [
						'id'   => [
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_text_field',
						],
						'name' => [
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_text_field',
						],
					],
				],
				[
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => [ self::class, 'delete_group' ],
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

		register_rest_route(
			self::NAMESPACE,
			'/groups/(?P<id>[a-zA-Z0-9_\-]+)/projects',
			[
				[
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => [ self::class, 'add_project_to_group' ],
					'permission_callback' => [ self::class, 'require_manage_options' ],
					'args'                => [
						'id'         => [
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_text_field',
						],
						'project_id' => [
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_text_field',
						],
					],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/groups/(?P<id>[a-zA-Z0-9_\-]+)/projects/(?P<project_id>[a-zA-Z0-9_\-]+)',
			[
				[
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => [ self::class, 'remove_project_from_group' ],
					'permission_callback' => [ self::class, 'require_manage_options' ],
					'args'                => [
						'id'         => [
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_text_field',
						],
						'project_id' => [
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_text_field',
						],
					],
				],
			]
		);

		// Build snapshots.
		register_rest_route(
			self::NAMESPACE,
			'/snapshots',
			[
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ self::class, 'get_snapshots' ],
					'permission_callback' => [ self::class, 'require_manage_options' ],
				],
				[
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => [ self::class, 'create_snapshot' ],
					'permission_callback' => [ self::class, 'require_manage_options' ],
					'args'                => [
						'name' => [
							'type'              => 'string',
							'default'           => '',
							'sanitize_callback' => 'sanitize_text_field',
						],
					],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/snapshots/(?P<id>[a-zA-Z0-9_\-]+)',
			[
				[
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => [ self::class, 'delete_snapshot' ],
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

		register_rest_route(
			self::NAMESPACE,
			'/snapshots/(?P<id>[a-zA-Z0-9_\-]+)/restore',
			[
				[
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => [ self::class, 'restore_snapshot' ],
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

		// Notification channel settings.
		register_rest_route(
			self::NAMESPACE,
			'/settings/notifications',
			[
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ self::class, 'get_notification_settings' ],
					'permission_callback' => [ self::class, 'require_manage_options' ],
				],
				[
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => [ self::class, 'update_notification_settings' ],
					'permission_callback' => [ self::class, 'require_manage_options' ],
					'args'                => [
						'email_enabled'  => [
							'type'    => 'boolean',
							'default' => false,
						],
						'email_address'  => [
							'type'              => 'string',
							'default'           => '',
							'sanitize_callback' => 'sanitize_email',
						],
						'slack_enabled'  => [
							'type'    => 'boolean',
							'default' => false,
						],
						'slack_webhook'  => [
							'type'              => 'string',
							'default'           => '',
							'sanitize_callback' => 'sanitize_url',
						],
						'notify_updates' => [
							'type'    => 'boolean',
							'default' => true,
						],
						'notify_circuit' => [
							'type'    => 'boolean',
							'default' => true,
						],
						'notify_install' => [
							'type'    => 'boolean',
							'default' => false,
						],
					],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/settings/notifications/test',
			[
				[
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => [ self::class, 'test_notification' ],
					'permission_callback' => [ self::class, 'require_manage_options' ],
				],
			]
		);

		// Block usage analytics.
		register_rest_route(
			self::NAMESPACE,
			'/analytics',
			[
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ self::class, 'get_analytics' ],
					'permission_callback' => [ self::class, 'require_manage_options' ],
					'args'                => [
						'force_scan' => [
							'type'    => 'boolean',
							'default' => false,
						],
					],
				],
			]
		);

		// Installed project health checks.
		register_rest_route(
			self::NAMESPACE,
			'/health/installed',
			[
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ self::class, 'get_health' ],
					'permission_callback' => [ self::class, 'require_manage_options' ],
					'args'                => [
						'force_scan' => [
							'type'    => 'boolean',
							'default' => false,
						],
					],
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
			return self::rate_limit_error( $_rl_retry );
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

		// $client is set only in the sync-fetch branch; reused below to avoid a second
		// token decrypt when some installed projects still need a live version lookup.
		$client = null;

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
				wp_trigger_error( __CLASS__, 'Dispatch: projects list failed — ' . $e->getMessage(), E_USER_WARNING );
				return new \WP_Error( 'telex_api', __( 'Could not fetch projects. Please try again.', 'dispatch' ), [ 'status' => 500 ] );
			}
		}

		// The bulk list() API does not include currentVersion. For installed projects we
		// need the accurate version to detect updates. Resolution order:
		// 1. Per-project cache (seeded by Telex_Updater on every WP update-check).
		// 2. The bulk $projects array — handles future API versions that do expose it.
		// 3. Live per-project get() — only for projects missing from both caches above.
		// Decrypt the token only when at least one project actually needs a live call.
		$remote_versions = [];
		if ( ! empty( $installed ) ) {
			// Build a fast lookup from the bulk list in case currentVersion is present.
			$bulk_versions = [];
			foreach ( $projects as $p ) {
				$pid = $p['publicId'] ?? '';
				if ( '' !== $pid && isset( $p['currentVersion'] ) ) {
					$bulk_versions[ $pid ] = (int) $p['currentVersion'];
				}
			}

			// Resolve from cache / bulk list first; collect only the IDs that need a live call.
			$needs_live = [];
			foreach ( array_keys( $installed ) as $installed_id ) {
				// 1. Per-project cache.
				$cp = Telex_Cache::get_project( $installed_id );
				if ( is_array( $cp ) && isset( $cp['currentVersion'] ) ) {
					$remote_versions[ $installed_id ] = (int) $cp['currentVersion'];
					continue;
				}
				// 2. Bulk list.
				if ( isset( $bulk_versions[ $installed_id ] ) ) {
					$remote_versions[ $installed_id ] = $bulk_versions[ $installed_id ];
					continue;
				}
				$needs_live[] = $installed_id;
			}

			if ( ! empty( $needs_live ) ) {
				// Reuse the client from the sync-fetch branch when available; otherwise
				// decrypt the token now — this is the only path that requires it.
				$vc = $client ?? Telex_Auth::get_client();
				if ( $vc ) {
					foreach ( $needs_live as $installed_id ) {
						// 3. Live API call.
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

		// Pre-load get_plugins() once for all projects to avoid N calls inside the map.
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$all_plugins    = get_plugins();
		$active_plugins = (array) get_option( 'active_plugins', [] );

		// Decorate each project with local install status.
		$projects = array_map(
			/**
			 * Decorates a project record with local install and activation state.
			 *
			 * @param array<string, mixed> $project Raw project from the Telex API.
			 * @return array<string, mixed>
			 */
			static function ( array $project ) use ( $installed, $remote_versions, $all_plugins, $active_plugins ): array {
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

				// Determine activation state for installed projects.
				$is_active = false;
				if ( null !== $local ) {
					$slug = $local['slug'] ?? '';
					$type = $local['type'] ?? 'block';

					if ( 'theme' === $type ) {
						$is_active = ( get_stylesheet() === $slug );
					} else {
						// Find the plugin file for this slug.
						foreach ( $all_plugins as $plugin_file => $_data ) {
							if ( str_starts_with( $plugin_file, $slug . '/' ) ) {
								$is_active = in_array( $plugin_file, $active_plugins, true );
								break;
							}
						}
					}
				}

				$project['_is_active']   = $is_active;
				$pin                     = Telex_Version_Pin::get( $id );
				$project['_pin']         = $pin;
				$project['_auto_update'] = Telex_Auto_Update::get_mode( $id );
				$project['_group_ids']   = Telex_Project_Groups::get_group_ids_for_project( $id );

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
		// SHA-256 over the payload — MD5 is sufficient for ETags but SHA-256 is
		// consistent with the rest of the codebase and avoids MD5 collision concerns.
		$json_payload  = wp_json_encode( [ $projects, $installed ] );
		$etag          = '"' . hash( 'sha256', false !== $json_payload ? $json_payload : '' ) . '"';
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
			return self::rate_limit_error( $_rl_retry );
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
			wp_trigger_error( __CLASS__, 'Dispatch: getBuild failed — ' . $e->getMessage(), E_USER_WARNING );
			return new \WP_Error( 'telex_api', __( 'Could not retrieve build information. Please try again.', 'dispatch' ), [ 'status' => 502 ] );
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

		// --- Conflict detection -----------------------------------------------
		// Check whether a plugin or theme with the same slug is already present
		// but was NOT installed via Dispatch. Silently overwriting an unmanaged
		// plugin could break the site or introduce a security regression.
		try {
			$project_data = $client->projects->get( $public_id );
			$slug         = $project_data['slug'] ?? '';
			$type         = $project_data['projectType'] ?? 'block';
		} catch ( \Exception $e ) {
			wp_trigger_error( __CLASS__, 'Dispatch: project get for conflict check failed — ' . $e->getMessage(), E_USER_WARNING );
			$slug = '';
			$type = 'block';
		}

		if ( '' !== $slug && ! Telex_Tracker::is_installed( $public_id ) ) {
			$conflict      = null;
			$conflict_type = null;

			if ( 'theme' === $type ) {
				$theme = wp_get_theme( $slug );
				if ( $theme->exists() ) {
					$conflict      = $theme->get( 'Name' );
					$conflict_type = 'theme';
				}
			} else {
				if ( ! function_exists( 'get_plugins' ) ) {
					require_once ABSPATH . 'wp-admin/includes/plugin.php';
				}
				foreach ( get_plugins() as $plugin_file => $plugin_data ) {
					if ( str_starts_with( $plugin_file, $slug . '/' ) ) {
						$conflict      = $plugin_data['Name'] ?? $plugin_file;
						$conflict_type = 'plugin';
						break;
					}
				}
			}

			if ( null !== $conflict ) {
				return new \WP_Error(
					'slug_conflict',
					sprintf(
						/* translators: 1: project slug, 2: existing plugin/theme name */
						__( 'A %1$s named "%2$s" with the same slug is already installed from another source. Remove it first to avoid conflicts.', 'dispatch' ),
						$conflict_type,
						$conflict
					),
					[
						'status'        => 409,
						'conflict_name' => $conflict,
						'conflict_type' => $conflict_type,
						'conflict_slug' => $slug,
					]
				);
			}
		}

		// Pass the already-fetched build data to the installer to avoid a second
		// getBuild() round-trip. A duplicate call can race with the build-readiness
		// state and incorrectly report "not ready" immediately after confirmation.
		$result = Telex_Installer::install( $public_id, $activate, $build );

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
			wp_trigger_error( __CLASS__, 'Dispatch: getBuild status failed — ' . $e->getMessage(), E_USER_WARNING );
			return new \WP_Error( 'telex_api', __( 'Could not retrieve build status. Please try again.', 'dispatch' ), [ 'status' => 502 ] );
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
			return self::rate_limit_error( $_rl_retry );
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

		self::bump_data_version();

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
			return self::rate_limit_error( $_rl_retry );
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
			return self::rate_limit_error( $_rl_retry );
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
		$circuit_status   = Telex_Circuit_Breaker::status();
		$circuit_reset_at = null;

		// Surface the circuit reset timestamp when the breaker is open so the UI
		// can display a countdown without requiring the client to guess the window.
		if ( 'open' === $circuit_status ) {
			$reset_transient  = get_transient( 'telex_cb_reset_at' );
			$circuit_reset_at = false !== $reset_transient ? (int) $reset_transient : null;
		}

		return rest_ensure_response(
			[
				'status'           => Telex_Auth::get_status()->value,
				'is_connected'     => Telex_Auth::is_connected(),
				'circuit_status'   => $circuit_status,
				'circuit_reset_at' => $circuit_reset_at,
			]
		);
	}

	// -------------------------------------------------------------------------
	// Circuit breaker
	// -------------------------------------------------------------------------

	/**
	 * POST /telex/v1/circuit/reset — manually resets the circuit breaker.
	 *
	 * @param \WP_REST_Request $request The incoming REST request.
	 * @return \WP_REST_Response
	 */
	public static function reset_circuit( \WP_REST_Request $request ): \WP_REST_Response { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		Telex_Circuit_Breaker::reset();
		return rest_ensure_response(
			[
				'success'        => true,
				'circuit_status' => Telex_Circuit_Breaker::status(),
			]
		);
	}

	// -------------------------------------------------------------------------
	// Audit log
	// -------------------------------------------------------------------------

	/**
	 * GET /telex/v1/audit-log — returns paginated audit log entries with user names resolved.
	 *
	 * @param \WP_REST_Request $request The incoming REST request.
	 * @return \WP_REST_Response
	 */
	public static function get_audit_log( \WP_REST_Request $request ): \WP_REST_Response {
		global $wpdb;

		$per_page   = (int) $request->get_param( 'per_page' );
		$page       = (int) $request->get_param( 'page' );
		$action     = (string) $request->get_param( 'action' );
		$project_id = (string) $request->get_param( 'project_id' );
		$search     = (string) $request->get_param( 'search' );
		$date_from  = (string) $request->get_param( 'date_from' );
		$date_to    = (string) $request->get_param( 'date_to' );
		$user_id    = (int) $request->get_param( 'user_id' );
		$offset     = ( $page - 1 ) * $per_page;
		$table      = Telex_Audit_Log::table_name();

		// Build optional WHERE clauses (using an allowlist — never raw user input in SQL).
		$valid_actions = [ 'install', 'update', 'remove', 'connect', 'disconnect', 'activate', 'deactivate', 'auto_update' ];
		$where_parts   = [];
		$where_values  = [];

		if ( '' !== $action && in_array( strtolower( $action ), $valid_actions, true ) ) {
			$where_parts[]  = 'action = %s';
			$where_values[] = strtolower( $action );
		}

		if ( '' !== $project_id ) {
			$where_parts[]  = 'public_id = %s';
			$where_values[] = $project_id;
		}

		if ( '' !== $search ) {
			$where_parts[]  = '(public_id LIKE %s OR context LIKE %s)';
			$like           = '%' . $wpdb->esc_like( $search ) . '%';
			$where_values[] = $like;
			$where_values[] = $like;
		}

		if ( '' !== $date_from ) {
			$ts = strtotime( $date_from );
			if ( false !== $ts ) {
				$where_parts[]  = 'created_at >= %s';
				$where_values[] = gmdate( 'Y-m-d 00:00:00', $ts );
			}
		}

		if ( '' !== $date_to ) {
			$ts = strtotime( $date_to );
			if ( false !== $ts ) {
				$where_parts[]  = 'created_at <= %s';
				$where_values[] = gmdate( 'Y-m-d 23:59:59', $ts );
			}
		}

		if ( $user_id > 0 ) {
			$where_parts[]  = 'user_id = %d';
			$where_values[] = $user_id;
		}

		$where_sql = '';
		if ( ! empty( $where_parts ) ) {
			$where_sql = 'WHERE ' . implode( ' AND ', $where_parts );
		}

		// $table is derived from $wpdb->prefix, which is trusted. $where_sql is
		// built exclusively from an allowlist of column names and %s/%d placeholders
		// (never from user input), so interpolation here is safe.
		//
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		if ( '' !== $where_sql ) {
			$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} {$where_sql}", ...$where_values ) );
		} else {
			$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		}

		// Fetch page of rows.
		$limit_values = array_merge( $where_values, [ $per_page, $offset ] );
		$rows         = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} {$where_sql} ORDER BY id DESC LIMIT %d OFFSET %d", ...$limit_values ),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		// phpcs:enable WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery

		$rows = is_array( $rows ) ? $rows : [];

		// Batch-resolve user display names to avoid N+1 get_userdata() calls.
		$user_ids  = array_unique(
			array_filter( array_column( $rows, 'user_id' ), static fn( $id ) => (int) $id > 0 )
		);
		$users_map = [];
		if ( ! empty( $user_ids ) ) {
			$user_objects = get_users(
				[
					'include' => array_map( 'intval', $user_ids ),
					'fields'  => [ 'ID', 'display_name' ],
				]
			);
			foreach ( $user_objects as $u ) {
				$users_map[ (int) $u->ID ] = $u->display_name;
			}
		}

		// Annotate each row with the resolved display name.
		$rows = array_map(
			static function ( array $row ) use ( $users_map ): array {
				$uid               = (int) ( $row['user_id'] ?? 0 );
				$row['_user_name'] = $uid > 0
					? ( $users_map[ $uid ] ?? sprintf( '#%d', $uid ) )
					: __( '(system)', 'dispatch' );
				return $row;
			},
			$rows
		);

		$response = rest_ensure_response(
			[
				'items'       => $rows,
				'total'       => $total,
				'total_pages' => (int) ceil( $total / $per_page ),
				'page'        => $page,
				'per_page'    => $per_page,
			]
		);

		return $response;
	}

	// -------------------------------------------------------------------------
	// Activation / deactivation
	// -------------------------------------------------------------------------

	/**
	 * POST /telex/v1/projects/{id}/activate — activates an installed plugin/theme.
	 *
	 * @param \WP_REST_Request $request The incoming REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function activate_project( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$public_id = sanitize_text_field( (string) $request->get_param( 'id' ) );
		$tracked   = Telex_Tracker::get( $public_id );

		if ( ! $tracked ) {
			return new \WP_Error( 'telex_not_installed', __( "This project isn't installed on your site.", 'dispatch' ), [ 'status' => 404 ] );
		}

		$type = $tracked['type'] ?? 'block';
		$slug = $tracked['slug'] ?? '';

		if ( 'theme' === $type ) {
			switch_theme( $slug );
			Telex_Audit_Log::log(
				AuditAction::Activate,
				$public_id,
				[
					'slug' => $slug,
					'type' => 'theme',
				]
			);
			return rest_ensure_response( [ 'activated' => true ] );
		}

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$plugin_file = '';
		foreach ( get_plugins() as $file => $_data ) {
			if ( str_starts_with( $file, $slug . '/' ) ) {
				$plugin_file = $file;
				break;
			}
		}

		if ( ! $plugin_file ) {
			return new \WP_Error( 'telex_not_installed', __( 'Plugin file not found on disk.', 'dispatch' ), [ 'status' => 404 ] );
		}

		$result = activate_plugin( $plugin_file );
		if ( is_wp_error( $result ) ) {
			return new \WP_Error( 'telex_activate', $result->get_error_message(), [ 'status' => 500 ] );
		}

		Telex_Audit_Log::log(
			AuditAction::Activate,
			$public_id,
			[
				'slug' => $slug,
				'type' => 'block',
			]
		);
		return rest_ensure_response( [ 'activated' => true ] );
	}

	/**
	 * POST /telex/v1/projects/{id}/deactivate — deactivates an installed plugin.
	 *
	 * Themes cannot be deactivated without switching to another theme; this
	 * endpoint intentionally returns a 400 for theme-type projects.
	 *
	 * @param \WP_REST_Request $request The incoming REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function deactivate_project( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$public_id = sanitize_text_field( (string) $request->get_param( 'id' ) );
		$tracked   = Telex_Tracker::get( $public_id );

		if ( ! $tracked ) {
			return new \WP_Error( 'telex_not_installed', __( "This project isn't installed on your site.", 'dispatch' ), [ 'status' => 404 ] );
		}

		$type = $tracked['type'] ?? 'block';
		$slug = $tracked['slug'] ?? '';

		if ( 'theme' === $type ) {
			return new \WP_Error(
				'telex_theme_deactivate',
				__( "Themes can't be deactivated — switch to a different theme instead.", 'dispatch' ),
				[ 'status' => 400 ]
			);
		}

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$plugin_file = '';
		foreach ( get_plugins() as $file => $_data ) {
			if ( str_starts_with( $file, $slug . '/' ) ) {
				$plugin_file = $file;
				break;
			}
		}

		if ( $plugin_file ) {
			deactivate_plugins( $plugin_file );
		}

		Telex_Audit_Log::log(
			AuditAction::Deactivate,
			$public_id,
			[
				'slug' => $slug,
				'type' => 'block',
			]
		);
		return rest_ensure_response( [ 'deactivated' => true ] );
	}

	// -------------------------------------------------------------------------
	// Project notes
	// -------------------------------------------------------------------------

	/**
	 * GET /telex/v1/projects/{id}/note — returns the stored note for a project.
	 *
	 * @param \WP_REST_Request $request The incoming REST request.
	 * @return \WP_REST_Response
	 */
	public static function get_project_note( \WP_REST_Request $request ): \WP_REST_Response {
		$public_id = sanitize_text_field( (string) $request->get_param( 'id' ) );
		$notes     = (array) get_option( 'telex_project_notes', [] );
		$note      = (string) ( $notes[ $public_id ] ?? '' );

		return rest_ensure_response( [ 'note' => $note ] );
	}

	/**
	 * PUT /telex/v1/projects/{id}/note — saves or clears a per-project note.
	 *
	 * @param \WP_REST_Request $request The incoming REST request.
	 * @return \WP_REST_Response
	 */
	public static function update_project_note( \WP_REST_Request $request ): \WP_REST_Response {
		$public_id = sanitize_text_field( (string) $request->get_param( 'id' ) );
		$note      = sanitize_textarea_field( (string) $request->get_param( 'note' ) );

		$notes               = (array) get_option( 'telex_project_notes', [] );
		$notes[ $public_id ] = $note;

		if ( '' === $note ) {
			unset( $notes[ $public_id ] );
		}

		update_option( 'telex_project_notes', $notes, false );

		return rest_ensure_response( [ 'note' => $note ] );
	}

	// -------------------------------------------------------------------------
	// Sites list (multisite)
	// -------------------------------------------------------------------------

	/**
	 * GET /telex/v1/sites — returns a paginated list of subsites for the network deploy selector.
	 *
	 * @param \WP_REST_Request $request The incoming REST request.
	 * @return \WP_REST_Response
	 */
	public static function get_sites_list( \WP_REST_Request $request ): \WP_REST_Response {
		$per_page = (int) $request->get_param( 'per_page' );
		$page     = (int) $request->get_param( 'page' );
		$search   = (string) $request->get_param( 'search' );
		$offset   = ( $page - 1 ) * $per_page;

		$query_args = [
			'number'   => $per_page,
			'offset'   => $offset,
			'deleted'  => 0,
			'spam'     => 0,
			'archived' => 0,
		];

		if ( '' !== $search ) {
			$query_args['search'] = '*' . $search . '*';
		}

		$sites = get_sites( $query_args );
		$total = (int) get_sites( array_merge( $query_args, [ 'count' => true ] ) );

		$items = array_map(
			static function ( $site ): array {
				return [
					'id'       => (int) $site->blog_id,
					'domain'   => $site->domain,
					'path'     => $site->path,
					'blogname' => get_blog_option( (int) $site->blog_id, 'blogname' ),
				];
			},
			is_array( $sites ) ? $sites : []
		);

		return rest_ensure_response(
			[
				'items'       => $items,
				'total'       => $total,
				'total_pages' => (int) ceil( $total / $per_page ),
				'page'        => $page,
				'per_page'    => $per_page,
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
			wp_trigger_error( __CLASS__, 'Dispatch: project get failed — ' . $e->getMessage(), E_USER_WARNING );
			return new \WP_Error( 'telex_api', __( 'Could not retrieve project details. Please try again.', 'dispatch' ), [ 'status' => 502 ] );
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

		// Fetch the build once before the site loop so every subsite gets the
		// same already-validated build data without N extra getBuild() API calls.
		$client = Telex_Auth::get_client();
		if ( ! $client ) {
			return new \WP_Error( 'telex_not_connected', __( "You're not connected. Head to Dispatch to link your account.", 'dispatch' ), [ 'status' => 401 ] );
		}

		try {
			$pre_fetched_build = $client->projects->getBuild( $public_id );
		} catch ( \Exception $e ) {
			wp_trigger_error( __CLASS__, 'Dispatch: network deploy getBuild failed — ' . $e->getMessage(), E_USER_WARNING );
			return new \WP_Error( 'telex_api', __( 'Could not retrieve build information. Please try again.', 'dispatch' ), [ 'status' => 502 ] );
		}

		// Paginate through all sites — the hard cap of 200 silently skips sites
		// on large networks, so we loop until get_sites() returns fewer than $batch.
		$succeeded = [];
		$failed    = [];
		$offset    = 0;
		$batch     = 100;
		$base_args = [
			'public'   => 1,
			'deleted'  => 0,
			'spam'     => 0,
			'archived' => 0,
		];

		do {
			$sites = get_sites(
				array_merge(
					$base_args,
					[
						'number' => $batch,
						'offset' => $offset,
					]
				)
			);

			foreach ( $sites as $site ) {
				switch_to_blog( (int) $site->blog_id );

				// try/finally guarantees restore_current_blog() runs even if
				// an unexpected exception escapes Telex_Installer::install().
				try {
					$result = Telex_Installer::install( $public_id, false, $pre_fetched_build );

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
				} finally {
					restore_current_blog();
				}
			}

			$offset     += $batch;
			$sites_count = count( $sites );
		} while ( $sites_count === $batch );

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
	 * GET /telex/v1/settings/deploy-secret — returns the current deploy secret.
	 *
	 * The secret is never embedded in page HTML; it is only served over this
	 * authenticated endpoint so it cannot be exfiltrated via XSS or devtools.
	 *
	 * @param \WP_REST_Request $request The incoming REST request.
	 * @return \WP_REST_Response
	 */
	public static function get_deploy_secret_endpoint( \WP_REST_Request $request ): \WP_REST_Response { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		return rest_ensure_response( [ 'secret' => self::get_deploy_secret() ] );
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
		// --- Per-IP rate limiting ---------------------------------------------
		// Authenticated endpoints use per-user limits; this public endpoint uses
		// a per-IP counter so a single source cannot flood the install queue.
		$_wh_retry = self::check_webhook_rate_limit();
		if ( $_wh_retry > 0 ) {
			return new \WP_Error(
				'telex_rate_limit',
				'Too many webhook requests from this address. Please wait.',
				[
					'status'  => 429,
					'headers' => [ 'Retry-After' => (string) $_wh_retry ],
				]
			);
		}

		// --- Content-Type guard -----------------------------------------------
		// All webhook params must live in the JSON body so the HMAC covers them.
		// Rejecting non-JSON prevents a class of bypass where params are supplied
		// via query string while the body (and therefore the HMAC) is empty.
		$ct = $request->get_content_type();
		if ( empty( $ct['value'] ) || 'application/json' !== $ct['value'] ) {
			return new \WP_Error( 'telex_bad_content_type', 'Content-Type must be application/json.', [ 'status' => 415 ] );
		}

		// --- Body size guard --------------------------------------------------
		// Enforce before HMAC to prevent a large-body memory-exhaustion attack.
		// WordPress's post_max_size is a PHP-level limit but varies per host;
		// this cap is unconditional regardless of server configuration.
		$body = $request->get_body();
		if ( strlen( $body ) > self::WEBHOOK_MAX_BODY_BYTES ) {
			return new \WP_Error( 'telex_body_too_large', 'Request body exceeds maximum allowed size.', [ 'status' => 413 ] );
		}

		// --- Signature verification -------------------------------------------
		$sig_header = $request->get_header( 'X-Telex-Signature' );
		if ( ! $sig_header || ! str_starts_with( $sig_header, 'sha256=' ) ) {
			return new \WP_Error( 'telex_no_signature', 'Missing or malformed X-Telex-Signature header.', [ 'status' => 401 ] );
		}

		$provided_sig = substr( $sig_header, 7 );
		$secret       = self::get_deploy_secret();
		$expected_sig = hash_hmac( 'sha256', $body, $secret );

		if ( ! hash_equals( $expected_sig, $provided_sig ) ) {
			return new \WP_Error( 'telex_bad_signature', 'Signature verification failed.', [ 'status' => 401 ] );
		}

		// --- Replay protection ------------------------------------------------
		// timestamp is required — a missing field would otherwise allow
		// indefinite replay of any HMAC-valid request without a timestamp.
		$timestamp = (int) $request->get_param( 'timestamp' );
		if ( 0 === $timestamp ) {
			return new \WP_Error( 'telex_replay', 'timestamp is required.', [ 'status' => 400 ] );
		}

		// Directional window: allow DEPLOY_CLOCK_SKEW_SECS of future drift
		// (clock skew) but reject anything more than DEPLOY_REPLAY_SECS old.
		// Using abs() would silently accept pre-signed requests timestamped
		// up to 5 minutes in the future — an unnecessary attack surface.
		$age = time() - $timestamp;
		if ( $age < -self::DEPLOY_CLOCK_SKEW_SECS || $age > self::DEPLOY_REPLAY_SECS ) {
			return new \WP_Error( 'telex_replay', 'Request timestamp is outside the acceptable range.', [ 'status' => 400 ] );
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

		self::bump_data_version();

		return rest_ensure_response(
			[
				'success'    => true,
				'project_id' => $public_id,
			]
		);
	}

	/**
	 * Per-IP rate limiter for the public webhook endpoint.
	 *
	 * Uses a transient keyed on a hashed, normalised IP address. Allows up to
	 * 10 webhook triggers per minute from any single address.
	 *
	 * @return int Seconds until the rate-limit window resets, or 0 if under the limit.
	 */
	private static function check_webhook_rate_limit(): int {
		$window = 60;
		$max    = 10;

		// Normalise IPv6 addresses before hashing to prevent bypass via
		// equivalent representations (e.g. "::1" vs "0:0:0:0:0:0:0:1").
		// The raw address is never stored — only its SHA-256 digest.
		$raw_ip = (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- hashed immediately; never stored raw.
		$ip_key = 'telex_wh_rl_' . substr( hash( 'sha256', self::normalise_ip( $raw_ip ) ), 0, 32 );
		$count  = (int) get_transient( $ip_key );
		if ( $count >= $max ) {
			return $window;
		}
		set_transient( $ip_key, $count + 1, $window );
		return 0;
	}

	/**
	 * Returns a canonical string representation of an IPv4 or IPv6 address.
	 *
	 * Uses inet_pton / inet_ntop to collapse equivalent IPv6 forms such as
	 * "::0001" and "0:0:0:0:0:0:0:1" to the same canonical string "::1",
	 * ensuring they map to the same rate-limit bucket.
	 *
	 * @param string $ip Raw IP address string (typically from $_SERVER['REMOTE_ADDR']).
	 * @return string Canonical IP string, or the original value if parsing fails.
	 */
	private static function normalise_ip( string $ip ): string {
		if ( '' === $ip ) {
			return $ip;
		}
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- inet_pton triggers E_WARNING on invalid input.
		$packed = @inet_pton( $ip );
		if ( false === $packed ) {
			return $ip;
		}
		$canonical = inet_ntop( $packed );
		return false !== $canonical ? $canonical : $ip;
	}

	/**
	 * Maps a Telex WP_Error code to an appropriate HTTP status code.
	 *
	 * Installer and auth methods return WP_Error codes that indicate client-side
	 * problems (bad build state, capability failures, API unavailability). Sending
	 * all of these as 500 is incorrect and masks the real cause from the frontend.
	 *
	 * @param string|int $code The WP_Error code (WP_Error::get_error_code() returns string|int).
	 * @return int HTTP status code.
	 */
	private static function http_status_for_error( string|int $code ): int {
		return match ( (string) $code ) {
			'telex_not_connected'  => 401,
			'telex_caps'           => 403,
			'telex_forbidden'      => 403,
			'telex_not_installed'  => 404,
			'telex_active_theme',
			'slug_conflict',
			'install_in_progress',
			'remove_in_progress'   => 409,
			'telex_not_ready'      => 503,
			'disallow_file_mods'   => 403,
			'telex_checksum',
			'telex_integrity',
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

	// -------------------------------------------------------------------------
	// Users list
	// -------------------------------------------------------------------------

	/**
	 * GET /telex/v1/users — list WP users for the Activity tab user-filter dropdown.
	 *
	 * @return \WP_REST_Response
	 */
	public static function get_users_list(): \WP_REST_Response {
		$users = get_users(
			[
				'fields'  => [ 'ID', 'display_name' ],
				'orderby' => 'display_name',
				'order'   => 'ASC',
				'number'  => 200,
			]
		);

		$data = array_map(
			static fn( object $u ) => [
				'id'   => (int) $u->ID,
				'name' => (string) $u->display_name,
			],
			$users
		);

		return rest_ensure_response( $data );
	}

	// -------------------------------------------------------------------------
	// Version pinning
	// -------------------------------------------------------------------------

	/**
	 * POST /telex/v1/projects/{id}/pin — pin a project at its currently installed version.
	 *
	 * @param \WP_REST_Request $request The incoming REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function pin_project( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$public_id = sanitize_text_field( (string) $request->get_param( 'id' ) );
		$reason    = sanitize_text_field( (string) $request->get_param( 'reason' ) );

		if ( '' === $reason ) {
			return new \WP_Error( 'telex_missing_reason', __( 'A reason is required when pinning a project.', 'dispatch' ), [ 'status' => 400 ] );
		}

		$installed = Telex_Tracker::get( $public_id );
		if ( null === $installed ) {
			return new \WP_Error( 'telex_not_installed', __( 'That project is not installed.', 'dispatch' ), [ 'status' => 404 ] );
		}

		$version = (int) $installed['version'];
		Telex_Version_Pin::pin( $public_id, $version, $reason );

		// A pinned project cannot also be set to auto-update.
		Telex_Auto_Update::set_mode( $public_id, 'off' );

		self::bump_data_version();

		return rest_ensure_response(
			[
				'pinned'    => true,
				'public_id' => $public_id,
				'version'   => $version,
				'reason'    => $reason,
			]
		);
	}

	/**
	 * DELETE /telex/v1/projects/{id}/pin — unpin a project to re-enable updates.
	 *
	 * @param \WP_REST_Request $request The incoming REST request.
	 * @return \WP_REST_Response
	 */
	public static function unpin_project( \WP_REST_Request $request ): \WP_REST_Response {
		$public_id = sanitize_text_field( (string) $request->get_param( 'id' ) );

		Telex_Version_Pin::unpin( $public_id );
		self::bump_data_version();

		return rest_ensure_response(
			[
				'pinned'    => false,
				'public_id' => $public_id,
			]
		);
	}

	// -------------------------------------------------------------------------
	// Auto-update setting
	// -------------------------------------------------------------------------

	/**
	 * PUT /telex/v1/projects/{id}/auto-update — set per-project auto-update mode.
	 *
	 * @param \WP_REST_Request $request The incoming REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function set_auto_update( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$public_id = sanitize_text_field( (string) $request->get_param( 'id' ) );
		$mode      = sanitize_text_field( (string) $request->get_param( 'mode' ) );

		// Cannot enable auto-update on a pinned project.
		if ( 'off' !== $mode && Telex_Version_Pin::is_pinned( $public_id ) ) {
			return new \WP_Error(
				'telex_pinned',
				__( 'Unpin this project before enabling auto-updates.', 'dispatch' ),
				[ 'status' => 409 ]
			);
		}

		Telex_Auto_Update::set_mode( $public_id, $mode );
		self::bump_data_version();

		return rest_ensure_response(
			[
				'public_id' => $public_id,
				'mode'      => $mode,
			]
		);
	}

	// -------------------------------------------------------------------------
	// Project groups
	// -------------------------------------------------------------------------

	/**
	 * GET /telex/v1/groups — list the current user's project groups.
	 *
	 * @return \WP_REST_Response
	 */
	public static function get_groups(): \WP_REST_Response {
		return rest_ensure_response( Telex_Project_Groups::get_for_user() );
	}

	/**
	 * POST /telex/v1/groups — create a new project group.
	 *
	 * @param \WP_REST_Request $request The incoming REST request.
	 * @return \WP_REST_Response
	 */
	public static function create_group( \WP_REST_Request $request ): \WP_REST_Response {
		$name  = sanitize_text_field( (string) $request->get_param( 'name' ) );
		$group = Telex_Project_Groups::create( $name );
		return rest_ensure_response( $group );
	}

	/**
	 * PUT /telex/v1/groups/{id} — rename a project group.
	 *
	 * @param \WP_REST_Request $request The incoming REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function update_group( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$id   = sanitize_text_field( (string) $request->get_param( 'id' ) );
		$name = sanitize_text_field( (string) $request->get_param( 'name' ) );

		$group = Telex_Project_Groups::update( $id, $name );
		if ( null === $group ) {
			return new \WP_Error( 'telex_not_found', __( 'Group not found.', 'dispatch' ), [ 'status' => 404 ] );
		}
		return rest_ensure_response( $group );
	}

	/**
	 * DELETE /telex/v1/groups/{id} — delete a project group.
	 *
	 * @param \WP_REST_Request $request The incoming REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function delete_group( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$id = sanitize_text_field( (string) $request->get_param( 'id' ) );

		if ( ! Telex_Project_Groups::delete( $id ) ) {
			return new \WP_Error( 'telex_not_found', __( 'Group not found.', 'dispatch' ), [ 'status' => 404 ] );
		}
		return rest_ensure_response( [ 'deleted' => true ] );
	}

	/**
	 * POST /telex/v1/groups/{id}/projects — add a project to a group.
	 *
	 * @param \WP_REST_Request $request The incoming REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function add_project_to_group( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$id         = sanitize_text_field( (string) $request->get_param( 'id' ) );
		$project_id = sanitize_text_field( (string) $request->get_param( 'project_id' ) );

		$group = Telex_Project_Groups::add_project( $id, $project_id );
		if ( null === $group ) {
			return new \WP_Error( 'telex_not_found', __( 'Group not found.', 'dispatch' ), [ 'status' => 404 ] );
		}
		return rest_ensure_response( $group );
	}

	/**
	 * DELETE /telex/v1/groups/{id}/projects/{project_id} — remove a project from a group.
	 *
	 * @param \WP_REST_Request $request The incoming REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function remove_project_from_group( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$id         = sanitize_text_field( (string) $request->get_param( 'id' ) );
		$project_id = sanitize_text_field( (string) $request->get_param( 'project_id' ) );

		$group = Telex_Project_Groups::remove_project( $id, $project_id );
		if ( null === $group ) {
			return new \WP_Error( 'telex_not_found', __( 'Group not found.', 'dispatch' ), [ 'status' => 404 ] );
		}
		return rest_ensure_response( $group );
	}

	// -------------------------------------------------------------------------
	// Build snapshots
	// -------------------------------------------------------------------------

	/**
	 * GET /telex/v1/snapshots — list all saved snapshots.
	 *
	 * @return \WP_REST_Response
	 */
	public static function get_snapshots(): \WP_REST_Response {
		return rest_ensure_response( Telex_Snapshot::get_all() );
	}

	/**
	 * POST /telex/v1/snapshots — capture a new named snapshot.
	 *
	 * @param \WP_REST_Request $request The incoming REST request.
	 * @return \WP_REST_Response
	 */
	public static function create_snapshot( \WP_REST_Request $request ): \WP_REST_Response {
		$name      = sanitize_text_field( (string) $request->get_param( 'name' ) );
		$name      = '' !== $name ? $name : gmdate( 'Y-m-d H:i:s' ) . ' snapshot';
		$installed = Telex_Tracker::get_all();
		$projects  = [];
		foreach ( $installed as $id => $info ) {
			$projects[] = [
				'publicId' => $id,
				'version'  => (int) $info['version'],
				'slug'     => (string) ( $info['slug'] ?? $id ),
			];
		}
		$snapshot_id = Telex_Snapshot::create( $name, $projects );
		$snapshot    = Telex_Snapshot::get( $snapshot_id );

		return rest_ensure_response( $snapshot );
	}

	/**
	 * DELETE /telex/v1/snapshots/{id} — delete a snapshot.
	 *
	 * @param \WP_REST_Request $request The incoming REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function delete_snapshot( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$id = sanitize_text_field( (string) $request->get_param( 'id' ) );

		if ( ! Telex_Snapshot::delete( $id ) ) {
			return new \WP_Error( 'telex_not_found', __( 'Snapshot not found.', 'dispatch' ), [ 'status' => 404 ] );
		}
		return rest_ensure_response( [ 'deleted' => true ] );
	}

	/**
	 * POST /telex/v1/snapshots/{id}/restore — reinstall all projects to captured versions.
	 *
	 * @param \WP_REST_Request $request The incoming REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function restore_snapshot( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$id       = sanitize_text_field( (string) $request->get_param( 'id' ) );
		$snapshot = Telex_Snapshot::get( $id );

		if ( null === $snapshot ) {
			return new \WP_Error( 'telex_not_found', __( 'Snapshot not found.', 'dispatch' ), [ 'status' => 404 ] );
		}

		$projects = $snapshot['projects'] ?? [];
		$results  = [];

		foreach ( $projects as $p ) {
			$public_id = sanitize_text_field( (string) $p['publicId'] );
			$result    = Telex_Installer::install( $public_id );
			$results[] = [
				'publicId' => $public_id,
				'success'  => ! is_wp_error( $result ),
				'message'  => is_wp_error( $result ) ? $result->get_error_message() : '',
			];
		}

		self::bump_data_version();

		return rest_ensure_response(
			[
				'snapshot_id' => $id,
				'results'     => $results,
				'errors'      => count( array_filter( $results, static fn( $r ) => ! $r['success'] ) ),
			]
		);
	}

	// -------------------------------------------------------------------------
	// Notification settings
	// -------------------------------------------------------------------------

	/**
	 * GET /telex/v1/settings/notifications — return current notification channel settings.
	 *
	 * @return \WP_REST_Response
	 */
	public static function get_notification_settings(): \WP_REST_Response {
		return rest_ensure_response( Telex_Notifications::get_settings() );
	}

	/**
	 * PUT /telex/v1/settings/notifications — save notification channel preferences.
	 *
	 * @param \WP_REST_Request $request The incoming REST request.
	 * @return \WP_REST_Response
	 */
	public static function update_notification_settings( \WP_REST_Request $request ): \WP_REST_Response {
		$settings = [
			'email_enabled'  => (bool) $request->get_param( 'email_enabled' ),
			'email_address'  => sanitize_email( (string) $request->get_param( 'email_address' ) ),
			'slack_enabled'  => (bool) $request->get_param( 'slack_enabled' ),
			'slack_webhook'  => sanitize_url( (string) $request->get_param( 'slack_webhook' ) ),
			'notify_updates' => (bool) $request->get_param( 'notify_updates' ),
			'notify_circuit' => (bool) $request->get_param( 'notify_circuit' ),
			'notify_install' => (bool) $request->get_param( 'notify_install' ),
		];

		Telex_Notifications::save_settings( $settings );

		return rest_ensure_response( Telex_Notifications::get_settings() );
	}

	/**
	 * POST /telex/v1/settings/notifications/test — send a test notification.
	 *
	 * @return \WP_REST_Response
	 */
	public static function test_notification(): \WP_REST_Response {
		$result = Telex_Notifications::send_test();

		return rest_ensure_response(
			[
				'sent'    => $result['sent'],
				'message' => $result['message'],
			]
		);
	}

	// -------------------------------------------------------------------------
	// Block usage analytics
	// -------------------------------------------------------------------------

	/**
	 * GET /telex/v1/analytics — return cached block usage data.
	 *
	 * @param \WP_REST_Request $request The incoming REST request.
	 * @return \WP_REST_Response
	 */
	public static function get_analytics( \WP_REST_Request $request ): \WP_REST_Response {
		if ( (bool) $request->get_param( 'force_scan' ) ) {
			Telex_Analytics::scan();
		}

		$data = Telex_Analytics::get_cached();

		return rest_ensure_response(
			[
				'scanned_at' => $data['scanned_at'] ?? null,
				'usage'      => $data['usage'] ?? [],
			]
		);
	}

	// -------------------------------------------------------------------------
	// Health checks
	// -------------------------------------------------------------------------

	/**
	 * GET /telex/v1/health/installed — check health of all installed projects.
	 *
	 * @param \WP_REST_Request $request The incoming REST request.
	 * @return \WP_REST_Response
	 */
	public static function get_health( \WP_REST_Request $request ): \WP_REST_Response {
		if ( (bool) $request->get_param( 'force_scan' ) ) {
			Telex_Health::bust_cache();
		}

		$results = Telex_Health::check_all();

		return rest_ensure_response( $results );
	}

	// -------------------------------------------------------------------------
	// Heartbeat data version helper
	// -------------------------------------------------------------------------

	/**
	 * Increment the telex_data_version transient.
	 *
	 * Called after any mutation (install/remove/update/pin/unpin) so that the
	 * WP Heartbeat handler can signal all open admin tabs that data has changed.
	 *
	 * @return void
	 */
	public static function bump_data_version(): void {
		set_transient( 'telex_data_version', wp_generate_uuid4(), 5 * MINUTE_IN_SECONDS );
	}
}
