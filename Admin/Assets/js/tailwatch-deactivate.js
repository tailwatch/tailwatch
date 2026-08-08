/**
 * Tailwatch Deactivation Handler
 *
 * Handles plugin deactivation feedback modal and data cleanup options.
 */
(function ($) {
	'use strict';

	var deactivateUrl  = '';
	var isDeactivating = false;

	/**
	 * Initialize deactivation handler
	 */
	function init() {
		bindEvents();
	}

	/**
	 * Bind all event handlers
	 */
	function bindEvents() {
		// Intercept plugin deactivation link.
		$( document ).on( 'click', 'tr[data-slug="tailwatch"] .deactivate a', handleDeactivateClick );

		// Modal close handlers. Delegated from document so they keep working
		// regardless of when the modal markup is added to the page.
		$( document ).on( 'click', '.tailwatch_modal-close', closeModal );
		$( document ).on( 'click', '#deleteModal', handleOutsideClick );
		$( document ).on( 'keydown', handleEscapeKey );

		// Form submission handlers (delegated for the same reason).
		$( document ).on( 'click', '#tailwatch-deactivate', handleSubmitDeactivation );
		$( document ).on( 'click', '#tailwatch-skip-deactivate', handleSkipDeactivation );

		// Radio button selection.
		$( document ).on( 'click', '.tailwatch_option', handleRadioSelection );

		// Help link handlers.
		$( document ).on( 'click', '.tailwatch-help-link', handleHelpLink );
	}

	/**
	 * Handle deactivation link click
	 *
	 * @param {Event} e Click event
	 */
	function handleDeactivateClick(e) {
		e.preventDefault();
		deactivateUrl = $( this ).attr( 'href' );
		openModal();
	}

	/**
	 * Open deactivation modal
	 */
	function openModal() {
		$( '#deleteModal' ).css( 'display', 'flex' ).attr( 'aria-hidden', 'false' );
		$( 'body' ).css( 'overflow', 'hidden' );

		// Focus on first form element for accessibility.
		setTimeout(
			function () {
				$( '#deactivation-reason' ).focus();
			},
			100
		);
	}

	/**
	 * Close deactivation modal
	 */
	function closeModal() {
		if (isDeactivating) {
			return; // Prevent closing during deactivation.
		}

		$( '#deleteModal' ).css( 'display', 'none' ).attr( 'aria-hidden', 'true' );
		$( 'body' ).css( 'overflow', 'auto' );

		// Reset form.
		$( '#deleteForm' )[0].reset();
	}

	/**
	 * Handle click outside modal
	 *
	 * @param {Event} e Click event
	 */
	function handleOutsideClick(e) {
		if (e.target === this && ! isDeactivating) {
			closeModal();
		}
	}

	/**
	 * Handle Escape key press
	 *
	 * @param {Event} e Keyboard event
	 */
	function handleEscapeKey(e) {
		if (e.key === 'Escape' && $( '#deleteModal' ).css( 'display' ) === 'flex' && ! isDeactivating) {
			closeModal();
		}
	}

	/**
	 * Handle submit & deactivate button click
	 */
	function handleSubmitDeactivation() {
		var reason = $( '#deactivation-reason' ).val();

		// Validate reason selection.
		if ( ! reason) {
			showError( 'Please select a reason for deactivation.' );
			$( '#deactivation-reason' ).focus();
			return;
		}

		var dataHandling = $( 'input[name="data_handling"]:checked' ).val();
		var comments     = $( '#comments' ).val();

		setLoadingState( true );

		// Send feedback (optional - remove if endpoint not ready).
		sendFeedback( reason, comments, dataHandling )
			.always(
				function () {
					// Proceed with deactivation regardless of feedback success.
					if (dataHandling === 'remove') {
						deletePluginData();
					} else {
						deactivatePlugin();
					}
				}
			);
	}

	/**
	 * Send feedback to server via WordPress AJAX
	 *
	 * @param {string} reason Deactivation reason
	 * @param {string} comments Additional comments
	 * @param {string} dataHandling Data handling preference
	 * @return {Promise} jQuery AJAX promise
	 */
	function sendFeedback(reason, comments, dataHandling) {
		return $.ajax(
			{
				url: tailwatch_ajax.ajax_url,
				method: 'POST',
				timeout: 10000, // 10 second timeout.
				data: {
					action: tailwatch_ajax.submit_feedback_action,
					nonce: tailwatch_ajax.nonce,
					reason: reason,
					comments: comments,
					data_handling: dataHandling,
					domain: tailwatch_ajax.site_domain
				}
			}
		).fail(
			function () {
				// Silent fail - don't block deactivation if feedback fails.
			}
		);
	}

	/**
	 * Delete plugin data via AJAX
	 */
	function deletePluginData() {
		$.ajax(
			{
				url: tailwatch_ajax.ajax_url,
				method: 'POST',
				data: {
					action: tailwatch_ajax.delete_data_action,
					nonce: tailwatch_ajax.nonce
				},
				timeout: 10000 // 10 second timeout.
			}
		).done(
			function (response) {
				if (response.success) {
					deactivatePlugin();
				} else {
					handleError(
						response.data && response.data.message
						? response.data.message
						: 'Failed to prepare data deletion. Please try again.'
					);
				}
			}
		).fail(
			function (jqXHR) {
				var errorMsg = 'Failed to prepare data deletion. ';

				if (jqXHR.status === 403) {
					errorMsg += 'Permission denied.';
				} else if (jqXHR.status === 0) {
					errorMsg += 'Network error.';
				} else {
					errorMsg += 'Please try again.';
				}

				handleError( errorMsg );
			}
		);
	}

	/**
	 * Redirect to deactivation URL
	 */
	function deactivatePlugin() {
		// Guard: if the modal was shown without the deactivation link being
		// captured, there is nothing to redirect to. Re-enable the UI instead
		// of navigating to an empty URL (which would just reload the page).
		if ( ! deactivateUrl ) {
			setLoadingState( false );
			return;
		}
		setTimeout(
			function () {
				window.location.href = deactivateUrl;
			},
			500
		);
	}

	/**
	 * Handle skip & deactivate button click
	 */
	function handleSkipDeactivation() {
		var dataHandling = $( 'input[name="data_handling"]:checked' ).val();

		setLoadingState( true, 'skip' );

		if (dataHandling === 'remove') {
			deletePluginData();
		} else {
			deactivatePlugin();
		}
	}

	/**
	 * Handle radio button selection
	 *
	 * @param {Event} e Click event
	 */
	function handleRadioSelection(e) {
		// Don't trigger if clicking directly on the radio button.
		if ($( e.target ).is( 'input[type="radio"]' )) {
			return;
		}

		var $radio = $( this ).find( 'input[type="radio"]' );
		$radio.prop( 'checked', true ).trigger( 'change' );
	}

	/**
	 * Handle help link clicks
	 *
	 * @param {Event} e Click event
	 */
	function handleHelpLink(e) {
		e.preventDefault();

		var action = $( this ).data( 'action' );

		switch (action) {
			case 'documentation':
				if (tailwatch_ajax.documentation_url) {
					window.open( tailwatch_ajax.documentation_url, '_blank', 'noopener,noreferrer' );
				}
				break;

			case 'support':
				if (tailwatch_ajax.support_url) {
					window.open( tailwatch_ajax.support_url, '_blank', 'noopener,noreferrer' );
				}
				break;

			case 'settings':
				if (tailwatch_ajax.settings_url) {
					window.location.href = tailwatch_ajax.settings_url;
				}
				break;
		}
	}

	/**
	 * Set loading state for buttons
	 *
	 * @param {boolean} loading Loading state
	 * @param {string} type Button type ('submit' or 'skip')
	 */
	function setLoadingState(loading, type) {
		var $submitBtn = $( '#tailwatch-deactivate' );
		var $skipBtn   = $( '#tailwatch-skip-deactivate' );
		var $closeBtn  = $( '.tailwatch_modal-close' );

		isDeactivating = loading;

		if (loading) {
			if (type === 'skip') {
				$skipBtn.addClass( 'loading' )
					.text( 'Deactivating...' )
					.prop( 'disabled', true );
			} else {
				$submitBtn.addClass( 'loading' )
					.text( 'Deactivating...' )
					.prop( 'disabled', true );
			}

			// Disable all interactive elements.
			$submitBtn.prop( 'disabled', true );
			$skipBtn.prop( 'disabled', true );
			$closeBtn.prop( 'disabled', true ).css( 'opacity', '0.5' );
			$( '#deleteForm :input' ).prop( 'disabled', true );
		} else {
			// Reset buttons.
			$submitBtn.removeClass( 'loading' )
				.text( 'Submit & Deactivate' )
				.prop( 'disabled', false );
			$skipBtn.removeClass( 'loading' )
				.text( 'Skip & Deactivate' )
				.prop( 'disabled', false );
			$closeBtn.prop( 'disabled', false ).css( 'opacity', '1' );
			$( '#deleteForm :input' ).prop( 'disabled', false );
		}
	}

	/**
	 * Show error message
	 *
	 * @param {string} message Error message
	 */
	function showError(message) {
		// Remove existing error if present.
		$( '.tailwatch-error-message' ).remove();

		// Create error element.
		var $error = $(
			'<div class="tailwatch-error-message" role="alert">' +
			'<span class="dashicons dashicons-warning"></span> ' +
			escapeHtml( message ) +
			'</div>'
		);

		// Insert before form.
		$( '#deleteForm' ).before( $error );

		// Auto-remove after 5 seconds.
		setTimeout(
			function () {
				$error.fadeOut(
					function () {
						$( this ).remove();
					}
				);
			},
			5000
		);
	}

	/**
	 * Handle AJAX errors
	 *
	 * @param {string} message Error message
	 */
	function handleError(message) {
		setLoadingState( false );
		showError( message );
	}

	/**
	 * Escape HTML to prevent XSS
	 *
	 * @param {string} text Text to escape
	 * @return {string} Escaped text
	 */
	function escapeHtml(text) {
		var map = {
			'&': '&amp;',
			'<': '&lt;',
			'>': '&gt;',
			'"': '&quot;',
			"'": '&#039;'
		};
		return text.replace(
			/[&<>"']/g,
			function (m) {
				return map[m]; }
		);
	}

	// Initialize when DOM is ready.
	$( document ).ready( init );

})( jQuery );