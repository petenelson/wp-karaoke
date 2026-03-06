/**
 * WP Karaoke Queue Manager - Admin JS
 *
 * @package WPKQ
 */

import '../../css/admin/queue-admin.css';

( function () {
	'use strict';

	// ─── State ───────────────────────────────────────────────────────────────

	/** @type {Array} Full singer queue from the API */
	let queue = [];

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

	// ─── Rendering ────────────────────────────────────────────────────────────

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
	 * @param {string} context     'queue' or 'pending'.
	 * @return {string} HTML string.
	 */
	function buildActionButtons( globalIndex, context ) {
		let html = '<div class="wpkq-actions">';

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
				<td class="wpkq-col-name" data-label="Name">${ escHtml( singer.singer_name ) }</td>
				<td class="wpkq-col-song" data-label="Song">${ escHtml( singer.song ) }</td>
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

		el.queueTbody.innerHTML = singers.map( ( { singer, index } ) => `
			<tr class="wpkq-row" data-index="${ index }">
				<td class="wpkq-col-drag wpkq-drag-handle" title="Drag to reorder">
					<span class="wpkq-drag-icon" aria-hidden="true">⠿</span>
				</td>
				<td class="wpkq-col-name" data-label="Name">${ escHtml( singer.singer_name ) }</td>
				<td class="wpkq-col-song" data-label="Song">${ escHtml( singer.song ) }</td>
				<td class="wpkq-col-artist" data-label="Artist">${ escHtml( singer.artist ) }</td>
				<td class="wpkq-col-time" data-label="Signed Up">${ formatTime( singer.signup_time ) }</td>
				<td class="wpkq-col-actions">${ buildActionButtons( index, 'queue' ) }</td>
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
				<td class="wpkq-col-name" data-label="Name">${ escHtml( singer.singer_name ) }</td>
				<td class="wpkq-col-song" data-label="Song">${ escHtml( singer.song ) }</td>
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

		console.log(singers);

		el.finishedCount.textContent = singers.length;

		if ( 0 === singers.length ) {
			el.finishedTbody.innerHTML = '';
			el.finishedEmpty.style.display = '';
			return;
		}

		el.finishedEmpty.style.display = 'none';

		el.finishedTbody.innerHTML = singers.map( ( { singer, index } ) => `
			<tr class="wpkq-row" data-index="${ index }">
				<td class="wpkq-col-name" data-label="Name">${ escHtml( singer.singer_name ) }</td>
				<td class="wpkq-col-song" data-label="Song">${ escHtml( singer.song ) }</td>
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
			forceFallback: false,
			fallbackOnBody: true,
			swapThreshold: 0.65,

			onEnd( evt ) {
				const inQueueSingers = queue
					.filter( s => s.status === 'in-the-queue' );

				// Reorder only the in-the-queue singers.
				const moved = inQueueSingers.splice( evt.oldIndex, 1 )[ 0 ];
				inQueueSingers.splice( evt.newIndex, 0, moved );

				reorderQueue( inQueueSingers.map( s => s.id ) );
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
	 */
	function saveAddModal() {
		const name = el.addName.value.trim().substring( 0, 50 );

		if ( ! name ) {
			el.addName.focus();
			return;
		}

		const newSinger = {
			id:             generateId(),
			singer_name:    name,
			song:           el.addSong.value.trim().substring( 0, 50 ),
			artist:         el.addArtist.value.trim().substring( 0, 50 ),
			youtube_url:    el.addYoutubeUrl.value.trim(),
			signup_time:    Math.floor( Date.now() / 1000 ),
			completed_time: 0,
			status:         'in-the-queue',
		};

		closeAddModal();
		addSinger( newSinger );
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
	 */
	function saveEditModal() {
		const index = parseInt( el.editIndex.value, 10 );

		if ( isNaN( index ) || ! queue[ index ] ) {
			closeEditModal();
			return;
		}

		const singer = queue[ index ];
		const fields = {
			singer_name: el.editName.value.substring( 0, 50 ),
			song:        el.editSong.value.substring( 0, 50 ),
			artist:      el.editArtist.value.substring( 0, 50 ),
			youtube_url: el.editYoutubeUrl.value.trim(),
		};

		closeEditModal();
		updateSinger( singer.id, fields );
	}

	// ─── Init ─────────────────────────────────────────────────────────────────

	/**
	 * Bind all event listeners.
	 */
	function bindEvents() {
		// Refresh button.
		el.refreshBtn.addEventListener( 'click', fetchQueue );

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
	}

	// Kick off when DOM is ready.
	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
