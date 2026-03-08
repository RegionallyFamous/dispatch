<?php
/**
 * Block usage analytics — tracks which installed blocks are in active use.
 *
 * @package Dispatch_For_Telex
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Scans post content via parse_blocks() to count how many posts use each
 * installed Telex block. Results are cached in a transient and refreshed
 * weekly by a WP Cron job.
 *
 * Why this matters: knowing that "Animated Logo is used in 47 posts" lets
 * users understand the blast radius of removing or updating a project. The
 * Remove confirmation modal uses this data to surface a prominent warning
 * when usage_count > 0.
 */
class Telex_Analytics {

	private const TRANSIENT_KEY = 'telex_block_usage';
	public const CRON_HOOK      = 'telex_analytics_scan';

	/**
	 * Maximum number of posts scanned in a single analytics pass.
	 *
	 * Overridable via the TELEX_ANALYTICS_MAX_POSTS constant so operators with
	 * large installs can raise or lower the limit without touching plugin code.
	 * Set to 0 to disable the cap (not recommended on large databases).
	 */
	private const DEFAULT_MAX_POSTS = 5000;

	/**
	 * Returns the cached usage data, or an empty result if no scan has run yet.
	 *
	 * @return array{scanned_at: string|null, usage: array<string, array{usage_count: int, post_ids: int[]}>}
	 */
	public static function get_cached(): array {
		$cached = get_transient( self::TRANSIENT_KEY );

		if ( is_array( $cached ) ) {
			/**
			 * Narrowed transient type.
			 *
			 * @phpstan-var array{scanned_at: string|null, usage: array<string, array{usage_count: int, post_ids: int[]}>} $cached
			 */
			return $cached;
		}

		return [
			'scanned_at' => null,
			'usage'      => [],
		];
	}

	/**
	 * Scans all post content and tallies block usage per installed Telex project.
	 *
	 * Runs async via WP Cron (weekly). Safe to call manually via the REST endpoint
	 * with force_scan=true.
	 *
	 * @return void
	 */
	public static function scan(): void {
		// Only scan when there are installed projects to check.
		$installed = Telex_Tracker::get_all();
		if ( empty( $installed ) ) {
			return;
		}

		// Build a slug → publicId map so we can match block namespaces.
		$slug_map = [];
		foreach ( $installed as $id => $info ) {
			$slug = (string) ( $info['slug'] ?? '' );
			if ( '' !== $slug ) {
				$slug_map[ $slug ] = $id;
			}
		}

		// Retrieve all published post types that can contain blocks.
		$max_posts = defined( 'TELEX_ANALYTICS_MAX_POSTS' )
			? (int) TELEX_ANALYTICS_MAX_POSTS
			: self::DEFAULT_MAX_POSTS;

		$query_args = [
			'post_status' => [ 'publish', 'draft', 'private' ],
			'post_type'   => 'any',
			'fields'      => 'ids',
		];

		if ( $max_posts > 0 ) {
			$query_args['posts_per_page'] = $max_posts;
		} else {
			// Uncapped scan — only safe on small databases; deliberately opted into.
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_post_type -- uncapped scan opted in via constant.
			$query_args['posts_per_page'] = -1;
		}

		// phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_post_type -- intentional full scan.
		$posts = get_posts( $query_args );
		// phpcs:enable WordPress.DB.SlowDBQuery.slow_db_query_post_type

		// Warn if the cap was hit — results may be incomplete.
		if ( $max_posts > 0 && count( $posts ) >= $max_posts ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_trigger_error,WordPress.Security.EscapeOutput.OutputNotEscaped
			trigger_error(
				sprintf(
					'Dispatch analytics scan reached the %d-post limit (TELEX_ANALYTICS_MAX_POSTS). Block usage counts may be incomplete. Increase the constant or set it to 0 to scan all posts.',
					(int) $max_posts
				),
				E_USER_NOTICE
			);
		}

		// Tally: publicId → [ usage_count, post_ids[] ]
		$usage = array_fill_keys(
			array_keys( $installed ),
			[
				'usage_count' => 0,
				'post_ids'    => [],
			]
		);

		foreach ( $posts as $post_id ) {
			$content = get_post_field( 'post_content', (int) $post_id );
			if ( ! is_string( $content ) || '' === $content ) {
				continue;
			}

			$blocks = parse_blocks( $content );
			$used   = self::collect_block_names( $blocks );

			foreach ( $used as $block_name ) {
				// Block names are namespace/block-name. The namespace typically
				// matches the project slug registered in Telex.
				$parts = explode( '/', $block_name, 2 );
				$ns    = $parts[0] ?? '';
				if ( '' === $ns ) {
					continue;
				}

				// Try exact slug match first.
				$public_id = $slug_map[ $ns ] ?? null;

				// Fallback: match any installed project whose slug starts with the namespace.
				if ( null === $public_id ) {
					foreach ( $slug_map as $slug => $pid ) {
						if ( str_starts_with( $slug, $ns ) || str_starts_with( $ns, $slug ) ) {
							$public_id = $pid;
							break;
						}
					}
				}

				if ( null !== $public_id && isset( $usage[ $public_id ] ) ) {
					++$usage[ $public_id ]['usage_count'];
					if ( ! in_array( (int) $post_id, $usage[ $public_id ]['post_ids'], true ) ) {
						$usage[ $public_id ]['post_ids'][] = (int) $post_id;
					}
				}
			}
		}

		$result = [
			'scanned_at' => gmdate( 'c' ),
			'usage'      => $usage,
		];

		set_transient( self::TRANSIENT_KEY, $result, WEEK_IN_SECONDS );
	}

	/**
	 * Recursively collects all block names from a parsed block tree.
	 *
	 * @param array<array-key, mixed> $blocks Parsed block array from parse_blocks().
	 * @return string[]
	 */
	private static function collect_block_names( array $blocks ): array {
		$names = [];
		foreach ( $blocks as $block ) {
			$block_name = (string) ( $block['blockName'] ?? '' );
			if ( '' !== $block_name ) {
				$names[] = $block_name;
			}
			$inner = $block['innerBlocks'] ?? [];
			if ( ! empty( $inner ) ) {
				$names = array_merge( $names, self::collect_block_names( (array) $inner ) );
			}
		}
		return $names;
	}
}
