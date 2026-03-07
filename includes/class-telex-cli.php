<?php

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
	 */
	public function list( array $args, array $assoc_args ): void {
		if ( ! Telex_Auth::is_connected() ) {
			\WP_CLI::error( __( 'Not connected to Telex. Run: wp telex connect', 'telex' ) );
		}

		$client = Telex_Auth::get_client();
		if ( ! $client ) {
			\WP_CLI::error( __( 'Could not initialise Telex client.', 'telex' ) );
		}

		try {
			$response = $client->projects->list( [ 'perPage' => 100 ] );
			$projects = $response['projects'] ?? [];
		} catch ( \Exception $e ) {
			\WP_CLI::error( $e->getMessage() );
		}

		if ( empty( $projects ) ) {
			\WP_CLI::log( __( 'No projects found.', 'telex' ) );
			return;
		}

		$installed = Telex_Tracker::get_all();

		$rows = array_map( static function ( array $p ) use ( $installed ): array {
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
		}, $projects );

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
	 */
	public function install( array $args, array $assoc_args ): void {
		$public_id = $args[0] ?? '';
		if ( empty( $public_id ) ) {
			\WP_CLI::error( __( 'Please provide a project ID.', 'telex' ) );
		}

		$activate = isset( $assoc_args['activate'] );

		\WP_CLI::log( sprintf(
			/* translators: %s: project ID */
			__( 'Installing project %s…', 'telex' ),
			$public_id
		) );

		$result = Telex_Installer::install( $public_id, $activate );

		if ( is_wp_error( $result ) ) {
			\WP_CLI::error( $result->get_error_message() );
		}

		\WP_CLI::success( sprintf(
			/* translators: %s: project ID */
			__( 'Project %s installed successfully.', 'telex' ),
			$public_id
		) );
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
	 */
	public function update( array $args, array $assoc_args ): void {
		$update_all = isset( $assoc_args['all'] );
		$public_id  = $args[0] ?? '';

		if ( ! $update_all && empty( $public_id ) ) {
			\WP_CLI::error( __( 'Provide a project ID or use --all.', 'telex' ) );
		}

		if ( $update_all ) {
			$installed = Telex_Tracker::get_all();
			$client    = Telex_Auth::get_client();

			if ( ! $client ) {
				\WP_CLI::error( __( 'Could not initialise Telex client.', 'telex' ) );
			}

			$to_update = [];
			foreach ( $installed as $id => $info ) {
				try {
					$remote         = $client->projects->get( $id );
					$remote_version = (int) ( $remote['currentVersion'] ?? 0 );
					if ( Telex_Tracker::needs_update( $id, $remote_version ) ) {
						$to_update[] = $id;
					}
				} catch ( \Exception ) {
				}
			}

			if ( empty( $to_update ) ) {
				\WP_CLI::log( __( 'All projects are up to date.', 'telex' ) );
				return;
			}

			$progress = \WP_CLI\Utils\make_progress_bar(
				__( 'Updating projects', 'telex' ),
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
			\WP_CLI::success( sprintf(
				/* translators: %d: count */
				__( 'Updated %d project(s).', 'telex' ),
				count( $to_update )
			) );
		} else {
			$result = Telex_Installer::install( $public_id );
			if ( is_wp_error( $result ) ) {
				\WP_CLI::error( $result->get_error_message() );
			}
			\WP_CLI::success( sprintf(
				/* translators: %s: project ID */
				__( 'Project %s updated.', 'telex' ),
				$public_id
			) );
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
	 */
	public function remove( array $args, array $assoc_args ): void {
		$public_id = $args[0] ?? '';
		if ( empty( $public_id ) ) {
			\WP_CLI::error( __( 'Please provide a project ID.', 'telex' ) );
		}

		\WP_CLI::confirm(
			sprintf(
				/* translators: %s: project ID */
				__( 'Remove project %s?', 'telex' ),
				$public_id
			),
			$assoc_args
		);

		$result = Telex_Installer::remove( $public_id );

		if ( is_wp_error( $result ) ) {
			\WP_CLI::error( $result->get_error_message() );
		}

		\WP_CLI::success( sprintf(
			/* translators: %s: project ID */
			__( 'Project %s removed.', 'telex' ),
			$public_id
		) );
	}

	/**
	 * Connect this site to Telex (starts the device flow).
	 *
	 * @subcommand connect
	 */
	public function connect( array $args, array $assoc_args ): void {
		if ( Telex_Auth::is_connected() ) {
			\WP_CLI::log( __( 'Already connected to Telex.', 'telex' ) );
			return;
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
			\WP_CLI::error( $response->get_error_message() );
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( empty( $body['device_code'] ) ) {
			\WP_CLI::error( $body['message'] ?? __( 'Failed to start device flow.', 'telex' ) );
		}

		set_transient( Telex_Auth::TRANSIENT_DEVICE, $body['device_code'], (int) $body['expires_in'] );

		\WP_CLI::log( sprintf(
			/* translators: %s: user code */
			__( "Visit %s and enter code: %s\n", 'telex' ),
			$body['verification_uri'],
			$body['user_code']
		) );

		$interval = (int) ( $body['interval'] ?? 5 );
		$expires  = time() + (int) ( $body['expires_in'] ?? 300 );

		\WP_CLI::log( __( 'Polling for authorization…', 'telex' ) );

		while ( time() < $expires ) {
			sleep( $interval );

			$poll = wp_remote_post(
				TELEX_DEVICE_AUTH_URL . '/token',
				[
					'headers' => [ 'Content-Type' => 'application/json' ],
					'body'    => wp_json_encode( [ 'device_code' => $body['device_code'] ] ),
					'timeout' => 15,
				]
			);

			if ( is_wp_error( $poll ) ) {
				continue;
			}

			$poll_code = (int) wp_remote_retrieve_response_code( $poll );
			$poll_body = json_decode( wp_remote_retrieve_body( $poll ), true );

			if ( 200 === $poll_code && ! empty( $poll_body['access_token'] ) ) {
				Telex_Auth::store_token( $poll_body['access_token'] );
				delete_transient( Telex_Auth::TRANSIENT_DEVICE );
				\WP_CLI::success( __( 'Connected to Telex!', 'telex' ) );
				return;
			}

			$error = $poll_body['error'] ?? '';

			if ( 'slow_down' === $error ) {
				$interval += 5;
			} elseif ( 'authorization_pending' !== $error ) {
				\WP_CLI::error( $poll_body['error_description'] ?? $error );
				return;
			}
		}

		\WP_CLI::error( __( 'Device code expired. Please try again.', 'telex' ) );
	}

	/**
	 * Disconnect this site from Telex.
	 *
	 * @subcommand disconnect
	 */
	public function disconnect( array $args, array $assoc_args ): void {
		Telex_Auth::disconnect();
		\WP_CLI::success( __( 'Disconnected from Telex.', 'telex' ) );
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
	 */
	public function circuit( array $args, array $assoc_args ): void {
		$sub = $args[0] ?? 'status';

		if ( 'reset' === $sub ) {
			Telex_Circuit_Breaker::reset();
			\WP_CLI::success( __( 'Circuit breaker reset to CLOSED.', 'telex' ) );
			return;
		}

		$status = Telex_Circuit_Breaker::status();
		$color  = match ( $status ) {
			'closed'    => '%G',
			'half-open' => '%Y',
			'open'      => '%R',
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
	 */
	public function cache( array $args, array $assoc_args ): void {
		$subcommand = $args[0] ?? '';

		if ( 'clear' === $subcommand ) {
			Telex_Cache::bust_all();
			\WP_CLI::success( __( 'Telex cache cleared.', 'telex' ) );
		} else {
			\WP_CLI::error( sprintf(
				/* translators: %s: unknown subcommand */
				__( 'Unknown cache subcommand: %s', 'telex' ),
				$subcommand
			) );
		}
	}
}
