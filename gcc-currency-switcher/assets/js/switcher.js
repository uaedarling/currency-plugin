/**
 * GCC & Jordan Multi-Currency for WooCommerce - front-end switcher script.
 *
 * Vanilla JS, no dependencies. Handles:
 *  - Syncing prices on cached pages via the cache-compat AJAX endpoint.
 *  - Handling the currency dropdown change event.
 *
 * @package Gcc_Currency_Switcher
 */
( function () {
	'use strict';

	if ( typeof window.gccCsSettings === 'undefined' ) {
		return;
	}

	var settings = window.gccCsSettings;

	/**
	 * Perform a lightweight POST request to admin-ajax.php.
	 *
	 * @param {string}   action  AJAX action name.
	 * @param {Object}   data    Extra POST data.
	 * @param {Function} onDone  Callback invoked with parsed JSON response.
	 */
	function gccCsAjax( action, data, onDone ) {
		try {
			var xhr = new XMLHttpRequest();
			var params = 'action=' + encodeURIComponent( action ) + '&nonce=' + encodeURIComponent( settings.nonce );

			for ( var key in data ) {
				if ( Object.prototype.hasOwnProperty.call( data, key ) ) {
					params += '&' + encodeURIComponent( key ) + '=' + encodeURIComponent( data[ key ] );
				}
			}

			xhr.open( 'POST', settings.ajaxUrl, true );
			xhr.setRequestHeader( 'Content-Type', 'application/x-www-form-urlencoded' );
			xhr.onreadystatechange = function () {
				if ( 4 === xhr.readyState ) {
					if ( 200 === xhr.status ) {
						try {
							var response = JSON.parse( xhr.responseText );
							if ( typeof onDone === 'function' ) {
								onDone( response );
							}
						} catch ( e ) {
							// Fail silently and gracefully degrade.
						}
					}
				}
			};
			xhr.send( params );
		} catch ( e ) {
			// Network/AJAX failure: degrade gracefully, no console errors surfaced.
		}
	}

	/**
	 * On page load, verify the resolved currency (useful when a full-page
	 * cache served a stale currency to this visitor) and refresh price
	 * elements marked with data-gcc-cs-price if it differs from what was
	 * rendered server-side.
	 */
	function syncCachedCurrency() {
		gccCsAjax( settings.getAction, {}, function ( response ) {
			if ( ! response || ! response.success || ! response.data ) {
				return;
			}

			var resolved = response.data.currency;
			var body = document.body;

			if ( body && body.getAttribute( 'data-gcc-cs-currency' ) ) {
				var rendered = body.getAttribute( 'data-gcc-cs-currency' );

				if ( rendered && rendered !== resolved ) {
					body.setAttribute( 'data-gcc-cs-currency', resolved );
					document.dispatchEvent(
						new CustomEvent( 'gccCsCurrencyMismatch', { detail: response.data } )
					);
				}
			}

			var select = document.getElementById( 'gcc-cs-currency-select' );
			if ( select && select.value !== resolved ) {
				select.value = resolved;
			}
		} );
	}

	/**
	 * Wire up the currency dropdown(s) change event.
	 */
	function bindDropdown() {
		var selects = document.querySelectorAll( '.gcc-cs-currency-select' );

		for ( var i = 0; i < selects.length; i++ ) {
			selects[ i ].addEventListener( 'change', function ( event ) {
				var currency = event.target.value;

				gccCsAjax( settings.setAction, { currency: currency }, function ( response ) {
					if ( response && response.success ) {
						window.location.reload();
					}
				} );
			} );
		}
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', function () {
			syncCachedCurrency();
			bindDropdown();
		} );
	} else {
		syncCachedCurrency();
		bindDropdown();
	}
} )();
