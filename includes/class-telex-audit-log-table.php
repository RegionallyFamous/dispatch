<?php
/**
 * WP_List_Table implementation for the Telex audit log admin page.
 *
 * @package Dispatch_For_Telex
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Renders the audit log as a read-only WordPress admin table.
 *
 * Displays the 100 most recent security events from the telex_audit_log table,
 * with columns for date, action, project, and the acting user.
 */
class Telex_Audit_Log_Table extends WP_List_Table {

	/**
	 * Sets up the list table with screen and arguments.
	 */
	public function __construct() {
		parent::__construct(
			[
				'singular' => __( 'Audit Event', 'dispatch' ),
				'plural'   => __( 'Audit Events', 'dispatch' ),
				'ajax'     => false,
			]
		);
	}

	/**
	 * Returns the list of columns and their labels.
	 *
	 * @return array<string, string>
	 */
	public function get_columns(): array {
		return [
			'created_at' => __( 'Date', 'dispatch' ),
			'action'     => __( 'Action', 'dispatch' ),
			'public_id'  => __( 'Project', 'dispatch' ),
			'user_id'    => __( 'User', 'dispatch' ),
		];
	}

	/**
	 * Returns columns that should be sortable.
	 *
	 * @return array<string, array{string, bool}>
	 */
	protected function get_sortable_columns(): array {
		return [
			'created_at' => [ 'created_at', true ],
			'action'     => [ 'action', false ],
		];
	}

	/**
	 * Prepares the items array for display, applying pagination and sorting.
	 *
	 * @return void
	 */
	public function prepare_items(): void {
		$per_page     = 50;
		$current_page = $this->get_pagenum();

		// Read and sanitise the sortable column parameters from the request.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- list table sort parameters are read-only UI state, not mutations.
		$orderby_raw = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : 'id';
		$order_raw   = isset( $_GET['order'] ) ? strtoupper( sanitize_key( wp_unslash( $_GET['order'] ) ) ) : 'DESC';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		// Allowlist checked inside Telex_Audit_Log::get_recent() as well; defence-in-depth.
		$orderby = in_array( $orderby_raw, [ 'id', 'action', 'created_at' ], true ) ? $orderby_raw : 'id';
		$order   = 'ASC' === $order_raw ? 'ASC' : 'DESC';

		$total_items = Telex_Audit_Log::count();
		$offset      = ( $current_page - 1 ) * $per_page;

		$this->set_pagination_args(
			[
				'total_items' => $total_items,
				'per_page'    => $per_page,
				'total_pages' => (int) ceil( $total_items / $per_page ),
			]
		);

		$this->items = Telex_Audit_Log::get_recent( $per_page, $offset, $orderby, $order );

		// Prime the WP user cache for all user IDs on this page in one query,
		// so column_user_id() can call get_userdata() without a per-row DB hit.
		$user_ids = array_values(
			array_unique(
				array_filter(
					array_map( 'intval', array_column( $this->items, 'user_id' ) ),
					fn( $id ) => $id > 0
				)
			)
		);
		if ( $user_ids ) {
			cache_users( $user_ids );
		}

		$this->_column_headers = [
			$this->get_columns(),
			[],
			$this->get_sortable_columns(),
			'created_at',
		];
	}

	/**
	 * Renders the date/time column.
	 *
	 * @param array<string, mixed> $item Row data.
	 * @return string
	 */
	public function column_created_at( array $item ): string {
		$ts = strtotime( $item['created_at'] ?? '' );
		if ( ! $ts ) {
			return esc_html__( 'Unknown', 'dispatch' );
		}
		return sprintf(
			'<abbr title="%s">%s</abbr>',
			esc_attr( gmdate( 'Y-m-d H:i:s', $ts ) . ' UTC' ),
			esc_html( get_date_from_gmt( $item['created_at'], 'Y-m-d H:i' ) )
		);
	}

	/**
	 * Renders the action column with a colored badge.
	 *
	 * @param array<string, mixed> $item Row data.
	 * @return string
	 */
	public function column_action( array $item ): string {
		$action = sanitize_key( $item['action'] ?? '' );

		$badge_class = match ( $action ) {
			'connect', 'install' => 'telex-badge telex-badge--success',
			'disconnect', 'remove' => 'telex-badge telex-badge--danger',
			'update'   => 'telex-badge telex-badge--info',
			default    => 'telex-badge',
		};

		return sprintf(
			'<span class="%s">%s</span>',
			esc_attr( $badge_class ),
			esc_html( ucfirst( $action ) )
		);
	}

	/**
	 * Renders the project ID column.
	 *
	 * @param array<string, mixed> $item Row data.
	 * @return string
	 */
	public function column_public_id( array $item ): string {
		$pid = $item['public_id'] ?? '';
		return '' !== $pid
			? '<code>' . esc_html( $pid ) . '</code>'
			: '<span aria-label="' . esc_attr__( 'No project', 'dispatch' ) . '">—</span>';
	}

	/**
	 * Renders the user column.
	 *
	 * @param array<string, mixed> $item Row data.
	 * @return string
	 */
	public function column_user_id( array $item ): string {
		$uid = (int) ( $item['user_id'] ?? 0 );
		if ( $uid <= 0 ) {
			return esc_html__( '(system)', 'dispatch' );
		}
		$user = get_userdata( $uid );
		if ( ! $user ) {
			return sprintf( '<em>%s</em>', esc_html( sprintf( '#%d', $uid ) ) );
		}
		return sprintf(
			'<a href="%s">%s</a>',
			esc_url( get_edit_user_link( $uid ) ),
			esc_html( $user->display_name )
		);
	}

	/**
	 * Default column renderer.
	 *
	 * @param array<string, mixed> $item        Row data.
	 * @param string               $column_name Column identifier.
	 * @return string
	 */
	public function column_default( $item, $column_name ): string {
		return esc_html( (string) ( $item[ $column_name ] ?? '' ) );
	}

	/**
	 * Renders the message shown when there are no audit events.
	 *
	 * @return void
	 */
	public function no_items(): void {
		esc_html_e( 'No audit events recorded yet.', 'dispatch' );
	}
}
