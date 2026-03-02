<?php
/**Template for displaying a single karaoke queue */

defined( 'ABSPATH' ) || exit;

get_header();
while ( have_posts() ) {
	the_post();
	?>
	<div id="wpkq-queue-app" class="wpkq-queue-container">
		<h1 class="wpkq-venue-name"><?php the_title(); ?></h1>
		<div id="wpkq-queue-content" class="wpkq-queue-content">
			<p id="wpkq-queue-loading"><?php esc_html_e( 'Loading queue...', 'wp-karaoke' ); ?></p>
			<p id="wpkq-queue-error" style="display:none;"><?php esc_html_e( 'Error loading queue. Please try again later.', 'wp-karaoke' ); ?></p>
			<div id="wpkq-queue-empty" style="display:none;"><?php esc_html_e( 'The queue is currently empty.', 'wp-karaoke' ); ?></p>
			<div id="wpkq-queue-details" style="display:none;">
				<h2><?php esc_html_e( 'Current Queue', 'wp-karaoke' ); ?></h2>
				<p id="wpkq-queue-count"></p>
				<table id="wpkq-queue-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Singer', 'wp-karaoke' ); ?></th>
							<th><?php esc_html_e( 'Song', 'wp-karaoke' ); ?></th>
							<th><?php esc_html_e( 'Artist', 'wp-karaoke' ); ?></th>
							<th><?php esc_html_e( 'Signed Up', 'wp-karaoke' ); ?></th>
							<th><?php esc_html_e( 'Actions', 'wp-karaoke' ); ?></th>
						</tr>
					</thead>
					<tbody id="wpkq-queue-tbody"></tbody>
				</table>
			</div>
		</div>
	</div>
	<?php
}
get_footer();
