<?php
/**
 * ProjectType enum — represents the type of a Telex project.
 *
 * @package Dispatch_For_Telex
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Backed enum representing the type of a Telex project.
 */
enum ProjectType: string {
	case Block = 'block';
	case Theme = 'theme';

	/**
	 * Derive from the raw projectType string returned by the Telex API.
	 *
	 * @param string|null $project_type Raw type string from the API response.
	 * @return self
	 */
	public static function from_api( ?string $project_type ): self {
		if ( $project_type && str_contains( strtolower( $project_type ), 'theme' ) ) {
			return self::Theme;
		}
		return self::Block;
	}

	/**
	 * Returns the WordPress capability required to install this project type.
	 *
	 * @return string
	 */
	public function install_capability(): string {
		return match ( $this ) {
			self::Block => 'install_plugins',
			self::Theme => 'install_themes',
		};
	}

	/**
	 * Returns the WordPress capability required to remove this project type.
	 *
	 * @return string
	 */
	public function remove_capability(): string {
		return match ( $this ) {
			self::Block => 'delete_plugins',
			self::Theme => 'delete_themes',
		};
	}

	/**
	 * Returns the human-readable label for this project type.
	 *
	 * @return string
	 */
	public function label(): string {
		return match ( $this ) {
			self::Block => __( 'Block', 'dispatch' ),
			self::Theme => __( 'Theme', 'dispatch' ),
		};
	}
}
