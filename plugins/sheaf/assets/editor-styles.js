/**
 * Editor integration for style sets (no build step; uses the wp.* globals).
 *
 * Turns a chapter's active style sets into editor controls:
 *   - inline styles -> rich-text formats (toolbar buttons, like bold), wrapping
 *     the selection in a <span> carrying the style's class;
 *   - block styles  -> paragraph block-style variations (WordPress applies an
 *     "is-style-<name>" class to the block).
 *
 * The list is computed server-side from the chapter's book at load time. The
 * book lives in a classic meta box outside the editor store, so when the author
 * changes it we only warn — the styles refresh on the next save + reload.
 */
( function ( wp ) {
	'use strict';

	var data = window.SheafStyles || {};
	var el = wp.element.createElement;
	var registerFormatType = wp.richText.registerFormatType;
	var toggleFormat = wp.richText.toggleFormat;
	var RichTextToolbarButton = wp.blockEditor.RichTextToolbarButton;
	var registerBlockStyle = wp.blocks.registerBlockStyle;

	( data.styles || [] ).forEach( function ( s ) {
		if ( 'block' === s.kind ) {
			registerBlockStyle( 'core/paragraph', { name: s.blockName, label: s.title } );
			return;
		}

		registerFormatType( s.name, {
			title: s.title,
			tagName: 'span',
			className: s.class,
			edit: function ( props ) {
				return el( RichTextToolbarButton, {
					icon: 'editor-textcolor',
					title: s.title,
					isActive: props.isActive,
					onClick: function () {
						props.onChange( toggleFormat( props.value, { type: s.name } ) );
					},
				} );
			},
		} );
	} );

	// Warn (without auto-reloading) when the author changes the chapter's book:
	// the style list above was built from the book at load time.
	wp.domReady( function () {
		var box = document.getElementById( 'sheaf-book' );
		if ( ! box ) {
			return;
		}
		var notices = wp.data && wp.data.dispatch( 'core/notices' );
		var NOTICE_ID = 'sheaf-style-book-changed';
		// wp_localize_script serializes the id as a string; compare as a number
		// so reverting the select back to the original book clears the warning.
		var loadedBook = parseInt( data.bookId, 10 ) || 0;

		function currentBook() {
			var sel = box.querySelector( 'select[name="sheaf_book"]:not([disabled])' );
			return sel ? ( parseInt( sel.value, 10 ) || 0 ) : 0;
		}

		function sync() {
			if ( ! notices ) {
				return;
			}
			if ( currentBook() !== loadedBook ) {
				notices.createWarningNotice( data.i18n.bookChanged, {
					id: NOTICE_ID,
					isDismissible: true,
				} );
			} else {
				notices.removeNotice( NOTICE_ID );
			}
		}

		box.addEventListener( 'change', function ( e ) {
			if ( e.target && 'sheaf_book' === e.target.name ) {
				sync();
			}
		} );
	} );
} )( window.wp );
