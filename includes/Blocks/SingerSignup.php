<?php
/**
 * Singer Signup Block
 *
 * @package WPKQ\Blocks
 */

namespace WPKQ\Blocks;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SingerSignup
 *
 * Registers the public-facing singer signup block.
 */
class SingerSignup {

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
	}

	/**
	 * Register the block type.
	 */
	public function register_block() {
		register_block_type( WPKQ_PLUGIN_DIR . 'includes/Blocks/singer-signup/' );
	}
}
