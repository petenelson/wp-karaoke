/**
 * Singer Signup Block — frontend behaviour.
 *
 * Handles form submission and shows a success message on completion.
 *
 * @package WPKQ
 */

import './style.css';

/**
 * Singer Signup Block module.
 */
const SingerSignupBlock = {

	COOKIE_NAME:           'wpkq_singer_name',
	COOKIE_TTL:            6 * 60 * 60, // 6 hours in seconds.
	SIGNUPS_COOKIE_PREFIX: 'wpkq_signups_',
	STATUS_POLL_MS:        20 * 1000,
	MAX_SIGNUP_IDS:        5,
	VISITOR_COOKIE:        'wpkq_visitor',
	REACTION_COOLDOWN_MS:  1500,
	REACTION_NOTE_MS:      5000,

	/** Visitor ID for this page view, so it stays stable even if cookies are blocked. */
	visitorId: '',

	/**
	 * Write the singer name cookie.
	 *
	 * @param {string} name
	 */
	saveSingerNameCookie: ( name ) => {
		const expires = new Date( Date.now() + SingerSignupBlock.COOKIE_TTL * 1000 ).toUTCString();
		document.cookie = `${ SingerSignupBlock.COOKIE_NAME }=${ encodeURIComponent( name ) }; expires=${ expires }; path=/; SameSite=Lax`;
	},

	/**
	 * Read the singer name cookie, or return an empty string.
	 *
	 * @return {string}
	 */
	readSingerNameCookie: () => {
		const match = document.cookie.match( new RegExp( '(?:^|; )' + SingerSignupBlock.COOKIE_NAME + '=([^;]*)' ) );
		return match ? decodeURIComponent( match[ 1 ] ) : '';
	},

	/**
	 * Read the list of this browser's private signup status tokens for a venue.
	 *
	 * @param {string} slug Venue slug.
	 * @return {string[]} Status tokens.
	 */
	readSignupTokens: ( slug ) => {
		const name  = SingerSignupBlock.SIGNUPS_COOKIE_PREFIX + slug;
		const match = document.cookie.match( new RegExp( '(?:^|; )' + name + '=([^;]*)' ) );

		if ( ! match ) {
			return [];
		}

		try {
			const tokens = JSON.parse( decodeURIComponent( match[ 1 ] ) );
			return Array.isArray( tokens ) ? tokens.filter( ( token ) => /^[A-Za-z0-9]+$/.test( token ) ) : [];
		} catch ( err ) {
			return [];
		}
	},

	/**
	 * Save the list of status tokens for a venue. An empty list removes the cookie.
	 *
	 * @param {string}   slug   Venue slug.
	 * @param {string[]} tokens Status tokens.
	 */
	saveSignupTokens: ( slug, tokens ) => {
		const name    = SingerSignupBlock.SIGNUPS_COOKIE_PREFIX + slug;
		const ttl     = tokens.length ? SingerSignupBlock.COOKIE_TTL * 1000 : -1000;
		const expires = new Date( Date.now() + ttl ).toUTCString();
		const value   = encodeURIComponent( JSON.stringify( tokens ) );

		document.cookie = `${ name }=${ value }; expires=${ expires }; path=/; SameSite=Lax`;
	},

	/**
	 * Add a status token for a venue, keeping only the most recent few.
	 *
	 * @param {string} slug  Venue slug.
	 * @param {string} token Status token.
	 */
	addSignupToken: ( slug, token ) => {
		const tokens = SingerSignupBlock.readSignupTokens( slug ).filter( ( existing ) => existing !== token );
		tokens.push( token );
		SingerSignupBlock.saveSignupTokens( slug, tokens.slice( -SingerSignupBlock.MAX_SIGNUP_IDS ) );
	},

	/**
	 * Build the status message for a single signup.
	 *
	 * @param {Object} singer Singer status from the REST API.
	 * @return {HTMLElement} Card element.
	 */
	buildStatusCard: ( singer ) => {
		const card  = document.createElement( 'div' );
		const title = document.createElement( 'p' );
		const msg   = document.createElement( 'p' );

		card.className  = `wpkq-signup__status-card wpkq-signup__status-card--${ singer.status }`;
		title.className = 'wpkq-signup__status-song';
		msg.className   = 'wpkq-signup__status-message';

		title.textContent = singer.artist ? `${ singer.song } by ${ singer.artist }` : singer.song;

		switch ( singer.status ) {
			case 'in-the-queue': {
				if ( 1 === singer.position ) {
					msg.textContent = "You're up next! Head toward the stage.";
					break;
				}

				const position = document.createElement( 'strong' );
				position.className   = 'wpkq-signup__status-position';
				position.textContent = `#${ singer.position }`;

				msg.append( "You're ", position, ' in line' );

				if ( null !== singer.wait_minutes ) {
					const wait = document.createElement( 'strong' );
					wait.textContent = `${ singer.wait_minutes } min`;
					msg.append( ', about ', wait );
				}

				msg.append( '.' );
				break;
			}
			case 'on-stage':
				msg.textContent = "You're on stage, go get 'em!";
				break;
			case 'finished':
				msg.textContent = 'Thanks for singing!';
				break;
			default:
				msg.textContent = 'Waiting for the KJ to add you to the queue.';
		}

		card.append( title, msg );
		return card;
	},

	/**
	 * Fetch and render the status of this browser's signups.
	 *
	 * @param {Object}  ctx    Wrapper context.
	 * @param {boolean} isView True when the singer opened or returned to the page (counted separately from background polls).
	 */
	refreshStatus: async ( ctx, isView = false ) => {
		const { statusEl, statusUrl, venueSlug } = ctx;
		const requested = SingerSignupBlock.readSignupTokens( venueSlug );

		if ( ! requested.length ) {
			statusEl.hidden = true;
			statusEl.replaceChildren();
			SingerSignupBlock.stopPolling( ctx );
			return;
		}

		SingerSignupBlock.startPolling( ctx );

		// Only the newest refresh may render, so a slow older response can't replace newer cards.
		ctx.refreshSeq = ( ctx.refreshSeq || 0 ) + 1;
		const seq      = ctx.refreshSeq;

		let data;
		try {
			const params   = new URLSearchParams( { slug: venueSlug, tokens: requested.join( ',' ), view: isView ? '1' : '0' } );
			const response = await fetch( `${ statusUrl }?${ params }` );

			if ( ! response.ok ) {
				return;
			}

			data = await response.json();
		} catch ( err ) {
			// Network hiccup: keep showing the last known status.
			return;
		}

		if ( seq !== ctx.refreshSeq ) {
			return;
		}

		const singers = Array.isArray( data.singers ) ? data.singers : [];
		const active  = singers
			.filter( ( singer ) => 'finished' !== singer.status )
			.map( ( singer ) => singer.token );

		// Drop only tokens this request checked that are gone or finished (finished ones show one last
		// time). Re-read the cookie so tokens added while the request was in flight are kept.
		const drop    = requested.filter( ( token ) => ! active.includes( token ) );
		const current = SingerSignupBlock.readSignupTokens( venueSlug ).filter( ( token ) => ! drop.includes( token ) );
		SingerSignupBlock.saveSignupTokens( venueSlug, current );

		// Show signups in the order they were made.
		singers.sort( ( a, b ) => requested.indexOf( a.token ) - requested.indexOf( b.token ) );

		statusEl.replaceChildren( ...singers.map( SingerSignupBlock.buildStatusCard ) );
		statusEl.hidden = ! singers.length;

		if ( ! current.length ) {
			SingerSignupBlock.stopPolling( ctx );
		}
	},

	/**
	 * Start polling for status updates, if not already running.
	 *
	 * @param {Object} ctx Wrapper context.
	 */
	startPolling: ( ctx ) => {
		if ( ctx.pollTimer ) {
			return;
		}

		ctx.pollTimer = setInterval( () => {
			if ( ! document.hidden ) {
				SingerSignupBlock.refreshStatus( ctx );
			}
		}, SingerSignupBlock.STATUS_POLL_MS );
	},

	/**
	 * Stop polling for status updates.
	 *
	 * @param {Object} ctx Wrapper context.
	 */
	stopPolling: ( ctx ) => {
		clearInterval( ctx.pollTimer );
		ctx.pollTimer = null;
	},

	/**
	 * Get (or create) this browser's random visitor ID, used only for reaction rate limiting.
	 *
	 * @return {string} Visitor ID.
	 */
	getVisitorId: () => {
		if ( SingerSignupBlock.visitorId ) {
			return SingerSignupBlock.visitorId;
		}

		const name  = SingerSignupBlock.VISITOR_COOKIE;
		const match = document.cookie.match( new RegExp( '(?:^|; )' + name + '=([a-z0-9]+)' ) );

		if ( match ) {
			SingerSignupBlock.visitorId = match[ 1 ];
			return SingerSignupBlock.visitorId;
		}

		const bytes = new Uint8Array( 12 );
		window.crypto.getRandomValues( bytes );
		const id      = Array.from( bytes, ( b ) => b.toString( 16 ).padStart( 2, '0' ) ).join( '' );
		const expires = new Date( Date.now() + SingerSignupBlock.COOKIE_TTL * 1000 ).toUTCString();

		document.cookie             = `${ name }=${ id }; expires=${ expires }; path=/; SameSite=Lax`;
		SingerSignupBlock.visitorId = id;
		return id;
	},

	/**
	 * Show a short note under the reaction buttons, cleared after a few seconds.
	 *
	 * @param {Object} rtx     Reactions context.
	 * @param {string} message Note text ('' clears it).
	 */
	setReactionNote: ( rtx, message ) => {
		clearTimeout( rtx.noteTimer );
		rtx.noteEl.textContent = message;

		if ( message ) {
			rtx.noteTimer = setTimeout( () => {
				rtx.noteEl.textContent = '';
			}, SingerSignupBlock.REACTION_NOTE_MS );
		}
	},

	/**
	 * Send an audience reaction to the monitor.
	 *
	 * @param {MouseEvent} e   Click event.
	 * @param {Object}     rtx Reactions context.
	 */
	sendReaction: async ( e, rtx ) => {
		const button = e.target.closest( '.wpkq-signup__reaction' );

		if ( ! button || rtx.coolingDown ) {
			return;
		}

		// Brief cooldown matching the server's per-visitor limit, with a little pop for feedback.
		rtx.coolingDown = true;
		rtx.buttons.forEach( ( btn ) => btn.setAttribute( 'aria-disabled', 'true' ) );
		button.classList.remove( 'is-sent' );
		void button.offsetWidth; // Restart the animation.
		button.classList.add( 'is-sent' );

		setTimeout( () => {
			rtx.coolingDown = false;
			rtx.buttons.forEach( ( btn ) => btn.removeAttribute( 'aria-disabled' ) );
		}, SingerSignupBlock.REACTION_COOLDOWN_MS );

		try {
			const response = await fetch( rtx.reactionsUrl, {
				method:  'POST',
				headers: { 'Content-Type': 'application/json' },
				body:    JSON.stringify( {
					slug:     rtx.venueSlug,
					reaction: button.dataset.reaction,
					visitor:  SingerSignupBlock.getVisitorId(),
				} ),
			} );

			if ( response.ok ) {
				SingerSignupBlock.setReactionNote( rtx, '' );
				return;
			}

			const data = await response.json().catch( () => ( {} ) );

			// Only explain "nobody's singing"; rate-limit hits are silently ignored.
			SingerSignupBlock.setReactionNote( rtx, 'no_one_on_stage' === data.code ? data.message : '' );
		} catch ( err ) {
			// Network hiccup: reactions are best-effort.
		}
	},

	/**
	 * Show an inline error message.
	 *
	 * @param {HTMLElement} errorEl
	 * @param {string}      message
	 */
	showError: ( errorEl, message ) => {
		errorEl.textContent = message;
		errorEl.hidden      = false;
	},

	/**
	 * Clear the inline error message.
	 *
	 * @param {HTMLElement} errorEl
	 */
	clearError: ( errorEl ) => {
		errorEl.textContent = '';
		errorEl.hidden      = true;
	},

	/**
	 * Show the success state and hide the form.
	 *
	 * @param {HTMLElement} form
	 * @param {HTMLElement} successEl
	 */
	showSuccess: ( form, successEl ) => {
		form.hidden      = true;
		successEl.hidden = false;
	},

	/**
	 * Reset to an empty form (for signing up another singer).
	 *
	 * @param {HTMLElement} form
	 * @param {HTMLElement} successEl
	 * @param {HTMLElement} errorEl
	 */
	resetForm: ( form, successEl, errorEl ) => {
		form.reset();
		SingerSignupBlock.clearError( errorEl );
		form.hidden      = false;
		successEl.hidden = true;

		const nameInput = form.querySelector( '[name="singer_name"]' );
		const savedName = SingerSignupBlock.readSingerNameCookie();
		nameInput.value = savedName;

		if ( savedName ) {
			form.querySelector( '[name="song"]' ).focus();
		} else {
			nameInput.focus();
		}
	},

	/**
	 * Handle form submission.
	 *
	 * @param {SubmitEvent} e
	 * @param {Object}      ctx
	 * @param {HTMLElement} ctx.form
	 * @param {HTMLElement} ctx.successEl
	 * @param {HTMLElement} ctx.errorEl
	 * @param {HTMLElement} ctx.submitBtn
	 * @param {string}      ctx.signupUrl
	 * @param {string}      ctx.venueSlug
	 * @param {HTMLElement} ctx.statusEl
	 * @param {string}      ctx.statusUrl
	 */
	handleSubmit: async ( e, ctx ) => {
		const { form, successEl, errorEl, submitBtn, signupUrl, venueSlug, statusEl, statusUrl } = ctx;
		e.preventDefault();
		SingerSignupBlock.clearError( errorEl );

		const name   = form.querySelector( '[name="singer_name"]' ).value.trim();
		const song   = form.querySelector( '[name="song"]' ).value.trim();
		const artist = form.querySelector( '[name="artist"]' ).value.trim();

		if ( ! name ) {
			SingerSignupBlock.showError( errorEl, 'Please enter your name.' );
			form.querySelector( '[name="singer_name"]' ).focus();
			return;
		}

		if ( ! song ) {
			SingerSignupBlock.showError( errorEl, 'Please enter a song title.' );
			form.querySelector( '[name="song"]' ).focus();
			return;
		}

		if ( ! artist ) {
			SingerSignupBlock.showError( errorEl, 'Please enter an artist.' );
			form.querySelector( '[name="artist"]' ).focus();
			return;
		}

		submitBtn.disabled    = true;
		submitBtn.textContent = 'Signing up…';

		try {
			const response = await fetch( signupUrl, {
				method:  'POST',
				headers: { 'Content-Type': 'application/json' },
				body:    JSON.stringify( { slug: venueSlug, singer_name: name, song, artist } ),
			} );

			const data = await response.json();

			if ( ! response.ok ) {
				throw new Error( data.message || 'Something went wrong. Please try again.' );
			}

			SingerSignupBlock.saveSingerNameCookie( name );
			SingerSignupBlock.showSuccess( form, successEl );

			if ( statusEl && statusUrl && data.status_token ) {
				SingerSignupBlock.addSignupToken( venueSlug, data.status_token );
				SingerSignupBlock.refreshStatus( ctx );
			}
		} catch ( err ) {
			SingerSignupBlock.showError( errorEl, err.message || 'Something went wrong. Please try again.' );
		} finally {
			submitBtn.disabled    = false;
			submitBtn.textContent = 'Sign Up to Sing';
		}
	},

	/**
	 * Initialise a single signup block wrapper.
	 *
	 * @param {HTMLElement} wrapper
	 */
	initWrapper: ( wrapper ) => {
		const signupUrl = wrapper.dataset.signupUrl;
		const venueSlug = wrapper.dataset.venueSlug;

		if ( ! signupUrl || ! venueSlug ) {
			return;
		}

		const form       = wrapper.querySelector( '.wpkq-signup__form' );
		const successEl  = wrapper.querySelector( '.wpkq-signup__success' );
		const errorEl    = wrapper.querySelector( '.wpkq-signup__error' );
		const submitBtn  = wrapper.querySelector( '.wpkq-signup__submit' );
		const anotherBtn = wrapper.querySelector( '.wpkq-signup__another' );
		const statusUrl  = wrapper.dataset.statusUrl || '';
		const statusEl   = wrapper.querySelector( '.wpkq-signup__status' );

		if ( ! form || ! successEl ) {
			return;
		}

		const ctx = { form, successEl, errorEl, submitBtn, signupUrl, venueSlug, statusEl, statusUrl, pollTimer: null, refreshSeq: 0 };

		// Audience reactions are enabled in the block settings.
		const reactionsUrl = wrapper.dataset.reactionsUrl || '';
		const reactionsEl  = wrapper.querySelector( '.wpkq-signup__reactions' );

		if ( reactionsUrl && reactionsEl ) {
			const rtx = {
				reactionsUrl,
				venueSlug,
				buttons:     reactionsEl.querySelectorAll( '.wpkq-signup__reaction' ),
				noteEl:      reactionsEl.querySelector( '.wpkq-signup__reactions-note' ),
				coolingDown: false,
				noteTimer:   null,
			};

			reactionsEl.addEventListener( 'click', ( e ) => SingerSignupBlock.sendReaction( e, rtx ) );
		}

		// Singer status is enabled in the block settings: show any existing signups and keep them fresh.
		if ( statusUrl && statusEl ) {
			SingerSignupBlock.refreshStatus( ctx, true );

			document.addEventListener( 'visibilitychange', () => {
				if ( ! document.hidden && ctx.pollTimer ) {
					SingerSignupBlock.refreshStatus( ctx, true );
				}
			} );
		}

		form.addEventListener( 'submit', ( e ) => SingerSignupBlock.handleSubmit( e, ctx ) );

		if ( anotherBtn ) {
			anotherBtn.addEventListener( 'click', () => SingerSignupBlock.resetForm( form, successEl, errorEl ) );
		}

		// Focus the name field on initial load.
		form.querySelector( '[name="singer_name"]' ).focus();
	},

	/**
	 * Initialise all signup block instances on the page.
	 */
	init: () => {
		document.querySelectorAll( '.wpkq-signup' ).forEach( SingerSignupBlock.initWrapper );
	},
};

window.addEventListener( 'DOMContentLoaded', () => {
	SingerSignupBlock.init();
} );
