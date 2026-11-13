<?php
/**
 * Karaoke Night post type and history stats.
 *
 * @package WPKQ\PostType
 */

namespace WPKQ\PostType;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Night
 *
 * Stores a compact, permanent summary of each karaoke night as a hidden
 * `karaoke-night` post (child of the venue). Summaries live in the night
 * post's own meta, so they are never loaded with the venue's meta on queue reads.
 */
class Night {

	/**
	 * Post type slug.
	 *
	 * @var string
	 */
	const POST_TYPE = 'karaoke-night';

	/**
	 * Post meta key for the night summary JSON.
	 *
	 * @var string
	 */
	const SUMMARY_META_KEY = '_wpkq_night_summary';

	/**
	 * Post meta key for a hash of the queue snapshot the summary was built from (prevents duplicates).
	 *
	 * @var string
	 */
	const SNAPSHOT_KEY_META_KEY = '_wpkq_snapshot_key';

	/**
	 * Summary format version.
	 *
	 * @var int
	 */
	const SUMMARY_VERSION = 1;

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
	public function register(): void {
		add_action( 'init', [ $this, 'register_post_type' ] );
		add_action( 'admin_post_wpkq_delete_night', [ $this, 'handle_delete_night' ] );
	}

	/**
	 * Register the hidden karaoke-night post type.
	 */
	public function register_post_type(): void {
		register_post_type(
			self::POST_TYPE,
			[
				'label'               => __( 'Karaoke Nights', 'wp-karaoke' ),
				'public'              => false,
				'publicly_queryable'  => false,
				'exclude_from_search' => true,
				'show_ui'             => false,
				'show_in_rest'        => false,
				'hierarchical'        => false,
				'rewrite'             => false,
				'query_var'           => false,
				'supports'            => [ 'title' ],
			]
		);
	}

	/**
	 * Create a night summary from a queue snapshot.
	 *
	 * Only finished, non-fake performances are kept. Nights with no performances
	 * are skipped. A snapshot that already has a summary is not added twice.
	 *
	 * @param int   $venue_id      Venue post ID.
	 * @param array $queue         Full queue (including pending signups) at reset time.
	 * @param int   $archived_time When the snapshot was taken.
	 * @return int Night post ID, or 0 if nothing was created.
	 */
	public static function create_from_queue( int $venue_id, array $queue, int $archived_time ): int {
		$singers = array_values(
			array_filter(
				$queue,
				function ( $singer ) {
					return is_array( $singer ) && empty( $singer['fake'] );
				}
			)
		);

		$performances = [];
		$unfinished   = 0;

		foreach ( $singers as $singer ) {
			$status = $singer['status'] ?? '';

			if ( 'finished' !== $status ) {
				$unfinished++;
				continue;
			}

			$performances[] = [
				'singer_name'      => (string) ( $singer['singer_name'] ?? '' ),
				'song'             => (string) ( $singer['song'] ?? '' ),
				'artist'           => (string) ( $singer['artist'] ?? '' ),
				'signup_time'      => absint( $singer['signup_time'] ?? 0 ),
				'queued_time'      => absint( $singer['queued_time'] ?? 0 ),
				'stage_time'       => absint( $singer['stage_time'] ?? 0 ),
				'completed_time'   => absint( $singer['completed_time'] ?? 0 ),
				'used_status_card' => ! empty( $singer['status_token'] ),
			];
		}

		// Same snapshot → same key, so backfilling never duplicates a night created at reset time.
		$snapshot_key = md5( $archived_time . '|' . wp_json_encode( $queue ) );

		if ( empty( $performances ) || self::find_by_snapshot_key( $venue_id, $snapshot_key ) ) {
			return 0;
		}

		// Performance order: when they took the stage (fall back to completion time).
		usort(
			$performances,
			function ( $a, $b ) {
				return ( $a['stage_time'] ?: $a['completed_time'] ) <=> ( $b['stage_time'] ?: $b['completed_time'] );
			}
		);

		$times = array_filter(
			array_merge(
				array_map( fn( $singer ) => absint( $singer['signup_time'] ?? 0 ), $singers ),
				array_column( $performances, 'stage_time' )
			)
		);

		$started = $times ? min( $times ) : $archived_time;
		$ended   = max( array_merge( [ 0 ], array_column( $performances, 'completed_time' ) ) ) ?: $archived_time;

		$summary = [
			'version'       => self::SUMMARY_VERSION,
			'archived_time' => $archived_time,
			'started'       => $started,
			'ended'         => $ended,
			'signups'       => count( $singers ),
			'unfinished'    => $unfinished,
			'performances'  => $performances,
		];

		$night_id = wp_insert_post(
			[
				'post_type'     => self::POST_TYPE,
				'post_status'   => 'publish',
				'post_parent'   => $venue_id,
				'post_title'    => sprintf(
					/* translators: 1: venue name, 2: date */
					__( '%1$s — %2$s', 'wp-karaoke' ),
					get_the_title( $venue_id ),
					wp_date( get_option( 'date_format' ), $started )
				),
				'post_date'     => wp_date( 'Y-m-d H:i:s', $started ),
				'post_date_gmt' => gmdate( 'Y-m-d H:i:s', $started ),
			],
			true
		);

		if ( is_wp_error( $night_id ) || ! $night_id ) {
			return 0;
		}

		// Meta functions unslash values, so slash the JSON to keep its escapes intact.
		update_post_meta( $night_id, self::SUMMARY_META_KEY, wp_slash( wp_json_encode( $summary ) ) );
		update_post_meta( $night_id, self::SNAPSHOT_KEY_META_KEY, $snapshot_key );

		return (int) $night_id;
	}

	/**
	 * Create summaries for any saved queue snapshots that don't have one yet.
	 *
	 * Covers snapshots saved before night history existed (up to the 30-day retention window).
	 *
	 * @param int $venue_id Venue post ID.
	 */
	public static function backfill_from_archives( int $venue_id ): void {
		foreach ( Venue::get_queue_archives( $venue_id ) as $archive ) {
			if ( isset( $archive['archived_time'], $archive['queue'] ) && is_array( $archive['queue'] ) ) {
				self::create_from_queue( $venue_id, $archive['queue'], absint( $archive['archived_time'] ) );
			}
		}
	}

	/**
	 * Find a night post created from a given queue snapshot.
	 *
	 * @param int    $venue_id     Venue post ID.
	 * @param string $snapshot_key Snapshot hash.
	 * @return int Night post ID, or 0.
	 */
	private static function find_by_snapshot_key( int $venue_id, string $snapshot_key ): int {
		$ids = get_posts(
			[
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'publish',
				'post_parent'    => $venue_id,
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_key'       => self::SNAPSHOT_KEY_META_KEY, // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value'     => $snapshot_key, // phpcs:ignore WordPress.DB.SlowDBQuery
			]
		);

		return $ids ? (int) $ids[0] : 0;
	}

	/**
	 * Get all nights for a venue, newest first.
	 *
	 * @param int $venue_id Venue post ID.
	 * @return array List of [ 'id' => int, 'summary' => array ].
	 */
	public static function get_nights( int $venue_id ): array {
		$ids = get_posts(
			[
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'publish',
				'post_parent'    => $venue_id,
				'posts_per_page' => -1,
				'orderby'        => 'date',
				'order'          => 'DESC',
				'fields'         => 'ids',
			]
		);

		$nights = [];

		foreach ( $ids as $id ) {
			$summary = json_decode( (string) get_post_meta( $id, self::SUMMARY_META_KEY, true ), true );

			if ( is_array( $summary ) && ! empty( $summary['performances'] ) ) {
				$nights[] = [
					'id'      => (int) $id,
					'summary' => $summary,
				];
			}
		}

		return $nights;
	}

	/**
	 * Per-night metrics from a summary.
	 *
	 * @param array $summary Night summary.
	 * @return array Metrics (averages are null when there is no data).
	 */
	public static function get_night_metrics( array $summary ): array {
		$performances = $summary['performances'] ?? [];
		$durations    = [];
		$waits        = [];
		$approvals    = [];
		$names        = [];
		$card_users   = 0;

		foreach ( $performances as $performance ) {
			$stage     = absint( $performance['stage_time'] ?? 0 );
			$completed = absint( $performance['completed_time'] ?? 0 );
			$queued    = absint( $performance['queued_time'] ?? 0 );
			$signup    = absint( $performance['signup_time'] ?? 0 );

			if ( $stage && $completed && ( $completed - $stage ) >= 60 && ( $completed - $stage ) <= 900 ) {
				$durations[] = $completed - $stage;
			}
			if ( $queued && $stage > $queued ) {
				$waits[] = $stage - $queued;
			}
			if ( $signup && $queued > $signup ) {
				$approvals[] = $queued - $signup;
			}
			if ( ! empty( $performance['used_status_card'] ) ) {
				$card_users++;
			}

			$name = self::normalize( $performance['singer_name'] ?? '' );
			if ( '' !== $name ) {
				$names[ $name ] = true;
			}
		}

		return [
			'performances'         => count( $performances ),
			'singers'              => count( $names ),
			'signups'              => absint( $summary['signups'] ?? count( $performances ) ),
			'unfinished'           => absint( $summary['unfinished'] ?? 0 ),
			'avg_song_seconds'     => self::average( $durations ),
			'avg_wait_seconds'     => self::average( $waits ),
			'avg_approval_seconds' => self::average( $approvals ),
			'status_card_users'    => $card_users,
			'started'              => absint( $summary['started'] ?? 0 ),
			'ended'                => absint( $summary['ended'] ?? 0 ),
		];
	}

	/**
	 * Stats across all nights: totals, top songs, top artists, and regulars.
	 *
	 * @param array $nights Nights from get_nights().
	 * @param int   $limit  Maximum rows per top list.
	 * @return array Stats.
	 */
	public static function get_stats( array $nights, int $limit = 10 ): array {
		$songs        = [];
		$artists      = [];
		$regulars     = [];
		$durations    = [];
		$performances = 0;

		foreach ( $nights as $night ) {
			foreach ( $night['summary']['performances'] ?? [] as $performance ) {
				$performances++;

				$song   = trim( (string) ( $performance['song'] ?? '' ) );
				$artist = trim( (string) ( $performance['artist'] ?? '' ) );
				$name   = trim( (string) ( $performance['singer_name'] ?? '' ) );

				if ( '' !== $song ) {
					$key = self::normalize( $song ) . '|' . self::normalize( $artist );
					if ( ! isset( $songs[ $key ] ) ) {
						$songs[ $key ] = [
							'variants' => [],
							'count'    => 0,
						];
					}
					$songs[ $key ]['count']++;
					$variant                               = $song . "\n" . $artist;
					$songs[ $key ]['variants'][ $variant ] = ( $songs[ $key ]['variants'][ $variant ] ?? 0 ) + 1;
				}

				if ( '' !== $artist ) {
					$key = self::normalize( $artist );
					if ( ! isset( $artists[ $key ] ) ) {
						$artists[ $key ] = [
							'variants' => [],
							'count'    => 0,
						];
					}
					$artists[ $key ]['count']++;
					$artists[ $key ]['variants'][ $artist ] = ( $artists[ $key ]['variants'][ $artist ] ?? 0 ) + 1;
				}

				if ( '' !== $name ) {
					$key = self::normalize( $name );
					if ( ! isset( $regulars[ $key ] ) ) {
						$regulars[ $key ] = [
							'variants'     => [],
							'nights'       => [],
							'performances' => 0,
						];
					}
					$regulars[ $key ]['nights'][ $night['id'] ] = true;
					$regulars[ $key ]['performances']++;
					$regulars[ $key ]['variants'][ $name ] = ( $regulars[ $key ]['variants'][ $name ] ?? 0 ) + 1;
				}

				$stage     = absint( $performance['stage_time'] ?? 0 );
				$completed = absint( $performance['completed_time'] ?? 0 );
				if ( $stage && $completed && ( $completed - $stage ) >= 60 && ( $completed - $stage ) <= 900 ) {
					$durations[] = $completed - $stage;
				}
			}
		}

		$by_count = function ( $a, $b ) {
			return $b['count'] <=> $a['count'];
		};

		$songs = array_map(
			function ( $row ) {
				list( $song, $artist ) = explode( "\n", self::most_common( $row['variants'] ), 2 );
				return [
					'song'   => $song,
					'artist' => $artist,
					'count'  => $row['count'],
				];
			},
			array_values( $songs )
		);

		$artists = array_map(
			function ( $row ) {
				return [
					'artist' => self::most_common( $row['variants'] ),
					'count'  => $row['count'],
				];
			},
			array_values( $artists )
		);

		usort( $songs, $by_count );
		usort( $artists, $by_count );

		$regulars = array_map(
			function ( $regular ) {
				return [
					'name'         => self::most_common( $regular['variants'] ),
					'nights'       => count( $regular['nights'] ),
					'performances' => $regular['performances'],
				];
			},
			array_values( $regulars )
		);

		$unique_singers = count( $regulars );

		// Regulars: singers who came on more than one night.
		$regulars = array_values( array_filter( $regulars, fn( $regular ) => $regular['nights'] > 1 ) );
		usort(
			$regulars,
			function ( $a, $b ) {
				return [ $b['nights'], $b['performances'] ] <=> [ $a['nights'], $a['performances'] ];
			}
		);

		return [
			'nights'           => count( $nights ),
			'performances'     => $performances,
			'unique_singers'   => $unique_singers,
			'avg_song_seconds' => self::average( $durations ),
			'top_songs'        => array_slice( $songs, 0, $limit ),
			'top_artists'      => array_slice( $artists, 0, $limit ),
			'regulars'         => array_slice( $regulars, 0, $limit ),
		];
	}

	/**
	 * Handle the wpkq_delete_night admin action (e.g. to remove a test night).
	 */
	public function handle_delete_night(): void {
		check_admin_referer( 'wpkq_delete_night' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'wp-karaoke' ) );
		}

		$input = filter_var_array(
			$_POST, // phpcs:ignore WordPress.Security.NonceVerification
			[
				'night_id' => FILTER_VALIDATE_INT,
				'slug'     => [
					'filter' => FILTER_CALLBACK,
					'options' => 'sanitize_title',
				],
			]
		);

		$night_id = absint( $input['night_id'] ?? 0 );
		$night    = $night_id ? get_post( $night_id ) : null;

		if ( ! $night || self::POST_TYPE !== $night->post_type ) {
			wp_die( esc_html__( 'Night not found.', 'wp-karaoke' ) );
		}

		wp_delete_post( $night_id, true );

		wp_safe_redirect(
			add_query_arg(
				[
					'page'    => 'wpkq-night-history',
					'slug'    => $input['slug'] ?? '',
					'deleted' => 1,
				],
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Normalize a name/title for grouping (case- and whitespace-insensitive).
	 *
	 * @param string $text Text.
	 * @return string Normalized text.
	 */
	private static function normalize( string $text ): string {
		return mb_strtolower( preg_replace( '/\s+/u', ' ', trim( $text ) ) );
	}

	/**
	 * Pick the most-used spelling of a name or title. Ties go to the one with more capital letters,
	 * so "Neil Diamond" wins over "neil diamond".
	 *
	 * @param array $variants Map of spelling => count.
	 * @return string Most common spelling.
	 */
	private static function most_common( array $variants ): string {
		$best = '';

		foreach ( $variants as $variant => $count ) {
			$variant = (string) $variant;

			if (
				'' === $best ||
				$count > $variants[ $best ] ||
				( $count === $variants[ $best ] && preg_match_all( '/\p{Lu}/u', $variant ) > preg_match_all( '/\p{Lu}/u', $best ) )
			) {
				$best = $variant;
			}
		}

		return $best;
	}

	/**
	 * Rounded average of a list of numbers, or null when empty.
	 *
	 * @param array $values Numbers.
	 * @return int|null Average.
	 */
	private static function average( array $values ): ?int {
		return $values ? (int) round( array_sum( $values ) / count( $values ) ) : null;
	}
}
