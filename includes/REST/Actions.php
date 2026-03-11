<?php
/**
 * Actions REST Controller
 *
 * @package WPKQ\REST
 */

namespace WPKQ\REST;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WPKQ\Blocks\Now_Playing_Block;

/**
 * Class Actions
 *
 * Handles REST API endpoints for venue action signalling.
 * Actions are short-lived signals (e.g. "set-on-stage") posted by the admin
 * and consumed by the Now Playing monitor via 1-second polling.
 */
class Actions {

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
	const ROUTE = '/actions';

	/**
	 * Transient TTL in seconds. Acts as a safety-net expiry for unconsumed actions.
	 *
	 * @var int
	 */
	const TRANSIENT_TTL = 60;

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
	public function register() {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	/**
	 * Register REST routes.
	 */
	public function register_routes() {
		// GET  /wpkq/v1/actions?slug= — fetch pending actions (monitor auth).
		// POST /wpkq/v1/actions       — create an action (admin auth).
		register_rest_route(
			self::NAMESPACE,
			self::ROUTE,
			[
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_actions' ],
					'permission_callback' => [ $this, 'monitor_permissions_check' ],
					'args'                => $this->get_slug_arg(),
				],
				[
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'create_action' ],
					'permission_callback' => [ $this, 'admin_permissions_check' ],
					'args'                => array_merge(
						$this->get_slug_arg(),
						[
							'action'    => [
								'required'          => true,
								'type'              => 'string',
								'sanitize_callback' => 'sanitize_key',
								'description'       => __( 'Action slug, e.g. "set-on-stage".', 'wp-karaoke' ),
							],
							'singer_id' => [
								'required'    => false,
								'type'        => 'string',
								'description' => __( 'Singer ID the action relates to.', 'wp-karaoke' ),
							],
						]
					),
				],
			]
		);

		// DELETE /wpkq/v1/actions/{id}?slug= — delete a consumed action (monitor auth).
		register_rest_route(
			self::NAMESPACE,
			self::ROUTE . '/(?P<id>[A-Za-z0-9]+)',
			[
				'methods'             => \WP_REST_Server::DELETABLE,
				'callback'            => [ $this, 'delete_action' ],
				'permission_callback' => [ $this, 'monitor_permissions_check' ],
				'args'                => array_merge(
					[ 'id' => [ 'required' => true, 'type' => 'string' ] ],
					$this->get_slug_arg()
				),
			]
		);
	}

	/**
	 * GET /wpkq/v1/actions
	 *
	 * Returns pending actions for the venue.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response
	 */
	public function get_actions( \WP_REST_Request $request ) {
		$slug    = $request->get_param( 'slug' );
		$actions = $this->read_actions( $slug );

		return rest_ensure_response( [
			'success' => true,
			'actions' => array_values( $actions ),
		] );
	}

	/**
	 * POST /wpkq/v1/actions
	 *
	 * Creates a new action signal for the monitor to consume.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response
	 */
	public function create_action( \WP_REST_Request $request ) {
		$slug      = $request->get_param( 'slug' );
		$action    = $request->get_param( 'action' );
		$singer_id = preg_replace( '/[^A-Za-z0-9]/', '', (string) ( $request->get_param( 'singer_id' ) ?? '' ) );

		$new_action = [
			'id'        => wp_generate_password( 10, false, false ),
			'action'    => $action,
			'singer_id' => $singer_id,
		];

		$actions   = $this->read_actions( $slug );
		$actions[] = $new_action;
		$this->write_actions( $slug, $actions );

		return rest_ensure_response( [
			'success' => true,
			'actions' => array_values( $actions ),
		] );
	}

	/**
	 * DELETE /wpkq/v1/actions/{id}
	 *
	 * Removes a consumed action by ID.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response
	 */
	public function delete_action( \WP_REST_Request $request ) {
		$slug      = $request->get_param( 'slug' );
		$id        = preg_replace( '/[^A-Za-z0-9]/', '', (string) $request->get_param( 'id' ) );
		$actions   = $this->read_actions( $slug );

		$actions = array_values(
			array_filter(
				$actions,
				fn( $a ) => ( $a['id'] ?? '' ) !== $id
			)
		);

		$this->write_actions( $slug, $actions );

		return rest_ensure_response( [
			'success' => true,
			'actions' => $actions,
		] );
	}

	/**
	 * Check permissions for admin-only actions (create).
	 *
	 * @return bool|\WP_Error
	 */
	public function admin_permissions_check() {
		if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
			return new \WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to create actions.', 'wp-karaoke' ),
				[ 'status' => 403 ]
			);
		}

		return true;
	}

	/**
	 * Check permissions for monitor-authenticated actions (get/delete).
	 * Validates the venue-specific cookie set after password entry.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return bool|\WP_Error
	 */
	public function monitor_permissions_check( \WP_REST_Request $request ) {
		$slug = $request->get_param( 'slug' );

		$posts = get_posts( [
			'name'           => $slug,
			'post_type'      => 'karaoke-venue',
			'post_status'    => 'publish',
			'posts_per_page' => 1,
		] );

		if ( empty( $posts ) ) {
			return new \WP_Error(
				'invalid_venue',
				__( 'Venue not found.', 'wp-karaoke' ),
				[ 'status' => 404 ]
			);
		}

		$password    = get_post_meta( $posts[0]->ID, Now_Playing_Block::PASSWORD_META_KEY, true );
		$cookie_name = Now_Playing_Block::get_cookie_name( $slug );

		if (
			empty( $password ) ||
			! isset( $_COOKIE[ $cookie_name ] ) || // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			! hash_equals( md5( $password ), $_COOKIE[ $cookie_name ] ) // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_md5,WordPress.Security.ValidatedSanitizedInput
		) {
			return new \WP_Error(
				'rest_forbidden',
				__( 'Invalid monitor credentials.', 'wp-karaoke' ),
				[ 'status' => 403 ]
			);
		}

		return true;
	}

	/**
	 * Read pending actions from the transient for a venue.
	 *
	 * @param string $slug Venue slug.
	 * @return array
	 */
	private function read_actions( $slug ) {
		$raw     = get_transient( $this->transient_key( $slug ) );
		$actions = $raw ? json_decode( $raw, true ) : [];

		return is_array( $actions ) ? $actions : [];
	}

	/**
	 * Write pending actions to the transient for a venue.
	 *
	 * @param string $slug    Venue slug.
	 * @param array  $actions Actions array.
	 */
	private function write_actions( $slug, array $actions ) {
		set_transient( $this->transient_key( $slug ), wp_json_encode( array_values( $actions ) ), self::TRANSIENT_TTL );
	}

	/**
	 * Return the transient key for a venue's pending actions.
	 *
	 * @param string $slug Venue slug.
	 * @return string
	 */
	private function transient_key( $slug ) {
		return 'wpkq_actions_' . sanitize_key( $slug );
	}

	/**
	 * Get the slug argument definition.
	 *
	 * @return array
	 */
	private function get_slug_arg() {
		return [
			'slug' => [
				'required'          => true,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_title',
				'validate_callback' => 'rest_validate_request_arg',
				'description'       => __( 'The slug of the karaoke venue.', 'wp-karaoke' ),
			],
		];
	}
}
