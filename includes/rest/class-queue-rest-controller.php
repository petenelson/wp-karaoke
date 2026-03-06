<?php
/**
 * Queue REST Controller
 *
 * @package WPKQ\REST
 */

namespace WPKQ\REST;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WPKQ\Post_Type\Venue_Post_Type;

/**
 * Class Queue_Rest_Controller
 *
 * Handles REST API endpoints for the karaoke queue.
 */
class Queue_Rest_Controller {

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
	const ROUTE = '/queue';

	/**
	 * Register hooks.
	 */
	public function register() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register REST routes.
	 */
	public function register_routes() {
		register_rest_route(
			self::NAMESPACE,
			self::ROUTE,
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_queue' ),
					'permission_callback' => '__return_true',
					'args'                => $this->get_slug_arg(),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'update_queue' ),
					'permission_callback' => array( $this, 'update_queue_permissions_check' ),
					'args'                => $this->get_update_args(),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			self::ROUTE . '/advance',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'advance_singer' ),
				'permission_callback' => array( $this, 'advance_permissions_check' ),
				'args'                => array(
					'slug' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_title',
					),
					'id'   => array(
						'required' => true,
						'type'     => 'string',
					),
				),
			)
		);
	}

	/**
	 * GET /wpkq/v1/queue
	 *
	 * Returns singer queue for a venue. Filters to in-the-queue only for public users.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_queue( \WP_REST_Request $request ) {
		$slug   = $request->get_param( 'slug' );
		$result = Venue_Post_Type::get_queue_by_slug( $slug );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$queue = $result['queue'];

		// Filter to active singers only for non-logged-in users.
		if ( ! is_user_logged_in() ) {
			$queue = array_values(
				array_filter(
					$queue,
					function( $singer ) {
						return isset( $singer['status'] ) && in_array( $singer['status'], array( 'on-stage', 'in-the-queue' ), true );
					}
				)
			);

			// Sort: on-stage singer first.
			usort(
				$queue,
				function( $a, $b ) {
					$a_on_stage = isset( $a['status'] ) && 'on-stage' === $a['status'];
					$b_on_stage = isset( $b['status'] ) && 'on-stage' === $b['status'];
					return $b_on_stage <=> $a_on_stage;
				}
			);

			// Only return public fields.
			foreach ( $queue as &$singer ) {
				$singer = array(
					'id'          => $singer['id'] ?? '',
					'singer_name' => $singer['singer_name'],
					'youtube_url' => $singer['youtube_url'] ?? '',
					'status'      => $singer['status'],
				);
			}
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'queue'   => $queue,
			)
		);
	}

	/**
	 * POST /wpkq/v1/queue
	 *
	 * Updates the full singer queue for a venue.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function update_queue( \WP_REST_Request $request ) {
		$slug   = $request->get_param( 'slug' );
		$result = Venue_Post_Type::get_queue_by_slug( $slug );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$post_id       = $result['post_id'];
		$singers_input = $request->get_param( 'queue' );

		if ( ! is_array( $singers_input ) ) {
			return new \WP_Error(
				'invalid_queue',
				__( 'Queue must be an array.', 'wp-karaoke' ),
				array( 'status' => 400 )
			);
		}

		$sanitized_queue = array();
		foreach ( $singers_input as $singer ) {
			$sanitized_queue[] = $this->sanitize_singer( $singer );
		}

		$saved = Venue_Post_Type::save_queue( $post_id, $sanitized_queue );

		if ( false === $saved ) {
			return new \WP_Error(
				'save_failed',
				__( 'Failed to save queue.', 'wp-karaoke' ),
				array( 'status' => 500 )
			);
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'queue'   => $sanitized_queue,
			)
		);
	}

	/**
	 * Check permissions for updating the queue.
	 *
	 * @return bool|\WP_Error
	 */
	public function update_queue_permissions_check() {
		if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
			return new \WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to update the queue.', 'wp-karaoke' ),
				array( 'status' => 403 )
			);
		}
		return true;
	}

	/**
	 * Sanitize a single singer record.
	 *
	 * @param array $singer Raw singer data.
	 * @return array Sanitized singer data.
	 */
	private function sanitize_singer( $singer ) {
		$valid_statuses = array( 'kj-received', 'in-the-queue', 'on-stage', 'finished' );

		return array(
			'id'             => isset( $singer['id'] ) ? preg_replace( '/[^A-Za-z0-9]/', '', $singer['id'] ) : '',
			'singer_name'    => isset( $singer['singer_name'] ) ? substr( sanitize_text_field( $singer['singer_name'] ), 0, 50 ) : '',
			'song'           => isset( $singer['song'] ) ? substr( sanitize_text_field( $singer['song'] ), 0, 50 ) : '',
			'artist'         => isset( $singer['artist'] ) ? substr( sanitize_text_field( $singer['artist'] ), 0, 50 ) : '',
			'youtube_url'    => isset( $singer['youtube_url'] ) ? esc_url_raw( $singer['youtube_url'] ) : '',
			'signup_time'    => isset( $singer['signup_time'] ) ? absint( $singer['signup_time'] ) : 0,
			'completed_time' => isset( $singer['completed_time'] ) ? absint( $singer['completed_time'] ) : 0,
			'status'         => ( isset( $singer['status'] ) && in_array( $singer['status'], $valid_statuses, true ) )
				? $singer['status']
				: 'kj-received',
		);
	}

	/**
	 * Get the slug argument definition.
	 *
	 * @return array
	 */
	private function get_slug_arg() {
		return array(
			'slug' => array(
				'required'          => true,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_title',
				'validate_callback' => 'rest_validate_request_arg',
				'description'       => __( 'The slug of the karaoke venue.', 'wp-karaoke' ),
			),
		);
	}

	/**
	 * Get argument definitions for the update endpoint.
	 *
	 * @return array
	 */
	private function get_update_args() {
		return array_merge(
			$this->get_slug_arg(),
			array(
				'queue' => array(
					'required'    => true,
					'type'        => 'array',
					'description' => __( 'The full singer queue array.', 'wp-karaoke' ),
				),
			)
		);
	}

	/**
	 * Check permissions for the advance endpoint using the venue monitor cookie.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return bool|\WP_Error
	 */
	public function advance_permissions_check( \WP_REST_Request $request ) {
		$slug = $request->get_param( 'slug' );

		$posts = get_posts(
			array(
				'name'           => $slug,
				'post_type'      => 'karaoke-venue',
				'post_status'    => 'publish',
				'posts_per_page' => 1,
			)
		);

		if ( empty( $posts ) ) {
			return new \WP_Error(
				'invalid_venue',
				__( 'Venue not found.', 'wp-karaoke' ),
				array( 'status' => 404 )
			);
		}

		$password    = get_post_meta( $posts[0]->ID, \WPKQ\Blocks\Now_Playing_Block::PASSWORD_META_KEY, true );
		$cookie_name = \WPKQ\Blocks\Now_Playing_Block::get_cookie_name( $slug );

		if (
			empty( $password ) ||
			! isset( $_COOKIE[ $cookie_name ] ) || // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			! hash_equals( md5( $password ), $_COOKIE[ $cookie_name ] ) // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_md5,WordPress.Security.ValidatedSanitizedInput
		) {
			return new \WP_Error(
				'rest_forbidden',
				__( 'Invalid monitor credentials.', 'wp-karaoke' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * POST /wpkq/v1/queue/advance
	 *
	 * Marks the current on-stage singer as finished and promotes the given singer to on-stage.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function advance_singer( \WP_REST_Request $request ) {
		$slug   = $request->get_param( 'slug' );
		$id     = preg_replace( '/[^A-Za-z0-9]/', '', (string) $request->get_param( 'id' ) );
		$result = Venue_Post_Type::get_queue_by_slug( $slug );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$post_id = $result['post_id'];
		$queue   = $result['queue'];
		$found   = false;

		foreach ( $queue as &$singer ) {
			if ( 'on-stage' === ( $singer['status'] ?? '' ) ) {
				$singer['status']         = 'finished';
				$singer['completed_time'] = time();
			}
			if ( isset( $singer['id'] ) && $singer['id'] === $id ) {
				$singer['status'] = 'on-stage';
				$found            = true;
			}
		}
		unset( $singer );

		if ( ! $found ) {
			return new \WP_Error(
				'singer_not_found',
				__( 'Singer not found in queue.', 'wp-karaoke' ),
				array( 'status' => 404 )
			);
		}

		Venue_Post_Type::save_queue( $post_id, $queue );

		return $this->get_queue( $request );
	}
}
