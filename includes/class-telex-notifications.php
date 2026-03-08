<?php
/**
 * Notification channels — email digests and Slack webhooks for Dispatch events.
 *
 * @package Dispatch_For_Telex
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sends notifications via email and/or Slack when notable events occur.
 *
 * Supported triggers:
 *   - Updates available (evaluated on the hourly cache-warm cron)
 *   - Circuit breaker opened (evaluated on the same cron)
 *   - Install/update events (fired immediately from REST callbacks)
 *
 * Channel settings are stored in the 'telex_notification_settings' option.
 */
class Telex_Notifications {

	private const OPTION_KEY        = 'telex_notification_settings';
	private const LAST_NOTIFIED_KEY = 'telex_notifications_last_update_notify';

	/**
	 * Returns the current notification settings with defaults applied.
	 *
	 * @return array{email_enabled: bool, email_address: string, slack_enabled: bool, slack_webhook: string, notify_updates: bool, notify_circuit: bool, notify_install: bool}
	 */
	public static function get_settings(): array {
		$saved = get_option( self::OPTION_KEY, [] );
		if ( ! is_array( $saved ) ) {
			$saved = [];
		}

		return [
			'email_enabled'  => (bool) ( $saved['email_enabled'] ?? false ),
			'email_address'  => sanitize_email( (string) ( $saved['email_address'] ?? get_option( 'admin_email', '' ) ) ),
			'slack_enabled'  => (bool) ( $saved['slack_enabled'] ?? false ),
			'slack_webhook'  => sanitize_url( (string) ( $saved['slack_webhook'] ?? '' ) ),
			'notify_updates' => (bool) ( $saved['notify_updates'] ?? true ),
			'notify_circuit' => (bool) ( $saved['notify_circuit'] ?? true ),
			'notify_install' => (bool) ( $saved['notify_install'] ?? false ),
		];
	}

	/**
	 * Saves notification settings.
	 *
	 * @param array<string, mixed> $settings Partial or full settings to merge.
	 * @return void
	 */
	public static function save_settings( array $settings ): void {
		$current = self::get_settings();
		$merged  = array_merge( $current, array_intersect_key( $settings, $current ) );
		update_option( self::OPTION_KEY, $merged, false );
	}

	/**
	 * Sends a test notification to all enabled channels.
	 *
	 * @return array{sent: bool, message: string}
	 */
	public static function send_test(): array {
		$settings = self::get_settings();
		$sent     = false;
		$messages = [];

		$body = sprintf(
			/* translators: %s: site name */
			__( 'This is a test notification from Dispatch on %s. If you received this, your notification channels are configured correctly.', 'dispatch' ),
			get_bloginfo( 'name' )
		);

		if ( $settings['email_enabled'] && '' !== $settings['email_address'] ) {
			$ok = wp_mail(
				$settings['email_address'],
				/* translators: %s: site name */
				sprintf( __( '[Dispatch] Test notification from %s', 'dispatch' ), get_bloginfo( 'name' ) ),
				$body
			);
			if ( $ok ) {
				$sent       = true;
				$messages[] = sprintf(
					/* translators: %s: email address */
					__( 'Email sent to %s.', 'dispatch' ),
					$settings['email_address']
				);
			} else {
				$messages[] = __( 'Email failed (check wp_mail configuration).', 'dispatch' );
			}
		}

		if ( $settings['slack_enabled'] && '' !== $settings['slack_webhook'] ) {
			$ok = self::send_slack( $settings['slack_webhook'], $body );
			if ( $ok ) {
				$sent       = true;
				$messages[] = __( 'Slack message sent.', 'dispatch' );
			} else {
				$messages[] = __( 'Slack notification failed (check webhook URL).', 'dispatch' );
			}
		}

		if ( ! $settings['email_enabled'] && ! $settings['slack_enabled'] ) {
			$messages[] = __( 'No channels are enabled. Enable email or Slack first.', 'dispatch' );
		}

		return [
			'sent'    => $sent,
			'message' => implode( ' ', $messages ),
		];
	}

	/**
	 * Evaluates update and circuit-breaker triggers.
	 *
	 * Hooked to 'telex_cache_warm' so it fires automatically on the hourly cron
	 * without a separate scheduled task.
	 *
	 * @return void
	 */
	public static function evaluate_cron_triggers(): void {
		$settings = self::get_settings();

		if ( ! $settings['email_enabled'] && ! $settings['slack_enabled'] ) {
			return;
		}

		self::maybe_notify_updates( $settings );
		self::maybe_notify_circuit( $settings );
	}

	/**
	 * Sends an update-available digest if new updates exist and haven't been notified recently.
	 *
	 * @param array<string, mixed> $settings Notification settings.
	 * @return void
	 */
	private static function maybe_notify_updates( array $settings ): void {
		if ( ! $settings['notify_updates'] ) {
			return;
		}

		$cached = Telex_Cache::get_projects();
		if ( ! is_array( $cached ) ) {
			return;
		}

		$installed = Telex_Tracker::get_all();
		$updates   = [];

		foreach ( $cached as $p ) {
			$id    = $p['publicId'] ?? '';
			$local = $installed[ $id ] ?? null;
			if ( null !== $local && ( (int) ( $p['currentVersion'] ?? 0 ) ) > (int) $local['version'] ) {
				$updates[] = (string) ( $p['name'] ?? $id );
			}
		}

		if ( empty( $updates ) ) {
			delete_option( self::LAST_NOTIFIED_KEY );
			return;
		}

		// Throttle: don't notify more than once per 12 hours for the same count.
		$last = (array) get_option( self::LAST_NOTIFIED_KEY, [] );
		if (
			isset( $last['count'], $last['ts'] ) &&
			count( $updates ) === (int) $last['count'] &&
			( time() - (int) $last['ts'] ) < 12 * HOUR_IN_SECONDS
		) {
			return;
		}

		$update_count = count( $updates );
		$subject      = sprintf(
			/* translators: 1: count, 2: site name */
			_n(
				'[Dispatch] %1$d update available on %2$s',
				'[Dispatch] %1$d updates available on %2$s',
				$update_count,
				'dispatch'
			),
			$update_count,
			get_bloginfo( 'name' )
		);

		$body = sprintf(
			/* translators: 1: count, 2: site name */
			_n(
				'%1$d project has an update available on %2$s:',
				'%1$d projects have updates available on %2$s:',
				$update_count,
				'dispatch'
			),
			$update_count,
			get_bloginfo( 'name' )
		);
		$body .= "\n\n" . implode( "\n", array_map( static fn( $n ) => '• ' . $n, $updates ) );
		$body .= "\n\n" . admin_url( 'admin.php?page=telex#updates' );

		self::dispatch( $settings, $subject, $body );

		update_option(
			self::LAST_NOTIFIED_KEY,
			[
				'count' => $update_count,
				'ts'    => time(),
			],
			false
		);
	}

	/**
	 * Sends a circuit-breaker-open notification once per open event.
	 *
	 * @param array<string, mixed> $settings Notification settings.
	 * @return void
	 */
	private static function maybe_notify_circuit( array $settings ): void {
		if ( ! $settings['notify_circuit'] ) {
			return;
		}

		if ( 'open' !== Telex_Circuit_Breaker::status() ) {
			delete_transient( 'telex_circuit_notified' );
			return;
		}

		if ( get_transient( 'telex_circuit_notified' ) ) {
			return;
		}

		$subject = sprintf(
			/* translators: %s: site name */
			__( '[Dispatch] Circuit breaker OPEN on %s', 'dispatch' ),
			get_bloginfo( 'name' )
		);

		$body  = __( 'The Dispatch circuit breaker is OPEN, meaning the Telex API is not responding. Installs and updates are temporarily paused. It will automatically retry in a few minutes.', 'dispatch' );
		$body .= "\n\n" . admin_url( 'admin.php?page=telex' );

		self::dispatch( $settings, $subject, $body );

		set_transient( 'telex_circuit_notified', 1, HOUR_IN_SECONDS );
	}

	/**
	 * Sends a notification via all enabled channels.
	 *
	 * @param array<string, mixed> $settings Notification settings.
	 * @param string               $subject  Email subject / Slack title.
	 * @param string               $body     Plain-text message body.
	 * @return void
	 */
	public static function dispatch( array $settings, string $subject, string $body ): void {
		if ( $settings['email_enabled'] && '' !== $settings['email_address'] ) {
			wp_mail( $settings['email_address'], $subject, $body );
		}

		if ( $settings['slack_enabled'] && '' !== $settings['slack_webhook'] ) {
			self::send_slack( $settings['slack_webhook'], $body, $subject );
		}
	}

	/**
	 * Sends a message to a Slack incoming webhook.
	 *
	 * @param string $webhook_url Slack incoming webhook URL.
	 * @param string $text        Message text.
	 * @param string $title       Optional bold title shown above the text.
	 * @return bool True on success.
	 */
	private static function send_slack( string $webhook_url, string $text, string $title = '' ): bool {
		if ( '' === $webhook_url ) {
			return false;
		}

		$blocks = [];

		if ( '' !== $title ) {
			$blocks[] = [
				'type' => 'header',
				'text' => [
					'type'  => 'plain_text',
					'text'  => $title,
					'emoji' => true,
				],
			];
		}

		$blocks[] = [
			'type' => 'section',
			'text' => [
				'type' => 'mrkdwn',
				'text' => $text,
			],
		];

		$encoded_body = wp_json_encode( [ 'blocks' => $blocks ] );

		$response = wp_remote_post(
			$webhook_url,
			[
				'headers'     => [ 'Content-Type' => 'application/json' ],
				'body'        => false !== $encoded_body ? $encoded_body : '{}',
				'timeout'     => 10,
				'redirection' => 0,
			]
		);

		if ( is_wp_error( $response ) ) {
			wp_trigger_error( __CLASS__, 'Dispatch: Slack notification failed — ' . $response->get_error_message(), E_USER_WARNING );
			return false;
		}

		$code = wp_remote_retrieve_response_code( $response );
		return 200 === (int) $code;
	}
}
