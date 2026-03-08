/**
 * Unit tests for src/admin/store.js
 *
 * Covers: DEFAULT_STATE shape, every action creator's return value,
 * every reducer branch, and every selector.
 */
import {
	DEFAULT_STATE,
	actions,
	reducer,
	selectors,
} from '../../src/admin/store';

// ---------------------------------------------------------------------------
// DEFAULT_STATE
// ---------------------------------------------------------------------------

describe( 'DEFAULT_STATE', () => {
	it( 'has expected keys', () => {
		expect( DEFAULT_STATE ).toMatchObject( {
			projects: [],
			installedProjects: {},
			loading: true,
			error: null,
			authExpired: false,
			searchQuery: '',
			installing: {},
			confirmRemove: null,
			currentPage: 1,
			perPage: 24,
			installSteps: {},
		} );
	} );
} );

// ---------------------------------------------------------------------------
// Action creators
// ---------------------------------------------------------------------------

describe( 'actions', () => {
	it( 'setProjects returns correct action', () => {
		const projects = [ { publicId: 'p1' } ];
		expect( actions.setProjects( projects ) ).toEqual( {
			type: 'SET_PROJECTS',
			projects,
		} );
	} );

	it( 'setInstalledProjects returns correct action', () => {
		const installed = { p1: { version: '1.0' } };
		expect( actions.setInstalledProjects( installed ) ).toEqual( {
			type: 'SET_INSTALLED',
			installed,
		} );
	} );

	it( 'setLoading returns correct action', () => {
		expect( actions.setLoading( true ) ).toEqual( {
			type: 'SET_LOADING',
			loading: true,
		} );
	} );

	it( 'setError returns correct action', () => {
		expect( actions.setError( 'oops' ) ).toEqual( {
			type: 'SET_ERROR',
			error: 'oops',
		} );
	} );

	it( 'setAuthExpired returns correct action', () => {
		expect( actions.setAuthExpired( true ) ).toEqual( {
			type: 'SET_AUTH_EXPIRED',
			expired: true,
		} );
	} );

	it( 'setSearchQuery returns correct action', () => {
		expect( actions.setSearchQuery( 'hello' ) ).toEqual( {
			type: 'SET_SEARCH',
			query: 'hello',
		} );
	} );

	it( 'setInstallStatus returns correct action', () => {
		expect( actions.setInstallStatus( 'p1', 'installing' ) ).toEqual( {
			type: 'SET_INSTALL_STATUS',
			publicId: 'p1',
			status: 'installing',
		} );
	} );

	it( 'setConfirmRemove returns correct action', () => {
		expect( actions.setConfirmRemove( 'p1' ) ).toEqual( {
			type: 'SET_CONFIRM_REMOVE',
			publicId: 'p1',
		} );
	} );

	it( 'setCurrentPage returns correct action', () => {
		expect( actions.setCurrentPage( 3 ) ).toEqual( {
			type: 'SET_PAGE',
			page: 3,
		} );
	} );

	it( 'setInstallStep returns correct action', () => {
		expect( actions.setInstallStep( 'p1', 2 ) ).toEqual( {
			type: 'SET_INSTALL_STEP',
			publicId: 'p1',
			step: 2,
		} );
	} );

	it( 'clearInstallStep returns correct action', () => {
		expect( actions.clearInstallStep( 'p1' ) ).toEqual( {
			type: 'CLEAR_INSTALL_STEP',
			publicId: 'p1',
		} );
	} );
} );

// ---------------------------------------------------------------------------
// Reducer
// ---------------------------------------------------------------------------

describe( 'reducer', () => {
	it( 'returns DEFAULT_STATE for unknown action', () => {
		expect( reducer( undefined, { type: '@@INIT' } ) ).toEqual(
			DEFAULT_STATE
		);
	} );

	it( 'SET_PROJECTS replaces projects and resets page to 1', () => {
		const state = { ...DEFAULT_STATE, currentPage: 5 };
		const projects = [ { publicId: 'p1' }, { publicId: 'p2' } ];
		const next = reducer( state, { type: 'SET_PROJECTS', projects } );
		expect( next.projects ).toEqual( projects );
		expect( next.currentPage ).toBe( 1 );
	} );

	it( 'SET_INSTALLED updates installedProjects', () => {
		const installed = { p1: { version: '2.0' } };
		const next = reducer( DEFAULT_STATE, {
			type: 'SET_INSTALLED',
			installed,
		} );
		expect( next.installedProjects ).toEqual( installed );
	} );

	it( 'SET_LOADING updates loading flag', () => {
		const next = reducer( DEFAULT_STATE, {
			type: 'SET_LOADING',
			loading: true,
		} );
		expect( next.loading ).toBe( true );
	} );

	it( 'SET_ERROR updates error', () => {
		const next = reducer( DEFAULT_STATE, {
			type: 'SET_ERROR',
			error: 'network error',
		} );
		expect( next.error ).toBe( 'network error' );
	} );

	it( 'SET_AUTH_EXPIRED updates authExpired', () => {
		const next = reducer( DEFAULT_STATE, {
			type: 'SET_AUTH_EXPIRED',
			expired: true,
		} );
		expect( next.authExpired ).toBe( true );
	} );

	it( 'SET_SEARCH updates searchQuery and resets page', () => {
		const state = { ...DEFAULT_STATE, currentPage: 3 };
		const next = reducer( state, {
			type: 'SET_SEARCH',
			query: 'gutenberg',
		} );
		expect( next.searchQuery ).toBe( 'gutenberg' );
		expect( next.currentPage ).toBe( 1 );
	} );

	it( 'SET_INSTALL_STATUS merges status without affecting other entries', () => {
		const state = {
			...DEFAULT_STATE,
			installing: { p1: 'installing' },
		};
		const next = reducer( state, {
			type: 'SET_INSTALL_STATUS',
			publicId: 'p2',
			status: 'removing',
		} );
		expect( next.installing ).toEqual( {
			p1: 'installing',
			p2: 'removing',
		} );
	} );

	it( 'SET_CONFIRM_REMOVE updates confirmRemove', () => {
		const next = reducer( DEFAULT_STATE, {
			type: 'SET_CONFIRM_REMOVE',
			publicId: 'p1',
		} );
		expect( next.confirmRemove ).toBe( 'p1' );
	} );

	it( 'SET_PAGE updates currentPage', () => {
		const next = reducer( DEFAULT_STATE, { type: 'SET_PAGE', page: 7 } );
		expect( next.currentPage ).toBe( 7 );
	} );

	it( 'SET_INSTALL_STEP adds step for publicId', () => {
		const next = reducer( DEFAULT_STATE, {
			type: 'SET_INSTALL_STEP',
			publicId: 'p1',
			step: 3,
		} );
		expect( next.installSteps.p1 ).toBe( 3 );
	} );

	it( 'CLEAR_INSTALL_STEP removes only the targeted publicId', () => {
		const state = {
			...DEFAULT_STATE,
			installSteps: { p1: 2, p2: 4 },
		};
		const next = reducer( state, {
			type: 'CLEAR_INSTALL_STEP',
			publicId: 'p1',
		} );
		expect( next.installSteps ).toEqual( { p2: 4 } );
	} );
} );

// ---------------------------------------------------------------------------
// Selectors
// ---------------------------------------------------------------------------

describe( 'selectors', () => {
	const base = {
		...DEFAULT_STATE,
		projects: [ { publicId: 'p1', name: 'Foo' } ],
		installedProjects: { p1: { version: '1.2' } },
		loading: true,
		error: 'err',
		authExpired: true,
		searchQuery: 'foo',
		installing: { p1: 'installing' },
		confirmRemove: 'p1',
		currentPage: 4,
		perPage: 12,
		installSteps: { p1: 2 },
	};

	it( 'getProjects returns projects', () => {
		expect( selectors.getProjects( base ) ).toEqual( base.projects );
	} );

	it( 'getInstalledProjects returns installedProjects', () => {
		expect( selectors.getInstalledProjects( base ) ).toEqual(
			base.installedProjects
		);
	} );

	it( 'isLoading returns loading flag', () => {
		expect( selectors.isLoading( base ) ).toBe( true );
	} );

	it( 'getError returns error', () => {
		expect( selectors.getError( base ) ).toBe( 'err' );
	} );

	it( 'isAuthExpired returns authExpired', () => {
		expect( selectors.isAuthExpired( base ) ).toBe( true );
	} );

	it( 'getSearchQuery returns searchQuery', () => {
		expect( selectors.getSearchQuery( base ) ).toBe( 'foo' );
	} );

	it( 'getInstallStatus returns the status for a known publicId', () => {
		expect( selectors.getInstallStatus( base, 'p1' ) ).toBe( 'installing' );
	} );

	it( 'getInstallStatus returns "idle" for an unknown publicId', () => {
		expect( selectors.getInstallStatus( base, 'unknown' ) ).toBe( 'idle' );
	} );

	it( 'getConfirmRemove returns confirmRemove', () => {
		expect( selectors.getConfirmRemove( base ) ).toBe( 'p1' );
	} );

	it( 'getCurrentPage returns currentPage', () => {
		expect( selectors.getCurrentPage( base ) ).toBe( 4 );
	} );

	it( 'getPerPage returns perPage', () => {
		expect( selectors.getPerPage( base ) ).toBe( 12 );
	} );

	it( 'getInstallStep returns the step for a known publicId', () => {
		expect( selectors.getInstallStep( base, 'p1' ) ).toBe( 2 );
	} );

	it( 'getInstallStep returns null for an unknown publicId', () => {
		expect( selectors.getInstallStep( base, 'unknown' ) ).toBeNull();
	} );
} );
