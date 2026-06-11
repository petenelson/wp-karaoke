<?php
/**
 * Now Playing Block — server-side render.
 *
 * @package WPKQ\Blocks
 *
 * @var array    $attributes Block attributes.
 * @var string   $content    Inner block content (unused).
 * @var WP_Block $block      Block instance.
 */

use WPKQ\Blocks\NowPlaying;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$venue_id           = isset( $attributes['venueId'] ) ? absint( $attributes['venueId'] ) : 0;
$password           = isset( $attributes['password'] ) ? $attributes['password'] : '';
$ticker_queue_limit = isset( $attributes['tickerQueueLimit'] ) ? absint( $attributes['tickerQueueLimit'] ) : 6;

// Block is not renderable without a venue and password configured.
if ( ! $venue_id || '' === $password ) {
	return;
}

$venue = get_post( $venue_id );

if ( ! $venue || 'karaoke-venue' !== $venue->post_type || 'publish' !== $venue->post_status ) {
	return;
}

$venue_slug  = $venue->post_name;
$cookie_name = NowPlaying::get_cookie_name( $venue_slug );

// Authorization: cookie value must equal md5( password ).
$is_authorized = isset( $_COOKIE[ $cookie_name ] ) &&
	hash_equals( md5( $password ), $_COOKIE[ $cookie_name ] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_md5

$block_props = get_block_wrapper_attributes( [ 'class' => 'wpkq-monitor' ] );

if ( ! $is_authorized ) {
	$has_error = isset( $_GET['wpkq_auth_error'] ) && '1' === sanitize_text_field( wp_unslash( $_GET['wpkq_auth_error'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
	?>
	<div <?php echo $block_props; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
		<div class="wpkq-monitor__password-gate">
			<h2 class="wpkq-monitor__password-title">
				<?php esc_html_e( 'Karaoke Monitor', 'wp-karaoke' ); ?>
			</h2>

			<?php if ( $has_error ) : ?>
				<p class="wpkq-monitor__password-error">
					<?php esc_html_e( 'Incorrect password. Please try again.', 'wp-karaoke' ); ?>
				</p>
			<?php endif; ?>

			<form
				method="post"
				action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
				class="wpkq-monitor__password-form"
			>
				<?php wp_nonce_field( 'wpkq_verify_password', 'wpkq_nonce' ); ?>
				<input type="hidden" name="action" value="wpkq_verify_password">
				<input type="hidden" name="venue_slug" value="<?php echo esc_attr( $venue_slug ); ?>">
				<input type="hidden" name="redirect_to" value="<?php echo esc_url( get_permalink() ); ?>">

				<div class="wpkq-monitor__password-field">
					<label for="wpkq-monitor-password" class="wpkq-monitor__password-label">
						<?php esc_html_e( 'Password', 'wp-karaoke' ); ?>
					</label>
					<input
						type="password"
						id="wpkq-monitor-password"
						name="password"
						class="wpkq-monitor__password-input"
						required
						autofocus
					>
				</div>

				<button type="submit" class="wpkq-monitor__password-submit">
					<?php esc_html_e( 'Enter', 'wp-karaoke' ); ?>
				</button>
			</form>
		</div>
	</div>
	<?php
	return;
}

$rest_url = esc_url_raw( rest_url( 'wpkq/v1/queue?slug=' . rawurlencode( $venue_slug ) ) );
?>
<div
	<?php echo $block_props; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
	data-venue-slug="<?php echo esc_attr( $venue_slug ); ?>"
	data-rest-url="<?php echo esc_attr( $rest_url ); ?>"
	data-advance-url="<?php echo esc_attr( esc_url_raw( rest_url( 'wpkq/v1/queue/advance' ) ) ); ?>"
	data-actions-url="<?php echo esc_attr( esc_url_raw( rest_url( 'wpkq/v1/actions' ) ) ); ?>"
	data-ticker-queue-limit="<?php echo esc_attr( $ticker_queue_limit ); ?>"
	data-seed-url="<?php echo esc_attr( esc_url_raw( rest_url( 'wpkq/v1/queue/seed' ) ) ); ?>"
>
	<?php if ( in_array( wp_get_environment_type(), [ 'local', 'development' ], true ) ) : ?>
	<div class="wpkq-monitor__dev-tools">
		<button type="button" class="wpkq-monitor__seed-btn" id="wpkq-seed-btn">
			<?php esc_html_e( 'Load Fake Singers', 'wp-karaoke' ); ?>
		</button>
	</div>
	<?php endif; ?>

	<div class="wpkq-monitor__player-wrap">
		<div class="wpkq-monitor__welcome" id="wpkq-welcome">
			<?php if ( ! empty( trim( $content ) ) ) : ?>
				<?php echo $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<?php else : ?>
				<p class="wpkq-monitor__welcome-text">
					<?php
					printf(
						/* translators: %s: venue name */
						esc_html__( 'Welcome to %s Karaoke Night!', 'wp-karaoke' ),
						esc_html( $venue->post_title )
					);
					?>
				</p>
			<?php endif; ?>
		</div>
		<div id="wpkq-yt-player"></div>
	</div>

	<div class="wpkq-monitor__ticker-wrap">
		<div class="wpkq-monitor__ticker">
			<div class="wpkq-monitor__ticker-inner">
				<!-- Populated by view.js -->
			</div>
		</div>
	</div>
</div>
