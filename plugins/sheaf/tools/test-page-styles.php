<?php
/**
 * Unit tests for per-set page styles (Sheaf\Style_Sets page-style methods):
 * the extra-selector validator, the CSS cleaner/balancer, and the scoped
 * compiler. CLI-only.
 *
 *   wpenv run cli wp eval-file wp-content/plugins/sheaf/tools/test-page-styles.php
 *
 * Creates a throwaway style set and deletes it again, so it is safe to run on a
 * live site. Touches only its own set key in the library option.
 *
 * @package Sheaf
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

use Sheaf\Style_Sets;

$pass  = 0;
$fail  = 0;
$check = function ( bool $cond, string $label ) use ( &$pass, &$fail ) {
	if ( $cond ) {
		++$pass;
		WP_CLI::log( "  ok   $label" );
	} else {
		++$fail;
		WP_CLI::log( "  FAIL $label" );
	}
};

// Cleaned-CSS helper: return only the css string from filter_page_css.
$css_of = static fn( string $in ): string => Style_Sets::filter_page_css( $in )['css'];
// Warnings helper.
$warn_of = static fn( string $in ): array => Style_Sets::filter_page_css( $in )['warnings'];

$set = '';

try {
	/* ----------------------------------------- clean_extra_selector ------- */

	$check( '' === Style_Sets::clean_extra_selector( '' ), 'extra: empty is the base block' );
	$check( '' === Style_Sets::clean_extra_selector( '   ' ), 'extra: blank trims to base block' );
	$check( 'sheaf-section' === Style_Sets::clean_extra_selector( 'sheaf-section' ), 'extra: simple class' );
	$check(
		'sheaf-book-114.sheaf-section' === Style_Sets::clean_extra_selector( 'sheaf-book-114.sheaf-section' ),
		'extra: chained classes'
	);
	$check( 'a_b-c' === Style_Sets::clean_extra_selector( 'a_b-c' ), 'extra: underscores and hyphens allowed' );
	$check( null === Style_Sets::clean_extra_selector( '.leading' ), 'extra: leading dot rejected' );
	$check( null === Style_Sets::clean_extra_selector( 'trailing.' ), 'extra: trailing dot rejected' );
	$check( null === Style_Sets::clean_extra_selector( 'a..b' ), 'extra: empty ".." segment rejected' );
	$check( null === Style_Sets::clean_extra_selector( 'has space' ), 'extra: space rejected' );
	$check( null === Style_Sets::clean_extra_selector( 'a>b' ), 'extra: combinator rejected' );
	$check( null === Style_Sets::clean_extra_selector( 'a{b}' ), 'extra: braces rejected' );
	$check( null === Style_Sets::clean_extra_selector( 'a:hover' ), 'extra: colon rejected' );

	/* ----------------------------------------- filter_page_css: balance --- */

	$check( 'p { margin: 0; }' === $css_of( 'p { margin: 0; }' ), 'css: simple rule passes through' );
	$check( '' === $css_of( 'p { margin: 0; } }' ), 'css: stray "}" rejected' );
	$check( '' === $css_of( 'p { margin: 0;' ), 'css: unclosed "{" rejected' );
	$check( count( $warn_of( 'p {' ) ) > 0, 'css: unbalanced input warns' );
	$check( [] === $warn_of( 'p { color: red; }' ), 'css: clean input has no warnings' );

	// Native-nesting body: nested rules preserved verbatim.
	$nested = ".entry-content {\n\tp { margin: 0; }\n\tp:first-child { text-indent: 0; }\n}";
	$check( $nested === $css_of( $nested ), 'css: nested rules preserved' );

	/* ----------------------------------------- filter_page_css: comments -- */

	$check( false !== strpos( $css_of( '/* note */ p { }' ), '/* note */' ), 'css: leading comment preserved' );
	$check( false !== strpos( $css_of( 'p { /* keep me */ color: red; }' ), '/* keep me */' ), 'css: inline comment preserved' );
	// A brace hidden inside a comment must not unbalance the rule.
	$check( '' !== $css_of( 'p { color: red; /* } */ }' ), 'css: closing brace in comment not counted' );
	$check( false !== strpos( $css_of( 'p { /* { */ color: red; }' ), 'color: red' ), 'css: opening brace in comment not counted' );
	// </style> smuggled inside a comment is still neutralised.
	$check( false === stripos( $css_of( 'p { /* </style> */ color: red; }' ), '</style' ), 'css: </style> in comment neutralised' );

	/* ----------------------------------------- filter_page_css: strings --- */

	$brace_str = 'p { content: "}"; }';
	$check( $brace_str === $css_of( $brace_str ), 'css: brace inside string not counted' );
	$check( '' === $css_of( 'p { content: "{"; ' ), 'css: real unclosed brace still caught past a string' );

	/* ----------------------------------------- filter_page_css: at-rules -- */

	$stmt = $css_of( '@import url(evil.css); p { color: red; }' );
	$check( false === strpos( $stmt, '@import' ), 'css: @import statement removed' );
	$check( false !== strpos( $stmt, 'color: red' ), 'css: rule after @import survives' );
	$check( count( $warn_of( '@import url(x); p{}' ) ) > 0, 'css: at-rule warns' );

	$block = $css_of( '@media (min-width: 40em) { p { color: red; } } q { color: blue; }' );
	$check( false === strpos( $block, '@media' ), 'css: @media block removed' );
	$check( false === strpos( $block, 'color: red' ), 'css: @media contents removed' );
	$check( false !== strpos( $block, 'color: blue' ), 'css: rule after @media block survives' );

	// A ';' inside a string must not prematurely end an @import skip.
	$semi = $css_of( '@import url("a;b.css"); p { color: red; }' );
	$check( false === strpos( $semi, '@import' ), 'css: @import with ";" in string fully removed' );
	$check( false !== strpos( $semi, 'color: red' ), 'css: rule after tricky @import survives' );

	/* ----------------------------------------- filter_page_css: break-out - */

	$check( false === strpos( strtolower( $css_of( 'p { content: "</style>"; }' ) ), '</style' ), 'css: </style> neutralised inside a string' );
	$check( false === stripos( $css_of( 'p::before { content: "</STYLE >"; }' ), '</style' ), 'css: </style variant neutralised' );
	$check( false === stripos( $css_of( 'a { background: url(javascript:alert(1)); }' ), 'javascript:' ), 'css: javascript: scrubbed' );
	$check( false === stripos( $css_of( 'a { width: expression(alert(1)); }' ), 'expression(' ), 'css: expression() scrubbed' );

	// url() to a normal asset must survive (fonts/textures need it).
	$check( false !== strpos( $css_of( 'p { background: url(paper.png); }' ), 'url(paper.png)' ), 'css: normal url() preserved' );

	/* ----------------------------------------- round-trip via a real set -- */

	$set = Style_Sets::save_set( 'ZZ Page Styles Test' );
	$check( '' !== $set, 'set: created a throwaway set' );
	$check( 'sheaf-styleset-' . $set === Style_Sets::styleset_body_class( $set ), 'set: body class token' );

	$warnings = Style_Sets::save_page_styles(
		$set,
		[
			[
				'extra' => '',
				'css'   => ".entry-content { p { margin: 0; text-indent: 2.5em; } p:first-child { text-indent: 0; } }",
			],
			[
				'extra' => 'sheaf-section',
				'css'   => 'h1 { text-transform: uppercase; }',
			],
			// Dropped: empty CSS.
			[
				'extra' => 'ignored-empty',
				'css'   => '   ',
			],
			// Dropped: invalid selector, with a warning.
			[
				'extra' => 'bad selector',
				'css'   => 'p { color: red; }',
			],
		]
	);
	$check( count( $warnings ) >= 1, 'save: invalid selector produced a warning' );

	$stored = Style_Sets::get_page_styles( $set );
	$check( 2 === count( $stored ), 'save: kept 2 usable blocks, dropped empty + invalid' );
	$check( '' === $stored[0]['extra'], 'save: base block first, empty extra' );
	$check( 'sheaf-section' === $stored[1]['extra'], 'save: second block keeps its extra' );

	$compiled = Style_Sets::compile_page_css( $set );
	$check(
		false !== strpos( $compiled, 'body.sheaf-styleset-' . $set . " {\n" ),
		'compile: base block wrapped in the set body selector'
	);
	$check(
		false !== strpos( $compiled, 'body.sheaf-styleset-' . $set . '.sheaf-section {' ),
		'compile: extra block chains its class'
	);
	$check( false !== strpos( $compiled, 'text-indent: 2.5em' ), 'compile: base declarations present' );
	$check( false !== strpos( $compiled, 'text-transform: uppercase' ), 'compile: extra declarations present' );

	// page_css() (whole library) includes this set's compiled CSS.
	$check(
		false !== strpos( Style_Sets::page_css(), 'body.sheaf-styleset-' . $set ),
		'library: page_css() aggregates the set'
	);

	// Emptying every block clears the stored key.
	Style_Sets::save_page_styles( $set, [ [ 'extra' => '', 'css' => '' ] ] );
	$check( [] === Style_Sets::get_page_styles( $set ), 'save: clearing all blocks removes page styles' );
	$check( '' === Style_Sets::compile_page_css( $set ), 'compile: empty set compiles to nothing' );

} finally {
	if ( '' !== $set ) {
		Style_Sets::delete_set( $set );
	}
	WP_CLI::log( '' );
	WP_CLI::log( "PASS $pass   FAIL $fail" );
	if ( $fail > 0 ) {
		WP_CLI::halt( 1 );
	}
}
