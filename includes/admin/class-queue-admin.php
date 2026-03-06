<?php
/**
 * Queue Admin
 *
 * @package WPKQ\Admin
 */

namespace WPKQ\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Queue_Admin
 *
 * Registers and renders the admin queue management page.
 */
class Queue_Admin {

	/**
	 * Admin page slug.
	 *
	 * @var string
	 */
	const PAGE_SLUG = 'wpkq-manage-queue';

	/**
	 * Register hooks.
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'register_admin_page' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Register the admin menu page.
	 */
	public function register_admin_page() {
		add_submenu_page(
			null, // No parent — hidden from menu, accessible via URL.
			__( 'Manage Queue', 'wp-karaoke' ),
			__( 'Manage Queue', 'wp-karaoke' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Enqueue admin assets on the queue management page.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_assets( $hook ) {
		// Only enqueue on our page.
		if ( false === strpos( $hook, self::PAGE_SLUG ) ) {
			return;
		}

		// Enqueue SortableJS from CDN.
		wp_enqueue_script(
			'sortablejs',
			'https://cdn.jsdelivr.net/npm/sortablejs@1.15.2/Sortable.min.js',
			array(),
			'1.15.2',
			true
		);

		// Enqueue admin CSS.
		wp_enqueue_style(
			'wpkq-admin',
			WPKQ_PLUGIN_URL . 'dist/css/admin.css',
			array(),
			WPKQ_VERSION
		);

		// Enqueue admin JS.
		wp_enqueue_script(
			'wpkq-admin',
			WPKQ_PLUGIN_URL . 'dist/js/admin.js',
			array( 'sortablejs' ),
			WPKQ_VERSION,
			true
		);

		// Pass data to JS.
		$slug = isset( $_GET['slug'] ) ? sanitize_title( wp_unslash( $_GET['slug'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification

		wp_localize_script(
			'wpkq-admin',
			'wpkqAdmin',
			array(
				'apiBase'   => esc_url_raw( rest_url( 'wpkq/v1/queue' ) ),
				'nonce'     => wp_create_nonce( 'wp_rest' ),
				'venueSlug' => $slug,
				'i18n'      => array(
					'confirmFinished' => __( 'Mark this singer as finished? This cannot be undone.', 'wp-karaoke' ),
					'confirmDelete'   => __( 'Remove this singer from the queue? This cannot be undone.', 'wp-karaoke' ),
					'saving'          => __( 'Saving...', 'wp-karaoke' ),
					'loading'         => __( 'Loading...', 'wp-karaoke' ),
					'saveError'       => __( 'Error saving queue. Please try again.', 'wp-karaoke' ),
					'loadError'       => __( 'Error loading queue. Please refresh the page.', 'wp-karaoke' ),
				),
			)
		);
	}

	/**
	 * Render the admin page.
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'wp-karaoke' ) );
		}

		$slug = isset( $_GET['slug'] ) ? sanitize_title( wp_unslash( $_GET['slug'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification

		if ( empty( $slug ) ) {
			wp_die( esc_html__( 'No venue specified.', 'wp-karaoke' ) );
		}

		// Validate venue slug.
		$posts = get_posts(
			array(
				'name'           => $slug,
				'post_type'      => 'karaoke-venue',
				'post_status'    => 'publish',
				'posts_per_page' => 1,
			)
		);

		if ( empty( $posts ) ) {
			wp_die( esc_html__( 'Invalid venue.', 'wp-karaoke' ) );
		}

		$venue = $posts[0];
		?>
		<div class="wpkq-admin-wrap wrap" id="wpkq-app">
			<div class="wpkq-header">
				<h1 class="wpkq-title">
					<span class="wpkq-title-icon">🎤</span>
					<?php
					printf(
						/* translators: %s: Venue name */
						esc_html__( 'Queue: %s', 'wp-karaoke' ),
						esc_html( $venue->post_title )
					);
					?>
				</h1>
				<div class="wpkq-header-right">
					<button class="wpkq-btn wpkq-btn--primary wpkq-btn--add-singer" id="wpkq-add-singer-btn">
						+ <?php esc_html_e( 'Add Singer', 'wp-karaoke' ); ?>
					</button>
					<div class="wpkq-save-status" id="wpkq-save-status" aria-live="polite"></div>
				</div>
			</div>

			<div class="wpkq-loading" id="wpkq-loading">
				<span class="wpkq-spinner"></span>
				<?php esc_html_e( 'Loading queue...', 'wp-karaoke' ); ?>
			</div>

			<div class="wpkq-content" id="wpkq-content" style="display:none;">

				<!-- On Stage Section -->
				<section class="wpkq-section wpkq-section--on-stage" id="wpkq-on-stage-section" style="display:none;">
					<h2 class="wpkq-section-title">
						<span class="wpkq-badge wpkq-badge--on-stage">🎤</span>
						<?php esc_html_e( 'On Stage', 'wp-karaoke' ); ?>
					</h2>
					<div class="wpkq-table-wrap">
						<table class="wpkq-table" id="wpkq-on-stage-table">
							<thead>
								<tr>
									<th class="wpkq-col-name"><?php esc_html_e( 'Name', 'wp-karaoke' ); ?></th>
									<th class="wpkq-col-song"><?php esc_html_e( 'Song', 'wp-karaoke' ); ?></th>
									<th class="wpkq-col-artist"><?php esc_html_e( 'Artist', 'wp-karaoke' ); ?></th>
									<th class="wpkq-col-time"><?php esc_html_e( 'Signed Up', 'wp-karaoke' ); ?></th>
									<th class="wpkq-col-actions"><?php esc_html_e( 'Actions', 'wp-karaoke' ); ?></th>
								</tr>
							</thead>
							<tbody id="wpkq-on-stage-tbody">
								<!-- Populated by JS -->
							</tbody>
						</table>
					</div>
				</section>

				<!-- Active Queue Table -->
				<section class="wpkq-section">
					<h2 class="wpkq-section-title">
						<span class="wpkq-badge wpkq-badge--active" id="wpkq-queue-count">0</span>
						<?php esc_html_e( 'In The Queue', 'wp-karaoke' ); ?>
						<button class="wpkq-btn wpkq-btn--refresh" id="wpkq-refresh-btn" aria-label="<?php esc_attr_e( 'Refresh queue', 'wp-karaoke' ); ?>">↻ <?php esc_html_e( 'Refresh', 'wp-karaoke' ); ?></button>
					</h2>
					<p class="wpkq-section-hint"><?php esc_html_e( 'Drag rows to reorder the queue.', 'wp-karaoke' ); ?></p>

					<div class="wpkq-table-wrap">
						<table class="wpkq-table" id="wpkq-queue-table">
							<thead>
								<tr>
									<th class="wpkq-col-drag" aria-label="<?php esc_attr_e( 'Drag to reorder', 'wp-karaoke' ); ?>"></th>
									<th class="wpkq-col-name"><?php esc_html_e( 'Name', 'wp-karaoke' ); ?></th>
									<th class="wpkq-col-song"><?php esc_html_e( 'Song', 'wp-karaoke' ); ?></th>
									<th class="wpkq-col-artist"><?php esc_html_e( 'Artist', 'wp-karaoke' ); ?></th>
									<th class="wpkq-col-time"><?php esc_html_e( 'Signed Up', 'wp-karaoke' ); ?></th>
									<th class="wpkq-col-actions"><?php esc_html_e( 'Actions', 'wp-karaoke' ); ?></th>
								</tr>
							</thead>
							<tbody id="wpkq-queue-tbody">
								<!-- Populated by JS -->
							</tbody>
						</table>
						<p class="wpkq-empty-msg" id="wpkq-queue-empty" style="display:none;">
							<?php esc_html_e( 'No singers in the queue yet.', 'wp-karaoke' ); ?>
						</p>
					</div>
				</section>

				<!-- KJ Received Table -->
				<section class="wpkq-section">
					<h2 class="wpkq-section-title">
						<span class="wpkq-badge wpkq-badge--pending" id="wpkq-pending-count">0</span>
						<?php esc_html_e( 'New Sign-Ups', 'wp-karaoke' ); ?>
					</h2>

					<div class="wpkq-table-wrap">
						<table class="wpkq-table" id="wpkq-pending-table">
							<thead>
								<tr>
									<th class="wpkq-col-name"><?php esc_html_e( 'Name', 'wp-karaoke' ); ?></th>
									<th class="wpkq-col-song"><?php esc_html_e( 'Song', 'wp-karaoke' ); ?></th>
									<th class="wpkq-col-artist"><?php esc_html_e( 'Artist', 'wp-karaoke' ); ?></th>
									<th class="wpkq-col-time"><?php esc_html_e( 'Signed Up', 'wp-karaoke' ); ?></th>
									<th class="wpkq-col-actions"><?php esc_html_e( 'Actions', 'wp-karaoke' ); ?></th>
								</tr>
							</thead>
							<tbody id="wpkq-pending-tbody">
								<!-- Populated by JS -->
							</tbody>
						</table>
						<p class="wpkq-empty-msg" id="wpkq-pending-empty" style="display:none;">
							<?php esc_html_e( 'No new sign-ups.', 'wp-karaoke' ); ?>
						</p>
					</div>
				</section>

				<!-- Finished Table -->
				<section class="wpkq-section">
					<h2 class="wpkq-section-title">
						<span class="wpkq-badge wpkq-badge--finished" id="wpkq-finished-count">0</span>
						<?php esc_html_e( 'Finished', 'wp-karaoke' ); ?>
					</h2>
					<div class="wpkq-table-wrap">
						<table class="wpkq-table" id="wpkq-finished-table">
							<thead>
								<tr>
									<th class="wpkq-col-name"><?php esc_html_e( 'Name', 'wp-karaoke' ); ?></th>
									<th class="wpkq-col-song"><?php esc_html_e( 'Song', 'wp-karaoke' ); ?></th>
									<th class="wpkq-col-artist"><?php esc_html_e( 'Artist', 'wp-karaoke' ); ?></th>
									<th class="wpkq-col-time"><?php esc_html_e( 'Signed Up', 'wp-karaoke' ); ?></th>
									<th class="wpkq-col-actions"><?php esc_html_e( 'Actions', 'wp-karaoke' ); ?></th>
								</tr>
							</thead>
							<tbody id="wpkq-finished-tbody">
								<!-- Populated by JS -->
							</tbody>
						</table>
						<p class="wpkq-empty-msg" id="wpkq-finished-empty" style="display:none;">
							<?php esc_html_e( 'No finished singers yet.', 'wp-karaoke' ); ?>
						</p>
					</div>
				</section>

			</div><!-- /.wpkq-content -->

			<!-- Add Singer Modal -->
			<div class="wpkq-modal-overlay" id="wpkq-add-modal" role="dialog" aria-modal="true" aria-labelledby="wpkq-add-modal-title" style="display:none;">
				<div class="wpkq-modal">
					<div class="wpkq-modal-header">
						<h2 class="wpkq-modal-title" id="wpkq-add-modal-title"><?php esc_html_e( 'Add Singer', 'wp-karaoke' ); ?></h2>
						<button class="wpkq-modal-close" id="wpkq-add-modal-close" aria-label="<?php esc_attr_e( 'Close', 'wp-karaoke' ); ?>">&times;</button>
					</div>
					<div class="wpkq-modal-body">
						<div class="wpkq-field">
							<label for="wpkq-add-name"><?php esc_html_e( 'Singer Name', 'wp-karaoke' ); ?></label>
							<input type="text" id="wpkq-add-name" maxlength="50" autocomplete="off">
						</div>
						<div class="wpkq-field">
							<label for="wpkq-add-song"><?php esc_html_e( 'Song', 'wp-karaoke' ); ?></label>
							<input type="text" id="wpkq-add-song" maxlength="50" autocomplete="off">
						</div>
						<div class="wpkq-field">
							<label for="wpkq-add-artist"><?php esc_html_e( 'Artist', 'wp-karaoke' ); ?></label>
							<input type="text" id="wpkq-add-artist" maxlength="50" autocomplete="off">
						</div>
						<div class="wpkq-field">
							<label for="wpkq-add-youtube-url"><?php esc_html_e( 'YouTube URL', 'wp-karaoke' ); ?></label>
							<input type="url" id="wpkq-add-youtube-url" autocomplete="off" placeholder="https://www.youtube.com/watch?v=...">
						</div>
					</div>
					<div class="wpkq-modal-footer">
						<button class="wpkq-btn wpkq-btn--secondary" id="wpkq-add-modal-cancel"><?php esc_html_e( 'Cancel', 'wp-karaoke' ); ?></button>
						<button class="wpkq-btn wpkq-btn--primary" id="wpkq-add-modal-save"><?php esc_html_e( 'Add to Queue', 'wp-karaoke' ); ?></button>
					</div>
				</div>
			</div>

			<!-- Edit Modal -->
			<div class="wpkq-modal-overlay" id="wpkq-modal" role="dialog" aria-modal="true" aria-labelledby="wpkq-modal-title" style="display:none;">
				<div class="wpkq-modal">
					<div class="wpkq-modal-header">
						<h2 class="wpkq-modal-title" id="wpkq-modal-title"><?php esc_html_e( 'Edit Singer', 'wp-karaoke' ); ?></h2>
						<button class="wpkq-modal-close" id="wpkq-modal-close" aria-label="<?php esc_attr_e( 'Close', 'wp-karaoke' ); ?>">&times;</button>
					</div>
					<div class="wpkq-modal-body">
						<input type="hidden" id="wpkq-edit-index" value="">
						<div class="wpkq-field">
							<label for="wpkq-edit-name"><?php esc_html_e( 'Singer Name', 'wp-karaoke' ); ?></label>
							<input type="text" id="wpkq-edit-name" maxlength="50" autocomplete="off">
						</div>
						<div class="wpkq-field">
							<label for="wpkq-edit-song"><?php esc_html_e( 'Song', 'wp-karaoke' ); ?></label>
							<input type="text" id="wpkq-edit-song" maxlength="50" autocomplete="off">
						</div>
						<div class="wpkq-field">
							<label for="wpkq-edit-artist"><?php esc_html_e( 'Artist', 'wp-karaoke' ); ?></label>
							<input type="text" id="wpkq-edit-artist" maxlength="50" autocomplete="off">
						</div>
						<div class="wpkq-field">
							<label for="wpkq-edit-youtube-url"><?php esc_html_e( 'YouTube URL', 'wp-karaoke' ); ?></label>
							<input type="url" id="wpkq-edit-youtube-url" autocomplete="off" placeholder="https://www.youtube.com/watch?v=...">
						</div>
					</div>
					<div class="wpkq-modal-footer">
						<button class="wpkq-btn wpkq-btn--secondary" id="wpkq-modal-cancel"><?php esc_html_e( 'Cancel', 'wp-karaoke' ); ?></button>
						<button class="wpkq-btn wpkq-btn--primary" id="wpkq-modal-save"><?php esc_html_e( 'Save Changes', 'wp-karaoke' ); ?></button>
					</div>
				</div>
			</div>

		</div><!-- /.wpkq-admin-wrap -->
		<?php
	}
}
