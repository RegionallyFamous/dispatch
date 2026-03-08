/**
 * Dispatch Command Palette integration.
 *
 * Registers Dispatch commands in the WordPress global Command Palette (⌘K / Ctrl+K)
 * so they surface on every wp-admin screen, not just the Dispatch page.
 *
 * Requires WordPress 6.3+ (@wordpress/commands).
 */
import { render } from '@wordpress/element';
import { useCommand } from '@wordpress/commands';
import { __ } from '@wordpress/i18n';
import { plugins as pluginsIcon } from '@wordpress/icons';

const adminUrl = window?.telexCommands?.adminUrl || '';

/**
 * Registers the global "Open Dispatch" command.
 * This component mounts on every admin page via `admin_enqueue_scripts`.
 *
 * @return {null} Renders nothing visible.
 */
function DispatchCommands() {
	useCommand( {
		name: 'dispatch/open',
		label: __( 'Open Dispatch', 'dispatch' ),
		icon: pluginsIcon,
		callback: () => {
			window.location.href = adminUrl + 'admin.php?page=telex';
		},
	} );

	return null;
}

// Mount as a lightweight React tree — no DOM output needed.
const container = document.getElementById( 'telex-commands-root' );
if ( container ) {
	render( <DispatchCommands />, container );
}
