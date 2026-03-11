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

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WPKQ_VERSION', '1.0.0' );
define( 'WPKQ_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WPKQ_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'WPKQ_PLUGIN_INCLUDES_DIR', WPKQ_PLUGIN_DIR . 'includes/' );

// Load Composer autoloader if available.
if ( file_exists( WPKQ_PLUGIN_DIR . 'vendor/autoload.php' ) ) {
	require_once WPKQ_PLUGIN_DIR . 'vendor/autoload.php';
}

// Load plugin classes.
$files = [
	'post-type/class-venue-post-type.php',
	'rest/class-queue-rest-controller.php',
	'rest/class-actions-rest-controller.php',
	'admin/class-queue-admin.php',
	'blocks/class-now-playing-block.php',
	'blocks/class-singer-signup-block.php',
];

foreach ( $files as $file ) {
	require_once WPKQ_PLUGIN_INCLUDES_DIR . $file;
}

// Initialize the plugin.
\WPKQ\Core::instance()->init();
