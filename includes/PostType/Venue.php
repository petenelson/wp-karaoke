<?php
/**
 * Venue Post Type
 *
 * @package WPKQ\Post_Type
 */

namespace WPKQ\PostType;

use WPKQ\REST\Queue;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Venue
 *
 * Registers and manages the karaoke-venue custom post type.
 */
class Venue {

	/**
	 * Post type slug.
	 *
	 * @var string
	 */
	const POST_TYPE = 'karaoke-venue';

	/**
	 * Post meta key for the singer queue.
	 *
	 * @var string
	 */
	const META_KEY = '_wpkq_singer_queue';

	/**
	 * Post meta key for the signups-open flag.
	 *
	 * @var string
	 */
	const SIGNUPS_META_KEY = '_wpkq_signups_open';

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
		add_action( 'init', [ $this, 'register_post_type' ] );
		add_action( 'init', [ $this, 'register_post_meta' ] );
		add_filter( 'manage_' . self::POST_TYPE . '_posts_columns', [ $this, 'add_admin_columns' ] );
		add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column', [ $this, 'render_admin_column' ], 10, 2 );
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_frontend_assets' ] );
		add_action( 'admin_post_wpkq_toggle_signups', [ $this, 'handle_toggle_signups' ] );
		add_filter( 'query_vars', [ $this, 'modify_query_vars' ] );
		add_action( 'init', [ $this, 'register_rewrite_rules' ] );
		add_action( 'pre_get_posts', [ $this, 'filter_venue_queries' ] );
	}

	/**
	 * Register the karaoke-venue post type.
	 */
	public function register_post_type() {
		$labels = [
			'name'               => __( 'Venues', 'wp-karaoke' ),
			'singular_name'      => __( 'Venue', 'wp-karaoke' ),
			'menu_name'          => __( 'Karaoke', 'wp-karaoke' ),
			'add_new'            => __( 'Add New', 'wp-karaoke' ),
			'add_new_item'       => __( 'Add New Venue', 'wp-karaoke' ),
			'edit_item'          => __( 'Edit Venue', 'wp-karaoke' ),
			'new_item'           => __( 'New Venue', 'wp-karaoke' ),
			'view_item'          => __( 'View Venue', 'wp-karaoke' ),
			'search_items'       => __( 'Search Venues', 'wp-karaoke' ),
			'not_found'          => __( 'No venues found.', 'wp-karaoke' ),
			'not_found_in_trash' => __( 'No venues found in Trash.', 'wp-karaoke' ),
		];

		$args = [
			'labels'              => $labels,
			'public'              => true,
			'publicly_queryable'  => true,
			'show_ui'             => true,
			'show_in_menu'        => true,
			'menu_label'          => __( 'Karaoke', 'wp-karaoke' ),
			'query_var'           => false,
			'rewrite'             => [ 'slug' => 'karaoke' ],
			'capability_type'     => 'post',
			'has_archive'         => false,
			'hierarchical'        => true,
			'menu_position'       => null,
			'menu_icon'           => 'dashicons-format-audio',
			'supports'            => [ 'title', 'editor', 'page-attributes' ],
			'show_in_rest'        => true,
			'capabilities'        => [
				'edit_post'          => 'manage_options',
				'read_post'          => 'manage_options',
				'delete_post'        => 'manage_options',
				'edit_posts'         => 'manage_options',
				'edit_others_posts'  => 'manage_options',
				'publish_posts'      => 'manage_options',
				'read_private_posts' => 'manage_options',
			],
		];

		register_post_type( self::POST_TYPE, $args );
	}

	/**
	 * Register post meta for the singer queue.
	 */
	public function register_post_meta() {
		register_post_meta(
			self::POST_TYPE,
			self::META_KEY,
			[
				'type'         => 'string',
				'description'  => __( 'Singer queue data stored as JSON.', 'wp-karaoke' ),
				'single'       => true,
				'default'      => '',
				'show_in_rest' => false,
			]
		);

		register_post_meta(
			self::POST_TYPE,
			self::SIGNUPS_META_KEY,
			[
				'type'         => 'boolean',
				'description'  => __( 'Whether singer signups are open for this venue.', 'wp-karaoke' ),
				'single'       => true,
				'default'      => false,
				'show_in_rest' => false,
			]
		);
	}

	/**
	 * Add custom admin columns.
	 *
	 * @param array $columns Existing columns.
	 * @return array Modified columns.
	 */
	public function add_admin_columns( $columns ) {
		$columns['manage_queue'] = __( 'Manage Queue', 'wp-karaoke' );
		return $columns;
	}

	/**
	 * Render custom admin column content.
	 *
	 * @param string $column  Column name.
	 * @param int    $post_id Post ID.
	 */
	public function render_admin_column( $column, $post_id ) {
		if ( 'manage_queue' !== $column ) {
			return;
		}

		$post = get_post( $post_id );
		if ( ! $post || 0 !== (int) $post->post_parent ) {
			return;
		}

		$url = add_query_arg(
			[
				'page' => 'wpkq-manage-queue',
				'slug' => $post->post_name,
			],
			admin_url( 'admin.php' )
		);

		printf(
			'<a href="%s" class="button button-small">%s</a>',
			esc_url( $url ),
			esc_html__( 'Manage Queue', 'wp-karaoke' )
		);
	}

	/**
	 * Get queue data for a venue by post slug.
	 *
	 * @param string $slug Post slug.
	 * @return array|WP_Error Singer queue array or WP_Error on failure.
	 */
	public static function get_queue_by_slug( $slug ) {
		$posts = get_posts(
			[
				'name'           => sanitize_title( $slug ),
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => 1,
			]
		);

		if ( empty( $posts ) ) {
			return new \WP_Error( 'invalid_slug', __( 'Invalid venue slug.', 'wp-karaoke' ), [ 'status' => 404 ] );
		}

		$post  = $posts[0];
		$meta  = get_post_meta( $post->ID, self::META_KEY, true );
		$queue = $meta ? json_decode( $meta, true ) : [];

		if ( ! is_array( $queue ) ) {
			$queue = [];
		}

		return [
			'post_id' => $post->ID,
			'queue'   => $queue,
		];
	}

	/**
	 * Handle the wpkq_toggle_signups admin action.
	 * Toggles the signups-open flag for the given venue and redirects back.
	 */
	public function handle_toggle_signups() {
		check_admin_referer( 'wpkq_toggle_signups' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'wp-karaoke' ) );
		}

		$input = filter_var_array(
			$_POST, // phpcs:ignore WordPress.Security.NonceVerification
			[ 'post_id' => FILTER_VALIDATE_INT ]
		);

		$post_id = $input['post_id'] ?? 0;

		if ( ! $post_id ) {
			wp_die( esc_html__( 'Invalid venue.', 'wp-karaoke' ) );
		}

		$post = get_post( $post_id );

		if ( ! $post || self::POST_TYPE !== $post->post_type || 0 !== (int) $post->post_parent ) {
			wp_die( esc_html__( 'Venue not found.', 'wp-karaoke' ) );
		}

		$current_open = (bool) get_post_meta( $post_id, self::SIGNUPS_META_KEY, true );
		update_post_meta( $post_id, self::SIGNUPS_META_KEY, ! $current_open );

		wp_safe_redirect(
			add_query_arg(
				[
					'page' => 'wpkq-manage-queue',
					'slug' => $post->post_name,
				],
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Save queue data for a venue.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $queue   Singer queue array.
	 * @return bool True on success.
	 */
	public static function save_queue( $post_id, $queue ) {
		return update_post_meta( $post_id, self::META_KEY, wp_json_encode( $queue ) );
	}

	/**
	 * Enqueue frontend assets for the queue page.
	 */
	public function enqueue_frontend_assets() {
		if ( is_singular( self::POST_TYPE ) ) {
			wp_enqueue_style( 'wpkq-queue-style', WPKQ_PLUGIN_URL . 'dist/css/frontend.css', [], WPKQ_VERSION );
			wp_enqueue_script( 'wpkq-queue-script', WPKQ_PLUGIN_URL . 'dist/js/frontend.js', [], WPKQ_VERSION, true );
			wp_localize_script( 'wpkq-queue-script', 'WPKQ_Queue_Data', [
				'rest_url' => '', // esc_url_raw( rest_url( Queue::REST_NAMESPACE . Queue::ROUTE ) ),
				'slug'     => get_post_field( 'post_name', get_the_ID() ),
			] );
		}
	}

	/**
	 * Modify query args to ensure only top-level venue posts are queried on the frontend.
	 *
	 * @param array $vars Query vars.
	 * @return array Modified query args.
	 */
	public function modify_query_vars( $vars ) {
		$vars[] = 'karaoke-venue';
		$vars[] = 'karaoke-page';
		return $vars;
	}

	/**
	 * Register rewrite rules for venue pages.
	 * 
	 * @return void
	 */
	public function register_rewrite_rules() {
		add_rewrite_rule( '^karaoke/([^/]+)/?$', 'index.php?post_type=karaoke-venue&name=$matches[1]', 'top' );

		// Add one for the karaoke-venue and karaoke-page.
		add_rewrite_rule( '^karaoke/([^/]+)/([^/]+)/?$', 'index.php?karaoke-venue=$matches[1]&karaoke-page=$matches[2]', 'top' );
	}

	/**
	 * Filter venue queries to ensure only top-level posts are returned.
	 *
	 * @param WP_Query $query The WP_Query instance (passed by reference).
	 */
	public function filter_venue_queries( $query ) {
		if ( is_admin() || ! $query->is_main_query() ) {
			return;
		}

		$karaoke_venue = get_query_var( 'karaoke-venue' );
		$karaoke_page  = get_query_var( 'karaoke-page' );

		// Get the venue post ID.
		if ( ! empty( $karaoke_venue ) && ! empty( $karaoke_page ) ) {
			$posts = get_posts(
				[
					'name'           => sanitize_title( $karaoke_venue ),
					'post_type'      => self::POST_TYPE,
					'post_status'    => 'publish',
					'posts_per_page' => 1,
					'fields'         => 'ids',
				]
			);

			if ( ! empty( $posts ) ) {
				// If a page is specified, ensure it exists as a child of the venue.
				$query->parse_query( [
					'name'           => sanitize_title( $karaoke_page ),
					'post_type'      => self::POST_TYPE,
					'post_status'    => 'publish',
					'posts_per_page' => 1,
					'post_parent'    => $posts[0],
				] );
			}
		}
	}
}
