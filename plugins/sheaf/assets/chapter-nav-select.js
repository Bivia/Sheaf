/**
 * Chapter navigation "full contents" drop-down.
 *
 * Progressive enhancement for the Renderer::nav_toc_select() control: each
 * option's value is a chapter permalink, so on change we simply navigate there.
 * Without this script the <select> is inert but harmless.
 */
( function () {
	'use strict';

	function bind( select ) {
		select.addEventListener( 'change', function () {
			var url = select.value;
			if ( url ) {
				window.location.href = url;
			}
		} );
	}

	var selects = document.querySelectorAll( '.sheaf-chapter-nav__select' );
	for ( var i = 0; i < selects.length; i++ ) {
		bind( selects[ i ] );
	}
}() );
