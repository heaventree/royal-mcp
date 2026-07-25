<?php
namespace Royal_MCP\Integrations;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * SEOPress MCP Integration
 *
 * Registers MCP tools for SEOPress (free + PRO). Only loaded when SEOPress is
 * active. Post/term meta fields are handled by the core wp_get_seo_meta /
 * wp_update_seo_meta auto-detection; this integration exposes the surfaces
 * that live outside postmeta:
 *
 *  - seopress_get_redirections   — list SEOPress redirections (seopress_404 CPT)
 *  - seopress_get_schemas        — list SEOPress PRO structured-data schemas
 *  - seopress_list_rest_routes   — enumerate SEOPress's own registered REST routes
 *  - seopress_call_rest          — invoke a SEOPress REST route internally
 *
 * The last two exist because SEOPress PRO ships a wide REST surface (site
 * audit issues, AI metadata / alt-text generation, alerts) whose route set
 * varies by version and license. Rather than hard-coding wrappers that drift,
 * we dispatch internally via rest_do_request() — no HTTP round-trip, no
 * Application Password needed, and SEOPress's own permission_callbacks still
 * run against the authenticated MCP user.
 */
class SEOPress {

	/**
	 * Check if SEOPress is available.
	 */
	public static function is_available() {
		return defined( 'SEOPRESS_VERSION' ) || function_exists( 'seopress_init' );
	}

	/**
	 * Check if SEOPress PRO is available.
	 */
	public static function is_pro() {
		return defined( 'SEOPRESS_PRO_VERSION' ) || function_exists( 'seopress_pro_init' );
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
				'name'        => 'seopress_get_redirections',
				'description' => 'List SEOPress redirections (301/302/307 rules stored in the seopress_404 post type). Returns id, source URL (post title), target URL, redirect type, enabled flag, and parameter mode for each rule.',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'per_page' => [ 'type' => 'integer', 'description' => 'Rules per page (default 50, max 200)' ],
						'page'     => [ 'type' => 'integer', 'description' => 'Page number, 1-indexed (default 1)' ],
						'search'   => [ 'type' => 'string', 'description' => 'Optional substring filter on the source URL' ],
					],
				],
			],
			[
				'name'        => 'seopress_get_schemas',
				'description' => 'List SEOPress PRO structured-data schemas (seopress_schemas post type). Returns id, title, schema type, and target conditions for each. PRO only — returns an explanatory error on free SEOPress.',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'per_page' => [ 'type' => 'integer', 'description' => 'Schemas per page (default 50, max 200)' ],
						'page'     => [ 'type' => 'integer', 'description' => 'Page number, 1-indexed (default 1)' ],
					],
				],
			],
			[
				'name'        => 'seopress_list_rest_routes',
				'description' => 'Enumerate every REST route SEOPress registers on this site (namespace /seopress), with allowed methods and argument names. Use this to discover what the installed SEOPress version/license actually exposes (site audit, AI metadata generation, alerts, settings) before calling seopress_call_rest.',
				'inputSchema' => [ 'type' => 'object', 'properties' => new \stdClass() ],
			],
			[
				'name'        => 'seopress_call_rest',
				'description' => 'Invoke a SEOPress REST route internally (no HTTP round-trip, no Application Password — SEOPress\'s own permission checks run against the authenticated MCP user). Path must start with /seopress. Discover available routes with seopress_list_rest_routes first. Requires manage_options.',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'path'   => [ 'type' => 'string', 'description' => 'REST route path, e.g. /seopress/v1/posts/22890/title-description-metas' ],
						'method' => [ 'type' => 'string', 'enum' => [ 'GET', 'POST', 'PUT', 'DELETE' ], 'description' => 'HTTP method (default GET)' ],
						'params' => [ 'type' => 'object', 'description' => 'Route parameters / request body as a JSON object' ],
					],
					'required'   => [ 'path' ],
				],
			],
		];
	}

	/**
	 * Execute a seopress_* tool.
	 */
	public static function execute_tool( $name, $args ) {
		if ( ! self::is_available() ) {
			throw new \Exception( 'SEOPress is not active on this site.' );
		}

		switch ( $name ) {
			case 'seopress_get_redirections':
				return self::get_redirections( $args );
			case 'seopress_get_schemas':
				return self::get_schemas( $args );
			case 'seopress_list_rest_routes':
				return self::list_rest_routes();
			case 'seopress_call_rest':
				return self::call_rest( $args );
			default:
				throw new \Exception( 'Unknown SEOPress tool: ' . esc_html( $name ) );
		}
	}

	private static function get_redirections( $args ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			throw new \Exception( 'manage_options capability required.' );
		}
		$per_page = isset( $args['per_page'] ) ? min( 200, max( 1, (int) $args['per_page'] ) ) : 50;
		$page     = isset( $args['page'] ) ? max( 1, (int) $args['page'] ) : 1;
		$search   = isset( $args['search'] ) ? sanitize_text_field( (string) $args['search'] ) : '';

		$query = new \WP_Query( [
			'post_type'      => 'seopress_404',
			'post_status'    => 'any',
			'posts_per_page' => $per_page,
			'paged'          => $page,
			's'              => $search,
			'orderby'        => 'ID',
			'order'          => 'ASC',
			'no_found_rows'  => false,
		] );

		$rules = [];
		foreach ( $query->posts as $p ) {
			$rules[] = [
				'id'      => (int) $p->ID,
				'source'  => (string) $p->post_title,
				'target'  => (string) get_post_meta( $p->ID, '_seopress_redirections_value', true ),
				'type'    => (string) get_post_meta( $p->ID, '_seopress_redirections_type', true ),
				'enabled' => get_post_meta( $p->ID, '_seopress_redirections_enabled', true ) === 'yes',
				'param'   => (string) get_post_meta( $p->ID, '_seopress_redirections_param', true ),
				'status'  => (string) $p->post_status,
			];
		}

		return [
			'total'        => (int) $query->found_posts,
			'page'         => $page,
			'per_page'     => $per_page,
			'redirections' => $rules,
		];
	}

	private static function get_schemas( $args ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			throw new \Exception( 'manage_options capability required.' );
		}
		if ( ! self::is_pro() ) {
			throw new \Exception( 'Structured-data schemas require SEOPress PRO, which is not active on this site.' );
		}
		$per_page = isset( $args['per_page'] ) ? min( 200, max( 1, (int) $args['per_page'] ) ) : 50;
		$page     = isset( $args['page'] ) ? max( 1, (int) $args['page'] ) : 1;

		$query = new \WP_Query( [
			'post_type'      => 'seopress_schemas',
			'post_status'    => 'any',
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'orderby'        => 'ID',
			'order'          => 'ASC',
			'no_found_rows'  => false,
		] );

		$schemas = [];
		foreach ( $query->posts as $p ) {
			$schemas[] = [
				'id'          => (int) $p->ID,
				'title'       => (string) $p->post_title,
				'schema_type' => (string) get_post_meta( $p->ID, '_seopress_pro_rich_snippets_type', true ),
				'status'      => (string) $p->post_status,
			];
		}

		return [
			'total'    => (int) $query->found_posts,
			'page'     => $page,
			'per_page' => $per_page,
			'schemas'  => $schemas,
		];
	}

	private static function list_rest_routes() {
		if ( ! current_user_can( 'manage_options' ) ) {
			throw new \Exception( 'manage_options capability required.' );
		}
		$server = rest_get_server();
		$routes = [];
		foreach ( $server->get_routes() as $route => $handlers ) {
			if ( strpos( $route, '/seopress' ) !== 0 ) {
				continue;
			}
			$methods = [];
			$arg_names = [];
			foreach ( $handlers as $handler ) {
				if ( isset( $handler['methods'] ) ) {
					$m = $handler['methods'];
					$methods = array_merge( $methods, is_array( $m ) ? array_keys( array_filter( $m ) ) : [ (string) $m ] );
				}
				if ( isset( $handler['args'] ) && is_array( $handler['args'] ) ) {
					$arg_names = array_merge( $arg_names, array_keys( $handler['args'] ) );
				}
			}
			$routes[] = [
				'route'   => $route,
				'methods' => array_values( array_unique( $methods ) ),
				'args'    => array_values( array_unique( $arg_names ) ),
			];
		}

		return [
			'count'  => count( $routes ),
			'routes' => $routes,
			'note'   => 'Call any of these with seopress_call_rest. Route availability depends on installed SEOPress version and license.',
		];
	}

	private static function call_rest( $args ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			throw new \Exception( 'manage_options capability required.' );
		}
		$path = isset( $args['path'] ) ? '/' . ltrim( sanitize_text_field( (string) $args['path'] ), '/' ) : '';
		if ( strpos( $path, '/seopress' ) !== 0 ) {
			throw new \Exception( 'Path must start with /seopress — this tool only dispatches to SEOPress routes.' );
		}
		$method = isset( $args['method'] ) ? strtoupper( sanitize_text_field( (string) $args['method'] ) ) : 'GET';
		if ( ! in_array( $method, [ 'GET', 'POST', 'PUT', 'DELETE' ], true ) ) {
			throw new \Exception( 'method must be one of GET, POST, PUT, DELETE.' );
		}

		$request = new \WP_REST_Request( $method, $path );
		if ( isset( $args['params'] ) && is_array( $args['params'] ) ) {
			if ( 'GET' === $method ) {
				foreach ( $args['params'] as $k => $v ) {
					$request->set_param( (string) $k, $v );
				}
			} else {
				$request->set_body_params( $args['params'] );
				foreach ( $args['params'] as $k => $v ) {
					$request->set_param( (string) $k, $v );
				}
			}
		}

		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			$error = $response->as_error();
			return [
				'success' => false,
				'status'  => (int) $response->get_status(),
				'code'    => $error ? $error->get_error_code() : 'unknown',
				'message' => $error ? $error->get_error_message() : 'REST dispatch failed.',
			];
		}

		return [
			'success' => true,
			'status'  => (int) $response->get_status(),
			'data'    => rest_get_server()->response_to_data( $response, false ),
		];
	}
}
