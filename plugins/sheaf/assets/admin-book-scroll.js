/**
 * Book settings form behaviour.
 *
 * The reading-mode radio shows exactly one options block — the separate-page
 * navigation options or the full-book scrolling options — and hides the other.
 * Hiding (rather than disabling) keeps the hidden block's fields in the POST,
 * so switching modes never discards the other mode's configuration. Within the
 * full-book block it still gates the section-break sub-fields on their checkbox
 * and reveals a break's divider-HTML textarea only for the "HTML divider"
 * choices; in Display settings it reveals the custom list-style field.
 */
( function () {
	'use strict';

	var form = document.querySelector( '.sheaf-scroll-settings' );
	if ( ! form ) {
		return;
	}

	var modeRadios  = form.querySelectorAll( 'input[name="sheaf_scroll[enabled]"]' );
	var fullbook    = form.querySelector( '.sheaf-scroll-fullbook' );
	var separate    = form.querySelector( '.sheaf-scroll-separate' );
	var special     = form.querySelector( '#sheaf-scroll-special-sections' );
	var sectionWrap = form.querySelector( '.sheaf-scroll-section-break' );
	var breaks      = form.querySelectorAll( '.sheaf-scroll-break' );
	var listStyle   = form.querySelector( '#sheaf-toc-list-style' );
	var custom      = form.querySelector( '.sheaf-toc-custom' );

	function show( el, on ) {
		if ( el ) {
			el.style.display = on ? '' : 'none';
		}
	}

	// Full-book scrolling is on when the value="1" radio is the checked one.
	function fullbookOn() {
		for ( var i = 0; i < modeRadios.length; i++ ) {
			if ( modeRadios[ i ].checked ) {
				return '1' === modeRadios[ i ].value;
			}
		}
		return false;
	}

	function syncMode() {
		var on = fullbookOn();
		show( fullbook, on );
		show( separate, ! on );
	}

	function syncSection() {
		show( sectionWrap, !! ( special && special.checked ) );
	}

	// Show a break's divider-HTML textarea only for the HTML-divider choices.
	function syncHtml( select ) {
		var target = select.getAttribute( 'data-html-target' );
		var wrap = form.querySelector( '.sheaf-scroll-html--' + target );
		if ( ! wrap ) {
			return;
		}
		var v = select.value;
		show( wrap, 'hr' === v || 'hr_page_break' === v );
	}

	function syncCustom() {
		show( custom, !! ( listStyle && 'custom' === listStyle.value ) );
	}

	for ( var i = 0; i < modeRadios.length; i++ ) {
		modeRadios[ i ].addEventListener( 'change', syncMode );
	}
	if ( special ) {
		special.addEventListener( 'change', syncSection );
	}
	for ( var j = 0; j < breaks.length; j++ ) {
		breaks[ j ].addEventListener( 'change', function ( e ) {
			syncHtml( e.target );
		} );
	}
	if ( listStyle ) {
		listStyle.addEventListener( 'change', syncCustom );
	}

	syncMode();
	syncSection();
	for ( var k = 0; k < breaks.length; k++ ) {
		syncHtml( breaks[ k ] );
	}
	syncCustom();
}() );
