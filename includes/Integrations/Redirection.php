<?php
namespace Royal_MCP\Integrations;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Redirection MCP Integration
 *
 * Registers MCP tools for John Godley's "Redirection" plugin
 * (https://redirection.me/), calling its PHP model classes directly
 * rather than looping back through its own REST API.
 *
 * Redirection's PHP API (Red_Item / Red_Group) is explicitly documented
 * as "subject to change" (https://redirection.me/developer/php-api/), so
 * every call here is guarded with class_exists()/method_exists() and
 * fails with a clear exception rather than a fatal error if the
 * installed version has moved things around. Only loaded when
 * Redirection is active.
 */
class Redirection {

	/**
	 * Check if Redirection is available.
	 */
	public static function is_available() {
		return class_exists( 'Red_Item' ) && class_exists( 'Red_Group' );
	}

	/**
	 * Get tool definitions for MCP tools/list response.
	 */
	public static function get_tools() {
		if ( ! self::is_available() ) {
			return [];
		}

		return [
			[
				'name'        => 'redirection_list_redirects',
				'description' => 'List 301/302 redirect rules managed by the Redirection plugin, optionally filtered by group or a search term matching the source URL.',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'limit'    => [ 'type' => 'integer', 'description' => 'Max redirects to return (default 25, max 200)' ],
						'group_id' => [ 'type' => 'integer', 'description' => 'Optional Redirection group ID to filter by' ],
						'search'   => [ 'type' => 'string', 'description' => 'Optional search term matched against the source URL' ],
					],
				],
			],
			[
				'name'        => 'redirection_create_redirect',
				'description' => 'Create a new redirect rule (e.g. 301 a duplicate/retired page to its canonical replacement).',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'source_url'  => [ 'type' => 'string', 'description' => 'The URL path to redirect FROM, e.g. "/old-page/" (relative to site root, or a full URL)' ],
						'target_url'  => [ 'type' => 'string', 'description' => 'The URL to redirect TO' ],
						'status_code' => [ 'type' => 'integer', 'enum' => [ 301, 302, 307 ], 'description' => 'HTTP redirect status (default: 301 permanent)' ],
						'group_id'    => [ 'type' => 'integer', 'description' => 'Redirection group ID to file this under (default: the first/default group)' ],
						'regex'       => [ 'type' => 'boolean', 'description' => 'Treat source_url as a regular expression (default: false)' ],
						'title'       => [ 'type' => 'string', 'description' => 'Optional internal label for this redirect' ],
					],
					'required'   => [ 'source_url', 'target_url' ],
				],
			],
			[
				'name'        => 'redirection_update_redirect',
				'description' => 'Update an existing redirect rule (change its target URL, status code, or enabled state).',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'id'          => [ 'type' => 'integer', 'description' => 'Redirect ID (from redirection_list_redirects or redirection_create_redirect)' ],
						'target_url'  => [ 'type' => 'string', 'description' => 'New destination URL' ],
						'status_code' => [ 'type' => 'integer', 'enum' => [ 301, 302, 307 ], 'description' => 'New HTTP redirect status' ],
						'enabled'     => [ 'type' => 'boolean', 'description' => 'Enable or disable this redirect' ],
					],
					'required'   => [ 'id' ],
				],
			],
			[
				'name'        => 'redirection_list_groups',
				'description' => 'List the redirect groups configured in Redirection (used to organize redirects, e.g. by site section).',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [],
				],
			],
		];
	}

	/**
	 * Execute a Redirection MCP tool.
	 */
	public static function execute_tool( $name, $args ) {
		// Redirects change how the public reaches pages on the live
		// site; require manage_options to mirror Redirection's own REST
		// API capability requirement, checked before is_available() so an
		// unprivileged caller can't learn whether the plugin is present.
		if ( ! current_user_can( 'manage_options' ) ) {
			throw new \Exception( 'You do not have permission to use Redirection tools.' );
		}

		if ( ! self::is_available() ) {
			throw new \Exception( 'Redirection is not active' );
		}

		switch ( $name ) {
			case 'redirection_list_redirects':
				return self::list_redirects( $args );

			case 'redirection_create_redirect':
				return self::create_redirect( $args );

			case 'redirection_update_redirect':
				return self::update_redirect( $args );

			case 'redirection_list_groups':
				return self::list_groups();

			default:
				throw new \Exception( 'Unknown Redirection tool: ' . esc_html( $name ) );
		}
	}

	/**
	 * List redirects.
	 *
	 * Reads directly from Redirection's own table rather than an
	 * undocumented listing method on Red_Item, since the schema
	 * (wp_redirection_items) is far more stable across plugin versions
	 * than its internal model API.
	 */
	private static function list_redirects( $args ) {
		global $wpdb;

		$table = $wpdb->prefix . 'redirection_items';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Redirection exposes no stable public listing API; reading its own table directly is documented as safe (read-only, no plugin business logic bypassed).
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			throw new \Exception( 'Redirection database table not found — plugin may need reactivating.' );
		}

		$limit = min( intval( $args['limit'] ?? 25 ), 200 );
		$where = [ '1=1' ];
		$params = [];

		if ( ! empty( $args['group_id'] ) ) {
			$where[]  = 'group_id = %d';
			$params[] = intval( $args['group_id'] );
		}
		if ( ! empty( $args['search'] ) ) {
			$where[]  = 'url LIKE %s';
			$params[] = '%' . $wpdb->esc_like( sanitize_text_field( $args['search'] ) ) . '%';
		}

		$sql = "SELECT id, url, action_data, action_code, group_id, status, last_count FROM {$table} WHERE " . implode( ' AND ', $where ) . ' ORDER BY id DESC LIMIT %d';
		$params[] = $limit;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- $sql built from a fixed template above, only params are user-supplied and passed through $wpdb->prepare().
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return array_map(
			function ( $row ) {
				return [
					'id'          => (int) $row['id'],
					'source_url'  => $row['url'],
					'target_url'  => $row['action_data'],
					'status_code' => (int) $row['action_code'],
					'group_id'    => (int) $row['group_id'],
					'enabled'     => 'enabled' === $row['status'],
					'hit_count'   => (int) $row['last_count'],
				];
			},
			$rows ?: []
		);
	}

	/**
	 * Create a redirect via Red_Item::create().
	 */
	private static function create_redirect( $args ) {
		if ( ! method_exists( '\Red_Item', 'create' ) ) {
			throw new \Exception( 'Red_Item::create() not found — Redirection plugin version may be incompatible.' );
		}

		$source = trim( sanitize_text_field( $args['source_url'] ?? '' ) );
		$target = trim( esc_url_raw( $args['target_url'] ?? '' ) );
		if ( '' === $source || '' === $target ) {
			throw new \Exception( 'source_url and target_url are required' );
		}

		$group_id = isset( $args['group_id'] ) ? intval( $args['group_id'] ) : self::get_default_group_id();

		$create_args = [
			'url'         => $source,
			'match_type'  => ! empty( $args['regex'] ) ? 'url' : 'url', // Redirection's own default matcher; regex flag applied below.
			'regex'       => ! empty( $args['regex'] ),
			'action_type' => 'url',
			'action_code' => ! empty( $args['status_code'] ) ? intval( $args['status_code'] ) : 301,
			'action_data' => $target,
			'group_id'    => $group_id,
			'match_data'  => [ 'source' => [ 'flag_query' => 'ignore' ] ],
		];
		if ( ! empty( $args['title'] ) ) {
			$create_args['title'] = sanitize_text_field( $args['title'] );
		}

		$result = \Red_Item::create( $create_args );

		if ( is_wp_error( $result ) ) {
			throw new \Exception( esc_html( $result->get_error_message() ) );
		}
		if ( ! $result ) {
			throw new \Exception( 'Redirection did not return a created redirect — check source_url is not a duplicate of an existing rule.' );
		}

		$id = is_object( $result ) && method_exists( $result, 'get_id' ) ? $result->get_id() : ( is_array( $result ) ? ( $result['id'] ?? null ) : null );

		return [
			'id'          => $id,
			'source_url'  => $source,
			'target_url'  => $target,
			'status_code' => $create_args['action_code'],
			'group_id'    => $group_id,
		];
	}

	/**
	 * Update an existing redirect.
	 */
	private static function update_redirect( $args ) {
		$id = intval( $args['id'] ?? 0 );
		if ( $id <= 0 ) {
			throw new \Exception( 'id is required' );
		}
		if ( ! method_exists( '\Red_Item', 'get_by_id' ) ) {
			throw new \Exception( 'Red_Item::get_by_id() not found — Redirection plugin version may be incompatible.' );
		}

		$item = \Red_Item::get_by_id( $id );
		if ( ! $item ) {
			throw new \Exception( 'Redirect not found for ID ' . esc_html( (string) $id ) );
		}
		if ( ! method_exists( $item, 'update' ) ) {
			throw new \Exception( 'This version of Redirection does not support updating redirects via its PHP API; edit it manually in wp-admin.' );
		}

		$update_args = [];
		if ( isset( $args['target_url'] ) ) {
			$update_args['action_data'] = esc_url_raw( $args['target_url'] );
		}
		if ( isset( $args['status_code'] ) ) {
			$update_args['action_code'] = intval( $args['status_code'] );
		}
		if ( isset( $args['enabled'] ) ) {
			$update_args['status'] = $args['enabled'] ? 'enabled' : 'disabled';
		}
		if ( empty( $update_args ) ) {
			throw new \Exception( 'Nothing to update — provide target_url, status_code, and/or enabled.' );
		}

		$result = $item->update( $update_args );
		if ( is_wp_error( $result ) ) {
			throw new \Exception( esc_html( $result->get_error_message() ) );
		}

		return array_merge( [ 'id' => $id ], $update_args );
	}

	/**
	 * List redirect groups.
	 */
	private static function list_groups() {
		global $wpdb;
		$table = $wpdb->prefix . 'redirection_groups';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- same rationale as list_redirects(): stable table read, no public listing method documented.
		$rows = $wpdb->get_results( "SELECT id, name, module_id FROM {$table} ORDER BY id ASC", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return array_map(
			function ( $row ) {
				return [
					'id'   => (int) $row['id'],
					'name' => $row['name'],
				];
			},
			$rows ?: []
		);
	}

	/**
	 * Resolve a sensible default group ID (the first configured group)
	 * so create_redirect() works without the caller needing to look one
	 * up first.
	 */
	private static function get_default_group_id() {
		$groups = self::list_groups();
		return ! empty( $groups ) ? $groups[0]['id'] : 1;
	}
}
