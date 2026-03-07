/**
 * Telex Admin — Projects Page
 *
 * Renders the project card grid with tab-based filtering, stats summary,
 * and live refetch (no full-page reload). State is managed via @wordpress/data.
 */
import {
	render,
	useEffect,
	useCallback,
	useState,
	useRef,
	Component,
} from '@wordpress/element';
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
	close as closeIcon,
	plugins as pluginsIcon,
	layout as layoutIcon,
	search as searchIcon,
	download,
	globe,
	copy as copyIcon,
	keyboardReturn,
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
	const currentLabel = INSTALL_STEPS[ currentStep - 1 ] || '';
	return (
		<div className="telex-install-progress">
			{ /* Scoped live region: only the active step label is announced. */ }
			<span
				className="screen-reader-text"
				role="status"
				aria-live="polite"
				aria-atomic="true"
			>
				{ currentLabel }
			</span>
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
						aria-hidden={ true }
					>
						{ isActive ? (
							<Spinner aria-hidden={ true } />
						) : (
							<span className="telex-install-step__dot" />
						) }
						{ label }
					</div>
				);
			} ) }
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
		<nav
			className="telex-pagination"
			aria-label={ __( 'Pagination', 'dispatch' ) }
		>
			<span
				className="telex-pagination__info"
				aria-live="polite"
				aria-atomic="true"
			>
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
					aria-label={ __( 'Go to previous page', 'dispatch' ) }
					__next40pxDefaultSize
				>
					{ __( '\u2039 Previous', 'dispatch' ) }
				</Button>
				<Button
					variant="secondary"
					onClick={ () => onPageChange( currentPage + 1 ) }
					disabled={ currentPage === totalPages }
					aria-label={ __( 'Go to next page', 'dispatch' ) }
					__next40pxDefaultSize
				>
					{ __( 'Next \u203a', 'dispatch' ) }
				</Button>
			</div>
		</nav>
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
			role="region"
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
			<Icon
				icon={ isTheme ? layoutIcon : pluginsIcon }
				size={ 10 }
				aria-hidden={ true }
				focusable={ false }
			/>
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

	const busyLabels = {
		building: __( 'Building…', 'dispatch' ),
		installing: __( 'Installing…', 'dispatch' ),
		removing: __( 'Removing…', 'dispatch' ),
	};

	const isBusy = installStatus in busyLabels;
	let variantClass;
	let inner;

	if ( isBusy ) {
		variantClass = 'telex-badge--loading';
		inner = (
			<>
				<Spinner aria-hidden={ true } />
				{ busyLabels[ installStatus ] }
			</>
		);
	} else if ( ! installed ) {
		variantClass = 'telex-badge--idle';
		inner = __( 'Not installed', 'dispatch' );
	} else if ( remoteVersion > installed.version ) {
		variantClass = 'telex-badge--update';
		inner = (
			<>
				<Icon
					icon={ updateIcon }
					size={ 10 }
					aria-hidden={ true }
					focusable={ false }
				/>
				{ sprintf(
					/* translators: 1: installed version, 2: available version */
					__( 'v%1$s → v%2$s', 'dispatch' ),
					installed.version,
					remoteVersion
				) }
			</>
		);
	} else {
		variantClass = 'telex-badge--installed';
		inner = (
			<>
				<Icon
					icon={ check }
					size={ 10 }
					aria-hidden={ true }
					focusable={ false }
				/>
				{ __( 'Up to date', 'dispatch' ) }
			</>
		);
	}

	return (
		<span
			className={ `telex-badge ${ variantClass }` }
			role="status"
			aria-live="polite"
			aria-atomic="true"
		>
			{ inner }
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
					<Icon
						icon={ searchIcon }
						size={ 32 }
						aria-hidden={ true }
						focusable={ false }
					/>
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
				<Icon
					icon={ state.icon }
					size={ 32 }
					aria-hidden={ true }
					focusable={ false }
				/>
			</div>
			<h3>{ state.heading }</h3>
			<p>{ state.body }</p>
		</div>
	);
}

// ---------------------------------------------------------------------------
// Toast notifications (bottom-right, auto-dismiss, undo support)
// ---------------------------------------------------------------------------

const TOAST_DURATION = 5000; // ms before auto-dismiss

/**
 * Single dismissible toast with optional undo action and progress bar.
 *
 * @param {Object}   root0
 * @param {Object}   root0.toast
 * @param {Function} root0.onDismiss
 * @return {import('@wordpress/element').WPElement} Rendered element.
 */
function Toast( { toast, onDismiss } ) {
	const { id, type, message, undoFn, duration = TOAST_DURATION } = toast;
	const [ undoUsed, setUndoUsed ] = useState( false );

	useEffect( () => {
		const timer = setTimeout( () => onDismiss( id ), duration );
		return () => clearTimeout( timer );
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ id, duration ] );

	function handleUndo() {
		setUndoUsed( true );
		onDismiss( id );
		undoFn?.();
	}

	return (
		<div
			className={ `telex-toast telex-toast--${ type || 'info' }` }
			role="status"
			aria-live="polite"
			style={ { '--telex-toast-duration': `${ duration }ms` } }
		>
			<div className="telex-toast__body">
				<span className="telex-toast__message">{ message }</span>
				{ undoFn && ! undoUsed && (
					<div className="telex-toast__actions">
						<Button
							variant="link"
							onClick={ handleUndo }
							__next40pxDefaultSize={ false }
						>
							{ __( 'Undo', 'dispatch' ) }
						</Button>
					</div>
				) }
			</div>
			<button
				type="button"
				className="telex-toast__close"
				onClick={ () => onDismiss( id ) }
				aria-label={ sprintf(
					/* translators: %s: notification message */
					__( 'Dismiss: %s', 'dispatch' ),
					message
				) }
			>
				&times;
			</button>
			<div className="telex-toast__progress" aria-hidden={ true } />
		</div>
	);
}

/**
 * Fixed bottom-right toast stack.
 *
 * @param {Object}   root0
 * @param {Array}    root0.toasts
 * @param {Function} root0.onDismiss
 * @return {import('@wordpress/element').WPElement|null} Toast list or null when empty.
 */
function ToastList( { toasts, onDismiss } ) {
	if ( ! toasts.length ) {
		return null;
	}
	return (
		<div
			className="telex-toasts"
			role="log"
			aria-label={ __( 'Notifications', 'dispatch' ) }
		>
			{ toasts.map( ( t ) => (
				<Toast key={ t.id } toast={ t } onDismiss={ onDismiss } />
			) ) }
		</div>
	);
}

// ---------------------------------------------------------------------------
// Keyboard shortcuts modal
// ---------------------------------------------------------------------------

const SHORTCUTS = [
	{ keys: [ 'R' ], label: __( 'Refresh projects', 'dispatch' ) },
	{ keys: [ '/' ], label: __( 'Focus search', 'dispatch' ) },
	{ keys: [ 'Esc' ], label: __( 'Clear search / close modal', 'dispatch' ) },
	{ keys: [ '?' ], label: __( 'Show this shortcuts list', 'dispatch' ) },
];

/**
 * @param {Object}   root0
 * @param {Function} root0.onClose
 * @return {import('@wordpress/element').WPElement} Rendered element.
 */
function KeyboardShortcutsModal( { onClose } ) {
	return (
		<Modal
			title={ __( 'Keyboard shortcuts', 'dispatch' ) }
			onRequestClose={ onClose }
			className="telex-shortcuts-modal"
		>
			<ul className="telex-shortcuts-grid">
				{ SHORTCUTS.map( ( shortcut ) => (
					<li key={ shortcut.label } className="telex-shortcuts-row">
						<span>
							{ shortcut.keys.map( ( k ) => (
								<kbd key={ k } className="telex-shortcut-key">
									{ k }
								</kbd>
							) ) }
						</span>
						<span>{ shortcut.label }</span>
					</li>
				) ) }
			</ul>
		</Modal>
	);
}

// ---------------------------------------------------------------------------
// Project detail modal
// ---------------------------------------------------------------------------

/**
 * Full project information modal — triggered by clicking the project name.
 *
 * @param {Object}   root0
 * @param {Object}   root0.project
 * @param {Object}   root0.installed Tracker record for this project, or null.
 * @param {Function} root0.onClose
 * @param {Function} root0.onInstall
 * @param {Function} root0.onUpdate
 * @param {Function} root0.onRemove
 * @return {import('@wordpress/element').WPElement} Rendered element.
 */
function ProjectDetailModal( {
	project,
	installed,
	onClose,
	onInstall,
	onUpdate,
	onRemove,
} ) {
	const isInstalled = !! installed;
	const needsUpdate = installed && project.currentVersion > installed.version;
	const typeStr = project.projectType?.toLowerCase() || 'block';

	return (
		<Modal
			title={ project.name }
			onRequestClose={ onClose }
			className="telex-detail-modal"
		>
			<div className="telex-detail-header">
				<ProjectAvatar
					name={ project.name }
					publicId={ project.publicId }
				/>
				<div>
					<TypeBadge type={ typeStr } />
				</div>
			</div>

			<div className="telex-detail-meta">
				{ project.slug && (
					<code className="telex-slug">{ project.slug }</code>
				) }
				{ isInstalled && (
					<span className="telex-meta-item">
						{ sprintf(
							/* translators: %s: version number */
							__( 'v%s installed', 'dispatch' ),
							installed.version
						) }
					</span>
				) }
				{ project.currentVersion && (
					<span className="telex-meta-item">
						{ sprintf(
							/* translators: %s: version number */
							__( 'Latest: v%s', 'dispatch' ),
							project.currentVersion
						) }
					</span>
				) }
			</div>

			{ needsUpdate && (
				<div className="telex-detail-version-diff">
					<Icon
						icon={ updateIcon }
						size={ 16 }
						aria-hidden={ true }
						focusable={ false }
					/>
					{ sprintf(
						/* translators: 1: installed version, 2: available version */
						__( 'Update available: v%1$s → v%2$s', 'dispatch' ),
						installed.version,
						project.currentVersion
					) }
				</div>
			) }

			{ project.description && (
				<p className="telex-detail-description">
					{ project.description }
				</p>
			) }

			<ExternalLink
				href={ `https://telex.automattic.ai/projects/${ project.publicId }` }
			>
				{ __( 'View in Telex →', 'dispatch' ) }
			</ExternalLink>

			<div className="telex-detail-actions">
				{ ! isInstalled && (
					<Button
						variant="primary"
						onClick={ () => {
							onInstall();
							onClose();
						} }
						icon={ download }
						__next40pxDefaultSize
					>
						{ __( 'Install', 'dispatch' ) }
					</Button>
				) }
				{ needsUpdate && (
					<Button
						variant="primary"
						onClick={ () => {
							onUpdate();
							onClose();
						} }
						icon={ updateIcon }
						__next40pxDefaultSize
					>
						{ __( 'Update', 'dispatch' ) }
					</Button>
				) }
				{ isInstalled && (
					<Button
						variant="tertiary"
						isDestructive
						onClick={ () => {
							onRemove();
							onClose();
						} }
						icon={ trash }
						__next40pxDefaultSize
					>
						{ __( 'Remove', 'dispatch' ) }
					</Button>
				) }
				<Button
					variant="secondary"
					onClick={ onClose }
					__next40pxDefaultSize
				>
					{ __( 'Close', 'dispatch' ) }
				</Button>
			</div>
		</Modal>
	);
}

// ---------------------------------------------------------------------------
// Changelog confirmation modal (shown before executing an update)
// ---------------------------------------------------------------------------

/**
 * @param {Object}   root0
 * @param {Object}   root0.project
 * @param {Object}   root0.installed
 * @param {Function} root0.onConfirm
 * @param {Function} root0.onCancel
 * @return {import('@wordpress/element').WPElement} Rendered element.
 */
function ChangelogModal( { project, installed, onConfirm, onCancel } ) {
	return (
		<Modal
			title={ sprintf(
				/* translators: %s: project name */
				__( 'Update %s', 'dispatch' ),
				project.name
			) }
			onRequestClose={ onCancel }
			className="telex-changelog-modal"
		>
			<p className="telex-version-diff">
				{ sprintf(
					/* translators: 1: installed version number, 2: available version number */
					__( 'v%1$s → v%2$s', 'dispatch' ),
					installed.version,
					project.currentVersion
				) }
			</p>

			{ project.description && (
				<div className="telex-changelog-body">
					{ project.description }
				</div>
			) }

			<p style={ { marginBottom: 12 } }>
				<ExternalLink
					href={ `https://telex.automattic.ai/projects/${ project.publicId }` }
				>
					{ __( 'View full changelog in Telex →', 'dispatch' ) }
				</ExternalLink>
			</p>

			<div className="telex-changelog-actions">
				<Button
					variant="primary"
					onClick={ onConfirm }
					icon={ updateIcon }
					__next40pxDefaultSize
				>
					{ __( 'Update now', 'dispatch' ) }
				</Button>
				<Button
					variant="secondary"
					onClick={ onCancel }
					__next40pxDefaultSize
				>
					{ __( 'Not yet', 'dispatch' ) }
				</Button>
			</div>
		</Modal>
	);
}

// ---------------------------------------------------------------------------
// Webhook / auto-deploy settings panel
// ---------------------------------------------------------------------------

/**
 * @param {Object} root0
 * @param {string} root0.webhookUrl
 * @param {string} root0.webhookSecret
 * @param {string} root0.restUrl
 * @return {import('@wordpress/element').WPElement} Rendered element.
 */
function WebhookPanel( { webhookUrl, webhookSecret, restUrl } ) {
	const [ visibleSecret, setVisibleSecret ] = useState( false );
	const [ copiedUrl, setCopiedUrl ] = useState( false );
	const [ copiedSecret, setCopiedSecret ] = useState( false );
	const [ regenerating, setRegenerating ] = useState( false );
	const [ currentSecret, setCurrentSecret ] = useState( webhookSecret );

	function copy( text, setCopied ) {
		if ( window.navigator?.clipboard ) {
			window.navigator.clipboard.writeText( text ).catch( () => {} );
		}
		setCopied( true );
		setTimeout( () => setCopied( false ), 2000 );
	}

	async function handleRegenerate() {
		setRegenerating( true );
		try {
			const data = await apiFetch( {
				url: `${ restUrl }/settings/deploy-secret`,
				method: 'POST',
			} );
			setCurrentSecret( data.secret );
		} catch {
			// Silently fail — user can reload.
		}
		setRegenerating( false );
	}

	if ( ! webhookUrl ) {
		return null;
	}

	const maskedSecret = currentSecret
		? currentSecret.slice( 0, 8 ) + '••••••••••••••••••••••••'
		: '';

	return (
		<div className="telex-webhook-panel">
			<h3>{ __( 'Auto-deploy webhook', 'dispatch' ) }</h3>
			<p>
				{ __(
					'Give this URL to Telex and it will automatically push new builds to your site.',
					'dispatch'
				) }
			</p>

			<div className="telex-webhook-field">
				<span className="telex-webhook-label">
					{ __( 'URL', 'dispatch' ) }
				</span>
				<span className="telex-webhook-value" title={ webhookUrl }>
					{ webhookUrl }
				</span>
				<Button
					variant="secondary"
					icon={ copyIcon }
					onClick={ () => copy( webhookUrl, setCopiedUrl ) }
					__next40pxDefaultSize
				>
					{ copiedUrl
						? __( 'Copied!', 'dispatch' )
						: __( 'Copy', 'dispatch' ) }
				</Button>
			</div>

			{ currentSecret && (
				<div className="telex-webhook-field">
					<span className="telex-webhook-label">
						{ __( 'Secret', 'dispatch' ) }
					</span>
					<span
						className="telex-webhook-value"
						title={ visibleSecret ? currentSecret : undefined }
						aria-live="polite"
						aria-atomic="true"
					>
						{ visibleSecret ? currentSecret : maskedSecret }
					</span>
					<Button
						variant="tertiary"
						onClick={ () => setVisibleSecret( ( v ) => ! v ) }
						aria-pressed={ visibleSecret }
						__next40pxDefaultSize
					>
						{ visibleSecret
							? __( 'Hide', 'dispatch' )
							: __( 'Show', 'dispatch' ) }
					</Button>
					<Button
						variant="secondary"
						icon={ copyIcon }
						onClick={ () => copy( currentSecret, setCopiedSecret ) }
						__next40pxDefaultSize
					>
						{ copiedSecret
							? __( 'Copied!', 'dispatch' )
							: __( 'Copy', 'dispatch' ) }
					</Button>
					<Button
						variant="tertiary"
						onClick={ handleRegenerate }
						disabled={ regenerating }
						isBusy={ regenerating }
						__next40pxDefaultSize
					>
						{ __( 'Regenerate', 'dispatch' ) }
					</Button>
				</div>
			) }
		</div>
	);
}

// ---------------------------------------------------------------------------
// Network deploy modal (multisite)
// ---------------------------------------------------------------------------

/**
 * @param {Object}   root0
 * @param {Object}   root0.project
 * @param {string}   root0.restUrl
 * @param {Function} root0.onClose
 * @return {import('@wordpress/element').WPElement} Rendered element.
 */
function NetworkDeployModal( { project, restUrl, onClose } ) {
	const [ deploying, setDeploying ] = useState( false );
	const [ results, setResults ] = useState( null );

	async function handleDeploy() {
		setDeploying( true );
		try {
			const data = await apiFetch( {
				url: `${ restUrl }/projects/${ project.publicId }/deploy-network`,
				method: 'POST',
			} );
			setResults( data );
		} catch ( err ) {
			setResults( { error: err.message } );
		}
		setDeploying( false );
	}

	const total = results
		? ( results.succeeded?.length ?? 0 ) + ( results.failed?.length ?? 0 )
		: 0;

	return (
		<Modal
			title={ sprintf(
				/* translators: %s: project name */
				__( 'Deploy "%s" across the network', 'dispatch' ),
				project.name
			) }
			onRequestClose={ onClose }
		>
			{ ! results && (
				<>
					<p>
						{ __(
							'This will install or update this project on every site in your network. Sites that already have the latest build will be skipped.',
							'dispatch'
						) }
					</p>
					<div style={ { display: 'flex', gap: 8, marginTop: 16 } }>
						<Button
							variant="primary"
							onClick={ handleDeploy }
							disabled={ deploying }
							isBusy={ deploying }
							icon={ deploying ? null : globe }
							__next40pxDefaultSize
						>
							{ __( 'Deploy to all sites', 'dispatch' ) }
						</Button>
						<Button
							variant="secondary"
							onClick={ onClose }
							__next40pxDefaultSize
						>
							{ __( 'Cancel', 'dispatch' ) }
						</Button>
					</div>
				</>
			) }

			{ results && results.error && (
				<Notice status="error" isDismissible={ false }>
					{ results.error }
				</Notice>
			) }

			{ results && ! results.error && (
				<>
					<p aria-live="polite" aria-atomic="true">
						{ sprintf(
							/* translators: 1: success count, 2: total count */
							__(
								'%1$d of %2$d sites updated successfully.',
								'dispatch'
							),
							results.succeeded?.length ?? 0,
							total
						) }
					</p>
					<ul className="telex-network-results">
						{ results.succeeded?.map( ( site ) => (
							<li
								key={ site.id }
								className="telex-network-site-row"
							>
								<span>{ site.domain }</span>
								<span className="telex-network-status--ok">
									<Icon
										icon={ check }
										size={ 14 }
										aria-hidden={ true }
										focusable={ false }
									/>
									{ __( 'Done', 'dispatch' ) }
								</span>
							</li>
						) ) }
						{ results.failed?.map( ( site ) => (
							<li
								key={ site.id }
								className="telex-network-site-row"
							>
								<span>{ site.domain }</span>
								<span className="telex-network-status--fail">
									<Icon
										icon={ closeIcon }
										size={ 14 }
										aria-hidden={ true }
										focusable={ false }
									/>
									{ site.error || __( 'Failed', 'dispatch' ) }
								</span>
							</li>
						) ) }
					</ul>
					<div style={ { marginTop: 16 } }>
						<Button
							variant="secondary"
							onClick={ onClose }
							__next40pxDefaultSize
						>
							{ __( 'Close', 'dispatch' ) }
						</Button>
					</div>
				</>
			) }
		</Modal>
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
 * @param {boolean}  activate   Whether to activate the plugin/theme after install.
 * @return {Promise<Object>} Final install response data.
 */
async function installWithPolling(
	fetch,
	url,
	publicId,
	onBuilding,
	activate = false
) {
	const doInstall = () =>
		fetch( {
			url: `${ url }/projects/${ publicId }/install`,
			method: 'POST',
			data: { activate },
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

/**
 * @param {Object}   root0
 * @param {Object}   root0.project
 * @param {string}   root0.restUrl
 * @param {Function} root0.onRefresh
 * @param {Function} root0.onToast        (toast) => void — adds a toast notification.
 * @param {boolean}  root0.isNetworkAdmin Whether rendered in WP network admin.
 * @return {import('@wordpress/element').WPElement} Rendered element.
 */
function ProjectCard( {
	project,
	restUrl,
	onRefresh,
	onToast,
	isNetworkAdmin,
} ) {
	const {
		setInstallStatus,
		setConfirmRemove,
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

	const [ showDetail, setShowDetail ] = useState( false );
	const [ showChangelog, setShowChangelog ] = useState( false );
	const [ showNetworkDeploy, setShowNetworkDeploy ] = useState( false );

	const installed = installedProjects[ project.publicId ];
	const needsUpdate = installed && project.currentVersion > installed.version;
	const isInstalled = !! installed;
	const isBusy =
		installStatus === 'installing' ||
		installStatus === 'removing' ||
		installStatus === 'building';
	const typeStr = project.projectType?.toLowerCase() || 'block';
	const isBlock = typeStr !== 'theme';

	/**
	 * Shared install/update handler.
	 * @param {string}  successMessage
	 * @param {boolean} [activate]
	 */
	async function executeInstall( successMessage, activate = false ) {
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
					// Build is queued server-side. Cancel the fake-progress timers
					// so they don't fire during the (potentially long) build wait.
					// Leave the step indicator wherever it is — no reset.
					clearStepTimers();
					setInstallStatus( project.publicId, 'building' );
				},
				activate
			);

			clearStepTimers();
			setInstallStep( project.publicId, 4 );
			setTimeout( () => clearInstallStep( project.publicId ), 600 );
			onToast( { type: 'success', message: successMessage } );
			await onRefresh();
			setInstallStatus( project.publicId, 'idle' );
		} catch ( err ) {
			clearStepTimers();
			clearInstallStep( project.publicId );
			setInstallStatus( project.publicId, 'failed' );
			onToast( {
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
			),
			isBlock // always activate blocks on install
		);
	}

	// handleUpdate is invoked after the changelog modal confirms.
	async function handleUpdate() {
		await executeInstall(
			sprintf(
				/* translators: %s: project name */
				__( '%s is updated!', 'dispatch' ),
				project.name
			),
			isBlock // keep blocks active after an update
		);
	}

	async function handleRemove() {
		setInstallStatus( project.publicId, 'removing' );
		setConfirmRemove( null );
		const removedPublicId = project.publicId;
		const removedName = project.name;
		try {
			await apiFetch( {
				url: `${ restUrl }/projects/${ removedPublicId }`,
				method: 'DELETE',
			} );
			await onRefresh();
			setInstallStatus( removedPublicId, 'idle' );
			onToast( {
				type: 'success',
				message: sprintf(
					/* translators: %s: project name */
					__( '%s has been removed.', 'dispatch' ),
					removedName
				),
				undoFn: async () => {
					setInstallStatus( removedPublicId, 'installing' );
					try {
						await installWithPolling(
							apiFetch,
							restUrl,
							removedPublicId,
							() =>
								setInstallStatus( removedPublicId, 'building' )
						);
						await onRefresh();
						setInstallStatus( removedPublicId, 'idle' );
						onToast( {
							type: 'success',
							message: sprintf(
								/* translators: %s: project name */
								__( '%s has been reinstalled.', 'dispatch' ),
								removedName
							),
						} );
					} catch ( err ) {
						setInstallStatus( removedPublicId, 'failed' );
						onToast( {
							type: 'error',
							message:
								err.message ||
								__(
									"Reinstall didn't work. Try again?",
									'dispatch'
								),
						} );
					}
				},
			} );
		} catch ( err ) {
			setInstallStatus( project.publicId, 'idle' );
			onToast( {
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

	// Show "Updated X ago" only when different from install time.
	const showUpdatedAgo =
		updatedAgo &&
		installed?.updated_at &&
		installed?.installed_at &&
		installed.updated_at !== installed.installed_at;

	return (
		<Card
			className={ [
				'telex-project-card',
				`telex-project-card--${ typeStr }`,
				needsUpdate ? 'telex-project-card--has-update' : '',
				isInstalled ? 'telex-project-card--installed' : '',
			]
				.filter( Boolean )
				.join( ' ' ) }
		>
			<CardHeader>
				<div className="telex-card-title-row">
					<ProjectAvatar
						name={ project.name }
						publicId={ project.publicId }
					/>
					<div className="telex-card-title-text">
						<button
							type="button"
							className="telex-card-title telex-card-title--btn"
							onClick={ () => setShowDetail( true ) }
							aria-label={ sprintf(
								/* translators: %s: project name */
								__( 'View details for %s', 'dispatch' ),
								project.name
							) }
						>
							{ project.name }
						</button>
						<TypeBadge type={ typeStr } />
					</div>
					<StatusBadge
						publicId={ project.publicId }
						remoteVersion={ project.currentVersion }
						installed={ installed }
					/>
					<ExternalLink
						href={ `https://telex.automattic.ai/projects/${ project.publicId }` }
						className="telex-card-edit-link"
						aria-label={ sprintf(
							/* translators: %s: project name */
							__( 'Edit %s in Telex', 'dispatch' ),
							project.name
						) }
					>
						{ __( 'Edit ↗', 'dispatch' ) }
					</ExternalLink>
				</div>
			</CardHeader>

			<CardBody>
				<div className="telex-card-meta">
					{ isInstalled && (
						<span className="telex-meta-item">
							{ sprintf(
								/* translators: %s: version number */
								__( 'v%s installed', 'dispatch' ),
								installed.version
							) }
						</span>
					) }
					{ isInstalled && needsUpdate && (
						<span className="telex-meta-item telex-meta-item--new">
							{ sprintf(
								/* translators: %s: version number */
								__( 'v%s available', 'dispatch' ),
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
									__( 'v%s', 'dispatch' ),
									project.currentVersion
								) }
							</span>
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
					{ isNetworkAdmin ? (
						<Tooltip
							text={ __(
								'Push to all network sites',
								'dispatch'
							) }
						>
							<Button
								variant="primary"
								onClick={ () => setShowNetworkDeploy( true ) }
								disabled={ isBusy }
								icon={ isBusy ? null : globe }
								__next40pxDefaultSize
							>
								{ __( 'Deploy', 'dispatch' ) }
							</Button>
						</Tooltip>
					) : (
						<>
							{ ! isInstalled && (
								<Tooltip
									text={ __(
										'Add to your site',
										'dispatch'
									) }
								>
									<Button
										variant="primary"
										onClick={ handleInstall }
										disabled={ isBusy }
										icon={ isBusy ? null : download }
										isBusy={
											isBusy &&
											installStatus === 'installing'
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
										onClick={ () =>
											setShowChangelog( true )
										}
										disabled={ isBusy }
										icon={ isBusy ? null : updateIcon }
										isBusy={
											isBusy &&
											installStatus === 'installing'
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
											isBusy &&
											installStatus === 'removing'
										}
										__next40pxDefaultSize
									>
										{ __( 'Remove', 'dispatch' ) }
									</Button>
								</Tooltip>
							) }
						</>
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
						aria-describedby="telex-remove-warning"
					>
						<p id="telex-remove-warning">
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

				{ showDetail && (
					<ProjectDetailModal
						project={ project }
						installed={ installed }
						onClose={ () => setShowDetail( false ) }
						onInstall={ handleInstall }
						onUpdate={ () => setShowChangelog( true ) }
						onRemove={ () => setConfirmRemove( project.publicId ) }
					/>
				) }

				{ showChangelog && installed && needsUpdate && (
					<ChangelogModal
						project={ project }
						installed={ installed }
						onConfirm={ () => {
							setShowChangelog( false );
							handleUpdate();
						} }
						onCancel={ () => setShowChangelog( false ) }
					/>
				) }

				{ showNetworkDeploy && (
					<NetworkDeployModal
						project={ project }
						restUrl={ restUrl }
						onClose={ () => setShowNetworkDeploy( false ) }
					/>
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
	const webhookUrl = container?.dataset?.webhookUrl || '';
	const webhookSecret = container?.dataset?.webhookSecret || '';
	const isNetworkAdmin = container?.dataset?.isNetwork === '1';

	// Toast state lives here (not Redux) so undoFn closures work cleanly.
	const [ toasts, setToasts ] = useState( [] );
	const [ showShortcuts, setShowShortcuts ] = useState( false );
	const searchInputRef = useRef( null );

	function addToast( toast ) {
		setToasts( ( prev ) => [
			...prev,
			{ id: Date.now() + Math.random(), ...toast },
		] );
	}
	function removeToast( id ) {
		setToasts( ( prev ) => prev.filter( ( t ) => t.id !== id ) );
	}

	const {
		setProjects,
		setInstalledProjects,
		setLoading,
		setError,
		setAuthExpired,
		setSearchQuery,
		setCurrentPage,
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
	const currentPage = useSelect( ( select ) =>
		select( 'telex/admin' ).getCurrentPage()
	);
	const perPage = useSelect( ( select ) =>
		select( 'telex/admin' ).getPerPage()
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

	// Keyboard shortcuts.
	useEffect( () => {
		function onKeyDown( e ) {
			const active = e.target?.ownerDocument?.activeElement;
			const tag = active?.tagName?.toLowerCase();
			const isEditable =
				tag === 'input' ||
				tag === 'textarea' ||
				active?.isContentEditable;
			if ( isEditable ) {
				return;
			}

			switch ( e.key ) {
				case 'r':
				case 'R':
					e.preventDefault();
					setLoading( true );
					fetchData( true ).finally( () => setLoading( false ) );
					break;
				case '/':
					e.preventDefault();
					// Focus the first input inside the SearchControl.
					const searchEl = document.querySelector(
						'.telex-toolbar .components-search-control__input'
					);
					searchEl?.focus();
					break;
				case 'Escape':
					if ( searchQuery ) {
						setSearchQuery( '' );
					}
					break;
				case '?':
					e.preventDefault();
					setShowShortcuts( ( v ) => ! v );
					break;
				default:
					break;
			}
		}
		document.addEventListener( 'keydown', onKeyDown );
		return () => document.removeEventListener( 'keydown', onKeyDown );
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ searchQuery ] );

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
			heartbeatData.telex_poll = true; // eslint-disable-line camelcase
		};

		const onHeartbeatTick = ( _e, data ) => {
			if ( ! data?.telex ) {
				return;
			}
			if ( data.telex.update_count > updatesCount ) {
				setLoading( true );
				fetchData( true ).finally( () => setLoading( false ) );
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

			<div className="telex-toolbar">
				<div role="search">
					<SearchControl
						ref={ searchInputRef }
						label={ __( 'Search projects', 'dispatch' ) }
						value={ searchQuery }
						onChange={ setSearchQuery }
						__nextHasNoMarginBottom
					/>
				</div>
				<div className="telex-toolbar-right">
					<Tooltip
						text={ __( 'Keyboard shortcuts (?)', 'dispatch' ) }
					>
						<Button
							variant="tertiary"
							icon={ keyboardReturn }
							onClick={ () => setShowShortcuts( true ) }
							aria-label={ __(
								'Keyboard shortcuts',
								'dispatch'
							) }
							__next40pxDefaultSize
						/>
					</Tooltip>
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
						{ loading ? (
							<Spinner aria-hidden={ true } />
						) : (
							__( 'Refresh', 'dispatch' )
						) }
					</Button>
					<a
						href={ disconnectUrl }
						className="button button-secondary telex-disconnect"
					>
						{ __( 'Disconnect', 'dispatch' ) }
					</a>
				</div>
			</div>

			<div
				className="telex-loading"
				role="status"
				aria-live="polite"
				aria-atomic="true"
			>
				{ loading && (
					<>
						<Spinner aria-hidden={ true } />
						<span>
							{ __( 'Loading your projects…', 'dispatch' ) }
						</span>
					</>
				) }
			</div>

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

						return (
							<>
								<div
									className="telex-project-grid"
									role="list"
									aria-label={ __(
										'Dispatch projects',
										'dispatch'
									) }
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
													onToast={ addToast }
													isNetworkAdmin={
														isNetworkAdmin
													}
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

			{ webhookUrl && (
				<WebhookPanel
					webhookUrl={ webhookUrl }
					webhookSecret={ webhookSecret }
					restUrl={ restUrl }
				/>
			) }

			<ToastList toasts={ toasts } onDismiss={ removeToast } />

			{ showShortcuts && (
				<KeyboardShortcutsModal
					onClose={ () => setShowShortcuts( false ) }
				/>
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
					role="alert"
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
