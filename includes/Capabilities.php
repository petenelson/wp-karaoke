<?php
/**
 * Custom role and capability registration.
 *
 * @package WPKQ
 */

namespace WPKQ;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Capabilities
 *
 * Defines the custom capability and role for karaoke hosts.
 * Called on plugin activation and deactivation — not on every request,
 * because role data persists in the database.
 */
class Capabilities {

	/** Capability required to manage the karaoke queue. */
	const CAP = 'manage_karaoke_queue';

	/** Role slug for the Karaoke Host role. */
	const ROLE = 'karaoke_host';

	/** Role display name. */
	const ROLE_NAME = 'Karaoke Host';

	/**
	 * Register the custom role and grant the capability to administrators.
	 * Safe to call multiple times — skips role creation if it already exists.
	 */
	public static function register(): void {
		$admin = get_role( 'administrator' );
		if ( $admin ) {
			$admin->add_cap( self::CAP );
		}

		if ( ! get_role( self::ROLE ) ) {
			add_role(
				self::ROLE,
				self::ROLE_NAME,
				[
					'read'    => true,
					self::CAP => true,
				]
			);
		}
	}

	/**
	 * Remove the custom role and revoke the capability from administrators.
	 * Called on plugin deactivation.
	 */
	public static function remove(): void {
		remove_role( self::ROLE );

		$admin = get_role( 'administrator' );
		if ( $admin ) {
			$admin->remove_cap( self::CAP );
		}
	}
}
