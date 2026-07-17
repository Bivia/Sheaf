<?php
/**
 * Unit tests for full-book scrolling settings (Sheaf\Scroll_Settings) and the
 * page-count estimator (Sheaf\Pages). CLI-only.
 *
 *   wpenv run cli wp eval-file wp-content/plugins/sheaf/tools/test-scroll.php
 *
 * Creates a throwaway book Page + chapters and deletes them again, so it is
 * safe to run on a live site.
 *
 * @package Sheaf
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

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

$created = [];

// Pin the page rate so page maths are deterministic regardless of any filter.
add_filter( 'sheaf_words_per_page', static fn() => 300, 99 );

// N words of plain content, so Words::count_in returns exactly N.
$content_of = static fn( int $words ): string => $words > 0 ? trim( str_repeat( 'word ', $words ) ) : '';

$make_chapter = static function ( int $book, string $title, int $words, int $order, bool $section = false ) use ( &$created, $content_of ): int {
	$id = (int) wp_insert_post(
		[
			'post_type'    => \Sheaf\Chapters::POST_TYPE,
			'post_title'   => $title,
			'post_status'  => 'publish',
			'post_content' => $content_of( $words ),
			'menu_order'   => $order,
		]
	);
	update_post_meta( $id, \Sheaf\Books::BOOK_META, $book );
	if ( $section ) {
		update_post_meta( $id, \Sheaf\Chapters::SECTION_META, true );
	}
	\Sheaf\Words::refresh( $id );
	$created[] = $id;
	return $id;
};

try {
	/* -------------------------------------------------- Scroll_Settings ---- */

	$d = \Sheaf\Scroll_Settings::defaults();
	$check( false === $d['enabled'], 'default: disabled' );
	$check( true === $d['chapter_titles'], 'default: chapter titles on' );
	$check( 'page_break' === $d['chapter_break'], 'default: chapter break = page_break' );

	// sanitize(): enum clamp, form checkbox semantics, verbatim trimmed HTML.
	$clean = \Sheaf\Scroll_Settings::sanitize(
		[
			'enabled'            => '1',
			'chapter_break'      => 'bogus',
			'chapter_break_html' => '  <hr class="x">  ',
			'section_break'      => 'hr',
			// chapter_titles intentionally absent.
		]
	);
	$check( true === $clean['enabled'], 'sanitize: "1" -> true' );
	$check( 'page_break' === $clean['chapter_break'], 'sanitize: unknown break -> default' );
	$check( 'hr' === $clean['section_break'], 'sanitize: valid break kept' );
	$check( false === $clean['chapter_titles'], 'sanitize: absent checkbox -> false' );
	$check( '<hr class="x">' === $clean['chapter_break_html'], 'sanitize: HTML trimmed, kept verbatim' );

	// from_request() reads the sheaf_scroll[...] namespace.
	$req = \Sheaf\Scroll_Settings::from_request(
		[ 'sheaf_scroll' => [ 'enabled' => '1', 'show_full_toc' => '1' ] ]
	);
	$check( true === $req['enabled'] && true === $req['show_full_toc'], 'from_request: reads sheaf_scroll[]' );
	$check( false === $req['show_page_numbers'], 'from_request: unspecified stays false' );

	// lint_html(): clean markup passes, unbalanced markup warns, never strips.
	$check( [] === \Sheaf\Scroll_Settings::lint_html( '<hr>' ), 'lint: void tag is clean' );
	$check( [] === \Sheaf\Scroll_Settings::lint_html( '<div class="d"><span>ok</span></div>' ), 'lint: balanced markup is clean' );
	// libxml (like a browser) recovers unclosed tags silently, but flags genuine
	// malformation: mismatched nesting and stray end tags.
	$check( ! empty( \Sheaf\Scroll_Settings::lint_html( '<b><i>x</b></i>' ) ), 'lint: mismatched nesting warns' );
	$check( ! empty( \Sheaf\Scroll_Settings::lint_html( 'hello </div>' ) ), 'lint: stray end tag warns' );
	// Foreign (SVG/MathML) and custom-element tags are valid HTML5 dividers, not
	// malformation — they must not warn (libxml calls them "invalid").
	$check( [] === \Sheaf\Scroll_Settings::lint_html( '<svg viewBox="0 0 10 10"><line x1="0" y1="0" x2="10" y2="10"/></svg>' ), 'lint: inline SVG is clean' );
	$check( [] === \Sheaf\Scroll_Settings::lint_html( '<my-divider>x</my-divider>' ), 'lint: custom element is clean' );

	// break_html(): HTML only surfaces for the divider break choices.
	$check( '<hr>' === \Sheaf\Scroll_Settings::break_html( [ 'chapter_break' => 'hr', 'chapter_break_html' => '<hr>' ], 'chapter_break' ), 'break_html: returned for hr' );
	$check( '' === \Sheaf\Scroll_Settings::break_html( [ 'chapter_break' => 'page_break', 'chapter_break_html' => '<hr>' ], 'chapter_break' ), 'break_html: empty for page_break' );

		/* ---- Display + chapter-navigation settings (new in this release) ---- */

		// Defaults preserve the pre-existing front-end behaviour.
		$check( 'none' === $d['toc_list_style'], 'default: TOC list style = none' );
		$check( '' === $d['toc_list_style_custom'], 'default: TOC custom list style empty' );
		$check( 'reading_time' === $d['toc_meta'], 'default: TOC meta = reading time' );
		$check( 'top' === $d['breadcrumbs'], 'default: breadcrumbs at top' );
		$check( 'full' === $d['breadcrumb_style'], 'default: breadcrumb style = full trail' );
		$check( 'bottom' === $d['chapter_nav_at'], 'default: chapter nav at bottom' );
		$check( 'prev_next_title' === $d['chapter_nav_style'], 'default: chapter nav style = prev/next + title' );

		// Enum clamps fall back to the default when the value is unknown.
		$nav = \Sheaf\Scroll_Settings::sanitize(
			[
				'toc_meta'          => 'bogus',
				'breadcrumbs'       => 'bottom',
				'breadcrumb_style'  => 'book_chapter',
				'chapter_nav_at'    => 'top',
				'chapter_nav_style' => 'nope',
			]
		);
		$check( 'reading_time' === $nav['toc_meta'], 'sanitize: unknown TOC meta -> default' );
		$check( 'bottom' === $nav['breadcrumbs'], 'sanitize: valid breadcrumb pos kept' );
		$check( 'book_chapter' === $nav['breadcrumb_style'], 'sanitize: valid breadcrumb style kept' );
		$check( 'full' === \Sheaf\Scroll_Settings::sanitize( [ 'breadcrumb_style' => 'nope' ] )['breadcrumb_style'], 'sanitize: unknown breadcrumb style -> default' );

		// "None" is a style now, not a placement.
		$check( ! in_array( 'none', \Sheaf\Scroll_Settings::BREADCRUMB_POS, true ), 'model: no none in breadcrumb placements' );
		$check( ! in_array( 'none', \Sheaf\Scroll_Settings::NAV_POS, true ), 'model: no none in nav placements' );
		$check( in_array( 'none', \Sheaf\Scroll_Settings::BREADCRUMB_STYLE, true ), 'model: none is a breadcrumb style' );
		$check( in_array( 'book_page', \Sheaf\Scroll_Settings::BREADCRUMB_STYLE, true ), 'model: book_page is a breadcrumb style' );
		$check( 'book_page' === \Sheaf\Scroll_Settings::sanitize( [ 'breadcrumb_style' => 'book_page' ] )['breadcrumb_style'], 'sanitize: book_page style kept' );
		$check( in_array( 'none', \Sheaf\Scroll_Settings::NAV_STYLE, true ), 'model: none is a nav style' );
		$check( ! in_array( 'back_to_book', \Sheaf\Scroll_Settings::BREADCRUMB_STYLE, true ), 'model: back_to_book is not a breadcrumb style' );

		// Upgrade: a placement of "none" was how a book turned this chrome off
		// before "off" became a style. It must stay off, not clamp to a default.
		$old = \Sheaf\Scroll_Settings::sanitize(
			[
				'breadcrumbs'       => 'none',
				'breadcrumb_style'  => 'full',
				'chapter_nav_at'    => 'none',
				'chapter_nav_style' => 'prev_next_title',
			]
		);
		$check( 'none' === $old['breadcrumb_style'], 'upgrade: breadcrumbs at none -> style none' );
		$check( 'top' === $old['breadcrumbs'], 'upgrade: breadcrumbs placement falls back to the default' );
		$check( 'none' === $old['chapter_nav_style'], 'upgrade: nav at none -> style none' );
		$check( 'bottom' === $old['chapter_nav_at'], 'upgrade: nav placement falls back to the default' );
		$check( 'top' === $nav['chapter_nav_at'], 'sanitize: valid nav pos kept' );
		$check( 'prev_next_title' === $nav['chapter_nav_style'], 'sanitize: unknown nav style -> default' );

		// List-style: known token kept; unknown -> none; custom sentinel + field.
		$ls = \Sheaf\Scroll_Settings::sanitize( [ 'toc_list_style' => 'lower-roman' ] );
		$check( 'lower-roman' === $ls['toc_list_style'], 'sanitize: known list style kept' );
		$check( 'none' === \Sheaf\Scroll_Settings::sanitize( [ 'toc_list_style' => 'nonsense' ] )['toc_list_style'], 'sanitize: unknown list style -> none' );

		// Custom value: quotes and symbols survive; injection chars are stripped.
		$custom = \Sheaf\Scroll_Settings::sanitize(
			[ 'toc_list_style' => 'custom', 'toc_list_style_custom' => '"⁂"' ]
		);
		$check( 'custom' === $custom['toc_list_style'], 'sanitize: custom sentinel kept' );
		$check( '"⁂"' === $custom['toc_list_style_custom'], 'sanitize: quoted marker preserved' );
		$check( 'redbad' === \Sheaf\Scroll_Settings::sanitize( [ 'toc_list_style_custom' => 'red;bad}{<>' ] )['toc_list_style_custom'], 'sanitize: injection chars stripped from custom' );

		// list_style_css() resolves the value to emit: keyword bare, string quoted.
		$css = static fn( $v ) => \Sheaf\Scroll_Settings::list_style_css( [ 'toc_list_style' => 'custom', 'toc_list_style_custom' => $v ] );
		$check( 'lower-roman' === \Sheaf\Scroll_Settings::list_style_css( [ 'toc_list_style' => 'lower-roman' ] ), 'list_style_css: token passthrough' );
		$check( 'lower-armenian' === $css( 'lower-armenian' ), 'list_style_css: custom keyword stays bare' );
		$check( '"⁂"' === $css( '"⁂"' ), 'list_style_css: quoted marker kept quoted' );
		$check( '"→"' === $css( '→' ), 'list_style_css: bare symbol auto-quoted' );
		$check( 'none' === $css( '' ), 'list_style_css: empty custom -> none' );

	// No book -> defaults.
	$check( \Sheaf\Scroll_Settings::defaults() === \Sheaf\Scroll_Settings::get( 0 ), 'get(0): defaults' );

	// save()/get() round-trip on a real Page.
	$book = (int) wp_insert_post(
		[ 'post_type' => 'page', 'post_title' => 'Scroll Test Book', 'post_status' => 'publish' ]
	);
	$created[] = $book;

	\Sheaf\Scroll_Settings::save(
		$book,
		[
			'enabled'            => true,
			'chapter_break'      => 'hr',
			'chapter_break_html' => '<hr class="c">',
			'show_page_numbers'  => true,
		]
	);
	$got = \Sheaf\Scroll_Settings::get( $book );
	$check( true === $got['enabled'], 'round-trip: enabled' );
	$check( 'hr' === $got['chapter_break'], 'round-trip: chapter_break' );
	$check( '<hr class="c">' === $got['chapter_break_html'], 'round-trip: chapter_break_html verbatim' );
	$check( true === $got['show_page_numbers'], 'round-trip: show_page_numbers' );
	$check( true === \Sheaf\Scroll_Settings::enabled( $book ), 'enabled() helper true' );

	// A book stored in the old shape (off = a placement of "none") reads back
	// switched off, without any migration pass over the database.
	$legacy = (int) wp_insert_post(
		[ 'post_type' => 'page', 'post_title' => 'Legacy Book', 'post_status' => 'publish' ]
	);
	$created[] = $legacy;
	update_post_meta(
		$legacy,
		\Sheaf\Scroll_Settings::META,
		[ 'breadcrumbs' => 'none', 'chapter_nav_at' => 'none', 'chapter_nav_style' => 'prev_next_title' ]
	);
	$old = \Sheaf\Scroll_Settings::get( $legacy );
	$check( 'none' === $old['breadcrumb_style'], 'upgrade on read: breadcrumbs stay off' );
	$check( 'none' === $old['chapter_nav_style'], 'upgrade on read: chapter nav stays off' );
	$check( in_array( $old['breadcrumbs'], \Sheaf\Scroll_Settings::BREADCRUMB_POS, true ), 'upgrade on read: placement is valid again' );

	/* --------------------------------------------------------------- Pages -- */

	$check( 300 === \Sheaf\Pages::words_per_page(), 'pages: wpp filter honored' );
	$check( 0 === \Sheaf\Pages::for_words( 0 ), 'pages: 0 words -> 0 pages' );
	$check( 1 === \Sheaf\Pages::for_words( 1 ), 'pages: any content >= 1 page' );
	$check( 1 === \Sheaf\Pages::for_words( 300 ), 'pages: 300 words -> 1 page' );
	$check( 2 === \Sheaf\Pages::for_words( 301 ), 'pages: 301 words -> 2 pages' );

	// Cumulative book map, with a section interleaved (0 words, 0 pages).
	$c1 = $make_chapter( $book, 'One', 300, 1 );
	$c2 = $make_chapter( $book, 'Part Two', 0, 2, true );
	$c3 = $make_chapter( $book, 'Two', 600, 3 );

	$map = \Sheaf\Pages::book_map( $book );
	$check( 900 === $map['total_words'], 'map: total words sums non-section chapters' );
	$check( 3 === $map['total_pages'], 'map: 900 words -> 3 pages' );
	$check( 1 === $map['chapters'][ $c1 ]['start_page'], 'map: first chapter on page 1' );
	$check( 0 === $map['chapters'][ $c2 ]['pages'] && $map['chapters'][ $c2 ]['is_section'], 'map: section spans 0 pages' );
	$check( 2 === $map['chapters'][ $c3 ]['start_page'], 'map: third chapter starts on page 2' );
	$check( 2 === $map['chapters'][ $c3 ]['pages'], 'map: 600-word chapter spans 2 pages' );

	/* ------------------------------------------------------- template tags -- */

	$check( true === sheaf_is_scroll_reader( $c1 ), 'tag: is_scroll_reader true for enabled book chapter' );
	$check( false === sheaf_is_scroll_reader( $book ), 'tag: is_scroll_reader false for a non-chapter' );
	$check( $book === sheaf_scroll_book_id( $c1 ), 'tag: scroll_book_id resolves the book' );
	$check( 0 === sheaf_scroll_book_id( $book ), 'tag: scroll_book_id 0 for a non-chapter' );
	$check( 1 === sheaf_chapter_pages( $c1 ), 'tag: chapter_pages = 1 for 300 words' );
	$check( 0 === sheaf_chapter_pages( $c2 ), 'tag: chapter_pages = 0 for a section' );
	$check( 2 === sheaf_chapter_pages( $c3 ), 'tag: chapter_pages = 2 for 600 words' );
	$check( 3 === sheaf_book_pages( $book ), 'tag: book_pages = 3' );
	$check( 3 === ( sheaf_book_page_map( $book )['total_pages'] ?? 0 ), 'tag: book_page_map total_pages' );

	$spine = sheaf_scroll_spine( $book, $c1 );
	$check( $book === ( $spine['bookId'] ?? 0 ), 'tag: spine bookId' );
	$check( $c1 === ( $spine['currentId'] ?? 0 ), 'tag: spine currentId' );
	$check( 3 === count( $spine['chapters'] ?? [] ), 'tag: spine lists all chapters' );
	$check( true === ( $spine['settings']['sidebar'] ?? null ), 'tag: spine sidebar defaults on' );
	$check( [] === sheaf_scroll_spine(), 'tag: spine empty with no current chapter' );

	/* ------------------------------------------------------------- filters -- */

	$mark = static fn( $spine ) => array_merge( $spine, [ 'marked' => 'yes' ] );
	add_filter( 'sheaf_scroll_spine', $mark );
	$check( 'yes' === ( sheaf_scroll_spine( $book, $c1 )['marked'] ?? null ), 'filter: sheaf_scroll_spine applied' );
	remove_filter( 'sheaf_scroll_spine', $mark );

	$off = static fn() => false;
	add_filter( 'sheaf_scroll_sidebar', $off );
	$check( false === sheaf_scroll_spine( $book, $c1 )['settings']['sidebar'], 'filter: sheaf_scroll_sidebar off' );
	remove_filter( 'sheaf_scroll_sidebar', $off );

	/* ----------------------------------------------------- Renderer::toc ---- */
	// (Mutates this book's settings, so it runs after the checks above.)

	\Sheaf\Scroll_Settings::save( $book, [ 'toc_list_style' => 'none', 'toc_meta' => 'reading_time' ] );
	$toc = \Sheaf\Renderer::toc( $book );
	$check( false !== strpos( $toc, 'list-style-type:none' ), 'toc: list style none inlined' );
	$check( false !== strpos( $toc, 'min</span>' ), 'toc: reading-time meta shown' );

	\Sheaf\Scroll_Settings::save( $book, [ 'toc_list_style' => 'decimal', 'toc_meta' => 'word_count' ] );
	$toc = \Sheaf\Renderer::toc( $book );
	$check( false !== strpos( $toc, 'list-style-type:decimal' ), 'toc: list style decimal inlined' );
	$check( false !== strpos( $toc, 'words</span>' ), 'toc: word-count meta shown' );
	$check( false === strpos( $toc, 'min</span>' ), 'toc: no reading time when word count chosen' );

	\Sheaf\Scroll_Settings::save( $book, [ 'toc_meta' => 'page_number' ] );
	$toc = \Sheaf\Renderer::toc( $book );
	$check( false !== strpos( $toc, 'p. 1</span>' ), 'toc: page-number meta shows start page' );

	// reading_time="no" override suppresses meta regardless of the setting.
	$toc = \Sheaf\Renderer::toc( $book, [ 'reading_time' => false ] );
	$check( false === strpos( $toc, 'sheaf-toc__meta' ), 'toc: reading_time=no suppresses all meta' );

	/* -------------------------------------------- Renderer::chapter_nav ---- */
	// Order is c1, then the section "Part Two", then c3, so c3's prev is the
	// section and it has no next.

	$nav = \Sheaf\Renderer::chapter_nav( $c3, 'prev_next_title' );
	$check( false !== strpos( $nav, 'Previous' ) && false !== strpos( $nav, 'Part Two' ), 'nav: prev_next_title shows word + title' );

	$nav = \Sheaf\Renderer::chapter_nav( $c3, 'prev_next' );
	$check( false !== strpos( $nav, 'Previous' ) && false === strpos( $nav, 'nav__title' ), 'nav: prev_next omits titles' );

	$nav = \Sheaf\Renderer::chapter_nav( $c3, 'title_only' );
	$check( false !== strpos( $nav, 'Part Two' ) && false === strpos( $nav, 'nav__dir' ), 'nav: title_only omits direction words' );

	$nav = \Sheaf\Renderer::chapter_nav( $c1, 'back_to_book' );
	$check( false !== strpos( $nav, 'Back to Scroll Test Book' ), 'nav: back_to_book links to the book' );
	$check( false !== strpos( $nav, (string) get_permalink( $book ) ), 'nav: back_to_book uses the book permalink' );

	$nav = \Sheaf\Renderer::chapter_nav( $c3, 'toc_select' );
	$check( false !== strpos( $nav, '<select' ), 'nav: toc_select renders a select' );
	$check( false !== strpos( $nav, 'selected' ), 'nav: toc_select marks the current chapter' );

	// Empty style reads the book's chapter_nav_style setting.
	\Sheaf\Scroll_Settings::save( $book, [ 'chapter_nav_style' => 'title_only' ] );
	$nav = \Sheaf\Renderer::chapter_nav( $c3, '' );
	$check( false === strpos( $nav, 'nav__dir' ), 'nav: empty style honours the book setting' );

	/* ------------------------------------------- Renderer::breadcrumbs ---- */
	// Give the book a parent, so the styles that include the hierarchy above it
	// are distinguishable from the ones that do not.

	$shelf = (int) wp_insert_post(
		[ 'post_type' => 'page', 'post_title' => 'Scroll Test Shelf', 'post_status' => 'publish' ]
	);
	$created[] = $shelf;
	wp_update_post( [ 'ID' => $book, 'post_parent' => $shelf ] );

	$crumbs = \Sheaf\Renderer::breadcrumbs( $c1, 'full' );
	$check( false !== strpos( $crumbs, 'Scroll Test Shelf' ), 'crumbs: full includes the hierarchy above the book' );
	$check( false !== strpos( $crumbs, 'Scroll Test Book' ), 'crumbs: full includes the book' );
	$check( false !== strpos( $crumbs, 'aria-current="page"' ), 'crumbs: full ends at the chapter, unlinked' );

	$crumbs = \Sheaf\Renderer::breadcrumbs( $c1, 'book_chapter' );
	$check( false === strpos( $crumbs, 'Scroll Test Shelf' ), 'crumbs: book_chapter drops the hierarchy above the book' );
	$check( false !== strpos( $crumbs, 'Scroll Test Book' ), 'crumbs: book_chapter keeps the book' );

	$check( '' === \Sheaf\Renderer::breadcrumbs( $c1, 'none' ), 'crumbs: none renders nothing' );
	$check( '' === \Sheaf\Renderer::chapter_nav( $c3, 'none' ), 'nav: none renders nothing' );

	// book_page: the book link, then the chapter's "pg X of Y" position.
	$crumbs = \Sheaf\Renderer::breadcrumbs( $c1, 'book_page' );
	$check( false !== strpos( $crumbs, 'Scroll Test Book' ), 'crumbs: book_page keeps the book' );
	$check( false === strpos( $crumbs, 'Scroll Test Shelf' ), 'crumbs: book_page drops the hierarchy above the book' );
	$check( false !== strpos( $crumbs, 'sheaf-breadcrumbs__page' ), 'crumbs: book_page shows a page position' );
	$check( false !== strpos( $crumbs, 'of ' ), 'crumbs: book_page reads "pg X of Y"' );
	$check( false === strpos( $crumbs, 'aria-current="page"' ), 'crumbs: book_page has no chapter crumb' );

	$crumbs = \Sheaf\Renderer::breadcrumbs( $c3, 'full_select' );
	$check( false !== strpos( $crumbs, 'sheaf-breadcrumbs__select' ), 'crumbs: full_select ends in a select' );
	$check( false !== strpos( $crumbs, 'selected' ), 'crumbs: full_select marks the current chapter' );
	$check( false !== strpos( $crumbs, 'Scroll Test Shelf' ), 'crumbs: full_select keeps the full trail' );
	$check( false === strpos( $crumbs, 'aria-current="page"' ), 'crumbs: full_select replaces the chapter crumb' );

	// Empty style reads the book's breadcrumb_style setting.
	\Sheaf\Scroll_Settings::save( $book, [ 'breadcrumb_style' => 'book_chapter' ] );
	$crumbs = \Sheaf\Renderer::breadcrumbs( $c1, '' );
	$check( false === strpos( $crumbs, 'Scroll Test Shelf' ), 'crumbs: empty style honours the book setting' );

	// An unknown style falls back to the full trail rather than rendering nothing.
	$crumbs = \Sheaf\Renderer::breadcrumbs( $c1, 'nonsense' );
	$check( false !== strpos( $crumbs, 'Scroll Test Shelf' ), 'crumbs: unknown style -> full trail' );

	// A Page's own trail is its hierarchy, unaffected by the chapter styles.
	$crumbs = \Sheaf\Renderer::breadcrumbs( $book, 'back_to_book' );
	$check( false !== strpos( $crumbs, 'Scroll Test Shelf' ), 'crumbs: a Page trail ignores the chapter style' );
	$check( '' === \Sheaf\Renderer::breadcrumbs( $shelf ), 'crumbs: a lone crumb renders nothing' );

	// A chapter with no book has nothing to trail from.
	$orphan = (int) wp_insert_post(
		[ 'post_type' => \Sheaf\Chapters::POST_TYPE, 'post_title' => 'Orphan', 'post_status' => 'publish' ]
	);
	$created[] = $orphan;
	$check( '' === \Sheaf\Renderer::breadcrumbs( $orphan, 'full' ), 'crumbs: off-book chapter renders nothing' );

	/* ---------------------------------------- Renderer::book_eyebrow ----- */
	// The "above the title" eyebrow ends at the book — the chapter is the <h1>,
	// so its crumb is dropped, and the trail carries the eyebrow modifier class.
	$eye = \Sheaf\Renderer::book_eyebrow( $c1, 'full' );
	$check( false !== strpos( $eye, 'sheaf-breadcrumbs--eyebrow' ), 'eyebrow: carries the eyebrow class' );
	$check( false !== strpos( $eye, 'Scroll Test Shelf' ), 'eyebrow: full keeps the series line above the book' );
	$check( false !== strpos( $eye, 'Scroll Test Book' ), 'eyebrow: full keeps the book' );
	$check( false === strpos( $eye, 'aria-current' ), 'eyebrow: the chapter crumb is dropped' );

	$eye = \Sheaf\Renderer::book_eyebrow( $c1, 'book_chapter' );
	$check( false === strpos( $eye, 'Scroll Test Shelf' ), 'eyebrow: book_chapter drops the series line' );
	$check( false !== strpos( $eye, 'Scroll Test Book' ), 'eyebrow: book_chapter keeps the book' );

	$eye = \Sheaf\Renderer::book_eyebrow( $c1, 'book_page' );
	$check( false !== strpos( $eye, 'sheaf-breadcrumbs--eyebrow' ), 'eyebrow: book_page is tagged an eyebrow' );
	$check( false !== strpos( $eye, 'sheaf-breadcrumbs__page' ), 'eyebrow: book_page shows the page position' );

	// A chapter drop-down would only echo the <h1>, so full_select renders as full.
	$eye = \Sheaf\Renderer::book_eyebrow( $c3, 'full_select' );
	$check( false === strpos( $eye, 'sheaf-breadcrumbs__select' ), 'eyebrow: full_select drops the chapter drop-down' );
	$check( false !== strpos( $eye, 'Scroll Test Shelf' ), 'eyebrow: full_select still shows the series line' );

	$check( '' === \Sheaf\Renderer::book_eyebrow( $c1, 'none' ), 'eyebrow: none renders nothing' );
	$check( '' === \Sheaf\Renderer::book_eyebrow( $orphan, 'full' ), 'eyebrow: off-book chapter renders nothing' );

	// 'above' is a real placement now, and 'none' still is not.
	$check( in_array( 'above', \Sheaf\Scroll_Settings::BREADCRUMB_POS, true ), 'model: above is a breadcrumb placement' );
	$check( 'above' === \Sheaf\Scroll_Settings::sanitize( [ 'breadcrumbs' => 'above' ] )['breadcrumbs'], 'sanitize: above placement kept' );
} finally {
	foreach ( $created as $id ) {
		wp_delete_post( $id, true );
	}
}

WP_CLI::log( sprintf( "\n%d passed, %d failed", $pass, $fail ) );
if ( $fail > 0 ) {
	WP_CLI::error( 'test-scroll: failures above' );
}
