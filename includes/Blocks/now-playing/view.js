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

	/** @type {HTMLElement|null} Progress bar fill element. */
	progressBar: null,

	/** @type {number} Milliseconds without mouse movement before the cursor is hidden. */
	CURSOR_IDLE_MS: 3000,

	/** @type {number|null} Timer that hides the cursor once the mouse is idle. */
	cursorIdleTimer: null,

	/**
	 * Show the cursor and restart the idle timer that hides it again.
	 *
	 * @param {HTMLElement} wrapper Block wrapper element.
	 */
	resetCursorIdle: ( wrapper ) => {
		wrapper.classList.remove( 'wpkq-monitor--cursor-hidden' );
		clearTimeout( NowPlayingBlock.cursorIdleTimer );

		NowPlayingBlock.cursorIdleTimer = setTimeout( () => {
			wrapper.classList.add( 'wpkq-monitor--cursor-hidden' );
		}, NowPlayingBlock.CURSOR_IDLE_MS );
	},

	/**
	 * Hide the mouse cursor over the monitor after it has been idle for a while.
	 *
	 * Any mouse movement, click, or scroll shows it again. The YouTube iframe
	 * controls its own cursor, so this only applies outside the video.
	 *
	 * @param {HTMLElement} wrapper Block wrapper element.
	 */
	initCursorIdle: ( wrapper ) => {
		const reset = () => NowPlayingBlock.resetCursorIdle( wrapper );

		[ 'mousemove', 'mousedown', 'wheel' ].forEach( ( eventName ) => {
			document.addEventListener( eventName, reset, { passive: true } );
		} );

		reset();
	},

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

			if ( NowPlayingBlock.progressBar ) {
				NowPlayingBlock.progressBar.style.width = '0%';
			}

			const welcome = document.getElementById( 'wpkq-welcome' );
			if ( welcome ) {
				welcome.hidden = true;
			}
		}
	},

	/**
	 * Update the progress bar to reflect the player's current playback position.
	 * Called on a polling interval since the IFrame API has no timeupdate event.
	 */
	updateProgress: () => {
		const player = NowPlayingBlock.player;
		const bar    = NowPlayingBlock.progressBar;

		if ( ! bar || ! player || typeof player.getDuration !== 'function' ) {
			return;
		}

		const duration = player.getDuration();
		const percent  = duration ? ( player.getCurrentTime() / duration ) * 100 : 0;

		bar.style.width = `${ Math.min( percent, 100 ) }%`;
	},

	/** Maximum number of in-the-queue singers shown in the ticker (excluding on-stage). */
	TICKER_QUEUE_LIMIT: 6,

	/** Milliseconds between background queue polls. */
	QUEUE_POLL_MS: 5000,

	/** Milliseconds before a queue request is abandoned, so a hung request can't stop polling. */
	QUEUE_TIMEOUT_MS: 10000,

	/** @type {Array} Most recently fetched queue; read by the ticker on each scroll-complete cycle. */
	queueCache: [],

	/**
	 * @type {number} Bumped on every cache write. A poll that started before the latest write
	 *                (e.g. an advance click) is discarded so it can't put an older queue back.
	 */
	queueVersion: 0,

	/** @type {boolean} True while a queue fetch is in-flight; prevents concurrent requests. */
	isFetchingQueue: false,

	/** @type {number} The endX target stored so speed changes can continue from mid-scroll. */
	currentEndX: 0,

	/** @type {boolean} True when the mouse is over the right half of the ticker (fast-scroll mode). */
	isSpedUp: false,

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

		// For the first singer after the on-stage performer, show "Up Next: " prefix.
		let upNextShown = false;
		const items = [ ...onStage, ...visible ].map( ( singer ) => {
			const isOnStage  = singer.status === 'on-stage';
			const prefix     = isOnStage ? 'On Stage: ' : ( !upNextShown ? ( upNextShown = true, 'Up Next: ' ) : '' );
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
	 * Re-apply the scroll transition from the current mid-scroll position at a new speed.
	 * Reads the live computed translateX so it can be called at any point during a scroll.
	 *
	 * @param {HTMLElement} tickerInner Ticker inner element.
	 * @param {number}      multiplier  Speed multiplier: 1 = normal, 1.5 = 50% faster.
	 */
	applySpeed: ( tickerInner, multiplier ) => {
		const currentX  = new DOMMatrix( window.getComputedStyle( tickerInner ).transform ).m41;
		const endX      = NowPlayingBlock.currentEndX;
		const remaining = endX - currentX;

		if ( remaining >= 0 ) {
			return;
		}

		const duration = Math.abs( remaining ) / ( 100 * multiplier );

		tickerInner.style.transition = 'none';
		tickerInner.style.transform  = `translateX( ${ currentX }px )`;
		void tickerInner.offsetWidth;
		tickerInner.style.transition = `transform ${ duration }s linear`;
		tickerInner.style.transform  = `translateX( ${ endX }px )`;
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
		const speed          = 100 * ( NowPlayingBlock.isSpedUp ? 4 : 1 );
		const duration       = distance / speed;

		NowPlayingBlock.currentEndX = endX;

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
	 * Fetch the queue from the REST API.
	 *
	 * Requests are abandoned after QUEUE_TIMEOUT_MS. Only one request runs at a time.
	 *
	 * @param {string} restUrl REST endpoint URL.
	 * @return {Promise<Array|null>} Singers, or null if the request failed, timed out, or one was already running.
	 */
	fetchQueue: ( restUrl ) => {
		if ( NowPlayingBlock.isFetchingQueue ) {
			return Promise.resolve( null );
		}

		NowPlayingBlock.isFetchingQueue = true;

		const controller = new AbortController();
		const timer      = setTimeout( () => controller.abort(), NowPlayingBlock.QUEUE_TIMEOUT_MS );

		return fetch( restUrl, { signal: controller.signal } )
			.then( ( res ) => {
				if ( ! res.ok ) {
					throw new Error( `Queue request failed: ${ res.status }` );
				}
				return res.json();
			} )
			// Handle success and failure in one step, so only request errors become null.
			.then(
				( data ) => ( Array.isArray( data.queue ) ? data.queue : [] ),
				() => null
			)
			.finally( () => {
				clearTimeout( timer );
				NowPlayingBlock.isFetchingQueue = false;
			} );
	},

	/** Last audience reaction sequence number shown. -1 until the first poll. */
	reactionsSeq: -1,

	/** Maximum floating reactions on screen at once. */
	MAX_REACTIONS_ON_SCREEN: 30,

	/**
	 * Float an audience reaction emoji up the side of the screen.
	 *
	 * @param {string} emoji Emoji character.
	 */
	showReaction: ( emoji ) => {
		const layer = document.querySelector( '.wpkq-monitor__reactions' );

		if ( ! layer || layer.childElementCount >= NowPlayingBlock.MAX_REACTIONS_ON_SCREEN ) {
			return;
		}

		const el = document.createElement( 'span' );
		el.className   = 'wpkq-monitor__reaction';
		el.textContent = emoji;

		// Random lane on the right side, size, drift, and speed so bursts don't stack up.
		el.style.left = `${ 70 + Math.random() * 25 }%`;
		el.style.setProperty( '--wpkq-reaction-size', `${ 2.5 + Math.random() * 2 }rem` );
		el.style.setProperty( '--wpkq-reaction-drift', `${ Math.round( ( Math.random() - 0.5 ) * 120 ) }px` );
		el.style.animationDuration = `${ 3.5 + Math.random() * 1.5 }s`;

		el.addEventListener( 'animationend', () => el.remove() );
		layer.appendChild( el );
	},

	/**
	 * Handle the reactions part of an actions poll response.
	 *
	 * @param {Object} data Actions endpoint response.
	 */
	handleReactions: ( data ) => {
		if ( 'number' !== typeof data.reactions_seq ) {
			return;
		}

		const reactions = data.reactions || [];

		// Spread each poll's batch evenly across the second until the next poll, so batches never pile up.
		const spacing = reactions.length ? Math.min( 150, 1000 / reactions.length ) : 0;

		reactions.forEach( ( reaction, index ) => {
			setTimeout( () => NowPlayingBlock.showReaction( reaction.emoji ), index * spacing );
		} );

		// The server's counter resets if the buffer expired; follow it down so new reactions still show.
		NowPlayingBlock.reactionsSeq = data.reactions_seq;
	},

	/**
	 * Store a new queue in the cache, and optionally redraw the ticker with it.
	 *
	 * Every cache write goes through here so the array check and version bump stay in one place.
	 *
	 * @param {Array}            queue       Singers.
	 * @param {HTMLElement|null} tickerInner Ticker inner element to redraw, or null to only update the cache.
	 */
	setQueue: ( queue, tickerInner = null ) => {
		if ( ! Array.isArray( queue ) ) {
			return;
		}

		NowPlayingBlock.queueVersion++;
		NowPlayingBlock.queueCache = queue;

		if ( tickerInner ) {
			NowPlayingBlock.renderTicker( tickerInner, queue );
		}
	},

	/**
	 * Load the queue and keep it fresh with a background poll. The ticker reads the cache
	 * on each scroll-complete cycle instead of fetching.
	 *
	 * The first result draws the ticker. If that first request fails, the empty message is
	 * drawn so the ticker still scrolls and picks up the next poll's result.
	 *
	 * @param {string}      restUrl     REST endpoint URL.
	 * @param {HTMLElement} tickerInner Ticker inner element.
	 */
	startQueuePolling: ( restUrl, tickerInner ) => {
		let hasRendered = false;

		const poll = () => {
			const startVersion = NowPlayingBlock.queueVersion;

			NowPlayingBlock.fetchQueue( restUrl )
				.then( ( singers ) => {
					// A local update (advance, seed) landed while this request was running; its queue is newer.
					const isStale = startVersion !== NowPlayingBlock.queueVersion;

					if ( ! hasRendered ) {
						hasRendered = true;

						if ( ! isStale ) {
							NowPlayingBlock.setQueue( singers || [], tickerInner );
						}
						return;
					}

					// On a failed request, keep the last good queue rather than blanking the ticker.
					if ( null !== singers && ! isStale ) {
						NowPlayingBlock.setQueue( singers );
					}
				} )
				.finally( () => setTimeout( poll, NowPlayingBlock.QUEUE_POLL_MS ) );
		};

		poll();
	},

	/**
	 * Start polling the actions endpoint once per second.
	 *
	 * @param {string}      actionsUrl  Base URL for the actions REST endpoint.
	 * @param {string}      venueSlug   Venue slug.
	 * @param {HTMLElement} tickerInner Ticker inner element.
	 */
	startActionPolling: ( actionsUrl, venueSlug, tickerInner ) => {
		const poll = () => {
			NowPlayingBlock.fetchActions( actionsUrl, venueSlug, tickerInner )
				.finally( () => setTimeout( poll, 1000 ) );
		};
		setTimeout( poll, 1000 );
	},

	/**
	 * Fetch pending actions from the REST endpoint and handle each one.
	 *
	 * @param {string}      actionsUrl  Base URL for the actions REST endpoint.
	 * @param {string}      venueSlug   Venue slug.
	 * @param {HTMLElement} tickerInner Ticker inner element.
	 */
	fetchActions: async ( actionsUrl, venueSlug, tickerInner ) => {
		try {
			const params = new URLSearchParams( { slug: venueSlug, reactions_after: NowPlayingBlock.reactionsSeq } );
			const res    = await fetch( `${ actionsUrl }?${ params }` );
			const data   = await res.json();
			( data.actions || [] ).forEach( ( action ) => {
				NowPlayingBlock.handleAction( action, actionsUrl, venueSlug, tickerInner );
			} );
			NowPlayingBlock.handleReactions( data );
		} catch ( e ) {}
	},

	/**
	 * Handle a single action from the actions endpoint.
	 * Deletes the action first (to prevent re-processing), then performs it.
	 *
	 * @param {Object}      action      Action object with id, action, singer_id.
	 * @param {string}      actionsUrl  Base URL for the actions REST endpoint.
	 * @param {string}      venueSlug   Venue slug.
	 * @param {HTMLElement} tickerInner Ticker inner element.
	 */
	handleAction: ( action, actionsUrl, venueSlug, tickerInner ) => {

		// Delete first to prevent re-processing on the next poll.
		fetch(
			`${ actionsUrl }/${ encodeURIComponent( action.id ) }?slug=${ encodeURIComponent( venueSlug ) }`,
			{ method: 'DELETE' }
		).catch( () => {} );

		switch ( action.action ) {
			case 'set-on-stage':
				const item = tickerInner.querySelector( `[data-singer-id="${ action.singer_id }"]` );
				if ( item ) {
					item.click();
				}
				break;

			case 'toggle-playback':
				const player = NowPlayingBlock.player;
				if ( player && typeof player.getPlayerState === 'function' ) {
					if ( window.YT.PlayerState.PLAYING === player.getPlayerState() ) {
						player.pauseVideo();
					} else {
						player.playVideo();
					}
				}
				break;

			case 'load-video': {
				const videoId = NowPlayingBlock.parseVideoId( action.url || '' );
				if ( videoId ) {
					NowPlayingBlock.loadVideo( videoId );
				}
				break;
			}
		}
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
		const actionsUrl  = wrapper.dataset.actionsUrl;
		const venueSlug   = wrapper.dataset.venueSlug;
		const limitAttr   = parseInt( wrapper.dataset.tickerQueueLimit, 10 );

		if ( ! isNaN( limitAttr ) && limitAttr > 0 ) {
			NowPlayingBlock.TICKER_QUEUE_LIMIT = limitAttr;
		}
		const tickerInner = wrapper.querySelector( '.wpkq-monitor__ticker-inner' );
		const seedBtn     = wrapper.querySelector( '#wpkq-seed-btn' );

		// Hide the idle cursor on the authorized monitor (not on the password form).
		if ( restUrl ) {
			NowPlayingBlock.initCursorIdle( wrapper );
		}

		// -----------------------------------------------------------------------
		// YouTube IFrame Player API
		// -----------------------------------------------------------------------

		NowPlayingBlock.progressBar = wrapper.querySelector( '.wpkq-monitor__progress-bar' );

		if ( NowPlayingBlock.progressBar ) {
			setInterval( NowPlayingBlock.updateProgress, 250 );
		}

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

		// Seed the queue with fake singers (when enabled in the block settings).
		if ( seedBtn ) {
			const seedUrl = wrapper.dataset.seedUrl;
			seedBtn.addEventListener( 'click', () => {
				seedBtn.disabled = true;
				fetch( seedUrl, {
					method: 'POST',
					headers: { 'Content-Type': 'application/json' },
					body: JSON.stringify( { slug: venueSlug } ),
				} )
					.then( ( res ) => res.json() )
					.then( ( data ) => {
						NowPlayingBlock.setQueue( data.queue, tickerInner );
					} )
					.catch( () => {} )
					.finally( () => {
						seedBtn.disabled = false;
					} );
			} );
		}

		// -----------------------------------------------------------------------
		// Singer ticker
		// -----------------------------------------------------------------------

		if ( ! tickerInner || ! restUrl ) {
			return;
		}

		// Speed up scroll 50% when mouse is over the right half of the ticker.
		const tickerWrap = wrapper.querySelector( '.wpkq-monitor__ticker-wrap' );
		tickerWrap.addEventListener( 'mousemove', ( e ) => {
			const rect    = tickerWrap.getBoundingClientRect();
			const isRight = e.clientX > rect.right - 120;
			if ( isRight !== NowPlayingBlock.isSpedUp ) {
				NowPlayingBlock.isSpedUp = isRight;
				NowPlayingBlock.applySpeed( tickerInner, isRight ? 4 : 1 );
			}
		} );
		tickerWrap.addEventListener( 'mouseleave', () => {
			if ( NowPlayingBlock.isSpedUp ) {
				NowPlayingBlock.isSpedUp = false;
				NowPlayingBlock.applySpeed( tickerInner, 1 );
			}
		} );

		// When the scroll completes, re-render from the cached queue and restart.
		tickerInner.addEventListener( 'transitionend', () => {
			NowPlayingBlock.renderTicker( tickerInner, NowPlayingBlock.queueCache );
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
					NowPlayingBlock.setQueue( data.queue, tickerInner );
					if ( youtubeUrl ) {
						const videoId = NowPlayingBlock.parseVideoId( youtubeUrl );
						if ( videoId ) {
							NowPlayingBlock.loadVideo( videoId );
						}
					}
				} )
				.catch( () => {} );
		} );

		// Initial load, then keep the cache fresh in the background.
		NowPlayingBlock.startQueuePolling( restUrl, tickerInner );

		// Start polling for admin-triggered actions (e.g. set-on-stage).
		if ( actionsUrl && venueSlug ) {
			NowPlayingBlock.startActionPolling( actionsUrl, venueSlug, tickerInner );
		}
	},
};

window.addEventListener( 'DOMContentLoaded', () => {
	NowPlayingBlock.init();
} );
