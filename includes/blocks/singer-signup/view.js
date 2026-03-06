/**
 * Singer Signup Block — frontend behaviour.
 *
 * Handles form submission and shows a success message on completion.
 *
 * @package WPKQ
 */

( function () {
	'use strict';

	const COOKIE_NAME = 'wpkq_singer_name';
	const COOKIE_TTL  = 6 * 60 * 60; // 6 hours in seconds.

	/**
	 * Write the singer name cookie.
	 *
	 * @param {string} name
	 */
	function saveSingerNameCookie( name ) {
		const expires = new Date( Date.now() + COOKIE_TTL * 1000 ).toUTCString();
		document.cookie = `${ COOKIE_NAME }=${ encodeURIComponent( name ) }; expires=${ expires }; path=/; SameSite=Lax`;
	}

	/**
	 * Read the singer name cookie, or return an empty string.
	 *
	 * @return {string}
	 */
	function readSingerNameCookie() {
		const match = document.cookie.match( new RegExp( '(?:^|; )' + COOKIE_NAME + '=([^;]*)' ) );
		return match ? decodeURIComponent( match[ 1 ] ) : '';
	}

	document.querySelectorAll( '.wpkq-signup' ).forEach( function ( wrapper ) {
		const signupUrl = wrapper.dataset.signupUrl;
		const venueSlug = wrapper.dataset.venueSlug;

		if ( ! signupUrl || ! venueSlug ) {
			return;
		}

		const form        = wrapper.querySelector( '.wpkq-signup__form' );
		const successEl   = wrapper.querySelector( '.wpkq-signup__success' );
		const errorEl     = wrapper.querySelector( '.wpkq-signup__error' );
		const submitBtn   = wrapper.querySelector( '.wpkq-signup__submit' );
		const anotherBtn  = wrapper.querySelector( '.wpkq-signup__another' );

		if ( ! form || ! successEl ) {
			return;
		}

		/**
		 * Show an inline error message.
		 *
		 * @param {string} message
		 */
		function showError( message ) {
			errorEl.textContent = message;
			errorEl.hidden      = false;
		}

		/**
		 * Clear the inline error message.
		 */
		function clearError() {
			errorEl.textContent = '';
			errorEl.hidden      = true;
		}

		/**
		 * Show the success state and hide the form.
		 */
		function showSuccess() {
			form.hidden      = true;
			successEl.hidden = false;
		}

		/**
		 * Reset to an empty form (for signing up another singer).
		 */
		function resetForm() {
			form.reset();
			clearError();
			form.hidden      = false;
			successEl.hidden = true;

			const nameInput = form.querySelector( '[name="singer_name"]' );
			const savedName = readSingerNameCookie();
			nameInput.value = savedName;

			if ( savedName ) {
				form.querySelector( '[name="song"]' ).focus();
			} else {
				nameInput.focus();
			}
		}

		form.addEventListener( 'submit', async function ( e ) {
			e.preventDefault();
			clearError();

			const name = form.querySelector( '[name="singer_name"]' ).value.trim();
			const artist = form.querySelector( '[name="artist"]' ).value.trim();
			const song = form.querySelector( '[name="song"]' ).value.trim();

			if ( ! name ) {
				showError( 'Please enter your name.' );
				form.querySelector( '[name="singer_name"]' ).focus();
				return;
			}

			if ( ! song ) {
				showError( 'Please enter a song title.' );
				form.querySelector( '[name="song"]' ).focus();
				return;
			}

			if ( ! artist ) {
				showError( 'Please enter an artist.' );
				form.querySelector( '[name="artist"]' ).focus();
				return;
			}

			submitBtn.disabled   = true;
			submitBtn.textContent = 'Signing up…';

			try {
				const response = await fetch( signupUrl, {
					method:  'POST',
					headers: { 'Content-Type': 'application/json' },
					body:    JSON.stringify( {
						slug:        venueSlug,
						singer_name: name,
						song:        form.querySelector( '[name="song"]' ).value.trim(),
						artist:      form.querySelector( '[name="artist"]' ).value.trim(),
					} ),
				} );

				const data = await response.json();

				if ( ! response.ok ) {
					throw new Error( data.message || 'Something went wrong. Please try again.' );
				}

				saveSingerNameCookie( name );
				showSuccess();
			} catch ( err ) {
				showError( err.message || 'Something went wrong. Please try again.' );
			} finally {
				submitBtn.disabled    = false;
				submitBtn.textContent = 'Sign Up to Sing';
			}
		} );

		if ( anotherBtn ) {
			anotherBtn.addEventListener( 'click', resetForm );
		}

		// Focus the name field on initial load.
		form.querySelector( '[name="singer_name"]' ).focus();
	} );
} )();
