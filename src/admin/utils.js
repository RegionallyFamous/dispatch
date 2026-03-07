/**
 * Shared utility functions for the Dispatch admin UI.
 *
 * Kept separate so they can be imported by both the main entry point
 * and the test suite without pulling in React or @wordpress/data.
 */
import { __, sprintf, _n } from '@wordpress/i18n';

/**
 * Colour palette for project avatar circles.
 * Each project deterministically maps to one colour based on its publicId.
 */
export const AVATAR_PALETTE = [
	'#2271b1', // WP admin blue
	'#1e8b3a', // green
	'#d63638', // red
	'#7e3bd0', // purple
	'#c67c0d', // amber
	'#007cba', // teal-blue
	'#e65054', // coral
	'#0ea5e9', // sky
	'#8b5cf6', // violet
	'#059669', // emerald
];

/**
 * Returns a deterministic colour from AVATAR_PALETTE for a seed string.
 * Uses the djb2 hash so the same seed always produces the same colour.
 *
 * @param {string} seed Any non-empty string (typically the project publicId).
 * @return {string} A hex colour value from AVATAR_PALETTE.
 */
export function getAvatarColor( seed ) {
	let hash = 0;
	for ( let i = 0; i < seed.length; i++ ) {
		// djb2 hash — non-cryptographic, fast, well-distributed.
		// eslint-disable-next-line no-bitwise
		hash = ( ( hash << 5 ) - hash + seed.charCodeAt( i ) ) | 0;
	}
	return AVATAR_PALETTE[ Math.abs( hash ) % AVATAR_PALETTE.length ];
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
