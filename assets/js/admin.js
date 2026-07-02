/**
 * Woo Order Doctor admin scripts.
 *
 * Minimal progressive enhancement. The plugin works without JavaScript; this
 * only adds a small confirmation before the "Ignore" action so admins do not
 * dismiss issues by accident.
 */
( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', function () {
		// Find every "Ignore" submit button and ask for confirmation on click.
		var forms = document.querySelectorAll( '.wod-actions form, .wod-inline-form' );

		forms.forEach( function ( form ) {
			var statusInput = form.querySelector( 'input[name="new_status"]' );
			if ( ! statusInput || statusInput.value !== 'ignored' ) {
				return;
			}

			form.addEventListener( 'submit', function ( event ) {
				var confirmed = window.confirm(
					'Ignore this issue? It will be hidden from the open issues list.'
				);
				if ( ! confirmed ) {
					event.preventDefault();
				}
			} );
		} );
	} );
} )();
