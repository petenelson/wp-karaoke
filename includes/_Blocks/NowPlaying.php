<?php
/**
 * Now Playing Block
 *
 * @package WPKQ\Blocks
 */

namespace WPKQ\Blocks;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class NowPlaying
 *
 * Registers the Now Playing block and handles password verification.
 */
class NowPlaying {

	/**
	 * Post meta key that stores the monitor password on the venue post.
	 *
	 * @var string
	 */
	const PASSWORD_META_KEY = '_wpkq_monitor_password';

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
		add_action( 'init', [ $this, 'register_block' ] );
		add_filter( 'body_class', [ $this, 'add_body_class' ] );
		add_action( 'admin_post_wpkq_verify_password', [ $this, 'handle_password_verification' ] );
		add_action( 'admin_post_nopriv_wpkq_verify_password', [ $this, 'handle_password_verification' ] );
		add_action( 'save_post', [ $this, 'sync_password_to_meta' ], 10, 2 );
	}

	/**
	 * Add a body class when the Now Playing block is present on the current page.
	 *
	 * @param string[] $classes Existing body classes.
	 * @return string[]
	 */
	public function add_body_class( $classes ) {
		if ( is_singular() && has_block( 'wp-karaoke/now-playing' ) ) {
			$classes[] = 'wpkq-has-now-playing-block';
		}

		return $classes;
	}

	/**
	 * Register the block type from block.json.
	 */
	public function register_block() {
		register_block_type( WPKQ_PLUGIN_DIR . 'includes/blocks/now-playing/' );
	}

	/**
	 * Return a venue-specific cookie name.
	 *
	 * @param string $venue_slug Venue post slug.
	 * @return string
	 */
	public static function get_cookie_name( $venue_slug ) {
		return 'wpkq_auth_' . sanitize_key( $venue_slug );
	}

	/**
	 * Handle the password form POST from admin-post.php.
	 *
	 * Validates the submitted password against the venue's stored password,
	 * then sets a venue-specific cookie and redirects back to the page.
	 */
	public function handle_password_verification() {
		if (
			! isset( $_POST['wpkq_nonce'] ) ||
			! wp_verify_nonce(
				sanitize_text_field( wp_unslash( $_POST['wpkq_nonce'] ) ),
				'wpkq_verify_password'
			)
		) {
			wp_die( esc_html__( 'Invalid request.', 'wp-karaoke' ) );
		}

		$venue_slug  = isset( $_POST['venue_slug'] ) ? sanitize_title( wp_unslash( $_POST['venue_slug'] ) ) : '';
		$password    = isset( $_POST['password'] ) ? wp_unslash( $_POST['password'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$redirect_to = isset( $_POST['redirect_to'] ) ? esc_url_raw( wp_unslash( $_POST['redirect_to'] ) ) : home_url();

		if ( empty( $venue_slug ) ) {
			wp_safe_redirect( $redirect_to );
			exit;
		}

		// Look up the venue and retrieve its stored password.
		$posts = get_posts(
			[
				'name'           => $venue_slug,
				'post_type'      => 'karaoke-venue',
				'post_status'    => 'publish',
				'posts_per_page' => 1,
			]
		);

		if ( empty( $posts ) ) {
			wp_safe_redirect( add_query_arg( 'wpkq_auth_error', '1', $redirect_to ) );
			exit;
		}

		$expected_password = get_post_meta( $posts[0]->ID, self::PASSWORD_META_KEY, true );

		if ( empty( $expected_password ) || $password !== $expected_password ) {
			wp_safe_redirect( add_query_arg( 'wpkq_auth_error', '1', $redirect_to ) );
			exit;
		}

		// Set a venue-specific cookie valid for 6 hours.
		$cookie_name = self::get_cookie_name( $venue_slug );
		$expiry      = time() + ( 6 * HOUR_IN_SECONDS );

		setcookie( // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing
			$cookie_name,
			md5( $password ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_md5
			$expiry,
			COOKIEPATH,
			COOKIE_DOMAIN,
			is_ssl(),
			true
		);

		wp_safe_redirect( $redirect_to );
		exit;
	}

	/**
	 * Sync the block's password attribute to the venue's post meta on save.
	 *
	 * This allows the password form handler to validate without parsing block
	 * content at request time.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post object.
	 */
	public function sync_password_to_meta( $post_id, $post ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! has_block( 'wp-karaoke/now-playing', $post ) ) {
			return;
		}

		$blocks = parse_blocks( $post->post_content );

		foreach ( $blocks as $block ) {
			if ( 'wp-karaoke/now-playing' !== $block['blockName'] ) {
				continue;
			}

			$venue_id = isset( $block['attrs']['venueId'] ) ? absint( $block['attrs']['venueId'] ) : 0;
			$password = isset( $block['attrs']['password'] ) ? $block['attrs']['password'] : '';

			if ( $venue_id && '' !== $password ) {
				update_post_meta( $venue_id, self::PASSWORD_META_KEY, $password );
			}

			break;
		}
	}
}
