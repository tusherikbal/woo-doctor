/**
 * Order Health Doctor admin scripts.
 *
 * Minimal progressive enhancement. The plugin works without JavaScript; this
 * only adds a small confirmation before the "Ignore" action so admins do not
 * dismiss issues by accident.
 */
( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', function () {
		var settingsTabs = document.getElementById( 'ohd-settings-tab-content' );
		var settingsNav = document.querySelector( '.ohd-settings-nav' );
		var activeTabInput = document.getElementById( 'ohd-active-settings-tab' );

		if ( settingsTabs ) {
			settingsTabs.classList.add( 'ohd-tabs-ready' );
		}
		if ( settingsNav ) {
			settingsNav.classList.add( 'ohd-tabs-ready' );
		}

		if ( activeTabInput ) {
			document.querySelectorAll( '#ohd-settings-tabs [data-bs-toggle="tab"]' ).forEach( function ( tabButton ) {
				tabButton.addEventListener( 'shown.bs.tab', function ( event ) {
					activeTabInput.value = event.target.id.replace( /^ohd-|-tab$/g, '' );
				} );
			} );
		}

		// Find every "Ignore" submit button and ask for confirmation on click.
		var forms = document.querySelectorAll( '.ohd-actions form, .ohd-inline-form' );

		forms.forEach( function ( form ) {
			var statusInput = form.querySelector( 'input[name="new_status"]' );
			if ( ! statusInput || statusInput.value !== 'ignored' ) {
				return;
			}

			form.addEventListener( 'submit', function ( event ) {
				var confirmed = window.confirm(
					window.ohdAjax.i18n.confirmIgnore
				);
				if ( ! confirmed ) {
					event.preventDefault();
				}
			} );
		} );
	} );
} )();
