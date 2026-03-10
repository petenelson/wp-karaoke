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
 * Class Singer_Signup_Block
 *
 * Registers the public-facing singer signup block.
 */
class Singer_Signup_Block {

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
		register_block_type( WPKQ_PLUGIN_DIR . 'includes/blocks/singer-signup/' );
	}
}
