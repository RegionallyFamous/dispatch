<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Immutable value object representing a Telex project as returned by the API.
 *
 * @phpstan-type ProjectArray array{publicId: string, name: string, slug: string, projectType: ?string, currentVersion: int}
 */
readonly class Telex_Project {

	public function __construct(
		public string      $public_id,
		public string      $name,
		public string      $slug,
		public ProjectType $type,
		public int         $current_version,
		public bool        $is_shared   = false,
		public bool        $is_owner    = false,
	) {}

	/**
	 * @param array<string, mixed> $data Raw API response array.
	 */
	public static function from_api( array $data ): self {
		return new self(
			public_id:       $data['publicId']      ?? '',
			name:            $data['name']           ?? '',
			slug:            $data['slug']           ?? '',
			type:            ProjectType::from_api( $data['projectType'] ?? null ),
			current_version: (int) ( $data['currentVersion'] ?? 0 ),
			is_shared:       (bool) ( $data['isShared'] ?? false ),
			is_owner:        (bool) ( $data['isOwner']  ?? false ),
		);
	}

	/** @return array<string, mixed> */
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

	public function __construct(
		public string $path,
		public int    $size  = 0,
		public string $sha256 = '',
	) {}

	/**
	 * @param array{path: string, size?: int, sha256?: string} $data
	 */
	public static function from_array( array $data ): self {
		return new self(
			path:   $data['path'],
			size:   (int) ( $data['size']   ?? 0 ),
			sha256: (string) ( $data['sha256'] ?? '' ),
		);
	}
}

/**
 * Immutable value object representing a Telex API credential pair.
 */
readonly class Telex_Api_Credentials {

	public function __construct(
		public string $token,
		public string $base_url,
		public int    $timeout = 15,
	) {}
}
