/**
 * Style Sets admin: the Editor/Page Styles tabs and the Page Styles editor.
 *
 * The Page Styles editor lets an admin write CSS scoped to a set's body class.
 * A base block targets `body.sheaf-styleset-<slug>`; "Add Additional Targeted
 * Block" adds blocks that chain extra classes onto that selector. A sandboxed
 * iframe shows the compiled CSS applied to sample chapter content, live.
 *
 * No dependencies; everything is read from the DOM. The server
 * (Style_Sets::filter_page_css / clean_extra_selector) remains authoritative —
 * this only mirrors the rules for a faithful preview and quick feedback.
 */
( function () {
	'use strict';

	/* ------------------------------------------------------------------ Tabs */

	document.querySelectorAll( '.sheaf-tabs' ).forEach( function ( tabs ) {
		var scope = tabs.closest( '.sheaf-set' ) || document;
		tabs.addEventListener( 'click', function ( e ) {
			var btn = e.target.closest( '.sheaf-tab' );
			if ( ! btn ) {
				return;
			}
			var target = btn.getAttribute( 'data-target' );
			tabs.querySelectorAll( '.sheaf-tab' ).forEach( function ( b ) {
				var on = b === btn;
				b.classList.toggle( 'is-active', on );
				b.setAttribute( 'aria-selected', on ? 'true' : 'false' );
			} );
			scope.querySelectorAll( '.sheaf-tabpanel' ).forEach( function ( p ) {
				p.hidden = p.id !== target;
			} );
		} );
	} );

	/* ---------------------------------------------------- Page Styles editor */

	// Sample chapter content — mirrors the real markup authors target
	// (main > div.entry-content > h1/p/ul/blockquote) and is long and varied
	// enough (dialogue, a list, a blockquote, inline formatting) to preview real
	// typography and to scroll vertically.
	var SAMPLE =
		'<main><div class="entry-content">' +
		'<h1>The Cold Road</h1>' +
		// 1 — three sentences.
		'<p>The ash came down like snow that year, and no one spoke of it. She kept the ' +
		'lamp trimmed low, because oil was dear and the nights were long. Outside, the dark ' +
		'pressed at the glass like something patient.</p>' +
		// 2 — six sentences, with every inline flavour.
		'<p>The archivist had warned them once, in a voice like <em>dry paper</em>, that the ' +
		'<strong>lower stacks</strong> were not to be trusted after dark. He showed her the ' +
		'catalogue entry — <code>MS-0447</code> — and the marginal note beneath it. Water rose ' +
		'to the third shelf that winter, H<sub>2</sub>O finding every crack, and the ink bloomed ' +
		'like bruises. They measured the damp in grains per foot<sup>3</sup> and wrote the numbers ' +
		'down. Later, when the numbers stopped mattering, she kept writing them anyway. You can ' +
		'still <a href="#">read the ledger</a> if you know which drawer holds it.</p>' +
		// 3 — dialogue, four words, too short to wrap.
		'<p>&ldquo;I won&rsquo;t go back.&rdquo;</p>' +
		// 4 — a short quoted reply, then an unquoted sentence.
		'<p>&ldquo;Then stay.&rdquo; He did not look up from the map.</p>' +
		// 5 — the blockquote (two sentences).
		'<blockquote><p>They said the war would be short. They were right, in the way that ' +
		'grief is short &mdash; which is to say not at all.</p></blockquote>' +
		// 6 — four sentences, then the list.
		'<p>Below the levee the water turned the colour of old iron and held its breath. He ' +
		'counted the bells from the far tower and lost the count twice. The second time he did ' +
		'not begin again. A gull wheeled once over the harbour and did not come back.</p>' +
		'<ul>' +
		'<li>Salt.</li>' +
		'<li>Lamp oil, if any remained.</li>' +
		'<li>The long list of everything they had been promised before the cold road forked, ' +
		'back when the forking still felt less like a choice than a verdict handed quietly down.</li>' +
		'<li>Three matches.</li>' +
		'</ul>' +
		// 7 — five sentences.
		'<p>Frost wrote its slow grammar across the glass before first light. The map was wrong, ' +
		'and being wrong, it had already killed three of them. In the hollow under the hill the ' +
		'old machines kept their patient appointments. He learned the city by its smells. The ' +
		'city, in turn, forgot him.</p>' +
		// 8 — three sentences.
		'<p>Nobody had told the children that the gate would not open again. They waited by it ' +
		'anyway, in the way of children. When the letters finally arrived, weeks late, they ' +
		'smelled of smoke and salt and other people.</p>' +
		'</div></main>';

	// Light reader-like defaults so the preview reads as a chapter; author rules
	// (higher specificity, scoped to body.<class>) override these.
	var PREVIEW_BASE =
		'html{font:16px/1.6 Georgia,"Times New Roman",serif;color:#222;background:#fff}' +
		'body{margin:0;padding:1.2em 1.4em}' +
		'.entry-content{max-width:38em;margin:0 auto}' +
		'h1{font-size:1.6em;line-height:1.2;margin:0 0 .6em}' +
		'p{margin:0 0 1em}a{color:#2271b1}';

	// The valid extra-class chain: dot-separated tokens, no leading/trailing or
	// doubled dot. Mirrors Style_Sets::clean_extra_selector.
	var EXTRA_RE = /^[A-Za-z0-9_-]+(\.[A-Za-z0-9_-]+)*$/;

	document.querySelectorAll( '.sheaf-page-styles' ).forEach( initPageStyles );

	function initPageStyles( root ) {
		var bodyClass = root.getAttribute( 'data-body-class' ) || '';
		var additional = root.querySelector( '.sheaf-pcss-additional' );
		var template = root.querySelector( '.sheaf-pcss-template' );
		var addBtn = root.querySelector( '.sheaf-pcss-add' );
		var frame = root.querySelector( '.sheaf-pcss-frame' );
		var nextIndex = 1000; // Above any server-rendered index; only needs to be unique.
		var timer = null;

		if ( addBtn && template && additional ) {
			addBtn.addEventListener( 'click', function () {
				var html = template.innerHTML.replace( /__i__/g, String( nextIndex++ ) );
				var holder = document.createElement( 'div' );
				holder.innerHTML = html.trim();
				var block = holder.firstElementChild;
				if ( ! block ) {
					return;
				}
				additional.appendChild( block );
				var input = block.querySelector( '.sheaf-pcss-extra-input' );
				if ( input ) {
					input.focus();
				}
				var show = block.querySelector( '.sheaf-pcss-show' );
				if ( show ) {
					show.checked = true; // a freshly added block previews immediately
				}
				schedule();
			} );
		}

		// Explicit "Remove" button on an additional block.
		root.addEventListener( 'click', function ( e ) {
			var rm = e.target.closest( '.sheaf-pcss-remove' );
			if ( ! rm ) {
				return;
			}
			var block = rm.closest( '.sheaf-pcss-block' );
			if ( block ) {
				block.remove();
				schedule();
			}
		} );

		// Additional blocks are removed only via their explicit Remove button (see
		// above) — never automatically on blur, which could interrupt editing. An
		// empty block that is left in place is simply dropped server-side on save.
		root.addEventListener( 'input', schedule );
		root.addEventListener( 'change', schedule ); // "Show in preview" toggles

		function schedule() {
			clearTimeout( timer );
			timer = setTimeout( render, 150 );
		}

		function render() {
			if ( ! frame ) {
				return;
			}
			var classes = {};
			classes[ bodyClass ] = true;
			var css = '';

			root.querySelectorAll( '.sheaf-pcss-block' ).forEach( function ( block ) {
				var cssField = block.querySelector( '.sheaf-pcss-css' );
				var body = cssField ? cssField.value.trim() : '';
				if ( '' === body ) {
					return;
				}
				var input = block.querySelector( '.sheaf-pcss-extra-input' );
				var extra = input ? input.value.trim() : '';
				var selector = 'body.' + bodyClass;
				if ( '' !== extra ) {
					if ( ! EXTRA_RE.test( extra ) ) {
						return; // invalid chain — server would reject; skip in preview
					}
					selector += '.' + extra;
					// The rule is always emitted, but its classes only join the
					// preview body when "Show in preview" is ticked — so each
					// targeted scenario can be viewed on its own or combined. An
					// unticked block's rule stays inert (its classes are absent).
					var show = block.querySelector( '.sheaf-pcss-show' );
					if ( show && show.checked ) {
						extra.split( '.' ).forEach( function ( c ) {
							classes[ c ] = true;
						} );
					}
				}
				css += selector + ' {\n' + body + '\n}\n';
			} );

			// Neutralise any </style> so a half-typed rule can't break the preview
			// markup. (The iframe is sandboxed with no scripts, so this is only
			// about keeping the preview legible, not security.)
			css = css.replace( /<\/(\s*style)/gi, '' );

			var bodyClasses = Object.keys( classes ).join( ' ' );
			frame.srcdoc =
				'<!doctype html><html><head><meta charset="utf-8"><style>' +
				PREVIEW_BASE +
				css +
				'</style></head><body class="' +
				bodyClasses.replace( /"/g, '' ) +
				'">' +
				SAMPLE +
				'</body></html>';
		}

		render();
	}
} )();
