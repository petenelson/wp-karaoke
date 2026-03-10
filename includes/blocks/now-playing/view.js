/**
 * Now Playing Block — frontend script.
 *
 * Handles two responsibilities:
 *  1. YouTube IFrame Player API — parses a pasted URL, extracts the video ID,
 *     and loads the video into the embedded player.
 *  2. Singer ticker — fetches the queue via REST, renders a CSS keyframe
 *     horizontal scroll, and refreshes on every animation cycle.
 *
 * @package WPKQ
 * @see https://developers.google.com/youtube/iframe_api_reference
 * @see https://github.com/constgenius/InfiniteScroll (CSS technique)
 */

import './style.css';

/**
 * Now Playing Block module.
 *
 * Handles two responsibilities:
 *  1. YouTube IFrame Player API — parses a pasted URL, extracts the video ID,
 *     and loads the video into the embedded player.
 *  2. Singer ticker — fetches the queue via REST, renders a CSS keyframe
 *     horizontal scroll, and refreshes on every animation cycle.
 */
const NowPlayingBlock = {

	/** @type {YT.Player|null} Active YouTube IFrame player instance. */
	player: null,

	/**
	 * Extract a YouTube video ID from a URL or bare ID string.
	 *
	 * Supports:
	 *   https://www.youtube.com/watch?v=ID
	 *   https://youtu.be/ID
	 *   https://www.youtube.com/embed/ID
	 *   https://www.youtube.com/shorts/ID
	 *
	 *   A bare 11-character video ID
	 *
	 * @param {string} input Raw user input.
	 * @return {string|null} Video ID, or null if not recognised.
	 */
	parseVideoId: ( input ) => {
		const trimmed = ( input || '' ).trim();

		try {
			const url = new URL( trimmed );

			if ( url.hostname === 'youtu.be' ) {
				return url.pathname.slice( 1 ).split( '?' )[ 0 ] || null;
			}

			if ( url.hostname.includes( 'youtube.com' ) ) {
				const v = url.searchParams.get( 'v' );
				if ( v ) {
					return v;
				}

				const match = url.pathname.match(
					/\/(embed|v|shorts)\/([^/?&#]+)/
				);
				if ( match ) {
					return match[ 2 ];
				}
			}
		} catch ( e ) {
			// Not a valid URL — accept a bare 11-character video ID.
			if ( /^[a-zA-Z0-9_-]{11}$/.test( trimmed ) ) {
				return trimmed;
			}
		}

		return null;
	},

	/**
	 * Load a video into the player by ID.
	 *
	 * Hides the welcome overlay on first load.
	 *
	 * @param {string} videoId YouTube video ID.
	 */
	loadVideo: ( videoId ) => {
		if ( NowPlayingBlock.player && typeof NowPlayingBlock.player.loadVideoById === 'function' ) {
			NowPlayingBlock.player.loadVideoById( videoId );

			const welcome = document.getElementById( 'wpkq-welcome' );
			if ( welcome ) {
				welcome.hidden = true;
			}
		}
	},

	/** Maximum number of in-the-queue singers shown in the ticker (excluding on-stage). */
	TICKER_QUEUE_LIMIT: 6,

	/**
	 * Build a placeholder ticker item for an empty queue.
	 *
	 * @return {HTMLElement}
	 */
	buildEmptyItem: () => {
		const span = document.createElement( 'span' );
		span.className   = 'wpkq-monitor__ticker-item';
		span.textContent = 'No singers in the queue.';
		return span;
	},

	/**
	 * Build a "+X More" overflow item shown when the queue exceeds the display limit.
	 *
	 * @param {number} count Number of additional singers not shown.
	 * @return {HTMLElement}
	 */
	buildMoreItem: ( count ) => {
		const span = document.createElement( 'span' );
		span.className   = 'wpkq-monitor__ticker-item wpkq-monitor__ticker-item--more';
		span.textContent = `+${ count } More`;
		return span;
	},

	/**
	 * Build an array of ticker <span> elements from singers data.
	 *
	 * Shows the on-stage singer first, then up to TICKER_QUEUE_LIMIT in-the-queue
	 * singers, followed by a "+X More" item if additional singers exist.
	 *
	 * @param {Array} singers Singer objects from the REST API.
	 * @return {HTMLElement[]}
	 */
	buildItems: ( singers ) => {
		const onStage = singers.filter( ( s ) => s.status === 'on-stage' );
		const inQueue = singers.filter( ( s ) => s.status === 'in-the-queue' );
		const visible = inQueue.slice( 0, NowPlayingBlock.TICKER_QUEUE_LIMIT );
		const overflow = inQueue.length - visible.length;

		const items = [ ...onStage, ...visible ].map( ( singer ) => {
			const isOnStage  = singer.status === 'on-stage';
			const prefix     = isOnStage ? 'On Stage: ' : '';
			const name       = singer.singer_name || '';
			const singerId   = singer.id || '';
			const youtubeUrl = isOnStage ? '' : ( singer.youtube_url || '' );

			const span = document.createElement( 'span' );
			span.className = 'wpkq-monitor__ticker-item';

			if ( isOnStage ) {
				span.classList.add( 'wpkq-monitor__ticker-item--on-stage' );
			}

			if ( youtubeUrl ) {
				span.dataset.singerId   = singerId;
				span.dataset.youtubeUrl = youtubeUrl;
				span.classList.add( 'wpkq-monitor__ticker-item--playable' );
			}

			span.textContent = prefix + name;
			return span;
		} );

		if ( overflow > 0 ) {
			items.push( NowPlayingBlock.buildMoreItem( overflow ) );
		}

		return items;
	},

	/**
	 * Start a one-shot CSS transition that scrolls the ticker from the right
	 * edge of the container until the last item's left edge reaches the
	 * container's left edge, then fires 'transitionend'.
	 *
	 * @param {HTMLElement} tickerInner Ticker inner element.
	 */
	startScroll: ( tickerInner ) => {
		const lastItem = tickerInner.lastElementChild;
		if ( ! lastItem ) {
			return;
		}

		const containerWidth = tickerInner.parentElement.offsetWidth;
		const endX           = -lastItem.offsetLeft;
		const distance       = containerWidth - endX;
		const speed          = 100; // px per second
		const duration       = distance / speed;

		// Snap to start position without transition.
		tickerInner.style.transition = 'none';
		tickerInner.style.transform  = `translateX( ${ containerWidth }px )`;

		// Force reflow so the snap takes effect before we re-enable transition.
		void tickerInner.offsetWidth;

		tickerInner.style.transition = `transform ${ duration }s linear`;
		tickerInner.style.transform  = `translateX( ${ endX }px )`;
	},

	/**
	 * Render singer items into the ticker, then start the scroll.
	 *
	 * @param {HTMLElement} tickerInner Ticker inner element.
	 * @param {Array}       singers     Singer objects from the REST API.
	 */
	renderTicker: ( tickerInner, singers ) => {
		tickerInner.innerHTML = '';

		const items = singers.length
			? NowPlayingBlock.buildItems( singers )
			: [ NowPlayingBlock.buildEmptyItem() ];

		items.forEach( ( el ) => tickerInner.appendChild( el ) );

		NowPlayingBlock.startScroll( tickerInner );
	},

	/**
	 * Fetch the queue from the REST API and pass singers to the callback.
	 *
	 * @param {string}   restUrl  REST endpoint URL.
	 * @param {Function} callback Receives an array of singer objects.
	 */
	fetchQueue: ( restUrl, callback ) => {
		fetch( restUrl )
			.then( ( res ) => res.json() )
			.then( ( data ) => callback( data.queue || [] ) )
			.catch( () => callback( [] ) );
	},

	/**
	 * Initialise the Now Playing block.
	 */
	init: () => {
		const wrapper = document.querySelector(
			'.wp-block-wp-karaoke-now-playing'
		);

		if ( ! wrapper ) {
			return;
		}

		// Inject a style tag here for the html element margin reset, which is necessary to ensure the monitor fills the full viewport height. We can't rely on the theme or user CSS to do this, and it needs to be applied after any admin bar styles.
		const style = document.createElement( 'style' );
		style.textContent = 'html { margin-top: 0 !important; }';
		document.head.appendChild( style );

		const restUrl     = wrapper.dataset.restUrl;
		const advanceUrl  = wrapper.dataset.advanceUrl;
		const venueSlug   = wrapper.dataset.venueSlug;
		const limitAttr   = parseInt( wrapper.dataset.tickerQueueLimit, 10 );

		if ( ! isNaN( limitAttr ) && limitAttr > 0 ) {
			NowPlayingBlock.TICKER_QUEUE_LIMIT = limitAttr;
		}
		const tickerInner = wrapper.querySelector( '.wpkq-monitor__ticker-inner' );
		const urlForm     = wrapper.querySelector( '#wpkq-url-form' );
		const urlInput    = wrapper.querySelector( '#wpkq-url-input' );

		// -----------------------------------------------------------------------
		// YouTube IFrame Player API
		// -----------------------------------------------------------------------

		// Initialise the YT.Player once the API script is ready.
		window.onYouTubeIframeAPIReady = () => {
			NowPlayingBlock.player = new window.YT.Player( 'wpkq-yt-player', {
				height: '100%',
				width: '100%',
				playerVars: {
					autoplay: 0,
					controls: 1,
					rel: 0,
					modestbranding: 1,
				},
			} );
		};

		// Inject the IFrame API script asynchronously.
		const apiScript = document.createElement( 'script' );
		apiScript.src   = 'https://www.youtube.com/iframe_api';
		document.head.appendChild( apiScript );

		// Handle URL form submission.
		if ( urlForm && urlInput ) {
			urlForm.addEventListener( 'submit', ( e ) => {
				e.preventDefault();

				const videoId = NowPlayingBlock.parseVideoId( urlInput.value );

				if ( videoId ) {
					NowPlayingBlock.loadVideo( videoId );
					urlInput.value = '';
					urlInput.blur();
				} else {
					urlInput.classList.add( 'wpkq-monitor__url-input--error' );
					setTimeout( () => {
						urlInput.classList.remove(
							'wpkq-monitor__url-input--error'
						);
					}, 1500 );
				}
			} );
		}

		// -----------------------------------------------------------------------
		// Singer ticker
		// -----------------------------------------------------------------------

		if ( ! tickerInner || ! restUrl ) {
			return;
		}

		// When the scroll completes, fetch a fresh queue and restart.
		tickerInner.addEventListener( 'transitionend', () => {
			NowPlayingBlock.fetchQueue( restUrl, ( singers ) => NowPlayingBlock.renderTicker( tickerInner, singers ) );
		} );

		// Click a singer item to advance them to on-stage and load their video.
		tickerInner.addEventListener( 'click', ( e ) => {
			const item = e.target.closest( '[data-singer-id]' );

			// If this is the "On Stage" item, ignore clicks since they're already on stage and we don't want to reload the video.
			if ( ! item || item.classList.contains( 'wpkq-monitor__ticker-item--on-stage' ) ) {
				return;
			}

			const singerId   = item.dataset.singerId;
			const youtubeUrl = item.dataset.youtubeUrl || '';

			fetch( advanceUrl, {
				method: 'POST',
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify( { slug: venueSlug, id: singerId } ),
			} )
				.then( ( res ) => res.json() )
				.then( ( data ) => {
					if ( data.queue ) {
						NowPlayingBlock.renderTicker( tickerInner, data.queue );
					}
					if ( youtubeUrl ) {
						const videoId = NowPlayingBlock.parseVideoId( youtubeUrl );
						if ( videoId ) {
							NowPlayingBlock.loadVideo( videoId );
						}
					}
				} )
				.catch( () => {} );
		} );

		// Initial load.
		NowPlayingBlock.fetchQueue( restUrl, ( singers ) => NowPlayingBlock.renderTicker( tickerInner, singers ) );
	},
};

window.addEventListener( 'DOMContentLoaded', () => {
	NowPlayingBlock.init();
} );
