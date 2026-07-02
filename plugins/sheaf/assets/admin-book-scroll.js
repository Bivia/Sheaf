/**
 * Book "Display settings" form behaviour.
 *
 * Grays out (disables) the dependent fields when full-book scrolling is off,
 * gates the section-break sub-fields on the "special section breaks" checkbox,
 * and reveals a break's divider-HTML textarea only for the "HTML divider"
 * choices. On submit every control is re-enabled so a disabled-but-configured
 * value still persists — toggling the feature off never discards the options.
 */
( function () {
	'use strict';

	var form = document.querySelector( '.sheaf-scroll-settings' );
	if ( ! form ) {
		return;
	}

	var enabled = form.querySelector( '#sheaf-scroll-enabled' );
	var dependent = form.querySelector( '.sheaf-scroll-dependent' );
	var special = form.querySelector( '#sheaf-scroll-special-sections' );
	var sectionWrap = form.querySelector( '.sheaf-scroll-section-break' );
	var breaks = form.querySelectorAll( '.sheaf-scroll-break' );

	if ( ! enabled ) {
		return;
	}

	// Toggle a container's grayed state and disable/enable its controls.
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
		var target = select.getAttribute( 'data-html-target' );
		var wrap = form.querySelector( '.sheaf-scroll-html--' + target );
		if ( ! wrap ) {
			return;
		}
		var v = select.value;
		wrap.style.display = ( 'hr' === v || 'hr_page_break' === v ) ? '' : 'none';
	}

	function syncAll() {
		setEnabled( dependent, enabled.checked );
		// Section break sub-controls need both the master toggle and the
		// special-sections checkbox on.
		setEnabled( sectionWrap, enabled.checked && !! ( special && special.checked ) );
		for ( var i = 0; i < breaks.length; i++ ) {
			syncHtml( breaks[ i ] );
		}
	}

	enabled.addEventListener( 'change', syncAll );
	if ( special ) {
		special.addEventListener( 'change', syncAll );
	}
	for ( var i = 0; i < breaks.length; i++ ) {
		breaks[ i ].addEventListener( 'change', function ( e ) {
			syncHtml( e.target );
		} );
	}

	// Re-enable everything before submit so disabled fields still POST their
	// values (an off toggle must not wipe the configured options).
	form.addEventListener( 'submit', function () {
		var nodes = form.querySelectorAll( 'input, select, textarea' );
		for ( var j = 0; j < nodes.length; j++ ) {
			nodes[ j ].disabled = false;
		}
	} );

	syncAll();
}() );
