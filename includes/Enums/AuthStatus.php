<?php
/**
 * AuthStatus enum — represents the OAuth connection state.
 *
 * @package Dispatch_For_Telex
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

enum AuthStatus: string {
	case Connected    = 'connected';
	case Disconnected = 'disconnected';
}
