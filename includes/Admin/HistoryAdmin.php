<?php
/**
 * Night History Admin
 *
 * @package WPKQ\Admin
 */

namespace WPKQ\Admin;

use WPKQ\PostType\Night;
use WPKQ\PostType\Venue;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class HistoryAdmin
 *
 * Renders the admin-only Night History page for a venue: totals, top songs,
 * top artists, regulars, and a list of past nights.
 */
class HistoryAdmin {

	/**
	 * Admin page slug.
	 *
	 * @var string
	 */
	const PAGE_SLUG = 'wpkq-night-history';

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
	public function register(): void {
		add_action( 'admin_menu', [ $this, 'register_admin_page' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
	}

	/**
	 * Register the (hidden) admin page. Reached from the venue list's History link.
	 */
	public function register_admin_page(): void {
		add_submenu_page(
			'none', // No parent — hidden from menu, accessible via URL.
			__( 'Night History', 'wp-karaoke' ),
			__( 'Night History', 'wp-karaoke' ),
			'manage_options',
			self::PAGE_SLUG,
			[ $this, 'render_page' ]
		);
	}

	/**
	 * Add the small amount of layout CSS the page needs.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_assets( $hook ): void {
		if ( false === strpos( (string) $hook, self::PAGE_SLUG ) ) {
			return;
		}

		wp_add_inline_style(
			'common',
			'.wpkq-history-totals{display:flex;flex-wrap:wrap;gap:12px;margin:16px 0}'
			. '.wpkq-history-total{background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:12px 16px;min-width:140px}'
			. '.wpkq-history-total strong{display:block;font-size:24px;line-height:1.3}'
			. '.wpkq-history-lists{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:16px;margin:16px 0}'
			. '.wpkq-history-lists h2,.wpkq-history-nights h2{font-size:16px;margin:0 0 8px}'
			. '.wpkq-history-nights details summary{cursor:pointer}'
			. '.wpkq-history-nights ol{margin:8px 0 8px 20px}'
			. '.wpkq-history-count{text-align:right;width:60px}'
		);
	}

	/**
	 * Format a number of seconds as "m:ss", or an em dash when unknown.
	 *
	 * @param int|null $seconds Seconds.
	 * @return string Formatted duration.
	 */
	private function format_duration( ?int $seconds ): string {
		if ( null === $seconds ) {
			return '—';
		}

		return sprintf( '%d:%02d', intdiv( $seconds, 60 ), $seconds % 60 );
	}

	/**
	 * Render the admin page.
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'wp-karaoke' ) );
		}

		$input = filter_var_array(
			$_GET, // phpcs:ignore WordPress.Security.NonceVerification
			[
				'slug'    => [
					'filter'  => FILTER_CALLBACK,
					'options' => 'sanitize_title',
				],
				'deleted' => FILTER_VALIDATE_INT,
			]
		);

		$slug  = (string) ( $input['slug'] ?? '' );
		$venue = $slug ? Venue::get_queue_by_slug( $slug ) : null;

		if ( ! $venue || is_wp_error( $venue ) ) {
			wp_die( esc_html__( 'Invalid venue.', 'wp-karaoke' ) );
		}

		$venue_id = (int) $venue['post_id'];

		// Pick up snapshots saved before night history existed.
		Night::backfill_from_archives( $venue_id );

		$nights    = Night::get_nights( $venue_id );
		$stats     = Night::get_stats( $nights );
		$queue_url = add_query_arg(
			[
				'page' => QueueAdmin::PAGE_SLUG,
				'slug' => $slug,
			],
			admin_url( 'admin.php' )
		);
		?>
		<div class="wrap wpkq-history">
			<h1>
				<?php
				printf(
					/* translators: %s: Venue name */
					esc_html__( 'Night History: %s', 'wp-karaoke' ),
					esc_html( get_the_title( $venue_id ) )
				);
				?>
			</h1>
			<p><a href="<?php echo esc_url( $queue_url ); ?>">&larr; <?php esc_html_e( 'Back to Manage Queue', 'wp-karaoke' ); ?></a></p>

			<?php if ( ! empty( $input['deleted'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Night deleted.', 'wp-karaoke' ); ?></p></div>
			<?php endif; ?>

			<?php if ( empty( $nights ) ) : ?>
				<p><?php esc_html_e( 'No nights recorded yet. A night is saved here each time you click Reset Queue after singers have finished.', 'wp-karaoke' ); ?></p>
			</div>
				<?php
				return;
			endif;
			?>

			<div class="wpkq-history-totals">
				<?php
				$totals = [
					__( 'Nights', 'wp-karaoke' )            => number_format_i18n( $stats['nights'] ),
					__( 'Performances', 'wp-karaoke' )      => number_format_i18n( $stats['performances'] ),
					__( 'Different singers', 'wp-karaoke' ) => number_format_i18n( $stats['unique_singers'] ),
					__( 'Avg song length', 'wp-karaoke' )   => $this->format_duration( $stats['avg_song_seconds'] ),
				];
				foreach ( $totals as $label => $value ) :
					?>
					<div class="wpkq-history-total">
						<strong><?php echo esc_html( $value ); ?></strong>
						<?php echo esc_html( $label ); ?>
					</div>
				<?php endforeach; ?>
			</div>

			<div class="wpkq-history-lists">
				<div>
					<h2><?php esc_html_e( 'Top Songs', 'wp-karaoke' ); ?></h2>
					<table class="widefat striped">
						<tbody>
						<?php foreach ( $stats['top_songs'] as $row ) : ?>
							<tr>
								<td>
									<?php echo esc_html( $row['song'] ); ?>
									<?php if ( '' !== $row['artist'] ) : ?>
										<br><span class="description"><?php echo esc_html( $row['artist'] ); ?></span>
									<?php endif; ?>
								</td>
								<td class="wpkq-history-count"><?php echo esc_html( number_format_i18n( $row['count'] ) ); ?></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				</div>

				<div>
					<h2><?php esc_html_e( 'Top Artists', 'wp-karaoke' ); ?></h2>
					<table class="widefat striped">
						<tbody>
						<?php foreach ( $stats['top_artists'] as $row ) : ?>
							<tr>
								<td><?php echo esc_html( $row['artist'] ); ?></td>
								<td class="wpkq-history-count"><?php echo esc_html( number_format_i18n( $row['count'] ) ); ?></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				</div>

				<div>
					<h2><?php esc_html_e( 'Regulars', 'wp-karaoke' ); ?></h2>
					<?php if ( empty( $stats['regulars'] ) ) : ?>
						<p class="description"><?php esc_html_e( 'Singers who come on more than one night will show up here.', 'wp-karaoke' ); ?></p>
					<?php else : ?>
						<table class="widefat striped">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Singer', 'wp-karaoke' ); ?></th>
									<th class="wpkq-history-count"><?php esc_html_e( 'Nights', 'wp-karaoke' ); ?></th>
									<th class="wpkq-history-count"><?php esc_html_e( 'Songs', 'wp-karaoke' ); ?></th>
								</tr>
							</thead>
							<tbody>
							<?php foreach ( $stats['regulars'] as $row ) : ?>
								<tr>
									<td><?php echo esc_html( $row['name'] ); ?></td>
									<td class="wpkq-history-count"><?php echo esc_html( number_format_i18n( $row['nights'] ) ); ?></td>
									<td class="wpkq-history-count"><?php echo esc_html( number_format_i18n( $row['performances'] ) ); ?></td>
								</tr>
							<?php endforeach; ?>
							</tbody>
						</table>
					<?php endif; ?>
				</div>
			</div>

			<div class="wpkq-history-nights">
				<h2><?php esc_html_e( 'Nights', 'wp-karaoke' ); ?></h2>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Night', 'wp-karaoke' ); ?></th>
							<th><?php esc_html_e( 'Songs', 'wp-karaoke' ); ?></th>
							<th><?php esc_html_e( 'Singers', 'wp-karaoke' ); ?></th>
							<th><?php esc_html_e( 'Avg song', 'wp-karaoke' ); ?></th>
							<th><?php esc_html_e( 'Avg wait in queue', 'wp-karaoke' ); ?></th>
							<th><?php esc_html_e( 'Avg time to approve', 'wp-karaoke' ); ?></th>
							<th><?php esc_html_e( 'Used status card', 'wp-karaoke' ); ?></th>
							<th><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'wp-karaoke' ); ?></span></th>
						</tr>
					</thead>
					<tbody>
					<?php
					foreach ( $nights as $night ) :
						$metrics = Night::get_night_metrics( $night['summary'] );
						?>
						<tr>
							<td>
								<details>
									<summary>
										<strong><?php echo esc_html( wp_date( get_option( 'date_format' ), $metrics['started'] ) ); ?></strong>
										<span class="description">
											<?php
											echo esc_html(
												wp_date( get_option( 'time_format' ), $metrics['started'] )
												. ' – '
												. wp_date( get_option( 'time_format' ), $metrics['ended'] )
											);
											?>
										</span>
									</summary>
									<ol>
										<?php foreach ( $night['summary']['performances'] as $performance ) : ?>
											<li>
												<strong><?php echo esc_html( $performance['singer_name'] ); ?></strong>
												— <?php echo esc_html( $performance['song'] ); ?>
												<?php if ( '' !== $performance['artist'] ) : ?>
													<span class="description">(<?php echo esc_html( $performance['artist'] ); ?>)</span>
												<?php endif; ?>
											</li>
										<?php endforeach; ?>
									</ol>
									<?php if ( $metrics['unfinished'] ) : ?>
										<p class="description">
											<?php
											printf(
												/* translators: %d: number of signups that never sang */
												esc_html( _n( '%d signup was still waiting at reset.', '%d signups were still waiting at reset.', $metrics['unfinished'], 'wp-karaoke' ) ),
												(int) $metrics['unfinished']
											);
											?>
										</p>
									<?php endif; ?>
								</details>
							</td>
							<td><?php echo esc_html( number_format_i18n( $metrics['performances'] ) ); ?></td>
							<td><?php echo esc_html( number_format_i18n( $metrics['singers'] ) ); ?></td>
							<td><?php echo esc_html( $this->format_duration( $metrics['avg_song_seconds'] ) ); ?></td>
							<td><?php echo esc_html( $this->format_duration( $metrics['avg_wait_seconds'] ) ); ?></td>
							<td><?php echo esc_html( $this->format_duration( $metrics['avg_approval_seconds'] ) ); ?></td>
							<td><?php echo esc_html( number_format_i18n( $metrics['status_card_users'] ) ); ?></td>
							<td>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return window.confirm( '<?php echo esc_js( __( 'Delete this night from history? This cannot be undone.', 'wp-karaoke' ) ); ?>' );">
									<?php wp_nonce_field( 'wpkq_delete_night' ); ?>
									<input type="hidden" name="action" value="wpkq_delete_night">
									<input type="hidden" name="night_id" value="<?php echo esc_attr( $night['id'] ); ?>">
									<input type="hidden" name="slug" value="<?php echo esc_attr( $slug ); ?>">
									<button type="submit" class="button-link button-link-delete">
										<?php esc_html_e( 'Delete', 'wp-karaoke' ); ?>
									</button>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<p class="description"><?php esc_html_e( 'Times shown as minutes:seconds. "Avg wait in queue" runs from KJ approval to going on stage.', 'wp-karaoke' ); ?></p>
			</div>
		</div>
		<?php
	}
}
