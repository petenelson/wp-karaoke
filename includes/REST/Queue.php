<?php
/**
 * Queue REST Controller
 *
 * @package WPKQ\REST
 */

namespace WPKQ\REST;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WPKQ\PostType\Venue;
use WPKQ\Blocks\NowPlaying;

/**
 * Class Queue
 *
 * Handles REST API endpoints for the karaoke queue.
 */
class Queue {

	/**
	 * REST API namespace.
	 *
	 * @var string
	 */
	const NAMESPACE = 'wpkq/v1';

	/**
	 * REST API route.
	 *
	 * @var string
	 */
	const ROUTE = '/queue';

	/**
	 * Fallback song length (seconds) used for wait estimates before any songs have finished.
	 *
	 * @var int
	 */
	const DEFAULT_SONG_SECONDS = 300;

	/**
	 * Maximum number of status tokens accepted by the status endpoint.
	 *
	 * @var int
	 */
	const MAX_STATUS_TOKENS = 5;

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
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	/**
	 * Register REST routes.
	 */
	public function register_routes() {
		// GET /wpkq/v1/queue — fetch queue.
		// DELETE /wpkq/v1/queue — reset queue to empty.
		register_rest_route(
			self::NAMESPACE,
			self::ROUTE,
			[
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_queue' ],
					'permission_callback' => '__return_true',
					'args'                => $this->get_slug_arg(),
				],
				[
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => [ $this, 'reset_queue' ],
					'permission_callback' => [ $this, 'update_queue_permissions_check' ],
					'args'                => $this->get_slug_arg(),
				],
			]
		);

		// POST /wpkq/v1/queue/singer — add a singer.
		register_rest_route(
			self::NAMESPACE,
			self::ROUTE . '/singer',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'add_singer' ],
				'permission_callback' => [ $this, 'update_queue_permissions_check' ],
				'args'                => array_merge( $this->get_slug_arg(), $this->get_youtube_playable_arg() ),
			]
		);

		// PUT|PATCH /wpkq/v1/queue/singer/{id} — update a singer.
		// DELETE    /wpkq/v1/queue/singer/{id} — delete a singer.
		register_rest_route(
			self::NAMESPACE,
			self::ROUTE . '/singer/(?P<id>[A-Za-z0-9]+)',
			[
				[
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => [ $this, 'update_singer' ],
					'permission_callback' => [ $this, 'update_queue_permissions_check' ],
					'args'                => array_merge( $this->get_slug_arg(), $this->get_youtube_playable_arg() ),
				],
				[
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => [ $this, 'delete_singer' ],
					'permission_callback' => [ $this, 'update_queue_permissions_check' ],
					'args'                => $this->get_slug_arg(),
				],
			]
		);

		// POST /wpkq/v1/queue/reorder — reorder in-the-queue singers.
		register_rest_route(
			self::NAMESPACE,
			self::ROUTE . '/reorder',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'reorder_queue' ],
				'permission_callback' => [ $this, 'update_queue_permissions_check' ],
				'args'                => array_merge(
					$this->get_slug_arg(),
					[
						'order' => [
							'required'    => true,
							'type'        => 'array',
							'description' => __( 'Array of singer IDs in the desired queue order.', 'wp-karaoke' ),
						],
					]
				),
			]
		);

		// POST /wpkq/v1/queue/signup — public singer self-signup.
		register_rest_route(
			self::NAMESPACE,
			self::ROUTE . '/signup',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'signup_singer' ],
				'permission_callback' => '__return_true',
				'args'                => array_merge(
					$this->get_slug_arg(),
					[
						'singer_name' => [
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						],
						'song'        => [
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						],
						'artist'      => [
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						],
					]
				),
			]
		);

		// GET /wpkq/v1/queue/status — public status lookup for specific singer IDs.
		register_rest_route(
			self::NAMESPACE,
			self::ROUTE . '/status',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_singer_status' ],
				'permission_callback' => '__return_true',
				'args'                => array_merge(
					$this->get_slug_arg(),
					[
						'tokens' => [
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
							'description'       => __( 'Comma-separated list of private status tokens returned by signup.', 'wp-karaoke' ),
						],
					]
				),
			]
		);

		// GET /wpkq/v1/queue/report — admin: queue, estimate log, and archives for analysis.
		register_rest_route(
			self::NAMESPACE,
			self::ROUTE . '/report',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_report' ],
				'permission_callback' => [ $this, 'update_queue_permissions_check' ],
				'args'                => $this->get_slug_arg(),
			]
		);

		// POST /wpkq/v1/queue/seed — load fake singers (when enabled in the Now Playing block).
		register_rest_route(
			self::NAMESPACE,
			self::ROUTE . '/seed',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'seed_queue' ],
				'permission_callback' => [ $this, 'seed_permissions_check' ],
				'args'                => [
					'slug' => [
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_title',
					],
				],
			]
		);

		// POST /wpkq/v1/queue/advance — monitor: advance to next singer.
		register_rest_route(
			self::NAMESPACE,
			self::ROUTE . '/advance',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'advance_singer' ],
				'permission_callback' => [ $this, 'advance_permissions_check' ],
				'args'                => [
					'slug' => [
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_title',
					],
					'id'   => [
						'required' => true,
						'type'     => 'string',
					],
				],
			]
		);
	}

	/**
	 * GET /wpkq/v1/queue
	 *
	 * Returns singer queue for a venue. Filters to in-the-queue only for public users.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_queue( \WP_REST_Request $request ) {
		$slug   = $request->get_param( 'slug' );
		$result = Venue::get_queue_by_slug( $slug );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$queue = array_merge( $result['queue'], Venue::get_new_singers( $result['post_id'] ) );

		// Filter to active singers only for non-logged-in users.
		if ( ! is_user_logged_in() ) {
			$queue = array_values(
				array_filter(
					$queue,
					function( $singer ) {
						return isset( $singer['status'] ) && in_array( $singer['status'], [ 'on-stage', 'in-the-queue' ], true );
					}
				)
			);

			// Sort: on-stage singer first.
			usort(
				$queue,
				function( $a, $b ) {
					$a_on_stage = isset( $a['status'] ) && 'on-stage' === $a['status'];
					$b_on_stage = isset( $b['status'] ) && 'on-stage' === $b['status'];
					return $b_on_stage <=> $a_on_stage;
				}
			);

			// Only return public fields.
			foreach ( $queue as &$singer ) {
				$singer = [
					'id'          => $singer['id'] ?? '',
					'singer_name' => $singer['singer_name'],
					'youtube_url' => $singer['youtube_url'] ?? '',
					'status'      => $singer['status'],
				];
			}
		}

		return rest_ensure_response(
			[
				'success' => true,
				'queue'   => $queue,
			]
		);
	}

	/**
	 * Check permissions for admin queue mutations.
	 *
	 * @return bool|\WP_Error
	 */
	public function update_queue_permissions_check() {
		if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
			return new \WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to update the queue.', 'wp-karaoke' ),
				[ 'status' => 403 ]
			);
		}
		return true;
	}

	/**
	 * POST /wpkq/v1/queue/singer
	 *
	 * Adds a new singer to the queue.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function add_singer( \WP_REST_Request $request ) {
		$slug   = $request->get_param( 'slug' );
		$result = Venue::get_queue_by_slug( $slug );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$post_id    = $result['post_id'];
		$queue      = $result['queue'];
		$new_singer = $this->sanitize_singer( $request->get_params() );

		if ( 'in-the-queue' === $new_singer['status'] && empty( $new_singer['queued_time'] ) ) {
			$new_singer['queued_time'] = time();
		}
		if ( 'on-stage' === $new_singer['status'] && empty( $new_singer['stage_time'] ) ) {
			$new_singer['stage_time'] = time();
		}

		$queue[] = $new_singer;

		$saved = Venue::save_queue( $post_id, $queue );

		if ( false === $saved ) {
			return new \WP_Error(
				'save_failed',
				__( 'Failed to save queue.', 'wp-karaoke' ),
				[ 'status' => 500 ]
			);
		}

		return $this->get_queue( $request );
	}

	/**
	 * PUT /wpkq/v1/queue/singer/{id}
	 *
	 * Updates fields on a single singer. Only provided fields are changed.
	 * When status is set to 'on-stage', any existing on-stage singer is reverted to 'in-the-queue'.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function update_singer( \WP_REST_Request $request ) {
		$slug   = $request->get_param( 'slug' );
		$id     = preg_replace( '/[^A-Za-z0-9]/', '', (string) $request->get_param( 'id' ) );
		$result = Venue::get_queue_by_slug( $slug );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$post_id           = $result['post_id'];
		$queue             = $result['queue'];
		$found             = false;
		$from_new_singer   = false;
		$new_status        = null;
		$valid_statuses    = [ 'kj-received', 'in-the-queue', 'on-stage', 'finished' ];
		$json_params       = $request->get_json_params() ?? [];

		foreach ( $queue as &$singer ) {
			if ( ( $singer['id'] ?? '' ) !== $id ) {
				continue;
			}

			if ( null !== $request->get_param( 'singer_name' ) ) {
				$singer['singer_name'] = $this->normalize_apostrophes( substr( sanitize_text_field( $request->get_param( 'singer_name' ) ), 0, 50 ) );
			}
			if ( null !== $request->get_param( 'song' ) ) {
				$singer['song'] = $this->normalize_apostrophes( substr( sanitize_text_field( $request->get_param( 'song' ) ), 0, 50 ) );
			}
			if ( null !== $request->get_param( 'artist' ) ) {
				$singer['artist'] = $this->normalize_apostrophes( substr( sanitize_text_field( $request->get_param( 'artist' ) ), 0, 50 ) );
			}
			if ( null !== $request->get_param( 'youtube_url' ) ) {
				$singer['youtube_url'] = esc_url_raw( $request->get_param( 'youtube_url' ) );
			}
			if ( array_key_exists( 'youtube_playable', $json_params ) ) {
				$val                       = $json_params['youtube_playable'];
				$singer['youtube_playable'] = is_null( $val ) ? null : (bool) $val;
			}
			if ( null !== $request->get_param( 'completed_time' ) ) {
				$singer['completed_time'] = absint( $request->get_param( 'completed_time' ) );
			}
			if ( null !== $request->get_param( 'status' ) ) {
				$status_param = $request->get_param( 'status' );
				if ( in_array( $status_param, $valid_statuses, true ) ) {
					if ( 'on-stage' === $status_param && 'on-stage' !== ( $singer['status'] ?? '' ) ) {
						$singer['stage_time'] = time();
					}
					if ( 'in-the-queue' === $status_param && empty( $singer['queued_time'] ) ) {
						$singer['queued_time'] = time();
					}
					$singer['status'] = $status_param;
					$new_status       = $status_param;
				}
			}

			$found = true;
			break;
		}
		unset( $singer );

		// If not found in the main queue, check pending new-singer meta entries.
		if ( ! $found ) {
			$new_singers = Venue::get_new_singers( $post_id );
			foreach ( $new_singers as $new_singer ) {
				if ( ( $new_singer['id'] ?? '' ) !== $id ) {
					continue;
				}

				if ( null !== $request->get_param( 'singer_name' ) ) {
					$new_singer['singer_name'] = $this->normalize_apostrophes( substr( sanitize_text_field( $request->get_param( 'singer_name' ) ), 0, 50 ) );
				}
				if ( null !== $request->get_param( 'song' ) ) {
					$new_singer['song'] = $this->normalize_apostrophes( substr( sanitize_text_field( $request->get_param( 'song' ) ), 0, 50 ) );
				}
				if ( null !== $request->get_param( 'artist' ) ) {
					$new_singer['artist'] = $this->normalize_apostrophes( substr( sanitize_text_field( $request->get_param( 'artist' ) ), 0, 50 ) );
				}
				if ( null !== $request->get_param( 'youtube_url' ) ) {
					$new_singer['youtube_url'] = esc_url_raw( $request->get_param( 'youtube_url' ) );
				}
				if ( array_key_exists( 'youtube_playable', $json_params ) ) {
					$val                            = $json_params['youtube_playable'];
					$new_singer['youtube_playable'] = is_null( $val ) ? null : (bool) $val;
				}
				if ( null !== $request->get_param( 'completed_time' ) ) {
					$new_singer['completed_time'] = absint( $request->get_param( 'completed_time' ) );
				}
				if ( null !== $request->get_param( 'status' ) ) {
					$status_param = $request->get_param( 'status' );
					if ( in_array( $status_param, $valid_statuses, true ) ) {
						if ( 'on-stage' === $status_param ) {
							$new_singer['stage_time'] = time();
						}
						if ( in_array( $status_param, [ 'in-the-queue', 'on-stage' ], true ) && empty( $new_singer['queued_time'] ) ) {
							$new_singer['queued_time'] = time();
						}
						$new_singer['status'] = $status_param;
						$new_status           = $status_param;
					}
				}

				if ( null !== $new_status && 'kj-received' !== $new_status ) {
					// Promote singer: move from individual meta into the main queue.
					$queue[] = $new_singer;
					Venue::save_queue( $post_id, $queue );
					Venue::delete_new_singer( $post_id, $id );
				} else {
					// Still pending: update the individual meta entry only.
					Venue::save_new_singer( $post_id, $new_singer );
				}

				$found           = true;
				$from_new_singer = true;
				break;
			}
		}

		if ( ! $found ) {
			return new \WP_Error(
				'singer_not_found',
				__( 'Singer not found in queue.', 'wp-karaoke' ),
				[ 'status' => 404 ]
			);
		}

		if ( ! $from_new_singer ) {
			// Revert any other on-stage singer when promoting a new one.
			if ( 'on-stage' === $new_status ) {
				foreach ( $queue as &$singer ) {
					if ( ( $singer['id'] ?? '' ) !== $id && 'on-stage' === ( $singer['status'] ?? '' ) ) {
						$singer['status'] = 'in-the-queue';
					}
				}
				unset( $singer );
			}

			$saved = Venue::save_queue( $post_id, $queue );

			if ( false === $saved ) {
				return new \WP_Error(
					'save_failed',
					__( 'Failed to save queue.', 'wp-karaoke' ),
					[ 'status' => 500 ]
				);
			}
		}

		return $this->get_queue( $request );
	}

	/**
	 * DELETE /wpkq/v1/queue/singer/{id}
	 *
	 * Removes a singer from the queue.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function delete_singer( \WP_REST_Request $request ) {
		$slug   = $request->get_param( 'slug' );
		$id     = preg_replace( '/[^A-Za-z0-9]/', '', (string) $request->get_param( 'id' ) );
		$result = Venue::get_queue_by_slug( $slug );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$post_id        = $result['post_id'];
		$queue          = $result['queue'];
		$original_count = count( $queue );

		$queue = array_values(
			array_filter(
				$queue,
				function ( $singer ) use ( $id ) {
					return ( $singer['id'] ?? '' ) !== $id;
				}
			)
		);

		if ( count( $queue ) === $original_count ) {
			// Singer was not in the main queue; check pending new-singer meta.
			$deleted = Venue::delete_new_singer( $post_id, $id );

			if ( ! $deleted ) {
				return new \WP_Error(
					'singer_not_found',
					__( 'Singer not found in queue.', 'wp-karaoke' ),
					[ 'status' => 404 ]
				);
			}

			return $this->get_queue( $request );
		}

		$saved = Venue::save_queue( $post_id, $queue );

		if ( false === $saved ) {
			return new \WP_Error(
				'save_failed',
				__( 'Failed to save queue.', 'wp-karaoke' ),
				[ 'status' => 500 ]
			);
		}

		return $this->get_queue( $request );
	}

	/**
	 * POST /wpkq/v1/queue/reorder
	 *
	 * Reorders the in-the-queue singers based on an array of IDs.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function reorder_queue( \WP_REST_Request $request ) {
		$slug   = $request->get_param( 'slug' );
		$order  = $request->get_param( 'order' );
		$result = Venue::get_queue_by_slug( $slug );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( ! is_array( $order ) ) {
			return new \WP_Error(
				'invalid_order',
				__( 'Order must be an array of IDs.', 'wp-karaoke' ),
				[ 'status' => 400 ]
			);
		}

		$post_id = $result['post_id'];
		$queue   = $result['queue'];

		// Sanitize the incoming IDs.
		$order = array_map(
			function ( $id ) {
				return preg_replace( '/[^A-Za-z0-9]/', '', (string) $id );
			},
			$order
		);

		// Split queue into in-the-queue and everything else.
		$in_queue = [];
		$others   = [];
		foreach ( $queue as $singer ) {
			if ( 'in-the-queue' === ( $singer['status'] ?? '' ) ) {
				$in_queue[ $singer['id'] ] = $singer;
			} else {
				$others[] = $singer;
			}
		}

		// Rebuild in-the-queue section in the requested order.
		$reordered = [];
		foreach ( $order as $id ) {
			if ( isset( $in_queue[ $id ] ) ) {
				$reordered[] = $in_queue[ $id ];
				unset( $in_queue[ $id ] );
			}
		}
		// Append any in-queue singers missing from the order array.
		foreach ( $in_queue as $singer ) {
			$reordered[] = $singer;
		}

		$new_queue = array_merge( $others, $reordered );
		$saved     = Venue::save_queue( $post_id, $new_queue );

		if ( false === $saved ) {
			return new \WP_Error(
				'save_failed',
				__( 'Failed to save queue.', 'wp-karaoke' ),
				[ 'status' => 500 ]
			);
		}

		return $this->get_queue( $request );
	}

	/**
	 * DELETE /wpkq/v1/queue
	 *
	 * Resets the queue to an empty array.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function reset_queue( \WP_REST_Request $request ) {
		$slug   = $request->get_param( 'slug' );
		$result = Venue::get_queue_by_slug( $slug );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$post_id = $result['post_id'];

		// Keep a snapshot of the night for later analysis before clearing.
		Venue::archive_queue( $post_id, array_merge( $result['queue'], Venue::get_new_singers( $post_id ) ) );
		Venue::prune_history( $post_id );

		$saved = Venue::save_queue( $post_id, [] );

		if ( false === $saved ) {
			return new \WP_Error(
				'save_failed',
				__( 'Failed to reset queue.', 'wp-karaoke' ),
				[ 'status' => 500 ]
			);
		}

		Venue::delete_all_new_singers( $post_id );

		return $this->get_queue( $request );
	}

	/**
	 * POST /wpkq/v1/queue/seed (only when enabled in the Now Playing block)
	 *
	 * Replaces the queue with the fake singers from NowPlaying::get_fake_singers(), for testing.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function seed_queue( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$slug   = $request->get_param( 'slug' );
		$result = Venue::get_queue_by_slug( $slug );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$post_id = $result['post_id'];
		$now     = time();

		$fake_singers = [];

		foreach ( NowPlaying::get_fake_singers() as $index => $fake_singer ) {
			$fake_singers[] = [
				'id'               => wp_generate_password( 10, false, false ),
				'singer_name'      => $fake_singer['singer_name'],
				'song'             => $fake_singer['song'],
				'artist'           => $fake_singer['artist'],
				'youtube_url'      => $fake_singer['youtube_url'],
				'youtube_playable' => null,
				'signup_time'      => $now - 3600 + ( $index * 300 ),
				'completed_time'   => 0,
				'stage_time'       => 0 === $index ? $now : 0,
				// The first fake singer starts on stage; the rest wait in the queue.
				'status'           => 0 === $index ? 'on-stage' : 'in-the-queue',
			];
		}

		Venue::delete_all_new_singers( $post_id );
		$saved = Venue::save_queue( $post_id, $fake_singers );

		if ( false === $saved ) {
			return new \WP_Error(
				'save_failed',
				__( 'Failed to seed queue.', 'wp-karaoke' ),
				[ 'status' => 500 ]
			);
		}

		return $this->get_queue( $request );
	}

	/**
	 * Normalize Unicode apostrophes to plain ASCII apostrophes.
	 *
	 * Mobile keyboards (e.g. iOS) insert U+2019 instead of a plain apostrophe.
	 * Depending on request encoding it may arrive as the actual character, as the
	 * six-character literal "’", or — after backslash-stripping — as "u2019".
	 * All three forms are converted to a plain apostrophe.
	 *
	 * @param string $text Input string.
	 * @return string Normalised string.
	 */
	private function normalize_apostrophes( string $text ): string {
		return str_replace(
			[ "\u{2019}", '\\u2019', 'u2019' ],
			"'",
			$text
		);
	}

	/**
	 * Sanitize a single singer record.
	 *
	 * @param array $singer Raw singer data.
	 * @return array Sanitized singer data.
	 */
	private function sanitize_singer( $singer ) {
		$valid_statuses = [ 'kj-received', 'in-the-queue', 'on-stage', 'finished' ];

		$youtube_playable = null;
		if ( array_key_exists( 'youtube_playable', $singer ) ) {
			$youtube_playable = is_null( $singer['youtube_playable'] ) ? null : (bool) $singer['youtube_playable'];
		}

		return [
			'id'               => isset( $singer['id'] ) ? preg_replace( '/[^A-Za-z0-9]/', '', $singer['id'] ) : '',
			'singer_name'      => isset( $singer['singer_name'] ) ? $this->normalize_apostrophes( substr( sanitize_text_field( $singer['singer_name'] ), 0, 50 ) ) : '',
			'song'             => isset( $singer['song'] ) ? $this->normalize_apostrophes( substr( sanitize_text_field( $singer['song'] ), 0, 50 ) ) : '',
			'artist'           => isset( $singer['artist'] ) ? $this->normalize_apostrophes( substr( sanitize_text_field( $singer['artist'] ), 0, 50 ) ) : '',
			'youtube_url'      => isset( $singer['youtube_url'] ) ? esc_url_raw( $singer['youtube_url'] ) : '',
			'youtube_playable' => $youtube_playable,
			'signup_time'      => isset( $singer['signup_time'] ) ? absint( $singer['signup_time'] ) : 0,
			'completed_time'   => isset( $singer['completed_time'] ) ? absint( $singer['completed_time'] ) : 0,
			'stage_time'       => isset( $singer['stage_time'] ) ? absint( $singer['stage_time'] ) : 0,
			'queued_time'      => isset( $singer['queued_time'] ) ? absint( $singer['queued_time'] ) : 0,
			'status'           => ( isset( $singer['status'] ) && in_array( $singer['status'], $valid_statuses, true ) )
				? $singer['status']
				: 'kj-received',
		];
	}

	/**
	 * Get the youtube_playable argument definition.
	 *
	 * @return array
	 */
	private function get_youtube_playable_arg() {
		return [
			'youtube_playable' => [
				'required'    => false,
				'type'        => [ 'boolean', 'null' ],
				'description' => __( 'Whether the YouTube video can be played in an iframe embed. Null means unchecked.', 'wp-karaoke' ),
			],
		];
	}

	/**
	 * Get the slug argument definition.
	 *
	 * @return array
	 */
	private function get_slug_arg() {
		return [
			'slug' => [
				'required'          => true,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_title',
				'validate_callback' => 'rest_validate_request_arg',
				'description'       => __( 'The slug of the karaoke venue.', 'wp-karaoke' ),
			],
		];
	}

	/**
	 * Look up a published venue and verify the request's monitor cookie for it.
	 *
	 * @param string $slug Venue slug.
	 * @return \WP_Post|\WP_Error The venue post on success, or an error.
	 */
	private function get_monitor_authorized_venue( string $slug ): \WP_Post|\WP_Error {
		$posts = get_posts(
			[
				'name'           => $slug,
				'post_type'      => 'karaoke-venue',
				'post_status'    => 'publish',
				'posts_per_page' => 1,
			]
		);

		if ( empty( $posts ) ) {
			return new \WP_Error(
				'invalid_venue',
				__( 'Venue not found.', 'wp-karaoke' ),
				[ 'status' => 404 ]
			);
		}

		$password    = get_post_meta( $posts[0]->ID, NowPlaying::PASSWORD_META_KEY, true );
		$cookie_name = NowPlaying::get_cookie_name( $slug );

		if (
			empty( $password ) ||
			! isset( $_COOKIE[ $cookie_name ] ) || // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			! hash_equals( md5( $password ), $_COOKIE[ $cookie_name ] ) // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_md5,WordPress.Security.ValidatedSanitizedInput
		) {
			return new \WP_Error(
				'rest_forbidden',
				__( 'Invalid monitor credentials.', 'wp-karaoke' ),
				[ 'status' => 403 ]
			);
		}

		return $posts[0];
	}

	/**
	 * Check permissions for the advance endpoint using the venue monitor cookie.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return bool|\WP_Error
	 */
	public function advance_permissions_check( \WP_REST_Request $request ) {
		$venue = $this->get_monitor_authorized_venue( (string) $request->get_param( 'slug' ) );

		return is_wp_error( $venue ) ? $venue : true;
	}

	/**
	 * Check permissions for the seed endpoint.
	 *
	 * Requires valid monitor credentials and the "Load Fake Singers" toggle
	 * to be enabled in the venue's Now Playing block.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return bool|\WP_Error
	 */
	public function seed_permissions_check( \WP_REST_Request $request ) {
		$venue = $this->get_monitor_authorized_venue( (string) $request->get_param( 'slug' ) );

		if ( is_wp_error( $venue ) ) {
			return $venue;
		}

		if ( '1' !== get_post_meta( $venue->ID, NowPlaying::SEED_ENABLED_META_KEY, true ) ) {
			return new \WP_Error(
				'rest_forbidden',
				__( 'Loading fake singers is not enabled for this venue.', 'wp-karaoke' ),
				[ 'status' => 403 ]
			);
		}

		return true;
	}

	/**
	 * POST /wpkq/v1/queue/signup
	 *
	 * Public endpoint for audience members to sign up to sing.
	 * Adds the singer with status 'kj-received'; the KJ reviews and promotes them.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function signup_singer( \WP_REST_Request $request ) {
		$slug   = $request->get_param( 'slug' );
		$result = Venue::get_queue_by_slug( $slug );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$new_singer = [
			'id'             => wp_generate_password( 10, false, false ),
			'singer_name'    => $this->normalize_apostrophes( substr( sanitize_text_field( $request->get_param( 'singer_name' ) ?? '' ), 0, 50 ) ),
			'song'           => $this->normalize_apostrophes( substr( sanitize_text_field( $request->get_param( 'song' ) ?? '' ), 0, 50 ) ),
			'artist'         => $this->normalize_apostrophes( substr( sanitize_text_field( $request->get_param( 'artist' ) ?? '' ), 0, 50 ) ),
			'youtube_url'    => '',
			'signup_time'    => time(),
			'completed_time' => 0,
			'stage_time'     => 0,
			'status'         => 'kj-received',
			// Private token for the status endpoint. Never returned by the public queue endpoint.
			'status_token'   => wp_generate_password( 20, false, false ),
		];

		if ( empty( $new_singer['singer_name'] ) ) {
			return new \WP_Error(
				'missing_name',
				__( 'Singer name is required.', 'wp-karaoke' ),
				[ 'status' => 400 ]
			);
		}

		$saved = Venue::save_new_singer( $result['post_id'], $new_singer );

		if ( false === $saved ) {
			return new \WP_Error(
				'save_failed',
				__( 'Failed to save signup.', 'wp-karaoke' ),
				[ 'status' => 500 ]
			);
		}

		return rest_ensure_response(
			[
				'success'      => true,
				'id'           => $new_singer['id'],
				'status_token' => $new_singer['status_token'],
			]
		);
	}

	/**
	 * GET /wpkq/v1/queue/status
	 *
	 * Returns status, queue position, and an estimated wait for the singers matching
	 * the given private status tokens (returned only to the browser that signed up).
	 * Public singer IDs are not accepted, so nobody can look up someone else's entry.
	 * Tokens that are not found are omitted from the response.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_singer_status( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$slug   = $request->get_param( 'slug' );
		$result = Venue::get_queue_by_slug( $slug );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$tokens = array_map(
			function ( $token ) {
				return preg_replace( '/[^A-Za-z0-9]/', '', $token );
			},
			explode( ',', (string) $request->get_param( 'tokens' ) )
		);
		$tokens = array_slice( array_values( array_unique( array_filter( $tokens ) ) ), 0, self::MAX_STATUS_TOKENS );

		$queue       = array_merge( $result['queue'], Venue::get_new_singers( $result['post_id'] ) );
		$avg_seconds = $this->get_average_song_seconds( $queue );
		$now         = time();

		// Time left for whoever is on stage right now.
		$on_stage_remaining = 0;
		foreach ( $queue as $singer ) {
			if ( 'on-stage' === ( $singer['status'] ?? '' ) ) {
				$stage_time         = absint( $singer['stage_time'] ?? 0 );
				$on_stage_remaining = $stage_time > 0
					? max( 0, $avg_seconds - ( $now - $stage_time ) )
					: (int) floor( $avg_seconds / 2 );
				break;
			}
		}

		// 1-based positions of in-the-queue singers, in queue order.
		$positions = [];
		$position  = 0;
		foreach ( $queue as $singer ) {
			if ( 'in-the-queue' === ( $singer['status'] ?? '' ) && ! empty( $singer['id'] ) ) {
				$positions[ $singer['id'] ] = ++$position;
			}
		}

		$singers = [];
		foreach ( $queue as $singer ) {
			$id    = $singer['id'] ?? '';
			$token = $singer['status_token'] ?? '';
			if ( '' === $id || '' === $token || ! in_array( $token, $tokens, true ) || isset( $singers[ $token ] ) ) {
				continue;
			}

			$status       = $singer['status'] ?? 'kj-received';
			$position     = null;
			$wait_minutes = null;

			if ( 'in-the-queue' === $status && isset( $positions[ $id ] ) ) {
				$position     = $positions[ $id ];
				$wait_seconds = $on_stage_remaining + ( ( $position - 1 ) * $avg_seconds );
				$wait_minutes = (int) ceil( $wait_seconds / 60 );

				$this->maybe_log_estimate(
					$result['post_id'],
					[
						'id'                 => $id,
						'time'               => $now,
						'position'           => $position,
						'wait_minutes'       => $wait_minutes,
						'avg_seconds'        => $avg_seconds,
						'on_stage_remaining' => $on_stage_remaining,
					]
				);
			}

			$singers[ $token ] = [
				'token'        => $token,
				'song'         => $singer['song'] ?? '',
				'artist'       => $singer['artist'] ?? '',
				'status'       => $status,
				'position'     => $position,
				'wait_minutes' => $wait_minutes,
			];
		}

		return rest_ensure_response(
			[
				'success' => true,
				'singers' => array_values( $singers ),
			]
		);
	}

	/**
	 * Log a wait estimate the first time a singer is shown a given queue position.
	 *
	 * A transient marks each singer/position pair as logged, so polling doesn't add duplicate rows.
	 *
	 * @param int   $post_id Venue post ID.
	 * @param array $entry   Estimate data (id, time, position, wait_minutes, avg_seconds, on_stage_remaining).
	 */
	private function maybe_log_estimate( int $post_id, array $entry ): void {
		$marker = 'wpkq_est_' . md5( $post_id . '|' . $entry['id'] . '|' . $entry['position'] );

		if ( false !== get_transient( $marker ) ) {
			return;
		}

		set_transient( $marker, 1, 12 * HOUR_IN_SECONDS );
		Venue::add_estimate_log( $post_id, $entry );
	}

	/**
	 * GET /wpkq/v1/queue/report
	 *
	 * Admin-only data dump for analyzing a night: the current queue (all fields),
	 * pending signups, wait-estimate log, and queue snapshots saved on reset.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_report( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$result = Venue::get_queue_by_slug( $request->get_param( 'slug' ) );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$post_id = $result['post_id'];

		return rest_ensure_response(
			[
				'success'              => true,
				'generated_time'       => time(),
				'timezone'             => wp_timezone_string(),
				'default_song_seconds' => $this->get_default_song_seconds(),
				'queue'                => $result['queue'],
				'pending'              => Venue::get_new_singers( $post_id ),
				'estimate_log'         => Venue::get_estimate_log( $post_id ),
				'archives'             => Venue::get_queue_archives( $post_id ),
			]
		);
	}

	/**
	 * Fallback song length used before any songs have finished tonight (minimum 60 seconds).
	 *
	 * @return int Seconds.
	 */
	private function get_default_song_seconds(): int {
		return max( 60, absint( apply_filters( 'wpkq_default_song_seconds', self::DEFAULT_SONG_SECONDS ) ) );
	}

	/**
	 * Average song length tonight, based on finished singers' stage and completed times.
	 *
	 * Uses the most recent 10 plausible durations (60–900 seconds). Falls back to
	 * DEFAULT_SONG_SECONDS (filterable via `wpkq_default_song_seconds`) when there is no data.
	 *
	 * @param array $queue Full singer queue.
	 * @return int Average song length in seconds.
	 */
	private function get_average_song_seconds( array $queue ): int {
		$finished = [];

		foreach ( $queue as $singer ) {
			if ( 'finished' !== ( $singer['status'] ?? '' ) ) {
				continue;
			}

			$stage_time     = absint( $singer['stage_time'] ?? 0 );
			$completed_time = absint( $singer['completed_time'] ?? 0 );
			$duration       = $completed_time - $stage_time;

			if ( $stage_time > 0 && $completed_time > 0 && $duration >= 60 && $duration <= 900 ) {
				$finished[ $completed_time . '-' . count( $finished ) ] = $duration;
			}
		}

		if ( empty( $finished ) ) {
			return $this->get_default_song_seconds();
		}

		krsort( $finished, SORT_NATURAL );
		$recent = array_slice( $finished, 0, 10 );

		return (int) round( array_sum( $recent ) / count( $recent ) );
	}

	/**
	 * POST /wpkq/v1/queue/advance
	 *
	 * Marks the current on-stage singer as finished and promotes the given singer to on-stage.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function advance_singer( \WP_REST_Request $request ) {
		$slug   = $request->get_param( 'slug' );
		$id     = preg_replace( '/[^A-Za-z0-9]/', '', (string) $request->get_param( 'id' ) );
		$result = Venue::get_queue_by_slug( $slug );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$post_id = $result['post_id'];
		$queue   = $result['queue'];
		$found   = false;

		foreach ( $queue as &$singer ) {
			if ( 'on-stage' === ( $singer['status'] ?? '' ) ) {
				$singer['status']         = 'finished';
				$singer['completed_time'] = time();
			}
			if ( isset( $singer['id'] ) && $singer['id'] === $id ) {
				$singer['status']     = 'on-stage';
				$singer['stage_time'] = time();
				$found                = true;
			}
		}
		unset( $singer );

		if ( ! $found ) {
			return new \WP_Error(
				'singer_not_found',
				__( 'Singer not found in queue.', 'wp-karaoke' ),
				[ 'status' => 404 ]
			);
		}

		Venue::save_queue( $post_id, $queue );

		return $this->get_queue( $request );
	}
}
