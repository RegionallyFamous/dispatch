<?php
/**
 * GDPR / WordPress privacy framework integration for Dispatch for Telex.
 *
 * Registers a personal-data exporter and eraser for the audit log table
 * (which stores user IDs) and adds a privacy policy snippet describing
 * what data the plugin stores.
 *
 * @package Dispatch_For_Telex
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Integrates Dispatch with the WordPress privacy tools (Tools → Export Personal Data
 * and Tools → Erase Personal Data) and the built-in privacy policy guide.
 */
class Telex_Privacy {

	/**
	 * Registers all privacy hooks. Called from Telex_Admin::init().
	 *
	 * @return void
	 */
	public static function init(): void {
		add_filter( 'wp_privacy_personal_data_exporters', self::register_exporter( ... ) );
		add_filter( 'wp_privacy_personal_data_erasers', self::register_eraser( ... ) );
		add_action( 'admin_init', self::add_privacy_policy_content( ... ) );
	}

	// -------------------------------------------------------------------------
	// Exporter
	// -------------------------------------------------------------------------

	/**
	 * Registers the Dispatch personal data exporter.
	 *
	 * @param array<string, array<string, mixed>> $exporters Registered exporters.
	 * @return array<string, array<string, mixed>>
	 */
	public static function register_exporter( array $exporters ): array {
		$exporters['dispatch-for-telex'] = [
			'exporter_friendly_name' => __( 'Dispatch for Telex', 'dispatch' ),
			'callback'               => self::export_user_data( ... ),
		];
		return $exporters;
	}

	/**
	 * Exports audit log entries associated with a given email address.
	 *
	 * @param string $email_address The email address whose data is being exported.
	 * @param int    $page          Pagination page number (1-based).
	 * @return array{data: array<int, array<string, mixed>>, done: bool}
	 */
	public static function export_user_data( string $email_address, int $page = 1 ): array {
		$user = get_user_by( 'email', $email_address );
		if ( ! $user ) {
			return [
				'data' => [],
				'done' => true,
			];
		}

		$per_page = 50;
		$offset   = ( $page - 1 ) * $per_page;

		global $wpdb;
		$table = Telex_Audit_Log::table_name();

		// $table is derived from $wpdb->prefix and is safe to interpolate here.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE user_id = %d ORDER BY id DESC LIMIT %d OFFSET %d",
				$user->ID,
				$per_page,
				$offset
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( ! is_array( $rows ) || empty( $rows ) ) {
			return [
				'data' => [],
				'done' => true,
			];
		}

		$export_items = [];
		foreach ( $rows as $row ) {
			$export_items[] = [
				'group_id'          => 'dispatch-audit-log',
				'group_label'       => __( 'Dispatch Audit Log', 'dispatch' ),
				'group_description' => __( 'A record of actions this user performed in the Dispatch plugin.', 'dispatch' ),
				'item_id'           => 'dispatch-audit-' . (int) $row['id'],
				'data'              => [
					[
						'name'  => __( 'Action', 'dispatch' ),
						'value' => esc_html( (string) ( $row['action'] ?? '' ) ),
					],
					[
						'name'  => __( 'Project ID', 'dispatch' ),
						'value' => esc_html( (string) ( $row['public_id'] ?? '' ) ),
					],
					[
						'name'  => __( 'Date (UTC)', 'dispatch' ),
						'value' => esc_html( (string) ( $row['created_at'] ?? '' ) ),
					],
				],
			];
		}

		// Check if there are more pages.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE user_id = %d", $user->ID )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return [
			'data' => $export_items,
			'done' => ( $offset + count( $rows ) ) >= $total,
		];
	}

	// -------------------------------------------------------------------------
	// Eraser
	// -------------------------------------------------------------------------

	/**
	 * Registers the Dispatch personal data eraser.
	 *
	 * @param array<string, array<string, mixed>> $erasers Registered erasers.
	 * @return array<string, array<string, mixed>>
	 */
	public static function register_eraser( array $erasers ): array {
		$erasers['dispatch-for-telex'] = [
			'eraser_friendly_name' => __( 'Dispatch for Telex', 'dispatch' ),
			'callback'             => self::erase_user_data( ... ),
		];
		return $erasers;
	}

	/**
	 * Anonymises audit log entries for a given email address.
	 *
	 * Rows are not deleted — the audit trail integrity is preserved. Instead,
	 * user_id is set to 0, which renders the entry anonymous while keeping
	 * the action and timestamp for operational records.
	 *
	 * @param string $email_address The email address whose data is being erased.
	 * @param int    $page          Pagination page number (1-based, unused here as one query handles all).
	 * @return array{items_removed: int, items_retained: int, messages: string[], done: bool}
	 */
	public static function erase_user_data( string $email_address, int $page = 1 ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- WP erasers receive $page; we handle all rows in one query.
		$user = get_user_by( 'email', $email_address );
		if ( ! $user ) {
			return [
				'items_removed'  => 0,
				'items_retained' => 0,
				'messages'       => [],
				'done'           => true,
			];
		}

		global $wpdb;
		$table = Telex_Audit_Log::table_name();

		// Count affected rows before anonymising.
		// $table is derived from $wpdb->prefix and is safe to interpolate here.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$count = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE user_id = %d", $user->ID )
		);

		if ( $count > 0 ) {
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$table} SET user_id = 0 WHERE user_id = %d",
					$user->ID
				)
			);
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return [
			'items_removed'  => $count,
			'items_retained' => 0,
			'messages'       => [],
			'done'           => true,
		];
	}

	// -------------------------------------------------------------------------
	// Privacy policy content
	// -------------------------------------------------------------------------

	/**
	 * Adds Dispatch-specific content to the WordPress privacy policy guide.
	 *
	 * @return void
	 */
	public static function add_privacy_policy_content(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		$content  = '<h2>' . esc_html__( 'Dispatch for Telex', 'dispatch' ) . '</h2>';
		$content .= '<p>' . esc_html__( 'This plugin stores the following data on your server:', 'dispatch' ) . '</p>';
		$content .= '<ul>';
		$content .= '<li>' . esc_html__( 'An encrypted OAuth 2.0 access token used to authenticate with the Telex API. This token is stored in the WordPress options table and is encrypted with AES-256-GCM using a per-site key derived from your WordPress secret keys. No personal data is included in the token itself.', 'dispatch' ) . '</li>';
		$content .= '<li>' . esc_html__( 'An audit log table recording every install, update, and removal of Telex projects. Each entry stores: the action type, the project identifier, the WordPress user ID of the admin who performed the action, and a UTC timestamp. This data is never transmitted off-site — it lives only in your WordPress database.', 'dispatch' ) . '</li>';
		$content .= '</ul>';
		$content .= '<p>' . esc_html__( 'Dispatch for Telex does not collect or transmit any visitor or customer data. It only stores administrator action records as described above.', 'dispatch' ) . '</p>';

		wp_add_privacy_policy_content(
			__( 'Dispatch for Telex', 'dispatch' ),
			wp_kses_post( $content )
		);
	}
}
