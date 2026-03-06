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

( function () {
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
	const tickerInner = wrapper.querySelector( '.wpkq-monitor__ticker-inner' );
	const urlForm     = wrapper.querySelector( '#wpkq-url-form' );
	const urlInput    = wrapper.querySelector( '#wpkq-url-input' );

	// -----------------------------------------------------------------------
	// YouTube IFrame Player API
	// -----------------------------------------------------------------------

	let player = null;

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
	function parseVideoId( input ) {
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
	}

	/**
	 * Load a video into the player by ID.
	 *
	 * @param {string} videoId YouTube video ID.
	 */
	function loadVideo( videoId ) {
		if ( player && typeof player.loadVideoById === 'function' ) {
			player.loadVideoById( videoId );
		}
	}

	// Initialise the YT.Player once the API script is ready.
	window.onYouTubeIframeAPIReady = function () {
		player = new window.YT.Player( 'wpkq-yt-player', {
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
		urlForm.addEventListener( 'submit', function ( e ) {
			e.preventDefault();

			const videoId = parseVideoId( urlInput.value );

			if ( videoId ) {
				loadVideo( videoId );
				urlInput.value = '';
				urlInput.blur();
			} else {
				urlInput.classList.add( 'wpkq-monitor__url-input--error' );
				setTimeout( function () {
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

	/**
	 * Build an array of ticker <span> elements from singers data.
	 *
	 * @param {Array} singers In-the-queue singers.
	 * @return {HTMLElement[]}
	 */
	function buildItems( singers ) {
		return singers.map( function ( singer ) {
			const isOnStage  = singer.status === 'on-stage';
			const prefix     = isOnStage ? 'On Stage: ' : '';
			const name       = singer.singer_name || '';
			const singerId   = singer.id || '';
			const youtubeUrl = singer.status === 'on-stage' ? '' : ( singer.youtube_url || '' );

			const span = document.createElement( 'span' );
			span.className = 'wpkq-monitor__ticker-item';

			if ( isOnStage ) {
				span.classList.add( 'wpkq-monitor__ticker-item--on-stage' );
			}

			if ( youtubeUrl ) {
				span.dataset.singerId = singerId;
				span.classList.add( 'wpkq-monitor__ticker-item--playable' );
			}

			if ( youtubeUrl ) {
				span.dataset.youtubeUrl = youtubeUrl;
			}

			span.textContent = prefix + name;
			return span;
		} );
	}

	/**
	 * Start a one-shot CSS transition that scrolls the ticker from the right
	 * edge of the container until the last item's left edge reaches the
	 * container's left edge, then fires 'transitionend'.
	 */
	function startScroll() {
		const lastItem = tickerInner.lastElementChild;
		if ( ! lastItem ) {
			return;
		}

		const containerWidth = tickerInner.parentElement.offsetWidth;
		const endX = -lastItem.offsetLeft;
		const distance = containerWidth - endX;
		const speed = 100; // px per second
		const duration = distance / speed;

		// Snap to start position without transition.
		tickerInner.style.transition = 'none';
		tickerInner.style.transform = `translateX( ${ containerWidth }px )`;

		// Force reflow so the snap takes effect before we re-enable transition.
		void tickerInner.offsetWidth;

		tickerInner.style.transition = `transform ${ duration }s linear`;
		tickerInner.style.transform = `translateX( ${ endX }px )`;
	}

	/**
	 * Render singer items into the ticker, then start the scroll.
	 *
	 * @param {Array} singers Singer objects from the REST API.
	 */
	function renderTicker( singers ) {
		tickerInner.innerHTML = '';

		const items = singers.length
			? buildItems( singers )
			: [ ( function () {
				const span = document.createElement( 'span' );
				span.className = 'wpkq-monitor__ticker-item';
				span.textContent = 'No singers in the queue.';
				return span;
			} )() ];

		items.forEach( function ( el ) {
			tickerInner.appendChild( el );
		} );

		startScroll();
	}

	/**
	 * Fetch the queue from the REST API and pass the first 4 singers to the callback.
	 *
	 * @param {Function} callback Receives an array of singer objects.
	 */
	function fetchQueue( callback ) {
		fetch( restUrl )
			.then( function ( res ) {
				return res.json();
			} )
			.then( function ( data ) {
				callback( data.queue || [] );
			} )
			.catch( function () {
				callback( [] );
			} );
	}

	// When the scroll completes, fetch a fresh queue and restart.
	tickerInner.addEventListener( 'transitionend', function () {
		fetchQueue( renderTicker );
	} );

	// Click a singer item to advance them to on-stage and load their video.
	tickerInner.addEventListener( 'click', function ( e ) {
		const item = e.target.closest( '[data-singer-id]' );
		if ( ! item ) {
			return;
		}

		// If this is the "On Stage" item, ignore clicks since they're already on stage and we don't want to reload the video.
		if ( item.classList.contains( 'wpkq-monitor__ticker-item--on-stage' ) ) {
			return;
		}

		const singerId   = item.dataset.singerId;
		const youtubeUrl = item.dataset.youtubeUrl || '';

		fetch( advanceUrl, {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify( { slug: venueSlug, id: singerId } ),
		} )
			.then( function ( res ) {
				return res.json();
			} )
			.then( function ( data ) {
				if ( data.queue ) {
					renderTicker( data.queue );
				}
				if ( youtubeUrl ) {
					const videoId = parseVideoId( youtubeUrl );
					if ( videoId ) {
						loadVideo( videoId );
					}
				}
			} )
			.catch( function () {} );
	} );

	// Initial load.
	fetchQueue( renderTicker );
} )();
