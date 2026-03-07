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
 *     wp telex install <id>
 *     wp telex install <id> --activate
 *     wp telex update --all
 *     wp telex remove <id>
 *     wp telex connect
 *     wp telex disconnect
 *     wp telex cache clear
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
	 * @subcommand list
	 * @param array $args       Positional arguments (unused).
	 * @param array $assoc_args Associative arguments (format).
	 */
	public function list( array $args, array $assoc_args ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed
		if ( ! Telex_Auth::is_connected() ) {
			\WP_CLI::error( __( 'Not connected to Telex. Run: wp telex connect', 'dispatch' ) );
		}

		$client = Telex_Auth::get_client();
		if ( ! $client ) {
			\WP_CLI::error( __( 'Could not initialise Telex client.', 'dispatch' ) );
		}

		try {
			$response = $client->projects->list( [ 'perPage' => 100 ] );
			$projects = $response['projects'] ?? [];
		} catch ( \Exception $e ) {
			\WP_CLI::error( $e->getMessage() );
		}

		if ( empty( $projects ) ) {
			\WP_CLI::log( __( 'No projects found.', 'dispatch' ) );
			return;
		}

		$installed = Telex_Tracker::get_all();

		$rows = array_map(
			static function ( array $p ) use ( $installed ): array {
				$id     = $p['publicId'] ?? '';
				$status = isset( $installed[ $id ] )
				? ( Telex_Tracker::needs_update( $id, (int) ( $p['currentVersion'] ?? 0 ) )
					? 'update-available'
					: 'installed' )
				: 'not-installed';

				return [
					'ID'      => $id,
					'Name'    => $p['name'] ?? '',
					'Type'    => $p['projectType'] ?? '',
					'Version' => $p['currentVersion'] ?? '',
					'Status'  => $status,
				];
			},
			$projects
		);

		$format = $assoc_args['format'] ?? 'table';
		\WP_CLI\Utils\format_items( $format, $rows, [ 'ID', 'Name', 'Type', 'Version', 'Status' ] );
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
	 * @param array $args       Positional arguments: [0] project public ID.
	 * @param array $assoc_args Associative arguments (activate flag).
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
				__( 'Installing project %s…', 'dispatch' ),
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
				__( 'Project %s installed successfully.', 'dispatch' ),
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
	 * @param array $args       Positional arguments: [0] optional project public ID.
	 * @param array $assoc_args Associative arguments (all flag).
	 */
	public function update( array $args, array $assoc_args ): void {
		$update_all = isset( $assoc_args['all'] );
		$public_id  = $args[0] ?? '';

		if ( ! $update_all && empty( $public_id ) ) {
			\WP_CLI::error( __( 'Provide a project ID or use --all.', 'dispatch' ) );
		}

		if ( $update_all ) {
			$installed = Telex_Tracker::get_all();
			$client    = Telex_Auth::get_client();

			if ( ! $client ) {
				\WP_CLI::error( __( 'Could not initialise Telex client.', 'dispatch' ) );
			}

			$to_update = [];
			foreach ( $installed as $id => $info ) {
				try {
					$remote         = $client->projects->get( $id );
					$remote_version = (int) ( $remote['currentVersion'] ?? 0 );
					if ( Telex_Tracker::needs_update( $id, $remote_version ) ) {
						$to_update[] = $id;
					}
				} catch ( \Exception ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement -- Per-project fetch failures are skipped; remaining items continue.
					// Skip projects that fail to fetch.
				}
			}

			if ( empty( $to_update ) ) {
				\WP_CLI::log( __( 'All projects are up to date.', 'dispatch' ) );
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
					__( 'Updated %d project(s).', 'dispatch' ),
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
					__( 'Project %s updated.', 'dispatch' ),
					$public_id
				)
			);
		}
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
	 * @param array $args       Positional arguments: [0] project public ID.
	 * @param array $assoc_args Associative arguments (yes flag for confirmation).
	 */
	public function remove( array $args, array $assoc_args ): void {
		$public_id = $args[0] ?? '';
		if ( empty( $public_id ) ) {
			\WP_CLI::error( __( 'Please provide a project ID.', 'dispatch' ) );
		}

		\WP_CLI::confirm(
			sprintf(
				/* translators: %s: project ID */
				__( 'Remove project %s?', 'dispatch' ),
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
				__( 'Project %s removed.', 'dispatch' ),
				$public_id
			)
		);
	}

	/**
	 * Connect this site to Telex (starts the device flow).
	 *
	 * @subcommand connect
	 * @param array $_args       Positional arguments (unused).
	 * @param array $_assoc_args Associative arguments (unused).
	 */
	public function connect( array $_args, array $_assoc_args ): void {
		if ( Telex_Auth::is_connected() ) {
			\WP_CLI::log( __( 'Already connected to Telex.', 'dispatch' ) );
			return;
		}

		$flow = Telex_Auth::start_device_flow();
		if ( is_wp_error( $flow ) ) {
			\WP_CLI::error( $flow->get_error_message() );
		}

		\WP_CLI::log(
			sprintf(
			/* translators: 1: verification URL, 2: user code */
				__( "Visit %1\$s and enter code: %2\$s\n", 'dispatch' ),
				$flow['verification_uri'],
				$flow['user_code']
			)
		);

		$interval    = $flow['interval'];
		$expires     = time() + $flow['expires_in'];
		$device_code = (string) get_transient( Telex_Auth::TRANSIENT_DEVICE );

		\WP_CLI::log( __( 'Polling for authorization…', 'dispatch' ) );

		while ( time() < $expires ) {
			sleep( $interval );

			$result = Telex_Auth::poll_device_flow( $device_code );

			if ( is_wp_error( $result ) ) {
				\WP_CLI::error( $result->get_error_message() );
				return;
			}

			if ( true === $result ) {
				\WP_CLI::success( __( 'Connected to Telex!', 'dispatch' ) );
				return;
			}

			// Array with 'status' key; slow_down also carries a new 'interval' value.
			if ( 'slow_down' === ( $result['status'] ?? '' ) ) {
				$interval = $result['interval'] ?? ( $interval + 5 );
			}
		}

		\WP_CLI::error( __( 'Device code expired. Please try again.', 'dispatch' ) );
	}

	/**
	 * Disconnect this site from Telex.
	 *
	 * @subcommand disconnect
	 * @param array $_args       Positional arguments (unused).
	 * @param array $_assoc_args Associative arguments (unused).
	 */
	public function disconnect( array $_args, array $_assoc_args ): void {
		Telex_Auth::disconnect();
		\WP_CLI::success( __( 'Disconnected from Telex.', 'dispatch' ) );
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
	 * @param array $args        Positional arguments: [0] optional 'reset' subcommand.
	 * @param array $_assoc_args Associative arguments (unused).
	 */
	public function circuit( array $args, array $_assoc_args ): void {
		$sub = $args[0] ?? 'status';

		if ( 'reset' === $sub ) {
			Telex_Circuit_Breaker::reset();
			\WP_CLI::success( __( 'Circuit breaker reset to CLOSED.', 'dispatch' ) );
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
	 *     clear     Delete all Telex cached project data.
	 *
	 * @subcommand cache
	 * @param array $args        Positional arguments: [0] subcommand (e.g. 'clear').
	 * @param array $_assoc_args Associative arguments (unused).
	 */
	public function cache( array $args, array $_assoc_args ): void {
		$subcommand = $args[0] ?? '';

		if ( 'clear' === $subcommand ) {
			Telex_Cache::bust_all();
			\WP_CLI::success( __( 'Telex cache cleared.', 'dispatch' ) );
		} else {
			\WP_CLI::error(
				sprintf(
				/* translators: %s: unknown subcommand */
					__( 'Unknown cache subcommand: %s', 'dispatch' ),
					$subcommand
				)
			);
		}
	}
}
