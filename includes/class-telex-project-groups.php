<?php
/**
 * Project groups — named per-user collections of Telex projects.
 *
 * @package Dispatch_For_Telex
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages per-user project group collections stored in user_meta.
 *
 * Groups are user-scoped (not site-wide). Each group is a named collection
 * of project public IDs. Users can assign any number of projects to any group
 * and filter the admin UI by group.
 *
 * Storage: user_meta key 'telex_user_groups' → JSON-encoded array of group objects:
 *   [{ id: string, name: string, project_ids: string[], created_at: string }]
 */
class Telex_Project_Groups {

	private const META_KEY = 'telex_user_groups';

	/**
	 * Returns all groups for the current user.
	 *
	 * @return array<int, array{id: string, name: string, project_ids: string[], created_at: string}>
	 */
	public static function get_for_user(): array {
		$raw    = get_user_meta( get_current_user_id(), self::META_KEY, true );
		$groups = is_array( $raw ) ? $raw : [];
		return array_values( $groups );
	}

	/**
	 * Returns all group IDs a given project belongs to for the current user.
	 *
	 * @param string $public_id Project public ID.
	 * @return string[]
	 */
	public static function get_group_ids_for_project( string $public_id ): array {
		$groups = self::get_for_user();
		$ids    = [];
		foreach ( $groups as $g ) {
			if ( in_array( $public_id, (array) ( $g['project_ids'] ?? [] ), true ) ) {
				$ids[] = $g['id'];
			}
		}
		return $ids;
	}

	/**
	 * Creates a new group and returns the new group object.
	 *
	 * @param string $name Group display name.
	 * @return array{id: string, name: string, project_ids: string[], created_at: string}
	 */
	public static function create( string $name ): array {
		$groups   = self::get_for_user();
		$group    = [
			'id'          => wp_generate_uuid4(),
			'name'        => sanitize_text_field( $name ),
			'project_ids' => [],
			'created_at'  => gmdate( 'c' ),
		];
		$groups[] = $group;
		self::save( $groups );
		return $group;
	}

	/**
	 * Renames a group and returns the updated group, or null if not found.
	 *
	 * @param string $id   Group UUID.
	 * @param string $name New display name.
	 * @return array{id: string, name: string, project_ids: string[], created_at: string}|null
	 */
	public static function update( string $id, string $name ): ?array {
		$groups  = self::get_for_user();
		$updated = null;

		foreach ( $groups as &$g ) {
			if ( $g['id'] === $id ) {
				$g['name'] = sanitize_text_field( $name );
				$updated   = $g;
				break;
			}
		}
		unset( $g );

		if ( null !== $updated ) {
			self::save( $groups );
		}

		return $updated;
	}

	/**
	 * Deletes a group. Returns true on success, false if not found.
	 *
	 * @param string $id Group UUID.
	 * @return bool
	 */
	public static function delete( string $id ): bool {
		$groups  = self::get_for_user();
		$initial = count( $groups );

		$groups = array_values( array_filter( $groups, static fn( $g ) => $g['id'] !== $id ) );

		if ( count( $groups ) === $initial ) {
			return false;
		}

		self::save( $groups );
		return true;
	}

	/**
	 * Adds a project to a group and returns the updated group, or null if not found.
	 *
	 * @param string $id         Group UUID.
	 * @param string $project_id Project public ID.
	 * @return array{id: string, name: string, project_ids: string[], created_at: string}|null
	 */
	public static function add_project( string $id, string $project_id ): ?array {
		$groups  = self::get_for_user();
		$updated = null;

		foreach ( $groups as &$g ) {
			if ( $g['id'] === $id ) {
				if ( ! in_array( $project_id, (array) $g['project_ids'], true ) ) {
					$g['project_ids'][] = $project_id;
				}
				$updated = $g;
				break;
			}
		}
		unset( $g );

		if ( null !== $updated ) {
			self::save( $groups );
		}

		return $updated;
	}

	/**
	 * Removes a project from a group and returns the updated group, or null if not found.
	 *
	 * @param string $id         Group UUID.
	 * @param string $project_id Project public ID.
	 * @return array{id: string, name: string, project_ids: string[], created_at: string}|null
	 */
	public static function remove_project( string $id, string $project_id ): ?array {
		$groups  = self::get_for_user();
		$updated = null;

		foreach ( $groups as &$g ) {
			if ( $g['id'] === $id ) {
				$g['project_ids'] = array_values(
					array_filter( (array) $g['project_ids'], static fn( $p ) => $p !== $project_id )
				);
				$updated          = $g;
				break;
			}
		}
		unset( $g );

		if ( null !== $updated ) {
			self::save( $groups );
		}

		return $updated;
	}

	/**
	 * Persists the groups array to user_meta.
	 *
	 * @param array<int, array{id: string, name: string, project_ids: string[], created_at: string}> $groups Groups array.
	 * @return void
	 */
	private static function save( array $groups ): void {
		update_user_meta( get_current_user_id(), self::META_KEY, array_values( $groups ) );
	}
}
