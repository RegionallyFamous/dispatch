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
	Modal,
	Notice,
	SearchControl,
	Spinner,
	TabPanel,
	Tooltip,
	Icon,
} from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
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
// Store
// ---------------------------------------------------------------------------

const DEFAULT_STATE = {
	projects: [],
	installedProjects: {}, // publicId → { version, type, … }
	loading: false,
	error: null,
	searchQuery: '',
	installing: {}, // publicId → 'installing' | 'removing' | 'idle' | 'failed'
	notice: null,
	confirmRemove: null, // publicId awaiting confirmation
};

const actions = {
	setProjects: ( projects ) => ( { type: 'SET_PROJECTS', projects } ),
	setInstalledProjects: ( installed ) => ( {
		type: 'SET_INSTALLED',
		installed,
	} ),
	setLoading: ( loading ) => ( { type: 'SET_LOADING', loading } ),
	setError: ( error ) => ( { type: 'SET_ERROR', error } ),
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
};

function reducer( state = DEFAULT_STATE, action ) {
	switch ( action.type ) {
		case 'SET_PROJECTS':
			return { ...state, projects: action.projects };
		case 'SET_INSTALLED':
			return { ...state, installedProjects: action.installed };
		case 'SET_LOADING':
			return { ...state, loading: action.loading };
		case 'SET_ERROR':
			return { ...state, error: action.error };
		case 'SET_SEARCH':
			return { ...state, searchQuery: action.query };
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
		getSearchQuery: ( state ) => state.searchQuery,
		getInstallStatus: ( state, publicId ) =>
			state.installing[ publicId ] || 'idle',
		getNotice: ( state ) => state.notice,
		getConfirmRemove: ( state ) => state.confirmRemove,
	},
} );

register( store );

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

	if ( installStatus === 'installing' || installStatus === 'removing' ) {
		return (
			<span
				className="telex-badge telex-badge--loading"
				aria-live="polite"
			>
				<Spinner />
				{ installStatus === 'installing'
					? __( 'Installing…', 'dispatch' )
					: __( 'Removing…', 'dispatch' ) }
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
				<h3>{ __( 'No results', 'dispatch' ) }</h3>
				<p>
					{ sprintf(
						/* translators: %s: search query */
						__(
							'No projects match "%s". Try a different search.',
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
			heading: __( 'No projects yet', 'dispatch' ),
			body: __(
				"Your Telex projects will show up here once you've created some.",
				'dispatch'
			),
		},
		updates: {
			icon: check,
			heading: __( 'Everything is up to date', 'dispatch' ),
			body: __(
				'All installed projects are running the latest version.',
				'dispatch'
			),
		},
		blocks: {
			icon: pluginsIcon,
			heading: __( 'No blocks', 'dispatch' ),
			body: __(
				"You don't have any block projects in your Telex account.",
				'dispatch'
			),
		},
		themes: {
			icon: layoutIcon,
			heading: __( 'No themes', 'dispatch' ),
			body: __(
				"You don't have any theme projects in your Telex account.",
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
// Project card
// ---------------------------------------------------------------------------

function ProjectCard( { project, restUrl, onRefresh } ) {
	const { setInstallStatus, setNotice, setConfirmRemove } =
		useDispatch( 'telex/admin' );
	const installedProjects = useSelect( ( select ) =>
		select( 'telex/admin' ).getInstalledProjects()
	);
	const confirmRemove = useSelect( ( select ) =>
		select( 'telex/admin' ).getConfirmRemove()
	);
	const installStatus = useSelect( ( select ) =>
		select( 'telex/admin' ).getInstallStatus( project.publicId )
	);

	const installed = installedProjects[ project.publicId ];
	const needsUpdate = installed && project.currentVersion > installed.version;
	const isInstalled = !! installed;
	const isBusy =
		installStatus === 'installing' || installStatus === 'removing';
	const typeStr = project.projectType?.toLowerCase() || 'block';

	async function handleInstall() {
		setInstallStatus( project.publicId, 'installing' );
		try {
			await apiFetch( {
				url: `${ restUrl }/projects/${ project.publicId }/install`,
				method: 'POST',
				data: { activate: false },
			} );
			setNotice( {
				type: 'success',
				message: sprintf(
					/* translators: %s: project name */
					__( '%s installed successfully.', 'dispatch' ),
					project.name
				),
			} );
			await onRefresh();
			setInstallStatus( project.publicId, 'idle' );
		} catch ( err ) {
			setInstallStatus( project.publicId, 'failed' );
			setNotice( {
				type: 'error',
				message: err.message || __( 'Install failed.', 'dispatch' ),
			} );
		}
	}

	async function handleUpdate() {
		setInstallStatus( project.publicId, 'installing' );
		try {
			await apiFetch( {
				url: `${ restUrl }/projects/${ project.publicId }/install`,
				method: 'POST',
				data: { activate: false },
			} );
			setNotice( {
				type: 'success',
				message: sprintf(
					/* translators: %s: project name */
					__( '%s updated successfully.', 'dispatch' ),
					project.name
				),
			} );
			await onRefresh();
			setInstallStatus( project.publicId, 'idle' );
		} catch ( err ) {
			setInstallStatus( project.publicId, 'failed' );
			setNotice( {
				type: 'error',
				message: err.message || __( 'Update failed.', 'dispatch' ),
			} );
		}
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
					__( '%s removed.', 'dispatch' ),
					project.name
				),
			} );
			await onRefresh();
			setInstallStatus( project.publicId, 'idle' );
		} catch ( err ) {
			setInstallStatus( project.publicId, 'idle' );
			setNotice( {
				type: 'error',
				message: err.message || __( 'Remove failed.', 'dispatch' ),
			} );
		}
	}

	return (
		<Card
			className={ [
				'telex-project-card',
				needsUpdate ? 'telex-project-card--has-update' : '',
				isInstalled ? 'telex-project-card--installed' : '',
			]
				.filter( Boolean )
				.join( ' ' ) }
		>
			<CardHeader>
				<div className="telex-card-title-row">
					<strong className="telex-card-title">
						{ project.name }
					</strong>
					<TypeBadge type={ typeStr } />
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
								__( 'Installed: v%s', 'dispatch' ),
								installed.version
							) }
						</span>
					) }
					{ isInstalled && needsUpdate && (
						<span className="telex-meta-item telex-meta-item--new">
							{ sprintf(
								/* translators: %s: version number */
								__( 'Available: v%s', 'dispatch' ),
								project.currentVersion
							) }
						</span>
					) }
					{ ! isInstalled && project.currentVersion && (
						<span className="telex-meta-item">
							{ sprintf(
								/* translators: %s: version number */
								__( 'v%s available', 'dispatch' ),
								project.currentVersion
							) }
						</span>
					) }
				</div>

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
						<Tooltip
							text={ __(
								'Download and install to this site',
								'dispatch'
							) }
						>
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
								__( 'Update to v%s', 'dispatch' ),
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
							text={ __( 'Remove from this site', 'dispatch' ) }
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
									"This removes %s from your site and deletes all its files. There's no undo.",
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
								{ __( 'Remove', 'dispatch' ) }
							</Button>
							<Button
								variant="secondary"
								onClick={ () => setConfirmRemove( null ) }
								__next40pxDefaultSize
							>
								{ __( 'Cancel', 'dispatch' ) }
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
		setSearchQuery,
		clearNotice,
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
	const searchQuery = useSelect( ( select ) =>
		select( 'telex/admin' ).getSearchQuery()
	);
	const notice = useSelect( ( select ) =>
		select( 'telex/admin' ).getNotice()
	);

	// Fetch project list + installed tracker data.
	const fetchData = useCallback( async () => {
		try {
			const data = await apiFetch( { url: `${ restUrl }/projects` } );
			setProjects( data.projects || [] );
			if ( data.installed ) {
				setInstalledProjects( data.installed );
			}
		} catch ( err ) {
			setError(
				err.message || __( 'Failed to load projects.', 'dispatch' )
			);
		}
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ restUrl ] );

	useEffect( () => {
		apiFetch.use( apiFetch.createNonceMiddleware( nonce ) );
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
			{ notice && (
				<Notice
					status={ notice.type }
					onRemove={ clearNotice }
					isDismissible
				>
					{ notice.message }
				</Notice>
			) }

			{ ! loading && ! error && projects.length > 0 && (
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
							fetchData().finally( () => setLoading( false ) );
						} }
						disabled={ loading }
						aria-label={ __( 'Refresh projects list', 'dispatch' ) }
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
					aria-label={ __( 'Loading projects', 'dispatch' ) }
				>
					<Spinner />
					<span>{ __( 'Loading projects…', 'dispatch' ) }</span>
				</div>
			) }

			{ error && (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) }

			{ ! loading && ! error && (
				<TabPanel
					tabs={ tabs }
					className="telex-tab-panel"
					initialTabName="all"
				>
					{ ( tab ) => {
						const visible = getTabProjects( tab.name );
						return (
							<div
								className="telex-project-grid"
								role="list"
								aria-label={ __(
									'Dispatch projects',
									'dispatch'
								) }
								aria-live="polite"
							>
								{ visible.length === 0 ? (
									<EmptyState
										tab={ tab.name }
										searchQuery={ searchQuery }
									/>
								) : (
									visible.map( ( project ) => (
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
							{ __( 'Something went wrong.', 'dispatch' ) }
						</strong>{ ' ' }
						{ __(
							'Reload the page to try again. If it keeps happening, check the browser console.',
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
