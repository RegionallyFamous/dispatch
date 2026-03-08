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
	useMemo,
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
	CheckboxControl,
	ExternalLink,
	Modal,
	Notice,
	SearchControl,
	SelectControl,
	Spinner,
	TextareaControl,
	TextControl,
	Tooltip,
	Icon,
} from '@wordpress/components';
import { __, sprintf, _n } from '@wordpress/i18n';
import { useCommand } from '@wordpress/commands';
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
	keyboardReturn,
	pencil,
	seen,
	unseen,
	lock,
	lockSmall,
	timeToRead,
	people,
	shield,
	chartBar,
	starEmpty,
	starFilled,
	tag as tagIcon,
	cautionFilled,
} from '@wordpress/icons';
import { getAvatarGradient, djb2, relativeDate } from './utils';
import { reducer, actions, selectors, DEFAULT_STATE } from './store';

// ---------------------------------------------------------------------------
// Project avatar
// ---------------------------------------------------------------------------

/**
 * Five decorative SVG shapes used as subtle background accents on avatars.
 * Picked deterministically from the project's hash so each avatar is unique.
 * All shapes use semi-transparent white to layer over any gradient cleanly.
 */
const AVATAR_DECORATIONS = [
	// Quarter-circle bleeding off the bottom-right corner.
	<circle key="0" cx="38" cy="38" r="22" fill="rgba(255,255,255,0.15)" />,
	// Large circle floating in the top-right.
	<circle key="1" cx="33" cy="7" r="16" fill="rgba(255,255,255,0.13)" />,
	// Two offset circles — top-right small, bottom-left large.
	<>
		<circle key="2a" cx="32" cy="8" r="9" fill="rgba(255,255,255,0.18)" />
		<circle key="2b" cx="6" cy="34" r="14" fill="rgba(255,255,255,0.13)" />
	</>,
	// Rotated square (diamond) in the bottom-right quadrant.
	<rect
		key="3"
		x="22"
		y="22"
		width="28"
		height="28"
		rx="4"
		transform="rotate(45 36 36)"
		fill="rgba(255,255,255,0.13)"
	/>,
	// Gentle arc sweeping across the lower half.
	<path
		key="4"
		d="M -4 30 Q 20 16 44 30"
		stroke="rgba(255,255,255,0.28)"
		strokeWidth="3.5"
		fill="none"
		strokeLinecap="round"
	/>,
];

/**
 * SVG avatar with a unique gradient + geometric accent, seeded from publicId.
 * Zero API calls — every project gets a distinct avatar from its ID alone.
 *
 * @param {Object} root0          Component props.
 * @param {string} root0.name     Project display name.
 * @param {string} root0.publicId Telex project public ID (gradient + shape seed).
 * @return {import('@wordpress/element').WPElement} Avatar SVG element.
 */
function ProjectAvatar( { name, publicId } ) {
	const seed = publicId || name || '?';
	const [ c1, c2 ] = getAvatarGradient( seed );
	const decoration =
		AVATAR_DECORATIONS[
			Math.abs( djb2( seed ) ) % AVATAR_DECORATIONS.length
		];
	const initial = ( name || '?' ).charAt( 0 ).toUpperCase();
	// Gradient IDs must be unique in the SVG DOM. Prefix with 'ag' (always a
	// letter) then take alphanumeric chars from the seed so it's a valid XML name.
	const gid = 'ag' + seed.replace( /[^a-zA-Z0-9]/g, '' ).substring( 0, 16 );

	return (
		<svg
			className="telex-project-avatar"
			viewBox="0 0 40 40"
			aria-hidden="true"
		>
			<defs>
				<linearGradient id={ gid } x1="0%" y1="0%" x2="100%" y2="100%">
					<stop offset="0%" stopColor={ c1 } />
					<stop offset="100%" stopColor={ c2 } />
				</linearGradient>
			</defs>
			<rect width="40" height="40" rx="6" fill={ `url(#${ gid })` } />
			{ decoration }
			<text
				x="20"
				y="26"
				textAnchor="middle"
				fill="white"
				fontSize="16"
				fontWeight="bold"
				style={ {
					fontFamily:
						'-apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif',
					userSelect: 'none',
				} }
			>
				{ initial }
			</text>
		</svg>
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

// Merge the runtime per-page preference into the default state.
const runtimeDefaultState = { ...DEFAULT_STATE, perPage: INITIAL_PER_PAGE };

const store = createReduxStore( 'telex/admin', {
	reducer: ( state = runtimeDefaultState, action ) =>
		reducer( state, action ),
	actions,
	selectors,
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
		return null;
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

function SkeletonCard() {
	return (
		<div className="telex-skeleton-row" aria-hidden="true">
			<div className="telex-skeleton telex-skeleton--avatar" />
			<div className="telex-skeleton-row__identity">
				<div className="telex-skeleton telex-skeleton--title" />
				<div className="telex-skeleton telex-skeleton--badge" />
			</div>
			<div className="telex-skeleton-row__spacer" />
			<div className="telex-skeleton telex-skeleton--icon" />
			<div className="telex-skeleton telex-skeleton--button" />
		</div>
	);
}

function ActivityTableSkeleton() {
	return (
		<table
			className="wp-list-table widefat fixed striped telex-activity-table"
			aria-hidden="true"
		>
			<thead>
				<tr>
					<th>{ __( 'Action', 'dispatch' ) }</th>
					<th>{ __( 'Project', 'dispatch' ) }</th>
					<th>{ __( 'User', 'dispatch' ) }</th>
					<th>{ __( 'Date', 'dispatch' ) }</th>
				</tr>
			</thead>
			<tbody>
				{ Array.from( { length: 8 } ).map( ( _, i ) => (
					<tr key={ i } className="telex-skeleton-table-row">
						<td>
							<div className="telex-skeleton telex-skeleton--cell-badge" />
						</td>
						<td>
							<div className="telex-skeleton telex-skeleton--cell-id" />
						</td>
						<td>
							<div className="telex-skeleton telex-skeleton--cell-user" />
						</td>
						<td>
							<div className="telex-skeleton telex-skeleton--cell-date" />
						</td>
					</tr>
				) ) }
			</tbody>
		</table>
	);
}

function HealthTableSkeleton() {
	return (
		<table
			className="wp-list-table widefat fixed striped telex-health-table"
			aria-hidden="true"
		>
			<thead>
				<tr>
					<th>{ __( 'Project', 'dispatch' ) }</th>
					<th>{ __( 'Active', 'dispatch' ) }</th>
					<th>{ __( 'PHP Compat', 'dispatch' ) }</th>
					<th>{ __( 'Block Registered', 'dispatch' ) }</th>
					<th>{ __( 'Error Log', 'dispatch' ) }</th>
					<th>{ __( 'Status', 'dispatch' ) }</th>
				</tr>
			</thead>
			<tbody>
				{ Array.from( { length: 6 } ).map( ( _, i ) => (
					<tr key={ i } className="telex-skeleton-table-row">
						<td>
							<div className="telex-skeleton telex-skeleton--cell-id" />
						</td>
						<td>
							<div className="telex-skeleton telex-skeleton--cell-indicator" />
						</td>
						<td>
							<div className="telex-skeleton telex-skeleton--cell-indicator" />
						</td>
						<td>
							<div className="telex-skeleton telex-skeleton--cell-indicator" />
						</td>
						<td>
							<div className="telex-skeleton telex-skeleton--cell-indicator" />
						</td>
						<td>
							<div className="telex-skeleton telex-skeleton--cell-badge" />
						</td>
					</tr>
				) ) }
			</tbody>
		</table>
	);
}

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
			heading: __( 'Everything is up to date', 'dispatch' ),
			body: __(
				'All your installed projects are running the latest build.',
				'dispatch'
			),
			accent: true,
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
			<div
				className={
					'telex-empty-state__icon' +
					( state.accent ? ' telex-empty-state__icon--success' : '' )
				}
			>
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
	{ keys: [ '←', '→' ], label: __( 'Switch tabs', 'dispatch' ) },
	{
		keys: [ 'U' ],
		label: __( 'Update all (when updates available)', 'dispatch' ),
	},
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
					<p className="telex-changelog-section-label">
						{ __( 'About this project', 'dispatch' ) }
					</p>
					{ project.description }
				</div>
			) }

			<p style={ { marginBottom: 12 } }>
				<ExternalLink
					href={ `https://telex.automattic.ai/projects/${ project.publicId }` }
				>
					{ __( "See what's new in Telex →", 'dispatch' ) }
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
 * @param {Function}             fetch        apiFetch bound with the correct middleware.
 * @param {string}               url          REST base URL.
 * @param {string}               publicId     Project public ID.
 * @param {Function}             onBuilding   Called with poll_interval when the build starts.
 * @param {boolean}              activate     Whether to activate the plugin/theme after install.
 * @param {{ cancelled: false }} cancelSignal Pass { cancelled: false }; set .cancelled = true to abort.
 * @return {Promise<Object>} Final install response data.
 */
async function installWithPolling(
	fetch,
	url,
	publicId,
	onBuilding,
	activate = false,
	cancelSignal = null
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
	// Cap the server-supplied interval so a misconfigured response can't stall
	// the UI indefinitely.
	const MAX_POLL_INTERVAL = 30;
	let pollInterval = Math.min( data.poll_interval || 5, MAX_POLL_INTERVAL );

	for ( let attempt = 0; attempt < MAX_POLLS; attempt++ ) {
		// Bail out immediately if the component unmounted or the user navigated away.
		if ( cancelSignal?.cancelled ) {
			throw new Error( __( 'Installation was cancelled.', 'dispatch' ) );
		}

		// eslint-disable-next-line no-await-in-loop
		await new Promise( ( r ) => setTimeout( r, pollInterval * 1000 ) );

		if ( cancelSignal?.cancelled ) {
			throw new Error( __( 'Installation was cancelled.', 'dispatch' ) );
		}

		// eslint-disable-next-line no-await-in-loop
		const buildStatus = await fetch( {
			url: `${ url }/projects/${ publicId }/build`,
		} );

		if ( buildStatus.poll_interval ) {
			pollInterval = Math.min(
				buildStatus.poll_interval,
				MAX_POLL_INTERVAL
			);
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
 * @param {boolean}  root0.showCheckbox   Whether multi-select checkboxes are visible.
 * @param {boolean}  root0.isSelected     Whether this row is currently selected.
 * @param {Function} root0.onToggleSelect Called with publicId when checkbox is toggled.
 * @param {Object}   root0.analyticsData  Block usage counts keyed by publicId.
 * @return {import('@wordpress/element').WPElement} Rendered element.
 */
function ProjectCard( {
	project,
	restUrl,
	onRefresh,
	onToast,
	isNetworkAdmin,
	showCheckbox,
	isSelected,
	onToggleSelect,
	analyticsData,
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
	const [ showNoteEditor, setShowNoteEditor ] = useState( false );
	const [ noteValue, setNoteValue ] = useState( '' );
	const [ conflictData, setConflictData ] = useState( null );
	const [ showPinModal, setShowPinModal ] = useState( false );
	const [ pinReason, setPinReason ] = useState( '' );
	const [ pinBusy, setPinBusy ] = useState( false );
	const [ autoUpdateMode, setAutoUpdateMode ] = useState(
		project._auto_update || 'off'
	);
	const [ autoUpdateBusy, setAutoUpdateBusy ] = useState( false );

	// Favorites (optimistic UI).
	const [ isStarred, setIsStarred ] = useState( !! project._favorite );

	// Tags inline editor.
	const [ showTagEditor, setShowTagEditor ] = useState( false );
	const [ tagInput, setTagInput ] = useState( '' );
	const [ tags, setTags ] = useState( project._tags || [] );
	const [ tagsBusy, setTagsBusy ] = useState( false );

	// Load saved note on mount.
	useEffect( () => {
		apiFetch( { url: `${ restUrl }/projects/${ project.publicId }/note` } )
			.then( ( d ) => setNoteValue( d?.note || '' ) )
			.catch( () => {} );
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ project.publicId ] );

	const saveNote = useCallback( async () => {
		try {
			await apiFetch( {
				url: `${ restUrl }/projects/${ project.publicId }/note`,
				method: 'PUT',
				data: { note: noteValue },
			} );
		} catch {} // eslint-disable-line no-empty
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ noteValue, project.publicId, restUrl ] );

	const isActive =
		project._is_active !== false && project._is_active !== undefined
			? project._is_active
			: null;

	// These must be declared before any useCallback that references them
	// to avoid a Temporal Dead Zone error during webpack scope hoisting.
	const installed = installedProjects[ project.publicId ];
	const needsUpdate = installed && project.currentVersion > installed.version;
	const isInstalled = !! installed;
	const isBusy =
		installStatus === 'installing' ||
		installStatus === 'removing' ||
		installStatus === 'building';
	const typeStr = project.projectType?.toLowerCase() || 'block';
	const isBlock = typeStr !== 'theme';

	const isPinned = !! project._pin;
	const pinInfo = project._pin || null;

	// Usage count from block analytics.
	const usageCount = analyticsData?.[ project.publicId ] ?? null;

	// Soak period countdown for delayed auto-updates.
	const soakQueuedAt = project._auto_update_queued_at || null;
	const soakHoursLeft = ( () => {
		if ( autoUpdateMode !== 'delayed_24h' || ! soakQueuedAt ) {
			return null;
		}
		const elapsed =
			( Date.now() - new Date( soakQueuedAt ).getTime() ) / 3600000;
		const remaining = Math.max( 0, 24 - elapsed );
		return remaining < 1
			? Math.ceil( remaining * 60 ) + 'm'
			: Math.ceil( remaining ) + 'h';
	} )();

	const handlePinSubmit = useCallback( async () => {
		if ( ! pinReason.trim() ) {
			return;
		}
		setPinBusy( true );
		try {
			await apiFetch( {
				url: `${ restUrl }/projects/${ project.publicId }/pin`,
				method: 'POST',
				data: {
					version: installed?.version,
					reason: pinReason.trim(),
				},
			} );
			setPinReason( '' );
			setShowPinModal( false );
			onRefresh();
			onToast( {
				type: 'success',
				message: sprintf(
					/* translators: %s: project name */
					__( '%s pinned.', 'dispatch' ),
					project.name
				),
			} );
		} catch ( e ) {
			onToast( {
				type: 'error',
				message:
					e.message || __( 'Could not pin project.', 'dispatch' ),
			} );
		} finally {
			setPinBusy( false );
		}
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ pinReason, project.publicId, restUrl, installed ] );

	const handleUnpin = useCallback( async () => {
		setPinBusy( true );
		try {
			await apiFetch( {
				url: `${ restUrl }/projects/${ project.publicId }/pin`,
				method: 'DELETE',
			} );
			onRefresh();
			onToast( {
				type: 'success',
				message: sprintf(
					/* translators: %s: project name */
					__( '%s unpinned.', 'dispatch' ),
					project.name
				),
			} );
		} catch ( e ) {
			onToast( {
				type: 'error',
				message:
					e.message || __( 'Could not unpin project.', 'dispatch' ),
			} );
		} finally {
			setPinBusy( false );
		}
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ project.publicId, restUrl ] );

	const handleAutoUpdateChange = useCallback(
		async ( mode ) => {
			setAutoUpdateMode( mode );
			setAutoUpdateBusy( true );
			try {
				await apiFetch( {
					url: `${ restUrl }/projects/${ project.publicId }/auto-update`,
					method: 'PUT',
					data: { mode },
				} );
				onRefresh();
			} catch ( e ) {
				onToast( {
					type: 'error',
					message:
						e.message ||
						__( 'Could not save auto-update setting.', 'dispatch' ),
				} );
			} finally {
				setAutoUpdateBusy( false );
			}
		},
		// eslint-disable-next-line react-hooks/exhaustive-deps
		[ project.publicId, restUrl ]
	);

	const handleToggleFavorite = useCallback( async () => {
		const next = ! isStarred;
		setIsStarred( next ); // Optimistic update.
		try {
			await apiFetch( {
				url: `${ restUrl }/projects/${ project.publicId }/favorite`,
				method: next ? 'POST' : 'DELETE',
			} );
			// Refresh so _favorite is set on the project object and the
			// sort order re-evaluates, floating starred items to the top.
			onRefresh();
		} catch {
			setIsStarred( ! next ); // Revert on error.
		}
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ isStarred, project.publicId, restUrl ] );

	const persistTags = useCallback(
		async ( nextTags ) => {
			setTagsBusy( true );
			try {
				const resp = await apiFetch( {
					url: `${ restUrl }/projects/${ project.publicId }/tags`,
					method: 'PUT',
					data: { tags: nextTags },
				} );
				if ( resp?.tags ) {
					setTags( resp.tags );
				}
			} catch ( e ) {
				onToast( {
					type: 'error',
					message:
						e.message || __( 'Could not save tags.', 'dispatch' ),
				} );
			} finally {
				setTagsBusy( false );
			}
		},
		// eslint-disable-next-line react-hooks/exhaustive-deps
		[ project.publicId, restUrl ]
	);

	const addTag = useCallback( () => {
		const clean = tagInput
			.trim()
			.toLowerCase()
			.replace( /[^a-z0-9_-]/g, '-' );
		if ( clean && ! tags.includes( clean ) && tags.length < 20 ) {
			const nextTags = [ ...tags, clean ];
			setTags( nextTags );
			persistTags( nextTags );
		}
		setTagInput( '' );
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ tagInput, tags, persistTags ] );

	const removeTag = useCallback(
		( t ) => {
			const nextTags = tags.filter( ( x ) => x !== t );
			setTags( nextTags );
			persistTags( nextTags );
		},
		// eslint-disable-next-line react-hooks/exhaustive-deps
		[ tags, persistTags ]
	);

	// Refs for cleanup on unmount.
	const cancelSignalRef = useRef( { cancelled: false } );
	const step4TimerRef = useRef( null );

	useEffect( () => {
		return () => {
			// Cancel any in-flight polling loop when this card unmounts.
			cancelSignalRef.current.cancelled = true;
			clearTimeout( step4TimerRef.current );
		};
	}, [] );

	/**
	 * Shared install/update handler.
	 * @param {string}  successMessage
	 * @param {boolean} [activate]
	 */
	async function executeInstall( successMessage, activate = false ) {
		// Create a fresh signal for this install attempt.
		const cancelSignal = { cancelled: false };
		cancelSignalRef.current = cancelSignal;

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
					// and reset to step 1 so the indicator shows the accurate state
					// rather than a false partial-complete while waiting for the build.
					clearStepTimers();
					setInstallStep( project.publicId, 1 );
					setInstallStatus( project.publicId, 'building' );
				},
				activate,
				cancelSignal
			);

			clearStepTimers();
			setInstallStep( project.publicId, 4 );
			step4TimerRef.current = setTimeout(
				() => clearInstallStep( project.publicId ),
				600
			);
			onToast( { type: 'success', message: successMessage } );
			await onRefresh();
			setInstallStatus( project.publicId, 'idle' );
		} catch ( err ) {
			clearStepTimers();
			clearInstallStep( project.publicId );
			setInstallStatus( project.publicId, 'failed' );
			if ( err?.code === 'slug_conflict' && err?.data?.conflict_name ) {
				setConflictData( err.data );
			} else {
				onToast( {
					type: 'error',
					message:
						err.message ||
						__( "That didn't work. Try again?", 'dispatch' ),
				} );
			}
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

	// Pick the most useful single timestamp to show in the row meta.
	const timestamp = ( () => {
		if ( isInstalled && showUpdatedAgo ) {
			return sprintf(
				/* translators: %s: relative time */
				__( 'Updated %s', 'dispatch' ),
				updatedAgo
			);
		}
		if ( isInstalled && installedAgo ) {
			return sprintf(
				/* translators: %s: relative time */
				__( 'Installed %s', 'dispatch' ),
				installedAgo
			);
		}
		if ( project.updatedAt && relativeDate( project.updatedAt ) ) {
			return sprintf(
				/* translators: %s: relative time */
				__( 'Built %s', 'dispatch' ),
				relativeDate( project.updatedAt )
			);
		}
		return null;
	} )();

	const isFailed = !! project._failed;

	return (
		<div
			className={ [
				'telex-project-row',
				`telex-project-row--${ typeStr }`,
				needsUpdate ? 'telex-project-row--has-update' : '',
				isInstalled ? 'telex-project-row--installed' : '',
				isBusy ? 'telex-project-row--busy' : '',
				isSelected ? 'telex-project-row--selected' : '',
				isPinned ? 'telex-project-row--pinned' : '',
				isStarred ? 'telex-project-row--starred' : '',
				isFailed ? 'telex-project-row--failed' : '',
			]
				.filter( Boolean )
				.join( ' ' ) }
			data-public-id={ project.publicId }
		>
			{ /* Optional checkbox for multi-select */ }
			{ showCheckbox &&
				( () => {
					const checkId = `telex-select-${ project.publicId }`;
					return (
						<>
							<label
								className="telex-row-checkbox"
								htmlFor={ checkId }
							>
								<span className="screen-reader-text">
									{ sprintf(
										/* translators: %s: project name */
										__( 'Select %s', 'dispatch' ),
										project.name
									) }
								</span>
							</label>
							<input
								id={ checkId }
								type="checkbox"
								className="telex-row-checkbox-input"
								checked={ isSelected }
								onChange={ () =>
									onToggleSelect( project.publicId )
								}
							/>
						</>
					);
				} )() }
			{ /* Star button — always present, fades in on hover or when starred */ }
			<Tooltip
				text={
					isStarred
						? __( 'Remove from favorites', 'dispatch' )
						: __( 'Add to favorites', 'dispatch' )
				}
			>
				<button
					type="button"
					className={ [
						'telex-star-btn',
						isStarred ? 'telex-star-btn--starred' : '',
					]
						.filter( Boolean )
						.join( ' ' ) }
					onClick={ handleToggleFavorite }
					aria-label={
						isStarred
							? sprintf(
									/* translators: %s: project name */
									__( 'Unstar %s', 'dispatch' ),
									project.name
							  )
							: sprintf(
									/* translators: %s: project name */
									__( 'Star %s', 'dispatch' ),
									project.name
							  )
					}
					aria-pressed={ isStarred }
				>
					<Icon
						icon={ isStarred ? starFilled : starEmpty }
						size={ 20 }
					/>
				</button>
			</Tooltip>
			{ /* Identity — avatar + name + type */ }
			<div className="telex-row-identity">
				<ProjectAvatar
					name={ project.name }
					publicId={ project.publicId }
				/>
				<div className="telex-row-name">
					<button
						type="button"
						className="telex-row-title-btn"
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
					{ isPinned && (
						<Tooltip
							text={ sprintf(
								/* translators: %s: pin reason */
								__( 'Pinned: %s', 'dispatch' ),
								pinInfo?.reason || ''
							) }
						>
							<span
								className="telex-pin-badge"
								aria-label={ __(
									'Version pinned',
									'dispatch'
								) }
							>
								<Icon icon={ lockSmall } size={ 14 } />
								{ sprintf(
									/* translators: %s: version number */
									__( 'v%s', 'dispatch' ),
									pinInfo?.version || ''
								) }
							</span>
						</Tooltip>
					) }
					{ usageCount !== null && usageCount > 0 && (
						<Tooltip
							text={ sprintf(
								/* translators: %d: post count */
								__( 'Used in %d posts/pages', 'dispatch' ),
								usageCount
							) }
						>
							<span className="telex-usage-badge">
								<Icon icon={ chartBar } size={ 12 } />
								{ usageCount }
							</span>
						</Tooltip>
					) }
					{ soakHoursLeft !== null && (
						<Tooltip
							text={ __(
								'Auto-update queued, waiting out soak period',
								'dispatch'
							) }
						>
							<span className="telex-soak-badge">
								<Icon icon={ timeToRead } size={ 12 } />
								{ sprintf(
									/* translators: %s: time remaining */
									__( '%s left', 'dispatch' ),
									soakHoursLeft
								) }
							</span>
						</Tooltip>
					) }
					{ isInstalled && isActive === false && (
						<span className="telex-inactive-badge">
							{ __( 'Inactive', 'dispatch' ) }
						</span>
					) }
					{ isFailed && (
						<span className="telex-failed-badge">
							<Icon icon={ cautionFilled } size={ 12 } />
							{ __( 'Install failed', 'dispatch' ) }
						</span>
					) }
				</div>
			</div>

			{ /* Meta — one clear state, no duplication with the actions zone */ }
			<div className="telex-row-meta">
				{ installStep !== null && (
					<InstallProgress currentStep={ installStep } />
				) }
				{ installStep === null && isBusy && (
					<StatusBadge
						publicId={ project.publicId }
						remoteVersion={ project.currentVersion }
						installed={ installed }
					/>
				) }
				{ installStep === null && ! isBusy && (
					<>
						{ /* Update available: version-diff badge only */ }
						{ needsUpdate && (
							<StatusBadge
								publicId={ project.publicId }
								remoteVersion={ project.currentVersion }
								installed={ installed }
							/>
						) }
					</>
				) }
			</div>

			{ /* Actions — primary + icon-only secondary */ }
			<div
				className="telex-row-actions"
				role="group"
				aria-label={ sprintf(
					/* translators: %s: project name */
					__( 'Actions for %s', 'dispatch' ),
					project.name
				) }
			>
				{ isNetworkAdmin ? (
					<Tooltip
						text={ __( 'Push to all network sites', 'dispatch' ) }
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
						) }
						{ isInstalled && needsUpdate && (
							<Button
								variant="primary"
								onClick={ () => setShowChangelog( true ) }
								disabled={ isBusy }
								icon={ isBusy ? null : updateIcon }
								isBusy={
									isBusy && installStatus === 'installing'
								}
								__next40pxDefaultSize
							>
								{ __( 'Update', 'dispatch' ) }
							</Button>
						) }
					</>
				) }

				<div className="telex-row-secondary">
					{ isInstalled &&
						! needsUpdate &&
						( timestamp ? (
							<Tooltip text={ timestamp }>
								<span
									className="telex-row-version"
									tabIndex={ 0 }
								>
									{ sprintf(
										/* translators: %s: version number */
										__( 'v%s', 'dispatch' ),
										installed.version
									) }
								</span>
							</Tooltip>
						) : (
							<span className="telex-row-version">
								{ sprintf(
									/* translators: %s: version number */
									__( 'v%s', 'dispatch' ),
									installed.version
								) }
							</span>
						) ) }
					{ isInstalled && isActive === false && (
						<Tooltip
							text={ __( 'Activate on this site', 'dispatch' ) }
						>
							<Button
								variant="secondary"
								icon={ check }
								onClick={ async () => {
									try {
										await apiFetch( {
											url: `${ restUrl }/projects/${ project.publicId }/activate`,
											method: 'POST',
										} );
										onRefresh();
										onToast( {
											type: 'success',
											message: sprintf(
												/* translators: %s: project name */ __(
													'%s activated.',
													'dispatch'
												),
												project.name
											),
										} );
									} catch ( e ) {
										onToast( {
											type: 'error',
											message:
												e.message ||
												__(
													'Activation failed.',
													'dispatch'
												),
										} );
									}
								} }
								disabled={ isBusy }
								aria-label={ __(
									'Activate on this site',
									'dispatch'
								) }
								__next40pxDefaultSize
							/>
						</Tooltip>
					) }
					{ isInstalled &&
						isActive === true &&
						project.type !== 'theme' && (
							<Tooltip
								text={ __(
									'Deactivate on this site',
									'dispatch'
								) }
							>
								<Button
									variant="tertiary"
									icon={ unseen }
									onClick={ async () => {
										try {
											await apiFetch( {
												url: `${ restUrl }/projects/${ project.publicId }/deactivate`,
												method: 'POST',
											} );
											onRefresh();
											onToast( {
												type: 'success',
												message: sprintf(
													/* translators: %s: project name */ __(
														'%s deactivated.',
														'dispatch'
													),
													project.name
												),
											} );
										} catch ( e ) {
											onToast( {
												type: 'error',
												message:
													e.message ||
													__(
														'Deactivation failed.',
														'dispatch'
													),
											} );
										}
									} }
									disabled={ isBusy }
									aria-label={ __(
										'Deactivate on this site',
										'dispatch'
									) }
									__next40pxDefaultSize
								/>
							</Tooltip>
						) }
					<Tooltip
						text={
							showNoteEditor
								? __( 'Close note', 'dispatch' )
								: noteValue || __( 'Add note', 'dispatch' )
						}
					>
						<Button
							variant="tertiary"
							icon={ showNoteEditor ? seen : pencil }
							onClick={ () => setShowNoteEditor( ( v ) => ! v ) }
							aria-label={
								noteValue
									? sprintf(
											/* translators: %s: note text */
											__( 'Note: %s', 'dispatch' ),
											noteValue
									  )
									: __( 'Add note', 'dispatch' )
							}
							__next40pxDefaultSize
						/>
					</Tooltip>
					{ isInstalled && (
						<Tooltip
							text={
								isPinned
									? __( 'Unpin version', 'dispatch' )
									: __( 'Pin version', 'dispatch' )
							}
						>
							<Button
								variant="tertiary"
								icon={ isPinned ? lock : lockSmall }
								onClick={
									isPinned
										? handleUnpin
										: () => setShowPinModal( true )
								}
								disabled={ isBusy || pinBusy }
								isBusy={ pinBusy }
								aria-label={
									isPinned
										? __( 'Unpin version', 'dispatch' )
										: __( 'Pin version', 'dispatch' )
								}
								className={ isPinned ? 'telex-btn-pinned' : '' }
								__next40pxDefaultSize
							/>
						</Tooltip>
					) }
					{ isInstalled && ! isPinned && (
						<Tooltip text={ __( 'Auto-update', 'dispatch' ) }>
							<SelectControl
								label={ __( 'Auto-update', 'dispatch' ) }
								hideLabelFromVision
								value={ autoUpdateMode }
								className="telex-autoupdate-select"
								options={ [
									{
										value: 'off',
										label: __( 'Off', 'dispatch' ),
									},
									{
										value: 'immediate',
										label: __( 'Immediate', 'dispatch' ),
									},
									{
										value: 'delayed_24h',
										label: __( 'Delayed 24h', 'dispatch' ),
									},
								] }
								onChange={ handleAutoUpdateChange }
								disabled={ autoUpdateBusy }
								__nextHasNoMarginBottom
								__next40pxDefaultSize
							/>
						</Tooltip>
					) }
					<Tooltip
						text={ sprintf(
							/* translators: %s: project name */
							__( 'Edit %s in Telex', 'dispatch' ),
							project.name
						) }
					>
						<Button
							variant="tertiary"
							href={ `https://telex.automattic.ai/projects/${ project.publicId }` }
							target="_blank"
							rel="noreferrer"
							aria-label={ sprintf(
								/* translators: %s: project name */
								__( 'Edit %s in Telex', 'dispatch' ),
								project.name
							) }
							icon={ globe }
							__next40pxDefaultSize
						/>
					</Tooltip>
					{ isInstalled && (
						<Tooltip
							text={ __( 'Remove from your site', 'dispatch' ) }
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
								aria-label={ __(
									'Remove from your site',
									'dispatch'
								) }
								__next40pxDefaultSize
							/>
						</Tooltip>
					) }
					<Tooltip
						text={
							tags.length > 0
								? __( 'Edit tags', 'dispatch' )
								: __( 'Add tags', 'dispatch' )
						}
					>
						<Button
							variant="tertiary"
							icon={ tagIcon }
							onClick={ () => setShowTagEditor( ( v ) => ! v ) }
							aria-label={
								tags.length > 0
									? __( 'Edit tags', 'dispatch' )
									: __( 'Add tags', 'dispatch' )
							}
							className={
								tags.length > 0 ? 'telex-btn-has-tags' : ''
							}
							__next40pxDefaultSize
						/>
					</Tooltip>
				</div>
			</div>
			{ /* Inline note editor */ }
			{ showNoteEditor && (
				<div className="telex-row-note-editor">
					<div className="telex-row-editor-header">
						<span className="telex-row-editor-label">
							{ __( 'Note', 'dispatch' ) }
						</span>
						<button
							type="button"
							className="telex-row-editor-close"
							onClick={ () => setShowNoteEditor( false ) }
							aria-label={ __( 'Close note editor', 'dispatch' ) }
						>
							×
						</button>
					</div>
					<TextareaControl
						label={ __( 'Note', 'dispatch' ) }
						hideLabelFromVision
						value={ noteValue }
						onChange={ setNoteValue }
						rows={ 2 }
						__nextHasNoMarginBottom
					/>
					<div className="telex-row-note-actions">
						<Button
							variant="primary"
							size="small"
							onClick={ async () => {
								await saveNote();
								setShowNoteEditor( false );
							} }
							__next40pxDefaultSize={ false }
						>
							{ __( 'Save', 'dispatch' ) }
						</Button>
					</div>
				</div>
			) }

			{ /* Tag editor */ }
			{ showTagEditor && (
				<div className="telex-row-tag-editor">
					<div className="telex-row-editor-header">
						<span className="telex-row-editor-label">
							{ __( 'Tags', 'dispatch' ) }
						</span>
						{ tagsBusy && <Spinner /> }
						<button
							type="button"
							className="telex-row-editor-close"
							onClick={ () => setShowTagEditor( false ) }
							aria-label={ __( 'Close tag editor', 'dispatch' ) }
						>
							×
						</button>
					</div>
					<div className="telex-row-tag-chips">
						{ tags.map( ( t ) => (
							<span
								key={ t }
								className="telex-tag-chip telex-tag-chip--editable"
							>
								{ t }
								<button
									type="button"
									className="telex-tag-chip__remove"
									onClick={ () => removeTag( t ) }
									aria-label={ sprintf(
										/* translators: %s: tag name */
										__( 'Remove tag %s', 'dispatch' ),
										t
									) }
								>
									×
								</button>
							</span>
						) ) }
					</div>
					<div className="telex-row-tag-input">
						<TextControl
							label={ __( 'Add tag', 'dispatch' ) }
							hideLabelFromVision
							placeholder={ __(
								'e.g. client-a, beta',
								'dispatch'
							) }
							value={ tagInput }
							onChange={ setTagInput }
							onKeyDown={ ( e ) => {
								if ( e.key === 'Enter' ) {
									e.preventDefault();
									addTag();
								}
							} }
							__next40pxDefaultSize
							__nextHasNoMarginBottom
						/>
						<Button
							variant="secondary"
							size="small"
							onClick={ addTag }
							disabled={ tagsBusy || ! tagInput.trim() }
							__next40pxDefaultSize={ false }
						>
							{ __( 'Add', 'dispatch' ) }
						</Button>
					</div>
				</div>
			) }

			{ /* Pin modal */ }
			{ showPinModal && (
				<Modal
					title={ sprintf(
						/* translators: 1: project name, 2: version number */
						__( 'Pin "%1$s" at v%2$s', 'dispatch' ),
						project.name,
						installed?.version || ''
					) }
					onRequestClose={ () => {
						setShowPinModal( false );
						setPinReason( '' );
					} }
				>
					<p className="description">
						{ __(
							'Pinning prevents this project from being updated by auto-update or "Update All". You can unpin it at any time.',
							'dispatch'
						) }
					</p>
					<TextareaControl
						label={ __(
							'Reason for pinning (required)',
							'dispatch'
						) }
						value={ pinReason }
						onChange={ setPinReason }
						rows={ 2 }
						placeholder={ __(
							'e.g. Client requested freeze before launch',
							'dispatch'
						) }
						__nextHasNoMarginBottom
					/>
					<div className="telex-modal-actions">
						<Button
							variant="primary"
							onClick={ handlePinSubmit }
							disabled={ ! pinReason.trim() || pinBusy }
							isBusy={ pinBusy }
							icon={ lock }
							__next40pxDefaultSize
						>
							{ __( 'Pin version', 'dispatch' ) }
						</Button>
						<Button
							variant="secondary"
							onClick={ () => {
								setShowPinModal( false );
								setPinReason( '' );
							} }
							__next40pxDefaultSize
						>
							{ __( 'Cancel', 'dispatch' ) }
						</Button>
					</div>
				</Modal>
			) }
			{ /* Modals */ }
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
								'%s will be removed from your site. You can reinstall it any time.',
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
			{ conflictData && (
				<Modal
					title={ __( 'Conflict detected', 'dispatch' ) }
					onRequestClose={ () => setConflictData( null ) }
				>
					<p>
						{ sprintf(
							/* translators: 1: Telex project name, 2: existing plugin/theme name */
							__(
								'"%1$s" cannot be installed because an existing plugin or theme named "%2$s" is already using the same folder name. Remove the existing one first, or rename the slug in Telex.',
								'dispatch'
							),
							project.name,
							conflictData.conflict_name
						) }
					</p>
					<div className="telex-modal-actions">
						<Button
							variant="primary"
							onClick={ () => setConflictData( null ) }
							__next40pxDefaultSize
						>
							{ __( 'OK', 'dispatch' ) }
						</Button>
					</div>
				</Modal>
			) }
		</div>
	);
}

// ---------------------------------------------------------------------------
// Inline device flow — embedded reconnect modal state machine
// ---------------------------------------------------------------------------

const FLOW_STATUS = {
	IDLE: 'idle',
	STARTING: 'starting',
	WAITING: 'waiting',
	SUCCESS: 'success',
	EXPIRED: 'expired',
	ERROR: 'error',
};

/**
 * Self-contained device flow component that can be embedded in any modal.
 * Calls onSuccess() when the user approves, so the parent can reload data
 * rather than triggering a full-page navigation.
 *
 * @param {Object}   props
 * @param {string}   props.restUrl   Base REST API URL.
 * @param {Function} props.onSuccess Called when authorization succeeds.
 * @param {Function} props.onClose   Called when the user dismisses.
 */
function InlineDeviceFlow( { restUrl, onSuccess, onClose } ) {
	const [ status, setStatus ] = useState( FLOW_STATUS.IDLE );
	const [ deviceData, setDeviceData ] = useState( null );
	const [ errorMsg, setErrorMsg ] = useState( '' );
	const [ copied, setCopied ] = useState( false );
	const pollRef = useRef( null );
	const copyTimeoutRef = useRef( null );
	const pollInFlightRef = useRef( false );
	const statusRef = useRef( status );
	useEffect( () => {
		statusRef.current = status;
	}, [ status ] );

	useEffect( () => {
		const onHeartbeatTick = ( _event, response ) => {
			if (
				response?.telex?.is_connected &&
				statusRef.current === FLOW_STATUS.WAITING
			) {
				stopPolling();
				setStatus( FLOW_STATUS.SUCCESS );
				setTimeout( () => onSuccess(), 1200 );
			}
		};
		if ( window.jQuery ) {
			window
				.jQuery( document )
				.on( 'heartbeat-tick.telex-reconnect', onHeartbeatTick );
			window
				.jQuery( document )
				.on( 'heartbeat-send.telex-reconnect', ( _e, data ) => {
					data.telex_poll = true;
				} );
		}
		return () => {
			stopPolling();
			clearTimeout( copyTimeoutRef.current );
			if ( window.jQuery ) {
				window
					.jQuery( document )
					.off( 'heartbeat-tick.telex-reconnect' );
				window
					.jQuery( document )
					.off( 'heartbeat-send.telex-reconnect' );
			}
		};
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [] );

	function stopPolling() {
		if ( pollRef.current ) {
			clearInterval( pollRef.current );
			pollRef.current = null;
		}
	}

	function startPolling( intervalSeconds ) {
		stopPolling();
		pollRef.current = setInterval(
			() => pollForToken(),
			intervalSeconds * 1000
		);
	}

	async function startDeviceFlow() {
		setStatus( FLOW_STATUS.STARTING );
		setErrorMsg( '' );
		try {
			const data = await apiFetch( {
				url: `${ restUrl }/auth/device`,
				method: 'POST',
			} );
			setDeviceData( data );
			setStatus( FLOW_STATUS.WAITING );
			startPolling( data.interval || 5 );
		} catch ( err ) {
			setErrorMsg(
				err.message ||
					__(
						"Couldn't get a code from Telex. Give it another try.",
						'dispatch'
					)
			);
			setStatus( FLOW_STATUS.ERROR );
		}
	}

	async function pollForToken() {
		if ( pollInFlightRef.current ) {
			return;
		}
		pollInFlightRef.current = true;
		try {
			const data = await apiFetch( { url: `${ restUrl }/auth/device` } );
			if ( data.authorized ) {
				stopPolling();
				setStatus( FLOW_STATUS.SUCCESS );
				setTimeout( () => onSuccess(), 1200 );
				return;
			}
			if ( data.status === 'slow_down' && data.interval ) {
				stopPolling();
				startPolling( data.interval );
			}
		} catch ( err ) {
			const errorCode = err?.code || err?.data?.code || '';
			const isTerminal = [
				'telex_device_expired_token',
				'telex_device_access_denied',
				'telex_no_device_flow',
			].includes( errorCode );
			if ( isTerminal ) {
				stopPolling();
				setErrorMsg(
					err.message ||
						__(
							'Something went wrong — the code may have expired.',
							'dispatch'
						)
				);
				setStatus( FLOW_STATUS.EXPIRED );
			}
		} finally {
			pollInFlightRef.current = false;
		}
	}

	async function cancelDeviceFlow() {
		stopPolling();
		try {
			await apiFetch( {
				url: `${ restUrl }/auth/device`,
				method: 'DELETE',
			} );
		} catch {} // eslint-disable-line no-empty
		setStatus( FLOW_STATUS.IDLE );
		setDeviceData( null );
	}

	function handleCopyCode() {
		if ( ! deviceData?.user_code ) {
			return;
		}
		if ( window.navigator?.clipboard ) {
			window.navigator.clipboard
				.writeText( deviceData.user_code )
				.catch( () => {} );
		}
		setCopied( true );
		clearTimeout( copyTimeoutRef.current );
		copyTimeoutRef.current = setTimeout( () => setCopied( false ), 2500 );
	}

	if ( status === FLOW_STATUS.SUCCESS ) {
		return (
			<div className="telex-inline-flow">
				<Spinner aria-hidden={ true } />
				<p>
					{ __( 'Connected! Refreshing your projects…', 'dispatch' ) }
				</p>
			</div>
		);
	}

	if ( status === FLOW_STATUS.STARTING ) {
		return (
			<div className="telex-inline-flow">
				<Spinner aria-hidden={ true } />
				<p>{ __( 'Getting your code…', 'dispatch' ) }</p>
			</div>
		);
	}

	if ( status === FLOW_STATUS.WAITING && deviceData ) {
		return (
			<div className="telex-inline-flow">
				<p>
					{ __(
						'Enter this code at telex.automattic.ai to reconnect:',
						'dispatch'
					) }
				</p>
				<div className="telex-inline-flow__code-row">
					<code className="telex-inline-flow__code">
						{ deviceData.user_code }
					</code>
					<Button
						variant="tertiary"
						size="small"
						onClick={ handleCopyCode }
						__next40pxDefaultSize={ false }
					>
						{ copied
							? __( 'Copied!', 'dispatch' )
							: __( 'Copy', 'dispatch' ) }
					</Button>
				</div>
				{ /^https:\/\//i.test(
					deviceData.verification_uri_complete
				) && (
					<Button
						variant="primary"
						href={ deviceData.verification_uri_complete }
						target="_blank"
						rel="noopener noreferrer"
						__next40pxDefaultSize
					>
						{ __( 'Open Telex and approve →', 'dispatch' ) }
					</Button>
				) }
				<div className="telex-inline-flow__waiting">
					<Spinner aria-hidden={ true } />
					<span>{ __( 'Waiting for approval…', 'dispatch' ) }</span>
				</div>
				<Button
					variant="tertiary"
					isDestructive
					onClick={ cancelDeviceFlow }
					__next40pxDefaultSize
				>
					{ __( 'Cancel', 'dispatch' ) }
				</Button>
			</div>
		);
	}

	if ( status === FLOW_STATUS.EXPIRED || status === FLOW_STATUS.ERROR ) {
		return (
			<div className="telex-inline-flow">
				<Notice status="error" isDismissible={ false }>
					{ errorMsg ||
						__(
							'That code expired. Hit the button below to start over.',
							'dispatch'
						) }
				</Notice>
				<Button
					variant="primary"
					onClick={ startDeviceFlow }
					__next40pxDefaultSize
				>
					{ __( 'Start over', 'dispatch' ) }
				</Button>
				<Button
					variant="tertiary"
					onClick={ onClose }
					__next40pxDefaultSize
				>
					{ __( 'Cancel', 'dispatch' ) }
				</Button>
			</div>
		);
	}

	// IDLE
	return (
		<div className="telex-inline-flow">
			<p>
				{ __(
					'Your session has expired. Complete the quick device flow below to reconnect without leaving this page.',
					'dispatch'
				) }
			</p>
			<Button
				variant="primary"
				onClick={ startDeviceFlow }
				__next40pxDefaultSize
			>
				{ __( 'Reconnect to Telex', 'dispatch' ) }
			</Button>
			<Button
				variant="tertiary"
				onClick={ onClose }
				__next40pxDefaultSize
			>
				{ __( 'Cancel', 'dispatch' ) }
			</Button>
		</div>
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
	const isNetworkAdmin = container?.dataset?.isNetwork === '1';

	// Toast state lives here (not Redux) so undoFn closures work cleanly.
	const [ toasts, setToasts ] = useState( [] );
	const [ showShortcuts, setShowShortcuts ] = useState( false );

	// Initialize activeTab from URL hash, falling back to localStorage, then 'all'.
	const [ activeTab, setActiveTab ] = useState( () => {
		const hash = window.location.hash.replace( '#', '' );
		const validTabs = [
			'all',
			'updates',
			'blocks',
			'themes',
			'activity',
			'health',
			'failed',
		];
		if ( validTabs.includes( hash ) ) {
			return hash;
		}
		try {
			const stored = window.localStorage.getItem( 'telex_ui_activeTab' );
			if ( stored && validTabs.includes( stored ) ) {
				return stored;
			}
		} catch {} // eslint-disable-line no-empty
		return 'all';
	} );

	// Sort order — persisted to localStorage.
	const [ sortOrder, setSortOrder ] = useState( () => {
		try {
			return (
				window.localStorage.getItem( 'telex_ui_sortOrder' ) ||
				'name-asc'
			);
		} catch {
			return 'name-asc';
		}
	} );

	// Circuit breaker status.
	const [ circuitStatus, setCircuitStatus ] = useState( 'closed' );
	const [ circuitResetAt, setCircuitResetAt ] = useState( null );
	const [ circuitDismissed, setCircuitDismissed ] = useState( false );

	// Update All queue state.
	const [ updateAllRunning, setUpdateAllRunning ] = useState( false );
	const [ updateAllProgress, setUpdateAllProgress ] = useState( null ); // { done, total }
	const updateAllCancelRef = useRef( false );

	// Multi-select state.
	const [ selectedIds, setSelectedIds ] = useState( new Set() );
	const [ showCheckboxes, setShowCheckboxes ] = useState( false );

	// Inline reconnect modal.
	const [ showReconnectModal, setShowReconnectModal ] = useState( false );

	// Activity tab state.
	const [ activityItems, setActivityItems ] = useState( [] );
	const [ activityTotal, setActivityTotal ] = useState( 0 );
	const [ activityPage, setActivityPage ] = useState( 1 );
	const [ activityFilter, setActivityFilter ] = useState( '' );
	const [ activityLoading, setActivityLoading ] = useState( false );
	const [ activitySearch, setActivitySearch ] = useState( '' );
	const [ activityDateFrom, setActivityDateFrom ] = useState( '' );
	const [ activityDateTo, setActivityDateTo ] = useState( '' );
	const [ activityUserId, setActivityUserId ] = useState( 0 );
	const [ activityUsers, setActivityUsers ] = useState( [] );

	// Project groups (user-scoped collections).
	const [ groups, setGroups ] = useState( [] );
	const [ activeGroupId, setActiveGroupId ] = useState( '' );
	const [ showGroupsPanel, setShowGroupsPanel ] = useState( false );

	// Health dashboard state.
	const [ healthData, setHealthData ] = useState( null );
	const [ healthLoading, setHealthLoading ] = useState( false );

	// Analytics data (block usage counts).
	const [ analyticsData, setAnalyticsData ] = useState( {} );

	// Tag filter.
	const [ tagFilter, setTagFilter ] = useState( '' );
	const [ allTags, setAllTags ] = useState( [] );

	// Pending auto-update approvals.
	const [ pendingApprovals, setPendingApprovals ] = useState( [] );
	const [ pendingLoading, setPendingLoading ] = useState( false );

	// Heartbeat data version — used to detect mutations by other admin sessions.
	const dataVersionRef = useRef( '' );

	const searchInputRef = useRef( null );
	// Guard against stacking concurrent fetches (e.g. rapid keyboard shortcut presses).
	const fetchInFlightRef = useRef( false );
	// Debounce timer for the search input — avoids a Redux dispatch + full list
	// re-render on every keystroke; fires after 150 ms of inactivity.
	const searchDebounceRef = useRef( null );
	// Local state for the visible search input value. Updates immediately so
	// the field feels responsive while the Redux dispatch is debounced.
	const [ localSearchQuery, setLocalSearchQuery ] = useState( '' );

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

	// Persist tab + sort to localStorage and update URL hash.
	const changeTab = useCallback(
		( tabName ) => {
			setActiveTab( tabName );
			setCurrentPage( 1 );
			try {
				window.localStorage.setItem( 'telex_ui_activeTab', tabName );
			} catch {} // eslint-disable-line no-empty
			window.history.replaceState( null, '', `#${ tabName }` );
			// Lazy-load health data on first visit to the health tab.
			if ( tabName === 'health' && ! healthData && ! healthLoading ) {
				setHealthLoading( true );
				apiFetch( { url: `${ restUrl }/health/installed` } )
					.then( ( d ) => setHealthData( d ) )
					.catch( () => {} )
					.finally( () => setHealthLoading( false ) );
			}
			// Lazy-load pending approvals on first visit to updates tab.
			if ( tabName === 'updates' ) {
				setPendingLoading( true );
				apiFetch( { url: `${ restUrl }/auto-updates/pending` } )
					.then( ( d ) => setPendingApprovals( d?.pending || [] ) )
					.catch( () => {} )
					.finally( () => setPendingLoading( false ) );
			}
		},
		// eslint-disable-next-line react-hooks/exhaustive-deps
		[ setCurrentPage, healthData, healthLoading, restUrl ]
	);

	const changeSortOrder = useCallback( ( order ) => {
		setSortOrder( order );
		try {
			window.localStorage.setItem( 'telex_ui_sortOrder', order );
		} catch {} // eslint-disable-line no-empty
	}, [] );

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
			// Don't stack requests — if a fetch is already in flight and this is
			// not a user-initiated force-refresh, silently skip.
			if ( fetchInFlightRef.current && ! forceRefresh ) {
				return;
			}
			fetchInFlightRef.current = true;
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
				} else if (
					err?.data?.status === 429 ||
					err?.code === 'telex_rate_limit'
				) {
					// Surface rate-limit with countdown toast instead of a generic error.
					const retryAfter = parseInt(
						err?.data?.retryAfter ||
							err?.data?.headers?.[ 'Retry-After' ] ||
							30,
						10
					);
					addToast( {
						type: 'rate_limited',
						message: sprintf(
							/* translators: %d: seconds until retry */
							__(
								'Rate limit reached. Try again in %ds.',
								'dispatch'
							),
							retryAfter
						),
						retryAfter,
						autoRemoveMs: retryAfter * 1000,
					} );
				} else if (
					! forceRefresh &&
					fetchInFlightRef.retryDone !== true
				) {
					// Single automatic retry after 1.5 s for transient network failures
					// (e.g. a cold server or brief connectivity blip on initial page load).
					fetchInFlightRef.retryDone = true;
					fetchInFlightRef.current = false;
					await new Promise( ( r ) => setTimeout( r, 1500 ) );
					await fetchData( false );
					return;
				} else {
					setError(
						err.message ||
							__(
								"Couldn't load your projects. Check your connection and try again.",
								'dispatch'
							)
					);
				}
			} finally {
				fetchInFlightRef.current = false;
				fetchInFlightRef.retryDone = false;
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
			// Use a mutable ref-style variable so the Heartbeat tick can update
			// it in-place without re-registering the middleware. This prevents
			// silent 403s when the 24-hour nonce window rolls over on long-lived
			// admin sessions (e.g. overnight staging sites).
			let currentNonce = nonce;

			apiFetch.use( ( options, next ) => {
				return next( {
					...options,
					headers: {
						...( options.headers || {} ),
						'X-WP-Nonce': currentNonce,
					},
				} );
			} );

			// Refresh nonce from Heartbeat tick — zero extra HTTP requests since
			// the Heartbeat fires every 60 s on active admin pages anyway.
			if ( window.jQuery ) {
				window
					.jQuery( document )
					.on( 'heartbeat-tick.telex-nonce', ( _e, data ) => {
						if ( data?.telex?.telex_nonce ) {
							currentNonce = data.telex.telex_nonce;
						}
					} );
			}

			ProjectsApp._nonceRegistered = true;
		}
		setLoading( true );
		fetchData().finally( () => setLoading( false ) );

		// Fetch initial circuit breaker / auth status.
		apiFetch( { url: `${ restUrl }/auth/status` } )
			.then( ( status ) => {
				if ( status?.circuit_status ) {
					setCircuitStatus( status.circuit_status );
				}
				if ( status?.circuit_reset_at ) {
					setCircuitResetAt( status.circuit_reset_at );
				}
			} )
			.catch( () => {} );
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [] );

	// Activity log fetch.
	const fetchActivity = useCallback(
		async ( page = 1, filter = '', extra = {} ) => {
			setActivityLoading( true );
			try {
				const params = new URLSearchParams( {
					per_page: 25,
					page,
					...( filter ? { action: filter } : {} ),
					...( extra.search ? { search: extra.search } : {} ),
					...( extra.date_from
						? { date_from: extra.date_from }
						: {} ),
					...( extra.date_to ? { date_to: extra.date_to } : {} ),
					...( extra.user_id ? { user_id: extra.user_id } : {} ),
				} );
				const data = await apiFetch( {
					url: `${ restUrl }/audit-log?${ params }`,
				} );
				setActivityItems( data.items || [] );
				setActivityTotal( data.total || 0 );
				setActivityPage( page );
				setActivityFilter( filter );
			} catch {
			} finally {
				// eslint-disable-line no-empty
				setActivityLoading( false );
			}
		},
		// eslint-disable-next-line react-hooks/exhaustive-deps
		[ restUrl ]
	);

	// Load activity data when the Activity tab becomes active; also load user list.
	useEffect( () => {
		if ( activeTab === 'activity' ) {
			fetchActivity( 1, activityFilter, {
				search: activitySearch,
				date_from: activityDateFrom,
				date_to: activityDateTo,
				user_id: activityUserId,
			} );
			// Lazy-load users list for the filter dropdown.
			if ( activityUsers.length === 0 ) {
				apiFetch( { url: `${ restUrl }/users` } )
					.then( ( users ) => setActivityUsers( users || [] ) )
					.catch( () => {} );
			}
		}
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ activeTab ] );

	// Load groups on mount.
	useEffect( () => {
		apiFetch( { url: `${ restUrl }/groups` } )
			.then( ( data ) => setGroups( data || [] ) )
			.catch( () => {} );
		// Load analytics for usage counts.
		apiFetch( { url: `${ restUrl }/analytics` } )
			.then( ( data ) => {
				if ( data?.usage ) {
					setAnalyticsData( data.usage );
				}
			} )
			.catch( () => {} );
		// Load all tags for the filter dropdown.
		apiFetch( { url: `${ restUrl }/tags` } )
			.then( ( data ) => setAllTags( data?.tags || [] ) )
			.catch( () => {} );
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [] );

	// Command Palette — register page-specific commands for WP 6.3+ ⌘K / Ctrl+K.
	useCommand( {
		name: 'dispatch/refresh',
		label: __( 'Refresh Dispatch projects', 'dispatch' ),
		callback: () => fetchData( true ),
	} );

	// Dynamically register one "Update …" command per project that needs an update.
	// Uses the lower-level dispatch API so we can register/unregister in a loop
	// without breaking hook rules.
	useEffect( () => {
		const { dispatch: wpDispatch } = window.wp?.data || {};
		if ( ! wpDispatch ) {
			return;
		}
		const commandsStore = wpDispatch( 'core/commands' );
		if ( ! commandsStore?.registerCommand ) {
			return;
		}

		const updatableProjects = projects.filter( ( p ) => p._needs_update );
		const registeredNames = updatableProjects.map( ( p ) => {
			const name = `dispatch/update/${ p.publicId }`;
			commandsStore.registerCommand( {
				name,
				label: sprintf(
					/* translators: %s: project name */
					__( 'Update %s via Dispatch', 'dispatch' ),
					p.name
				),
				callback: () => {
					const el = document.querySelector(
						`[data-public-id="${ p.publicId }"] .telex-update-btn`
					);
					el?.click();
				},
			} );
			return name;
		} );

		return () => {
			registeredNames.forEach( ( name ) =>
				commandsStore.unregisterCommand?.( name )
			);
		};
	}, [ projects ] ); // eslint-disable-line react-hooks/exhaustive-deps

	// Update All — sequential install queue.
	const handleUpdateAll = useCallback(
		async () => {
			// Exclude pinned projects from Update All.
			const toUpdate = projects.filter(
				( p ) => p._needs_update && ! p._pin
			);
			if ( toUpdate.length === 0 ) {
				return;
			}
			updateAllCancelRef.current = false;
			setUpdateAllRunning( true );
			setUpdateAllProgress( { done: 0, total: toUpdate.length } );
			const failed = [];
			for ( let i = 0; i < toUpdate.length; i++ ) {
				if ( updateAllCancelRef.current ) {
					break;
				}
				const p = toUpdate[ i ];
				try {
					await apiFetch( {
						url: `${ restUrl }/projects/${ p.publicId }/install`,
						method: 'POST',
					} );
				} catch ( err ) {
					failed.push( p.name || p.publicId );
				}
				setUpdateAllProgress( { done: i + 1, total: toUpdate.length } );
			}
			setUpdateAllRunning( false );
			setUpdateAllProgress( null );
			updateAllCancelRef.current = false;
			await fetchData( true );
			if ( failed.length > 0 ) {
				addToast( {
					type: 'error',
					message: sprintf(
						/* translators: %s: comma-separated list of project names */
						__( 'Update failed for: %s', 'dispatch' ),
						failed.join( ', ' )
					),
				} );
			} else {
				addToast( {
					type: 'success',
					message: __( 'All updates installed!', 'dispatch' ),
				} );
			}
		},
		// eslint-disable-next-line react-hooks/exhaustive-deps
		[ projects, restUrl ]
	);

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
				case 'u':
				case 'U':
					if (
						! updateAllRunning &&
						projects.some( ( p ) => p._needs_update )
					) {
						e.preventDefault();
						handleUpdateAll();
					}
					break;
				case '/':
					e.preventDefault();
					// Focus the first input inside the SearchControl.
					const searchEl = document.querySelector(
						'.telex-unified-bar .components-search-control__input'
					);
					searchEl?.focus();
					break;
				case 'Escape':
					if ( searchQuery || localSearchQuery ) {
						setLocalSearchQuery( '' );
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
	}, [ searchQuery, updateAllRunning, projects ] );

	// Derived counts for tab titles — memoised so filter() only runs when the
	// project list or installed map changes, not on every unrelated re-render.
	const updatesCount = useMemo(
		() =>
			projects.filter( ( p ) => {
				const inst = installedProjects[ p.publicId ];
				return inst && p.currentVersion > inst.version;
			} ).length,
		[ projects, installedProjects ]
	);

	const blocksCount = useMemo(
		() =>
			projects.filter(
				( p ) => ( p.projectType?.toLowerCase() || 'block' ) !== 'theme'
			).length,
		[ projects ]
	);

	const themesCount = useMemo(
		() =>
			projects.filter( ( p ) => p.projectType?.toLowerCase() === 'theme' )
				.length,
		[ projects ]
	);

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
			// Update circuit breaker status from Heartbeat.
			if ( data.telex.circuit_status ) {
				setCircuitStatus( data.telex.circuit_status );
			}
			// Detect mutations by other admin sessions: silently re-fetch when
			// the data version bumped (install/remove/update by another user).
			const serverVersion = data.telex.data_version || '';
			if (
				serverVersion &&
				dataVersionRef.current &&
				serverVersion !== dataVersionRef.current
			) {
				fetchData( false );
			}
			if ( serverVersion ) {
				dataVersionRef.current = serverVersion;
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

	// Sort helper — starred items always float to the top; the selected
	// sort order acts as the secondary comparator within each tier.
	const sortProjects = useCallback(
		( list ) => {
			const copy = [ ...list ];

			// Secondary comparator based on user-selected sort order.
			const secondary = ( a, b ) => {
				switch ( sortOrder ) {
					case 'name-desc':
						return ( b.name || '' ).localeCompare( a.name || '' );
					case 'installed-newest': {
						const ta = a._local?.installed_at || '';
						const tb = b._local?.installed_at || '';
						return tb.localeCompare( ta );
					}
					case 'updated-newest': {
						const ta = a.updatedAt || '';
						const tb = b.updatedAt || '';
						return tb.localeCompare( ta );
					}
					case 'most-used': {
						const ua =
							analyticsData[ a.publicId ]?.usage_count || 0;
						const ub =
							analyticsData[ b.publicId ]?.usage_count || 0;
						return ub - ua;
					}
					default: // 'name-asc'
						return ( a.name || '' ).localeCompare( b.name || '' );
				}
			};

			copy.sort( ( a, b ) => {
				const aStarred = !! a._favorite;
				const bStarred = !! b._favorite;
				if ( aStarred !== bStarred ) {
					return aStarred ? -1 : 1;
				}
				return secondary( a, b );
			} );

			return copy;
		},
		[ sortOrder, analyticsData ]
	);

	// Filter projects for a given tab + search — memoised on the deps that matter.
	const getTabProjects = useCallback(
		( tabName ) =>
			sortProjects(
				projects
					.filter( ( p ) => {
						const t = p.projectType?.toLowerCase() || 'block';
						if ( tabName === 'updates' ) {
							return p._needs_update;
						}
						if ( tabName === 'blocks' ) {
							return t !== 'theme';
						}
						if ( tabName === 'themes' ) {
							return t === 'theme';
						}
						if ( tabName === 'failed' ) {
							return p._failed;
						}
						return true;
					} )
					.filter(
						( p ) =>
							! searchQuery ||
							p.name
								?.toLowerCase()
								.includes( searchQuery.toLowerCase() )
					)
					.filter( ( p ) => {
						if ( ! activeGroupId ) {
							return true;
						}
						return (
							p._group_ids &&
							p._group_ids.includes( activeGroupId )
						);
					} )
					.filter( ( p ) => {
						if ( ! tagFilter ) {
							return true;
						}
						return p._tags && p._tags.includes( tagFilter );
					} )
			),
		[ projects, searchQuery, sortProjects, activeGroupId, tagFilter ] // eslint-disable-line react-hooks/exhaustive-deps
	);

	const failedCount = useMemo(
		() => projects.filter( ( p ) => p._failed ).length,
		[ projects ]
	);

	const tabs = useMemo(
		() => [
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
			{
				name: 'activity',
				title: __( 'Activity', 'dispatch' ),
			},
			{
				name: 'health',
				title: __( 'Health', 'dispatch' ),
			},
			...( failedCount > 0
				? [
						{
							name: 'failed',
							title: `${ __(
								'Failed',
								'dispatch'
							) } (${ failedCount })`,
							className: 'telex-tab--failed',
						},
				  ]
				: [] ),
		],
		[ projects.length, updatesCount, blocksCount, themesCount, failedCount ]
	);

	// Inline reconnect: when auth expires, show modal device flow rather than full reload.
	const handleReconnect = useCallback( () => {
		setShowReconnectModal( true );
	}, [] );

	// Multi-select toggle helpers.
	const toggleSelect = useCallback( ( id ) => {
		setSelectedIds( ( prev ) => {
			const next = new Set( prev );
			if ( next.has( id ) ) {
				next.delete( id );
			} else {
				next.add( id );
				setShowCheckboxes( true );
			}
			if ( next.size === 0 ) {
				setShowCheckboxes( false );
			}
			return next;
		} );
	}, [] );

	const clearSelection = useCallback( () => {
		setSelectedIds( new Set() );
		setShowCheckboxes( false );
	}, [] );

	const handleBatchInstall = useCallback( async () => {
		for ( const id of selectedIds ) {
			try {
				await apiFetch( {
					url: `${ restUrl }/projects/${ id }/install`,
					method: 'POST',
				} );
			} catch {} // eslint-disable-line no-empty
		}
		clearSelection();
		setLoading( true );
		fetchData( true ).finally( () => setLoading( false ) );
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ selectedIds, restUrl ] );

	const handleBatchRemove = useCallback( async () => {
		for ( const id of selectedIds ) {
			try {
				await apiFetch( {
					url: `${ restUrl }/projects/${ id }`,
					method: 'DELETE',
				} );
			} catch {} // eslint-disable-line no-empty
		}
		clearSelection();
		setLoading( true );
		fetchData( true ).finally( () => setLoading( false ) );
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ selectedIds, restUrl ] );

	return (
		<div className="telex-app">
			{ authExpired && (
				<Notice
					status="warning"
					isDismissible={ false }
					className="telex-reconnect-notice"
				>
					{ __( 'Your Telex connection has expired.', 'dispatch' ) }{ ' ' }
					<Button variant="link" onClick={ handleReconnect }>
						{ __( 'Reconnect now', 'dispatch' ) }
					</Button>
				</Notice>
			) }

			{ circuitStatus !== 'closed' && ! circuitDismissed && (
				<Notice
					status="warning"
					isDismissible={ true }
					onRemove={ () => setCircuitDismissed( true ) }
					className="telex-circuit-notice"
				>
					{ circuitStatus === 'open'
						? __(
								'The Telex API is temporarily unavailable. Showing cached data.',
								'dispatch'
						  )
						: __(
								'The Telex API is recovering. Showing cached data.',
								'dispatch'
						  ) }
					{ circuitResetAt
						? sprintf(
								/* translators: %s: reset timestamp */
								__( 'Auto-reset at %s.', 'dispatch' ),
								new Date(
									circuitResetAt * 1000
								).toLocaleTimeString()
						  )
						: '' }{ ' ' }
					<Button
						variant="link"
						onClick={ () =>
							apiFetch( {
								url: `${ restUrl }/circuit/reset`,
								method: 'POST',
							} )
								.then( () => {
									setCircuitStatus( 'closed' );
									setCircuitDismissed( false );
								} )
								.catch( () => {} )
						}
					>
						{ __( 'Reset now', 'dispatch' ) }
					</Button>
				</Notice>
			) }

			{ ! loading && ! error && ! authExpired && projects.length > 0 && (
				<StatsBar
					projects={ projects }
					installedProjects={ installedProjects }
				/>
			) }

			{ updateAllRunning && updateAllProgress && (
				<div className="telex-update-all-progress">
					<Spinner aria-hidden />
					{ sprintf(
						/* translators: 1: done count, 2: total count */
						__( 'Updating %1$d of %2$d…', 'dispatch' ),
						updateAllProgress.done,
						updateAllProgress.total
					) }
					<Button
						variant="link"
						onClick={ () => {
							updateAllCancelRef.current = true;
						} }
					>
						{ __( 'Cancel', 'dispatch' ) }
					</Button>
				</div>
			) }

			<div className="telex-unified-bar">
				<div
					className="telex-unified-bar__tabs"
					role="tablist"
					aria-label={ __( 'Filter projects', 'dispatch' ) }
				>
					{ tabs.map( ( tab ) => (
						<button
							key={ tab.name }
							role="tab"
							id={ `telex-tab-${ tab.name }` }
							aria-selected={ activeTab === tab.name }
							aria-controls={ `telex-tabpanel-${ tab.name }` }
							tabIndex={ activeTab === tab.name ? 0 : -1 }
							className={ [
								'telex-tab-button',
								activeTab === tab.name ? 'is-active' : '',
								tab.className || '',
							]
								.filter( Boolean )
								.join( ' ' ) }
							onClick={ () => changeTab( tab.name ) }
							onKeyDown={ ( e ) => {
								const names = tabs.map( ( t ) => t.name );
								const idx = names.indexOf( activeTab );
								if ( e.key === 'ArrowRight' ) {
									const next =
										names[ ( idx + 1 ) % names.length ];
									changeTab( next );
									document
										.getElementById( `telex-tab-${ next }` )
										?.focus();
								} else if ( e.key === 'ArrowLeft' ) {
									const prev =
										names[
											( idx - 1 + names.length ) %
												names.length
										];
									changeTab( prev );
									document
										.getElementById( `telex-tab-${ prev }` )
										?.focus();
								}
							} }
						>
							{ tab.title }
						</button>
					) ) }
				</div>

				<div className="telex-unified-bar__search" role="search">
					<SearchControl
						ref={ searchInputRef }
						label={ __( 'Search projects', 'dispatch' ) }
						value={ localSearchQuery }
						onChange={ ( value ) => {
							setLocalSearchQuery( value );
							clearTimeout( searchDebounceRef.current );
							searchDebounceRef.current = setTimeout(
								() => setSearchQuery( value ),
								150
							);
						} }
						__nextHasNoMarginBottom
					/>
				</div>

				<div className="telex-unified-bar__actions">
					{ groups.length > 0 &&
						activeTab !== 'activity' &&
						activeTab !== 'health' && (
							<SelectControl
								className="telex-group-filter"
								label={ __( 'Group', 'dispatch' ) }
								hideLabelFromVision
								__next40pxDefaultSize
								value={ activeGroupId }
								options={ [
									{
										value: '',
										label: __( 'All groups', 'dispatch' ),
									},
									...groups.map( ( g ) => ( {
										value: g.id,
										label: g.name,
									} ) ),
								] }
								onChange={ ( v ) => setActiveGroupId( v ) }
								__nextHasNoMarginBottom
							/>
						) }
					{ activeTab !== 'activity' && activeTab !== 'health' && (
						<Tooltip
							text={ __( 'Manage project groups', 'dispatch' ) }
						>
							<Button
								variant="tertiary"
								icon={ people }
								onClick={ () => setShowGroupsPanel( true ) }
								aria-label={ __(
									'Manage project groups',
									'dispatch'
								) }
								__next40pxDefaultSize
							/>
						</Tooltip>
					) }
					{ allTags.length > 0 &&
						activeTab !== 'activity' &&
						activeTab !== 'health' && (
							<SelectControl
								className="telex-tag-filter"
								label={ __( 'Tag', 'dispatch' ) }
								hideLabelFromVision
								__next40pxDefaultSize
								value={ tagFilter }
								options={ [
									{
										value: '',
										label: __( 'All tags', 'dispatch' ),
									},
									...allTags.map( ( t ) => ( {
										value: t,
										label: t,
									} ) ),
								] }
								onChange={ ( v ) => setTagFilter( v ) }
								__nextHasNoMarginBottom
							/>
						) }
					{ activeTab !== 'activity' && activeTab !== 'health' && (
						<SelectControl
							className="telex-sort-control"
							label={ __( 'Sort', 'dispatch' ) }
							hideLabelFromVision
							__next40pxDefaultSize
							value={ sortOrder }
							options={ [
								{
									value: 'name-asc',
									label: __( 'Name A–Z', 'dispatch' ),
								},
								{
									value: 'name-desc',
									label: __( 'Name Z–A', 'dispatch' ),
								},
								{
									value: 'updated-newest',
									label: __( 'Updated (newest)', 'dispatch' ),
								},
								{
									value: 'installed-newest',
									label: __(
										'Installed (newest)',
										'dispatch'
									),
								},
								{
									value: 'most-used',
									label: __( 'Most used', 'dispatch' ),
								},
							] }
							onChange={ changeSortOrder }
							__nextHasNoMarginBottom
						/>
					) }
					{ updatesCount > 0 && ! updateAllRunning && (
						<Button
							variant="primary"
							onClick={ handleUpdateAll }
							__next40pxDefaultSize
						>
							{ sprintf(
								/* translators: %d: number of updates */
								_n(
									'Update %d',
									'Update %d',
									updatesCount,
									'dispatch'
								),
								updatesCount
							) }
						</Button>
					) }
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
					{ activeTab !== 'activity' && projects.length > 0 && (
						<Tooltip
							text={
								showCheckboxes
									? __( 'Cancel selection', 'dispatch' )
									: __( 'Select projects', 'dispatch' )
							}
						>
							<Button
								variant={
									showCheckboxes ? 'primary' : 'tertiary'
								}
								onClick={ () => {
									setShowCheckboxes( ( v ) => ! v );
									if ( showCheckboxes ) {
										clearSelection();
									}
								} }
								aria-label={
									showCheckboxes
										? __( 'Cancel selection', 'dispatch' )
										: __( 'Select projects', 'dispatch' )
								}
								aria-pressed={ showCheckboxes }
								__next40pxDefaultSize
							>
								{ showCheckboxes
									? __( 'Cancel', 'dispatch' )
									: __( 'Select', 'dispatch' ) }
							</Button>
						</Tooltip>
					) }
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
					<Button
						variant="secondary"
						href={ disconnectUrl }
						__next40pxDefaultSize
					>
						{ __( 'Disconnect', 'dispatch' ) }
					</Button>
				</div>
			</div>

			{ loading && (
				<div
					className="telex-project-list telex-project-list--loading"
					role="status"
					aria-live="polite"
					aria-label={ __( 'Loading your projects…', 'dispatch' ) }
				>
					{ Array.from( { length: 6 } ).map( ( _, i ) => (
						<SkeletonCard key={ i } />
					) ) }
				</div>
			) }

			{ error && (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) }

			{ ! loading &&
				! error &&
				! authExpired &&
				( () => {
					// Activity tab renders its own panel.
					if ( activeTab === 'activity' ) {
						const activityTotalPages = Math.ceil(
							activityTotal / 25
						);
						return (
							<div
								id="telex-tabpanel-activity"
								role="tabpanel"
								aria-labelledby="telex-tab-activity"
								className="telex-tab-panel telex-activity-panel"
							>
								<div className="telex-activity-toolbar">
									<SearchControl
										label={ __(
											'Search activity',
											'dispatch'
										) }
										value={ activitySearch }
										onChange={ ( v ) => {
											setActivitySearch( v );
											fetchActivity( 1, activityFilter, {
												search: v,
												date_from: activityDateFrom,
												date_to: activityDateTo,
												user_id: activityUserId,
											} );
										} }
										__nextHasNoMarginBottom
									/>
									<SelectControl
										label={ __(
											'Filter by action',
											'dispatch'
										) }
										__next40pxDefaultSize
										value={ activityFilter }
										options={ [
											{
												value: '',
												label: __(
													'All actions',
													'dispatch'
												),
											},
											{
												value: 'install',
												label: __(
													'Install',
													'dispatch'
												),
											},
											{
												value: 'update',
												label: __(
													'Update',
													'dispatch'
												),
											},
											{
												value: 'remove',
												label: __(
													'Remove',
													'dispatch'
												),
											},
											{
												value: 'connect',
												label: __(
													'Connect',
													'dispatch'
												),
											},
											{
												value: 'disconnect',
												label: __(
													'Disconnect',
													'dispatch'
												),
											},
										] }
										onChange={ ( v ) =>
											fetchActivity( 1, v, {
												search: activitySearch,
												date_from: activityDateFrom,
												date_to: activityDateTo,
												user_id: activityUserId,
											} )
										}
										__nextHasNoMarginBottom
									/>
									{ activityUsers.length > 0 && (
										<SelectControl
											label={ __( 'User', 'dispatch' ) }
											__next40pxDefaultSize
											value={ String( activityUserId ) }
											options={ [
												{
													value: '0',
													label: __(
														'All users',
														'dispatch'
													),
												},
												...activityUsers.map(
													( u ) => ( {
														value: String( u.id ),
														label: u.name,
													} )
												),
											] }
											onChange={ ( v ) => {
												const uid =
													parseInt( v, 10 ) || 0;
												setActivityUserId( uid );
												fetchActivity(
													1,
													activityFilter,
													{
														search: activitySearch,
														date_from:
															activityDateFrom,
														date_to: activityDateTo,
														user_id: uid,
													}
												);
											} }
											__nextHasNoMarginBottom
										/>
									) }
									<div className="telex-activity-dates">
										<label
											className="telex-date-label"
											htmlFor="telex-date-from"
										>
											{ __( 'From', 'dispatch' ) }
											<input
												id="telex-date-from"
												type="date"
												value={ activityDateFrom }
												className="telex-date-input"
												onChange={ ( e ) => {
													const v = e.target.value;
													setActivityDateFrom( v );
													fetchActivity(
														1,
														activityFilter,
														{
															search: activitySearch,
															date_from: v,
															date_to:
																activityDateTo,
															user_id:
																activityUserId,
														}
													);
												} }
											/>
										</label>
										<label
											className="telex-date-label"
											htmlFor="telex-date-to"
										>
											{ __( 'To', 'dispatch' ) }
											<input
												id="telex-date-to"
												type="date"
												value={ activityDateTo }
												className="telex-date-input"
												onChange={ ( e ) => {
													const v = e.target.value;
													setActivityDateTo( v );
													fetchActivity(
														1,
														activityFilter,
														{
															search: activitySearch,
															date_from:
																activityDateFrom,
															date_to: v,
															user_id:
																activityUserId,
														}
													);
												} }
											/>
										</label>
									</div>
									<a
										href={ `${ restUrl.replace(
											'/wp-json/telex/v1',
											''
										) }/wp-admin/admin.php?page=telex-settings&action=telex_export_csv` }
										className="button button-secondary"
									>
										{ __( 'Export CSV', 'dispatch' ) }
									</a>
								</div>
								{ activityLoading ? (
									<ActivityTableSkeleton />
								) : (
									<table className="wp-list-table widefat fixed striped telex-activity-table">
										<thead>
											<tr>
												<th>
													{ __(
														'Action',
														'dispatch'
													) }
												</th>
												<th>
													{ __(
														'Project',
														'dispatch'
													) }
												</th>
												<th>
													{ __( 'User', 'dispatch' ) }
												</th>
												<th>
													{ __( 'Date', 'dispatch' ) }
												</th>
											</tr>
										</thead>
										<tbody>
											{ activityItems.length === 0 ? (
												<tr>
													<td colSpan={ 4 }>
														{ __(
															'No activity yet.',
															'dispatch'
														) }
													</td>
												</tr>
											) : (
												activityItems.map( ( item ) => (
													<tr key={ item.id }>
														<td>
															<span
																className={ `telex-action-badge telex-action-badge--${ item.action }` }
															>
																{ item.action }
															</span>
														</td>
														<td>
															{ item.public_id ||
																'—' }
														</td>
														<td>
															{ item._user_name ||
																'—' }
														</td>
														<td>
															{ item.created_at ||
																'—' }
														</td>
													</tr>
												) )
											) }
										</tbody>
									</table>
								) }
								{ activityTotalPages > 1 && (
									<PaginationControls
										currentPage={ activityPage }
										totalPages={ activityTotalPages }
										totalItems={ activityTotal }
										perPage={ 25 }
										onPageChange={ ( p ) =>
											fetchActivity( p, activityFilter, {
												search: activitySearch,
												date_from: activityDateFrom,
												date_to: activityDateTo,
												user_id: activityUserId,
											} )
										}
									/>
								) }
							</div>
						);
					}

					// Health tab.
					if ( activeTab === 'health' ) {
						return (
							<div
								id="telex-tabpanel-health"
								role="tabpanel"
								aria-labelledby="telex-tab-health"
								className="telex-tab-panel telex-health-panel"
							>
								<div className="telex-health-toolbar">
									<h2>
										{ __( 'Project Health', 'dispatch' ) }
									</h2>
									<Button
										variant="secondary"
										icon={ shield }
										onClick={ () => {
											setHealthLoading( true );
											apiFetch( {
												url: `${ restUrl }/health/installed?force_scan=1`,
											} )
												.then( ( d ) =>
													setHealthData( d )
												)
												.catch( () => {} )
												.finally( () =>
													setHealthLoading( false )
												);
										} }
										disabled={ healthLoading }
										__next40pxDefaultSize
									>
										{ healthLoading
											? __( 'Scanning…', 'dispatch' )
											: __( 'Scan now', 'dispatch' ) }
									</Button>
								</div>
								{ healthLoading ? (
									<HealthTableSkeleton />
								) : (
									healthData && (
										<>
											<p className="description">
												{ sprintf(
													/* translators: %s: scan timestamp */
													__(
														'Last checked: %s',
														'dispatch'
													),
													healthData.checked_at || '—'
												) }
											</p>
											<table className="wp-list-table widefat fixed striped telex-health-table">
												<thead>
													<tr>
														<th>
															{ __(
																'Project',
																'dispatch'
															) }
														</th>
														<th>
															{ __(
																'Active',
																'dispatch'
															) }
														</th>
														<th>
															{ __(
																'PHP Compat',
																'dispatch'
															) }
														</th>
														<th>
															{ __(
																'Block Registered',
																'dispatch'
															) }
														</th>
														<th>
															{ __(
																'Error Log',
																'dispatch'
															) }
														</th>
														<th>
															{ __(
																'Status',
																'dispatch'
															) }
														</th>
													</tr>
												</thead>
												<tbody>
													{ (
														healthData.projects ||
														[]
													).map( ( hp ) => (
														<tr
															key={ hp.public_id }
															className={ `telex-health-row telex-health-row--${ hp.status }` }
														>
															<td>
																<code>
																	{ hp.slug ||
																		hp.public_id }
																</code>
															</td>
															<td>
																<span
																	className={ `telex-health-indicator telex-health-indicator--${
																		hp.active
																			? 'ok'
																			: 'error'
																	}` }
																>
																	{ hp.active
																		? '✓'
																		: '✗' }
																</span>
															</td>
															<td>
																<span
																	className={ `telex-health-indicator telex-health-indicator--${
																		hp.php_compat
																			? 'ok'
																			: 'error'
																	}` }
																>
																	{ hp.php_compat
																		? '✓'
																		: '✗' }
																</span>
															</td>
															<td>
																<span
																	className={ `telex-health-indicator telex-health-indicator--${
																		hp.block_registered
																			? 'ok'
																			: 'warn'
																	}` }
																>
																	{ hp.block_registered
																		? '✓'
																		: '?' }
																</span>
															</td>
															<td>
																{ hp.in_error_log >
																0 ? (
																	<span className="telex-health-indicator telex-health-indicator--warn">
																		{ sprintf(
																			/* translators: %d: line count */
																			__(
																				'%d lines',
																				'dispatch'
																			),
																			hp.in_error_log
																		) }
																	</span>
																) : (
																	<span className="telex-health-indicator telex-health-indicator--ok">
																		{ __(
																			'Clean',
																			'dispatch'
																		) }
																	</span>
																) }
															</td>
															<td>
																<span
																	className={ `telex-health-status telex-health-status--${ hp.status }` }
																>
																	{
																		hp.status
																	}
																</span>
															</td>
														</tr>
													) ) }
													{ (
														healthData.projects ||
														[]
													).length === 0 && (
														<tr>
															<td colSpan={ 6 }>
																{ __(
																	'No installed projects to check.',
																	'dispatch'
																) }
															</td>
														</tr>
													) }
												</tbody>
											</table>
										</>
									)
								) }
								{ ! healthLoading && ! healthData && (
									<p className="description">
										{ __(
											'Click "Scan now" to check the health of your installed projects.',
											'dispatch'
										) }
									</p>
								) }
							</div>
						);
					}

					const allVisible = getTabProjects( activeTab );
					const totalItems = allVisible.length;
					const totalPages = Math.ceil( totalItems / perPage );
					const safePage = Math.min( currentPage, totalPages || 1 );
					const pageStart = ( safePage - 1 ) * perPage;
					const paginated = allVisible.slice(
						pageStart,
						pageStart + perPage
					);

					return (
						<div
							id={ `telex-tabpanel-${ activeTab }` }
							role="tabpanel"
							aria-labelledby={ `telex-tab-${ activeTab }` }
							className="telex-tab-panel"
						>
							{ /* Pending auto-update approval queue */ }
							{ activeTab === 'updates' &&
								! pendingLoading &&
								pendingApprovals.length > 0 && (
									<div className="telex-approval-queue">
										<h3 className="telex-approval-queue__title">
											{ sprintf(
												/* translators: %d: number of pending approvals */
												_n(
													'%d update awaiting approval',
													'%d updates awaiting approval',
													pendingApprovals.length,
													'dispatch'
												),
												pendingApprovals.length
											) }
										</h3>
										<div className="telex-approval-queue__list">
											{ pendingApprovals.map(
												( item ) => {
													const proj = projects.find(
														( p ) =>
															p.publicId ===
															item.publicId
													);
													return (
														<div
															key={
																item.publicId
															}
															className="telex-approval-item"
														>
															<span className="telex-approval-item__name">
																{ proj?.name ||
																	item.publicId }
															</span>
															<span className="telex-approval-item__age">
																{ sprintf(
																	/* translators: %d: number of hours */
																	__(
																		'Queued %dh ago',
																		'dispatch'
																	),
																	item.soakHours
																) }
															</span>
															<div className="telex-approval-item__actions">
																<Button
																	variant="primary"
																	size="small"
																	onClick={ async () => {
																		try {
																			await apiFetch(
																				{
																					url: `${ restUrl }/projects/${ item.publicId }/auto-update/approve`,
																					method: 'POST',
																				}
																			);
																			setPendingApprovals(
																				(
																					prev
																				) =>
																					prev.filter(
																						(
																							x
																						) =>
																							x.publicId !==
																							item.publicId
																					)
																			);
																			fetchData(
																				true
																			);
																			addToast(
																				{
																					type: 'success',
																					message:
																						sprintf(
																							/* translators: %s: project name */
																							__(
																								'%s updated.',
																								'dispatch'
																							),
																							proj?.name ||
																								item.publicId
																						),
																				}
																			);
																		} catch ( e ) {
																			addToast(
																				{
																					type: 'error',
																					message:
																						e.message ||
																						__(
																							'Update failed.',
																							'dispatch'
																						),
																				}
																			);
																		}
																	} }
																	__next40pxDefaultSize={
																		false
																	}
																>
																	{ __(
																		'Approve',
																		'dispatch'
																	) }
																</Button>
																<Button
																	variant="tertiary"
																	size="small"
																	onClick={ async () => {
																		await apiFetch(
																			{
																				url: `${ restUrl }/projects/${ item.publicId }/auto-update/skip`,
																				method: 'POST',
																			}
																		).catch(
																			() => {}
																		);
																		setPendingApprovals(
																			(
																				prev
																			) =>
																				prev.filter(
																					(
																						x
																					) =>
																						x.publicId !==
																						item.publicId
																				)
																		);
																	} }
																	__next40pxDefaultSize={
																		false
																	}
																>
																	{ __(
																		'Skip',
																		'dispatch'
																	) }
																</Button>
															</div>
														</div>
													);
												}
											) }
										</div>
									</div>
								) }
							<div
								className="telex-project-list"
								role="list"
								aria-label={ __(
									'Dispatch projects',
									'dispatch'
								) }
							>
								{ paginated.length === 0 ? (
									<EmptyState
										tab={ activeTab }
										searchQuery={ searchQuery }
									/>
								) : (
									paginated.map( ( project ) => (
										<ProjectCard
											key={ project.publicId }
											project={ project }
											restUrl={ restUrl }
											onRefresh={ fetchData }
											onToast={ addToast }
											isNetworkAdmin={ isNetworkAdmin }
											showCheckbox={ showCheckboxes }
											isSelected={ selectedIds.has(
												project.publicId
											) }
											onToggleSelect={ toggleSelect }
											analyticsData={ analyticsData }
										/>
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
						</div>
					);
				} )() }

			{ showCheckboxes && selectedIds.size > 0 && (
				<div
					className="telex-batch-bar"
					role="toolbar"
					aria-label={ __( 'Batch actions', 'dispatch' ) }
				>
					<span className="telex-batch-bar__count">
						{ sprintf(
							/* translators: %d: number of selected projects */
							_n(
								'%d selected',
								'%d selected',
								selectedIds.size,
								'dispatch'
							),
							selectedIds.size
						) }
					</span>
					<Button variant="primary" onClick={ handleBatchInstall }>
						{ __( 'Install selected', 'dispatch' ) }
					</Button>
					<Button
						variant="secondary"
						isDestructive
						onClick={ handleBatchRemove }
					>
						{ __( 'Remove selected', 'dispatch' ) }
					</Button>
					<Button variant="tertiary" onClick={ clearSelection }>
						{ __( 'Clear', 'dispatch' ) }
					</Button>
				</div>
			) }

			<ToastList toasts={ toasts } onDismiss={ removeToast } />

			{ showShortcuts && (
				<KeyboardShortcutsModal
					onClose={ () => setShowShortcuts( false ) }
				/>
			) }

			{ showReconnectModal && (
				<Modal
					title={ __( 'Reconnect to Telex', 'dispatch' ) }
					onRequestClose={ () => setShowReconnectModal( false ) }
					className="telex-reconnect-modal"
				>
					<InlineDeviceFlow
						restUrl={ restUrl }
						onSuccess={ () => {
							setShowReconnectModal( false );
							setAuthExpired( false );
							setLoading( true );
							fetchData( true ).finally( () =>
								setLoading( false )
							);
						} }
						onClose={ () => setShowReconnectModal( false ) }
					/>
				</Modal>
			) }
			{ showGroupsPanel && (
				<Modal
					title={ __( 'Project Groups', 'dispatch' ) }
					onRequestClose={ () => setShowGroupsPanel( false ) }
					className="telex-groups-modal"
				>
					<GroupsPanel
						restUrl={ restUrl }
						groups={ groups }
						onChange={ ( updated ) => {
							setGroups( updated );
							if (
								activeGroupId &&
								! updated.find(
									( g ) => g.id === activeGroupId
								)
							) {
								setActiveGroupId( '' );
							}
						} }
						onToast={ addToast }
					/>
				</Modal>
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
// Notification settings panel
// ---------------------------------------------------------------------------

/**
 * Panel for configuring notification channels (email digest + Slack webhook).
 *
 * @param {Object}   props         Component props.
 * @param {string}   props.restUrl REST API base URL.
 * @param {Function} props.onToast Toast callback.
 * @return {import('@wordpress/element').WPElement} Notification panel.
 */
function NotificationPanel( { restUrl, onToast } ) {
	const [ settings, setSettings ] = useState( null );
	const [ saving, setSaving ] = useState( false );
	const [ testing, setTesting ] = useState( false );

	useEffect( () => {
		apiFetch( { url: `${ restUrl }/settings/notifications` } )
			.then( ( d ) => setSettings( d ) )
			.catch( () => {} );
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [] );

	if ( ! settings ) {
		return (
			<div className="telex-notification-panel">
				<h3>{ __( 'Notifications', 'dispatch' ) }</h3>
				<p className="description">
					{ __(
						'Receive alerts when updates are available, circuit breakers open, or installs fail.',
						'dispatch'
					) }
				</p>
				<div className="telex-panel-skeleton" aria-hidden="true">
					<div className="telex-skeleton telex-skeleton--checkbox-row" />
					<div className="telex-skeleton telex-skeleton--checkbox-row" />
					<div className="telex-skeleton telex-skeleton--checkbox-row" />
					<div className="telex-skeleton telex-skeleton--input-group" />
					<div className="telex-skeleton telex-skeleton--input-group" />
				</div>
			</div>
		);
	}

	async function handleSave() {
		setSaving( true );
		try {
			await apiFetch( {
				url: `${ restUrl }/settings/notifications`,
				method: 'POST',
				data: settings,
			} );
			onToast( {
				type: 'success',
				message: __( 'Notification settings saved.', 'dispatch' ),
			} );
		} catch ( e ) {
			onToast( {
				type: 'error',
				message:
					e.message || __( 'Could not save settings.', 'dispatch' ),
			} );
		} finally {
			setSaving( false );
		}
	}

	async function handleTest() {
		setTesting( true );
		try {
			const result = await apiFetch( {
				url: `${ restUrl }/settings/notifications/test`,
				method: 'POST',
			} );
			onToast( {
				type: result.ok ? 'success' : 'error',
				message: result.message || __( 'Test sent.', 'dispatch' ),
			} );
		} catch ( e ) {
			onToast( {
				type: 'error',
				message: e.message || __( 'Test failed.', 'dispatch' ),
			} );
		} finally {
			setTesting( false );
		}
	}

	return (
		<div className="telex-notification-panel">
			<h3>{ __( 'Notifications', 'dispatch' ) }</h3>
			<p className="description">
				{ __(
					'Receive alerts when updates are available, circuit breakers open, or installs fail.',
					'dispatch'
				) }
			</p>
			<div className="telex-notification-columns">
				<div className="telex-notification-col">
					<h4>{ __( 'Alert conditions', 'dispatch' ) }</h4>
					<div className="telex-notification-checkboxes">
						<CheckboxControl
							label={ __(
								'Notify on updates available',
								'dispatch'
							) }
							checked={ !! settings.notify_updates }
							onChange={ ( v ) =>
								setSettings( {
									...settings,
									notify_updates: v,
								} )
							}
							__nextHasNoMarginBottom
						/>
						<CheckboxControl
							label={ __(
								'Notify on circuit breaker open',
								'dispatch'
							) }
							checked={ !! settings.notify_circuit }
							onChange={ ( v ) =>
								setSettings( {
									...settings,
									notify_circuit: v,
								} )
							}
							__nextHasNoMarginBottom
						/>
						<CheckboxControl
							label={ __(
								'Notify on install failure',
								'dispatch'
							) }
							checked={ !! settings.notify_failures }
							onChange={ ( v ) =>
								setSettings( {
									...settings,
									notify_failures: v,
								} )
							}
							__nextHasNoMarginBottom
						/>
					</div>
				</div>
				<div className="telex-notification-col">
					<div className="telex-notification-section">
						<h4>{ __( 'Email', 'dispatch' ) }</h4>
						<TextControl
							label={ __( 'Email address', 'dispatch' ) }
							type="email"
							value={ settings.email || '' }
							onChange={ ( v ) =>
								setSettings( { ...settings, email: v } )
							}
							placeholder={ __(
								'admin@example.com',
								'dispatch'
							) }
							__next40pxDefaultSize
							__nextHasNoMarginBottom
						/>
					</div>
					<div className="telex-notification-section">
						<h4>{ __( 'Slack', 'dispatch' ) }</h4>
						<TextControl
							label={ __( 'Slack webhook URL', 'dispatch' ) }
							type="url"
							value={ settings.slack_webhook || '' }
							onChange={ ( v ) =>
								setSettings( { ...settings, slack_webhook: v } )
							}
							placeholder="https://hooks.slack.com/services/…"
							__next40pxDefaultSize
							__nextHasNoMarginBottom
						/>
					</div>
				</div>
			</div>
			<div className="telex-notification-actions">
				<Button
					variant="primary"
					onClick={ handleSave }
					isBusy={ saving }
					disabled={ saving }
					__next40pxDefaultSize
				>
					{ __( 'Save settings', 'dispatch' ) }
				</Button>
				<Button
					variant="secondary"
					onClick={ handleTest }
					isBusy={ testing }
					disabled={
						testing ||
						( ! settings.email && ! settings.slack_webhook )
					}
					__next40pxDefaultSize
				>
					{ __( 'Send test', 'dispatch' ) }
				</Button>
			</div>
		</div>
	);
}

// ---------------------------------------------------------------------------
// Project groups management panel
// ---------------------------------------------------------------------------

/**
 * Panel for managing project groups on the main admin page.
 *
 * @param {Object}   props          Component props.
 * @param {string}   props.restUrl  REST API base URL.
 * @param {Array}    props.groups   Current groups array.
 * @param {Function} props.onChange Called with new groups array after any change.
 * @param {Function} props.onToast  Toast callback.
 * @return {import('@wordpress/element').WPElement} Groups panel.
 */
function GroupsPanel( { restUrl, groups, onChange, onToast } ) {
	const [ newName, setNewName ] = useState( '' );
	const [ editId, setEditId ] = useState( null );
	const [ editName, setEditName ] = useState( '' );
	const [ busy, setBusy ] = useState( false );

	async function handleCreate() {
		if ( ! newName.trim() ) {
			return;
		}
		setBusy( true );
		try {
			const result = await apiFetch( {
				url: `${ restUrl }/groups`,
				method: 'POST',
				data: { name: newName.trim() },
			} );
			onChange( [ ...groups, result ] );
			setNewName( '' );
		} catch ( e ) {
			onToast( {
				type: 'error',
				message:
					e.message || __( 'Could not create group.', 'dispatch' ),
			} );
		} finally {
			setBusy( false );
		}
	}

	async function handleUpdate( id ) {
		if ( ! editName.trim() ) {
			return;
		}
		setBusy( true );
		try {
			const result = await apiFetch( {
				url: `${ restUrl }/groups/${ id }`,
				method: 'PUT',
				data: { name: editName.trim() },
			} );
			onChange( groups.map( ( g ) => ( g.id === id ? result : g ) ) );
			setEditId( null );
		} catch ( e ) {
			onToast( {
				type: 'error',
				message:
					e.message || __( 'Could not update group.', 'dispatch' ),
			} );
		} finally {
			setBusy( false );
		}
	}

	async function handleDelete( id ) {
		setBusy( true );
		try {
			await apiFetch( {
				url: `${ restUrl }/groups/${ id }`,
				method: 'DELETE',
			} );
			onChange( groups.filter( ( g ) => g.id !== id ) );
		} catch ( e ) {
			onToast( {
				type: 'error',
				message:
					e.message || __( 'Could not delete group.', 'dispatch' ),
			} );
		} finally {
			setBusy( false );
		}
	}

	return (
		<div className="telex-groups-panel">
			<h3>{ __( 'Project Groups', 'dispatch' ) }</h3>
			<p className="description">
				{ __(
					'Organise your projects into named groups for faster filtering.',
					'dispatch'
				) }
			</p>
			{ groups.length > 0 && (
				<ul className="telex-groups-list">
					{ groups.map( ( g ) => (
						<li key={ g.id } className="telex-groups-list__item">
							{ editId === g.id ? (
								<>
									<TextControl
										label={ __( 'Group name', 'dispatch' ) }
										hideLabelFromVision
										value={ editName }
										onChange={ setEditName }
										__next40pxDefaultSize
										__nextHasNoMarginBottom
									/>
									<Button
										variant="primary"
										size="small"
										onClick={ () => handleUpdate( g.id ) }
										disabled={ busy || ! editName.trim() }
										isBusy={ busy }
										__next40pxDefaultSize={ false }
									>
										{ __( 'Save', 'dispatch' ) }
									</Button>
									<Button
										variant="tertiary"
										size="small"
										onClick={ () => setEditId( null ) }
										__next40pxDefaultSize={ false }
									>
										{ __( 'Cancel', 'dispatch' ) }
									</Button>
								</>
							) : (
								<>
									<span className="telex-groups-list__name">
										{ g.name }
									</span>
									<Button
										variant="tertiary"
										size="small"
										icon={ pencil }
										onClick={ () => {
											setEditId( g.id );
											setEditName( g.name );
										} }
										aria-label={ sprintf(
											/* translators: %s: group name */
											__( 'Edit group: %s', 'dispatch' ),
											g.name
										) }
										__next40pxDefaultSize={ false }
									/>
									<Button
										variant="tertiary"
										size="small"
										isDestructive
										icon={ trash }
										onClick={ () => handleDelete( g.id ) }
										disabled={ busy }
										aria-label={ sprintf(
											/* translators: %s: group name */
											__(
												'Delete group: %s',
												'dispatch'
											),
											g.name
										) }
										__next40pxDefaultSize={ false }
									/>
								</>
							) }
						</li>
					) ) }
				</ul>
			) }
			<div className="telex-groups-new">
				<TextControl
					label={ __( 'New group name', 'dispatch' ) }
					value={ newName }
					onChange={ setNewName }
					placeholder={ __(
						'e.g. Client A, Core Blocks…',
						'dispatch'
					) }
					__next40pxDefaultSize
					__nextHasNoMarginBottom
				/>
				<Button
					variant="secondary"
					onClick={ handleCreate }
					disabled={ busy || ! newName.trim() }
					isBusy={ busy }
					icon={ people }
					__next40pxDefaultSize
				>
					{ __( 'Create group', 'dispatch' ) }
				</Button>
			</div>
		</div>
	);
}

// ---------------------------------------------------------------------------
// Build snapshot panel
// ---------------------------------------------------------------------------

/**
 * Panel for managing build snapshots.
 *
 * @param {Object}   props         Component props.
 * @param {string}   props.restUrl REST API base URL.
 * @param {Function} props.onToast Toast callback.
 * @return {import('@wordpress/element').WPElement} Snapshots panel.
 */
function SnapshotPanel( { restUrl, onToast } ) {
	const [ snapshots, setSnapshots ] = useState( null );
	const [ newName, setNewName ] = useState( '' );
	const [ busy, setBusy ] = useState( false );
	const [ confirmDelete, setConfirmDelete ] = useState( null );
	const [ confirmRestore, setConfirmRestore ] = useState( null );

	useEffect( () => {
		apiFetch( { url: `${ restUrl }/snapshots` } )
			.then( ( d ) => setSnapshots( d ) )
			.catch( () => setSnapshots( [] ) );
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [] );

	async function handleCreate() {
		if ( ! newName.trim() ) {
			return;
		}
		setBusy( true );
		try {
			const result = await apiFetch( {
				url: `${ restUrl }/snapshots`,
				method: 'POST',
				data: { name: newName.trim() },
			} );
			setSnapshots( [ result, ...( snapshots || [] ) ] );
			setNewName( '' );
			onToast( {
				type: 'success',
				message: __( 'Snapshot created.', 'dispatch' ),
			} );
		} catch ( e ) {
			onToast( {
				type: 'error',
				message:
					e.message || __( 'Could not create snapshot.', 'dispatch' ),
			} );
		} finally {
			setBusy( false );
		}
	}

	async function handleDelete( id ) {
		setBusy( true );
		try {
			await apiFetch( {
				url: `${ restUrl }/snapshots/${ id }`,
				method: 'DELETE',
			} );
			setSnapshots( ( snapshots || [] ).filter( ( s ) => s.id !== id ) );
			onToast( {
				type: 'success',
				message: __( 'Snapshot deleted.', 'dispatch' ),
			} );
		} catch ( e ) {
			onToast( {
				type: 'error',
				message:
					e.message || __( 'Could not delete snapshot.', 'dispatch' ),
			} );
		} finally {
			setBusy( false );
			setConfirmDelete( null );
		}
	}

	async function handleRestore( id ) {
		setBusy( true );
		try {
			await apiFetch( {
				url: `${ restUrl }/snapshots/${ id }/restore`,
				method: 'POST',
			} );
			onToast( {
				type: 'success',
				message: __(
					'Snapshot restored. Projects are being reinstalled.',
					'dispatch'
				),
			} );
		} catch ( e ) {
			onToast( {
				type: 'error',
				message: e.message || __( 'Restore failed.', 'dispatch' ),
			} );
		} finally {
			setBusy( false );
			setConfirmRestore( null );
		}
	}

	return (
		<div className="telex-snapshot-panel">
			<h3>{ __( 'Build Snapshots', 'dispatch' ) }</h3>
			<p className="description">
				{ __(
					'Capture the current set of installed projects and their versions. Restore to roll back your entire build.',
					'dispatch'
				) }
			</p>
			<div className="telex-snapshot-new">
				<TextControl
					label={ __( 'Snapshot name', 'dispatch' ) }
					value={ newName }
					onChange={ setNewName }
					placeholder={ __( 'e.g. Before v2 launch', 'dispatch' ) }
					__next40pxDefaultSize
					__nextHasNoMarginBottom
				/>
				<Button
					variant="secondary"
					onClick={ handleCreate }
					disabled={ busy || ! newName.trim() }
					isBusy={ busy }
					__next40pxDefaultSize
				>
					{ __( 'Create snapshot', 'dispatch' ) }
				</Button>
			</div>
			{ snapshots === null && (
				<div className="telex-panel-skeleton" aria-hidden="true">
					<div className="telex-skeleton telex-skeleton--table-row" />
					<div className="telex-skeleton telex-skeleton--table-row" />
					<div className="telex-skeleton telex-skeleton--table-row" />
				</div>
			) }
			{ snapshots !== null && snapshots.length === 0 && (
				<p className="description">
					{ __( 'No snapshots yet.', 'dispatch' ) }
				</p>
			) }
			{ snapshots !== null && snapshots.length > 0 && (
				<table className="wp-list-table widefat fixed striped telex-snapshot-table">
					<thead>
						<tr>
							<th>{ __( 'Name', 'dispatch' ) }</th>
							<th>{ __( 'Projects', 'dispatch' ) }</th>
							<th>{ __( 'Created', 'dispatch' ) }</th>
							<th>{ __( 'Actions', 'dispatch' ) }</th>
						</tr>
					</thead>
					<tbody>
						{ snapshots.map( ( snap ) => (
							<tr key={ snap.id }>
								<td>{ snap.name }</td>
								<td>{ ( snap.projects || [] ).length }</td>
								<td>{ snap.created_at || '—' }</td>
								<td className="telex-snapshot-actions">
									<Button
										variant="secondary"
										size="small"
										onClick={ () =>
											setConfirmRestore( snap )
										}
										disabled={ busy }
										__next40pxDefaultSize={ false }
									>
										{ __( 'Restore', 'dispatch' ) }
									</Button>
									<Button
										variant="tertiary"
										size="small"
										isDestructive
										icon={ trash }
										onClick={ () =>
											setConfirmDelete( snap )
										}
										disabled={ busy }
										aria-label={ sprintf(
											/* translators: %s: snapshot name */
											__(
												'Delete snapshot: %s',
												'dispatch'
											),
											snap.name
										) }
										__next40pxDefaultSize={ false }
									/>
								</td>
							</tr>
						) ) }
					</tbody>
				</table>
			) }
			{ confirmDelete && (
				<Modal
					title={ sprintf(
						/* translators: %s: snapshot name */
						__( 'Delete snapshot "%s"?', 'dispatch' ),
						confirmDelete.name
					) }
					onRequestClose={ () => setConfirmDelete( null ) }
				>
					<p>{ __( 'This cannot be undone.', 'dispatch' ) }</p>
					<div className="telex-modal-actions">
						<Button
							variant="primary"
							isDestructive
							onClick={ () => handleDelete( confirmDelete.id ) }
							isBusy={ busy }
							__next40pxDefaultSize
						>
							{ __( 'Delete', 'dispatch' ) }
						</Button>
						<Button
							variant="secondary"
							onClick={ () => setConfirmDelete( null ) }
							__next40pxDefaultSize
						>
							{ __( 'Cancel', 'dispatch' ) }
						</Button>
					</div>
				</Modal>
			) }
			{ confirmRestore && (
				<Modal
					title={ sprintf(
						/* translators: %s: snapshot name */
						__( 'Restore snapshot "%s"?', 'dispatch' ),
						confirmRestore.name
					) }
					onRequestClose={ () => setConfirmRestore( null ) }
				>
					<p>
						{ __(
							'This will reinstall all projects to the versions captured in this snapshot. The site will remain operational during the restore.',
							'dispatch'
						) }
					</p>
					<div className="telex-modal-actions">
						<Button
							variant="primary"
							onClick={ () => handleRestore( confirmRestore.id ) }
							isBusy={ busy }
							__next40pxDefaultSize
						>
							{ __( 'Restore', 'dispatch' ) }
						</Button>
						<Button
							variant="secondary"
							onClick={ () => setConfirmRestore( null ) }
							__next40pxDefaultSize
						>
							{ __( 'Cancel', 'dispatch' ) }
						</Button>
					</div>
				</Modal>
			) }
		</div>
	);
}

// ---------------------------------------------------------------------------
// Settings page app
// ---------------------------------------------------------------------------

/**
 * Minimal app for the Settings page: Notifications, Snapshots, Import/Export.
 *
 * @return {import('@wordpress/element').WPElement} Settings app element.
 */
/**
 * Import / Export panel — shown in the Settings tab.
 *
 * @param {Object}   root0
 * @param {string}   root0.restUrl REST API base URL.
 * @param {Function} root0.onToast Toast callback.
 * @return {import('@wordpress/element').WPElement} Panel element.
 */
function ImportExportPanel( { restUrl, onToast } ) {
	const [ exporting, setExporting ] = useState( false );
	const [ importing, setImporting ] = useState( false );
	const fileInputRef = useRef( null );

	const handleExport = useCallback( async () => {
		setExporting( true );
		try {
			const data = await apiFetch( {
				url: `${ restUrl }/config/export`,
			} );
			const blob = new Blob( [ JSON.stringify( data, null, 2 ) ], {
				type: 'application/json',
			} );
			const url = URL.createObjectURL( blob );
			const a = document.createElement( 'a' );
			a.href = url;
			a.download = 'dispatch-config.json';
			a.click();
			URL.revokeObjectURL( url );
			onToast( {
				type: 'success',
				message: __( 'Config exported.', 'dispatch' ),
			} );
		} catch ( e ) {
			onToast( {
				type: 'error',
				message: e.message || __( 'Export failed.', 'dispatch' ),
			} );
		} finally {
			setExporting( false );
		}
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ restUrl ] );

	const handleImport = useCallback(
		async ( e ) => {
			const file = e.target.files?.[ 0 ];
			if ( ! file ) {
				return;
			}
			setImporting( true );
			try {
				const text = await file.text();
				const json = JSON.parse( text );
				const resp = await apiFetch( {
					url: `${ restUrl }/config/import`,
					method: 'POST',
					data: json,
				} );
				onToast( {
					type: 'success',
					message:
						resp?.message ||
						__( 'Config imported successfully.', 'dispatch' ),
				} );
			} catch ( err ) {
				onToast( {
					type: 'error',
					message: err.message || __( 'Import failed.', 'dispatch' ),
				} );
			} finally {
				setImporting( false );
				if ( fileInputRef.current ) {
					fileInputRef.current.value = '';
				}
			}
		},
		// eslint-disable-next-line react-hooks/exhaustive-deps
		[ restUrl ]
	);

	return (
		<div className="telex-notification-panel">
			<h3>{ __( 'Config Export / Import', 'dispatch' ) }</h3>
			<p className="description">
				{ __(
					'Export your pins, notes, tags, groups, and auto-update settings as a JSON file. Import on any site to replicate your setup.',
					'dispatch'
				) }
			</p>
			<div className="telex-notification-actions">
				<Button
					variant="secondary"
					isBusy={ exporting }
					disabled={ exporting }
					onClick={ handleExport }
					__next40pxDefaultSize
				>
					{ __( 'Export config', 'dispatch' ) }
				</Button>
				<Button
					variant="secondary"
					isBusy={ importing }
					disabled={ importing }
					onClick={ () => fileInputRef.current?.click() }
					__next40pxDefaultSize
				>
					{ __( 'Import config', 'dispatch' ) }
				</Button>
				<input
					ref={ fileInputRef }
					type="file"
					accept="application/json,.json"
					style={ { display: 'none' } }
					onChange={ handleImport }
				/>
			</div>
		</div>
	);
}

function WebhookApp() {
	const container = document.getElementById( 'telex-webhook-app' );
	const restUrl = container?.dataset?.restUrl?.replace( /\/$/, '' ) || '';
	const [ toasts, setToasts ] = useState( [] );

	/**
	 * Adds a toast notification.
	 *
	 * @param {Object} toast Toast data object.
	 */
	function addToast( toast ) {
		setToasts( ( prev ) => [
			...prev,
			{ id: Date.now() + Math.random(), ...toast },
		] );
	}

	/**
	 * Removes a toast by id.
	 *
	 * @param {number} id Toast id to remove.
	 */
	function removeToast( id ) {
		setToasts( ( prev ) => prev.filter( ( t ) => t.id !== id ) );
	}

	return (
		<>
			<NotificationPanel restUrl={ restUrl } onToast={ addToast } />
			<SnapshotPanel restUrl={ restUrl } onToast={ addToast } />
			<ImportExportPanel restUrl={ restUrl } onToast={ addToast } />
			<ToastList toasts={ toasts } onDismiss={ removeToast } />
		</>
	);
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

const webhookRoot = document.getElementById( 'telex-webhook-app' );
if ( webhookRoot ) {
	render(
		<TelexErrorBoundary>
			<WebhookApp />
		</TelexErrorBoundary>,
		webhookRoot
	);
}
