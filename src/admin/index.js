/**
 * Telex Admin — Projects Page
 *
 * Renders the project card grid, handles install/update/remove,
 * and manages UI state via @wordpress/data.
 */
import { render, useState, useEffect, Component } from '@wordpress/element';
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
} from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';

// ---------------------------------------------------------------------------
// Store
// ---------------------------------------------------------------------------

const DEFAULT_STATE = {
	projects: [],
	loading: false,
	error: null,
	searchQuery: '',
	installing: {}, // publicId → InstallStatus
	notice: null,
	confirmRemove: null, // publicId to confirm
};

const actions = {
	setProjects: ( projects ) => ( { type: 'SET_PROJECTS', projects } ),
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
// Components
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

	if ( installed && remoteVersion > installed.version ) {
		return (
			<span className="telex-badge telex-badge--update">
				{ sprintf(
					/* translators: 1: installed version, 2: available version */
					__( 'v%1$d → v%2$d', 'dispatch' ),
					installed.version,
					remoteVersion
				) }
			</span>
		);
	}

	return (
		<span
			className="telex-badge telex-badge--installed"
			aria-label={ __( 'Up to date', 'dispatch' ) }
		>
			{ __( 'Up to date', 'dispatch' ) }
		</span>
	);
}

function ProjectCard( { project, installedProjects, restUrl } ) {
	const { setInstallStatus, setNotice, setConfirmRemove } =
		useDispatch( 'telex/admin' );
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

	async function handleInstall( activate = false ) {
		setInstallStatus( project.publicId, 'installing' );
		try {
			await apiFetch( {
				url: `${ restUrl }/projects/${ project.publicId }/install`,
				method: 'POST',
				data: { activate },
			} );
			setNotice( {
				type: 'success',
				message: sprintf(
					/* translators: %s: project name */
					__( '%s installed successfully.', 'dispatch' ),
					project.name
				),
			} );
			// Reload to sync state.
			window.location.reload();
		} catch ( err ) {
			setInstallStatus( project.publicId, 'failed' );
			setNotice( {
				type: 'error',
				message: err.message || __( 'Install failed.', 'dispatch' ),
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
			window.location.reload();
		} catch ( err ) {
			setInstallStatus( project.publicId, 'idle' );
			setNotice( {
				type: 'error',
				message: err.message || __( 'Remove failed.', 'dispatch' ),
			} );
		}
	}

	return (
		<Card className="telex-project-card">
			<CardHeader>
				<strong>{ project.name }</strong>
				<span className="telex-project-type">
					{ project.projectType || __( 'Block', 'dispatch' ) }
				</span>
			</CardHeader>
			<CardBody>
				<StatusBadge
					publicId={ project.publicId }
					remoteVersion={ project.currentVersion }
					installed={ installed }
				/>

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
						<>
							<Button
								variant="primary"
								onClick={ () => handleInstall( false ) }
								disabled={ isBusy }
								__next40pxDefaultSize
							>
								{ __( 'Install', 'dispatch' ) }
							</Button>
							<Button
								variant="secondary"
								onClick={ () => handleInstall( true ) }
								disabled={ isBusy }
								__next40pxDefaultSize
							>
								{ __( 'Install & Activate', 'dispatch' ) }
							</Button>
						</>
					) }

					{ isInstalled && needsUpdate && (
						<Button
							variant="primary"
							onClick={ () => handleInstall( false ) }
							disabled={ isBusy }
							__next40pxDefaultSize
						>
							{ __( 'Update', 'dispatch' ) }
						</Button>
					) }

					{ isInstalled && (
						<Button
							variant="tertiary"
							isDestructive
							onClick={ () =>
								setConfirmRemove( project.publicId )
							}
							disabled={ isBusy }
							__next40pxDefaultSize
						>
							{ __( 'Remove', 'dispatch' ) }
						</Button>
					) }
				</div>

				{ confirmRemove === project.publicId && (
					<Modal
						title={ __( 'Confirm removal', 'dispatch' ) }
						onRequestClose={ () => setConfirmRemove( null ) }
					>
						<p>
							{ sprintf(
								/* translators: %s: project name */
								__( 'Remove %s from this site?', 'dispatch' ),
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

function ProjectsApp() {
	const container = document.getElementById( 'telex-projects-app' );
	const restUrl = container?.dataset?.restUrl?.replace( /\/$/, '' ) || '';
	const nonce = container?.dataset?.nonce || '';
	const disconnectUrl = container?.dataset?.disconnectUrl || '';

	const { setProjects, setLoading, setError, setSearchQuery, clearNotice } =
		useDispatch( 'telex/admin' );
	const projects = useSelect( ( select ) =>
		select( 'telex/admin' ).getProjects()
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

	const [ installedProjects, setInstalledProjects ] = useState( {} );

	// Fetch on mount — deps intentionally empty; nonce/restUrl are read once
	// from the DOM on first render and never change.
	useEffect( () => {
		apiFetch.use( apiFetch.createNonceMiddleware( nonce ) );

		setLoading( true );

		apiFetch( { url: `${ restUrl }/projects` } )
			.then( ( data ) => {
				setProjects( data.projects || [] );
				// Tracker data is co-located in the same response.
				if ( data.installed ) {
					setInstalledProjects( data.installed );
				}
				setLoading( false );
			} )
			.catch( ( err ) => {
				setError(
					err.message || __( 'Failed to load projects.', 'dispatch' )
				);
				setLoading( false );
			} );
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [] );

	const filteredProjects = projects.filter(
		( p ) =>
			! searchQuery ||
			p.name?.toLowerCase().includes( searchQuery.toLowerCase() )
	);

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

			<div className="telex-toolbar" role="search">
				<SearchControl
					label={ __( 'Search projects', 'dispatch' ) }
					value={ searchQuery }
					onChange={ setSearchQuery }
				/>
				<a
					href={ disconnectUrl }
					className="button button-secondary telex-disconnect"
				>
					{ __( 'Disconnect', 'dispatch' ) }
				</a>
			</div>

			{ loading && (
				<div
					className="telex-loading"
					aria-live="polite"
					aria-label={ __( 'Loading projects', 'dispatch' ) }
				>
					<Spinner />
				</div>
			) }

			{ error && (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) }

			{ ! loading && ! error && (
				<div
					className="telex-project-grid"
					role="list"
					aria-label={ __( 'Telex projects', 'dispatch' ) }
					aria-live="polite"
				>
					{ filteredProjects.length === 0 && (
						<p>{ __( 'No projects found.', 'dispatch' ) }</p>
					) }
					{ filteredProjects.map( ( project ) => (
						<div key={ project.publicId } role="listitem">
							<ProjectCard
								project={ project }
								installedProjects={ installedProjects }
								restUrl={ restUrl }
								nonce={ nonce }
							/>
						</div>
					) ) }
				</div>
			) }
		</div>
	);
}

// ---------------------------------------------------------------------------
// Error boundary
// ---------------------------------------------------------------------------

/**
 * Catches any unhandled JS errors inside the React tree and renders a
 * graceful fallback notice instead of a blank white panel.
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
							{ __(
								'Telex encountered an unexpected error.',
								'dispatch'
							) }
						</strong>{ ' ' }
						{ __(
							'Please reload the page. If the problem persists, check the browser console for details.',
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
