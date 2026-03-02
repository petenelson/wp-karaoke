<?php
/**
 * Venue Post Type
 *
 * @package WPKQ\Post_Type
 */

namespace WPKQ\Post_Type;

use WPKQ\REST\Queue_Rest_Controller;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Venue_Post_Type
 *
 * Registers and manages the karaoke-venue custom post type.
 */
class Venue_Post_Type {

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
	 * Register hooks.
	 */
	public function register() {
		add_action( 'init', array( $this, 'register_post_type' ) );
		add_action( 'init', array( $this, 'register_post_meta' ) );
		add_filter( 'manage_' . self::POST_TYPE . '_posts_columns', array( $this, 'add_admin_columns' ) );
		add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column', array( $this, 'render_admin_column' ), 10, 2 );
		add_filter( 'template_include', array( $this, 'load_single_template' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_assets' ) );
	}

	/**
	 * Register the karaoke-venue post type.
	 */
	public function register_post_type() {
		$labels = array(
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
		);

		$args = array(
			'labels'              => $labels,
			'public'              => true,
			'publicly_queryable'  => true,
			'show_ui'             => true,
			'show_in_menu'        => true,
			'menu_label'          => __( 'Karaoke', 'wp-karaoke' ),
			'query_var'           => false,
			'rewrite'             => array( 'slug' => 'karaoke' ),
			'capability_type'     => 'post',
			'has_archive'         => false,
			'hierarchical'        => false,
			'menu_position'       => null,
			'menu_icon'           => 'dashicons-format-audio',
			'supports'            => array( 'title' ),
			'show_in_rest'        => true,
			'capabilities'        => array(
				'edit_post'          => 'manage_options',
				'read_post'          => 'manage_options',
				'delete_post'        => 'manage_options',
				'edit_posts'         => 'manage_options',
				'edit_others_posts'  => 'manage_options',
				'publish_posts'      => 'manage_options',
				'read_private_posts' => 'manage_options',
			),
		);

		register_post_type( self::POST_TYPE, $args );
	}

	/**
	 * Register post meta for the singer queue.
	 */
	public function register_post_meta() {
		register_post_meta(
			self::POST_TYPE,
			self::META_KEY,
			array(
				'type'         => 'string',
				'description'  => __( 'Singer queue data stored as JSON.', 'wp-karaoke' ),
				'single'       => true,
				'default'      => '',
				'show_in_rest' => false,
			)
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
		if ( ! $post ) {
			return;
		}

		$url = add_query_arg(
			array(
				'page' => 'wpkq-manage-queue',
				'slug' => $post->post_name,
			),
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
			array(
				'name'           => sanitize_title( $slug ),
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => 1,
			)
		);

		if ( empty( $posts ) ) {
			return new \WP_Error( 'invalid_slug', __( 'Invalid venue slug.', 'wp-karaoke' ), array( 'status' => 404 ) );
		}

		$post  = $posts[0];
		$meta  = get_post_meta( $post->ID, self::META_KEY, true );
		$queue = $meta ? json_decode( $meta, true ) : array();

		if ( ! is_array( $queue ) ) {
			$queue = array();
		}

		return array(
			'post_id' => $post->ID,
			'queue'   => $queue,
		);
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

	public function load_single_template( $template ) {
		if ( is_singular( self::POST_TYPE ) ) {
			$custom_template = WPKQ_PLUGIN_DIR . '/templates/single-queue.php';
			if ( file_exists( $custom_template ) ) {
				return $custom_template;
			}
		}
		return $template;
	}

	/**
	 * Enqueue frontend assets for the queue page.
	 */
	public function enqueue_frontend_assets() {
		if ( is_singular( self::POST_TYPE ) ) {
			wp_enqueue_style( 'wpkq-queue-style', WPKQ_PLUGIN_URL . 'dist/css/frontend.css', array(), WPKQ_VERSION );
			wp_enqueue_script( 'wpkq-queue-script', WPKQ_PLUGIN_URL . 'dist/js/frontend.js', array(), WPKQ_VERSION, true );
			wp_localize_script( 'wpkq-queue-script', 'WPKQ_Queue_Data', array(
				'rest_url' => '', // esc_url_raw( rest_url( Queue_Rest_Controller::REST_NAMESPACE . Queue_Rest_Controller::ROUTE ) ),
				'slug'     => get_post_field( 'post_name', get_the_ID() ),
			) );
		}
	}
}
