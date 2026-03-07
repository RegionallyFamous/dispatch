<?php
/**
 * AuditAction enum — represents recordable security events.
 *
 * @package Dispatch_For_Telex
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

enum AuditAction: string {
	case Install    = 'install';
	case Remove     = 'remove';
	case Connect    = 'connect';
	case Disconnect = 'disconnect';
	case Update     = 'update';
}
