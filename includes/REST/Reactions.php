<?php
/**
 * Reactions REST Controller
 *
 * @package WPKQ\REST
 */

namespace WPKQ\REST;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WPKQ\PostType\Venue;

/**
 * Class Reactions
 *
 * Public endpoint for audience emoji reactions. Reactions are held briefly in a
 * per-venue transient buffer and delivered to the Now Playing monitor through
 * its existing actions poll (see Actions::get_actions()).
 */
class Reactions {

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
	const ROUTE = '/reactions';

	/**
	 * How long reactions stay in the buffer, in seconds.
	 *
	 * @var int
	 */
	const BUFFER_SECONDS = 15;

	/**
	 * Minimum time between reactions from one visitor, in seconds.
	 *
	 * @var float
	 */
	const VISITOR_COOLDOWN = 1.5;

	/**
	 * Maximum reactions accepted per venue in any one-second window.
	 *
	 * @var int
	 */
	const VENUE_MAX_PER_SECOND = 30;

	/**
	 * Maximum reactions accepted from one IP address in any one-second window.
	 *
	 * Lower than the venue cap so a single source (e.g. a script sending a new visitor ID
	 * each time) can't use up the whole venue's allowance, but high enough for a bar's
	 * shared Wi-Fi.
	 *
	 * @var int
	 */
	const IP_MAX_PER_SECOND = 10;

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
	 * The allowed reactions: key => emoji.
	 *
	 * @return array
	 */
	public static function get_reactions(): array {
		return [
			'clap'  => '👏',
			'heart' => '❤️',
			'fire'  => '🔥',
			'laugh' => '😂',
			'mic'   => '🎤',
			'rock'  => '🤘',
		];
	}

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	/**
	 * Register REST routes.
	 */
	public function register_routes(): void {
		// POST /wpkq/v1/reactions — public: send a reaction to the monitor.
		register_rest_route(
			self::NAMESPACE,
			self::ROUTE,
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'create_reaction' ],
				'permission_callback' => '__return_true',
				'args'                => [
					'slug'     => [
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_title',
					],
					'reaction' => [
						'required' => true,
						'type'     => 'string',
						'enum'     => array_keys( self::get_reactions() ),
					],
					'visitor'  => [
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
						'description'       => __( 'Random per-browser ID used for rate limiting.', 'wp-karaoke' ),
					],
				],
			]
		);
	}

	/**
	 * POST /wpkq/v1/reactions
	 *
	 * Accepts a reaction while someone is on stage, subject to per-visitor and per-venue limits.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function create_reaction( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$slug     = (string) $request->get_param( 'slug' );
		$reaction = (string) $request->get_param( 'reaction' );
		$now      = microtime( true );
		$ip       = $this->get_client_ip();

		// Cheap transient checks run first, so rejected taps never load the queue.

		// Per-visitor cooldown. Keyed on the browser's random ID (not IP alone: everyone on the bar's Wi-Fi shares one).
		$visitor     = (string) $request->get_param( 'visitor' );
		$visitor_key = 'wpkq_rx_' . md5( $slug . '|' . ( '' !== $visitor ? $visitor : $ip . '|' . $this->get_user_agent() ) );
		$last        = (float) get_transient( $visitor_key );

		if ( $last && ( $now - $last ) < self::VISITOR_COOLDOWN ) {
			return $this->too_many( __( 'Slow down a little!', 'wp-karaoke' ) );
		}

		// Per-IP cap, so one source can't fill the venue cap by inventing visitor IDs.
		$ip_key   = 'wpkq_rxip_' . md5( $slug . '|' . $ip );
		$ip_times = array_values(
			array_filter(
				(array) get_transient( $ip_key ),
				fn( $time ) => is_numeric( $time ) && ( $now - (float) $time ) < 1
			)
		);

		if ( count( $ip_times ) >= self::IP_MAX_PER_SECOND ) {
			return $this->too_many( __( 'Slow down a little!', 'wp-karaoke' ) );
		}

		// Per-venue cap, so the monitor isn't flooded.
		$buffer      = $this->read_buffer( $slug );
		$last_second = array_filter( $buffer['items'], fn( $item ) => ( $now - $item['t'] ) < 1 );

		if ( count( $last_second ) >= self::VENUE_MAX_PER_SECOND ) {
			return $this->too_many( __( 'The crowd is going wild! Try again in a moment.', 'wp-karaoke' ) );
		}

		$result = Venue::get_queue_by_slug( $slug );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$on_stage = array_filter(
			$result['queue'],
			fn( $singer ) => 'on-stage' === ( $singer['status'] ?? '' )
		);

		if ( empty( $on_stage ) ) {
			return new \WP_Error(
				'no_one_on_stage',
				__( 'Reactions work while someone is singing.', 'wp-karaoke' ),
				[ 'status' => 409 ]
			);
		}

		$ip_times[] = $now;
		set_transient( $ip_key, $ip_times, 10 );
		set_transient( $visitor_key, (string) $now, 10 );

		++$buffer['seq'];
		$buffer['items'][] = [
			'seq'      => $buffer['seq'],
			'reaction' => $reaction,
			't'        => $now,
		];

		$this->write_buffer( $slug, $buffer );

		return rest_ensure_response( [ 'success' => true ] );
	}

	/**
	 * Get reactions newer than a sequence number, for the monitor.
	 *
	 * Pass a negative $after on the monitor's first poll to get only the current
	 * sequence number, so old reactions aren't replayed when the page loads.
	 *
	 * @param string $slug  Venue slug.
	 * @param int    $after Last sequence number the monitor has seen.
	 * @return array [ 'seq' => int, 'reactions' => array of [ seq, emoji ] ].
	 */
	public static function get_since( string $slug, int $after ): array {
		$buffer    = self::instance()->read_buffer( $slug );
		$emoji     = self::get_reactions();
		$reactions = [];

		// If the buffer expired and its counter restarted below what the monitor has seen,
		// everything in it is new to the monitor.
		if ( $after > $buffer['seq'] ) {
			$after = 0;
		}

		if ( $after >= 0 ) {
			foreach ( $buffer['items'] as $item ) {
				if ( $item['seq'] > $after && isset( $emoji[ $item['reaction'] ] ) ) {
					$reactions[] = [
						'seq'   => $item['seq'],
						'emoji' => $emoji[ $item['reaction'] ],
					];
				}
			}
		}

		return [
			'seq'       => $buffer['seq'],
			'reactions' => $reactions,
		];
	}

	/**
	 * Read the venue's reaction buffer, dropping expired items.
	 *
	 * @param string $slug Venue slug.
	 * @return array [ 'seq' => int, 'items' => array ].
	 */
	private function read_buffer( string $slug ): array {
		$buffer = get_transient( $this->buffer_key( $slug ) );
		$buffer = is_string( $buffer ) ? json_decode( $buffer, true ) : null;

		if ( ! is_array( $buffer ) || ! isset( $buffer['seq'], $buffer['items'] ) || ! is_array( $buffer['items'] ) ) {
			$buffer = [
				'seq'   => 0,
				'items' => [],
			];
		}

		$cutoff          = microtime( true ) - self::BUFFER_SECONDS;
		$buffer['seq']   = absint( $buffer['seq'] );
		$buffer['items'] = array_values(
			array_filter(
				$buffer['items'],
				fn( $item ) => is_array( $item ) && isset( $item['seq'], $item['reaction'], $item['t'] ) && $item['t'] >= $cutoff
			)
		);

		return $buffer;
	}

	/**
	 * Save the venue's reaction buffer.
	 *
	 * The sequence number outlives the items (1 hour) so the monitor never sees it go backwards mid-night.
	 *
	 * @param string $slug   Venue slug.
	 * @param array  $buffer Buffer.
	 */
	private function write_buffer( string $slug, array $buffer ): void {
		set_transient( $this->buffer_key( $slug ), wp_json_encode( $buffer ), HOUR_IN_SECONDS );
	}

	/**
	 * Transient key for a venue's reaction buffer.
	 *
	 * @param string $slug Venue slug.
	 * @return string
	 */
	private function buffer_key( string $slug ): string {
		return 'wpkq_reactions_' . sanitize_key( $slug );
	}

	/**
	 * A 429 "too many reactions" error.
	 *
	 * @param string $message Message.
	 * @return \WP_Error
	 */
	private function too_many( string $message ): \WP_Error {
		return new \WP_Error( 'too_many_reactions', $message, [ 'status' => 429 ] );
	}

	/**
	 * The client's IP address, for rate limiting.
	 *
	 * Uses REMOTE_ADDR. Sites behind a proxy or CDN can return the real client IP
	 * with the `wpkq_reaction_client_ip` filter.
	 *
	 * @return string
	 */
	private function get_client_ip(): string {
		$server = filter_var_array(
			$_SERVER, // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			[ 'REMOTE_ADDR' => FILTER_VALIDATE_IP ]
		);

		return (string) apply_filters( 'wpkq_reaction_client_ip', (string) ( $server['REMOTE_ADDR'] ?? '' ) );
	}

	/**
	 * The client's user agent, used with the IP when the browser sent no visitor ID.
	 *
	 * @return string
	 */
	private function get_user_agent(): string {
		$server = filter_var_array(
			$_SERVER, // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			[
				'HTTP_USER_AGENT' => [
					'filter'  => FILTER_CALLBACK,
					'options' => 'sanitize_text_field',
				],
			]
		);

		return (string) ( $server['HTTP_USER_AGENT'] ?? '' );
	}
}
