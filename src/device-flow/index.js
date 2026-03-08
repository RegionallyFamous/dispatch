/**
 * Telex Device Flow — OAuth 2.0 Device Authorization (RFC 8628)
 *
 * Manages the connect-to-Telex UI using React + @wordpress/components.
 * All API calls go through the telex/v1 REST endpoints.
 *
 * Uses WP Heartbeat API for status polling when the device flow is active,
 * which piggybacks on WordPress's existing server polling instead of opening
 * an independent polling loop.
 */
import { render, useState, useEffect, useRef } from '@wordpress/element';
import {
	Button,
	ExternalLink,
	Icon,
	Notice,
	Spinner,
} from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { copy, check, caution } from '@wordpress/icons';

const STATUS = {
	IDLE: 'idle',
	STARTING: 'starting',
	WAITING: 'waiting',
	SUCCESS: 'success',
	EXPIRED: 'expired',
	ERROR: 'error',
};

// Steps shown above the connect card.
const STEPS = [
	__( 'Get a code', 'dispatch' ),
	__( 'Approve in Telex', 'dispatch' ),
	__( "You're in!", 'dispatch' ),
];

function TelexLogo( { className } ) {
	return (
		<svg
			width="153"
			height="32"
			viewBox="0 0 153 32"
			fill="currentColor"
			aria-label={ __( 'Telex', 'dispatch' ) }
			role="img"
			className={ className }
		>
			<path d="M129.428 15.726L119.051 0H131.211L135.828 7.63443L140.491 0H152.423L142.046 15.6346L152.88 32.0006H140.72L135.645 23.6804L130.525 32.0006H118.594L129.428 15.726Z" />
			<path d="M90.4297 0H117.722V8.7773H100.944V12.0231H116.579V19.7489H100.944V23.2233H117.95V32.0006H90.4297V0Z" />
			<path d="M62.3496 0H73.0469V22.8576H88.0872V32.0006H62.3496V0Z" />
			<path d="M31.9453 0H59.2372V8.7773H42.4598V12.0231H58.0944V19.7489H42.4598V23.2233H59.4658V32.0006H31.9453V0Z" />
			<path d="M9.41732 9.05159H0V0H29.532V9.05159H20.1147V32.0006H9.41732V9.05159Z" />
		</svg>
	);
}

function StepIndicator( { active } ) {
	return (
		<ol
			className="telex-steps"
			aria-label={ __( 'Connection steps', 'dispatch' ) }
		>
			{ STEPS.map( ( label, idx ) => {
				const stepNum = idx + 1;
				const isDone = stepNum < active;
				const isCurrent = stepNum === active;
				return (
					<li
						key={ label }
						className={ [
							'telex-step',
							isDone ? 'telex-step--done' : '',
							isCurrent ? 'telex-step--active' : '',
						]
							.filter( Boolean )
							.join( ' ' ) }
						aria-current={ isCurrent ? 'step' : undefined }
					>
						<span className="telex-step__dot">
							{ isDone ? (
								<Icon
									icon={ check }
									size={ 12 }
									aria-hidden={ true }
									focusable={ false }
								/>
							) : (
								stepNum
							) }
						</span>
						<span className="telex-step__label">{ label }</span>
					</li>
				);
			} ) }
		</ol>
	);
}

function DeviceFlowApp() {
	const container = document.getElementById( 'telex-device-flow-app' );
	const restUrl = container?.dataset?.restUrl?.replace( /\/$/, '' ) || '';
	const nonce = container?.dataset?.nonce || '';

	const [ status, setStatus ] = useState( STATUS.IDLE );
	const [ deviceData, setDeviceData ] = useState( null );
	const [ errorMsg, setErrorMsg ] = useState( '' );
	const [ copied, setCopied ] = useState( false );
	const pollRef = useRef( null );
	const copyTimeoutRef = useRef( null );
	const pollInFlightRef = useRef( false );
	// Keep a ref in sync with status so the Heartbeat handler doesn't close
	// over a stale value from mount time.
	const statusRef = useRef( status );
	useEffect( () => {
		statusRef.current = status;
	}, [ status ] );

	// Initialise nonce middleware and WP Heartbeat listener on mount only.
	useEffect( () => {
		if ( ! DeviceFlowApp._nonceRegistered ) {
			apiFetch.use( apiFetch.createNonceMiddleware( nonce ) );
			DeviceFlowApp._nonceRegistered = true;
		}

		const onHeartbeatTick = ( _event, response ) => {
			if (
				response?.telex?.is_connected &&
				statusRef.current === STATUS.WAITING
			) {
				stopPolling();
				setStatus( STATUS.SUCCESS );
				setTimeout( () => {
					window.location.href =
						window.location.pathname + '?page=telex';
				}, 1500 );
			}
		};

		if ( window.jQuery ) {
			window
				.jQuery( document )
				.on( 'heartbeat-tick.telex', onHeartbeatTick );
			window
				.jQuery( document )
				.on( 'heartbeat-send.telex', ( _e, data ) => {
					data.telex_poll = true;
				} );
		}

		return () => {
			stopPolling();
			clearTimeout( copyTimeoutRef.current );
			if ( window.jQuery ) {
				window.jQuery( document ).off( 'heartbeat-tick.telex' );
				window.jQuery( document ).off( 'heartbeat-send.telex' );
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

	async function startDeviceFlow() {
		setStatus( STATUS.STARTING );
		setErrorMsg( '' );

		try {
			const data = await apiFetch( {
				url: `${ restUrl }/auth/device`,
				method: 'POST',
			} );

			setDeviceData( data );
			setStatus( STATUS.WAITING );
			startPolling( data.interval || 5 );
		} catch ( err ) {
			setErrorMsg(
				err.message ||
					__(
						"Couldn't get a code from Telex. Give it another try.",
						'dispatch'
					)
			);
			setStatus( STATUS.ERROR );
		}
	}

	function startPolling( intervalSeconds ) {
		stopPolling();
		const ms = intervalSeconds * 1000;
		pollRef.current = setInterval( () => pollForToken(), ms );
	}

	async function pollForToken() {
		if ( pollInFlightRef.current ) {
			return; // Previous poll still in-flight; skip this tick.
		}
		pollInFlightRef.current = true;
		try {
			const data = await apiFetch( { url: `${ restUrl }/auth/device` } );

			if ( data.authorized ) {
				stopPolling();
				setStatus( STATUS.SUCCESS );
				setTimeout( () => {
					window.location.href =
						window.location.pathname + '?page=telex';
				}, 1500 );
				return;
			}

			// RFC 8628 §3.5: slow_down — increase interval by 5 s.
			if ( data.status === 'slow_down' && data.interval ) {
				stopPolling();
				startPolling( data.interval );
			}
			// authorization_pending and any other non-terminal state: keep polling.
		} catch ( err ) {
			// Only stop polling on RFC 8628 terminal error codes. Transient
			// network errors (e.g. connection reset, 5xx) should not abort the
			// flow — the user may still approve in the browser tab.
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
				setStatus( STATUS.EXPIRED );
			}
			// Otherwise: transient error — keep polling silently.
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
		} catch ( err ) {
			// Swallow silently — the user explicitly cancelled, so resetting
			// the UI is always the right outcome even if the server call fails.
		}
		setStatus( STATUS.IDLE );
		setDeviceData( null );
	}

	const userCodeRef = useRef( null );

	function handleCopyCode() {
		if ( ! deviceData?.user_code ) {
			return;
		}
		const text = deviceData.user_code;
		if ( window.navigator?.clipboard ) {
			window.navigator.clipboard
				.writeText( text )
				.catch( () => selectCodeText() );
		} else {
			selectCodeText();
		}
		setCopied( true );
		clearTimeout( copyTimeoutRef.current );
		copyTimeoutRef.current = setTimeout( () => setCopied( false ), 2500 );
	}

	function selectCodeText() {
		const el = userCodeRef.current;
		if ( ! el ) {
			return;
		}
		const range = document.createRange();
		range.selectNodeContents( el );
		const sel = el.ownerDocument.defaultView?.getSelection();
		if ( sel ) {
			sel.removeAllRanges();
			sel.addRange( range );
		}
	}

	// -------------------------------------------------------------------------
	// Render states
	// -------------------------------------------------------------------------

	if ( status === STATUS.SUCCESS ) {
		return (
			<div className="telex-connect-wrap telex-connect-wrap--focused">
				<StepIndicator active={ 3 } />
				<div
					className="telex-connect-card telex-connect-card--success"
					role="alert"
				>
					<div className="telex-connect-success-icon">
						<Icon
							icon={ check }
							size={ 28 }
							aria-hidden={ true }
							focusable={ false }
						/>
					</div>
					<h2>{ __( "You're in!", 'dispatch' ) }</h2>
					<p>
						{ __(
							'Your Telex account is connected. Taking you to your projects…',
							'dispatch'
						) }
					</p>
					<Spinner aria-hidden={ true } />
				</div>
			</div>
		);
	}

	if ( status === STATUS.IDLE ) {
		return (
			<div className="telex-connect-wrap telex-connect-wrap--landing">
				{ /* ---- Left: hero ---- */ }
				<div className="telex-connect-hero">
					<h2 className="telex-connect-hero__heading">
						{ __(
							'Your Telex projects, live in WordPress.',
							'dispatch'
						) }
					</h2>
					<p className="telex-connect-hero__body">
						{ __(
							'Build a block or theme in Telex. Deploy it here with one click — no zip files, no FTP, no round trips.',
							'dispatch'
						) }
					</p>
					<ul className="telex-connect-pillars">
						{ [
							__(
								'Install any project in one click',
								'dispatch'
							),
							__(
								'Snapshots, rollbacks & version pinning',
								'dispatch'
							),
							__(
								'Health monitoring & auto-updates',
								'dispatch'
							),
							__( 'WP-CLI and CI/CD ready', 'dispatch' ),
						].map( ( item ) => (
							<li key={ item } className="telex-connect-pillar">
								<span
									className="telex-connect-pillar__dot"
									aria-hidden="true"
								/>
								{ item }
							</li>
						) ) }
					</ul>
					<ExternalLink
						href="https://telex.automattic.ai"
						className="telex-connect-hero__link"
					>
						{ __( 'New to Telex? Start building →', 'dispatch' ) }
					</ExternalLink>
				</div>

				{ /* ---- Right: card ---- */ }
				<div className="telex-connect-card-col">
					<StepIndicator active={ 1 } />
					<div className="telex-connect-card">
						<TelexLogo className="telex-connect-brand__logo" />
						<h2>{ __( 'Link your account', 'dispatch' ) }</h2>
						<p>
							{ __(
								'One-time setup. No passwords. Disconnect whenever you like.',
								'dispatch'
							) }
						</p>
						<ul className="telex-connect-features">
							<li className="telex-connect-feature">
								<Icon
									icon={ check }
									size={ 14 }
									aria-hidden={ true }
									focusable={ false }
								/>
								{ __(
									"One-time setup — that's it",
									'dispatch'
								) }
							</li>
							<li className="telex-connect-feature">
								<Icon
									icon={ check }
									size={ 14 }
									aria-hidden={ true }
									focusable={ false }
								/>
								{ __( 'No password to remember', 'dispatch' ) }
							</li>
							<li className="telex-connect-feature">
								<Icon
									icon={ check }
									size={ 14 }
									aria-hidden={ true }
									focusable={ false }
								/>
								{ __(
									'Disconnect whenever you like',
									'dispatch'
								) }
							</li>
						</ul>
						<Button
							variant="primary"
							onClick={ startDeviceFlow }
							__next40pxDefaultSize
						>
							{ __( 'Connect to Telex →', 'dispatch' ) }
						</Button>
					</div>
				</div>
			</div>
		);
	}

	if ( status === STATUS.STARTING ) {
		return (
			<div className="telex-connect-wrap">
				<StepIndicator active={ 1 } />
				<div
					className="telex-connect-card telex-connect-card--loading"
					role="status"
					aria-label={ __( 'Getting your code…', 'dispatch' ) }
				>
					<Spinner aria-hidden={ true } />
					<span aria-hidden={ true }>
						{ __( 'Getting your code…', 'dispatch' ) }
					</span>
				</div>
			</div>
		);
	}

	if ( status === STATUS.WAITING && deviceData ) {
		return (
			<div className="telex-connect-wrap telex-connect-wrap--focused">
				<StepIndicator active={ 2 } />
				<div className="telex-connect-card telex-connect-card--waiting">
					<TelexLogo className="telex-connect-brand__logo" />
					<h2>{ __( 'Enter this code in Telex', 'dispatch' ) }</h2>
					<p>
						{ __(
							'Open Telex in a new tab, paste the code below, and approve the connection.',
							'dispatch'
						) }
					</p>

					<div className="telex-device-code-block">
						<div
							ref={ userCodeRef }
							className="telex-user-code"
							role="status"
							aria-live="polite"
						>
							<span className="screen-reader-text">
								{ sprintf(
									/* translators: %s: user code */
									__( 'Your device code is %s', 'dispatch' ),
									deviceData.user_code
								) }
							</span>
							<span aria-hidden={ true }>
								{ deviceData.user_code }
							</span>
						</div>
						<Button
							variant="secondary"
							icon={ copied ? check : copy }
							onClick={ handleCopyCode }
							aria-label={ __(
								'Copy code to clipboard',
								'dispatch'
							) }
							__next40pxDefaultSize
							className={ copied ? 'telex-copy-btn--copied' : '' }
						>
							{ copied
								? __( 'Copied!', 'dispatch' )
								: __( 'Copy code', 'dispatch' ) }
						</Button>
					</div>

					{ /^https:\/\//i.test(
						deviceData.verification_uri_complete
					) ? (
						<Button
							variant="primary"
							href={ deviceData.verification_uri_complete }
							target="_blank"
							rel="noopener noreferrer"
							__next40pxDefaultSize
						>
							{ __( 'Open Telex and approve →', 'dispatch' ) }
							<span className="screen-reader-text">
								{ __( '(opens in a new tab)', 'dispatch' ) }
							</span>
						</Button>
					) : (
						<Notice status="error" isDismissible={ false }>
							{ __(
								'The verification link received from the server is invalid. Please try again.',
								'dispatch'
							) }
						</Notice>
					) }

					<div className="telex-polling-status">
						<span
							className="telex-polling-dot"
							aria-hidden={ true }
						/>
						<span>
							{ __( 'Waiting for approval…', 'dispatch' ) }
						</span>
					</div>

					<button
						type="button"
						className="telex-cancel-link"
						onClick={ cancelDeviceFlow }
					>
						{ __( 'Cancel', 'dispatch' ) }
					</button>
				</div>
			</div>
		);
	}

	if ( status === STATUS.EXPIRED || status === STATUS.ERROR ) {
		return (
			<div className="telex-connect-wrap">
				<StepIndicator active={ 1 } />
				<div className="telex-connect-card" role="alert">
					<div className="telex-connect-error-icon">
						<Icon
							icon={ caution }
							size={ 32 }
							aria-hidden={ true }
							focusable={ false }
						/>
					</div>
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
				</div>
			</div>
		);
	}

	return null;
}

// ---------------------------------------------------------------------------
// Boot
// ---------------------------------------------------------------------------

const root = document.getElementById( 'telex-device-flow-app' );
if ( root ) {
	render( <DeviceFlowApp />, root );
}
