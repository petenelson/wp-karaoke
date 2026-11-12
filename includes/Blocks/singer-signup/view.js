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
	 * Read the list of this browser's signup IDs for a venue.
	 *
	 * @param {string} slug Venue slug.
	 * @return {string[]} Signup IDs.
	 */
	readSignupIds: ( slug ) => {
		const name  = SingerSignupBlock.SIGNUPS_COOKIE_PREFIX + slug;
		const match = document.cookie.match( new RegExp( '(?:^|; )' + name + '=([^;]*)' ) );

		if ( ! match ) {
			return [];
		}

		try {
			const ids = JSON.parse( decodeURIComponent( match[ 1 ] ) );
			return Array.isArray( ids ) ? ids.filter( ( id ) => /^[A-Za-z0-9]+$/.test( id ) ) : [];
		} catch ( err ) {
			return [];
		}
	},

	/**
	 * Save the list of signup IDs for a venue. An empty list removes the cookie.
	 *
	 * @param {string}   slug Venue slug.
	 * @param {string[]} ids  Signup IDs.
	 */
	saveSignupIds: ( slug, ids ) => {
		const name    = SingerSignupBlock.SIGNUPS_COOKIE_PREFIX + slug;
		const ttl     = ids.length ? SingerSignupBlock.COOKIE_TTL * 1000 : -1000;
		const expires = new Date( Date.now() + ttl ).toUTCString();
		const value   = encodeURIComponent( JSON.stringify( ids ) );

		document.cookie = `${ name }=${ value }; expires=${ expires }; path=/; SameSite=Lax`;
	},

	/**
	 * Add a signup ID for a venue, keeping only the most recent few.
	 *
	 * @param {string} slug Venue slug.
	 * @param {string} id   Signup ID.
	 */
	addSignupId: ( slug, id ) => {
		const ids = SingerSignupBlock.readSignupIds( slug ).filter( ( existing ) => existing !== id );
		ids.push( id );
		SingerSignupBlock.saveSignupIds( slug, ids.slice( -SingerSignupBlock.MAX_SIGNUP_IDS ) );
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
	 * @param {Object} ctx Wrapper context.
	 */
	refreshStatus: async ( ctx ) => {
		const { statusEl, statusUrl, venueSlug } = ctx;
		const ids = SingerSignupBlock.readSignupIds( venueSlug );

		if ( ! ids.length ) {
			statusEl.hidden = true;
			statusEl.replaceChildren();
			SingerSignupBlock.stopPolling( ctx );
			return;
		}

		SingerSignupBlock.startPolling( ctx );

		let data;
		try {
			const params   = new URLSearchParams( { slug: venueSlug, ids: ids.join( ',' ) } );
			const response = await fetch( `${ statusUrl }?${ params }` );

			if ( ! response.ok ) {
				return;
			}

			data = await response.json();
		} catch ( err ) {
			// Network hiccup: keep showing the last known status.
			return;
		}

		const singers = Array.isArray( data.singers ) ? data.singers : [];

		// Keep IDs that still exist and haven't finished; finished ones are shown one last time.
		const remaining = singers
			.filter( ( singer ) => 'finished' !== singer.status )
			.map( ( singer ) => singer.id );
		SingerSignupBlock.saveSignupIds( venueSlug, ids.filter( ( id ) => remaining.includes( id ) ) );

		// Show signups in the order they were made.
		singers.sort( ( a, b ) => ids.indexOf( a.id ) - ids.indexOf( b.id ) );

		statusEl.replaceChildren( ...singers.map( SingerSignupBlock.buildStatusCard ) );
		statusEl.hidden = ! singers.length;

		if ( ! remaining.length ) {
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

			if ( statusEl && statusUrl && data.id ) {
				SingerSignupBlock.addSignupId( venueSlug, data.id );
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

		const ctx = { form, successEl, errorEl, submitBtn, signupUrl, venueSlug, statusEl, statusUrl, pollTimer: null };

		// Singer status is enabled in the block settings: show any existing signups and keep them fresh.
		if ( statusUrl && statusEl ) {
			SingerSignupBlock.refreshStatus( ctx );

			document.addEventListener( 'visibilitychange', () => {
				if ( ! document.hidden && ctx.pollTimer ) {
					SingerSignupBlock.refreshStatus( ctx );
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
