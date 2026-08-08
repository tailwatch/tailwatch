document.addEventListener(
	'DOMContentLoaded',
	function () {
		if (typeof tailwatchDisableKeysOptions === 'undefined') {
			return;
		}

		function toast( message ) {
			if ( window.tailwatchToast && typeof window.tailwatchToast.warning === 'function' ) {
				window.tailwatchToast.warning( message );
			}
		}

		// AltGr on Windows fires ctrlKey + altKey simultaneously, which would
		// otherwise look like a Ctrl shortcut. getModifierState lets us tell
		// AltGr typing apart from a real Ctrl combo.
		function isAltGraph( e ) {
			return typeof e.getModifierState === 'function' && e.getModifierState( 'AltGraph' );
		}

		if (tailwatchDisableKeysOptions.contextMenu) {
			document.addEventListener(
				'contextmenu',
				function (e) {
					e.preventDefault();
					toast( 'Right-clicking is disabled on this website.' );
				}
			);
		}

		if (tailwatchDisableKeysOptions.inspectElement) {
			document.addEventListener(
				'keydown',
				function (e) {
					if (isAltGraph( e )) {
						return;
					}
					// e.code (physical key position) is used instead of e.key so
					// non-Latin layouts (Cyrillic, Greek, Arabic, etc.) cannot
					// bypass the block by producing a different character.
					var isLetterKey = e.code === 'KeyI' || e.code === 'KeyC' || e.code === 'KeyJ';
					var primary = e.ctrlKey || e.metaKey;
					var secondary = e.shiftKey || e.altKey;
					// Win/Linux: Ctrl+Shift+I/C/J. macOS: Cmd+Option+I/J.
					if (e.key === 'F12' || (isLetterKey && primary && secondary)) {
						e.preventDefault();
						toast( 'Inspect Element is disabled on this website.' );
					}
				}
			);
		}

		if (tailwatchDisableKeysOptions.copyPasteCut) {
			document.addEventListener(
				'copy',
				function (e) {
					e.preventDefault();
					toast( 'Copying content is disabled on this website.' );
				}
			);
			document.addEventListener(
				'cut',
				function (e) {
					e.preventDefault();
					toast( 'Cutting content is disabled on this website.' );
				}
			);
			document.addEventListener(
				'paste',
				function (e) {
					e.preventDefault();
					toast( 'Pasting content is disabled on this website.' );
				}
			);
		}

		if (tailwatchDisableKeysOptions.viewSource) {
			document.addEventListener(
				'keydown',
				function (e) {
					if (isAltGraph( e ) || e.code !== 'KeyU') {
						return;
					}
					// Win/Linux: Ctrl+U (no other modifier).
					// macOS: Cmd+Option+U.
					var isWinShortcut = e.ctrlKey && !e.metaKey && !e.altKey;
					var isMacShortcut = e.metaKey && e.altKey;
					if (isWinShortcut || isMacShortcut) {
						e.preventDefault();
						toast( 'Viewing page source is disabled on this website.' );
					}
				}
			);
		}

		if (tailwatchDisableKeysOptions.dragDrop) {
			document.addEventListener(
				'dragstart',
				function (event) {
					event.preventDefault();
					toast( 'Dragging and dropping images is disabled on this website.' );
				}
			);
		}
	}
);