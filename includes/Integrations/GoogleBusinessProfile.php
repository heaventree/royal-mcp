<?php
namespace Royal_MCP\Integrations;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Google Business Profile MCP Integration
 *
 * Registers MCP tools for auditing and managing Google Business Profile
 * listings via Google's own APIs (Account Management, Business Information,
 * Verifications, Performance, Q&A, and the legacy-but-still-active v4 API
 * for reviews/posts/media/service-lists).
 *
 * Unlike the other integrations in this directory, this one talks to an
 * external service rather than a companion WordPress plugin: credentials
 * (OAuth client id/secret + a long-lived refresh token) are entered on the
 * Royal MCP settings page and stored in the royal_mcp_settings option.
 * Short-lived access tokens are fetched from Google on demand and cached in
 * a transient so we don't hit the token endpoint on every tool call.
 */
class GoogleBusinessProfile {

	const TOKEN_URL         = 'https://oauth2.googleapis.com/token';
	const ACCOUNTS_BASE     = 'https://mybusinessaccountmanagement.googleapis.com/v1';
	const BIZINFO_BASE      = 'https://mybusinessbusinessinformation.googleapis.com/v1';
	const VERIFICATIONS_BASE = 'https://mybusinessverifications.googleapis.com/v1';
	const PERFORMANCE_BASE  = 'https://businessprofileperformance.googleapis.com/v1';
	const QANDA_BASE        = 'https://mybusinessqanda.googleapis.com/v1';
	const LEGACY_BASE       = 'https://mybusiness.googleapis.com/v4';
	const TRANSIENT_KEY     = 'royal_mcp_gbp_access_token';

	/**
	 * Whether Google Business Profile credentials are configured.
	 */
	public static function is_available() {
		$settings = self::settings();
		return ! empty( $settings['gbp_client_id'] ) && ! empty( $settings['gbp_client_secret'] ) && ! empty( $settings['gbp_refresh_token'] );
	}

	private static function settings() {
		return get_option( 'royal_mcp_settings', [] );
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
				'name'        => 'gbp_list_accounts',
				'description' => 'List the Google Business Profile accounts (personal or organization) this connection has access to. Usually the first call — you need an account name (accounts/{id}) to list locations.',
				'inputSchema' => [ 'type' => 'object', 'properties' => new \stdClass() ],
			],
			[
				'name'        => 'gbp_list_locations',
				'description' => 'List locations (business listings) under a Google Business Profile account.',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'account'    => [ 'type' => 'string', 'description' => 'Account resource name, e.g. "accounts/123456789"' ],
						'page_size'  => [ 'type' => 'integer', 'description' => 'Max locations per page (default 25, max 100)' ],
						'page_token' => [ 'type' => 'string', 'description' => 'Pagination token from a previous response' ],
					],
					'required'   => [ 'account' ],
				],
			],
			[
				'name'        => 'gbp_get_location',
				'description' => 'Get full details for a single location: name, address, phone, categories, hours, service area, etc.',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'location' => [ 'type' => 'string', 'description' => 'Location resource name, e.g. "locations/987654321"' ],
					],
					'required'   => [ 'location' ],
				],
			],
			[
				'name'        => 'gbp_get_voice_of_merchant',
				'description' => 'Get the Voice of Merchant state for a location — whether it is actually verified and eligible to appear on Google Search/Maps. This should usually be the FIRST check in any audit: an unverified or suspended listing makes every other metric moot.',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'location' => [ 'type' => 'string', 'description' => 'Location resource name, e.g. "locations/987654321"' ],
					],
					'required'   => [ 'location' ],
				],
			],
			[
				'name'        => 'gbp_get_google_updated',
				'description' => 'Get the Google-updated version of a location\'s attributes. Google sometimes edits listing details it thinks are wrong (hours, categories, phone) without merchant action — this surfaces those pending/applied Google-suggested changes so you can catch a listing being silently altered.',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'location' => [ 'type' => 'string', 'description' => 'Location resource name, e.g. "locations/987654321"' ],
					],
					'required'   => [ 'location' ],
				],
			],
			[
				'name'        => 'gbp_list_reviews',
				'description' => 'List reviews for a location, including reviewer, star rating, comment, existing owner reply (if any), and timestamps.',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'account'    => [ 'type' => 'string', 'description' => 'Account resource name, e.g. "accounts/123456789"' ],
						'location'   => [ 'type' => 'string', 'description' => 'Location id or "locations/{id}"' ],
						'page_size'  => [ 'type' => 'integer', 'description' => 'Max reviews per page (default 20, max 50)' ],
						'page_token' => [ 'type' => 'string', 'description' => 'Pagination token from a previous response' ],
					],
					'required'   => [ 'account', 'location' ],
				],
			],
			[
				'name'        => 'gbp_reply_to_review',
				'description' => 'Post or update the owner reply on a review.',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'account'  => [ 'type' => 'string', 'description' => 'Account resource name' ],
						'location' => [ 'type' => 'string', 'description' => 'Location id or "locations/{id}"' ],
						'review'   => [ 'type' => 'string', 'description' => 'Review id' ],
						'comment'  => [ 'type' => 'string', 'description' => 'Reply text' ],
					],
					'required'   => [ 'account', 'location', 'review', 'comment' ],
				],
			],
			[
				'name'        => 'gbp_delete_review_reply',
				'description' => 'Delete the owner reply from a review.',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'account'  => [ 'type' => 'string', 'description' => 'Account resource name' ],
						'location' => [ 'type' => 'string', 'description' => 'Location id or "locations/{id}"' ],
						'review'   => [ 'type' => 'string', 'description' => 'Review id' ],
					],
					'required'   => [ 'account', 'location', 'review' ],
				],
			],
			[
				'name'        => 'gbp_list_questions',
				'description' => 'List the Q&A questions asked on a location listing, including any existing answers. Unanswered questions are a common, easy optimization win to flag in an audit.',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'location'   => [ 'type' => 'string', 'description' => 'Location resource name, e.g. "locations/987654321"' ],
						'page_size'  => [ 'type' => 'integer', 'description' => 'Max questions per page (default 20, max 50)' ],
						'page_token' => [ 'type' => 'string', 'description' => 'Pagination token from a previous response' ],
					],
					'required'   => [ 'location' ],
				],
			],
			[
				'name'        => 'gbp_answer_question',
				'description' => 'Post (or replace) the merchant answer to a Q&A question.',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'question' => [ 'type' => 'string', 'description' => 'Question resource name, e.g. "locations/123/questions/456"' ],
						'answer'   => [ 'type' => 'string', 'description' => 'Answer text' ],
					],
					'required'   => [ 'question', 'answer' ],
				],
			],
			[
				'name'        => 'gbp_list_local_posts',
				'description' => 'List local posts (updates, offers, events) published to a location.',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'account'    => [ 'type' => 'string', 'description' => 'Account resource name' ],
						'location'   => [ 'type' => 'string', 'description' => 'Location id or "locations/{id}"' ],
						'page_size'  => [ 'type' => 'integer', 'description' => 'Max posts per page (default 20, max 100)' ],
						'page_token' => [ 'type' => 'string', 'description' => 'Pagination token from a previous response' ],
					],
					'required'   => [ 'account', 'location' ],
				],
			],
			[
				'name'        => 'gbp_create_local_post',
				'description' => 'Create a new local post (standard update, offer, or event) on a location.',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'account'     => [ 'type' => 'string', 'description' => 'Account resource name' ],
						'location'    => [ 'type' => 'string', 'description' => 'Location id or "locations/{id}"' ],
						'summary'     => [ 'type' => 'string', 'description' => 'Post body text' ],
						'topic_type'  => [ 'type' => 'string', 'enum' => [ 'STANDARD', 'EVENT', 'OFFER' ], 'description' => 'Post type (default STANDARD)' ],
						'action_url'  => [ 'type' => 'string', 'description' => 'Optional call-to-action URL' ],
						'action_type' => [ 'type' => 'string', 'enum' => [ 'BOOK', 'ORDER', 'SHOP', 'LEARN_MORE', 'SIGN_UP', 'CALL' ], 'description' => 'Optional call-to-action type' ],
					],
					'required'   => [ 'account', 'location', 'summary' ],
				],
			],
			[
				'name'        => 'gbp_report_post_insights',
				'description' => 'Get per-post performance metrics (views, clicks) for one or more local posts, so you can tell which posts actually worked.',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'account'    => [ 'type' => 'string', 'description' => 'Account resource name' ],
						'location'   => [ 'type' => 'string', 'description' => 'Location id or "locations/{id}"' ],
						'post_names' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ], 'description' => 'Full local post resource names to report on' ],
					],
					'required'   => [ 'account', 'location', 'post_names' ],
				],
			],
			[
				'name'        => 'gbp_get_daily_metrics',
				'description' => 'Get a daily time series for one performance metric on a location (e.g. BUSINESS_IMPRESSIONS_DESKTOP_MAPS, CALL_CLICKS, WEBSITE_CLICKS) over a date range.',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'location'    => [ 'type' => 'string', 'description' => 'Location resource name, e.g. "locations/987654321"' ],
						'metric'      => [ 'type' => 'string', 'description' => 'Metric name, e.g. "CALL_CLICKS", "WEBSITE_CLICKS", "BUSINESS_IMPRESSIONS_DESKTOP_MAPS"' ],
						'start_date'  => [ 'type' => 'string', 'description' => 'Start date, YYYY-MM-DD' ],
						'end_date'    => [ 'type' => 'string', 'description' => 'End date, YYYY-MM-DD' ],
					],
					'required'   => [ 'location', 'metric', 'start_date', 'end_date' ],
				],
			],
			[
				'name'        => 'gbp_get_service_list',
				'description' => 'Get the legacy v4 service list for a location (categories and structured services offered). Fallback to try if the Business Information v1 serviceItems field is rejected for this account.',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'account'  => [ 'type' => 'string', 'description' => 'Account resource name' ],
						'location' => [ 'type' => 'string', 'description' => 'Location id or "locations/{id}"' ],
					],
					'required'   => [ 'account', 'location' ],
				],
			],
			[
				'name'        => 'gbp_update_service_list',
				'description' => 'Update the legacy v4 service list for a location. Fallback to try if the Business Information v1 serviceItems field is rejected for this account.',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'account'      => [ 'type' => 'string', 'description' => 'Account resource name' ],
						'location'     => [ 'type' => 'string', 'description' => 'Location id or "locations/{id}"' ],
						'service_list' => [ 'type' => 'object', 'description' => 'ServiceList object as documented at developers.google.com/my-business/reference/rest/v4/accounts.locations/updateServiceList' ],
					],
					'required'   => [ 'account', 'location', 'service_list' ],
				],
			],
		];
	}

	/**
	 * Execute a gbp_* tool.
	 */
	public static function execute_tool( $name, $args ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			throw new \Exception( 'manage_options capability required.' );
		}
		if ( ! self::is_available() ) {
			throw new \Exception( 'Google Business Profile is not configured. Add a Client ID, Client Secret, and Refresh Token in Royal MCP settings.' );
		}

		switch ( $name ) {
			case 'gbp_list_accounts':
				return self::list_accounts();
			case 'gbp_list_locations':
				return self::list_locations( $args );
			case 'gbp_get_location':
				return self::get_location( $args );
			case 'gbp_get_voice_of_merchant':
				return self::get_voice_of_merchant( $args );
			case 'gbp_get_google_updated':
				return self::get_google_updated( $args );
			case 'gbp_list_reviews':
				return self::list_reviews( $args );
			case 'gbp_reply_to_review':
				return self::reply_to_review( $args );
			case 'gbp_delete_review_reply':
				return self::delete_review_reply( $args );
			case 'gbp_list_questions':
				return self::list_questions( $args );
			case 'gbp_answer_question':
				return self::answer_question( $args );
			case 'gbp_list_local_posts':
				return self::list_local_posts( $args );
			case 'gbp_create_local_post':
				return self::create_local_post( $args );
			case 'gbp_report_post_insights':
				return self::report_post_insights( $args );
			case 'gbp_get_daily_metrics':
				return self::get_daily_metrics( $args );
			case 'gbp_get_service_list':
				return self::get_service_list( $args );
			case 'gbp_update_service_list':
				return self::update_service_list( $args );
			default:
				throw new \Exception( 'Unknown Google Business Profile tool: ' . esc_html( $name ) );
		}
	}

	// ------------------------------------------------------------------
	// Auth
	// ------------------------------------------------------------------

	private static function get_access_token() {
		$cached = get_transient( self::TRANSIENT_KEY );
		if ( ! empty( $cached ) ) {
			return $cached;
		}

		$settings = self::settings();
		$response = wp_remote_post( self::TOKEN_URL, [
			'timeout' => 15,
			'body'    => [
				'client_id'     => $settings['gbp_client_id'],
				'client_secret' => $settings['gbp_client_secret'],
				'refresh_token' => $settings['gbp_refresh_token'],
				'grant_type'    => 'refresh_token',
			],
		] );

		if ( is_wp_error( $response ) ) {
			throw new \Exception( 'Failed to reach Google token endpoint: ' . $response->get_error_message() );
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 || empty( $body['access_token'] ) ) {
			$detail = $body['error_description'] ?? $body['error'] ?? 'unknown error';
			throw new \Exception( 'Google token refresh failed (' . $code . '): ' . $detail );
		}

		$ttl = isset( $body['expires_in'] ) ? max( 60, (int) $body['expires_in'] - 60 ) : 3000;
		set_transient( self::TRANSIENT_KEY, $body['access_token'], $ttl );

		return $body['access_token'];
	}

	/**
	 * Make an authenticated request against a Google Business Profile API.
	 */
	private static function request( $method, $url, $body = null ) {
		$access_token = self::get_access_token();

		$request_args = [
			'method'  => $method,
			'timeout' => 20,
			'headers' => [
				'Authorization' => 'Bearer ' . $access_token,
				'Content-Type'  => 'application/json',
			],
		];
		if ( null !== $body ) {
			$request_args['body'] = wp_json_encode( $body );
		}

		$response = wp_remote_request( $url, $request_args );

		if ( is_wp_error( $response ) ) {
			throw new \Exception( 'Request to Google failed: ' . $response->get_error_message() );
		}

		$code    = wp_remote_retrieve_response_code( $response );
		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 ) {
			$detail = $decoded['error']['message'] ?? 'unknown error';
			throw new \Exception( 'Google API error (' . $code . '): ' . $detail );
		}

		return is_array( $decoded ) ? $decoded : [];
	}

	private static function normalize_location( $location ) {
		$location = (string) $location;
		return ( strpos( $location, 'locations/' ) === 0 ) ? $location : 'locations/' . $location;
	}

	// ------------------------------------------------------------------
	// Accounts / Locations / Verification
	// ------------------------------------------------------------------

	private static function list_accounts() {
		return self::request( 'GET', self::ACCOUNTS_BASE . '/accounts' );
	}

	private static function list_locations( $args ) {
		if ( empty( $args['account'] ) ) {
			throw new \Exception( 'account is required.' );
		}
		$page_size = isset( $args['page_size'] ) ? min( 100, max( 1, (int) $args['page_size'] ) ) : 25;
		$url = self::BIZINFO_BASE . '/' . ltrim( sanitize_text_field( $args['account'] ), '/' ) . '/locations'
			. '?readMask=name,title,storefrontAddress,phoneNumbers,categories,metadata'
			. '&pageSize=' . $page_size;
		if ( ! empty( $args['page_token'] ) ) {
			$url .= '&pageToken=' . rawurlencode( $args['page_token'] );
		}
		return self::request( 'GET', $url );
	}

	private static function get_location( $args ) {
		if ( empty( $args['location'] ) ) {
			throw new \Exception( 'location is required.' );
		}
		$location = self::normalize_location( $args['location'] );
		$url = self::BIZINFO_BASE . '/' . $location
			. '?readMask=name,title,storefrontAddress,phoneNumbers,categories,regularHours,specialHours,serviceArea,websiteUri,profile,metadata,serviceItems';
		return self::request( 'GET', $url );
	}

	private static function get_voice_of_merchant( $args ) {
		if ( empty( $args['location'] ) ) {
			throw new \Exception( 'location is required.' );
		}
		$location = self::normalize_location( $args['location'] );
		$url = self::VERIFICATIONS_BASE . '/' . $location . '/VoiceOfMerchantState';
		return self::request( 'GET', $url );
	}

	private static function get_google_updated( $args ) {
		if ( empty( $args['location'] ) ) {
			throw new \Exception( 'location is required.' );
		}
		$location = self::normalize_location( $args['location'] );
		$url = self::BIZINFO_BASE . '/' . $location . ':getGoogleUpdated'
			. '?readMask=name,title,storefrontAddress,phoneNumbers,categories,regularHours,websiteUri';
		return self::request( 'GET', $url );
	}

	// ------------------------------------------------------------------
	// Reviews (legacy v4 — still active for reviews/posts/media)
	// ------------------------------------------------------------------

	private static function reviews_base( $args ) {
		if ( empty( $args['account'] ) || empty( $args['location'] ) ) {
			throw new \Exception( 'account and location are required.' );
		}
		$account  = ltrim( sanitize_text_field( $args['account'] ), '/' );
		$location = self::normalize_location( $args['location'] );
		return self::LEGACY_BASE . '/' . $account . '/' . $location;
	}

	private static function list_reviews( $args ) {
		$page_size = isset( $args['page_size'] ) ? min( 50, max( 1, (int) $args['page_size'] ) ) : 20;
		$url = self::reviews_base( $args ) . '/reviews?pageSize=' . $page_size;
		if ( ! empty( $args['page_token'] ) ) {
			$url .= '&pageToken=' . rawurlencode( $args['page_token'] );
		}
		return self::request( 'GET', $url );
	}

	private static function reply_to_review( $args ) {
		if ( empty( $args['review'] ) || ! isset( $args['comment'] ) || '' === $args['comment'] ) {
			throw new \Exception( 'review and comment are required.' );
		}
		$url = self::reviews_base( $args ) . '/reviews/' . rawurlencode( $args['review'] ) . '/reply';
		return self::request( 'PUT', $url, [ 'comment' => (string) $args['comment'] ] );
	}

	private static function delete_review_reply( $args ) {
		if ( empty( $args['review'] ) ) {
			throw new \Exception( 'review is required.' );
		}
		$url = self::reviews_base( $args ) . '/reviews/' . rawurlencode( $args['review'] ) . '/reply';
		self::request( 'DELETE', $url );
		return [ 'success' => true ];
	}

	// ------------------------------------------------------------------
	// Q&A
	// ------------------------------------------------------------------

	private static function list_questions( $args ) {
		if ( empty( $args['location'] ) ) {
			throw new \Exception( 'location is required.' );
		}
		$location  = self::normalize_location( $args['location'] );
		$page_size = isset( $args['page_size'] ) ? min( 50, max( 1, (int) $args['page_size'] ) ) : 20;
		$url = self::QANDA_BASE . '/' . $location . '/questions?pageSize=' . $page_size . '&answersPerQuestion=10';
		if ( ! empty( $args['page_token'] ) ) {
			$url .= '&pageToken=' . rawurlencode( $args['page_token'] );
		}
		return self::request( 'GET', $url );
	}

	private static function answer_question( $args ) {
		if ( empty( $args['question'] ) || empty( $args['answer'] ) ) {
			throw new \Exception( 'question and answer are required.' );
		}
		$question = ltrim( sanitize_text_field( $args['question'] ), '/' );
		$url = self::QANDA_BASE . '/' . $question . '/answers:upsert';
		return self::request( 'POST', $url, [
			'answer' => [ 'text' => (string) $args['answer'] ],
		] );
	}

	// ------------------------------------------------------------------
	// Local posts
	// ------------------------------------------------------------------

	private static function list_local_posts( $args ) {
		$page_size = isset( $args['page_size'] ) ? min( 100, max( 1, (int) $args['page_size'] ) ) : 20;
		$url = self::reviews_base( $args ) . '/localPosts?pageSize=' . $page_size;
		if ( ! empty( $args['page_token'] ) ) {
			$url .= '&pageToken=' . rawurlencode( $args['page_token'] );
		}
		return self::request( 'GET', $url );
	}

	private static function create_local_post( $args ) {
		if ( empty( $args['summary'] ) ) {
			throw new \Exception( 'summary is required.' );
		}
		$post = [
			'languageCode' => 'en',
			'summary'      => (string) $args['summary'],
			'topicType'    => ! empty( $args['topic_type'] ) ? sanitize_text_field( $args['topic_type'] ) : 'STANDARD',
		];
		if ( ! empty( $args['action_url'] ) || ! empty( $args['action_type'] ) ) {
			$post['callToAction'] = array_filter( [
				'actionType' => ! empty( $args['action_type'] ) ? sanitize_text_field( $args['action_type'] ) : null,
				'url'        => ! empty( $args['action_url'] ) ? esc_url_raw( $args['action_url'] ) : null,
			] );
		}
		$url = self::reviews_base( $args ) . '/localPosts';
		return self::request( 'POST', $url, $post );
	}

	private static function report_post_insights( $args ) {
		if ( empty( $args['post_names'] ) || ! is_array( $args['post_names'] ) ) {
			throw new \Exception( 'post_names (array) is required.' );
		}
		$url = self::reviews_base( $args ) . '/localPosts:reportInsights';
		return self::request( 'POST', $url, [
			'localPostNames' => array_map( 'sanitize_text_field', $args['post_names'] ),
			'localPostMetrics' => [ 'LOCAL_POST_VIEWS_SEARCH', 'LOCAL_POST_ACTIONS_CALL_TO_ACTION' ],
		] );
	}

	// ------------------------------------------------------------------
	// Performance
	// ------------------------------------------------------------------

	private static function get_daily_metrics( $args ) {
		if ( empty( $args['location'] ) || empty( $args['metric'] ) || empty( $args['start_date'] ) || empty( $args['end_date'] ) ) {
			throw new \Exception( 'location, metric, start_date, and end_date are required.' );
		}
		$location = self::normalize_location( $args['location'] );

		$start = self::date_parts( $args['start_date'] );
		$end   = self::date_parts( $args['end_date'] );

		$url = self::PERFORMANCE_BASE . '/' . $location . ':getDailyMetricsTimeSeries'
			. '?dailyMetric=' . rawurlencode( sanitize_text_field( $args['metric'] ) )
			. '&dailyRange.start_date.year=' . $start['year'] . '&dailyRange.start_date.month=' . $start['month'] . '&dailyRange.start_date.day=' . $start['day']
			. '&dailyRange.end_date.year=' . $end['year'] . '&dailyRange.end_date.month=' . $end['month'] . '&dailyRange.end_date.day=' . $end['day'];

		return self::request( 'GET', $url );
	}

	private static function date_parts( $date ) {
		$dt = \DateTime::createFromFormat( 'Y-m-d', (string) $date );
		if ( ! $dt ) {
			throw new \Exception( 'Dates must be in YYYY-MM-DD format.' );
		}
		return [ 'year' => (int) $dt->format( 'Y' ), 'month' => (int) $dt->format( 'n' ), 'day' => (int) $dt->format( 'j' ) ];
	}

	// ------------------------------------------------------------------
	// Service list (legacy v4 fallback)
	// ------------------------------------------------------------------

	private static function get_service_list( $args ) {
		$url = self::reviews_base( $args ) . '/serviceList';
		return self::request( 'GET', $url );
	}

	private static function update_service_list( $args ) {
		if ( empty( $args['service_list'] ) || ! is_array( $args['service_list'] ) ) {
			throw new \Exception( 'service_list (object) is required.' );
		}
		$url = self::reviews_base( $args ) . '/serviceList?updateMask=servicesList,statement';
		return self::request( 'PATCH', $url, $args['service_list'] );
	}
}
