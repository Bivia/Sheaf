/**
 * Book settings form behaviour + auto-save.
 *
 * There is no Save button: every change to a field auto-saves the whole form
 * over AJAX (like the chapter-reorder and style-set screens), with a small
 * status line and inline divider-HTML warnings.
 *
 * The "Enable full-book scrolling" checkbox grays out (disables) the full-book
 * options while it is off; within them the section-break sub-fields gate on
 * their own checkbox, and a break's divider-HTML textarea shows only for the
 * "HTML divider" choices. In Display settings the custom list-style field
 * appears only for the "Custom" choice. Disabled fields are still read by the
 * serializer, so graying a field out never discards its stored value.
 */
( function () {
	'use strict';

	var cfg = window.SheafBookScroll || {};
	var form = document.querySelector( '.sheaf-scroll-settings' );
	if ( ! form ) {
		return;
	}

	var book        = form.getAttribute( 'data-book' );
	var enabled     = form.querySelector( '#sheaf-scroll-enabled' );
	var fullbook    = form.querySelector( '.sheaf-scroll-fullbook' );
	var special     = form.querySelector( '#sheaf-scroll-special-sections' );
	var sectionWrap = form.querySelector( '.sheaf-scroll-section-break' );
	var breaks      = form.querySelectorAll( '.sheaf-scroll-break' );
	var listStyle   = form.querySelector( '#sheaf-toc-list-style' );
	var custom      = form.querySelector( '.sheaf-toc-custom' );
	var status      = form.querySelector( '#sheaf-scroll-status' );
	var warnings    = form.querySelector( '#sheaf-scroll-warnings' );

	function show( el, on ) {
		if ( el ) {
			el.style.display = on ? '' : 'none';
		}
	}

	// Gray a container and disable/enable its controls.
	function setEnabled( container, on ) {
		if ( ! container ) {
			return;
		}
		container.classList.toggle( 'sheaf-scroll-disabled', ! on );
		var nodes = container.querySelectorAll( 'input, select, textarea' );
		for ( var i = 0; i < nodes.length; i++ ) {
			nodes[ i ].disabled = ! on;
		}
	}

	// Show a break's divider-HTML textarea only for the HTML-divider choices.
	function syncHtml( select ) {
		var wrap = form.querySelector( '.sheaf-scroll-html--' + select.getAttribute( 'data-html-target' ) );
		if ( ! wrap ) {
			return;
		}
		var v = select.value;
		show( wrap, 'hr' === v || 'hr_page_break' === v );
	}

	function syncUi() {
		var on = !! ( enabled && enabled.checked );
		setEnabled( fullbook, on );
		// Section-break sub-controls need both the master toggle and their own box.
		setEnabled( sectionWrap, on && !! ( special && special.checked ) );
		for ( var i = 0; i < breaks.length; i++ ) {
			syncHtml( breaks[ i ] );
		}
		show( custom, !! ( listStyle && 'custom' === listStyle.value ) );
	}

	// Serialize every sheaf_scroll[...] field, reading disabled ones too so a
	// grayed-out value still persists. Unchecked checkboxes are omitted (form
	// semantics — the server reads that as false).
	function serialize() {
		var params = new URLSearchParams();
		var fields = form.querySelectorAll( '[name^="sheaf_scroll"]' );
		for ( var i = 0; i < fields.length; i++ ) {
			var el = fields[ i ];
			if ( 'checkbox' === el.type || 'radio' === el.type ) {
				if ( el.checked ) {
					params.append( el.name, el.value );
				}
			} else {
				params.append( el.name, el.value );
			}
		}
		params.append( 'action', 'sheaf_scroll_settings' );
		params.append( 'nonce', cfg.nonce || '' );
		params.append( 'book', book );
		return params;
	}

	function setStatus( text ) {
		if ( status ) {
			status.textContent = text;
		}
	}

	function renderWarnings( list ) {
		if ( ! warnings ) {
			return;
		}
		warnings.innerHTML = '';
		if ( ! list || ! list.length ) {
			return;
		}
		for ( var i = 0; i < list.length; i++ ) {
			var notice = document.createElement( 'div' );
			notice.className = 'notice notice-warning inline';
			var p = document.createElement( 'p' );
			p.textContent = ( cfg.warnPrefix || 'Divider HTML may be malformed:' ) + ' ' + list[ i ];
			notice.appendChild( p );
			warnings.appendChild( notice );
		}
	}

	function save() {
		setStatus( cfg.savingText || 'Saving…' );
		fetch( cfg.ajax, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: serialize().toString(),
		} )
			.then( function ( response ) {
				return response.json();
			} )
			.then( function ( result ) {
				if ( result && result.success ) {
					setStatus( cfg.savedText || 'Saved.' );
					renderWarnings( result.data && result.data.warnings );
				} else {
					setStatus( cfg.failedText || 'Save failed.' );
				}
			} )
			.catch( function () {
				setStatus( cfg.failedText || 'Save failed.' );
			} );
	}

	// Debounce so typing into a text field coalesces into one save on pause.
	var timer = null;
	function queueSave() {
		if ( timer ) {
			clearTimeout( timer );
		}
		timer = setTimeout( save, 400 );
	}

	form.addEventListener( 'change', function () {
		syncUi();
		queueSave();
	} );
	// Reveal fields responsively while typing/selecting, without saving yet.
	form.addEventListener( 'input', syncUi );

	syncUi();
}() );
