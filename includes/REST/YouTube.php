<?php
/**
 * YouTube Search REST Controller
 *
 * @package WPKQ\REST
 */

namespace WPKQ\REST;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class YouTube
 *
 * Proxies YouTube Data API v3 search requests server-side so the API key
 * is never exposed to the browser.
 */
class YouTube {

	/**
	 * REST API namespace.
	 *
	 * @var string
	 */
	const NAMESPACE = 'wpkq/v1';

	/**
	 * REST API route.
	 *
	 * @var string
	 */
	const ROUTE = '/youtube/search';

	/**
	 * WordPress option key for the YouTube Data API key.
	 *
	 * @var string
	 */
	const API_KEY_OPTION = 'wpkq_youtube_api_key';

	/**
	 * YouTube Data API search endpoint.
	 *
	 * @var string
	 */
	const YT_SEARCH_URL = 'https://www.googleapis.com/youtube/v3/search';

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	static $instance = null;

	/**
	 * Get the singleton instance.
	 *
	 * @return self
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	/**
	 * Register REST routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			self::ROUTE,
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'search' ],
				'permission_callback' => [ $this, 'permissions_check' ],
				'args'                => [
					'q'           => [
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'description'       => __( 'Search query string.', 'wp-karaoke' ),
					],
					'max_results' => [
						'required'    => false,
						'type'        => 'integer',
						'default'     => 10,
						'minimum'     => 1,
						'maximum'     => 20,
						'description' => __( 'Maximum number of results to return (1–20).', 'wp-karaoke' ),
					],
				],
			]
		);
	}

	/**
	 * Check permissions — admin only.
	 *
	 * @return bool|\WP_Error
	 */
	public function permissions_check(): bool|\WP_Error {
		if ( ! is_user_logged_in() || ! current_user_can( \WPKQ\Capabilities::CAP ) ) {
			return new \WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to search YouTube.', 'wp-karaoke' ),
				[ 'status' => 403 ]
			);
		}
		return true;
	}

	/**
	 * GET /wpkq/v1/youtube/search
	 *
	 * Proxies a YouTube Data API v3 search/list request and returns a
	 * simplified array of video results.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function search( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$api_key = get_option( self::API_KEY_OPTION, '' );

		if ( empty( $api_key ) ) {
			return new \WP_Error(
				'youtube_api_key_missing',
				__( 'YouTube API key is not configured. Add it via: wp option update wpkq_youtube_api_key YOUR_KEY', 'wp-karaoke' ),
				[ 'status' => 501 ]
			);
		}

		$query       = $request->get_param( 'q' );
		$max_results = (int) $request->get_param( 'max_results' );

		// Build the YouTube API request URL with query parameters.
		// Only allow embeddable videos by adding 'videoEmbeddable=true' to the query.
		$yt_url = add_query_arg(
			[
				'part'            => 'snippet',
				'type'            => 'video',
				'videoEmbeddable' => 'true',
				'q'               => $query,
				'maxResults'      => $max_results,
				'key'             => $api_key,
			],
			self::YT_SEARCH_URL
		);

		$response = wp_remote_get(
			$yt_url,
			[
				'timeout' => 10,
			]
		);

		if ( is_wp_error( $response ) ) {
			return new \WP_Error(
				'youtube_request_failed',
				$response->get_error_message(),
				[ 'status' => 502 ]
			);
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$body        = wp_remote_retrieve_body( $response );
		$data        = json_decode( $body, true );

		if ( 200 !== $status_code ) {
			$message = isset( $data['error']['message'] )
				? $data['error']['message']
				: __( 'YouTube API returned an error.', 'wp-karaoke' );

			return new \WP_Error(
				'youtube_api_error',
				$message,
				[ 'status' => $status_code ]
			);
		}

		$results = [];

		if ( ! empty( $data['items'] ) ) {
			foreach ( $data['items'] as $item ) {
				$video_id  = $item['id']['videoId'] ?? '';
				$snippet   = $item['snippet'] ?? [];
				$title     = $snippet['title'] ?? '';
				$thumbnail = $snippet['thumbnails']['default']['url'] ?? '';

				if ( empty( $video_id ) ) {
					continue;
				}

				$results[] = [
					'videoId'   => $video_id,
					'title'     => $title,
					'thumbnail' => $thumbnail,
				];
			}
		}

		return rest_ensure_response(
			[
				'success' => true,
				'results' => $results,
			]
		);
	}
}
