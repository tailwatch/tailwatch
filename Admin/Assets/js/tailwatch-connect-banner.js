/*
 * Tailwatch: closing or snoozing the "Connect your free Tailwatch account" banner on the
 * WordPress Dashboard. The banner leaves at once; the choice is stored for this administrator
 * through the plugin's own admin-ajax handler. Pure vanilla JS; no jQuery.
 */
(function () {
	'use strict';

	var config = window.tailwatchConnectBanner;
	var banner = document.getElementById( 'tailwatch-connect-banner' );

	if ( ! config || ! banner || ! window.fetch || ! window.FormData ) {
		return;
	}

	banner.addEventListener( 'click', function ( event ) {
		var trigger = event.target.closest ? event.target.closest( '[data-tailwatch-banner]' ) : null;
		if ( ! trigger || ! banner.contains( trigger ) ) {
			return;
		}

		var body = new window.FormData();
		body.append( 'action', 'tailwatch_global_ajax_handler' );
		body.append( 'action_type', config.action );
		body.append( 'nonce', config.nonce );
		body.append( 'data', JSON.stringify( { choice: trigger.getAttribute( 'data-tailwatch-banner' ) } ) );

		banner.parentNode.removeChild( banner );

		window.fetch( config.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: body
		} ).catch( function () {} );
	} );
})();
