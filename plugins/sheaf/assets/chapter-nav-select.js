/**
 * Chapter drop-down navigator.
 *
 * Progressive enhancement for the two controls that render a chapter <select> —
 * Renderer::nav_toc_select() (the "full contents" navigation style) and
 * Renderer::crumb_chapter_select() (the last crumb of a breadcrumb trail). Each
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

	var selects = document.querySelectorAll(
		'.sheaf-chapter-nav__select, .sheaf-breadcrumbs__select'
	);
	for ( var i = 0; i < selects.length; i++ ) {
		bind( selects[ i ] );
	}
}() );
