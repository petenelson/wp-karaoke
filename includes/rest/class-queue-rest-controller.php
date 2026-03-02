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

		// Filter to in-the-queue only for non-logged-in users.
		if ( ! is_user_logged_in() ) {
			$queue = array_values(
				array_filter(
					$queue,
					function( $singer ) {
						return isset( $singer['status'] ) && 'in-the-queue' === $singer['status'];
					}
				)
			);
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
		$valid_statuses = array( 'kj-received', 'in-the-queue', 'finished' );

		return array(
			'singer_name'    => isset( $singer['singer_name'] ) ? substr( sanitize_text_field( $singer['singer_name'] ), 0, 50 ) : '',
			'song'           => isset( $singer['song'] ) ? substr( sanitize_text_field( $singer['song'] ), 0, 50 ) : '',
			'artist'         => isset( $singer['artist'] ) ? substr( sanitize_text_field( $singer['artist'] ), 0, 50 ) : '',
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
}
