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
import { Button, Icon, Notice, Spinner } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { copy, check, caution, plugins as pluginsIcon } from '@wordpress/icons';

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
	__( 'Get your code', 'dispatch' ),
	__( 'Open Telex', 'dispatch' ),
	__( "You're connected", 'dispatch' ),
];

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
								<Icon icon={ check } size={ 12 } />
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

	// Initialise nonce middleware and WP Heartbeat listener on mount only.
	useEffect( () => {
		apiFetch.use( apiFetch.createNonceMiddleware( nonce ) );

		const onHeartbeatTick = ( _event, response ) => {
			if ( response?.telex?.is_connected && status === STATUS.WAITING ) {
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
						"Couldn't start the connection. Please try again.",
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
		} catch ( err ) {
			stopPolling();
			setErrorMsg(
				err.message ||
					__( 'Authorization failed or code expired.', 'dispatch' )
			);
			setStatus( STATUS.EXPIRED );
		}
	}

	async function cancelDeviceFlow() {
		stopPolling();
		try {
			await apiFetch( {
				url: `${ restUrl }/auth/device`,
				method: 'DELETE',
			} );
		} catch {
			// Ignore cancel errors.
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
			<div className="telex-connect-wrap">
				<StepIndicator active={ 3 } />
				<div
					className="telex-connect-card telex-connect-card--success"
					aria-live="assertive"
				>
					<div className="telex-connect-success-icon">
						<Icon icon={ check } size={ 40 } />
					</div>
					<h2>{ __( 'Connected!', 'dispatch' ) }</h2>
					<p>{ __( 'Redirecting to your projects…', 'dispatch' ) }</p>
					<Spinner />
				</div>
			</div>
		);
	}

	if ( status === STATUS.IDLE ) {
		return (
			<div className="telex-connect-wrap">
				<StepIndicator active={ 1 } />
				<div className="telex-connect-card">
					<div className="telex-connect-brand">
						<Icon icon={ pluginsIcon } size={ 32 } />
					</div>
					<h2>{ __( 'Connect to Telex', 'dispatch' ) }</h2>
					<p>
						{ __(
							'Link your Telex account to browse and install your projects without leaving WordPress.',
							'dispatch'
						) }
					</p>
					<div className="telex-connect-features">
						<div className="telex-connect-feature">
							<Icon icon={ check } size={ 14 } />
							{ __( 'One-time authorisation', 'dispatch' ) }
						</div>
						<div className="telex-connect-feature">
							<Icon icon={ check } size={ 14 } />
							{ __( 'No password required', 'dispatch' ) }
						</div>
						<div className="telex-connect-feature">
							<Icon icon={ check } size={ 14 } />
							{ __( 'Revoke access anytime', 'dispatch' ) }
						</div>
					</div>
					<Button
						variant="primary"
						onClick={ startDeviceFlow }
						__next40pxDefaultSize
					>
						{ __( 'Connect', 'dispatch' ) }
					</Button>
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
					aria-live="polite"
				>
					<Spinner />
					<span>{ __( 'Starting authorization…', 'dispatch' ) }</span>
				</div>
			</div>
		);
	}

	if ( status === STATUS.WAITING && deviceData ) {
		return (
			<div className="telex-connect-wrap">
				<StepIndicator active={ 2 } />
				<div className="telex-connect-card">
					<h2>{ __( 'Enter your code', 'dispatch' ) }</h2>
					<p>
						{ __(
							'Open the Telex app and enter the code below to authorize this site.',
							'dispatch'
						) }
					</p>

					<div className="telex-device-code-block" aria-live="polite">
						<div
							ref={ userCodeRef }
							className="telex-user-code"
							role="status"
							aria-label={ sprintf(
								/* translators: %s: user code */
								__( 'Your device code is %s', 'dispatch' ),
								deviceData.user_code
							) }
						>
							{ deviceData.user_code }
						</div>
						<Button
							variant="tertiary"
							icon={ copy }
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

					<Button
						variant="primary"
						href={ deviceData.verification_uri_complete }
						target="_blank"
						rel="noopener noreferrer"
						__next40pxDefaultSize
					>
						{ __( 'Open Telex to authorize →', 'dispatch' ) }
					</Button>

					<div className="telex-polling-status" aria-live="polite">
						<Spinner />
						<span>
							{ __( 'Waiting for authorization…', 'dispatch' ) }
						</span>
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
			</div>
		);
	}

	if ( status === STATUS.EXPIRED || status === STATUS.ERROR ) {
		return (
			<div className="telex-connect-wrap">
				<StepIndicator active={ 1 } />
				<div className="telex-connect-card" aria-live="assertive">
					<div className="telex-connect-error-icon">
						<Icon icon={ caution } size={ 32 } />
					</div>
					<Notice status="error" isDismissible={ false }>
						{ errorMsg ||
							__(
								'Device code expired. Please try again.',
								'dispatch'
							) }
					</Notice>
					<Button
						variant="primary"
						onClick={ startDeviceFlow }
						__next40pxDefaultSize
					>
						{ __( 'Try Again', 'dispatch' ) }
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
