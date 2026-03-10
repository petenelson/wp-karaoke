/**
 * WP Karaoke Queue Manager - Admin JS
 *
 * @package WPKQ
 */

import '../../css/admin/queue-admin.css';

const WPKQAmin = () => {
	'use strict';

	// ─── State ───────────────────────────────────────────────────────────────

	/** @type {Array} Full singer queue from the API */
	let queue = [];

	/** @type {boolean} True while a drag is in progress — suppresses auto-refresh */
	let isDragging = false;

	/** @type {Set<string>} Singer IDs currently being checked for YouTube playability */
	const checkingIds = new Set();

	/** Milliseconds between New Sign-Ups auto-refreshes */
	const PENDING_REFRESH_MS = parseInt( wpkqAdmin.refreshInterval, 10 ) || 1000;

	// ─── DOM References ───────────────────────────────────────────────────────

	const el = {
		loading:        document.getElementById( 'wpkq-loading' ),
		content:        document.getElementById( 'wpkq-content' ),
		saveStatus:     document.getElementById( 'wpkq-save-status' ),

		// Queue table
		queueTbody:     document.getElementById( 'wpkq-queue-tbody' ),
		queueEmpty:     document.getElementById( 'wpkq-queue-empty' ),
		queueCount:     document.getElementById( 'wpkq-queue-count' ),

		pendingTbody:   document.getElementById( 'wpkq-pending-tbody' ),
		pendingEmpty:   document.getElementById( 'wpkq-pending-empty' ),
		pendingCount:   document.getElementById( 'wpkq-pending-count' ),

		// Finished table
		finishedCount:  document.getElementById( 'wpkq-finished-count' ),
		finishedTbody:  document.getElementById( 'wpkq-finished-tbody' ),
		finishedEmpty:  document.getElementById( 'wpkq-finished-empty' ),

		// Modal
		modal:          document.getElementById( 'wpkq-modal' ),
		modalClose:     document.getElementById( 'wpkq-modal-close' ),
		modalCancel:    document.getElementById( 'wpkq-modal-cancel' ),
		modalSave:      document.getElementById( 'wpkq-modal-save' ),
		editIndex:      document.getElementById( 'wpkq-edit-index' ),
		editName:       document.getElementById( 'wpkq-edit-name' ),
		editSong:       document.getElementById( 'wpkq-edit-song' ),
		editArtist:     document.getElementById( 'wpkq-edit-artist' ),

		refreshBtn:       document.getElementById( 'wpkq-refresh-btn' ),
		resetQueueBtn:    document.getElementById( 'wpkq-reset-queue-btn' ),

		// On Stage section
		onStageSection:   document.getElementById( 'wpkq-on-stage-section' ),
		onStageTbody:     document.getElementById( 'wpkq-on-stage-tbody' ),

		// Add Singer modal
		addSingerBtn:    document.getElementById( 'wpkq-add-singer-btn' ),
		addModal:        document.getElementById( 'wpkq-add-modal' ),
		addModalClose:   document.getElementById( 'wpkq-add-modal-close' ),
		addModalCancel:  document.getElementById( 'wpkq-add-modal-cancel' ),
		addModalSave:    document.getElementById( 'wpkq-add-modal-save' ),
		addName:         document.getElementById( 'wpkq-add-name' ),
		addSong:         document.getElementById( 'wpkq-add-song' ),
		addArtist:       document.getElementById( 'wpkq-add-artist' ),
		addYoutubeUrl:   document.getElementById( 'wpkq-add-youtube-url' ),

		// Edit modal — YouTube URL
		editYoutubeUrl:  document.getElementById( 'wpkq-edit-youtube-url' ),
	};

	const { apiBase, nonce, venueSlug, i18n } = wpkqAdmin;

	const singerApiBase = apiBase + '/singer';
	const reorderApiUrl = apiBase + '/reorder';

	// ─── Utilities ────────────────────────────────────────────────────────────

	/**
	 * Generate a random 10-character alphanumeric ID.
	 *
	 * @return {string}
	 */
	function generateId() {
		const chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
		let id = '';
		const array = new Uint8Array( 10 );
		crypto.getRandomValues( array );
		array.forEach( function ( byte ) {
			id += chars[ byte % chars.length ];
		} );
		return id;
	}

	/**
	 * Format a Unix timestamp as a human-readable time string.
	 *
	 * @param {number} timestamp Unix timestamp in seconds.
	 * @return {string}
	 */
	function formatTime( timestamp ) {
		if ( ! timestamp ) {
			return '—';
		}
		const date = new Date( timestamp * 1000 );
		return date.toLocaleTimeString( [], { hour: '2-digit', minute: '2-digit' } );
	}

	/**
	 * Escape HTML special characters.
	 *
	 * @param {string} str
	 * @return {string}
	 */
	function escHtml( str ) {
		const div = document.createElement( 'div' );
		div.appendChild( document.createTextNode( str || '' ) );
		return div.innerHTML;
	}

	/**
	 * Show a transient save status message.
	 *
	 * @param {string}  message Message to display.
	 * @param {boolean} isError Whether this is an error.
	 */
	function showSaveStatus( message, isError = false ) {
		el.saveStatus.textContent = message;
		el.saveStatus.className = 'wpkq-save-status ' + ( isError ? 'wpkq-save-status--error' : 'wpkq-save-status--success' );
		clearTimeout( el.saveStatus._timeout );
		el.saveStatus._timeout = setTimeout( () => {
			el.saveStatus.textContent = '';
			el.saveStatus.className = 'wpkq-save-status';
		}, 3000 );
	}

	// ─── API ──────────────────────────────────────────────────────────────────

	/**
	 * Fetch the queue from the REST API.
	 *
	 * @return {Promise<void>}
	 */
	async function fetchQueue() {
		try {
			const url = `${ apiBase }?slug=${ encodeURIComponent( venueSlug ) }`;
			const response = await fetch( url, {
				headers: {
					'X-WP-Nonce': nonce,
				},
			} );

			if ( ! response.ok ) {
				throw new Error( `HTTP ${ response.status }` );
			}

			const data = await response.json();
			queue = data.queue || [];

			el.loading.style.display = 'none';
			el.content.style.display = '';
			renderAll();
		} catch ( err ) {
			el.loading.style.display = 'none';
			showSaveStatus( i18n.loadError, true );
			console.error( 'WPKQ fetchQueue error:', err );
		}
	}

	/**
	 * Make an authenticated JSON request to the REST API.
	 *
	 * @param {string} method  HTTP method.
	 * @param {string} url     Full URL.
	 * @param {Object} body    Request body.
	 * @return {Promise<Object>}
	 */
	async function apiRequest( method, url, body ) {
		const response = await fetch( url, {
			method,
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': nonce,
			},
			body: JSON.stringify( body ),
		} );

		if ( ! response.ok ) {
			throw new Error( `HTTP ${ response.status }` );
		}

		return response.json();
	}

	/**
	 * Add a singer to the queue via the REST API.
	 *
	 * @param {Object} singerData Full singer object.
	 * @return {Promise<void>}
	 */
	async function addSinger( singerData ) {
		showSaveStatus( i18n.saving );
		try {
			const data = await apiRequest( 'POST', singerApiBase, { slug: venueSlug, ...singerData } );
			queue = data.queue || queue;
			showSaveStatus( '✓ Singer added' );
			renderAll();
		} catch ( err ) {
			showSaveStatus( i18n.saveError, true );
			console.error( 'WPKQ addSinger error:', err );
		}
	}

	/**
	 * Update a single singer's fields via the REST API.
	 *
	 * @param {string} id     Singer ID.
	 * @param {Object} fields Fields to update.
	 * @return {Promise<void>}
	 */
	async function updateSinger( id, fields ) {
		showSaveStatus( i18n.saving );
		try {
			const data = await apiRequest( 'PUT', `${ singerApiBase }/${ id }`, { slug: venueSlug, ...fields } );
			queue = data.queue || queue;
			showSaveStatus( '✓ Saved' );
			renderAll();
		} catch ( err ) {
			showSaveStatus( i18n.saveError, true );
			console.error( 'WPKQ updateSinger error:', err );
		}
	}

	/**
	 * Delete a singer from the queue via the REST API.
	 *
	 * @param {string} id Singer ID.
	 * @return {Promise<void>}
	 */
	async function deleteSinger( id ) {
		showSaveStatus( i18n.saving );
		try {
			const data = await apiRequest( 'DELETE', `${ singerApiBase }/${ id }`, { slug: venueSlug } );
			queue = data.queue || queue;
			showSaveStatus( '✓ Singer removed' );
			renderAll();
		} catch ( err ) {
			showSaveStatus( i18n.saveError, true );
			console.error( 'WPKQ deleteSinger error:', err );
		}
	}

	/**
	 * Reorder the in-the-queue singers via the REST API.
	 *
	 * @param {string[]} order Array of singer IDs in the desired order.
	 * @return {Promise<void>}
	 */
	async function reorderQueue( order ) {
		showSaveStatus( i18n.saving );
		try {
			const data = await apiRequest( 'POST', reorderApiUrl, { slug: venueSlug, order } );
			queue = data.queue || queue;
			showSaveStatus( '✓ Order saved' );
			renderAll();
		} catch ( err ) {
			showSaveStatus( i18n.saveError, true );
			console.error( 'WPKQ reorderQueue error:', err );
		}
	}

	/**
	 * Reset the queue to empty via the REST API.
	 *
	 * @return {Promise<void>}
	 */
	async function resetQueue() {
		showSaveStatus( i18n.saving );
		try {
			const url = `${ apiBase }?slug=${ encodeURIComponent( venueSlug ) }`;
			const response = await fetch( url, {
				method: 'DELETE',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': nonce,
				},
			} );

			if ( ! response.ok ) {
				throw new Error( `HTTP ${ response.status }` );
			}

			const data = await response.json();
			queue = data.queue || [];
			showSaveStatus( '✓ Queue reset' );
			renderAll();
		} catch ( err ) {
			showSaveStatus( i18n.saveError, true );
			console.error( 'WPKQ resetQueue error:', err );
		}
	}

	/**
	 * Silently fetch the queue and re-render only the New Sign-Ups table.
	 * Used for the auto-refresh interval so other tables are not disturbed.
	 *
	 * @return {Promise<void>}
	 */
	async function refreshPending() {
		if ( ! isDragging ) {
			try {
				const url = `${ apiBase }?slug=${ encodeURIComponent( venueSlug ) }`;
				const response = await fetch( url, {
					headers: { 'X-WP-Nonce': nonce },
				} );

				if ( response.ok ) {
					const data = await response.json();
					queue = data.queue || [];
					renderPendingTable();
				}
			} catch ( err ) {
				// Silent — do not disrupt the UI for background refresh failures.
			}
		}

		setTimeout( refreshPending, PENDING_REFRESH_MS );
	}

	/**
	 * Silently fetch the queue and re-render only the On Stage section.
	 * Used for the auto-refresh interval so other tables are not disturbed.
	 *
	 * @return {Promise<void>}
	 */
	async function refreshOnStage() {
		if ( ! isDragging ) {
			try {
				const url = `${ apiBase }?slug=${ encodeURIComponent( venueSlug ) }`;
				const response = await fetch( url, {
					headers: { 'X-WP-Nonce': nonce },
				} );

				if ( response.ok ) {
					const data = await response.json();
					queue = data.queue || [];
					renderOnStageSection();
				}
			} catch ( err ) {
				// Silent — do not disrupt the UI for background refresh failures.
			}
		}

		setTimeout( refreshOnStage, PENDING_REFRESH_MS );
	}

	// ─── YouTube Playability Check ────────────────────────────────────────────

	/** @type {YT.Player|null} Reusable hidden YouTube player for URL validation */
	let ytCheckPlayer = null;

	/** @type {boolean} True once the YouTube IFrame API has fully loaded */
	let ytApiReady = false;

	/** @type {Function|null} Resolve callback for the current in-flight check */
	let ytCheckResolve = null;

	/** @type {number|null} Timeout ID for the current in-flight check */
	let ytCheckTimeout = null;

	/**
	 * Extract an 11-character YouTube video ID from a URL or bare ID.
	 *
	 * @param {string} url
	 * @return {string|null}
	 */
	function parseVideoId( url ) {
		if ( ! url ) {
			return null;
		}
		const patterns = [
			/[?&]v=([A-Za-z0-9_-]{11})/,
			/youtu\.be\/([A-Za-z0-9_-]{11})/,
			/\/embed\/([A-Za-z0-9_-]{11})/,
			/\/shorts\/([A-Za-z0-9_-]{11})/,
		];
		for ( const pattern of patterns ) {
			const match = url.match( pattern );
			if ( match ) {
				return match[ 1 ];
			}
		}
		if ( /^[A-Za-z0-9_-]{11}$/.test( url ) ) {
			return url;
		}
		return null;
	}

	/**
	 * Called by the YouTube IFrame API once it has loaded.
	 * Creates the hidden player instance used for URL validation.
	 */
	function handleYouTubeApiReady() {
		ytApiReady = true;

		const wrap          = document.createElement( 'div' );
		wrap.style.cssText  = 'position:absolute;left:-9999px;top:-9999px;width:1px;height:1px;overflow:hidden;';
		const playerEl      = document.createElement( 'div' );
		playerEl.id         = 'wpkq-yt-check-player';
		wrap.appendChild( playerEl );
		document.body.appendChild( wrap );

		ytCheckPlayer = new window.YT.Player( 'wpkq-yt-check-player', {
			width:      1,
			height:     1,
			playerVars: { autoplay: 0, controls: 0 },
			events: {
				onStateChange: ( e ) => {
					if ( e.data === window.YT.PlayerState.CUED ) {
						resolveYtCheck( true );
					}
				},
				onError: () => resolveYtCheck( false ),
			},
		} );
	}

	/**
	 * Load the YouTube IFrame API script (idempotent).
	 */
	function loadYouTubeApi() {
		if ( window.YT && window.YT.Player ) {
			handleYouTubeApiReady();
			return;
		}
		if ( document.getElementById( 'wpkq-yt-api' ) ) {
			return;
		}
		window.onYouTubeIframeAPIReady = handleYouTubeApiReady;
		const script  = document.createElement( 'script' );
		script.id     = 'wpkq-yt-api';
		script.src    = 'https://www.youtube.com/iframe_api';
		document.head.appendChild( script );
	}

	/**
	 * Settle the current in-flight check and clear its timeout.
	 *
	 * @param {boolean} playable
	 */
	function resolveYtCheck( playable ) {
		clearTimeout( ytCheckTimeout );
		ytCheckTimeout = null;
		if ( ytCheckResolve ) {
			ytCheckResolve( playable );
			ytCheckResolve = null;
		}
	}

	/**
	 * Cue a video in the hidden player and wait for a CUED or error event.
	 * Cancels any prior in-flight check before starting a new one.
	 *
	 * @param {string} videoId
	 * @return {Promise<boolean|null>} true = playable, false = not playable, null = timed out.
	 */
	function checkVideoPlayable( videoId ) {
		return new Promise( ( resolve ) => {
			if ( ! ytApiReady || ! ytCheckPlayer || ! ytCheckPlayer.cueVideoById ) {
				resolve( null );
				return;
			}

			// Cancel any prior in-flight check.
			if ( ytCheckResolve ) {
				clearTimeout( ytCheckTimeout );
				ytCheckResolve( null );
			}

			ytCheckResolve = resolve;
			ytCheckTimeout = setTimeout( () => {
				ytCheckResolve = null;
				resolve( null );
			}, 10000 );

			ytCheckPlayer.cueVideoById( videoId );
		} );
	}

	/**
	 * Check whether a singer's YouTube URL is playable and persist the result.
	 * Marks the singer as checking (spinner dot) while the check runs.
	 *
	 * @param {string} singerId
	 * @param {string} youtubeUrl
	 * @return {Promise<void>}
	 */
	async function checkYouTubePlayability( singerId, youtubeUrl ) {
		const videoId = parseVideoId( youtubeUrl );
		let playable;

		if ( ! videoId ) {
			playable = false;
		} else {
			playable = await checkVideoPlayable( videoId );
		}

		checkingIds.delete( singerId );

		// Verify the singer still has the same URL (may have changed during the check).
		const singer = queue.find( ( s ) => s.id === singerId );
		if ( null === playable || ! singer || singer.youtube_url !== youtubeUrl ) {
			renderAll();
			return;
		}

		try {
			const data = await apiRequest( 'PUT', `${ singerApiBase }/${ singerId }`, {
				slug:             venueSlug,
				youtube_playable: playable,
			} );
			queue = data.queue || queue;
		} catch ( err ) {
			console.error( 'WPKQ: Failed to save youtube_playable.', err );
		}

		renderAll();
	}

	// ─── Rendering ────────────────────────────────────────────────────────────

	/**
	 * Render a singer's name with a YouTube status dot when a URL is present.
	 *
	 * Dot states:
	 *  - checking (gray pulse): singer is in checkingIds
	 *  - green: youtube_playable === true
	 *  - red:   youtube_playable === false
	 *  - none:  no URL, or URL not yet checked
	 *
	 * @param {Object} singer Singer record.
	 * @return {string} HTML string.
	 */
	function renderNameWithYtDot( singer ) {
		const name = escHtml( singer.singer_name );

		if ( ! singer.youtube_url ) {
			return name;
		}

		if ( checkingIds.has( singer.id ) ) {
			return `${ name } <span class="wpkq-yt-dot wpkq-yt-dot--checking" title="Checking video&hellip;" aria-hidden="true"></span>`;
		}

		if ( true === singer.youtube_playable ) {
			return `${ name } <span class="wpkq-yt-dot wpkq-yt-dot--ok" title="Video available" aria-hidden="true"></span>`;
		}

		if ( false === singer.youtube_playable ) {
			return `${ name } <span class="wpkq-yt-dot wpkq-yt-dot--err" title="Video unavailable" aria-hidden="true"></span>`;
		}

		return name;
	}

	/**
	 * Render a singer's song title as a YouTube search link.
	 * Opens a pre-populated search for "artist song" in a new tab.
	 *
	 * @param {Object} singer Singer record.
	 * @return {string} HTML string.
	 */
	function renderSongLink( singer ) {
		if ( ! singer.song ) {
			return '—';
		}

		const query = [ 'karaoke', singer.artist, singer.song ].filter( Boolean ).join( ' ' );
		const url   = 'https://www.youtube.com/results?search_query=' + encodeURIComponent( query );

		return `<a href="${ escHtml( url ) }" target="_blank" rel="noopener noreferrer" class="wpkq-song-link">${ escHtml( singer.song ) }</a>`;
	}

	/**
	 * Render both tables.
	 */
	function renderAll() {
		renderOnStageSection();
		renderQueueTable();
		renderPendingTable();
		renderFinishedTable();
	}

	/**
	 * Build the action buttons for a singer row.
	 *
	 * @param {number} globalIndex Index of singer in the full queue array.
	 * @param {string} context     'queue', 'pending', or 'on-stage'.
	 * @param {number} pos         Position within the filtered list (queue context only).
	 * @param {number} total       Total items in the filtered list (queue context only).
	 * @return {string} HTML string.
	 */
	function buildActionButtons( globalIndex, context, pos = 0, total = 1 ) {
		let html = '<div class="wpkq-actions">';

		if ( 'queue' === context ) {
			html += `<button
				class="wpkq-btn wpkq-btn--move"
				data-action="move-up"
				data-index="${ globalIndex }"
				aria-label="Move up"
				${ 0 === pos ? 'disabled' : '' }
			>↑</button>`;
			html += `<button
				class="wpkq-btn wpkq-btn--move"
				data-action="move-down"
				data-index="${ globalIndex }"
				aria-label="Move down"
				${ pos === total - 1 ? 'disabled' : '' }
			>↓</button>`;
		}

		if ( 'pending' === context ) {
			html += `<button
				class="wpkq-btn wpkq-btn--add"
				data-action="add-to-queue"
				data-index="${ globalIndex }"
				aria-label="Add to queue"
			>Add to Queue</button>`;
		}

		if ( 'on-stage' !== context ) {
			html += `<button
				class="wpkq-btn wpkq-btn--edit"
				data-action="edit"
				data-index="${ globalIndex }"
				aria-label="Edit singer"
			>✏️ Edit</button>`;
		}

		if ( 'queue' === context ) {
			html += `<button
				class="wpkq-btn wpkq-btn--on-stage"
				data-action="on-stage"
				data-index="${ globalIndex }"
				aria-label="Mark as on stage"
			>🎤 On Stage</button>`;
		}

		if ( 'queue' === context || 'on-stage' === context ) {
			html += `<button
				class="wpkq-btn wpkq-btn--finish"
				data-action="finish"
				data-index="${ globalIndex }"
				aria-label="Mark as finished"
			>✅ Finished</button>`;
		}

		html += `<button
			class="wpkq-btn wpkq-btn--delete"
			data-action="delete"
			data-index="${ globalIndex }"
			aria-label="Delete singer"
		>🗑️ Delete</button>`;

		html += '</div>';
		return html;
	}

	/**
	 * Render the On Stage section (the single singer currently on stage).
	 */
	function renderOnStageSection() {
		const onStage = queue
			.map( ( s, i ) => ( { singer: s, index: i } ) )
			.find( ( { singer } ) => singer.status === 'on-stage' );

		if ( ! onStage ) {
			el.onStageSection.style.display = 'none';
			el.onStageTbody.innerHTML = '';
			return;
		}

		el.onStageSection.style.display = '';

		const { singer, index } = onStage;
		el.onStageTbody.innerHTML = `
			<tr class="wpkq-row wpkq-row--on-stage" data-index="${ index }">
				<td class="wpkq-col-name" data-label="Name">${ renderNameWithYtDot( singer ) }</td>
				<td class="wpkq-col-song" data-label="Song">${ renderSongLink( singer ) }</td>
				<td class="wpkq-col-artist" data-label="Artist">${ escHtml( singer.artist ) }</td>
				<td class="wpkq-col-time" data-label="Signed Up">${ formatTime( singer.signup_time ) }</td>
				<td class="wpkq-col-actions">${ buildActionButtons( index, 'on-stage' ) }</td>
			</tr>
		`;
	}

	/**
	 * Render the active queue table (status: in-the-queue).
	 */
	function renderQueueTable() {
		const singers = queue
			.map( ( s, i ) => ( { singer: s, index: i } ) )
			.filter( ( { singer } ) => singer.status === 'in-the-queue' );

		el.queueCount.textContent = singers.length;

		if ( 0 === singers.length ) {
			el.queueTbody.innerHTML = '';
			el.queueEmpty.style.display = '';
			return;
		}

		el.queueEmpty.style.display = 'none';

		el.queueTbody.innerHTML = singers.map( ( { singer, index }, pos ) => `
			<tr class="wpkq-row" data-index="${ index }">
				<td class="wpkq-col-drag wpkq-drag-handle" title="Drag to reorder">
					<span class="wpkq-drag-icon" aria-hidden="true">⠿</span>
				</td>
				<td class="wpkq-col-name" data-label="Name">${ renderNameWithYtDot( singer ) }</td>
				<td class="wpkq-col-song" data-label="Song">${ renderSongLink( singer ) }</td>
				<td class="wpkq-col-artist" data-label="Artist">${ escHtml( singer.artist ) }</td>
				<td class="wpkq-col-time" data-label="Signed Up">${ formatTime( singer.signup_time ) }</td>
				<td class="wpkq-col-actions">${ buildActionButtons( index, 'queue', pos, singers.length ) }</td>
			</tr>
		` ).join( '' );
	}

	/**
	 * Render the pending (kj-received) table.
	 */
	function renderPendingTable() {
		const singers = queue
			.map( ( s, i ) => ( { singer: s, index: i } ) )
			.filter( ( { singer } ) => singer.status === 'kj-received' );

		el.pendingCount.textContent = singers.length;

		if ( 0 === singers.length ) {
			el.pendingTbody.innerHTML = '';
			el.pendingEmpty.style.display = '';
			return;
		}

		el.pendingEmpty.style.display = 'none';

		el.pendingTbody.innerHTML = singers.map( ( { singer, index } ) => `
			<tr class="wpkq-row" data-index="${ index }">
				<td class="wpkq-col-name" data-label="Name">${ renderNameWithYtDot( singer ) }</td>
				<td class="wpkq-col-song" data-label="Song">${ renderSongLink( singer ) }</td>
				<td class="wpkq-col-artist" data-label="Artist">${ escHtml( singer.artist ) }</td>
				<td class="wpkq-col-time" data-label="Signed Up">${ formatTime( singer.signup_time ) }</td>
				<td class="wpkq-col-actions">${ buildActionButtons( index, 'pending' ) }</td>
			</tr>
		` ).join( '' );
	}

	/**
	 * Render the finished table (status: finished).
	 */
	function renderFinishedTable() {
		const singers = queue
			.map( ( s, i ) => ( { singer: s, index: i } ) )
			.filter( ( { singer } ) => singer.status === 'finished' );

		el.finishedCount.textContent = singers.length;

		if ( 0 === singers.length ) {
			el.finishedTbody.innerHTML = '';
			el.finishedEmpty.style.display = '';
			return;
		}

		el.finishedEmpty.style.display = 'none';

		el.finishedTbody.innerHTML = singers.map( ( { singer, index } ) => `
			<tr class="wpkq-row" data-index="${ index }">
				<td class="wpkq-col-name" data-label="Name">${ renderNameWithYtDot( singer ) }</td>
				<td class="wpkq-col-song" data-label="Song">${ renderSongLink( singer ) }</td>
				<td class="wpkq-col-artist" data-label="Artist">${ escHtml( singer.artist ) }</td>
				<td class="wpkq-col-time" data-label="Signed Up">${ formatTime( singer.signup_time ) }</td>
				<td class="wpkq-col-actions"></td>
			</tr>
		` ).join( '' );
	}

	// ─── SortableJS ───────────────────────────────────────────────────────────

	/**
	 * Initialize SortableJS on the active queue tbody.
	 */
	function initSortable() {
		if ( typeof Sortable === 'undefined' ) {
			console.warn( 'WPKQ: SortableJS not loaded.' );
			return;
		}

		Sortable.create( el.queueTbody, {
			animation: 150,
			handle: '.wpkq-drag-handle',
			ghostClass: 'wpkq-row--ghost',
			chosenClass: 'wpkq-row--chosen',
			dragClass: 'wpkq-row--drag',

			// Required for touch devices.
			forceFallback: true,
			fallbackOnBody: true,
			swapThreshold: 0.65,
			delayOnTouchOnly: true,
			delay: 100,

			onStart() {
				isDragging = true;
			},

			onEnd( evt ) {
				isDragging = false;
				const inQueueSingers = queue.filter( ( s ) => s.status === 'in-the-queue' );

				// Reorder only the in-the-queue singers.
				const moved = inQueueSingers.splice( evt.oldIndex, 1 )[ 0 ];
				inQueueSingers.splice( evt.newIndex, 0, moved );

				reorderQueue( inQueueSingers.map( ( s ) => s.id ) );
			},
		} );
	}

	// ─── Event Handlers ───────────────────────────────────────────────────────

	/**
	 * Handle button clicks in both tables via event delegation.
	 *
	 * @param {Event} e
	 */
	function handleTableClick( e ) {
		const btn = e.target.closest( '[data-action]' );
		if ( ! btn ) {
			return;
		}

		const action = btn.dataset.action;
		const index  = parseInt( btn.dataset.index, 10 );

		const singer = queue[ index ];
		if ( ! singer ) {
			return;
		}

		switch ( action ) {
			case 'move-up':
			case 'move-down': {
				const inQueueSingers = queue.filter( ( s ) => s.status === 'in-the-queue' );
				const pos = inQueueSingers.findIndex( ( s ) => s.id === singer.id );
				if ( 'move-up' === action && pos > 0 ) {
					[ inQueueSingers[ pos - 1 ], inQueueSingers[ pos ] ] = [ inQueueSingers[ pos ], inQueueSingers[ pos - 1 ] ];
					reorderQueue( inQueueSingers.map( ( s ) => s.id ) );
				} else if ( 'move-down' === action && pos < inQueueSingers.length - 1 ) {
					[ inQueueSingers[ pos ], inQueueSingers[ pos + 1 ] ] = [ inQueueSingers[ pos + 1 ], inQueueSingers[ pos ] ];
					reorderQueue( inQueueSingers.map( ( s ) => s.id ) );
				}
				break;
			}

			case 'add-to-queue':
				updateSinger( singer.id, { status: 'in-the-queue' } );
				break;

			case 'on-stage':
				updateSinger( singer.id, { status: 'on-stage' } );
				break;

			case 'edit':
				openEditModal( index );
				break;

			case 'finish':
				if ( window.confirm( i18n.confirmFinished ) ) {
					updateSinger( singer.id, {
						status:         'finished',
						completed_time: Math.floor( Date.now() / 1000 ),
					} );
				}
				break;

			case 'delete':
				if ( window.confirm( i18n.confirmDelete ) ) {
					deleteSinger( singer.id );
				}
				break;
		}
	}

	// ─── Add Singer Modal ─────────────────────────────────────────────────────

	/**
	 * Open the Add Singer modal.
	 */
	function openAddModal() {
		el.addName.value       = '';
		el.addSong.value       = '';
		el.addArtist.value     = '';
		el.addYoutubeUrl.value = '';

		el.addModal.style.display = '';
		el.addModal.removeAttribute( 'hidden' );
		el.addName.focus();
	}

	/**
	 * Close the Add Singer modal.
	 */
	function closeAddModal() {
		el.addModal.style.display = 'none';
	}

	/**
	 * Save the new singer from the Add Singer modal.
	 *
	 * @return {Promise<void>}
	 */
	async function saveAddModal() {
		const name = el.addName.value.trim().substring( 0, 50 );

		if ( ! name ) {
			el.addName.focus();
			return;
		}

		const youtubeUrl = el.addYoutubeUrl.value.trim();
		const newSinger  = {
			id:               generateId(),
			singer_name:      name,
			song:             el.addSong.value.trim().substring( 0, 50 ),
			artist:           el.addArtist.value.trim().substring( 0, 50 ),
			youtube_url:      youtubeUrl,
			youtube_playable: null,
			signup_time:      Math.floor( Date.now() / 1000 ),
			completed_time:   0,
			status:           'in-the-queue',
		};

		closeAddModal();
		await addSinger( newSinger );

		if ( youtubeUrl ) {
			checkingIds.add( newSinger.id );
			renderAll();
			checkYouTubePlayability( newSinger.id, youtubeUrl );
		}
	}

	// ─── Edit Modal ───────────────────────────────────────────────────────────

	/**
	 * Open the edit modal for a singer.
	 *
	 * @param {number} index Index in the queue array.
	 */
	function openEditModal( index ) {
		const singer = queue[ index ];
		if ( ! singer ) {
			return;
		}

		el.editIndex.value      = index;
		el.editName.value       = singer.singer_name || '';
		el.editSong.value       = singer.song || '';
		el.editArtist.value     = singer.artist || '';
		el.editYoutubeUrl.value = singer.youtube_url || '';

		el.modal.style.display = '';
		el.modal.removeAttribute( 'hidden' );

		// Focus first field for accessibility.
		el.editName.focus();
	}

	/**
	 * Close the edit modal.
	 */
	function closeEditModal() {
		el.modal.style.display = 'none';
		el.editIndex.value = '';
	}

	/**
	 * Save edits from the modal.
	 *
	 * @return {Promise<void>}
	 */
	async function saveEditModal() {
		const index = parseInt( el.editIndex.value, 10 );

		if ( isNaN( index ) || ! queue[ index ] ) {
			closeEditModal();
			return;
		}

		const singer     = queue[ index ];
		const newUrl     = el.editYoutubeUrl.value.trim();
		const urlChanged = newUrl !== ( singer.youtube_url || '' );

		const fields = {
			singer_name: el.editName.value.substring( 0, 50 ),
			song:        el.editSong.value.substring( 0, 50 ),
			artist:      el.editArtist.value.substring( 0, 50 ),
			youtube_url: newUrl,
		};

		// Reset playability when the URL changes so the dot clears until re-checked.
		if ( urlChanged ) {
			fields.youtube_playable = null;
		}

		const singerId = singer.id;
		closeEditModal();
		await updateSinger( singerId, fields );

		if ( urlChanged && newUrl ) {
			checkingIds.add( singerId );
			renderAll();
			checkYouTubePlayability( singerId, newUrl );
		}
	}

	// ─── Init ─────────────────────────────────────────────────────────────────

	/**
	 * Bind all event listeners.
	 */
	function bindEvents() {
		// Refresh button.
		el.refreshBtn.addEventListener( 'click', fetchQueue );

		// Reset queue button.
		el.resetQueueBtn.addEventListener( 'click', function () {
			if ( window.confirm( i18n.confirmReset ) ) {
				resetQueue();
			}
		} );

		// Table delegation.
		el.onStageTbody.addEventListener( 'click', handleTableClick );
		el.queueTbody.addEventListener( 'click', handleTableClick );
		el.pendingTbody.addEventListener( 'click', handleTableClick );

		// Add Singer modal.
		el.addSingerBtn.addEventListener( 'click', openAddModal );
		el.addModalClose.addEventListener( 'click', closeAddModal );
		el.addModalCancel.addEventListener( 'click', closeAddModal );
		el.addModalSave.addEventListener( 'click', saveAddModal );

		el.addModal.addEventListener( 'click', function ( e ) {
			if ( e.target === el.addModal ) {
				closeAddModal();
			}
		} );

		[ el.addName, el.addSong, el.addArtist, el.addYoutubeUrl ].forEach( function ( input ) {
			input.addEventListener( 'keydown', function ( e ) {
				if ( 'Enter' === e.key ) {
					saveAddModal();
				}
			} );
		} );

		// Modal.
		el.modalClose.addEventListener( 'click', closeEditModal );
		el.modalCancel.addEventListener( 'click', closeEditModal );
		el.modalSave.addEventListener( 'click', saveEditModal );

		// Close modal on overlay click.
		el.modal.addEventListener( 'click', function ( e ) {
			if ( e.target === el.modal ) {
				closeEditModal();
			}
		} );

		// Close modals on Escape key.
		document.addEventListener( 'keydown', function ( e ) {
			if ( 'Escape' === e.key ) {
				if ( el.modal.style.display !== 'none' ) {
					closeEditModal();
				}
				if ( el.addModal.style.display !== 'none' ) {
					closeAddModal();
				}
			}
		} );

		// Save on Enter in modal inputs.
		[ el.editName, el.editSong, el.editArtist, el.editYoutubeUrl ].forEach( function ( input ) {
			input.addEventListener( 'keydown', function ( e ) {
				if ( 'Enter' === e.key ) {
					saveEditModal();
				}
			} );
		} );
	}

	/**
	 * Bootstrap the app.
	 */
	function init() {
		if ( ! venueSlug ) {
			console.error( 'WPKQ: No venue slug provided.' );
			return;
		}

		bindEvents();
		initSortable();
		fetchQueue();
		loadYouTubeApi();

		// Start the auto-refresh chain.
		setTimeout( refreshPending, PENDING_REFRESH_MS );
		setTimeout( refreshOnStage, PENDING_REFRESH_MS );
	}

	init();
};

document.addEventListener( 'DOMContentLoaded', () => {
	WPKQAmin();
} );
