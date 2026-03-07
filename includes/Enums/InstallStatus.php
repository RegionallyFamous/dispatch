<?php
/**
 * InstallStatus enum — represents the lifecycle state of a project install.
 *
 * @package Dispatch_For_Telex
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

enum InstallStatus: string {
	case Idle       = 'idle';
	case Installing = 'installing';
	case Installed  = 'installed';
	case Failed     = 'failed';
	case Removing   = 'removing';
}
