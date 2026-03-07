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
import { Button, Notice, Spinner } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';

const STATUS = {
	IDLE: 'idle',
	STARTING: 'starting',
	WAITING: 'waiting',
	SUCCESS: 'success',
	EXPIRED: 'expired',
	ERROR: 'error',
};

function DeviceFlowApp() {
	const container = document.getElementById( 'telex-device-flow-app' );
	const restUrl = container?.dataset?.restUrl?.replace( /\/$/, '' ) || '';
	const nonce = container?.dataset?.nonce || '';

	const [ status, setStatus ] = useState( STATUS.IDLE );
	const [ deviceData, setDeviceData ] = useState( null );
	const [ errorMsg, setErrorMsg ] = useState( '' );
	const pollRef = useRef( null );

	// Initialise nonce middleware and WP Heartbeat listener on mount only.
	// nonce/status are intentionally excluded — nonce is stable, and status
	// is read via the Heartbeat closure which is acceptable given its role.
	useEffect( () => {
		apiFetch.use( apiFetch.createNonceMiddleware( nonce ) );

		// Listen for Heartbeat responses carrying Telex auth status.
		// This fires every 15–60 s (WP's Heartbeat interval) and supplements
		// the RFC 8628 polling loop — whichever fires first wins.
		const onHeartbeatTick = ( _event, response ) => {
			if ( response?.telex?.is_connected && status === STATUS.WAITING ) {
				stopPolling();
				setStatus( STATUS.SUCCESS );
				setTimeout( () => {
					window.location.href =
						window.location.pathname + '?page=telex';
				}, 1200 );
			}
		};

		if ( window.jQuery ) {
			window
				.jQuery( document )
				.on( 'heartbeat-tick.telex', onHeartbeatTick );
			// Tell the server we want telex status on each tick.
			window
				.jQuery( document )
				.on( 'heartbeat-send.telex', ( _e, data ) => {
					data.telex_poll = true;
				} );
		}

		return () => {
			stopPolling();
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
				err.message || __( 'Failed to start device flow.', 'dispatch' )
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
				// Give the success state a beat to render, then reload.
				setTimeout( () => {
					window.location.href =
						window.location.pathname + '?page=telex';
				}, 1200 );
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

	// -------------------------------------------------------------------------
	// Render states
	// -------------------------------------------------------------------------

	if ( status === STATUS.SUCCESS ) {
		return (
			<div className="telex-connect-card" aria-live="polite">
				<Notice status="success" isDismissible={ false }>
					{ __( 'Connected to Telex! Redirecting…', 'dispatch' ) }
				</Notice>
			</div>
		);
	}

	if ( status === STATUS.IDLE ) {
		return (
			<div className="telex-connect-card">
				<h2>{ __( 'Connect to Telex', 'dispatch' ) }</h2>
				<p>
					{ __(
						'Connect your account to browse and install your Telex projects.',
						'dispatch'
					) }
				</p>
				<Button
					variant="primary"
					onClick={ startDeviceFlow }
					__next40pxDefaultSize
				>
					{ __( 'Connect', 'dispatch' ) }
				</Button>
			</div>
		);
	}

	if ( status === STATUS.STARTING ) {
		return (
			<div className="telex-connect-card" aria-live="polite">
				<Spinner />
				<span>{ __( 'Starting…', 'dispatch' ) }</span>
			</div>
		);
	}

	if ( status === STATUS.WAITING && deviceData ) {
		return (
			<div className="telex-connect-card">
				<div className="telex-device-code-block" aria-live="polite">
					<p>
						{ __(
							'Enter this code in the Telex app:',
							'dispatch'
						) }
					</p>
					<div
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
						variant="secondary"
						href={ deviceData.verification_uri_complete }
						target="_blank"
						rel="noopener noreferrer"
						__next40pxDefaultSize
					>
						{ __( 'Open Telex →', 'dispatch' ) }
					</Button>
				</div>

				<div className="telex-polling-status" aria-live="polite">
					<Spinner />
					<span id="telex-device-status">
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
		);
	}

	if ( status === STATUS.EXPIRED || status === STATUS.ERROR ) {
		return (
			<div className="telex-connect-card" aria-live="assertive">
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
