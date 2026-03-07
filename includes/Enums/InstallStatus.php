<?php

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
