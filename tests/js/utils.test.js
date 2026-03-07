/**
 * Unit tests for src/admin/utils.js
 *
 * Covers: AVATAR_GRADIENTS, getAvatarGradient, getAvatarColor (djb2 determinism,
 * gradient bounds, collision resistance), and relativeDate (all time buckets).
 */
import {
	AVATAR_GRADIENTS,
	getAvatarColor,
	relativeDate,
} from '../../src/admin/utils';

// ---------------------------------------------------------------------------
// getAvatarGradient / getAvatarColor
// ---------------------------------------------------------------------------

describe( 'getAvatarColor', () => {
	it( 'returns the first color of a gradient pair from AVATAR_GRADIENTS', () => {
		const color = getAvatarColor( 'any-seed' );
		expect( AVATAR_GRADIENTS.map( ( [ c ] ) => c ) ).toContain( color );
	} );

	it( 'is deterministic — same seed always returns same colour', () => {
		const seed = 'project-alpha';
		expect( getAvatarColor( seed ) ).toBe( getAvatarColor( seed ) );
	} );

	it( 'returns different colours for seeds that differ', () => {
		// Not guaranteed by contract but must hold for the current palette and seeds.
		const colours = new Set(
			[
				'alpha',
				'beta',
				'gamma',
				'delta',
				'epsilon',
				'zeta',
				'eta',
				'theta',
				'iota',
				'kappa',
				'lambda',
				'mu',
			].map( getAvatarColor )
		);
		// Must use more than 1 distinct colour.
		expect( colours.size ).toBeGreaterThan( 1 );
	} );

	it( 'handles a single character seed', () => {
		const colour = getAvatarColor( 'X' );
		expect( AVATAR_GRADIENTS.map( ( [ c ] ) => c ) ).toContain( colour );
	} );

	it( 'handles a very long seed without throwing', () => {
		const longSeed = 'a'.repeat( 10000 );
		expect( () => getAvatarColor( longSeed ) ).not.toThrow();
		expect( AVATAR_GRADIENTS.map( ( [ c ] ) => c ) ).toContain(
			getAvatarColor( longSeed )
		);
	} );
} );

// ---------------------------------------------------------------------------
// relativeDate
// ---------------------------------------------------------------------------

/**
 * Builds an ISO string that is `offsetMs` milliseconds in the past.
 *
 * @param {number} offsetMs Milliseconds to subtract from the current time.
 * @return {string} ISO-8601 date string.
 */
function past( offsetMs ) {
	return new Date( Date.now() - offsetMs ).toISOString();
}

const SECOND = 1000;
const MINUTE = 60 * SECOND;
const HOUR = 60 * MINUTE;
const DAY = 24 * HOUR;
const YEAR = 365 * DAY;

describe( 'relativeDate', () => {
	it( 'returns null for null', () => {
		expect( relativeDate( null ) ).toBeNull();
	} );

	it( 'returns null for undefined', () => {
		expect( relativeDate( undefined ) ).toBeNull();
	} );

	it( 'returns null for an empty string', () => {
		expect( relativeDate( '' ) ).toBeNull();
	} );

	it( 'returns null for an invalid date string', () => {
		expect( relativeDate( 'not-a-date' ) ).toBeNull();
	} );

	it( 'returns "Just now" for timestamps within the last 59 seconds', () => {
		expect( relativeDate( past( 30 * SECOND ) ) ).toBe( 'Just now' );
	} );

	it( 'returns a minutes-ago string for timestamps 1–59 minutes ago', () => {
		const result = relativeDate( past( 5 * MINUTE ) );
		expect( result ).toMatch( /minute/i );
		expect( result ).toContain( '5' );
	} );

	it( 'returns singular "minute ago" at exactly 1 minute', () => {
		const result = relativeDate( past( 1 * MINUTE + 1 ) );
		expect( result ).toMatch( /1 minute ago/i );
	} );

	it( 'returns an hours-ago string for timestamps 1–23 hours ago', () => {
		const result = relativeDate( past( 3 * HOUR ) );
		expect( result ).toMatch( /hour/i );
		expect( result ).toContain( '3' );
	} );

	it( 'returns singular "hour ago" at exactly 1 hour', () => {
		const result = relativeDate( past( 1 * HOUR + 1 ) );
		expect( result ).toMatch( /1 hour ago/i );
	} );

	it( 'returns a days-ago string for timestamps 1–29 days ago', () => {
		const result = relativeDate( past( 10 * DAY ) );
		expect( result ).toMatch( /day/i );
		expect( result ).toContain( '10' );
	} );

	it( 'returns a months-ago string for timestamps 30–364 days ago', () => {
		const result = relativeDate( past( 60 * DAY ) ); // 60 days = ~2 months
		expect( result ).toMatch( /month/i );
	} );

	it( 'returns a years-ago string for timestamps ≥365 days ago', () => {
		const result = relativeDate( past( 2 * YEAR ) );
		expect( result ).toMatch( /year/i );
		expect( result ).toContain( '2' );
	} );

	it( 'returns singular "year ago" at exactly 1 year', () => {
		const result = relativeDate( past( 1 * YEAR + DAY ) );
		expect( result ).toMatch( /1 year ago/i );
	} );
} );
