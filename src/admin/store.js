/**
 * Redux store definition for the Dispatch admin UI.
 *
 * Isolated from the React entry point so the reducer, actions, and selectors
 * can be unit-tested independently. Registered with @wordpress/data via
 * createReduxStore() in the entry point.
 */

/** @type {string} The registered store name. */
export const STORE_NAME = 'telex/admin';

/**
 * Default Redux state for the Dispatch admin store.
 *
 * @type {Object}
 */
export const DEFAULT_STATE = {
	projects: /** @type {Array} */ ( [] ),
	installedProjects: /** @type {Object} */ ( {} ),
	loading: false,
	error: /** @type {string|null} */ ( null ),
	authExpired: false,
	searchQuery: '',
	installing: /** @type {Object} */ ( {} ),
	confirmRemove: /** @type {string|null} */ ( null ),
	currentPage: 1,
	perPage: 24,
	installSteps: /** @type {Object} */ ( {} ),
};

// ---------------------------------------------------------------------------
// Action creators
// ---------------------------------------------------------------------------
export const actions = {
	/**
	 * @param {Array} projects Project list from the API.
	 * @return {Object} Redux action.
	 */
	setProjects: ( projects ) => ( { type: 'SET_PROJECTS', projects } ),
	/**
	 * @param {Object} installed Map of publicId to install metadata.
	 * @return {Object} Redux action.
	 */
	setInstalledProjects: ( installed ) => ( {
		type: 'SET_INSTALLED',
		installed,
	} ),
	/**
	 * @param {boolean} loading Whether a network request is in flight.
	 * @return {Object} Redux action.
	 */
	setLoading: ( loading ) => ( { type: 'SET_LOADING', loading } ),
	/**
	 * @param {string|null} error Error message to display, or null to clear.
	 * @return {Object} Redux action.
	 */
	setError: ( error ) => ( { type: 'SET_ERROR', error } ),
	/**
	 * @param {boolean} expired Whether the auth token has expired.
	 * @return {Object} Redux action.
	 */
	setAuthExpired: ( expired ) => ( { type: 'SET_AUTH_EXPIRED', expired } ),
	/**
	 * @param {string} query Search query string.
	 * @return {Object} Redux action.
	 */
	setSearchQuery: ( query ) => ( { type: 'SET_SEARCH', query } ),
	/**
	 * @param {string} publicId Project public ID.
	 * @param {string} status   Install status string.
	 * @return {Object} Redux action.
	 */
	setInstallStatus: ( publicId, status ) => ( {
		type: 'SET_INSTALL_STATUS',
		publicId,
		status,
	} ),
	/**
	 * @param {string} publicId Project public ID to confirm for removal.
	 * @return {Object} Redux action.
	 */
	setConfirmRemove: ( publicId ) => ( {
		type: 'SET_CONFIRM_REMOVE',
		publicId,
	} ),
	/**
	 * @param {number} page Page number (1-indexed).
	 * @return {Object} Redux action.
	 */
	setCurrentPage: ( page ) => ( { type: 'SET_PAGE', page } ),
	/**
	 * @param {string} publicId Project public ID.
	 * @param {number} step     Install progress step number (1–4).
	 * @return {Object} Redux action.
	 */
	setInstallStep: ( publicId, step ) => ( {
		type: 'SET_INSTALL_STEP',
		publicId,
		step,
	} ),
	/**
	 * @param {string} publicId Project public ID whose step to clear.
	 * @return {Object} Redux action.
	 */
	clearInstallStep: ( publicId ) => ( {
		type: 'CLEAR_INSTALL_STEP',
		publicId,
	} ),
};

// ---------------------------------------------------------------------------
// Reducer
// ---------------------------------------------------------------------------

/**
 * Dispatch admin Redux reducer.
 *
 * @param {Object} state  Current state.
 * @param {Object} action Dispatched action.
 * @return {Object} Next state.
 */
export function reducer( state = DEFAULT_STATE, action ) {
	switch ( action.type ) {
		case 'SET_PROJECTS':
			return { ...state, projects: action.projects, currentPage: 1 };
		case 'SET_INSTALLED':
			return { ...state, installedProjects: action.installed };
		case 'SET_LOADING':
			return { ...state, loading: action.loading };
		case 'SET_ERROR':
			return { ...state, error: action.error };
		case 'SET_AUTH_EXPIRED':
			return { ...state, authExpired: action.expired };
		case 'SET_SEARCH':
			return { ...state, searchQuery: action.query, currentPage: 1 };
		case 'SET_INSTALL_STATUS':
			return {
				...state,
				installing: {
					...state.installing,
					[ action.publicId ]: action.status,
				},
			};
		case 'SET_CONFIRM_REMOVE':
			return { ...state, confirmRemove: action.publicId };
		case 'SET_PAGE':
			return { ...state, currentPage: action.page };
		case 'SET_INSTALL_STEP':
			return {
				...state,
				installSteps: {
					...state.installSteps,
					[ action.publicId ]: action.step,
				},
			};
		case 'CLEAR_INSTALL_STEP': {
			const { [ action.publicId ]: _removed, ...remainingSteps } =
				state.installSteps;
			return { ...state, installSteps: remainingSteps };
		}
		default:
			return state;
	}
}

// ---------------------------------------------------------------------------
// Selectors
// ---------------------------------------------------------------------------
export const selectors = {
	/**
	 * @param {Object} state Current store state.
	 * @return {Array} The project list.
	 */
	getProjects: ( state ) => state.projects,
	/**
	 * @param {Object} state Current store state.
	 * @return {Object} Map of installed project metadata.
	 */
	getInstalledProjects: ( state ) => state.installedProjects,
	/**
	 * @param {Object} state Current store state.
	 * @return {boolean} Whether a network request is in flight.
	 */
	isLoading: ( state ) => state.loading,
	/**
	 * @param {Object} state Current store state.
	 * @return {string|null} Current error message, or null.
	 */
	getError: ( state ) => state.error,
	/**
	 * @param {Object} state Current store state.
	 * @return {boolean} Whether the auth token has expired.
	 */
	isAuthExpired: ( state ) => state.authExpired,
	/**
	 * @param {Object} state Current store state.
	 * @return {string} Current search query string.
	 */
	getSearchQuery: ( state ) => state.searchQuery,
	/**
	 * @param {Object} state    Current store state.
	 * @param {string} publicId Project public ID to check.
	 * @return {string} Install status string ('idle', 'installing', 'removing', 'failed').
	 */
	getInstallStatus: ( state, publicId ) =>
		state.installing[ publicId ] || 'idle',
	/**
	 * @param {Object} state Current store state.
	 * @return {string|null} Public ID of the project awaiting removal confirmation.
	 */
	getConfirmRemove: ( state ) => state.confirmRemove,
	/**
	 * @param {Object} state Current store state.
	 * @return {number} Current pagination page (1-indexed).
	 */
	getCurrentPage: ( state ) => state.currentPage,
	/**
	 * @param {Object} state Current store state.
	 * @return {number} Number of items displayed per page.
	 */
	getPerPage: ( state ) => state.perPage,
	/**
	 * @param {Object} state    Current store state.
	 * @param {string} publicId Project public ID to check.
	 * @return {number|null} Current install step (1–4), or null if not installing.
	 */
	getInstallStep: ( state, publicId ) =>
		state.installSteps[ publicId ] ?? null,
};
