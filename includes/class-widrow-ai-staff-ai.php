<?php

/**
 * Class Widrow_AI_Staff_AI
 *
 * Staff AI REST routes for Content AI, and the console export's service worker and manifest.
 *
 * @package widrow-ai
 * @since 1.1.0
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
	exit;
}

/**
 * The Content AI routes (/staff-ai/content/*) and the console export's helpers.
 */
final class Widrow_AI_Staff_AI
{
	/**
	 * "Create with reference sources" acceptance bounds (Engine 2.5). DEF
	 * re-validates authoritatively; these refuse oversize PAYLOADS (decoded
	 * bytes, pasted text) with a clear 400. The per-item COUNT caps that sat
	 * here were deleted in 5.7.11 — counts ration a management user's own
	 * references, and DEF's budget owns that decision.
	 */
	const CREATE_MAX_REFERENCE_FILE_BYTES = 10485760; // 10MB total decoded.
	const CREATE_MAX_REFERENCE_TEXT       = 20000;
	const CREATE_ALLOWED_FILE_EXT         = array( 'pdf', 'docx', 'txt', 'csv', 'xlsx' );

	/**
	 * The Creator's name when the tenant has not given her another one. DEF's
	 * own default for the content_creator employee — the Content Drafts page
	 * renders it before any list response has arrived.
	 */
	const CREATOR_DEFAULT_NAME = 'Carol';

	/**
	 * Longest creator name the page will show. DEF already bounds it; this is
	 * the BFF's own floor so the title can never grow without limit.
	 */
	const CREATOR_NAME_MAX = 100;

	/**
	 * Initialize the Staff AI REST routes.
	 */
	public static function init(): void
	{
		add_action('rest_api_init', array(__CLASS__, 'register_rest_routes'));
	}

	/**
	 * Register REST API routes for Staff AI adapter.
	 *
	 * @since 1.1.0
	 */
	public static function register_rest_routes(): void
	{
		// Content Agent review queue (PR-6): list pending staged drafts, apply, dismiss.
		register_rest_route(
			WIDROW_AI_API_NAME_SPACE,
			'/staff-ai/content/drafts',
			array(
				'methods'             => 'GET',
				'permission_callback' => array(__CLASS__, 'rest_permission_check'),
				'callback'            => array(__CLASS__, 'rest_list_content_drafts'),
			)
		);
		// Content Agent "Create New" (Engine 2): on-demand create request. The agent
		// generates a draft asynchronously; it surfaces in the review queue above.
		register_rest_route(
			WIDROW_AI_API_NAME_SPACE,
			'/staff-ai/content/create',
			array(
				'methods'             => 'POST',
				'permission_callback' => array(__CLASS__, 'rest_permission_check'),
				'callback'            => array(__CLASS__, 'rest_content_create'),
			)
		);
		// Last audit-run timestamp for the Content Drafts freshness line (read-only).
		register_rest_route(
			WIDROW_AI_API_NAME_SPACE,
			'/staff-ai/content/last-run',
			array(
				'methods'             => 'GET',
				'permission_callback' => array(__CLASS__, 'rest_permission_check'),
				'callback'            => array(__CLASS__, 'rest_content_last_run'),
			)
		);
		// Current-state coverage breakdown (per content type) for the status strip.
		register_rest_route(
			WIDROW_AI_API_NAME_SPACE,
			'/staff-ai/content/summary',
			array(
				'methods'             => 'GET',
				'permission_callback' => array(__CLASS__, 'rest_permission_check'),
				'callback'            => array(__CLASS__, 'rest_content_summary'),
			)
		);
		// Items skipped for lack of a focus keyphrase (the human must set one).
		register_rest_route(
			WIDROW_AI_API_NAME_SPACE,
			'/staff-ai/content/needs-keyphrase',
			array(
				'methods'             => 'GET',
				'permission_callback' => array(__CLASS__, 'rest_permission_check'),
				'callback'            => array(__CLASS__, 'rest_list_needs_keyphrase'),
			)
		);
		register_rest_route(
			WIDROW_AI_API_NAME_SPACE,
			'/staff-ai/content/drafts/(?P<id>[a-zA-Z0-9_-]+)/apply',
			array(
				'methods'             => 'POST',
				'permission_callback' => array(__CLASS__, 'rest_permission_check'),
				'callback'            => array(__CLASS__, 'rest_apply_content_draft'),
			)
		);
		register_rest_route(
			WIDROW_AI_API_NAME_SPACE,
			'/staff-ai/content/drafts/(?P<id>[a-zA-Z0-9_-]+)/dismiss',
			array(
				'methods'             => 'POST',
				'permission_callback' => array(__CLASS__, 'rest_permission_check'),
				'callback'            => array(__CLASS__, 'rest_dismiss_content_draft'),
			)
		);

		// Content Agent DEF #522 — Optimize tab: item-level dismiss + dismissed list + restore.
		// GET /list?bucket=<bucket> — item list by state bucket (e.g. dismissed).
		register_rest_route(
			WIDROW_AI_API_NAME_SPACE,
			'/staff-ai/content/list',
			array(
				'methods'             => 'GET',
				'permission_callback' => array( __CLASS__, 'rest_permission_check' ),
				'callback'            => array( __CLASS__, 'rest_list_content_items' ),
			)
		);
		// POST /items/{item_id}/dismiss — lightweight dismiss (item stays in knowledge).
		register_rest_route(
			WIDROW_AI_API_NAME_SPACE,
			'/staff-ai/content/items/(?P<item_id>\d+)/dismiss',
			array(
				'methods'             => 'POST',
				'permission_callback' => array( __CLASS__, 'rest_permission_check' ),
				'callback'            => array( __CLASS__, 'rest_dismiss_content_item' ),
			)
		);
		// POST /items/{item_id}/restore — restore a dismissed item back to its prior bucket.
		register_rest_route(
			WIDROW_AI_API_NAME_SPACE,
			'/staff-ai/content/items/(?P<item_id>\d+)/restore',
			array(
				'methods'             => 'POST',
				'permission_callback' => array( __CLASS__, 'rest_permission_check' ),
				'callback'            => array( __CLASS__, 'rest_restore_content_item' ),
			)
		);

		// Content Agent Engine 2.5 — Clusters curation (targets + keyphrase queues).
		// Thin proxies to DEF /api/staff-ai/content/* with field-faithful bodies;
		// gated by widrow_staff_access (rest_permission_check) like the review queue.
		register_rest_route(
			WIDROW_AI_API_NAME_SPACE,
			'/staff-ai/content/targets',
			array(
				array(
					'methods'             => 'GET',
					'permission_callback' => array(__CLASS__, 'rest_permission_check'),
					'callback'            => array(__CLASS__, 'rest_list_content_targets'),
				),
				array(
					'methods'             => 'POST',
					'permission_callback' => array(__CLASS__, 'rest_permission_check'),
					'callback'            => array(__CLASS__, 'rest_create_content_target'),
				),
			)
		);
		register_rest_route(
			WIDROW_AI_API_NAME_SPACE,
			'/staff-ai/content/targets/(?P<id>[a-zA-Z0-9_-]+)',
			array(
				array(
					'methods'             => 'PATCH',
					'permission_callback' => array(__CLASS__, 'rest_permission_check'),
					'callback'            => array(__CLASS__, 'rest_update_content_target'),
				),
				array(
					'methods'             => 'DELETE',
					'permission_callback' => array(__CLASS__, 'rest_permission_check'),
					'callback'            => array(__CLASS__, 'rest_delete_content_target'),
				),
			)
		);
		register_rest_route(
			WIDROW_AI_API_NAME_SPACE,
			'/staff-ai/content/targets/(?P<id>[a-zA-Z0-9_-]+)/keyphrases',
			array(
				array(
					'methods'             => 'GET',
					'permission_callback' => array(__CLASS__, 'rest_permission_check'),
					'callback'            => array(__CLASS__, 'rest_list_target_keyphrases'),
				),
				array(
					'methods'             => 'POST',
					'permission_callback' => array(__CLASS__, 'rest_permission_check'),
					'callback'            => array(__CLASS__, 'rest_add_target_keyphrase'),
				),
			)
		);
		register_rest_route(
			WIDROW_AI_API_NAME_SPACE,
			'/staff-ai/content/targets/(?P<id>[a-zA-Z0-9_-]+)/derive',
			array(
				'methods'             => 'POST',
				'permission_callback' => array(__CLASS__, 'rest_permission_check'),
				'callback'            => array(__CLASS__, 'rest_derive_content_target'),
			)
		);
		register_rest_route(
			WIDROW_AI_API_NAME_SPACE,
			'/staff-ai/content/keyphrases/(?P<id>[a-zA-Z0-9_-]+)',
			array(
				'methods'             => 'PATCH',
				'permission_callback' => array(__CLASS__, 'rest_permission_check'),
				'callback'            => array(__CLASS__, 'rest_update_keyphrase'),
			)
		);
		register_rest_route(
			WIDROW_AI_API_NAME_SPACE,
			'/staff-ai/content/keyphrases/(?P<id>[a-zA-Z0-9_-]+)/approve',
			array(
				'methods'             => 'POST',
				'permission_callback' => array(__CLASS__, 'rest_permission_check'),
				'callback'            => array(__CLASS__, 'rest_approve_keyphrase'),
			)
		);
		register_rest_route(
			WIDROW_AI_API_NAME_SPACE,
			'/staff-ai/content/keyphrases/(?P<id>[a-zA-Z0-9_-]+)/dismiss',
			array(
				'methods'             => 'POST',
				'permission_callback' => array(__CLASS__, 'rest_permission_check'),
				'callback'            => array(__CLASS__, 'rest_dismiss_keyphrase'),
			)
		);
		register_rest_route(
			WIDROW_AI_API_NAME_SPACE,
			'/staff-ai/content/targets/(?P<id>[a-zA-Z0-9_-]+)/keyphrases/dismiss-remaining',
			array(
				'methods'             => 'POST',
				'permission_callback' => array(__CLASS__, 'rest_permission_check'),
				'callback'            => array(__CLASS__, 'rest_dismiss_remaining_keyphrases'),
			)
		);
		// Local WP item search backing the Clusters target picker — no DEF call.
		register_rest_route(
			WIDROW_AI_API_NAME_SPACE,
			'/staff-ai/content/target-search',
			array(
				'methods'             => 'GET',
				'permission_callback' => array(__CLASS__, 'rest_permission_check'),
				'callback'            => array(__CLASS__, 'rest_target_search'),
			)
		);

	}

	/**
	 * REST API permission check for Staff AI endpoints.
	 *
	 * @return bool|\WP_Error True if access granted, WP_Error otherwise.
	 * @since 1.1.0
	 */
	public static function rest_permission_check()
	{
		// Authentication gate.
		if (! is_user_logged_in()) {
			return new \WP_Error(
				'rest_not_logged_in',
				__('Authentication required.', 'widrow-ai'),
				array('status' => 401)
			);
		}

		// Capability gate.
		if (! self::user_has_staff_ai_access()) {
			return self::access_denied_error();
		}

		return true;
	}

	/**
	 * Get the backend API base URL.
	 *
	 * @return string|null API base URL or null if not configured.
	 * @since 1.1.0
	 */
	private static function get_api_base_url(): ?string
	{
		$url = \Widrow_AI::get_def_api_url_internal();
		return ! empty($url) ? $url : null;
	}

	/**
	 * Make a request to the backend API.
	 *
	 * @param string $method HTTP method (GET, POST).
	 * @param string $endpoint API endpoint path.
	 * @param array  $body Request body for POST requests.
	 * @return array|\WP_Error Response data or WP_Error.
	 * @since 1.1.0
	 */
	/**
	 * Flatten a DEF error `detail` to a human-readable string.
	 *
	 * DEF's refusals are not always strings: validation refusals carry a
	 * dict ({"error": "validation_failed", "message": "..."}) and FastAPI
	 * 422s carry a list of {loc, msg, type} dicts. Passing either into
	 * sprintf('%s') prints "Array" — the honest refusal became illegible
	 * (found by #277's security leg against the live producer, on the exact
	 * rider that existed to prevent it). Nothing here is a leak: the detail
	 * is already the server's user-destined message.
	 *
	 * @param mixed $detail Raw `detail` from the DEF error body.
	 * @return string Human-readable detail, or '' when there is none.
	 */
	private static function stringify_backend_detail($detail): string
	{
		if (is_string($detail)) {
			return $detail;
		}
		if (is_array($detail)) {
			// Dict shape: the server's message IS the user-facing refusal.
			if (isset($detail['message']) && is_string($detail['message'])) {
				return $detail['message'];
			}
			// Pydantic 422 list: flatten each entry's msg.
			$msgs = array();
			foreach ($detail as $entry) {
				if (is_array($entry) && isset($entry['msg']) && is_string($entry['msg'])) {
					$msgs[] = $entry['msg'];
				}
			}
			if (! empty($msgs)) {
				return implode('; ', $msgs);
			}
			// Unknown structure: JSON beats "Array".
			return (string) wp_json_encode($detail);
		}
		return '';
	}

	private static function backend_request(string $method, string $endpoint, array $body = array())
	{
		$base_url = self::get_api_base_url();
		if (! $base_url) {
			return new \WP_Error(
				'staff_ai_not_configured',
				__('Staff AI backend URL is not configured. Go to Widrow AI → Settings to set the Staff AI API URL.', 'widrow-ai'),
				array('status' => 503)
			);
		}

		$url = $base_url . $endpoint;

		// BFF proxy auth: API key + user ID + capabilities (no JWT).
		$user = wp_get_current_user();
		if (! $user || 0 === $user->ID) {
			return new \WP_Error(
				'staff_ai_not_authenticated',
				__('User not authenticated.', 'widrow-ai'),
				array('status' => 401)
			);
		}

		$api_key = \Widrow_AI_Encryption::get_secret( 'widrow_ai_api_key' );
		if ( empty( $api_key ) ) {
			return new \WP_Error(
				'staff_ai_not_configured',
				__( 'API key not configured. Go to Widrow AI → Settings to set up the connection.', 'widrow-ai' ),
				array( 'status' => 503 )
			);
		}

		$capabilities = \Widrow_AI_Tools::get_user_def_capabilities( $user );

		$headers = array(
			'X-DEF-API-Key'            => $api_key,
			'X-DEF-User'               => (string) $user->ID,
			'X-DEF-User-Capabilities'  => implode( ',', $capabilities ),
			'Content-Type'             => 'application/json',
			'Accept'                   => 'application/json',
		);

		// Identity headers — let DEF build the "## Current authenticated user"
		// prompt section. URL-encoded for Unicode safety; DEF decodes via
		// urllib.parse.unquote(). See DEF auth.py verify_internal_request().
		if ( ! empty( $user->display_name ) ) {
			$headers['X-DEF-User-Display-Name'] = rawurlencode( $user->display_name );
		}
		if ( ! empty( $user->user_email ) ) {
			$headers['X-DEF-User-Email'] = rawurlencode( $user->user_email );
		}
		if ( ! empty( $user->roles ) && is_array( $user->roles ) ) {
			$roles = array_filter( $user->roles, 'is_string' );
			if ( ! empty( $roles ) ) {
				$headers['X-DEF-User-Roles'] = implode( ',', $roles );
			}
		}
		$site_name = get_bloginfo( 'name' );
		if ( ! empty( $site_name ) ) {
			$headers['X-DEF-Site-Name'] = rawurlencode( $site_name );
		}

		$args = array(
			'timeout'     => 60,
			'httpversion' => '1.1',
			'headers'     => $headers,
		);

		if ('GET' === $method) {
			$response = wp_remote_get($url, $args);
		} else {
			// POST / PATCH / DELETE — wp_remote_post() only speaks POST, so all
			// write verbs go through wp_remote_request() with an explicit method.
			$args['method'] = $method;
			$args['body']   = wp_json_encode($body);
			$response       = wp_remote_request($url, $args);
		}

		if (is_wp_error($response)) {
			return new \WP_Error(
				'staff_ai_request_failed',
				__('Failed to connect to Staff AI backend.', 'widrow-ai'),
				array('status' => 502)
			);
		}

		$status = wp_remote_retrieve_response_code($response);
		$body   = wp_remote_retrieve_body($response);
		$data   = json_decode($body, true);

		// Handle unexpected status codes (0 = network error, empty = no response).
		if (empty($status) || 0 === $status) {
			return new \WP_Error(
				'staff_ai_network_error',
				sprintf(
					/* translators: 1: URL being called */
					__('Network error: Could not connect to backend at %1$s. Check if the URL is correct and the server is reachable.', 'widrow-ai'),
					$url
				),
				array('status' => 502)
			);
		}

		// Map backend errors to clean UI-safe errors.
		if ($status >= 400) {
			$backend_detail = self::stringify_backend_detail(isset($data['detail']) ? $data['detail'] : '');

			// Handle different error status codes - each branch MUST set both $error_code and $error_message.
			$backend_error = isset($data['detail']['error']) && is_string($data['detail']['error']) ? $data['detail']['error'] : '';
			if (403 === $status && 'speech_disabled' === $backend_error) {
				// Not an auth failure: the tenant switched this employee's voice
				// off (Employees page). DEF's own sentence says so (7.7.0).
				$error_code    = 'speech_disabled';
				$error_message = $backend_detail;
			} elseif (401 === $status || 403 === $status) {
				$error_code    = 'staff_ai_auth_failed';
				$error_message = sprintf(
					/* translators: 1: HTTP status code, 2: backend error detail */
					__('Backend auth failed (HTTP %1$d). The backend may need JWKS configuration. Detail: %2$s', 'widrow-ai'),
					$status,
					$backend_detail ? $backend_detail : 'none'
				);
			} elseif (404 === $status) {
				$error_code    = 'staff_ai_not_found';
				$error_message = sprintf(
					/* translators: 1: API endpoint path, 2: full URL */
					__('Backend endpoint not found (HTTP 404): %1$s. Full URL: %2$s - Please verify the backend API supports this endpoint.', 'widrow-ai'),
					$endpoint,
					$url
				);
			} elseif ($status >= 500) {
				$error_code    = 'staff_ai_service_error';
				$error_message = sprintf(
					/* translators: 1: HTTP status code */
					__('Backend service error (HTTP %1$d). The service may be temporarily unavailable.', 'widrow-ai'),
					$status
				);
			} else {
				// Any other 4xx error (400, 405, 422, etc.). Post ten-bounds
				// deletion this is the ROUTINE refusal path (over-size upload,
				// over-budget references): the server's own message IS the
				// user-facing refusal, and the internal DEF URL must not ride
				// along with it — the browser never sees that URL anywhere
				// else, and every routine refusal was about to print it. The
				// URL stays in the WP_DEBUG log line below for diagnostics.
				$error_code    = 'staff_ai_http_' . $status;
				$error_message = sprintf(
					/* translators: 1: HTTP status code, 2: backend error detail */
					__('The assistant service declined this request (HTTP %1$d): %2$s', 'widrow-ai'),
					$status,
					$backend_detail ? $backend_detail : __('Unknown error', 'widrow-ai')
				);
			}

			// Log detailed error in debug mode for troubleshooting.
			if (defined('WP_DEBUG') && WP_DEBUG) {
				$debug_info = sprintf(
					'Staff AI backend error: status=%d, endpoint=%s, url=%s, detail=%s',
					$status,
					$endpoint,
					$url,
					$backend_detail ? $backend_detail : 'none'
				);
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log($debug_info);
			}

			return new \WP_Error(
				$error_code,
				$error_message,
				array('status' => $status)
			);
		}

		return $data;
	}

	/**
	 * REST handler: list pending Content Agent drafts (PR-6 review queue).
	 *
	 * Proxies to DEF GET /api/staff-ai/content/drafts and passes the draft fields
	 * through unchanged (proposed/source carry the diff the UI renders).
	 *
	 * @return \WP_REST_Response|\WP_Error Response.
	 */
	/**
	 * True when $url is a syntactically valid http(s) URL. Used to validate
	 * reference-source URLs WITHOUT reshaping them — esc_url_raw would re-encode
	 * the string, but these are opaque payload data for DEF, so we only check the
	 * scheme/host shape and pass the original through.
	 *
	 * @param string $url Candidate URL.
	 * @return bool Whether it is an http(s) URL.
	 */
	private static function is_http_url( string $url ): bool
	{
		$scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
		return ( 'http' === $scheme || 'https' === $scheme )
			&& '' !== (string) wp_parse_url( $url, PHP_URL_HOST );
	}

	/**
	 * Validate a client-supplied reference_sources object (Engine 2.5).
	 *
	 * Shape mirrors the DEF contract: { urls: string[≤5], text: string[≤20k],
	 * files: [{filename, content_b64}][≤2, ≤10MB decoded total; pdf/docx/txt/csv/
	 * xlsx] }. Everything optional. Returns the CLEANED object passed through
	 * unchanged (no esc_url_raw / sanitize that could corrupt payload data —
	 * length/type/scheme checks only), an empty array when no usable source is
	 * present, or a WP_Error naming the first cap/shape violation. DEF owns the
	 * authoritative validation; this is a BFF sanity gate with clear 400s.
	 *
	 * @param mixed $raw Raw reference_sources from the request body.
	 * @return array|\WP_Error Cleaned reference_sources (possibly empty) or error.
	 */
	private static function validate_reference_sources( $raw )
	{
		if ( null === $raw || ( is_array( $raw ) && empty( $raw ) ) ) {
			return array();
		}
		if ( ! is_array( $raw ) ) {
			return new \WP_Error(
				'invalid_reference_sources',
				__( 'reference_sources must be an object.', 'widrow-ai' ),
				array( 'status' => 400 )
			);
		}

		$err   = static function ( $message ) {
			return new \WP_Error( 'invalid_reference_sources', $message, array( 'status' => 400 ) );
		};
		$clean = array();

		// urls: ≤5 http(s), passed through unchanged.
		if ( isset( $raw['urls'] ) ) {
			if ( ! is_array( $raw['urls'] ) ) {
				return $err( __( 'reference_sources.urls must be a list of URLs.', 'widrow-ai' ) );
			}
			$urls = array();
			foreach ( $raw['urls'] as $u ) {
				$u = is_string( $u ) ? trim( $u ) : '';
				if ( '' === $u ) {
					continue;
				}
				if ( ! self::is_http_url( $u ) ) {
					return $err( __( 'Each reference URL must be a valid http(s) URL.', 'widrow-ai' ) );
				}
				$urls[] = $u;
			}
			if ( ! empty( $urls ) ) {
				$clean['urls'] = $urls;
			}
		}

		// text: ≤20,000 chars, passed through verbatim (sanitizing would corrupt
		// source material — this is payload data for DEF, not display text).
		if ( isset( $raw['text'] ) ) {
			if ( ! is_string( $raw['text'] ) ) {
				return $err( __( 'reference_sources.text must be a string.', 'widrow-ai' ) );
			}
			if ( mb_strlen( $raw['text'] ) > self::CREATE_MAX_REFERENCE_TEXT ) {
				return $err( __( 'Source text is limited to 20,000 characters.', 'widrow-ai' ) );
			}
			if ( '' !== trim( $raw['text'] ) ) {
				$clean['text'] = $raw['text'];
			}
		}

		// files: ≤2 items, allowed types, ≤10MB total decoded; passed through unchanged.
		if ( isset( $raw['files'] ) ) {
			if ( ! is_array( $raw['files'] ) ) {
				return $err( __( 'reference_sources.files must be a list.', 'widrow-ai' ) );
			}
			$files = array();
			$bytes = 0;
			foreach ( $raw['files'] as $f ) {
				if ( ! is_array( $f ) || ! isset( $f['filename'], $f['content_b64'] )
					|| ! is_string( $f['filename'] ) || ! is_string( $f['content_b64'] ) ) {
					return $err( __( 'Each reference file needs a filename and base64 content.', 'widrow-ai' ) );
				}
				$ext = strtolower( pathinfo( $f['filename'], PATHINFO_EXTENSION ) );
				if ( ! in_array( $ext, self::CREATE_ALLOWED_FILE_EXT, true ) ) {
					return $err( __( 'Reference files must be PDF, DOCX, TXT, CSV or XLSX.', 'widrow-ai' ) );
				}
				$decoded = base64_decode( $f['content_b64'], true );
				if ( false === $decoded ) {
					return $err( __( 'A reference file is not valid base64.', 'widrow-ai' ) );
				}
				$bytes  += strlen( $decoded );
				$files[] = array( 'filename' => $f['filename'], 'content_b64' => $f['content_b64'] );
			}
			if ( $bytes > self::CREATE_MAX_REFERENCE_FILE_BYTES ) {
				return $err( __( 'Reference files exceed the 10MB total limit.', 'widrow-ai' ) );
			}
			if ( ! empty( $files ) ) {
				$clean['files'] = $files;
			}
		}

		return $clean;
	}

	/**
	 * REST handler: on-demand "Create New" request (Content Agent Engine 2).
	 *
	 * Proxies DEF POST /api/staff-ai/content/create with the user's keyphrase. DEF
	 * generates a fully-optimized draft asynchronously; it then appears in the
	 * Content Drafts review queue (kind = 'create') for approval. No synchronous
	 * wait — this just acknowledges the request.
	 *
	 * @return \WP_REST_Response|\WP_Error Response.
	 */
	public static function rest_content_create( \WP_REST_Request $request )
	{
		$params    = $request->get_json_params();
		$params    = is_array( $params ) ? $params : array();
		$keyphrase = ( isset( $params['keyphrase'] ) && is_string( $params['keyphrase'] ) )
			? trim( $params['keyphrase'] )
			: '';

		// Reference sources (Engine 2.5 "Create with sources") — optional. Validated
		// for shape/caps here and passed through UNCHANGED (no lossy reshape); DEF
		// owns authoritative validation. When ≥1 source is supplied the keyphrase is
		// optional (DEF derives it, the human reviews it on the draft card).
		$reference_sources = self::validate_reference_sources(
			isset( $params['reference_sources'] ) ? $params['reference_sources'] : null
		);
		if ( is_wp_error( $reference_sources ) ) {
			return $reference_sources;
		}
		$has_sources = ! empty( $reference_sources );

		if ( '' === $keyphrase && ! $has_sources ) {
			return new \WP_Error(
				'invalid_keyphrase',
				__( 'Provide a keyphrase or at least one reference source to generate a post.', 'widrow-ai' ),
				array( 'status' => 400 )
			);
		}

		// Optional writer notes (Engine 2.5) — forwarded verbatim to DEF; the
		// writer consumes them when retrieval-grounded writing lands (PR 2.5-3).
		$notes = ( isset( $params['notes'] ) && is_string( $params['notes'] ) )
			? trim( sanitize_textarea_field( $params['notes'] ) )
			: '';
		if ( mb_strlen( $notes ) > 2000 ) {
			return new \WP_Error(
				'invalid_notes',
				__( 'Notes are limited to 2000 characters.', 'widrow-ai' ),
				array( 'status' => 400 )
			);
		}

		$body = array();
		if ( '' !== $keyphrase ) {
			$body['keyphrase'] = $keyphrase;
		}
		if ( '' !== $notes ) {
			$body['notes'] = $notes;
		}
		if ( $has_sources ) {
			$body['reference_sources'] = $reference_sources;
		}

		$result = self::backend_request( 'POST', '/api/staff-ai/content/create', $body );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$payload = array( 'success' => true );
		if ( is_array( $result ) ) {
			$payload = array_merge( $payload, $result );
		}
		return new \WP_REST_Response( $payload, 200 );
	}

	/**
	 * The tenant's name for the Creator, off a content list response.
	 *
	 * DEF puts `creator_name` on both list payloads (the stored name, else its
	 * own default). It is tenant DATA, not markup, and the page renders it as
	 * text — so this only has to make it printable: control characters (a
	 * newline that would break the title out of its line included) go, it is
	 * capped, and anything unusable falls back to the default rather than
	 * leaving the page nameless.
	 *
	 * @param mixed $payload Decoded backend response.
	 * @return string Display name, never empty.
	 */
	public static function creator_name_from( $payload ): string
	{
		$raw = ( is_array( $payload ) && isset( $payload['creator_name'] ) && is_string( $payload['creator_name'] ) )
			? $payload['creator_name']
			: '';
		// Byte class, no /u: these bytes never occur inside a UTF-8 sequence, so
		// this cannot cut one in half and cannot fail on invalid input.
		$name = trim( (string) preg_replace( '/[\x00-\x1F\x7F]+/', ' ', $raw ) );
		if ( '' === $name ) {
			return self::CREATOR_DEFAULT_NAME;
		}
		return function_exists( 'mb_substr' )
			? (string) mb_substr( $name, 0, self::CREATOR_NAME_MAX )
			: substr( $name, 0, self::CREATOR_NAME_MAX );
	}

	/**
	 * Remember the name DEF last gave the Creator (8.2.8).
	 *
	 * The page draws before any list response lands, so without this it renders
	 * the platform default and the JS swaps it a moment later — on every single
	 * visit, for every tenant who has renamed her. Stored, the page opens
	 * already showing her name and only a rename repaints, once.
	 *
	 * Written only when it has changed: a list runs on every page load and a
	 * rename is rare. Not autoloaded — one admin page reads it.
	 *
	 * @param string $name A name already through creator_name_from().
	 */
	public static function remember_creator_name( string $name ): void
	{
		if ( get_option( 'widrow_ai_creator_name', '' ) !== $name ) {
			update_option( 'widrow_ai_creator_name', $name, false );
		}
	}

	public static function rest_list_content_drafts( \WP_REST_Request $request )
	{
		$result = self::backend_request( 'GET', '/api/staff-ai/content/drafts' );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$drafts = ( isset( $result['drafts'] ) && is_array( $result['drafts'] ) ) ? $result['drafts'] : array();

		// Enrich each draft with the local product title + links. The draft's
		// item_id IS the WordPress product post ID, so we resolve title/links here
		// (in WP) rather than round-tripping the backend — always accurate, no extra
		// call. get_edit_post_link returns '' when the current user can't edit, so
		// the title simply falls back to plain text client-side.
		foreach ( $drafts as &$draft ) {
			$item_id = isset( $draft['item_id'] ) ? (int) $draft['item_id'] : 0;
			if ( $item_id > 0 && get_post_status( $item_id ) ) {
				$title             = get_the_title( $item_id );
				$draft['title']    = is_string( $title ) ? $title : '';
				$edit_url          = get_edit_post_link( $item_id, 'raw' );
				$draft['edit_url'] = $edit_url ? $edit_url : '';
				$view_url          = get_permalink( $item_id );
				$draft['view_url'] = $view_url ? $view_url : '';
			}
		}
		unset( $draft );

		// The page titles itself with her name, so it travels with the list —
		// and is remembered, so the next visit renders it at first paint.
		$creator_name = self::creator_name_from( $result );
		self::remember_creator_name( $creator_name );

		return new \WP_REST_Response(
			array(
				'success'      => true,
				'drafts'       => $drafts,
				'creator_name' => $creator_name,
			),
			200
		);
	}

	/**
	 * REST handler: the most recent Content Agent audit-run summary, for the
	 * Content Drafts status strip.
	 *
	 * Proxies DEF GET /api/staff-ai/content/last-run and normalizes the payload
	 * (see normalize_last_run_payload) so the client receives integer counts and
	 * a predictable shape. Returns last_run = null when no run has happened.
	 *
	 * @return \WP_REST_Response|\WP_Error Response.
	 */
	public static function rest_content_last_run( \WP_REST_Request $request )
	{
		$result = self::backend_request( 'GET', '/api/staff-ai/content/last-run' );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new \WP_REST_Response(
			array(
				'success'  => true,
				'last_run' => self::normalize_last_run_payload( $result ),
			),
			200
		);
	}

	/**
	 * Normalize the DEF /content/last-run payload into a safe, predictable shape.
	 *
	 * The backend returns either {"last_run": null} (never run) or
	 * {"last_run": {status, counts{...}, started_at, finished_at}}. This coerces
	 * every count to a non-negative integer (the strip renders them as numbers
	 * and must never be handed a string/float), keeps status/timestamps as
	 * strings (or null), and drops anything malformed. A missing or non-array
	 * last_run collapses to null so the UI shows "No runs yet".
	 *
	 * Note: counts are surfaced as a raw activity breakdown by design — we never
	 * derive a ratio like "N/total optimized", because most audited items
	 * legitimately pass and need no work, so a fraction would imply false pending
	 * work (Steve's hard requirement).
	 *
	 * @param mixed $payload Decoded backend response.
	 * @return array|null Normalized last_run, or null when there is no run.
	 */
	public static function normalize_last_run_payload( $payload ): ?array
	{
		if ( ! is_array( $payload ) || ! array_key_exists( 'last_run', $payload ) ) {
			return null;
		}
		$last_run = $payload['last_run'];
		if ( ! is_array( $last_run ) ) {
			return null; // null (never run) or malformed → "No runs yet".
		}

		$counts_in = ( isset( $last_run['counts'] ) && is_array( $last_run['counts'] ) )
			? $last_run['counts']
			: array();

		$counts = array();
		foreach ( array( 'audited', 'flagged', 'staged', 'needs_keyphrase', 'skipped', 'errored' ) as $key ) {
			$counts[ $key ] = isset( $counts_in[ $key ] ) ? max( 0, (int) $counts_in[ $key ] ) : 0;
		}

		// Optional: content types whose audit hit a transport error. Strings only.
		if ( isset( $counts_in['audit_failed_types'] ) && is_array( $counts_in['audit_failed_types'] ) ) {
			$failed_types = array();
			foreach ( $counts_in['audit_failed_types'] as $type ) {
				if ( is_string( $type ) && '' !== $type ) {
					$failed_types[] = $type;
				}
			}
			if ( ! empty( $failed_types ) ) {
				$counts['audit_failed_types'] = array_values( $failed_types );
			}
		}

		$string_or_null = static function ( $value ) {
			return ( is_string( $value ) && '' !== $value ) ? $value : null;
		};

		return array(
			'status'      => is_string( $last_run['status'] ?? null ) ? $last_run['status'] : '',
			'counts'      => $counts,
			'started_at'  => $string_or_null( $last_run['started_at'] ?? null ),
			'finished_at' => $string_or_null( $last_run['finished_at'] ?? null ),
		);
	}

	/**
	 * Bucket keys for the current-state coverage breakdown. Whitelisted so a
	 * hostile/garbled backend payload can only ever produce these integer fields.
	 */
	const COVERAGE_BUCKET_KEYS = array(
		'good',
		'optimized',
		'awaiting_review',
		'needs_work',
		'needs_keyphrase',
		'dismissed',
		'errored',
		'other',
		'total',
	);

	/**
	 * REST handler: current-state content coverage breakdown, per content type,
	 * for the Content Drafts status strip headline.
	 *
	 * Proxies DEF GET /api/staff-ai/content/summary and normalizes the payload
	 * (see normalize_summary_payload). Returns summary = null when the backend has
	 * no coverage data yet.
	 *
	 * @return \WP_REST_Response|\WP_Error Response.
	 */
	public static function rest_content_summary( \WP_REST_Request $request )
	{
		$result = self::backend_request( 'GET', '/api/staff-ai/content/summary' );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new \WP_REST_Response(
			array(
				'success' => true,
				'summary' => self::normalize_summary_payload( $result ),
			),
			200
		);
	}

	/**
	 * Normalize the DEF /content/summary payload into a safe, predictable shape.
	 *
	 * Backend shape: {"summary": {"by_type": {"<type>": {<buckets>}, ...},
	 * "totals": {<buckets>, "total_reviewed": N}}}. This whitelists the bucket
	 * keys, coerces every bucket to a non-negative integer, keeps only string
	 * type keys, and tolerates missing types/buckets. A missing or non-array
	 * summary collapses to null (the strip then simply omits the coverage line).
	 *
	 * The buckets are surfaced as discrete counts; we never derive a ratio over
	 * "all content" — the only legitimate denominator is items reviewed
	 * (Steve's hard requirement), carried as totals.total_reviewed.
	 *
	 * @param mixed $payload Decoded backend response.
	 * @return array|null Normalized summary, or null when there is none.
	 */
	public static function normalize_summary_payload( $payload ): ?array
	{
		if ( ! is_array( $payload ) || ! isset( $payload['summary'] ) || ! is_array( $payload['summary'] ) ) {
			return null;
		}
		$summary = $payload['summary'];

		$by_type_in = ( isset( $summary['by_type'] ) && is_array( $summary['by_type'] ) ) ? $summary['by_type'] : array();
		$by_type    = array();
		foreach ( $by_type_in as $type => $buckets ) {
			if ( ! is_string( $type ) || '' === $type ) {
				continue; // Drop malformed type keys.
			}
			$by_type[ $type ] = self::normalize_coverage_buckets( $buckets );
		}

		$totals = self::normalize_coverage_buckets( isset( $summary['totals'] ) ? $summary['totals'] : array() );
		// total_reviewed is the only sanctioned denominator (never "all content").
		$totals_in                = ( isset( $summary['totals'] ) && is_array( $summary['totals'] ) ) ? $summary['totals'] : array();
		$totals['total_reviewed'] = ( isset( $totals_in['total_reviewed'] ) && is_scalar( $totals_in['total_reviewed'] ) )
			? max( 0, (int) $totals_in['total_reviewed'] )
			: 0;

		return array(
			'by_type' => $by_type,
			'totals'  => $totals,
		);
	}

	/**
	 * Coerce one set of coverage buckets to a whitelisted map of non-negative
	 * integers. Unknown keys are dropped; missing keys default to 0.
	 *
	 * @param mixed $buckets Raw per-type or totals bucket map.
	 * @return array<string,int> Normalized buckets.
	 */
	private static function normalize_coverage_buckets( $buckets ): array
	{
		$buckets = is_array( $buckets ) ? $buckets : array();
		$out     = array();
		foreach ( self::COVERAGE_BUCKET_KEYS as $key ) {
			// Guard is_scalar before the int-cast so an array value (garbled
			// payload) can't raise an "Array to int conversion" warning or coerce
			// to a misleading 1 — it cleanly becomes 0.
			$out[ $key ] = ( isset( $buckets[ $key ] ) && is_scalar( $buckets[ $key ] ) )
				? max( 0, (int) $buckets[ $key ] )
				: 0;
		}
		return $out;
	}

	/**
	 * REST handler: list items the Content Agent skipped for lack of a focus
	 * keyphrase. Enriched with the local title + edit link so the human can jump
	 * to the editor and set one.
	 *
	 * @return \WP_REST_Response|\WP_Error Response.
	 */
	public static function rest_list_needs_keyphrase( \WP_REST_Request $request )
	{
		$result = self::backend_request( 'GET', '/api/staff-ai/content/needs-keyphrase' );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$items = ( isset( $result['items'] ) && is_array( $result['items'] ) ) ? $result['items'] : array();
		foreach ( $items as &$item ) {
			$item_id = isset( $item['item_id'] ) ? (int) $item['item_id'] : 0;
			if ( $item_id > 0 && get_post_status( $item_id ) ) {
				$title            = get_the_title( $item_id );
				$item['title']    = is_string( $title ) ? $title : '';
				$edit_url         = get_edit_post_link( $item_id, 'raw' );
				$item['edit_url'] = $edit_url ? $edit_url : '';
			}
		}
		unset( $item );

		return new \WP_REST_Response( array( 'success' => true, 'items' => $items ), 200 );
	}

	/**
	 * REST handler: apply a draft live (PR-6). The DEF write runs as THIS user, so
	 * WordPress's own current_user_can gates the product edit.
	 *
	 * Optionally forwards reviewer edits {proposed:{field:value}} (PR-C). Only the
	 * content allowlist is forwarded; DEF re-validates (edits may change the value
	 * of an already-touched field, not introduce new ones).
	 *
	 * @return \WP_REST_Response|\WP_Error Response ({status: applied|stale|apply_failed}).
	 */
	public static function rest_apply_content_draft( \WP_REST_Request $request )
	{
		$id   = sanitize_text_field( $request->get_param( 'id' ) );
		$body = array();
		$params = $request->get_json_params();
		if ( is_array( $params ) && isset( $params['proposed'] ) && is_array( $params['proposed'] ) ) {
			$allowed  = array( 'description', 'short_description', 'name' );
			$proposed = array();
			foreach ( $params['proposed'] as $field => $value ) {
				if ( in_array( $field, $allowed, true ) && is_string( $value ) ) {
					$proposed[ $field ] = $value;
				}
			}
			if ( ! empty( $proposed ) ) {
				$body['proposed'] = $proposed;
			}
		}
		$result = self::backend_request( 'POST', '/api/staff-ai/content/drafts/' . rawurlencode( $id ) . '/apply', $body );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new \WP_REST_Response( $result, 200 );
	}

	/**
	 * REST handler: dismiss a draft (PR-6).
	 *
	 * @return \WP_REST_Response|\WP_Error Response ({status: dismissed}).
	 */
	public static function rest_dismiss_content_draft( \WP_REST_Request $request )
	{
		$id     = sanitize_text_field( $request->get_param( 'id' ) );
		$result = self::backend_request( 'POST', '/api/staff-ai/content/drafts/' . rawurlencode( $id ) . '/dismiss' );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new \WP_REST_Response( $result, 200 );
	}

	// ── Content Agent DEF #522: item-level dismiss / dismissed list / restore ──────

	/**
	 * REST handler: list content items by bucket (e.g. bucket=dismissed).
	 *
	 * Proxies DEF GET /api/staff-ai/content/list?bucket=<bucket> and enriches each
	 * item with the local WP title and edit URL (same pattern as needs-keyphrase).
	 *
	 * @return \WP_REST_Response|\WP_Error Response ({success, items}).
	 */
	public static function rest_list_content_items( \WP_REST_Request $request )
	{
		$bucket = sanitize_key( (string) $request->get_param( 'bucket' ) );
		if ( '' === $bucket ) {
			return new \WP_Error(
				'invalid_bucket',
				__( 'bucket parameter is required.', 'widrow-ai' ),
				array( 'status' => 400 )
			);
		}
		$result = self::backend_request( 'GET', '/api/staff-ai/content/list?bucket=' . rawurlencode( $bucket ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$items = ( isset( $result['items'] ) && is_array( $result['items'] ) ) ? $result['items'] : array();
		foreach ( $items as &$item ) {
			$item_id = isset( $item['item_id'] ) ? (int) $item['item_id'] : 0;
			if ( $item_id > 0 && get_post_status( $item_id ) ) {
				$title             = get_the_title( $item_id );
				$item['title']     = is_string( $title ) ? $title : '';
				$edit_url          = get_edit_post_link( $item_id, 'raw' );
				$item['edit_url']  = $edit_url ? $edit_url : '';
				$view_url          = get_permalink( $item_id );
				$item['view_url']  = $view_url ? $view_url : '';
			}
		}
		unset( $item );

		// Allowlist the fields the JS UI reads. Prevents future backend schema
		// additions from silently surfacing sensitive fields to the client.
		$allowed = array_flip( array( 'item_id', 'item_type', 'draft_id', 'restorable', 'last_audited', 'title', 'edit_url', 'view_url' ) );
		$items   = array_map( static function ( $item ) use ( $allowed ) {
			return is_array( $item ) ? array_intersect_key( $item, $allowed ) : array();
		}, $items );

		return new \WP_REST_Response( array( 'success' => true, 'items' => $items ), 200 );
	}

	/**
	 * REST handler: lightweight dismiss of a content item (DEF #522).
	 *
	 * The item stays in knowledge / Customer Chat — only the optimization queue
	 * entry moves to the dismissed bucket. Proxies DEF POST
	 * /api/staff-ai/content/items/{item_id}/dismiss.
	 *
	 * @return \WP_REST_Response|\WP_Error Response ({status: dismissed}).
	 */
	public static function rest_dismiss_content_item( \WP_REST_Request $request )
	{
		$item_id = absint( $request->get_param( 'item_id' ) );
		if ( $item_id <= 0 ) {
			return new \WP_Error(
				'invalid_item_id',
				__( 'A valid item_id is required.', 'widrow-ai' ),
				array( 'status' => 400 )
			);
		}
		$result = self::backend_request( 'POST', '/api/staff-ai/content/items/' . $item_id . '/dismiss' );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new \WP_REST_Response( $result, 200 );
	}

	/**
	 * REST handler: restore a dismissed content item (DEF #522).
	 *
	 * Returns the item to its prior optimization bucket (needs_keyphrase /
	 * needs_work / pass). Proxies DEF POST
	 * /api/staff-ai/content/items/{item_id}/restore.
	 *
	 * @return \WP_REST_Response|\WP_Error Response ({status: needs_keyphrase|needs_work|pass}).
	 */
	public static function rest_restore_content_item( \WP_REST_Request $request )
	{
		$item_id = absint( $request->get_param( 'item_id' ) );
		if ( $item_id <= 0 ) {
			return new \WP_Error(
				'invalid_item_id',
				__( 'A valid item_id is required.', 'widrow-ai' ),
				array( 'status' => 400 )
			);
		}
		$result = self::backend_request( 'POST', '/api/staff-ai/content/items/' . $item_id . '/restore' );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new \WP_REST_Response( $result, 200 );
	}

	// ── Content Agent Engine 2.5: Clusters curation (targets + keyphrase queues) ──

	/**
	 * Validate a client-supplied reference_urls list: an array of http(s)
	 * URLs. Returns the cleaned list, or a WP_Error naming the problem —
	 * an invalid entry is rejected explicitly, never silently dropped. No
	 * count cap (5.7.11): DEF owns any per-target bound, and its refusal
	 * comes back through this same error path.
	 *
	 * @param mixed $raw Raw reference_urls from the request body.
	 * @return array|\WP_Error Cleaned URL list or error.
	 */
	private static function validate_reference_urls( $raw )
	{
		if ( ! is_array( $raw ) ) {
			return new \WP_Error(
				'invalid_reference_urls',
				__( 'reference_urls must be a list of URLs.', 'widrow-ai' ),
				array( 'status' => 400 )
			);
		}
		$urls = array();
		foreach ( $raw as $u ) {
			$clean = is_string( $u ) ? esc_url_raw( trim( $u ), array( 'http', 'https' ) ) : '';
			if ( '' === $clean ) {
				return new \WP_Error(
					'invalid_reference_urls',
					__( 'Each reference URL must be a valid http(s) URL.', 'widrow-ai' ),
					array( 'status' => 400 )
				);
			}
			$urls[] = $clean;
		}
		return $urls;
	}

	/**
	 * REST handler: list cluster targets. Proxies DEF GET /content/targets and
	 * passes the target objects through unchanged (id, item_type, item_id,
	 * source_route, title, url, reference_urls, focus_keyphrase, status,
	 * keyphrase_counts, created_at), plus the Creator's name for the page.
	 *
	 * @return \WP_REST_Response|\WP_Error Response.
	 */
	public static function rest_list_content_targets( \WP_REST_Request $request )
	{
		$result = self::backend_request( 'GET', '/api/staff-ai/content/targets' );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$targets = ( isset( $result['targets'] ) && is_array( $result['targets'] ) ) ? $result['targets'] : array();

		// Whichever list lands first names her on the page, and remembers her.
		$creator_name = self::creator_name_from( $result );
		self::remember_creator_name( $creator_name );

		return new \WP_REST_Response(
			array(
				'success'      => true,
				'targets'      => $targets,
				'creator_name' => $creator_name,
			),
			200
		);
	}

	/**
	 * REST handler: nominate a cluster target. Forwards item_type, item_id,
	 * source_route (CPTs), title, url and reference_urls to DEF — every accepted
	 * field is forwarded; DEF re-validates authoritatively (422/409).
	 *
	 * @return \WP_REST_Response|\WP_Error Response.
	 */
	public static function rest_create_content_target( \WP_REST_Request $request )
	{
		$params = $request->get_json_params();
		$params = is_array( $params ) ? $params : array();

		$item_type = ( isset( $params['item_type'] ) && is_string( $params['item_type'] ) ) ? sanitize_key( $params['item_type'] ) : '';
		$item_id   = isset( $params['item_id'] ) ? (string) $params['item_id'] : '';
		$title     = ( isset( $params['title'] ) && is_string( $params['title'] ) ) ? sanitize_text_field( $params['title'] ) : '';
		$url       = ( isset( $params['url'] ) && is_string( $params['url'] ) ) ? esc_url_raw( trim( $params['url'] ), array( 'http', 'https' ) ) : '';

		if ( '' === $item_type || ! preg_match( '/^\d+$/', $item_id ) || '' === $title || '' === $url ) {
			return new \WP_Error(
				'invalid_target',
				__( 'item_type, a numeric item_id, title and a valid http(s) url are required.', 'widrow-ai' ),
				array( 'status' => 400 )
			);
		}

		$body = array(
			'item_type' => $item_type,
			'item_id'   => $item_id,
			'title'     => $title,
			'url'       => $url,
		);
		if ( isset( $params['source_route'] ) && is_string( $params['source_route'] ) && '' !== $params['source_route'] ) {
			$body['source_route'] = sanitize_key( $params['source_route'] );
		}
		if ( isset( $params['reference_urls'] ) ) {
			$urls = self::validate_reference_urls( $params['reference_urls'] );
			if ( is_wp_error( $urls ) ) {
				return $urls;
			}
			$body['reference_urls'] = $urls;
		}

		$result = self::backend_request( 'POST', '/api/staff-ai/content/targets', $body );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$payload = array( 'success' => true );
		if ( is_array( $result ) ) {
			$payload = array_merge( $payload, $result );
		}
		return new \WP_REST_Response( $payload, 200 );
	}

	/**
	 * REST handler: update a target. PATCH semantics — only the keys present in
	 * the request are forwarded (title, url, reference_urls, status).
	 *
	 * @return \WP_REST_Response|\WP_Error Response.
	 */
	public static function rest_update_content_target( \WP_REST_Request $request )
	{
		$id     = sanitize_text_field( $request->get_param( 'id' ) );
		$params = $request->get_json_params();
		$params = is_array( $params ) ? $params : array();
		$body   = array();

		if ( array_key_exists( 'title', $params ) ) {
			$title = is_string( $params['title'] ) ? sanitize_text_field( $params['title'] ) : '';
			if ( '' === $title ) {
				return new \WP_Error( 'invalid_target', __( 'title cannot be empty.', 'widrow-ai' ), array( 'status' => 400 ) );
			}
			$body['title'] = $title;
		}
		if ( array_key_exists( 'url', $params ) ) {
			$url = is_string( $params['url'] ) ? esc_url_raw( trim( $params['url'] ), array( 'http', 'https' ) ) : '';
			if ( '' === $url ) {
				return new \WP_Error( 'invalid_target', __( 'url must be a valid http(s) URL.', 'widrow-ai' ), array( 'status' => 400 ) );
			}
			$body['url'] = $url;
		}
		if ( array_key_exists( 'reference_urls', $params ) ) {
			$urls = self::validate_reference_urls( $params['reference_urls'] );
			if ( is_wp_error( $urls ) ) {
				return $urls;
			}
			$body['reference_urls'] = $urls;
		}
		if ( array_key_exists( 'status', $params ) ) {
			if ( ! in_array( $params['status'], array( 'active', 'paused' ), true ) ) {
				return new \WP_Error( 'invalid_target', __( 'status must be active or paused.', 'widrow-ai' ), array( 'status' => 400 ) );
			}
			$body['status'] = $params['status'];
		}
		if ( empty( $body ) ) {
			return new \WP_Error( 'invalid_target', __( 'Nothing to update.', 'widrow-ai' ), array( 'status' => 400 ) );
		}

		$result = self::backend_request( 'PATCH', '/api/staff-ai/content/targets/' . rawurlencode( $id ), $body );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new \WP_REST_Response( $result, 200 );
	}

	/**
	 * REST handler: remove a target (and its queue rows, on the DEF side).
	 * Written posts and staged drafts are untouched.
	 *
	 * @return \WP_REST_Response|\WP_Error Response ({status: deleted}).
	 */
	public static function rest_delete_content_target( \WP_REST_Request $request )
	{
		$id     = sanitize_text_field( $request->get_param( 'id' ) );
		$result = self::backend_request( 'DELETE', '/api/staff-ai/content/targets/' . rawurlencode( $id ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new \WP_REST_Response( $result, 200 );
	}

	/**
	 * REST handler: list a target's keyphrase queue. Rows pass through unchanged
	 * (phrase, intent_type, status, rationale, staged_change_id, post_id, …);
	 * written rows whose post_id resolves locally are enriched with edit_url /
	 * view_url so the UI can link the cluster post.
	 *
	 * @return \WP_REST_Response|\WP_Error Response.
	 */
	public static function rest_list_target_keyphrases( \WP_REST_Request $request )
	{
		$id     = sanitize_text_field( $request->get_param( 'id' ) );
		$result = self::backend_request( 'GET', '/api/staff-ai/content/targets/' . rawurlencode( $id ) . '/keyphrases' );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$rows = ( isset( $result['keyphrases'] ) && is_array( $result['keyphrases'] ) ) ? $result['keyphrases'] : array();

		foreach ( $rows as &$row ) {
			$post_id = ( is_array( $row ) && isset( $row['post_id'] ) ) ? (int) $row['post_id'] : 0;
			if ( $post_id > 0 && get_post_status( $post_id ) ) {
				$edit_url        = get_edit_post_link( $post_id, 'raw' );
				$row['edit_url'] = $edit_url ? $edit_url : '';
				$view_url        = get_permalink( $post_id );
				$row['view_url'] = $view_url ? $view_url : '';
			}
		}
		unset( $row );

		return new \WP_REST_Response( array( 'success' => true, 'keyphrases' => $rows ), 200 );
	}

	/**
	 * REST handler: manually add a keyphrase to a target's queue (born approved
	 * on the DEF side — human-added IS curation).
	 *
	 * @return \WP_REST_Response|\WP_Error Response.
	 */
	public static function rest_add_target_keyphrase( \WP_REST_Request $request )
	{
		$id     = sanitize_text_field( $request->get_param( 'id' ) );
		$params = $request->get_json_params();
		$phrase = ( is_array( $params ) && isset( $params['phrase'] ) && is_string( $params['phrase'] ) )
			? sanitize_text_field( $params['phrase'] )
			: '';
		$intent = ( is_array( $params ) && isset( $params['intent_type'] ) && is_string( $params['intent_type'] ) )
			? sanitize_key( $params['intent_type'] )
			: '';
		if ( '' === $phrase || '' === $intent ) {
			return new \WP_Error(
				'invalid_keyphrase',
				__( 'A phrase and an intent type are required.', 'widrow-ai' ),
				array( 'status' => 400 )
			);
		}
		$result = self::backend_request(
			'POST',
			'/api/staff-ai/content/targets/' . rawurlencode( $id ) . '/keyphrases',
			array( 'phrase' => $phrase, 'intent_type' => $intent )
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new \WP_REST_Response( $result, 200 );
	}

	/**
	 * REST handler: edit a queued keyphrase (phrase and/or intent_type, only
	 * while proposed/approved — DEF enforces the state machine with 409).
	 *
	 * @return \WP_REST_Response|\WP_Error Response.
	 */
	public static function rest_update_keyphrase( \WP_REST_Request $request )
	{
		$id     = sanitize_text_field( $request->get_param( 'id' ) );
		$params = $request->get_json_params();
		$params = is_array( $params ) ? $params : array();
		$body   = array();

		if ( array_key_exists( 'phrase', $params ) ) {
			$phrase = is_string( $params['phrase'] ) ? sanitize_text_field( $params['phrase'] ) : '';
			if ( '' === $phrase ) {
				return new \WP_Error( 'invalid_keyphrase', __( 'phrase cannot be empty.', 'widrow-ai' ), array( 'status' => 400 ) );
			}
			$body['phrase'] = $phrase;
		}
		if ( array_key_exists( 'intent_type', $params ) ) {
			$intent = is_string( $params['intent_type'] ) ? sanitize_key( $params['intent_type'] ) : '';
			if ( '' === $intent ) {
				return new \WP_Error( 'invalid_keyphrase', __( 'intent_type cannot be empty.', 'widrow-ai' ), array( 'status' => 400 ) );
			}
			$body['intent_type'] = $intent;
		}
		if ( empty( $body ) ) {
			return new \WP_Error( 'invalid_keyphrase', __( 'Nothing to update.', 'widrow-ai' ), array( 'status' => 400 ) );
		}

		$result = self::backend_request( 'PATCH', '/api/staff-ai/content/keyphrases/' . rawurlencode( $id ), $body );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new \WP_REST_Response( $result, 200 );
	}

	/**
	 * REST handler: approve a proposed keyphrase.
	 *
	 * @return \WP_REST_Response|\WP_Error Response.
	 */
	public static function rest_approve_keyphrase( \WP_REST_Request $request )
	{
		$id     = sanitize_text_field( $request->get_param( 'id' ) );
		$result = self::backend_request( 'POST', '/api/staff-ai/content/keyphrases/' . rawurlencode( $id ) . '/approve' );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new \WP_REST_Response( $result, 200 );
	}

	/**
	 * REST handler: dismiss a keyphrase (keeps its slot claimed — re-derive
	 * won't re-propose it).
	 *
	 * @return \WP_REST_Response|\WP_Error Response.
	 */
	public static function rest_dismiss_keyphrase( \WP_REST_Request $request )
	{
		$id     = sanitize_text_field( $request->get_param( 'id' ) );
		$result = self::backend_request( 'POST', '/api/staff-ai/content/keyphrases/' . rawurlencode( $id ) . '/dismiss' );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new \WP_REST_Response( $result, 200 );
	}

	/**
	 * REST handler: bulk-dismiss every still-proposed keyphrase on a target
	 * (the post-curation "Dismiss remaining" click). Proxies DEF's same-named
	 * route; dismissed phrases keep their slot, so re-derive won't re-propose
	 * them. Pure passthrough — keyphrase_counts is sparse (zero-count keys
	 * omitted) and the JS coerces missing keys to 0.
	 *
	 * @return \WP_REST_Response|\WP_Error Response ({dismissed, keyphrase_counts}).
	 */
	public static function rest_dismiss_remaining_keyphrases( \WP_REST_Request $request )
	{
		$id     = sanitize_text_field( $request->get_param( 'id' ) );
		$result = self::backend_request(
			'POST',
			'/api/staff-ai/content/targets/' . rawurlencode( $id ) . '/keyphrases/dismiss-remaining'
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new \WP_REST_Response( $result, 200 );
	}

	/**
	 * REST handler: run derive for a target. Enqueue-and-ack on the DEF side
	 * ({status: accepted}, ~15-60s) — the UI polls the keyphrase list for new
	 * proposed rows.
	 *
	 * @return \WP_REST_Response|\WP_Error Response.
	 */
	public static function rest_derive_content_target( \WP_REST_Request $request )
	{
		$id     = sanitize_text_field( $request->get_param( 'id' ) );
		$result = self::backend_request( 'POST', '/api/staff-ai/content/targets/' . rawurlencode( $id ) . '/derive' );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new \WP_REST_Response( $result, 200 );
	}

	/**
	 * REST handler: local WP item search backing the Clusters target picker.
	 * Searches published items across all public, REST-exposed post types
	 * (posts and pages first-class alongside products and CPTs) and returns the
	 * fields a nomination needs — source_route is the type's rest_base, which
	 * DEF requires for non-built-in types.
	 *
	 * @return \WP_REST_Response Response ({items: [...]}).
	 */
	public static function rest_target_search( \WP_REST_Request $request )
	{
		$q = sanitize_text_field( (string) $request->get_param( 'q' ) );
		if ( strlen( $q ) < 2 ) {
			return new \WP_REST_Response( array( 'success' => true, 'items' => array() ), 200 );
		}

		$types = get_post_types( array( 'public' => true, 'show_in_rest' => true ), 'names' );
		unset( $types['attachment'] );

		$posts = get_posts(
			array(
				's'              => $q,
				'post_type'      => array_values( $types ),
				'post_status'    => 'publish',
				'posts_per_page' => 20,
			)
		);

		$items = array();
		foreach ( $posts as $p ) {
			$pto     = get_post_type_object( $p->post_type );
			$items[] = array(
				'item_type'    => $p->post_type,
				'item_id'      => (string) $p->ID,
				'title'        => get_the_title( $p ),
				'url'          => get_permalink( $p ),
				'source_route' => ( $pto && ! empty( $pto->rest_base ) ) ? $pto->rest_base : $p->post_type,
			);
		}

		return new \WP_REST_Response( array( 'success' => true, 'items' => $items ), 200 );
	}

	/**
	 * Check if current user has Staff AI access.
	 *
	 * @return bool True if user has widrow_staff_access OR widrow_management_access.
	 */
	public static function user_has_staff_ai_access(): bool
	{
		$user = wp_get_current_user();
		if (! $user || ! $user->exists()) {
			return false;
		}

		return $user->has_cap('widrow_staff_access') || $user->has_cap('widrow_management_access');
	}

	/**
	 * The canonical access-denied copy.
	 *
	 * One message, one source. Before 5.7.7 the same refusal existed in three
	 * variants across four surfaces (this page, rest_permission_check, the BFF
	 * passthrough callbacks, the download wp_die), none of which said how
	 * access is granted.
	 *
	 * The only population that can actually be refused is a signed-in user
	 * holding no DEF capability: administrators and management users have
	 * Staff AI access built in (map_def_capabilities grants widrow_staff_access
	 * via map_meta_cap), so the copy speaks to the one audience that can
	 * really see it — someone who needs their administrator.
	 *
	 * @return string The refusal copy.
	 */
	public static function access_denied_message(): string
	{
		return __("Your account doesn't have Staff AI access. Ask your site administrator to grant it with the Setup Assistant.", 'widrow-ai');
	}

	/**
	 * The canonical access-denied WP_Error for REST refusals.
	 *
	 * Deliberately a DISTINCT, stable code — not rest_forbidden — so a caller
	 * (or a test) can tell "the gate refused you" from every other 403/401 in
	 * the stack by code rather than by status arithmetic.
	 *
	 * @return \WP_Error 403 with code def_staff_access_required.
	 */
	public static function access_denied_error(): \WP_Error
	{
		return new \WP_Error(
			'def_staff_access_required',
			self::access_denied_message(),
			array('status' => 403)
		);
	}

	/**
	 * The manifest a host other than WordPress serves beside the exported console (W85 S8).
	 *
	 * The site's members, with every address relative to the host's own place and the
	 * bundled icons, which the export copies to assets/images/. No site name: the export
	 * runs without a site.
	 *
	 * @return array The web app manifest.
	 */
	public static function host_pwa_manifest(): array
	{
		$icons = array();
		foreach (array(192, 512) as $size) {
			$icons[] = array(
				'src'     => 'assets/images/staff-ai-icon-' . $size . '.png?ver=' . WIDROW_AI_VERSION,
				'sizes'   => $size . 'x' . $size,
				'type'    => 'image/png',
				'purpose' => 'any maskable',
			);
		}

		return array(
			'name'             => __('Staff AI', 'widrow-ai'),
			'short_name'       => __('Staff AI', 'widrow-ai'),
			'description'      => __('Staff AI Assistant', 'widrow-ai'),
			'start_url'        => './',
			'scope'            => './',
			'display'          => 'standalone',
			'background_color' => '#ffffff',
			'theme_color'      => '#6366f1',
			'icons'            => $icons,
			'version'          => WIDROW_AI_VERSION,
		);
	}

	/**
	 * The service worker's text — written by the export (W85 S8).
	 * Minimal worker — just enough for PWA installability.
	 *
	 * @return string The worker.
	 */
	public static function pwa_service_worker(): string
	{
		return <<<'JS'
// Staff AI Service Worker — enables PWA install.
const CACHE_NAME = 'staff-ai-v1';

self.addEventListener('install', function(event) {
	self.skipWaiting();
});

self.addEventListener('activate', function(event) {
	event.waitUntil(self.clients.claim());
});

self.addEventListener('fetch', function(event) {
	// GETs only. The chat stream is a POST and must reach the network untouched: wrapping
	// it in respondWith() turned a locked phone's dropped stream into "FetchEvent.respondWith
	// received an error: Load failed" with nothing to fall back to (7.6.9, Steve's canary
	// 2026-09-06). The page's own fetch now sees the drop and reloads the finished reply.
	if (event.request.method !== 'GET') return;
	// Network-first for everything else — Staff AI is a live app, not offline-capable.
	event.respondWith(
		fetch(event.request).catch(function() {
			return caches.match(event.request);
		})
	);
});
JS;
	}
}
