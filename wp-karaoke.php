<?php
/**
 * Plugin Name: WP Karaoke Queue Manager
 * Plugin URI:  https://example.com/wp-karaoke
 * Description: Manage a karaoke queue and singer signups.
 * Version:     1.0.0
 * Author:      Your Name
 * License:     GPL-2.0-or-later
 * Text Domain: wp-karaoke
 *
 * @package WPKQ
 */

namespace WPKQ;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WPKQ_VERSION', '1.0.0' );
define( 'WPKQ_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WPKQ_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// Load Composer autoloader if available.
if ( file_exists( WPKQ_PLUGIN_DIR . 'vendor/autoload.php' ) ) {
	require_once WPKQ_PLUGIN_DIR . 'vendor/autoload.php';
}

// Load plugin classes.
require_once WPKQ_PLUGIN_DIR . 'includes/post-type/class-venue-post-type.php';
require_once WPKQ_PLUGIN_DIR . 'includes/rest/class-queue-rest-controller.php';
require_once WPKQ_PLUGIN_DIR . 'includes/admin/class-queue-admin.php';
require_once WPKQ_PLUGIN_DIR . 'includes/blocks/class-now-playing-block.php';

/**
 * Initialize the plugin.
 */
function wpkq_init() {
	$venue_post_type = new Post_Type\Venue_Post_Type();
	$venue_post_type->register();

	$rest_controller = new REST\Queue_Rest_Controller();
	$rest_controller->register();

	$queue_admin = new Admin\Queue_Admin();
	$queue_admin->register();

	$now_playing_block = new Blocks\Now_Playing_Block();
	$now_playing_block->register();
}
add_action( 'plugins_loaded', __NAMESPACE__ . '\\wpkq_init' );
