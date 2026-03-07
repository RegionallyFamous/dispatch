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
	__( 'Get a code', 'dispatch' ),
	__( 'Approve in Telex', 'dispatch' ),
	__( "You're in!", 'dispatch' ),
];

function TelexAbout() {
	return (
		<div className="telex-about">
			<p>
				{ __(
					'Telex is a natural language WordPress block and theme builder by Automattic AI Labs. Describe what you want in plain English, and Telex generates a fully functional block or theme you can download and deploy.',
					'dispatch'
				) }
			</p>
			<ExternalLink href="https://telex.automattic.ai">
				{ __( 'Learn more at telex.automattic.ai', 'dispatch' ) }
			</ExternalLink>
		</div>
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
			// Log but don't surface — the user explicitly cancelled, so resetting
			// the UI is always the right outcome even if the server call fails.
			// eslint-disable-next-line no-console
			console.warn( 'Dispatch: cancel device flow failed', err );
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
					role="alert"
				>
					<div className="telex-connect-success-icon">
						<Icon
							icon={ check }
							size={ 40 }
							aria-hidden={ true }
							focusable={ false }
						/>
					</div>
					<h2>{ __( "You're in!", 'dispatch' ) }</h2>
					<p>{ __( 'Taking you to your projects…', 'dispatch' ) }</p>
					<Spinner aria-hidden={ true } />
				</div>
			</div>
		);
	}

	if ( status === STATUS.IDLE ) {
		return (
			<div className="telex-connect-wrap">
				<TelexAbout />
				<StepIndicator active={ 1 } />
				<div className="telex-connect-card">
					<div className="telex-connect-brand">
						<Icon
							icon={ pluginsIcon }
							size={ 32 }
							aria-hidden={ true }
							focusable={ false }
						/>
					</div>
					<h2>{ __( 'Link your Telex account', 'dispatch' ) }</h2>
					<p>
						{ __(
							'Your Telex projects, right here in WordPress. Connect once — install anything with a click.',
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
							{ __( "One-time setup — that's it", 'dispatch' ) }
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
							{ __( 'Disconnect whenever you like', 'dispatch' ) }
						</li>
					</ul>
					<Button
						variant="primary"
						onClick={ startDeviceFlow }
						__next40pxDefaultSize
					>
						{ __( "Let's connect", 'dispatch' ) }
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
			<div className="telex-connect-wrap">
				<StepIndicator active={ 2 } />
				<div className="telex-connect-card">
					<h2>{ __( "You're almost in", 'dispatch' ) }</h2>
					<p>
						{ __(
							"Open Telex, enter this code, and you're connected.",
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
						<Spinner aria-hidden={ true } />
						<span>
							{ __( 'Waiting for you to approve…', 'dispatch' ) }
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
