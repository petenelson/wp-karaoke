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
	 * Post meta key that flags whether the "Load Fake Singers" button is enabled for the venue.
	 *
	 * @var string
	 */
	const SEED_ENABLED_META_KEY = '_wpkq_seed_enabled';

	/**
	 * Maps the block's color attributes to the CSS custom properties used in style.css.
	 *
	 * Shared with the block editor (localized as WPKQNowPlaying.colorVars) so the
	 * editor preview and the front end use the same mapping.
	 *
	 * @var array<string, string>
	 */
	const COLOR_CSS_VARS = [
		'monitorBackgroundColor'       => '--wpkq-monitor-bg',
		'progressBarColor'             => '--wpkq-progress-bar-color',
		'tickerBackgroundColor'        => '--wpkq-ticker-bg',
		'tickerItemBackgroundColor'    => '--wpkq-ticker-item-bg',
		'tickerItemTextColor'          => '--wpkq-ticker-item-color',
		'tickerOnStageBackgroundColor' => '--wpkq-ticker-on-stage-bg',
		'tickerOnStageTextColor'       => '--wpkq-ticker-on-stage-color',
	];

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
		add_action( 'enqueue_block_editor_assets', [ $this, 'localize_editor_script' ] );
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
		register_block_type( WPKQ_PLUGIN_DIR . 'includes/Blocks/now-playing/' );
	}

	/**
	 * Pass the fake singers to the block editor script for the ticker preview.
	 */
	public function localize_editor_script() {
		wp_localize_script(
			generate_block_asset_handle( 'wp-karaoke/now-playing', 'editorScript' ),
			'WPKQNowPlaying',
			[
				'fakeSingers' => self::get_fake_singers(),
				'colorVars'   => self::COLOR_CSS_VARS,
			]
		);
	}

	/**
	 * Sanitize a color value from the block's color settings.
	 *
	 * Accepts hex colors, rgb()/rgba()/hsl()/hsla() colors, and theme palette
	 * preset variables (var(--wp--preset--color--{slug})).
	 *
	 * @param string $color Color value.
	 * @return string The color, or an empty string if it is not an accepted format.
	 */
	public static function sanitize_color( string $color ): string {
		$color = trim( $color );

		if ( sanitize_hex_color( $color ) ) {
			return $color;
		}

		if ( preg_match( '/^(rgb|rgba|hsl|hsla)\(\s*[0-9.%\s,\/+-]+\)$/i', $color ) ) {
			return $color;
		}

		if ( preg_match( '/^var\(\s*--wp--preset--color--[a-z0-9-]+\s*\)$/i', $color ) ) {
			return $color;
		}

		return '';
	}

	/**
	 * Build the inline style of CSS custom properties for the block's color settings.
	 *
	 * @param array $attributes Block attributes.
	 * @return string Inline style declarations, or an empty string when no colors are set.
	 */
	public static function get_color_style( array $attributes ): string {
		$style = '';

		foreach ( self::COLOR_CSS_VARS as $attribute => $css_var ) {
			$color = isset( $attributes[ $attribute ] ) ? self::sanitize_color( (string) $attributes[ $attribute ] ) : '';

			if ( '' !== $color ) {
				$style .= $css_var . ':' . $color . ';';
			}
		}

		return $style;
	}

	/**
	 * Get the fake singers used by the "Load Fake Singers" button and the editor ticker preview.
	 *
	 * The first singer is placed on stage; the rest are added to the queue in order.
	 *
	 * @return array[] List of singers, each with `singer_name`, `song`, `artist`, and `youtube_url`.
	 */
	public static function get_fake_singers(): array {
		$fake_singers = [
			[
				'singer_name' => 'Ripley',
				'song'        => 'You Are My Lucky Star',
				'artist'      => 'Singing in the Rain',
				'youtube_url' => 'https://www.youtube.com/watch?v=86DOsuQoado',
			],
			[
				'singer_name' => 'Hicks',
				'song'        => 'Frosty the Snowman',
				'artist'      => 'Traditional',
				'youtube_url' => 'https://www.youtube.com/watch?v=8hINXuDyyvs',
			],
			[
				'singer_name' => 'Hudson',
				'song'        => 'Danger Zone',
				'artist'      => 'Kenny Loggins',
				'youtube_url' => 'https://www.youtube.com/watch?v=_GytioD-eDs',
			],
			[
				'singer_name' => 'Vasquez',
				'song'        => 'We Will Rock You',
				'artist'      => 'Queen',
				'youtube_url' => 'https://www.youtube.com/watch?v=GZSJ0kRMKk4',
			],
			[
				'singer_name' => 'Bishop',
				'song'        => 'Mr Roboto',
				'artist'      => 'Styx',
				'youtube_url' => 'https://www.youtube.com/watch?v=kAfrhwb2uDU',
			],
			[
				'singer_name' => 'Newt',
				'song'        => 'I Will Survive',
				'artist'      => 'Gloria Gaynor',
				'youtube_url' => 'https://www.youtube.com/watch?v=ehqhfZvaPNM',
			],
			[
				'singer_name' => 'Burke',
				'song'        => "Money (That's What I Want)",
				'artist'      => 'Barrett Strong',
				'youtube_url' => 'https://www.youtube.com/watch?v=8t1nz4BKXdY',
			],
			[
				'singer_name' => 'Apone',
				'song'        => 'Fortunate Son',
				'artist'      => 'Creedence Clearwater Revival',
				'youtube_url' => 'https://www.youtube.com/watch?v=VEh9tS1jN5U',
			],
		];

		/**
		 * Filters the fake singers used by the "Load Fake Singers" button and the editor ticker preview.
		 *
		 * The first singer is placed on stage; the rest are added to the queue in order.
		 *
		 * @param array[] $fake_singers List of singers, each with `singer_name`, `song`, `artist`, and `youtube_url`.
		 */
		$filtered = apply_filters( 'wpkq_fake_singers', $fake_singers );

		if ( ! is_array( $filtered ) ) {
			return $fake_singers;
		}

		$normalized = [];

		foreach ( $filtered as $singer ) {
			if ( ! is_array( $singer ) || empty( $singer['singer_name'] ) ) {
				continue;
			}

			$normalized[] = [
				'singer_name' => sanitize_text_field( $singer['singer_name'] ),
				'song'        => sanitize_text_field( $singer['song'] ?? '' ),
				'artist'      => sanitize_text_field( $singer['artist'] ?? '' ),
				'youtube_url' => esc_url_raw( $singer['youtube_url'] ?? '' ),
			];
		}

		return $normalized;
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
	 * Sync the block's password and "Load Fake Singers" toggle to the venue's post meta on save.
	 *
	 * This allows the password form handler and the seed endpoint to validate
	 * without parsing block content at request time.
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

			if ( $venue_id ) {
				if ( ! empty( $block['attrs']['showFakeSingersButton'] ) ) {
					update_post_meta( $venue_id, self::SEED_ENABLED_META_KEY, '1' );
				} else {
					delete_post_meta( $venue_id, self::SEED_ENABLED_META_KEY );
				}
			}

			break;
		}
	}
}
