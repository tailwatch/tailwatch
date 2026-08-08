/*
 * Tailwatch Toast — small in-house toast component.
 * Exposes window.tailwatchToast.{warning, error, success, info}(message).
 * Pure vanilla JS; no jQuery, no external lib.
 */
(function () {
	'use strict';

	var CONTAINER_ID = 'tailwatch-toast-container';
	var DISMISS_AFTER_MS = 5000;
	var FADE_OUT_MS = 300;

	function getContainer() {
		var c = document.getElementById( CONTAINER_ID );
		if ( ! c ) {
			c = document.createElement( 'div' );
			c.id = CONTAINER_ID;
			c.className = 'tailwatch-toast-container';
			document.body.appendChild( c );
		}
		return c;
	}

	function show( message, level ) {
		if ( ! message ) {
			return;
		}
		var toast = document.createElement( 'div' );
		toast.className = 'tailwatch-toast tailwatch-toast--' + ( level || 'info' );
		toast.textContent = String( message );
		getContainer().appendChild( toast );

		// Force layout so the transition fires when we add the visible class.
		// eslint-disable-next-line no-unused-expressions
		toast.offsetHeight;
		toast.classList.add( 'is-visible' );

		setTimeout( function () {
			toast.classList.remove( 'is-visible' );
			setTimeout( function () {
				if ( toast.parentNode ) {
					toast.parentNode.removeChild( toast );
				}
			}, FADE_OUT_MS );
		}, DISMISS_AFTER_MS );
	}

	window.tailwatchToast = {
		warning: function ( msg ) { show( msg, 'warning' ); },
		error:   function ( msg ) { show( msg, 'error' ); },
		success: function ( msg ) { show( msg, 'success' ); },
		info:    function ( msg ) { show( msg, 'info' ); }
	};
})();