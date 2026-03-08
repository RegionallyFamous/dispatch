<?php
/**
 * WordPress Updates screen integration for Telex projects.
 *
 * @package Dispatch_For_Telex
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Integrates Telex updates with the native WordPress Updates screen.
 *
 * Hooks:
 *  - pre_set_site_transient_update_plugins / update_themes  — inject update entries
 *  - plugins_api                                             — provide plugin info modal data
 *  - after_plugin_row_{file}                                 — show "Update via Telex" row notice
 */
class Telex_Updater {

	/**
	 * Registers all update-related filters and row action hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_filter( 'pre_set_site_transient_update_plugins', self::inject_plugin_updates( ... ) );
		add_filter( 'pre_set_site_transient_update_themes', self::inject_theme_updates( ... ) );
		add_filter( 'plugins_api', self::plugins_api_info( ... ), 10, 3 );
		add_filter( 'upgrader_pre_download', self::intercept_telex_upgrade( ... ), 10, 4 );

		// Register after_plugin_row_* notices for each tracked block.
		foreach ( Telex_Tracker::get_all() as $public_id => $info ) {
			if ( ProjectType::from( $info['type'] ) !== ProjectType::Block ) {
				continue;
			}
			$plugin_file = self::find_plugin_file( $info['slug'] );
			if ( $plugin_file ) {
				add_action(
					'after_plugin_row_' . $plugin_file,
					static fn( string $file, array $_data ) => self::render_plugin_row_notice( $public_id, $info, $file ), // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
					10,
					2
				);
			}
		}
	}

	// -------------------------------------------------------------------------
	// Update injection
	// -------------------------------------------------------------------------

	/**
	 * Injects Telex block updates into the WordPress plugin update transient.
	 *
	 * @param object $transient The site transient object for plugin updates.
	 * @return object
	 */
	public static function inject_plugin_updates( object $transient ): object {
		/**
		 * WordPress always initialises `response` on this transient before calling the filter.
		 *
		 * @phpstan-var object{response: array<string, mixed>, checked?: array<string, string>} $transient
		 */
		if ( ! Telex_Auth::is_connected() ) {
			return $transient;
		}

		$installed = Telex_Tracker::get_all();
		if ( empty( $installed ) ) {
			return $transient;
		}

		$client = Telex_Auth::get_client();
		if ( ! $client ) {
			return $transient;
		}

		// Pre-seed per-project caches from the bulk list to avoid N+1 API calls.
		self::prime_project_caches( $installed );

		foreach ( $installed as $public_id => $info ) {
			if ( ProjectType::from( $info['type'] ) !== ProjectType::Block ) {
				continue;
			}

			try {
				$remote = Telex_Cache::get_project( $public_id );
				if ( null === $remote ) {
					$remote = $client->projects->get( $public_id );
					Telex_Cache::set_project( $public_id, $remote );
				}

				$remote_version = (int) ( $remote['currentVersion'] ?? 0 );
				if ( ! Telex_Tracker::needs_update( $public_id, $remote_version ) ) {
					continue;
				}

				$plugin_file = self::find_plugin_file( $info['slug'] );
				if ( ! $plugin_file ) {
					continue;
				}

				// Inject a pseudo-update package pointing to our REST install endpoint.
				// @phpstan-ignore assign.propertyReadOnly
				$transient->response[ $plugin_file ] = (object) [
					'id'          => 'telex/' . $public_id,
					'slug'        => $info['slug'],
					'plugin'      => $plugin_file,
					'new_version' => 'v' . $remote_version,
					'url'         => TELEX_PUBLIC_URL,
					'package'     => '', // Empty — Telex_Updater handles the actual install via WP_Upgrader.
				];
			} catch ( \Exception ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement -- Per-project failures are silenced so remaining update checks proceed normally.
				// Don't disrupt the update check for one bad project.
			}
		}

		return $transient;
	}

	/**
	 * Injects Telex theme updates into the WordPress theme update transient.
	 *
	 * @param object $transient The site transient object for theme updates.
	 * @return object
	 */
	public static function inject_theme_updates( object $transient ): object {
		/**
		 * WordPress always initialises `response` on this transient before calling the filter.
		 *
		 * @phpstan-var object{response: array<string, mixed>} $transient
		 */
		if ( ! Telex_Auth::is_connected() ) {
			return $transient;
		}

		$installed = Telex_Tracker::get_all();
		$client    = Telex_Auth::get_client();

		if ( empty( $installed ) || ! $client ) {
			return $transient;
		}

		// Pre-seed per-project caches from the bulk list to avoid N+1 API calls.
		self::prime_project_caches( $installed );

		foreach ( $installed as $public_id => $info ) {
			if ( ProjectType::from( $info['type'] ) !== ProjectType::Theme ) {
				continue;
			}

			try {
				$remote = Telex_Cache::get_project( $public_id );
				if ( null === $remote ) {
					$remote = $client->projects->get( $public_id );
					Telex_Cache::set_project( $public_id, $remote );
				}

				$remote_version = (int) ( $remote['currentVersion'] ?? 0 );
				if ( ! Telex_Tracker::needs_update( $public_id, $remote_version ) ) {
					continue;
				}

				// @phpstan-ignore assign.propertyReadOnly
				$transient->response[ $info['slug'] ] = [
					'theme'       => $info['slug'],
					'new_version' => 'v' . $remote_version,
					'url'         => TELEX_PUBLIC_URL,
					'package'     => '',
				];
			} catch ( \Exception ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement -- Per-project failures are silenced so remaining update checks proceed normally.
				// Don't disrupt the update check for one bad project.
			}
		}

		return $transient;
	}

	// -------------------------------------------------------------------------
	// plugins_api filter — "View version details" modal
	// -------------------------------------------------------------------------

	/**
	 * Provides plugin info for the "View version details" modal in the Updates screen.
	 *
	 * @param false|object $result The current result (false to indicate no data yet).
	 * @param string       $action The plugins_api action being performed.
	 * @param object       $args   Arguments including the plugin slug.
	 * @return false|object
	 */
	public static function plugins_api_info( false|object $result, string $action, object $args ): false|object {
		if ( 'plugin_information' !== $action ) {
			return $result;
		}

		$slug = $args->slug ?? '';
		if ( empty( $slug ) ) {
			return $result;
		}

		// Match tracked projects by slug.
		foreach ( Telex_Tracker::get_all() as $public_id => $info ) {
			if ( $info['slug'] !== $slug ) {
				continue;
			}

			// Use the per-project cache when warm — avoids a live API round-trip
			// every time the "View version details" modal is opened.
			$remote = Telex_Cache::get_project( $public_id );
			if ( null === $remote ) {
				$client = Telex_Auth::get_client();
				if ( ! $client ) {
					return $result;
				}

				try {
					$remote = $client->projects->get( $public_id );
					Telex_Cache::set_project( $public_id, $remote );
				} catch ( \Exception ) {
					return $result;
				}
			}

			$version = (int) ( $remote['currentVersion'] ?? 0 );

			return (object) [
				'name'              => $remote['name'] ?? $slug,
				'slug'              => $slug,
				'version'           => 'v' . $version,
				'author'            => 'Telex',
				'homepage'          => TELEX_PUBLIC_URL,
				'short_description' => sprintf(
					/* translators: %s: project name */
					__( '%s — managed by Dispatch for Telex', 'dispatch' ),
					$remote['name'] ?? $slug
				),
				'sections'          => [
					'description' => sprintf(
						/* translators: %s: public ID */
						__( 'Managed by Dispatch. Telex project ID: %s', 'dispatch' ),
						esc_html( $public_id )
					),
				],
				'download_link'     => '',
			];
		}

		return $result;
	}

	// -------------------------------------------------------------------------
	// Per-plugin row notice
	// -------------------------------------------------------------------------

	/**
	 * Renders an update-available notice below a plugin row in the Plugins list table.
	 *
	 * @param string               $public_id   The Telex project public ID.
	 * @param array<string, mixed> $info        The tracker entry for this project.
	 * @param string               $plugin_file The plugin file path relative to wp-content/plugins/.
	 * @return void
	 */
	public static function render_plugin_row_notice( string $public_id, array $info, string $plugin_file ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		// is_connected() is a lightweight option read — avoids decrypting the token
		// just to check whether we should render a notice.
		if ( ! Telex_Auth::is_connected() ) {
			return;
		}

		$remote         = Telex_Cache::get_project( $public_id );
		$remote_version = (int) ( $remote['currentVersion'] ?? 0 );

		if ( ! Telex_Tracker::needs_update( $public_id, $remote_version ) ) {
			return;
		}

		// The plugins list table always has 3 visible columns (checkbox, name, description+actions).
		$cols = 3;

		echo '<tr class="plugin-update-tr active">';
		printf( '<td colspan="%d" class="plugin-update colspanchange">', (int) $cols );
		echo '<div class="update-message notice inline notice-warning notice-alt"><p>';
		printf(
			/* translators: 1: version number, 2: update URL */
			wp_kses_post( __( 'Update available! v%1$s is ready in Dispatch. <a href="%2$s">Update now</a>.', 'dispatch' ) ),
			esc_html( (string) $remote_version ),
			esc_url( admin_url( 'admin.php?page=telex' ) )
		);
		echo '</p></div></td></tr>';
	}

	// -------------------------------------------------------------------------
	// upgrader_pre_download — block native WP upgrades for Telex projects
	// -------------------------------------------------------------------------

	/**
	 * Intercepts any attempt to run the native WP upgrader on a Telex-managed
	 * plugin or theme. Because Dispatch injects update entries with an empty
	 * `package` URL, clicking "Update" from the WordPress Updates screen would
	 * otherwise fail with a generic "No package specified" error.
	 *
	 * Instead we return a descriptive WP_Error that tells the user to use
	 * the Dispatch screen, which is the only supported update path.
	 *
	 * @param false|\WP_Error      $reply      Pass-through value; false means "proceed normally".
	 * @param string               $package    The download URL (empty for Telex projects).
	 * @param \WP_Upgrader         $upgrader   The upgrader instance (unused).
	 * @param array<string, mixed> $hook_extra Context: type, plugin/theme slug, action.
	 * @return false|\WP_Error
	 */
	public static function intercept_telex_upgrade( false|\WP_Error $reply, string $package, \WP_Upgrader $upgrader, array $hook_extra ): false|\WP_Error { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundInImplementedInterfaceAfterLastUsed
		// Only intercept when the package is empty (our injected entries always are).
		if ( '' !== $package || false !== $reply ) {
			return $reply;
		}

		$type = $hook_extra['type'] ?? '';
		$slug = '';

		if ( 'plugin' === $type && isset( $hook_extra['plugin'] ) ) {
			$parts = explode( '/', (string) $hook_extra['plugin'] );
			$slug  = $parts[0];
		} elseif ( 'theme' === $type && isset( $hook_extra['theme'] ) ) {
			$slug = (string) $hook_extra['theme'];
		}

		if ( '' === $slug || null === Telex_Tracker::get_by_slug( $slug ) ) {
			return $reply;
		}

		return new \WP_Error(
			'telex_use_dispatch',
			wp_kses(
				sprintf(
					/* translators: %s: URL to the Dispatch admin page */
					__( 'This project is managed by Dispatch for Telex. <a href="%s">Update it from the Dispatch screen.</a>', 'dispatch' ),
					esc_url( admin_url( 'admin.php?page=telex' ) )
				),
				[ 'a' => [ 'href' => true ] ]
			)
		);
	}

	// -------------------------------------------------------------------------
	// Helper
	// -------------------------------------------------------------------------

	/**
	 * Seeds per-project caches from the warm bulk project list so that the
	 * per-project loops avoid N individual API calls on a cold cache.
	 *
	 * @param array<string, mixed> $installed Tracked projects keyed by public ID.
	 * @return void
	 */
	public static function prime_project_caches( array $installed ): void {
		$bulk = Telex_Cache::get_projects();
		if ( ! is_array( $bulk ) ) {
			return;
		}
		foreach ( $bulk as $project ) {
			$id = $project['publicId'] ?? '';
			if ( $id && isset( $installed[ $id ] ) && null === Telex_Cache::get_project( $id ) ) {
				Telex_Cache::set_project( $id, $project );
			}
		}
	}

	/**
	 * Returns the plugin file path (e.g. 'my-plugin/my-plugin.php') for a given slug.
	 *
	 * @param string $slug The WordPress plugin directory slug.
	 * @return string Plugin file path, or empty string if not found.
	 */
	private static function find_plugin_file( string $slug ): string {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		foreach ( get_plugins() as $file => $data ) {
			if ( str_starts_with( $file, $slug . '/' ) ) {
				return $file;
			}
		}
		return '';
	}
}
