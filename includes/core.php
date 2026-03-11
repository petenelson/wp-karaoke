<?php
/**
 * Core plugin functionality.
 *
 * @package WPKQ\REST
 */

namespace WPKQ;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WPKQ\Blocks\Now_Playing_Block;

/**
 * Class Core
 *
 */
class Core {

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
	 * Initialize plugin components.
	 * 
	 * @return void
	 */
	public function init(): void {
		Post_Type\Venue_Post_Type::instance()->register();
		REST\Queue_Rest_Controller::instance()->register();
		REST\Actions_Rest_Controller::instance()->register();
		Admin\Queue_Admin::instance()->register();
		Blocks\Now_Playing_Block::instance()->register();
		Blocks\Singer_Signup_Block::instance()->register();
	}
}
