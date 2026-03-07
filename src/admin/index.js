/**
 * Telex Admin — Projects Page
 *
 * Renders the project card grid with tab-based filtering, stats summary,
 * and live refetch (no full-page reload). State is managed via @wordpress/data.
 */
import { render, useEffect, useCallback, Component } from '@wordpress/element';
import {
	createReduxStore,
	register,
	useDispatch,
	useSelect,
} from '@wordpress/data';
import {
	Button,
	Card,
	CardBody,
	CardHeader,
	ExternalLink,
	Modal,
	Notice,
	SearchControl,
	Spinner,
	TabPanel,
	Tooltip,
	Icon,
} from '@wordpress/components';
import { __, sprintf, _n } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import {
	update as updateIcon,
	trash,
	check,
	plugins as pluginsIcon,
	layout as layoutIcon,
	search as searchIcon,
	download,
} from '@wordpress/icons';

// ---------------------------------------------------------------------------
// Utilities
// ---------------------------------------------------------------------------

/**
 * Deterministic color from a seed string.
 * Each project always gets the same color across page loads.
 */
const AVATAR_PALETTE = [
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

function getAvatarColor( seed ) {
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
 * @return {string|null} Localised relative string, or null.
 */
function relativeDate( isoString ) {
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

// ---------------------------------------------------------------------------
// Project avatar
// ---------------------------------------------------------------------------

/**
 * Coloured initial-letter circle, deterministic from the project's publicId.
 * Gives each card a unique identity at a glance without any API changes.
 *
 * @param {Object} root0          Component props.
 * @param {string} root0.name     Project display name.
 * @param {string} root0.publicId Telex project public ID (used as colour seed).
 * @return {import('@wordpress/element').WPElement} Avatar element.
 */
function ProjectAvatar( { name, publicId } ) {
	const color = getAvatarColor( publicId || name );
	const initial = ( name || '?' ).charAt( 0 ).toUpperCase();
	return (
		<span
			className="telex-project-avatar"
			style={ { background: color } }
			aria-hidden="true"
		>
			{ initial }
		</span>
	);
}

// ---------------------------------------------------------------------------
// Store
// ---------------------------------------------------------------------------

// Per-page preference: PHP passes the saved screen option via data-per-page.
const _adminContainer = document.getElementById( 'telex-projects-app' );
const _perPageAttr = parseInt( _adminContainer?.dataset?.perPage, 10 );
const INITIAL_PER_PAGE =
	! isNaN( _perPageAttr ) && _perPageAttr > 0 ? _perPageAttr : 24;

const DEFAULT_STATE = {
	projects: [],
	installedProjects: {}, // publicId → { version, type, … }
	loading: false,
	error: null,
	authExpired: false,
	searchQuery: '',
	installing: {}, // publicId → 'installing' | 'removing' | 'idle' | 'failed'
	notice: null,
	confirmRemove: null, // publicId awaiting confirmation
	currentPage: 1,
	perPage: INITIAL_PER_PAGE,
	selectedProjects: [], // publicIds of projects selected for bulk install
	bulkInstalling: false,
	installSteps: {}, // publicId → step number (1–4) during install
};

const actions = {
	setProjects: ( projects ) => ( { type: 'SET_PROJECTS', projects } ),
	setInstalledProjects: ( installed ) => ( {
		type: 'SET_INSTALLED',
		installed,
	} ),
	setLoading: ( loading ) => ( { type: 'SET_LOADING', loading } ),
	setError: ( error ) => ( { type: 'SET_ERROR', error } ),
	setAuthExpired: ( expired ) => ( { type: 'SET_AUTH_EXPIRED', expired } ),
	setSearchQuery: ( query ) => ( { type: 'SET_SEARCH', query } ),
	setInstallStatus: ( publicId, status ) => ( {
		type: 'SET_INSTALL_STATUS',
		publicId,
		status,
	} ),
	setNotice: ( notice ) => ( { type: 'SET_NOTICE', notice } ),
	clearNotice: () => ( { type: 'SET_NOTICE', notice: null } ),
	setConfirmRemove: ( publicId ) => ( {
		type: 'SET_CONFIRM_REMOVE',
		publicId,
	} ),
	setCurrentPage: ( page ) => ( { type: 'SET_PAGE', page } ),
	toggleSelection: ( publicId ) => ( { type: 'TOGGLE_SELECTION', publicId } ),
	clearSelection: () => ( { type: 'CLEAR_SELECTION' } ),
	selectAllUninstalled: ( publicIds ) => ( {
		type: 'SELECT_ALL_UNINSTALLED',
		publicIds,
	} ),
	setBulkInstalling: ( installing ) => ( {
		type: 'SET_BULK_INSTALLING',
		installing,
	} ),
	setInstallStep: ( publicId, step ) => ( {
		type: 'SET_INSTALL_STEP',
		publicId,
		step,
	} ),
	clearInstallStep: ( publicId ) => ( {
		type: 'CLEAR_INSTALL_STEP',
		publicId,
	} ),
};

function reducer( state = DEFAULT_STATE, action ) {
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
		case 'SET_NOTICE':
			return { ...state, notice: action.notice };
		case 'SET_CONFIRM_REMOVE':
			return { ...state, confirmRemove: action.publicId };
		case 'SET_PAGE':
			return { ...state, currentPage: action.page };
		case 'TOGGLE_SELECTION': {
			const exists = state.selectedProjects.includes( action.publicId );
			return {
				...state,
				selectedProjects: exists
					? state.selectedProjects.filter(
							( id ) => id !== action.publicId
					  )
					: [ ...state.selectedProjects, action.publicId ],
			};
		}
		case 'CLEAR_SELECTION':
			return { ...state, selectedProjects: [] };
		case 'SELECT_ALL_UNINSTALLED':
			return { ...state, selectedProjects: action.publicIds };
		case 'SET_BULK_INSTALLING':
			return { ...state, bulkInstalling: action.installing };
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

const store = createReduxStore( 'telex/admin', {
	reducer,
	actions,
	selectors: {
		getProjects: ( state ) => state.projects,
		getInstalledProjects: ( state ) => state.installedProjects,
		isLoading: ( state ) => state.loading,
		getError: ( state ) => state.error,
		isAuthExpired: ( state ) => state.authExpired,
		getSearchQuery: ( state ) => state.searchQuery,
		getInstallStatus: ( state, publicId ) =>
			state.installing[ publicId ] || 'idle',
		getNotice: ( state ) => state.notice,
		getConfirmRemove: ( state ) => state.confirmRemove,
		getCurrentPage: ( state ) => state.currentPage,
		getPerPage: ( state ) => state.perPage,
		getSelectedProjects: ( state ) => state.selectedProjects,
		isBulkInstalling: ( state ) => state.bulkInstalling,
		getInstallStep: ( state, publicId ) =>
			state.installSteps[ publicId ] ?? null,
	},
} );

register( store );

// ---------------------------------------------------------------------------
// Install progress steps
// ---------------------------------------------------------------------------

const INSTALL_STEPS = [
	__( 'Downloading build files…', 'dispatch' ),
	__( 'Validating package…', 'dispatch' ),
	__( 'Installing…', 'dispatch' ),
	__( 'Activating…', 'dispatch' ),
];

function InstallProgress( { currentStep } ) {
	return (
		<div className="telex-install-progress" aria-live="polite">
			{ INSTALL_STEPS.map( ( label, idx ) => {
				const stepNum = idx + 1;
				const isDone = stepNum < currentStep;
				const isActive = stepNum === currentStep;
				return (
					<div
						key={ label }
						className={ [
							'telex-install-step',
							isDone ? 'telex-install-step--done' : '',
							isActive ? 'telex-install-step--active' : '',
						]
							.filter( Boolean )
							.join( ' ' ) }
					>
						{ isActive ? (
							<Spinner />
						) : (
							<span
								className="telex-install-step__dot"
								aria-hidden="true"
							/>
						) }
						{ label }
					</div>
				);
			} ) }
		</div>
	);
}

// ---------------------------------------------------------------------------
// Bulk install toolbar
// ---------------------------------------------------------------------------

function BulkToolbar( { selectedCount, onInstall, onClear, isBusy } ) {
	return (
		<div className="telex-bulk-toolbar" role="toolbar">
			<span className="telex-bulk-toolbar__count">
				{ sprintf(
					/* translators: %d: number of selected projects */
					_n(
						'%d project selected',
						'%d projects selected',
						selectedCount,
						'dispatch'
					),
					selectedCount
				) }
			</span>
			<Button
				variant="primary"
				onClick={ onInstall }
				disabled={ isBusy }
				isBusy={ isBusy }
				__next40pxDefaultSize
			>
				{ sprintf(
					/* translators: %d: number of projects to install */
					_n(
						'Install %d project',
						'Install %d projects',
						selectedCount,
						'dispatch'
					),
					selectedCount
				) }
			</Button>
			<Button
				variant="tertiary"
				onClick={ onClear }
				disabled={ isBusy }
				__next40pxDefaultSize
			>
				{ __( 'Clear selection', 'dispatch' ) }
			</Button>
		</div>
	);
}

// ---------------------------------------------------------------------------
// Pagination controls
// ---------------------------------------------------------------------------

function PaginationControls( {
	currentPage,
	totalPages,
	totalItems,
	perPage,
	onPageChange,
} ) {
	if ( totalPages <= 1 ) {
		return null;
	}

	const start = ( currentPage - 1 ) * perPage + 1;
	const end = Math.min( currentPage * perPage, totalItems );

	return (
		<div className="telex-pagination">
			<span className="telex-pagination__info">
				{ sprintf(
					/* translators: 1: first item number, 2: last item number, 3: total */
					__( '%1$d\u2013%2$d of %3$d', 'dispatch' ),
					start,
					end,
					totalItems
				) }
			</span>
			<div className="telex-pagination__buttons">
				<Button
					variant="secondary"
					onClick={ () => onPageChange( currentPage - 1 ) }
					disabled={ currentPage === 1 }
					__next40pxDefaultSize
				>
					{ __( '\u2039 Previous', 'dispatch' ) }
				</Button>
				<Button
					variant="secondary"
					onClick={ () => onPageChange( currentPage + 1 ) }
					disabled={ currentPage === totalPages }
					__next40pxDefaultSize
				>
					{ __( 'Next \u203a', 'dispatch' ) }
				</Button>
			</div>
		</div>
	);
}

// ---------------------------------------------------------------------------
// Stats bar
// ---------------------------------------------------------------------------

function StatsBar( { projects, installedProjects } ) {
	const installedCount = projects.filter(
		( p ) => installedProjects[ p.publicId ]
	).length;

	const updatesCount = projects.filter( ( p ) => {
		const inst = installedProjects[ p.publicId ];
		return inst && p.currentVersion > inst.version;
	} ).length;

	return (
		<div
			className="telex-stats-bar"
			aria-label={ __( 'Projects summary', 'dispatch' ) }
		>
			<div className="telex-stat">
				<span className="telex-stat__value">{ projects.length }</span>
				<span className="telex-stat__label">
					{ __( 'Projects', 'dispatch' ) }
				</span>
			</div>
			<div className="telex-stat">
				<span className="telex-stat__value">{ installedCount }</span>
				<span className="telex-stat__label">
					{ __( 'Installed', 'dispatch' ) }
				</span>
			</div>
			<div
				className={ `telex-stat${
					updatesCount > 0 ? ' telex-stat--has-updates' : ''
				}` }
			>
				<span className="telex-stat__value">{ updatesCount }</span>
				<span className="telex-stat__label">
					{ __( 'Updates', 'dispatch' ) }
				</span>
			</div>
		</div>
	);
}

// ---------------------------------------------------------------------------
// Type badge
// ---------------------------------------------------------------------------

function TypeBadge( { type } ) {
	const isTheme = type === 'theme';
	return (
		<span
			className={ `telex-type-badge ${
				isTheme ? 'telex-type-badge--theme' : 'telex-type-badge--block'
			}` }
		>
			<Icon icon={ isTheme ? layoutIcon : pluginsIcon } size={ 10 } />
			{ isTheme ? __( 'Theme', 'dispatch' ) : __( 'Block', 'dispatch' ) }
		</span>
	);
}

// ---------------------------------------------------------------------------
// Status badge
// ---------------------------------------------------------------------------

function StatusBadge( { publicId, remoteVersion, installed } ) {
	const installStatus = useSelect( ( select ) =>
		select( 'telex/admin' ).getInstallStatus( publicId )
	);

	if (
		installStatus === 'building' ||
		installStatus === 'installing' ||
		installStatus === 'removing'
	) {
		const busyLabels = {
			building: __( 'Building…', 'dispatch' ),
			installing: __( 'Installing…', 'dispatch' ),
			removing: __( 'Removing…', 'dispatch' ),
		};
		const busyLabel = busyLabels[ installStatus ];
		return (
			<span
				className="telex-badge telex-badge--loading"
				aria-live="polite"
			>
				<Spinner />
				{ busyLabel }
			</span>
		);
	}

	if ( ! installed ) {
		return (
			<span className="telex-badge telex-badge--idle">
				{ __( 'Not installed', 'dispatch' ) }
			</span>
		);
	}

	if ( remoteVersion > installed.version ) {
		return (
			<span className="telex-badge telex-badge--update">
				<Icon icon={ updateIcon } size={ 10 } />
				{ sprintf(
					/* translators: 1: installed version, 2: available version */
					__( 'v%1$s → v%2$s', 'dispatch' ),
					installed.version,
					remoteVersion
				) }
			</span>
		);
	}

	return (
		<span className="telex-badge telex-badge--installed">
			<Icon icon={ check } size={ 10 } />
			{ __( 'Up to date', 'dispatch' ) }
		</span>
	);
}

// ---------------------------------------------------------------------------
// Empty state
// ---------------------------------------------------------------------------

function EmptyState( { tab, searchQuery } ) {
	if ( searchQuery ) {
		return (
			<div className="telex-empty-state">
				<div className="telex-empty-state__icon">
					<Icon icon={ searchIcon } size={ 32 } />
				</div>
				<h3>{ __( 'No matches', 'dispatch' ) }</h3>
				<p>
					{ sprintf(
						/* translators: %s: search query */
						__(
							'Nothing matched "%s". Give something else a try.',
							'dispatch'
						),
						searchQuery
					) }
				</p>
			</div>
		);
	}

	const stateMap = {
		all: {
			icon: pluginsIcon,
			heading: __( 'Nothing here yet', 'dispatch' ),
			body: __(
				'Head over to Telex and create a project — it will show up right here.',
				'dispatch'
			),
		},
		updates: {
			icon: check,
			heading: __( "You're all caught up!", 'dispatch' ),
			body: __(
				'Every installed project is on the latest version. Nice work.',
				'dispatch'
			),
		},
		blocks: {
			icon: pluginsIcon,
			heading: __( 'No block projects yet', 'dispatch' ),
			body: __(
				"You haven't built any block projects in Telex yet.",
				'dispatch'
			),
		},
		themes: {
			icon: layoutIcon,
			heading: __( 'No theme projects yet', 'dispatch' ),
			body: __(
				"You haven't built any theme projects in Telex yet.",
				'dispatch'
			),
		},
	};

	const state = stateMap[ tab ] || stateMap.all;

	return (
		<div className="telex-empty-state">
			<div className="telex-empty-state__icon">
				<Icon icon={ state.icon } size={ 32 } />
			</div>
			<h3>{ state.heading }</h3>
			<p>{ state.body }</p>
		</div>
	);
}

// ---------------------------------------------------------------------------
// Shared install helper (used by both ProjectCard and bulk-install)
// ---------------------------------------------------------------------------

/**
 * POST /install, then poll /build if the server signals the build isn't ready yet.
 * Resolves when the install is confirmed, rejects with a user-facing error message.
 *
 * @param {Function} fetch      apiFetch bound with the correct middleware.
 * @param {string}   url        REST base URL.
 * @param {string}   publicId   Project public ID.
 * @param {Function} onBuilding Called with poll_interval when the build starts.
 * @return {Promise<Object>} Final install response data.
 */
async function installWithPolling( fetch, url, publicId, onBuilding ) {
	const doInstall = () =>
		fetch( {
			url: `${ url }/projects/${ publicId }/install`,
			method: 'POST',
			data: { activate: false },
		} );

	let data = await doInstall();

	if ( data.status !== 'building' ) {
		return data;
	}

	// Server queued a build — poll until ready then retry.
	onBuilding?.( data.poll_interval || 5 );

	const MAX_POLLS = 24; // ~2 minutes at the default 5 s interval
	let pollInterval = data.poll_interval || 5;

	for ( let attempt = 0; attempt < MAX_POLLS; attempt++ ) {
		// eslint-disable-next-line no-await-in-loop
		await new Promise( ( r ) => setTimeout( r, pollInterval * 1000 ) );

		// eslint-disable-next-line no-await-in-loop
		const buildStatus = await fetch( {
			url: `${ url }/projects/${ publicId }/build`,
		} );

		if ( buildStatus.poll_interval ) {
			pollInterval = buildStatus.poll_interval;
		}

		if ( ! buildStatus.ready ) {
			continue;
		}

		// eslint-disable-next-line no-await-in-loop
		data = await doInstall();
		return data;
	}

	throw new Error(
		__(
			'The build is taking longer than expected. Try again in a moment.',
			'dispatch'
		)
	);
}

// ---------------------------------------------------------------------------
// Project card
// ---------------------------------------------------------------------------

function ProjectCard( { project, restUrl, onRefresh } ) {
	const {
		setInstallStatus,
		setNotice,
		setConfirmRemove,
		toggleSelection,
		setInstallStep,
		clearInstallStep,
	} = useDispatch( 'telex/admin' );
	const installedProjects = useSelect( ( select ) =>
		select( 'telex/admin' ).getInstalledProjects()
	);
	const confirmRemove = useSelect( ( select ) =>
		select( 'telex/admin' ).getConfirmRemove()
	);
	const installStatus = useSelect( ( select ) =>
		select( 'telex/admin' ).getInstallStatus( project.publicId )
	);
	const installStep = useSelect( ( select ) =>
		select( 'telex/admin' ).getInstallStep( project.publicId )
	);
	const selectedProjects = useSelect( ( select ) =>
		select( 'telex/admin' ).getSelectedProjects()
	);
	const isSelected = selectedProjects.includes( project.publicId );

	const installed = installedProjects[ project.publicId ];
	const needsUpdate = installed && project.currentVersion > installed.version;
	const isInstalled = !! installed;
	const isBusy =
		installStatus === 'installing' ||
		installStatus === 'removing' ||
		installStatus === 'building';
	const typeStr = project.projectType?.toLowerCase() || 'block';

	/**
	 * Shared install/update handler.
	 *
	 * Flow:
	 *  1. POST /install — if the build is ready the server installs and we're done.
	 *  2. If the server returns { status:'building' } it has queued the build.
	 *     We poll GET /build every poll_interval seconds until ready (up to ~2 min).
	 *  3. Once the build is ready we POST /install again and it completes normally.
	 * @param {string} successMessage
	 */
	async function executeInstall( successMessage ) {
		setInstallStatus( project.publicId, 'installing' );
		setInstallStep( project.publicId, 1 );

		// Advance progress steps on a timer while the install runs.
		const t1 = setTimeout(
			() => setInstallStep( project.publicId, 2 ),
			1200
		);
		const t2 = setTimeout(
			() => setInstallStep( project.publicId, 3 ),
			2600
		);

		const clearStepTimers = () => {
			clearTimeout( t1 );
			clearTimeout( t2 );
		};

		try {
			await installWithPolling(
				apiFetch,
				restUrl,
				project.publicId,
				() => {
					clearStepTimers();
					setInstallStatus( project.publicId, 'building' );
					setInstallStep( project.publicId, 1 );
				}
			);

			clearStepTimers();
			setInstallStep( project.publicId, 4 );
			setTimeout( () => clearInstallStep( project.publicId ), 600 );
			setNotice( { type: 'success', message: successMessage } );
			await onRefresh();
			setInstallStatus( project.publicId, 'idle' );
		} catch ( err ) {
			clearStepTimers();
			clearInstallStep( project.publicId );
			setInstallStatus( project.publicId, 'failed' );
			setNotice( {
				type: 'error',
				message:
					err.message ||
					__( "That didn't work. Try again?", 'dispatch' ),
			} );
		}
	}

	async function handleInstall() {
		await executeInstall(
			sprintf(
				/* translators: %s: project name */
				__( '%s is installed!', 'dispatch' ),
				project.name
			)
		);
	}

	async function handleUpdate() {
		await executeInstall(
			sprintf(
				/* translators: %s: project name */
				__( '%s is updated!', 'dispatch' ),
				project.name
			)
		);
	}

	async function handleRemove() {
		setInstallStatus( project.publicId, 'removing' );
		setConfirmRemove( null );
		try {
			await apiFetch( {
				url: `${ restUrl }/projects/${ project.publicId }`,
				method: 'DELETE',
			} );
			setNotice( {
				type: 'success',
				message: sprintf(
					/* translators: %s: project name */
					__( '%s has been removed.', 'dispatch' ),
					project.name
				),
			} );
			await onRefresh();
			setInstallStatus( project.publicId, 'idle' );
		} catch ( err ) {
			setInstallStatus( project.publicId, 'idle' );
			setNotice( {
				type: 'error',
				message:
					err.message ||
					__( "Couldn't remove it. Try again?", 'dispatch' ),
			} );
		}
	}

	// Relative timestamps from the tracker — already in the REST response.
	const installedAgo = relativeDate( installed?.installed_at );
	const updatedAgo = relativeDate( installed?.updated_at );

	// Show "Updated X ago" only when different from install time (i.e. it was
	// actually updated at least once after the initial install).
	const showUpdatedAgo =
		updatedAgo &&
		installed?.updated_at &&
		installed?.installed_at &&
		installed.updated_at !== installed.installed_at;

	return (
		<Card
			className={ [
				'telex-project-card',
				needsUpdate ? 'telex-project-card--has-update' : '',
				isInstalled ? 'telex-project-card--installed' : '',
				isSelected ? 'telex-project-card--selected' : '',
			]
				.filter( Boolean )
				.join( ' ' ) }
		>
			{ ! isInstalled && ! isBusy && (
				<input
					type="checkbox"
					className="telex-card-select"
					checked={ isSelected }
					onChange={ () => toggleSelection( project.publicId ) }
					aria-label={ sprintf(
						/* translators: %s: project name */
						__( 'Select %s for bulk install', 'dispatch' ),
						project.name
					) }
				/>
			) }
			<CardHeader>
				<div className="telex-card-title-row">
					<ProjectAvatar
						name={ project.name }
						publicId={ project.publicId }
					/>
					<div className="telex-card-title-text">
						<strong className="telex-card-title">
							{ project.name }
						</strong>
						<TypeBadge type={ typeStr } />
					</div>
				</div>
				<StatusBadge
					publicId={ project.publicId }
					remoteVersion={ project.currentVersion }
					installed={ installed }
				/>
			</CardHeader>

			<CardBody>
				<div className="telex-card-meta">
					{ isInstalled && (
						<span className="telex-meta-item">
							{ sprintf(
								/* translators: %s: version number */
								__( 'Build #%s installed', 'dispatch' ),
								installed.version
							) }
						</span>
					) }
					{ isInstalled && needsUpdate && (
						<span className="telex-meta-item telex-meta-item--new">
							{ sprintf(
								/* translators: %s: version number */
								__( 'Build #%s available', 'dispatch' ),
								project.currentVersion
							) }
						</span>
					) }
					{ ( needsUpdate || ! isInstalled ) &&
						project.updatedAt &&
						relativeDate( project.updatedAt ) && (
							<span className="telex-meta-item telex-meta-item--timestamp">
								{ sprintf(
									/* translators: %s: relative time e.g. "5 minutes ago" */
									__( 'Built %s', 'dispatch' ),
									relativeDate( project.updatedAt )
								) }
							</span>
						) }
					{ ! isInstalled &&
						project.currentVersion &&
						! project.updatedAt && (
							<span className="telex-meta-item">
								{ sprintf(
									/* translators: %s: version number */
									__( 'Build #%s', 'dispatch' ),
									project.currentVersion
								) }
							</span>
						) }
					{ project.slug && (
						<code className="telex-slug">{ project.slug }</code>
					) }
				</div>

				{ project.description && (
					<p className="telex-card-description">
						{ project.description }
					</p>
				) }

				{ ( installedAgo || showUpdatedAgo ) && (
					<div className="telex-card-timestamps">
						{ showUpdatedAgo ? (
							<span className="telex-timestamp">
								{ sprintf(
									/* translators: %s: relative time */
									__( 'Updated %s', 'dispatch' ),
									updatedAgo
								) }
							</span>
						) : (
							installedAgo && (
								<span className="telex-timestamp">
									{ sprintf(
										/* translators: %s: relative time */
										__( 'Installed %s', 'dispatch' ),
										installedAgo
									) }
								</span>
							)
						) }
					</div>
				) }

				<ExternalLink
					href={ `https://telex.automattic.ai/projects/${ project.publicId }` }
					className="telex-card-telex-link"
				>
					{ __( 'Edit in Telex', 'dispatch' ) }
				</ExternalLink>

				{ installStep !== null && (
					<InstallProgress currentStep={ installStep } />
				) }

				<div
					className="telex-card-actions"
					role="group"
					aria-label={ sprintf(
						/* translators: %s: project name */
						__( 'Actions for %s', 'dispatch' ),
						project.name
					) }
				>
					{ ! isInstalled && (
						<Tooltip text={ __( 'Add to your site', 'dispatch' ) }>
							<Button
								variant="primary"
								onClick={ handleInstall }
								disabled={ isBusy }
								icon={ isBusy ? null : download }
								isBusy={
									isBusy && installStatus === 'installing'
								}
								__next40pxDefaultSize
							>
								{ __( 'Install', 'dispatch' ) }
							</Button>
						</Tooltip>
					) }

					{ isInstalled && needsUpdate && (
						<Tooltip
							text={ sprintf(
								/* translators: %s: version number */
								__( 'Get v%s', 'dispatch' ),
								project.currentVersion
							) }
						>
							<Button
								variant="primary"
								onClick={ handleUpdate }
								disabled={ isBusy }
								icon={ isBusy ? null : updateIcon }
								isBusy={
									isBusy && installStatus === 'installing'
								}
								__next40pxDefaultSize
							>
								{ __( 'Update', 'dispatch' ) }
							</Button>
						</Tooltip>
					) }

					{ isInstalled && ! needsUpdate && (
						<Button
							variant="secondary"
							disabled
							icon={ check }
							__next40pxDefaultSize
						>
							{ __( 'Installed', 'dispatch' ) }
						</Button>
					) }

					{ isInstalled && (
						<Tooltip
							text={ __(
								'Uninstall from your site',
								'dispatch'
							) }
						>
							<Button
								variant="tertiary"
								isDestructive
								icon={ trash }
								onClick={ () =>
									setConfirmRemove( project.publicId )
								}
								disabled={ isBusy }
								isBusy={
									isBusy && installStatus === 'removing'
								}
								__next40pxDefaultSize
							>
								{ __( 'Remove', 'dispatch' ) }
							</Button>
						</Tooltip>
					) }
				</div>

				{ confirmRemove === project.publicId && (
					<Modal
						title={ sprintf(
							/* translators: %s: project name */
							__( 'Remove "%s"?', 'dispatch' ),
							project.name
						) }
						onRequestClose={ () => setConfirmRemove( null ) }
					>
						<p>
							{ sprintf(
								/* translators: %s: project name */
								__(
									"This will delete %s from your site for good — there's no undo.",
									'dispatch'
								),
								project.name
							) }
						</p>
						<div className="telex-modal-actions">
							<Button
								variant="primary"
								isDestructive
								onClick={ handleRemove }
								__next40pxDefaultSize
							>
								{ __( 'Yes, remove it', 'dispatch' ) }
							</Button>
							<Button
								variant="secondary"
								onClick={ () => setConfirmRemove( null ) }
								__next40pxDefaultSize
							>
								{ __( 'Keep it', 'dispatch' ) }
							</Button>
						</div>
					</Modal>
				) }
			</CardBody>
		</Card>
	);
}

// ---------------------------------------------------------------------------
// Main app
// ---------------------------------------------------------------------------

function ProjectsApp() {
	const container = document.getElementById( 'telex-projects-app' );
	const restUrl = container?.dataset?.restUrl?.replace( /\/$/, '' ) || '';
	const nonce = container?.dataset?.nonce || '';
	const disconnectUrl = container?.dataset?.disconnectUrl || '';

	const {
		setProjects,
		setInstalledProjects,
		setLoading,
		setError,
		setAuthExpired,
		setSearchQuery,
		clearNotice,
		setNotice,
		setInstallStatus,
		setCurrentPage,
		clearSelection,
		selectAllUninstalled,
		setBulkInstalling,
	} = useDispatch( 'telex/admin' );

	const projects = useSelect( ( select ) =>
		select( 'telex/admin' ).getProjects()
	);
	const installedProjects = useSelect( ( select ) =>
		select( 'telex/admin' ).getInstalledProjects()
	);
	const loading = useSelect( ( select ) =>
		select( 'telex/admin' ).isLoading()
	);
	const error = useSelect( ( select ) => select( 'telex/admin' ).getError() );
	const authExpired = useSelect( ( select ) =>
		select( 'telex/admin' ).isAuthExpired()
	);
	const searchQuery = useSelect( ( select ) =>
		select( 'telex/admin' ).getSearchQuery()
	);
	const notice = useSelect( ( select ) =>
		select( 'telex/admin' ).getNotice()
	);
	const currentPage = useSelect( ( select ) =>
		select( 'telex/admin' ).getCurrentPage()
	);
	const perPage = useSelect( ( select ) =>
		select( 'telex/admin' ).getPerPage()
	);
	const selectedProjects = useSelect( ( select ) =>
		select( 'telex/admin' ).getSelectedProjects()
	);
	const bulkInstalling = useSelect( ( select ) =>
		select( 'telex/admin' ).isBulkInstalling()
	);

	// Fetch project list + installed tracker data.
	const fetchData = useCallback(
		async ( forceRefresh = false ) => {
			try {
				const url = forceRefresh
					? `${ restUrl }/projects?force_refresh=1`
					: `${ restUrl }/projects`;
				const data = await apiFetch( { url } );
				setProjects( data.projects || [] );
				if ( data.installed ) {
					setInstalledProjects( data.installed );
				}
			} catch ( err ) {
				if ( err?.code === 'telex_token_expired' ) {
					setAuthExpired( true );
				} else {
					setError(
						err.message ||
							__(
								"Couldn't load your projects. Check your connection and try again.",
								'dispatch'
							)
					);
				}
			}
			// eslint-disable-next-line react-hooks/exhaustive-deps
		},
		// Stable dispatch references excluded from deps intentionally.
		// eslint-disable-next-line react-hooks/exhaustive-deps
		[ restUrl ]
	);

	useEffect( () => {
		// Guard: apiFetch.use() pushes to a global array — register the nonce
		// middleware at most once per page load even if the component remounts.
		if ( ! ProjectsApp._nonceRegistered ) {
			apiFetch.use( apiFetch.createNonceMiddleware( nonce ) );
			ProjectsApp._nonceRegistered = true;
		}
		setLoading( true );
		fetchData().finally( () => setLoading( false ) );
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [] );

	// Derived counts for tab titles.
	const updatesCount = projects.filter( ( p ) => {
		const inst = installedProjects[ p.publicId ];
		return inst && p.currentVersion > inst.version;
	} ).length;

	const blocksCount = projects.filter(
		( p ) => ( p.projectType?.toLowerCase() || 'block' ) !== 'theme'
	).length;

	const themesCount = projects.filter(
		( p ) => p.projectType?.toLowerCase() === 'theme'
	).length;

	// Piggyback on the WordPress Heartbeat to detect new builds while the user
	// stays on the page. When the server reports more updates we force-refresh.
	useEffect( () => {
		if ( ! window.jQuery ) {
			return;
		}

		const onHeartbeatSend = ( _e, heartbeatData ) => {
			heartbeatData.telex_poll = true;
		};

		const onHeartbeatTick = ( _e, response ) => {
			if ( response?.telex?.update_count > updatesCount ) {
				fetchData( true );
			}
		};

		window
			.jQuery( document )
			.on( 'heartbeat-send.telex-admin', onHeartbeatSend );
		window
			.jQuery( document )
			.on( 'heartbeat-tick.telex-admin', onHeartbeatTick );

		return () => {
			window.jQuery( document ).off( 'heartbeat-send.telex-admin' );
			window.jQuery( document ).off( 'heartbeat-tick.telex-admin' );
		};
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ updatesCount ] );

	// Filter projects for a given tab + search.
	function getTabProjects( tabName ) {
		return projects
			.filter( ( p ) => {
				const t = p.projectType?.toLowerCase() || 'block';
				const inst = installedProjects[ p.publicId ];
				if ( tabName === 'updates' ) {
					return inst && p.currentVersion > inst.version;
				}
				if ( tabName === 'blocks' ) {
					return t !== 'theme';
				}
				if ( tabName === 'themes' ) {
					return t === 'theme';
				}
				return true;
			} )
			.filter(
				( p ) =>
					! searchQuery ||
					p.name?.toLowerCase().includes( searchQuery.toLowerCase() )
			);
	}

	// Bulk install: install all selected (uninstalled) projects sequentially.
	async function handleBulkInstall() {
		setBulkInstalling( true );
		let successCount = 0;
		let lastError = '';

		for ( const publicId of selectedProjects ) {
			if ( installedProjects[ publicId ] ) {
				continue; // already installed
			}
			setInstallStatus( publicId, 'installing' );
			try {
				// eslint-disable-next-line no-await-in-loop
				await installWithPolling( apiFetch, restUrl, publicId, () => {
					setInstallStatus( publicId, 'building' );
				} );
				successCount++;
				setInstallStatus( publicId, 'idle' );
			} catch ( err ) {
				setInstallStatus( publicId, 'failed' );
				lastError = err.message || __( 'Install failed.', 'dispatch' );
			}
		}

		await fetchData();
		clearSelection();
		setBulkInstalling( false );

		if ( successCount > 0 ) {
			setNotice( {
				type: lastError ? 'warning' : 'success',
				message: lastError
					? sprintf(
							/* translators: 1: success count, 2: error */
							__(
								'%1$d installed. One or more failed: %2$s',
								'dispatch'
							),
							successCount,
							lastError
					  )
					: sprintf(
							/* translators: %d: number of projects */
							_n(
								'%d project installed successfully.',
								'%d projects installed successfully.',
								successCount,
								'dispatch'
							),
							successCount
					  ),
			} );
		} else if ( lastError ) {
			setNotice( { type: 'error', message: lastError } );
		}
	}

	const tabs = [
		{
			name: 'all',
			title: projects.length
				? `${ __( 'All', 'dispatch' ) } (${ projects.length })`
				: __( 'All', 'dispatch' ),
		},
		{
			name: 'updates',
			title: updatesCount
				? `${ __( 'Updates', 'dispatch' ) } (${ updatesCount })`
				: __( 'Updates', 'dispatch' ),
			className: updatesCount > 0 ? 'telex-tab--has-updates' : '',
		},
		{
			name: 'blocks',
			title: blocksCount
				? `${ __( 'Blocks', 'dispatch' ) } (${ blocksCount })`
				: __( 'Blocks', 'dispatch' ),
		},
		{
			name: 'themes',
			title: themesCount
				? `${ __( 'Themes', 'dispatch' ) } (${ themesCount })`
				: __( 'Themes', 'dispatch' ),
		},
	];

	return (
		<div className="telex-app">
			{ notice && (
				<Notice
					status={ notice.type }
					onRemove={ clearNotice }
					isDismissible
				>
					{ notice.message }
				</Notice>
			) }

			{ authExpired && (
				<Notice
					status="warning"
					isDismissible={ false }
					className="telex-reconnect-notice"
				>
					{ __( 'Your Telex connection has expired.', 'dispatch' ) }{ ' ' }
					<Button
						variant="link"
						onClick={ () => window.location.reload() }
					>
						{ __( 'Reconnect now', 'dispatch' ) }
					</Button>
				</Notice>
			) }

			{ ! loading && ! error && ! authExpired && projects.length > 0 && (
				<StatsBar
					projects={ projects }
					installedProjects={ installedProjects }
				/>
			) }

			<div className="telex-toolbar" role="search">
				<SearchControl
					label={ __( 'Search projects', 'dispatch' ) }
					value={ searchQuery }
					onChange={ setSearchQuery }
					__nextHasNoMarginBottom
				/>
				<div className="telex-toolbar-right">
					<Button
						variant="secondary"
						onClick={ () => {
							setLoading( true );
							fetchData( true ).finally( () =>
								setLoading( false )
							);
						} }
						disabled={ loading }
						aria-label={ __( 'Check for new builds', 'dispatch' ) }
						__next40pxDefaultSize
					>
						{ loading ? <Spinner /> : __( 'Refresh', 'dispatch' ) }
					</Button>
					<a
						href={ disconnectUrl }
						className="button button-secondary telex-disconnect"
					>
						{ __( 'Disconnect', 'dispatch' ) }
					</a>
				</div>
			</div>

			{ loading && (
				<div
					className="telex-loading"
					aria-live="polite"
					aria-label={ __( 'Loading your projects', 'dispatch' ) }
				>
					<Spinner />
					<span>{ __( 'Loading your projects…', 'dispatch' ) }</span>
				</div>
			) }

			{ error && (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) }

			{ ! loading && ! error && ! authExpired && (
				<TabPanel
					tabs={ tabs }
					className="telex-tab-panel"
					initialTabName="all"
					onSelect={ () => setCurrentPage( 1 ) }
				>
					{ ( tab ) => {
						const allVisible = getTabProjects( tab.name );
						const totalItems = allVisible.length;
						const totalPages = Math.ceil( totalItems / perPage );
						const safePage = Math.min(
							currentPage,
							totalPages || 1
						);
						const pageStart = ( safePage - 1 ) * perPage;
						const paginated = allVisible.slice(
							pageStart,
							pageStart + perPage
						);

						// IDs of uninstalled projects on this tab (for bulk select).
						const uninstalledIds = allVisible
							.filter(
								( p ) => ! installedProjects[ p.publicId ]
							)
							.map( ( p ) => p.publicId );

						return (
							<>
								{ selectedProjects.length > 0 && (
									<BulkToolbar
										selectedCount={
											selectedProjects.length
										}
										onInstall={ handleBulkInstall }
										onClear={ clearSelection }
										isBusy={ bulkInstalling }
									/>
								) }
								{ uninstalledIds.length > 0 &&
									selectedProjects.length === 0 &&
									! bulkInstalling && (
										<div className="telex-select-all-row">
											<Button
												variant="link"
												onClick={ () =>
													selectAllUninstalled(
														uninstalledIds
													)
												}
												__next40pxDefaultSize
											>
												{ sprintf(
													/* translators: %d: number of uninstalled projects */
													_n(
														'Select %d uninstalled project',
														'Select all %d uninstalled projects',
														uninstalledIds.length,
														'dispatch'
													),
													uninstalledIds.length
												) }
											</Button>
										</div>
									) }
								<div
									className="telex-project-grid"
									role="list"
									aria-label={ __(
										'Dispatch projects',
										'dispatch'
									) }
									aria-live="polite"
								>
									{ paginated.length === 0 ? (
										<EmptyState
											tab={ tab.name }
											searchQuery={ searchQuery }
										/>
									) : (
										paginated.map( ( project ) => (
											<div
												key={ project.publicId }
												role="listitem"
											>
												<ProjectCard
													project={ project }
													restUrl={ restUrl }
													onRefresh={ fetchData }
												/>
											</div>
										) )
									) }
								</div>
								<PaginationControls
									currentPage={ safePage }
									totalPages={ totalPages }
									totalItems={ totalItems }
									perPage={ perPage }
									onPageChange={ setCurrentPage }
								/>
							</>
						);
					} }
				</TabPanel>
			) }
		</div>
	);
}

// ---------------------------------------------------------------------------
// Error boundary
// ---------------------------------------------------------------------------

/**
 * Catches unhandled JS errors inside the React tree and shows a graceful
 * fallback instead of a blank white panel.
 */
class TelexErrorBoundary extends Component {
	constructor( props ) {
		super( props );
		this.state = { hasError: false, message: '' };
	}

	static getDerivedStateFromError( error ) {
		return { hasError: true, message: error?.message ?? String( error ) };
	}

	render() {
		if ( this.state.hasError ) {
			return (
				<div
					className="notice notice-error"
					style={ { margin: '16px 0' } }
				>
					<p>
						<strong>
							{ __( 'Uh oh — something crashed.', 'dispatch' ) }
						</strong>{ ' ' }
						{ __(
							'Try reloading the page. If it keeps happening, check the browser console.',
							'dispatch'
						) }
					</p>
					{ this.state.message && (
						<details>
							<summary>
								{ __( 'Technical details', 'dispatch' ) }
							</summary>
							<pre
								style={ {
									fontSize: '11px',
									whiteSpace: 'pre-wrap',
									wordBreak: 'break-word',
								} }
							>
								{ this.state.message }
							</pre>
						</details>
					) }
				</div>
			);
		}
		return this.props.children;
	}
}

// ---------------------------------------------------------------------------
// Boot
// ---------------------------------------------------------------------------

const appRoot = document.getElementById( 'telex-projects-app' );
if ( appRoot ) {
	render(
		<TelexErrorBoundary>
			<ProjectsApp />
		</TelexErrorBoundary>,
		appRoot
	);
}
