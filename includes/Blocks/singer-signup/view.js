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

	COOKIE_NAME: 'wpkq_singer_name',
	COOKIE_TTL:  6 * 60 * 60, // 6 hours in seconds.

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
	 */
	handleSubmit: async ( e, { form, successEl, errorEl, submitBtn, signupUrl, venueSlug } ) => {
		e.preventDefault();
		SingerSignupBlock.clearError( errorEl );

		const name          = form.querySelector( '[name="singer_name"]' ).value.trim();
		const song          = form.querySelector( '[name="song"]' ).value.trim();
		const artist        = form.querySelector( '[name="artist"]' ).value.trim();
		const checkedEmoji  = form.querySelector( '[name="emoji"]:checked' );
		const emoji         = checkedEmoji ? checkedEmoji.value : '';

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
				body:    JSON.stringify( { slug: venueSlug, singer_name: name, song, artist, emoji } ),
			} );

			const data = await response.json();

			if ( ! response.ok ) {
				throw new Error( data.message || 'Something went wrong. Please try again.' );
			}

			SingerSignupBlock.saveSingerNameCookie( name );
			SingerSignupBlock.showSuccess( form, successEl );
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

		if ( ! form || ! successEl ) {
			return;
		}

		const ctx = { form, successEl, errorEl, submitBtn, signupUrl, venueSlug };

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
