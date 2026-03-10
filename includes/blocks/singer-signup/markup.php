<?php
/**
 * Singer Signup Block — server-side render.
 *
 * @package WPKQ
 *
 * @var array    $attributes Block attributes.
 * @var string   $content    Inner block content (unused).
 * @var WP_Block $block      Block instance.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$venue_id = absint( $attributes['venueId'] ?? 0 );

if ( ! $venue_id ) {
	// Editor placeholder — no venue selected yet.
	echo '<div class="wpkq-signup wpkq-signup--unconfigured"><p>'
		. esc_html__( 'Select a venue in the block settings panel.', 'wp-karaoke' )
		. '</p></div>';
	return;
}

$venue = get_post( $venue_id );

if ( ! $venue || 'karaoke-venue' !== $venue->post_type || 'publish' !== $venue->post_status ) {
	echo '<div class="wpkq-signup wpkq-signup--error"><p>'
		. esc_html__( 'Venue not found.', 'wp-karaoke' )
		. '</p></div>';
	return;
}

$venue_name   = get_the_title( $venue );
$signup_url   = esc_url( rest_url( 'wpkq/v1/queue/signup' ) );
$venue_slug   = esc_attr( $venue->post_name );
$signups_open = (bool) get_post_meta( $venue->ID, \WPKQ\Post_Type\Venue_Post_Type::SIGNUPS_META_KEY, true );
$disabled     = $signups_open ? '' : 'disabled';
?>
<div
	class="wpkq-signup<?php echo $signups_open ? '' : ' wpkq-signup--closed'; ?>"
	data-signup-url="<?php echo $signup_url; ?>"
	data-venue-slug="<?php echo $venue_slug; ?>"
>
	<?php if ( ! $signups_open ) : ?>
	<p class="wpkq-signup__closed-notice" role="status">
		<?php esc_html_e( 'Signups are currently closed.', 'wp-karaoke' ); ?>
	</p>
	<?php endif; ?>
	<form class="wpkq-signup__form" novalidate>
		<div class="wpkq-signup__field">
			<label class="wpkq-signup__label" for="wpkq-signup-name">
				<?php esc_html_e( 'Your Name', 'wp-karaoke' ); ?>
				<span class="wpkq-signup__required" aria-hidden="true">*</span>
			</label>
			<input
				class="wpkq-signup__input"
				type="text"
				id="wpkq-signup-name"
				name="singer_name"
				maxlength="50"
				required
			/>
		</div>

		<div class="wpkq-signup__field">
			<label class="wpkq-signup__label" for="wpkq-signup-song">
				<?php esc_html_e( 'Song Title', 'wp-karaoke' ); ?>
				<span class="wpkq-signup__required" aria-hidden="true">*</span>
			</label>
			<input
				class="wpkq-signup__input"
				type="text"
				id="wpkq-signup-song"
				name="song"
				maxlength="50"
				required
			/>
		</div>

		<div class="wpkq-signup__field">
			<label class="wpkq-signup__label" for="wpkq-signup-artist">
				<?php esc_html_e( 'Artist', 'wp-karaoke' ); ?>
				<span class="wpkq-signup__required" aria-hidden="true">*</span>
			</label>
			<input
				class="wpkq-signup__input"
				type="text"
				id="wpkq-signup-artist"
				name="artist"
				maxlength="50"
				required
			/>
		</div>

		<p class="wpkq-signup__error" role="alert" hidden></p>

		<p></p>

		<p>
			<button class="wpkq-signup__submit" type="submit" <?php echo $disabled; ?>>
				<?php echo esc_html( sprintf( __( 'Sign Up to Sing at %s', 'wp-karaoke' ), $venue_name ) ); ?>
			</button>
		</p>

		<p>
			<strong><?php esc_html_e( 'No Mic Drops!', 'wp-karaoke' ); ?></strong>
		</p>
	</form>

	<div class="wpkq-signup__success" hidden>
		<p class="wpkq-signup__success-message">
			<?php esc_html_e( "You're signed up! Watch for your name on screen.", 'wp-karaoke' ); ?>
		</p>
		<button class="wpkq-signup__another" type="button">
			<?php esc_html_e( 'Sign Up Again', 'wp-karaoke' ); ?>
		</button>
	</div>
</div>
