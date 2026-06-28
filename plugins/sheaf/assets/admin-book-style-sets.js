/**
 * Auto-save the per-book "Style sets" checkboxes: toggling a box immediately
 * persists the book's active sets over AJAX, with a small status line — no
 * Save button. Deactivating a set never strips styling already applied to the
 * book's chapters; it only changes what the editor and importer offer.
 */
( function () {
	'use strict';

	var cfg = window.SheafBookStyleSets || {};
	var list = document.querySelector( '.sheaf-style-set-list' );
	if ( ! list ) {
		return;
	}

	var book = list.getAttribute( 'data-book' );
	var status = document.getElementById( 'sheaf-style-set-status' );

	function setStatus( text ) {
		if ( status ) {
			status.textContent = text;
		}
	}

	list.addEventListener( 'change', function ( event ) {
		if ( ! event.target || 'checkbox' !== event.target.type ) {
			return;
		}

		var sets = Array.prototype.map.call(
			list.querySelectorAll( 'input[type="checkbox"]:checked' ),
			function ( box ) {
				return box.value;
			}
		);

		var body = new URLSearchParams();
		body.append( 'action', 'sheaf_book_style_sets' );
		body.append( 'nonce', cfg.nonce || '' );
		body.append( 'book', book );
		sets.forEach( function ( value ) {
			body.append( 'sets[]', value );
		} );

		setStatus( cfg.savingText || 'Saving…' );

		fetch( cfg.ajax, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body.toString(),
		} )
			.then( function ( response ) {
				return response.json();
			} )
			.then( function ( result ) {
				setStatus( result && result.success ? ( cfg.savedText || 'Saved.' ) : ( cfg.failedText || 'Save failed.' ) );
			} )
			.catch( function () {
				setStatus( cfg.failedText || 'Save failed.' );
			} );
	} );
} )();
