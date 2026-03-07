<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

enum AuthStatus: string {
	case Connected    = 'connected';
	case Disconnected = 'disconnected';
	case Polling      = 'polling';
}
