<?php

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
	 */
	public static function from_api( ?string $project_type ): self {
		if ( $project_type && str_contains( strtolower( $project_type ), 'theme' ) ) {
			return self::Theme;
		}
		return self::Block;
	}

	public function install_capability(): string {
		return match ( $this ) {
			self::Block => 'install_plugins',
			self::Theme => 'install_themes',
		};
	}

	public function remove_capability(): string {
		return match ( $this ) {
			self::Block => 'delete_plugins',
			self::Theme => 'delete_themes',
		};
	}

	public function label(): string {
		return match ( $this ) {
			self::Block => __( 'Block', 'telex' ),
			self::Theme => __( 'Theme', 'telex' ),
		};
	}
}
