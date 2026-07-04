/**
 * Order Health Doctor — dynamic admin behaviour.
 *
 * Progressive enhancement layer: animated health gauge, sparkline, no-reload
 * scan, and live single/bulk issue actions with toast feedback. Everything here
 * degrades gracefully — the classic POST forms still work without JavaScript.
 *
 * Uses plain fetch (no jQuery). Config comes from wp_localize_script (ohdAjax).
 */
( function () {
	'use strict';

	var A = window.ohdAjax || { ajaxUrl: '', nonce: '', i18n: {} };
	var I = A.i18n || {};

	/* ----------------------------------------------------------------- *
	 * Small helpers
	 * ----------------------------------------------------------------- */

	function qs( sel, root ) {
		return ( root || document ).querySelector( sel );
	}
	function qsa( sel, root ) {
		return Array.prototype.slice.call( ( root || document ).querySelectorAll( sel ) );
	}

	// POST to admin-ajax; resolves with the JSON `data` payload, rejects with a message.
	function post( action, extra ) {
		var body = new FormData();
		body.append( 'action', action );
		body.append( 'nonce', A.nonce );
		if ( extra ) {
			Object.keys( extra ).forEach( function ( k ) {
				var v = extra[ k ];
				if ( Array.isArray( v ) ) {
					v.forEach( function ( item ) {
						body.append( k + '[]', item );
					} );
				} else {
					body.append( k, v );
				}
			} );
		}
		return fetch( A.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } )
			.then( function ( r ) {
				return r.json();
			} )
			.then( function ( json ) {
				if ( json && json.success ) {
					return json.data || {};
				}
				throw new Error( ( json && json.data && json.data.message ) || I.genericError );
			} );
	}

	function toast( message, type ) {
		var box = qs( '#ohd-toasts' );
		if ( ! box ) {
			window.alert( message );
			return;
		}
		var el = document.createElement( 'div' );
		el.className = 'ohd-toast ohd-toast--' + ( type || 'success' );
		el.textContent = message;
		box.appendChild( el );
		// Force reflow so the entrance transition runs, then schedule removal.
		void el.offsetWidth;
		el.classList.add( 'is-visible' );
		setTimeout( function () {
			el.classList.remove( 'is-visible' );
			setTimeout( function () {
				if ( el.parentNode ) {
					el.parentNode.removeChild( el );
				}
			}, 300 );
		}, 3600 );
	}

	// Ease-out cubic tween of an integer from its current value to `target`.
	function tween( from, target, duration, onStep ) {
		var start = null;
		function frame( ts ) {
			if ( null === start ) {
				start = ts;
			}
			var p = Math.min( 1, ( ts - start ) / duration );
			var eased = 1 - Math.pow( 1 - p, 3 );
			onStep( Math.round( from + ( target - from ) * eased ) );
			if ( p < 1 ) {
				window.requestAnimationFrame( frame );
			}
		}
		window.requestAnimationFrame( frame );
	}

	/* ----------------------------------------------------------------- *
	 * Health gauge + counters + sparkline
	 * ----------------------------------------------------------------- */

	function animateGauge( gauge, target ) {
		if ( ! gauge ) {
			return;
		}
		var circ = parseFloat( gauge.getAttribute( 'data-circumference' ) ) || 0;
		var fill = qs( '.ohd-gauge-fill', gauge );
		var num = qs( '.ohd-gauge-num', gauge );
		var from = parseInt( num ? num.textContent : '0', 10 ) || 0;
		tween( from, target, 900, function ( val ) {
			if ( num ) {
				num.textContent = val;
			}
			if ( fill ) {
				fill.style.strokeDashoffset = circ * ( 1 - val / 100 );
			}
		} );
	}

	function animateCount( el, target ) {
		var from = parseInt( el.textContent, 10 ) || 0;
		if ( from === target ) {
			el.textContent = target;
			return;
		}
		tween( from, target, 700, function ( val ) {
			el.textContent = val;
		} );
	}

	function renderSparkline( svg ) {
		if ( ! svg ) {
			return;
		}
		var scores;
		try {
			scores = JSON.parse( svg.getAttribute( 'data-scores' ) || '[]' );
		} catch ( e ) {
			scores = [];
		}
		if ( scores.length < 2 ) {
			return;
		}
		var color = svg.getAttribute( 'data-color' ) || '#0d6efd';
		var w = 100, h = 40, pad = 3;
		var pts = scores.map( function ( s, i ) {
			var x = pad + ( i / ( scores.length - 1 ) ) * ( w - 2 * pad );
			var y = pad + ( 1 - Math.max( 0, Math.min( 100, s ) ) / 100 ) * ( h - 2 * pad );
			return x.toFixed( 1 ) + ',' + y.toFixed( 1 );
		} );
		var area = pad + ',' + ( h - pad ) + ' ' + pts.join( ' ' ) + ' ' + ( w - pad ) + ',' + ( h - pad );
		svg.innerHTML =
			'<polygon points="' + area + '" fill="' + color + '" fill-opacity="0.12" stroke="none"></polygon>' +
			'<polyline points="' + pts.join( ' ' ) + '" fill="none" stroke="' + color +
			'" stroke-width="2" stroke-linejoin="round" stroke-linecap="round"></polyline>';
	}

	// Apply a dashboard-state payload (counts, gauge, badge, last scan, trend, recent).
	function applyState( d ) {
		if ( d.counts ) {
			qsa( '.ohd-count' ).forEach( function ( el ) {
				var k = el.getAttribute( 'data-count' );
				if ( Object.prototype.hasOwnProperty.call( d.counts, k ) ) {
					animateCount( el, d.counts[ k ] );
				}
			} );
		}
		var gauge = qs( '.ohd-gauge' );
		if ( gauge && typeof d.health !== 'undefined' ) {
			if ( d.health_hex ) {
				var fill = qs( '.ohd-gauge-fill', gauge );
				if ( fill ) {
					fill.setAttribute( 'stroke', d.health_hex );
				}
			}
			animateGauge( gauge, d.health );
			gauge.setAttribute( 'data-score', d.health );
		}
		var badge = qs( '.ohd-health-badge' );
		if ( badge && d.health_label ) {
			badge.textContent = d.health_label;
			if ( d.health_band ) {
				badge.className = 'badge ' + d.health_band + ' ohd-health-badge';
			}
		}
		if ( d.last_scan ) {
			var ls = qs( '#ohd-last-scan' );
			if ( ls ) {
				ls.textContent = d.last_scan;
			}
		}
		if ( d.sparkline ) {
			var sp = qs( '.ohd-sparkline' );
			if ( sp ) {
				sp.setAttribute( 'data-scores', JSON.stringify( d.sparkline ) );
				renderSparkline( sp );
			}
		}
		if ( typeof d.recent_html !== 'undefined' ) {
			var body = qs( '#ohd-recent-body' );
			if ( body ) {
				body.innerHTML = d.recent_html;
			}
		}
	}

	/* ----------------------------------------------------------------- *
	 * Scan (dashboard)
	 * ----------------------------------------------------------------- */

	function initScan() {
		var form = qs( '#ohd-scan-form' );
		if ( ! form ) {
			return;
		}
		var btn = qs( '#ohd-scan-btn', form );
		var label = qs( '.ohd-scan-label', btn );

		form.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			btn.disabled = true;
			btn.classList.add( 'is-loading' );
			if ( label ) {
				label.textContent = I.scanning;
			}

			post( 'ohd_run_scan' )
				.then( function ( d ) {
					applyState( d );
					toast( d.message || I.scanComplete, 'success' );
				} )
				.catch( function ( err ) {
					toast( err.message || I.genericError, 'error' );
				} )
				.then( function () {
					btn.disabled = false;
					btn.classList.remove( 'is-loading' );
					if ( label ) {
						label.textContent = I.runScan;
					}
				} );
		} );
	}

	/* ----------------------------------------------------------------- *
	 * Issue actions (single + bulk) on the Issues page
	 * ----------------------------------------------------------------- */

	function currentStatusFilter() {
		var m = window.location.search.match( /[?&]status=([^&]*)/ );
		return m ? decodeURIComponent( m[ 1 ] ) : '';
	}

	function capitalize( s ) {
		return s ? s.charAt( 0 ).toUpperCase() + s.slice( 1 ) : s;
	}

	// Reflect a status change in a row: update the badge, and fade the row out if
	// it no longer matches the active status filter.
	function reflectRow( id, status ) {
		var row = qs( 'tr[data-issue-id="' + id + '"]' );
		if ( ! row ) {
			return;
		}
		var cell = qs( '.ohd-status-cell', row );
		if ( cell ) {
			cell.textContent = capitalize( status );
		}
		var filter = currentStatusFilter();
		if ( filter && filter !== status ) {
			row.classList.add( 'ohd-row-leaving' );
			setTimeout( function () {
				if ( row.parentNode ) {
					row.parentNode.removeChild( row );
				}
			}, 320 );
		}
	}

	function updateBulkCount() {
		var count = qsa( '.ohd-check:checked' ).length;
		var el = qs( '.ohd-bulk-count' );
		if ( el ) {
			el.textContent = count ? I.selectedCount.replace( '%d', count ) : '';
		}
	}

	function initIssueActions() {
		var table = qs( '#ohd-issues-table' );
		if ( ! table ) {
			return;
		}

		// Single per-row actions: intercept the status-change button clicks so the
		// classic form never submits (and admin.js's confirm never double-fires).
		qsa( '.ohd-inline-form', table ).forEach( function ( form ) {
			var btn = qs( 'button[type="submit"]', form );
			var statusInput = qs( 'input[name="new_status"]', form );
			var idInput = qs( 'input[name="issue_id"]', form );
			if ( ! btn || ! statusInput || ! idInput ) {
				return;
			}
			btn.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				var status = statusInput.value;
				var id = idInput.value;
				if ( 'ignored' === status && ! window.confirm( I.confirmIgnore ) ) {
					return;
				}
				btn.disabled = true;
				post( 'ohd_issue_action', { issue_id: id, new_status: status } )
					.then( function ( d ) {
						reflectRow( id, status );
						applyState( d );
						toast( d.message || I.issueUpdated, 'success' );
					} )
					.catch( function ( err ) {
						toast( err.message || I.genericError, 'error' );
					} )
					.then( function () {
						btn.disabled = false;
					} );
			} );
		} );

		// Select-all.
		var all = qs( '#ohd-check-all' );
		if ( all ) {
			all.addEventListener( 'change', function () {
				qsa( '.ohd-check', table ).forEach( function ( cb ) {
					cb.checked = all.checked;
				} );
				updateBulkCount();
			} );
		}
		qsa( '.ohd-check', table ).forEach( function ( cb ) {
			cb.addEventListener( 'change', updateBulkCount );
		} );

		// Bulk apply.
		var apply = qs( '#ohd-bulk-apply' );
		var select = qs( '#ohd-bulk-action' );
		if ( apply && select ) {
			apply.addEventListener( 'click', function () {
				var status = select.value;
				var ids = qsa( '.ohd-check:checked' ).map( function ( cb ) {
					return cb.value;
				} );
				if ( ! status ) {
					toast( I.chooseAction, 'error' );
					return;
				}
				if ( ! ids.length ) {
					toast( I.selectFirst || 'Select at least one issue first.', 'error' );
					return;
				}
				if ( 'ignored' === status && ! window.confirm( I.confirmIgnore ) ) {
					return;
				}
				apply.disabled = true;
				post( 'ohd_bulk_action', { new_status: status, issue_ids: ids } )
					.then( function ( d ) {
						ids.forEach( function ( id ) {
							reflectRow( id, status );
						} );
						if ( all ) {
							all.checked = false;
						}
						updateBulkCount();
						applyState( d );
						toast( d.message || 'Issues updated.', 'success' );
					} )
					.catch( function ( err ) {
						toast( err.message || I.genericError, 'error' );
					} )
					.then( function () {
						apply.disabled = false;
					} );
			} );
		}
	}

	/* ----------------------------------------------------------------- *
	 * Boot
	 * ----------------------------------------------------------------- */

	document.addEventListener( 'DOMContentLoaded', function () {
		// Animate the gauge in from its rendered score.
		var gauge = qs( '.ohd-gauge' );
		if ( gauge ) {
			animateGauge( gauge, parseInt( gauge.getAttribute( 'data-score' ), 10 ) || 0 );
		}
		renderSparkline( qs( '.ohd-sparkline' ) );
		initScan();
		initIssueActions();
	} );
} )();
