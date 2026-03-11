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
		PostType\Venue::instance()->register();
		REST\Queue::instance()->register();
		REST\Actions::instance()->register();
		Admin\QueueAdmin::instance()->register();
		Blocks\NowPlaying::instance()->register();
		Blocks\SingerSignup::instance()->register();
	}
}
