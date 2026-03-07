/**
 * Shared utility functions for the Dispatch admin UI.
 *
 * Kept separate so they can be imported by both the main entry point
 * and the test suite without pulling in React or @wordpress/data.
 */
import { __, sprintf, _n } from '@wordpress/i18n';

/**
 * Curated gradient pairs for project avatars.
 * Each entry is [startColor, endColor] applied top-left → bottom-right.
 * All pairs are dark enough to carry white text at 16–17px bold.
 */
export const AVATAR_GRADIENTS = [
	[ '#4f5fea', '#7c3aed' ], // blue → purple
	[ '#0891b2', '#0ea5e9' ], // cyan → sky
	[ '#e65054', '#c67c0d' ], // coral → amber
	[ '#7e3bd0', '#db2777' ], // purple → pink
	[ '#0073aa', '#0891b2' ], // wp-blue → cyan
	[ '#059669', '#0f766e' ], // emerald → teal
	[ '#be123c', '#7e3bd0' ], // crimson → purple
	[ '#1e40af', '#2563eb' ], // navy → blue
	[ '#6d28d9', '#4f5fea' ], // violet → blue
	[ '#065f46', '#0369a1' ], // dark-green → blue
	[ '#9f1239', '#e65054' ], // rose → coral
	[ '#334155', '#0073aa' ], // slate → wp-blue
];

/**
 * djb2 hash — non-cryptographic, fast, well-distributed.
 *
 * @param {string} seed Input string.
 * @return {number} 32-bit integer hash.
 */
export function djb2( seed ) {
	let hash = 0;
	for ( let i = 0; i < seed.length; i++ ) {
		// eslint-disable-next-line no-bitwise
		hash = ( ( hash << 5 ) - hash + seed.charCodeAt( i ) ) | 0;
	}
	return hash;
}

/**
 * Returns a deterministic gradient pair from AVATAR_GRADIENTS for a seed string.
 *
 * @param {string} seed Any non-empty string (typically the project publicId).
 * @return {string[]} [startColor, endColor] hex pair.
 */
export function getAvatarGradient( seed ) {
	return AVATAR_GRADIENTS[
		Math.abs( djb2( seed ) ) % AVATAR_GRADIENTS.length
	];
}

/**
 * Legacy single-colour accessor — kept for backward compatibility with tests.
 *
 * @param {string} seed Any non-empty string.
 * @return {string} A hex colour value.
 */
export function getAvatarColor( seed ) {
	return getAvatarGradient( seed )[ 0 ];
}

/**
 * Human-readable relative timestamp from an ISO-8601 string.
 * Returns null if the input is missing or unparseable.
 *
 * @param {string|null|undefined} isoString ISO-8601 date string.
 * @return {string|null} A localised relative string, or null for invalid input.
 */
export function relativeDate( isoString ) {
	if ( ! isoString ) {
		return null;
	}
	const d = new Date( isoString );
	if ( isNaN( d.getTime() ) ) {
		return null;
	}
	const seconds = Math.floor( ( Date.now() - d.getTime() ) / 1000 );
	if ( seconds < 60 ) {
		return __( 'Just now', 'dispatch' );
	}
	const minutes = Math.floor( seconds / 60 );
	if ( minutes < 60 ) {
		return sprintf(
			/* translators: %d: number of minutes */
			_n( '%d minute ago', '%d minutes ago', minutes, 'dispatch' ),
			minutes
		);
	}
	const hours = Math.floor( minutes / 60 );
	if ( hours < 24 ) {
		return sprintf(
			/* translators: %d: number of hours */
			_n( '%d hour ago', '%d hours ago', hours, 'dispatch' ),
			hours
		);
	}
	const days = Math.floor( hours / 24 );
	if ( days < 30 ) {
		return sprintf(
			/* translators: %d: number of days */
			_n( '%d day ago', '%d days ago', days, 'dispatch' ),
			days
		);
	}
	const months = Math.floor( days / 30 );
	if ( months < 12 ) {
		return sprintf(
			/* translators: %d: number of months */
			_n( '%d month ago', '%d months ago', months, 'dispatch' ),
			months
		);
	}
	const years = Math.floor( months / 12 );
	return sprintf(
		/* translators: %d: number of years */
		_n( '%d year ago', '%d years ago', years, 'dispatch' ),
		years
	);
}
