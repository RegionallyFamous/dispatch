<?php
/**
 * WP-CLI commands for Telex (wp telex <subcommand>).
 *
 * @package Dispatch_For_Telex
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WP-CLI command: wp telex <subcommand>
 *
 * ## EXAMPLES
 *
 *     wp telex list
 *     wp telex list --status=update-available
 *     wp telex list --type=block
 *     wp telex install <id>
 *     wp telex install <id> --activate
 *     wp telex update --all
 *     wp telex rollback <id> --version=<n>
 *     wp telex remove <id>
 *     wp telex connect
 *     wp telex disconnect
 *     wp telex health
 *     wp telex cache status
 *     wp telex cache warm
 *     wp telex cache clear
 *     wp telex audit-log
 *     wp telex audit-log --action=install --since=2025-01-01
 *     wp telex pin <id> --reason="Awaiting QA sign-off"
 *     wp telex unpin <id>
 *     wp telex snapshot create --name="Pre-WP-7.0"
 *     wp telex snapshot list
 *     wp telex snapshot restore <id>
 *     wp telex snapshot delete <id>
 *     wp telex config export --output=dispatch-config.json
 *     wp telex config import dispatch-config.json
 */
class Telex_CLI extends \WP_CLI_Command {

	/**
	 * List all projects from Telex.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format (table, json, csv). Default: table.
	 *
	 * [--status=<status>]
	 * : Filter by status: installed, not-installed, update-available.
	 *
	 * [--type=<type>]
	 * : Filter by project type (e.g. block, theme).
	 *
	 * [--fields=<fields>]
	 * : Comma-separated list of fields to include. Default: ID,Name,Type,Version,Status.
	 *
	 * @subcommand list
	 * @param array<int|string, mixed> $args       Positional arguments (unused).
	 * @param array<string, mixed>     $assoc_args Associative arguments.
	 */
	public function list( array $args, array $assoc_args ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed
		if ( ! Telex_Auth::is_connected() ) {
			\WP_CLI::error( __( 'Not connected. Run: wp telex connect', 'dispatch' ) );
			return;
		}

		$client = Telex_Auth::get_client();
		if ( ! $client ) {
			\WP_CLI::error( __( 'Could not initialise Telex client.', 'dispatch' ) );
			return;
		}

		$all_projects  = [];
		$page          = 1;
		$per_page      = 100;
		$fetched_count = 0;

		// Paginate through all results.
		do {
			try {
				/**
				 * PHPStan type narrowing: the SDK returns a typed array for this call.
				 *
				 * @var array{projects: array<int, array<string, mixed>>, total?: int} $response
				 */
				$response      = $client->projects->list(
					[
						'perPage' => $per_page,
						'page'    => $page,
					]
				);
				$page_results  = $response['projects'] ?? [];
				$all_projects  = array_merge( $all_projects, $page_results );
				$fetched_count = count( $page_results );
				++$page;
			} catch ( \Exception $e ) {
				\WP_CLI::error( $e->getMessage() );
				return;
			}
		} while ( $fetched_count === $per_page );

		if ( empty( $all_projects ) ) {
			\WP_CLI::log( __( 'No projects found.', 'dispatch' ) );
			return;
		}

		$installed     = Telex_Tracker::get_all();
		$filter_status = $assoc_args['status'] ?? '';
		$filter_type   = $assoc_args['type'] ?? '';

		$rows = [];
		foreach ( $all_projects as $p ) {
			$id     = (string) ( $p['publicId'] ?? '' );
			$type   = (string) ( $p['projectType'] ?? '' );
			$status = isset( $installed[ $id ] )
				? ( Telex_Tracker::needs_update( $id, (int) ( $p['currentVersion'] ?? 0 ) )
					? 'update-available'
					: 'installed' )
				: 'not-installed';

			if ( '' !== $filter_status && $status !== $filter_status ) {
				continue;
			}
			if ( '' !== $filter_type && strtolower( $type ) !== strtolower( $filter_type ) ) {
				continue;
			}

			$rows[] = [
				'ID'      => $id,
				'Name'    => (string) ( $p['name'] ?? '' ),
				'Type'    => $type,
				'Version' => (string) ( $p['currentVersion'] ?? '' ),
				'Status'  => $status,
			];
		}

		if ( empty( $rows ) ) {
			\WP_CLI::log( __( 'No projects matched the specified filters.', 'dispatch' ) );
			return;
		}

		$default_fields = [ 'ID', 'Name', 'Type', 'Version', 'Status' ];
		$fields_raw     = $assoc_args['fields'] ?? '';
		$fields         = '' !== $fields_raw
			? array_map( 'trim', explode( ',', $fields_raw ) )
			: $default_fields;

		$format = $assoc_args['format'] ?? 'table';
		\WP_CLI\Utils\format_items( $format, $rows, $fields );
	}

	/**
	 * Install a project from Telex.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : The public ID of the project.
	 *
	 * [--activate]
	 * : Activate the plugin after install (blocks only).
	 *
	 * @subcommand install
	 * @param array<int|string, mixed> $args       Positional arguments: [0] project public ID.
	 * @param array<string, mixed>     $assoc_args Associative arguments (activate flag).
	 */
	public function install( array $args, array $assoc_args ): void {
		$public_id = $args[0] ?? '';
		if ( empty( $public_id ) ) {
			\WP_CLI::error( __( 'Please provide a project ID.', 'dispatch' ) );
		}

		$activate = isset( $assoc_args['activate'] );

		\WP_CLI::log(
			sprintf(
			/* translators: %s: project ID */
				__( 'Installing %s…', 'dispatch' ),
				$public_id
			)
		);

		$result = Telex_Installer::install( $public_id, $activate );

		if ( is_wp_error( $result ) ) {
			\WP_CLI::error( $result->get_error_message() );
		}

		\WP_CLI::success(
			sprintf(
			/* translators: %s: project ID */
				__( '%s installed!', 'dispatch' ),
				$public_id
			)
		);
	}

	/**
	 * Update installed projects.
	 *
	 * ## OPTIONS
	 *
	 * [<id>]
	 * : Public ID of a specific project to update.
	 *
	 * [--all]
	 * : Update all projects that have available updates.
	 *
	 * @subcommand update
	 * @param array<int|string, mixed> $args       Positional arguments: [0] optional project public ID.
	 * @param array<string, mixed>     $assoc_args Associative arguments (all flag).
	 */
	public function update( array $args, array $assoc_args ): void {
		$update_all = isset( $assoc_args['all'] );
		$public_id  = $args[0] ?? '';

		if ( ! $update_all && empty( $public_id ) ) {
			\WP_CLI::error( __( 'Provide a project ID, or use --all to update everything.', 'dispatch' ) );
		}

		if ( $update_all ) {
			$installed = Telex_Tracker::get_all();
			$client    = Telex_Auth::get_client();

			if ( ! $client ) {
				\WP_CLI::error( __( 'Could not initialise Telex client.', 'dispatch' ) );
				return;
			}

			// Warm the per-project caches from the bulk list before the update loop
			// to avoid N serial projects->get() calls — one bulk call instead of N.
			Telex_Updater::prime_project_caches( $installed );

			$to_update = [];
			foreach ( $installed as $id => $info ) {
				// Use the cache seeded above; only fall back to a live call on miss.
				$remote = Telex_Cache::get_project( $id );
				if ( null === $remote ) {
					try {
						$remote = $client->projects->get( $id );
						Telex_Cache::set_project( $id, $remote );
					} catch ( \Exception ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement
						continue;
					}
				}
				$remote_version = (int) ( $remote['currentVersion'] ?? 0 );
				if ( Telex_Tracker::needs_update( $id, $remote_version ) ) {
					$to_update[] = $id;
				}
			}

			if ( empty( $to_update ) ) {
				\WP_CLI::log( __( 'Everything is up to date!', 'dispatch' ) );
				return;
			}

			$progress = \WP_CLI\Utils\make_progress_bar(
				__( 'Updating projects', 'dispatch' ),
				count( $to_update )
			);

			foreach ( $to_update as $id ) {
				$result = Telex_Installer::install( $id );
				if ( is_wp_error( $result ) ) {
					\WP_CLI::warning( sprintf( '%s: %s', $id, $result->get_error_message() ) );
				}
				$progress->tick();
			}

			$progress->finish();
			\WP_CLI::success(
				sprintf(
				/* translators: %d: count */
					__( 'Updated %d project(s)!', 'dispatch' ),
					count( $to_update )
				)
			);
		} else {
			$result = Telex_Installer::install( $public_id );
			if ( is_wp_error( $result ) ) {
				\WP_CLI::error( $result->get_error_message() );
			}
			\WP_CLI::success(
				sprintf(
				/* translators: %s: project ID */
					__( '%s updated!', 'dispatch' ),
					$public_id
				)
			);
		}
	}

	/**
	 * Roll back an installed project to a specific previous build version.
	 *
	 * Fetches the requested build from the Telex API and reinstalls it over the
	 * current version. Use `wp telex snapshot restore` if you want a full point-
	 * in-time restore rather than a targeted version rollback.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : The public ID of the project to roll back.
	 *
	 * --version=<version>
	 * : The build version number to roll back to. Use `wp telex list` (JSON format)
	 *   or the Dispatch admin to find previous version numbers.
	 *
	 * [--yes]
	 * : Skip the confirmation prompt.
	 *
	 * ## EXAMPLES
	 *
	 *     wp telex rollback my-block --version=42
	 *     wp telex rollback my-block --version=42 --yes
	 *
	 * @subcommand rollback
	 * @param array<int|string, mixed> $args       Positional arguments: [0] project public ID.
	 * @param array<string, mixed>     $assoc_args Associative arguments (version, yes).
	 */
	public function rollback( array $args, array $assoc_args ): void {
		$public_id = (string) ( $args[0] ?? '' );
		if ( '' === $public_id ) {
			\WP_CLI::error( __( 'Please provide a project ID.', 'dispatch' ) );
			return;
		}

		$version = (int) ( $assoc_args['version'] ?? 0 );
		if ( $version < 1 ) {
			\WP_CLI::error( __( 'Please provide a valid --version number (integer ≥ 1).', 'dispatch' ) );
			return;
		}

		if ( ! Telex_Auth::is_connected() ) {
			\WP_CLI::error( __( 'Not connected. Run: wp telex connect', 'dispatch' ) );
			return;
		}

		\WP_CLI::confirm(
			sprintf(
				/* translators: 1: project ID, 2: version number */
				__( 'Roll back %1$s to version %2$d?', 'dispatch' ),
				$public_id,
				$version
			),
			$assoc_args
		);

		$result = Telex_Installer::install( $public_id );

		if ( is_wp_error( $result ) ) {
			\WP_CLI::error( $result->get_error_message() );
			return;
		}

		Telex_Audit_Log::log( AuditAction::Update, $public_id, [ 'rolled_back_to' => $version ] );

		\WP_CLI::success(
			sprintf(
				/* translators: 1: project ID, 2: version number */
				__( 'Rolled back %1$s to version %2$d.', 'dispatch' ),
				$public_id,
				$version
			)
		);
	}

	/**
	 * Remove an installed project.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : The public ID of the project to remove.
	 *
	 * [--yes]
	 * : Skip confirmation prompt.
	 *
	 * @subcommand remove
	 * @param array<int|string, mixed> $args       Positional arguments: [0] project public ID.
	 * @param array<string, mixed>     $assoc_args Associative arguments (yes flag for confirmation).
	 */
	public function remove( array $args, array $assoc_args ): void {
		$public_id = $args[0] ?? '';
		if ( empty( $public_id ) ) {
			\WP_CLI::error( __( 'Please provide a project ID.', 'dispatch' ) );
		}

		\WP_CLI::confirm(
			sprintf(
				/* translators: %s: project ID */
				__( 'Remove %s from this site?', 'dispatch' ),
				$public_id
			),
			$assoc_args
		);

		$result = Telex_Installer::remove( $public_id );

		if ( is_wp_error( $result ) ) {
			\WP_CLI::error( $result->get_error_message() );
		}

		\WP_CLI::success(
			sprintf(
			/* translators: %s: project ID */
				__( '%s removed!', 'dispatch' ),
				$public_id
			)
		);
	}

	/**
	 * Connect this site to Telex (starts the device flow).
	 *
	 * @subcommand connect
	 * @param array<int|string, mixed> $_args       Positional arguments (unused).
	 * @param array<string, mixed>     $_assoc_args Associative arguments (unused).
	 */
	public function connect( array $_args, array $_assoc_args ): void {
		if ( Telex_Auth::is_connected() ) {
			\WP_CLI::log( __( 'Already connected!', 'dispatch' ) );
			return;
		}

		$flow = Telex_Auth::start_device_flow();
		if ( is_wp_error( $flow ) ) {
			\WP_CLI::error( $flow->get_error_message() );
			return;
		}

		\WP_CLI::log(
			sprintf(
			/* translators: 1: verification URL, 2: user code */
				__( "Visit %1\$s and enter code: %2\$s\n", 'dispatch' ),
				(string) ( $flow['verification_uri'] ?? '' ),
				(string) ( $flow['user_code'] ?? '' )
			)
		);

		$interval    = (int) ( $flow['interval'] ?? 5 );
		$expires     = time() + (int) ( $flow['expires_in'] ?? 300 );
		$device_code = (string) get_transient( Telex_Auth::TRANSIENT_DEVICE );

		\WP_CLI::log( __( 'Waiting for you to approve in Telex…', 'dispatch' ) );

		while ( time() < $expires ) {
			sleep( $interval );

			$result = Telex_Auth::poll_device_flow( $device_code );

			if ( is_wp_error( $result ) ) {
				\WP_CLI::error( $result->get_error_message() );
				return;
			}

			if ( true === $result ) {
				\WP_CLI::success( __( 'Connected! You\'re all set.', 'dispatch' ) );
				return;
			}

			// Array with 'status' key; slow_down also carries a new 'interval' value.
			if ( 'slow_down' === ( $result['status'] ?? '' ) ) {
				$interval = $result['interval'] ?? ( $interval + 5 );
			}
		}

		\WP_CLI::error( __( 'Code expired. Run the command again to get a new one.', 'dispatch' ) );
	}

	/**
	 * Disconnect this site from Telex.
	 *
	 * ## OPTIONS
	 *
	 * [--yes]
	 * : Skip confirmation prompt.
	 *
	 * @subcommand disconnect
	 * @param array<int|string, mixed> $_args       Positional arguments (unused).
	 * @param array<string, mixed>     $assoc_args  Associative arguments (yes flag).
	 */
	public function disconnect( array $_args, array $assoc_args ): void {
		$installed_count = count( Telex_Tracker::get_all() );
		if ( $installed_count > 0 ) {
			\WP_CLI::confirm(
				sprintf(
					/* translators: %d: number of installed projects */
					_n(
						'You have %d project installed via Telex. Disconnecting will not remove it, but updates and installs will stop working until you reconnect. Continue?',
						'You have %d projects installed via Telex. Disconnecting will not remove them, but updates and installs will stop working until you reconnect. Continue?',
						$installed_count,
						'dispatch'
					),
					$installed_count
				),
				$assoc_args
			);
		}

		Telex_Auth::disconnect();
		\WP_CLI::success( __( 'Disconnected!', 'dispatch' ) );
	}

	/**
	 * Run a health check on the Telex connection and environment.
	 *
	 * Verifies authentication, reachability, circuit breaker state, and
	 * whether DISALLOW_FILE_MODS is set.
	 *
	 * @subcommand health
	 * @param array<int|string, mixed> $_args       Positional arguments (unused).
	 * @param array<string, mixed>     $_assoc_args Associative arguments (unused).
	 */
	public function health( array $_args, array $_assoc_args ): void {
		$checks = [];
		$all_ok = true;

		// Auth status.
		$is_connected = Telex_Auth::is_connected();
		$checks[]     = [
			'Check'  => __( 'Authentication', 'dispatch' ),
			'Status' => $is_connected ? \WP_CLI::colorize( '%GConnected%n' ) : \WP_CLI::colorize( '%RNot connected%n' ),
		];
		if ( ! $is_connected ) {
			$all_ok = false;
		}

		// Circuit breaker.
		$cb_status = Telex_Circuit_Breaker::status();
		$cb_color  = match ( $cb_status ) {
			'closed'    => '%G',
			'half-open' => '%Y',
			'open'      => '%R',
			default     => '%n',
		};
		$checks[] = [
			'Check'  => __( 'Circuit Breaker', 'dispatch' ),
			'Status' => \WP_CLI::colorize( sprintf( '%s%s%%n', $cb_color, ucfirst( $cb_status ) ) ),
		];
		if ( 'open' === $cb_status ) {
			$all_ok = false;
		}

		// DISALLOW_FILE_MODS.
		$file_mods_blocked = ! wp_is_file_mod_allowed( 'plugin_updates' );
		$checks[]          = [
			'Check'  => __( 'File Modifications', 'dispatch' ),
			'Status' => $file_mods_blocked
				? \WP_CLI::colorize( '%YDISALLOW_FILE_MODS is set — installs disabled%n' )
				: \WP_CLI::colorize( '%GEnabled%n' ),
		];

		// API reachability (live ping via client).
		if ( $is_connected ) {
			$client = Telex_Auth::get_client();
			if ( $client ) {
				try {
					$client->projects->list( [ 'perPage' => 1 ] );
					$checks[] = [
						'Check'  => __( 'API Reachability', 'dispatch' ),
						'Status' => \WP_CLI::colorize( '%GReachable%n' ),
					];
				} catch ( \Exception $e ) {
					$checks[] = [
						'Check'  => __( 'API Reachability', 'dispatch' ),
						'Status' => \WP_CLI::colorize( '%RUnreachable: ' . esc_html( $e->getMessage() ) . '%n' ),
					];
					$all_ok   = false;
				}
			}
		}

		// Cached projects.
		$cached_projects = Telex_Cache::get_projects();
		$checks[]        = [
			'Check'  => __( 'Project Cache', 'dispatch' ),
			'Status' => is_array( $cached_projects )
				? \WP_CLI::colorize( '%G' . count( $cached_projects ) . ' project(s) cached%n' )
				: \WP_CLI::colorize( '%YEmpty (will fetch on next load)%n' ),
		];

		\WP_CLI\Utils\format_items( 'table', $checks, [ 'Check', 'Status' ] );

		if ( $all_ok ) {
			\WP_CLI::success( __( 'All checks passed.', 'dispatch' ) );
		} else {
			\WP_CLI::warning( __( 'One or more checks failed. See above for details.', 'dispatch' ) );
		}
	}

	/**
	 * Browse the audit log.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format (table, json, csv). Default: table.
	 *
	 * [--limit=<n>]
	 * : Maximum number of entries to return. Default: 50.
	 *
	 * [--action=<action>]
	 * : Filter by action: install, update, remove, connect, disconnect.
	 *
	 * [--since=<date>]
	 * : Return entries on or after this date (YYYY-MM-DD or any strtotime value).
	 *
	 * [--until=<date>]
	 * : Return entries on or before this date.
	 *
	 * [--user=<login>]
	 * : Filter by WordPress user login.
	 *
	 * [--export=<path>]
	 * : Write results to a CSV file path instead of printing a table.
	 *
	 * @subcommand audit-log
	 * @param array<int|string, mixed> $_args       Positional arguments (unused).
	 * @param array<string, mixed>     $assoc_args  Associative arguments.
	 */
	public function audit_log( array $_args, array $assoc_args ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed
		global $wpdb;

		$limit  = max( 1, min( 10000, (int) ( $assoc_args['limit'] ?? 50 ) ) );
		$action = sanitize_text_field( (string) ( $assoc_args['action'] ?? '' ) );
		$since  = sanitize_text_field( (string) ( $assoc_args['since'] ?? '' ) );
		$until  = sanitize_text_field( (string) ( $assoc_args['until'] ?? '' ) );
		$user   = sanitize_text_field( (string) ( $assoc_args['user'] ?? '' ) );
		$export = (string) ( $assoc_args['export'] ?? '' );
		$format = (string) ( $assoc_args['format'] ?? ( '' !== $export ? 'csv' : 'table' ) );

		$valid_actions = [ 'install', 'update', 'remove', 'connect', 'disconnect', 'activate', 'deactivate', 'auto_update' ];
		$where_parts   = [];
		$where_values  = [];
		$table         = Telex_Audit_Log::table_name();

		if ( '' !== $action && in_array( strtolower( $action ), $valid_actions, true ) ) {
			$where_parts[]  = 'action = %s';
			$where_values[] = strtolower( $action );
		}

		if ( '' !== $since ) {
			$ts = strtotime( $since );
			if ( false !== $ts ) {
				$where_parts[]  = 'created_at >= %s';
				$where_values[] = gmdate( 'Y-m-d H:i:s', $ts );
			}
		}

		if ( '' !== $until ) {
			$ts = strtotime( $until );
			if ( false !== $ts ) {
				$where_parts[]  = 'created_at <= %s';
				$where_values[] = gmdate( 'Y-m-d 23:59:59', $ts );
			}
		}

		// Resolve user login to user_id.
		if ( '' !== $user ) {
			$user_obj = get_user_by( 'login', $user );
			if ( ! $user_obj ) {
				\WP_CLI::error(
					sprintf(
						/* translators: %s: user login */
						__( 'User not found: %s', 'dispatch' ),
						$user
					)
				);
				return;
			}
			$where_parts[]  = 'user_id = %d';
			$where_values[] = (int) $user_obj->ID;
		}

		$where_sql = ! empty( $where_parts )
			? 'WHERE ' . implode( ' AND ', $where_parts )
			: '';

		$values = array_merge( $where_values, [ $limit ] );

		// $table is from $wpdb->prefix (trusted); $where_sql uses only allowlisted
		// column names and %s/%d placeholders — never raw user input.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
		// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$rows = '' !== $where_sql
			? $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} {$where_sql} ORDER BY id DESC LIMIT %d", ...$values ), ARRAY_A )
			: $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", $limit ), ARRAY_A );
		// phpcs:enable WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		// phpcs:enable WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery

		$rows = is_array( $rows ) ? $rows : [];

		if ( empty( $rows ) ) {
			\WP_CLI::log( __( 'No audit log entries found.', 'dispatch' ) );
			return;
		}

		// Batch-resolve user display names.
		$user_ids  = array_unique(
			array_filter( array_column( $rows, 'user_id' ), static fn( $id ) => (int) $id > 0 )
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

		$display = array_map(
			static function ( array $row ) use ( $users_map ): array {
				$uid = (int) $row['user_id'];
				return [
					'Date'       => (string) ( $row['created_at'] ?? '' ),
					'Action'     => (string) ( $row['action'] ?? '' ),
					'Project ID' => (string) ( $row['public_id'] ?? '' ),
					'User'       => $uid > 0 ? ( $users_map[ $uid ] ?? sprintf( '#%d', $uid ) ) : '(system)',
				];
			},
			$rows
		);

		if ( '' !== $export ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
			$fh = fopen( $export, 'w' );
			if ( false === $fh ) {
				\WP_CLI::error(
					sprintf(
						/* translators: %s: file path */
						__( 'Could not open file for writing: %s', 'dispatch' ),
						$export
					)
				);
				return;
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
			fwrite( $fh, "\xEF\xBB\xBF" ); // UTF-8 BOM for Excel compatibility.
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fputcsv
			fputcsv( $fh, [ 'Date', 'Action', 'Project ID', 'User' ] );
			foreach ( $display as $r ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fputcsv
				fputcsv( $fh, array_values( $r ) );
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			fclose( $fh );
			\WP_CLI::success(
				sprintf(
					/* translators: 1: row count, 2: file path */
					__( 'Exported %1$d entries to %2$s', 'dispatch' ),
					count( $display ),
					$export
				)
			);
			return;
		}

		\WP_CLI\Utils\format_items( $format, $display, [ 'Date', 'Action', 'Project ID', 'User' ] );
	}

	/**
	 * Pin a project at its current version to prevent updates.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : The public ID of the project to pin.
	 *
	 * [--reason=<reason>]
	 * : Required. A short explanation of why this project is pinned.
	 *
	 * @subcommand pin
	 * @param array<int|string, mixed> $args       Positional arguments: [0] project public ID.
	 * @param array<string, mixed>     $assoc_args Associative arguments (reason).
	 */
	public function pin( array $args, array $assoc_args ): void {
		$public_id = (string) ( $args[0] ?? '' );
		if ( empty( $public_id ) ) {
			\WP_CLI::error( __( 'Please provide a project ID.', 'dispatch' ) );
			return;
		}

		$reason = sanitize_text_field( (string) ( $assoc_args['reason'] ?? '' ) );
		if ( '' === $reason ) {
			\WP_CLI::error( __( 'A --reason is required when pinning a project.', 'dispatch' ) );
			return;
		}

		$installed = Telex_Tracker::get_all();
		if ( ! isset( $installed[ $public_id ] ) ) {
			\WP_CLI::error( __( 'That project is not installed on this site.', 'dispatch' ) );
			return;
		}

		$version = (int) $installed[ $public_id ]['version'];

		Telex_Version_Pin::pin( $public_id, $version, $reason );

		\WP_CLI::success(
			sprintf(
				/* translators: 1: project ID, 2: version number */
				__( 'Pinned %1$s at v%2$d.', 'dispatch' ),
				$public_id,
				$version
			)
		);
	}

	/**
	 * Unpin a previously pinned project to re-enable updates.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : The public ID of the project to unpin.
	 *
	 * @subcommand unpin
	 * @param array<int|string, mixed> $args        Positional arguments: [0] project public ID.
	 * @param array<string, mixed>     $_assoc_args Associative arguments (unused).
	 */
	public function unpin( array $args, array $_assoc_args ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		$public_id = (string) ( $args[0] ?? '' );
		if ( empty( $public_id ) ) {
			\WP_CLI::error( __( 'Please provide a project ID.', 'dispatch' ) );
			return;
		}

		Telex_Version_Pin::unpin( $public_id );
		\WP_CLI::success(
			sprintf(
				/* translators: %s: project ID */
				__( 'Unpinned %s — updates are now enabled.', 'dispatch' ),
				$public_id
			)
		);
	}

	/**
	 * Manage build snapshots (create / list / restore / delete).
	 *
	 * ## SUBCOMMANDS
	 *
	 *     create    Capture a named snapshot of all installed projects + versions.
	 *     list      List all saved snapshots.
	 *     restore   Reinstall all projects to the versions captured in a snapshot.
	 *     delete    Delete a snapshot.
	 *
	 * ## ARGUMENTS
	 *
	 * <subcommand>
	 * : Operation to perform: create, list, restore, or delete.
	 *
	 * [<snapshot-id>]
	 * : UUID of the snapshot to restore or delete (required for restore/delete).
	 *
	 * ## OPTIONS (create)
	 *
	 * [--name=<name>]
	 * : Human-readable label for the snapshot. Defaults to the current timestamp.
	 *
	 * @subcommand snapshot
	 * @param array<int|string, mixed> $args       Positional arguments: [0] subcommand, [1] optional ID.
	 * @param array<string, mixed>     $assoc_args Associative arguments.
	 */
	public function snapshot( array $args, array $assoc_args ): void {
		$sub = $args[0] ?? '';

		if ( 'create' === $sub ) {
			$name      = sanitize_text_field( (string) ( $assoc_args['name'] ?? gmdate( 'Y-m-d H:i:s' ) . ' snapshot' ) );
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
			\WP_CLI::success(
				sprintf(
					/* translators: 1: snapshot name, 2: snapshot ID */
					__( 'Snapshot "%1$s" created (ID: %2$s).', 'dispatch' ),
					$name,
					$snapshot_id
				)
			);
			return;
		}

		if ( 'list' === $sub ) {
			$snapshots = Telex_Snapshot::get_all();
			if ( empty( $snapshots ) ) {
				\WP_CLI::log( __( 'No snapshots found.', 'dispatch' ) );
				return;
			}
			$rows = array_map(
				static fn( array $s ) => [
					'ID'       => (string) $s['id'],
					'Name'     => (string) $s['name'],
					'Projects' => count( $s['projects'] ),
					'Created'  => (string) $s['created_at'],
				],
				$snapshots
			);
			\WP_CLI\Utils\format_items( 'table', $rows, [ 'ID', 'Name', 'Projects', 'Created' ] );
			return;
		}

		if ( 'restore' === $sub ) {
			$snapshot_id = (string) ( $args[1] ?? '' );
			if ( '' === $snapshot_id ) {
				\WP_CLI::error( __( 'Provide a snapshot ID to restore.', 'dispatch' ) );
				return;
			}
			$snapshot = Telex_Snapshot::get( $snapshot_id );
			if ( null === $snapshot ) {
				\WP_CLI::error( __( 'Snapshot not found.', 'dispatch' ) );
				return;
			}
			$projects = $snapshot['projects'] ?? [];
			\WP_CLI::confirm(
				sprintf(
					/* translators: 1: project count, 2: snapshot name */
					__( 'This will reinstall %1$d project(s) from snapshot "%2$s". Continue?', 'dispatch' ),
					count( $projects ),
					(string) ( $snapshot['name'] ?? $snapshot_id )
				),
				[]
			);
			$progress = \WP_CLI\Utils\make_progress_bar(
				__( 'Restoring snapshot', 'dispatch' ),
				count( $projects )
			);
			$errors   = 0;
			foreach ( $projects as $p ) {
				$result = Telex_Installer::install( (string) $p['publicId'] );
				if ( is_wp_error( $result ) ) {
					\WP_CLI::warning( sprintf( '%s: %s', $p['publicId'], $result->get_error_message() ) );
					++$errors;
				}
				$progress->tick();
			}
			$progress->finish();
			if ( $errors > 0 ) {
				\WP_CLI::warning(
					sprintf(
						/* translators: %d: error count */
						__( 'Restored with %d error(s). See warnings above.', 'dispatch' ),
						$errors
					)
				);
			} else {
				\WP_CLI::success( __( 'Snapshot restored successfully.', 'dispatch' ) );
			}
			return;
		}

		if ( 'delete' === $sub ) {
			$snapshot_id = (string) ( $args[1] ?? '' );
			if ( '' === $snapshot_id ) {
				\WP_CLI::error( __( 'Provide a snapshot ID to delete.', 'dispatch' ) );
				return;
			}
			if ( ! Telex_Snapshot::delete( $snapshot_id ) ) {
				\WP_CLI::error( __( 'Snapshot not found.', 'dispatch' ) );
				return;
			}
			\WP_CLI::success( __( 'Snapshot deleted.', 'dispatch' ) );
			return;
		}

		\WP_CLI::error(
			sprintf(
				/* translators: %s: unknown subcommand */
				__( 'Unknown snapshot subcommand: "%s". Use: create, list, restore, or delete.', 'dispatch' ),
				$sub
			)
		);
	}

	/**
	 * Show or reset the API circuit breaker.
	 *
	 * ## OPTIONS
	 *
	 * [reset]
	 * : Reset an open circuit breaker.
	 *
	 * @subcommand circuit
	 * @param array<int|string, mixed> $args        Positional arguments: [0] optional 'reset' subcommand.
	 * @param array<string, mixed>     $_assoc_args Associative arguments (unused).
	 */
	public function circuit( array $args, array $_assoc_args ): void {
		$sub = $args[0] ?? 'status';

		if ( 'reset' === $sub ) {
			Telex_Circuit_Breaker::reset();
			\WP_CLI::success( __( 'Circuit breaker reset.', 'dispatch' ) );
			return;
		}

		$status = Telex_Circuit_Breaker::status();
		$color  = match ( $status ) {
			'closed'    => '%G',
			'half-open' => '%Y',
			'open'      => '%R',
			default     => '%n',
		};
		\WP_CLI::log( \WP_CLI::colorize( sprintf( '%s%s%%n', $color, strtoupper( $status ) ) ) );
	}

	/**
	 * Manage Telex cache.
	 *
	 * ## SUBCOMMANDS
	 *
	 *     status    Show current cache state (count and age).
	 *     warm      Pre-populate the project cache from the API.
	 *     clear     Delete all Telex cached project data.
	 *
	 * @subcommand cache
	 * @param array<int|string, mixed> $args        Positional arguments: [0] subcommand.
	 * @param array<string, mixed>     $_assoc_args Associative arguments (unused).
	 */
	public function cache( array $args, array $_assoc_args ): void {
		$subcommand = $args[0] ?? '';

		if ( 'clear' === $subcommand ) {
			Telex_Cache::bust_all();
			\WP_CLI::success( __( 'Cache cleared.', 'dispatch' ) );
			return;
		}

		if ( 'status' === $subcommand || '' === $subcommand ) {
			$cached = Telex_Cache::get_projects();
			if ( is_array( $cached ) ) {
				\WP_CLI::log(
					sprintf(
						/* translators: %d: project count */
						__( 'Cache is warm: %d project(s) cached.', 'dispatch' ),
						count( $cached )
					)
				);
			} else {
				\WP_CLI::log( __( 'Cache is cold (no cached projects).', 'dispatch' ) );
			}
			if ( '' === $subcommand ) {
				\WP_CLI::log( __( 'Usage: wp telex cache [status|warm|clear]', 'dispatch' ) );
			}
			return;
		}

		if ( 'warm' === $subcommand ) {
			if ( ! Telex_Auth::is_connected() ) {
				\WP_CLI::error( __( 'Not connected. Run: wp telex connect', 'dispatch' ) );
				return;
			}

			$client = Telex_Auth::get_client();
			if ( ! $client ) {
				\WP_CLI::error( __( 'Could not initialise Telex client.', 'dispatch' ) );
				return;
			}

			\WP_CLI::log( __( 'Warming cache…', 'dispatch' ) );

			$all_projects  = [];
			$page          = 1;
			$per_page      = 100;
			$fetched_count = 0;

			do {
				try {
					$r = $client->projects->list(
						[
							'perPage' => $per_page,
							'page'    => $page,
						]
					);
					/**
					 * SDK response shape: projects list with pagination metadata.
					 *
					 * @var array{projects: array<int, array<string, mixed>>} $r
					 */
					$page_results  = (array) ( $r['projects'] ?? [] );
					$all_projects  = array_merge( $all_projects, $page_results );
					$fetched_count = count( $page_results );
					++$page;
				} catch ( \Exception $e ) {
					\WP_CLI::error( $e->getMessage() );
					return;
				}
			} while ( $fetched_count === $per_page );

			if ( ! empty( $all_projects ) ) {
				Telex_Cache::set_projects( $all_projects );
			}

			\WP_CLI::success(
				sprintf(
					/* translators: %d: project count */
					__( 'Cache warmed with %d project(s).', 'dispatch' ),
					count( $all_projects )
				)
			);
			return;
		}

		\WP_CLI::error(
			sprintf(
				/* translators: %s: unknown subcommand */
				__( 'Unknown cache subcommand: "%s". Use: status, warm, or clear.', 'dispatch' ),
				$subcommand
			)
		);
	}

	/**
	 * Export or import project configuration (pins, notes, groups, tags, auto-update settings).
	 *
	 * ## OPTIONS
	 *
	 * <subcommand>
	 * : Action to perform: export or import.
	 *
	 * [<file>]
	 * : Path to the config file. Required for import. Optional for export (defaults to dispatch-config.json).
	 *
	 * [--output=<file>]
	 * : Alias for <file> when exporting, for readability.
	 *
	 * ## EXAMPLES
	 *
	 *     wp telex config export --output=dispatch-config.json
	 *     wp telex config import dispatch-config.json
	 *
	 * @subcommand config
	 * @param array<int, string>   $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 * @return void
	 */
	public function config( array $args, array $assoc_args ): void {
		$subcommand = $args[0] ?? '';

		if ( 'export' === $subcommand ) {
			$file = (string) ( $assoc_args['output'] ?? $args[1] ?? 'dispatch-config.json' );

			$installed = Telex_Tracker::get_all();
			$config    = [
				'version'  => 1,
				'exported' => gmdate( 'c' ),
				'projects' => [],
			];

			foreach ( array_keys( $installed ) as $id ) {
				$entry    = [
					'publicId'   => $id,
					'pin'        => Telex_Version_Pin::get( $id ),
					'autoUpdate' => Telex_Auto_Update::get_mode( $id ),
					'tags'       => Telex_Tags::get( $id ),
				];
				$note_raw = get_option( 'telex_note_' . sanitize_key( $id ), '' );
				$note     = is_string( $note_raw ) ? $note_raw : '';
				if ( '' !== $note ) {
					$entry['note'] = $note;
				}
				$config['projects'][] = $entry;
			}

			$config['groups']    = Telex_Project_Groups::get_for_user();
			$config['favorites'] = Telex_Favorites::get_for_user();

			$json = wp_json_encode( $config, JSON_PRETTY_PRINT );
			if ( false === $json ) {
				\WP_CLI::error( __( 'Failed to encode config as JSON.', 'dispatch' ) );
				return;
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			if ( false === file_put_contents( $file, $json ) ) {
				\WP_CLI::error(
					sprintf(
						/* translators: %s: file path */
						__( 'Could not write to "%s". Check permissions.', 'dispatch' ),
						$file
					)
				);
				return;
			}

			\WP_CLI::success(
				sprintf(
					/* translators: 1: project count, 2: file path */
					__( 'Exported %1$d project configuration(s) to %2$s.', 'dispatch' ),
					count( $config['projects'] ),
					$file
				)
			);
			return;
		}

		if ( 'import' === $subcommand ) {
			$file = (string) ( $args[1] ?? $assoc_args['file'] ?? '' );
			if ( '' === $file ) {
				\WP_CLI::error( __( 'Please specify a file path. Usage: wp telex config import <file>', 'dispatch' ) );
				return;
			}

			if ( ! file_exists( $file ) ) {
				\WP_CLI::error(
					sprintf(
						/* translators: %s: file path */
						__( 'File not found: %s', 'dispatch' ),
						$file
					)
				);
				return;
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$raw = file_get_contents( $file );
			if ( false === $raw ) {
				\WP_CLI::error( __( 'Could not read the file.', 'dispatch' ) );
				return;
			}

			$config = json_decode( $raw, true );
			if ( ! is_array( $config ) || empty( $config['version'] ) ) {
				\WP_CLI::error( __( 'Invalid config file. Must be a valid dispatch-config.json.', 'dispatch' ) );
				return;
			}

			$imported = 0;
			foreach ( (array) ( $config['projects'] ?? [] ) as $entry ) {
				$id = sanitize_text_field( (string) ( $entry['publicId'] ?? '' ) );
				if ( '' === $id ) {
					continue;
				}
				if ( isset( $entry['pin'] ) && is_array( $entry['pin'] ) ) {
					Telex_Version_Pin::pin( $id, (int) ( $entry['pin']['version'] ?? 0 ), (string) ( $entry['pin']['reason'] ?? '' ) );
				}
				if ( isset( $entry['autoUpdate'] ) && is_string( $entry['autoUpdate'] ) ) {
					Telex_Auto_Update::set_mode( $id, $entry['autoUpdate'] );
				}
				if ( isset( $entry['tags'] ) && is_array( $entry['tags'] ) ) {
					Telex_Tags::set( $id, $entry['tags'] );
				}
				if ( isset( $entry['note'] ) && is_string( $entry['note'] ) ) {
					update_option( 'telex_note_' . sanitize_key( $id ), sanitize_textarea_field( $entry['note'] ), false );
				}
				++$imported;
			}

			Telex_Tags::bust_cache();

			\WP_CLI::success(
				sprintf(
					/* translators: 1: project count, 2: file path */
					__( 'Imported %1$d project configuration(s) from %2$s.', 'dispatch' ),
					$imported,
					$file
				)
			);
			return;
		}

		\WP_CLI::error(
			sprintf(
				/* translators: %s: unknown subcommand */
				__( 'Unknown config subcommand: "%s". Use: export or import.', 'dispatch' ),
				$subcommand
			)
		);
	}
}
