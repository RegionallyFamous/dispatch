<?php
/**
 * Data Transfer Objects for the Telex plugin.
 *
 * Contains Telex_Project, Telex_Build_File, and Telex_Api_Credentials.
 *
 * @package Dispatch_For_Telex
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Immutable value object representing a Telex project as returned by the API.
 *
 * @phpstan-type ProjectArray array{publicId: string, name: string, slug: string, projectType: ?string, currentVersion: int}
 */
readonly class Telex_Project {

	/**
	 * Creates a new Telex_Project instance.
	 *
	 * @param string      $public_id       The Telex project public ID.
	 * @param string      $name            The project display name.
	 * @param string      $slug            The WordPress plugin/theme slug.
	 * @param ProjectType $type            The project type (Block or Theme).
	 * @param int         $current_version The latest version number from the API.
	 * @param bool        $is_shared       Whether the project is shared from another account.
	 * @param bool        $is_owner        Whether the authenticated user owns the project.
	 */
	public function __construct(
		public string $public_id,
		public string $name,
		public string $slug,
		public ProjectType $type,
		public int $current_version,
		public bool $is_shared = false,
		public bool $is_owner = false,
	) {}

	/**
	 * Constructs a Telex_Project from a raw API response array.
	 *
	 * @param array<string, mixed> $data Raw API response array.
	 * @return self
	 */
	public static function from_api( array $data ): self {
		return new self(
			public_id:       $data['publicId'] ?? '',
			name:            $data['name'] ?? '',
			slug:            $data['slug'] ?? '',
			type:            ProjectType::from_api( $data['projectType'] ?? null ),
			current_version: (int) ( $data['currentVersion'] ?? 0 ),
			is_shared:       (bool) ( $data['isShared'] ?? false ),
			is_owner:        (bool) ( $data['isOwner'] ?? false ),
		);
	}

	/**
	 * Serializes the DTO to an associative array for JSON responses.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return [
			'publicId'       => $this->public_id,
			'name'           => $this->name,
			'slug'           => $this->slug,
			'projectType'    => $this->type->value,
			'currentVersion' => $this->current_version,
			'isShared'       => $this->is_shared,
			'isOwner'        => $this->is_owner,
		];
	}
}

/**
 * Immutable value object representing a single file in a Telex build.
 */
readonly class Telex_Build_File {

	/**
	 * Creates a new Telex_Build_File instance.
	 *
	 * @param string $path   Relative path of the file within the build.
	 * @param int    $size   File size in bytes.
	 * @param string $sha256 SHA-256 hex digest for integrity verification.
	 */
	public function __construct(
		public string $path,
		public int $size = 0,
		public string $sha256 = '',
	) {}

	/**
	 * Constructs a Telex_Build_File from an array returned in the build manifest.
	 *
	 * @param array{path: string, size?: int, sha256?: string} $data Build manifest entry.
	 * @return self
	 */
	public static function from_array( array $data ): self {
		return new self(
			path:   $data['path'],
			size:   (int) ( $data['size'] ?? 0 ),
			sha256: (string) ( $data['sha256'] ?? '' ),
		);
	}
}

/**
 * Immutable value object representing a Telex API credential pair.
 */
readonly class Telex_Api_Credentials {

	/**
	 * Creates a new Telex_Api_Credentials instance.
	 *
	 * @param string $token    The OAuth bearer token.
	 * @param string $base_url The Telex API base URL.
	 * @param int    $timeout  Request timeout in seconds.
	 */
	public function __construct(
		public string $token,
		public string $base_url,
		public int $timeout = 15,
	) {}
}
