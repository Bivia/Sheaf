<?php
/**
 * Development seed data for Sheaf. NOT loaded by the plugin — run it by hand:
 *
 *   wpenv run cli wp eval-file wp-content/plugins/sheaf/tools/seed.php
 *
 * It is idempotent: pages are upserted by (slug, parent) and chapters by
 * (book, slug), so re-running reconciles rather than duplicates. The fixture
 * mirrors the agreed sample URLs and the router torture test — five books, five
 * chapters each (~1200 words of filler), and the slug "prologue" reused across
 * five different books so per-book URL discrimination is exercised.
 *
 * @package Sheaf
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return; // Guard: this is a CLI-only dev tool.
}

if ( ! function_exists( 'sheaf_seed_filler' ) ) {
	/**
	 * ~1200 words of block-wrapped filler, deterministic per $seed.
	 */
	function sheaf_seed_filler( string $seed ): string {
		$sentences = [
			'The ash came down like snow that year, and no one spoke of it.',
			'She kept the lamp trimmed low, because oil was dear and the nights were long.',
			'Below the levee the water turned the colour of old iron and held its breath.',
			'He counted the bells from the far tower and lost the count twice.',
			'They had marched since the cold road forked, and the forking felt like a verdict.',
			'A gull wheeled once over the harbour and did not come back.',
			'In the workshop the brass mechanisms ticked out of time with one another.',
			'Nobody had told the children that the gate would not open again.',
			'The letters arrived weeks late, smelling of smoke and salt and other people.',
			'Skyfire broke along the ridge and for a moment the whole valley was noon.',
			'There is a kind of quiet that is only the held edge of a scream.',
			'He set the last gear and the mechanism shivered, almost alive, almost forgiving.',
			'The thaw uncovered what the winter had been polite enough to bury.',
			'She wrote his name in the margin and then, carefully, crossed it out.',
			'Floodlight swept the wall and found nothing, which was the worst answer.',
			'They said the war would be short; they were right, in the way of grief.',
			'Frost wrote its slow grammar across the glass before first light.',
			'The map was wrong, and being wrong, it had killed three of them already.',
			'In the hollow under the hill the old machines kept their patient appointments.',
			'He learned the city by its smells, and the city, in turn, forgot him.',
		];

		$count   = count( $sentences );
		$offset  = (int) ( crc32( $seed ) % $count );
		$words   = 0;
		$paras   = [];
		$current = [];
		$i       = 0;

		while ( $words < 1200 ) {
			$sentence  = $sentences[ ( $offset + $i ) % $count ];
			$current[] = $sentence;
			$words    += str_word_count( $sentence );
			if ( count( $current ) >= 5 ) {
				$paras[]= implode( ' ', $current );
				$current = [];
			}
			++$i;
		}
		if ( $current ) {
			$paras[] = implode( ' ', $current );
		}

		$blocks = '';
		foreach ( $paras as $p ) {
			$blocks .= "<!-- wp:paragraph -->\n<p>{$p}</p>\n<!-- /wp:paragraph -->\n\n";
		}
		return $blocks;
	}

	/**
	 * Upsert a Page by (slug, parent). Returns its ID.
	 */
	function sheaf_seed_page( string $slug, string $title, int $parent = 0, string $content = '' ): int {
		$existing = get_posts(
			[
				'post_type'   => 'page',
				'name'        => $slug,
				'post_parent' => $parent,
				'post_status' => 'any',
				'numberposts' => 1,
			]
		);
		$data = [
			'post_title'   => $title,
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_parent'  => $parent,
			'post_name'    => $slug,
			'post_content' => $content,
		];
		if ( $existing ) {
			$data['ID'] = $existing[0]->ID;
			wp_update_post( $data );
			return (int) $existing[0]->ID;
		}
		return (int) wp_insert_post( $data );
	}

	/**
	 * A short blurb for a section divider (a paragraph or two).
	 */
	function sheaf_seed_section_text( string $seed ): string {
		$lines = [
			'What follows was set down later, when the smoke had cleared enough to see by.',
			'The first part of the war belongs to the living; the rest belongs to the water.',
			'Here the wheels begin to turn in earnest, and nothing turns back.',
			'Of the cold months little was written, and less was meant to last.',
		];
		$line = $lines[ (int) ( crc32( $seed ) % count( $lines ) ) ];
		return "<!-- wp:paragraph -->\n<p>{$line}</p>\n<!-- /wp:paragraph -->";
	}

	/**
	 * A single placeholder paragraph for a book or series landing page.
	 */
	function sheaf_seed_blurb( string $seed ): string {
		$lines = [
			'Invented flap copy stands here in place of a real synopsis, present only so the page has something to show.',
			'A sentence or two of seeded placeholder, safe to ignore while the plugin is built.',
			'Fictional cover text for a book that does not exist; here to give the page shape.',
			'Development filler: imaginary blurb matter where a description will eventually go.',
		];
		$line = $lines[ (int) ( crc32( $seed ) % count( $lines ) ) ];
		return "<!-- wp:paragraph -->\n<p>{$line}</p>\n<!-- /wp:paragraph -->";
	}

	/**
	 * Landing content for a book Page: a blurb followed by its table of contents.
	 */
	function sheaf_seed_book_page( string $seed ): string {
		return sheaf_seed_blurb( $seed ) . "\n\n<!-- wp:shortcode -->\n[sheaf_toc]\n<!-- /wp:shortcode -->";
	}

	/**
	 * Landing content for a series Page: a blurb followed by links to its books.
	 */
	function sheaf_seed_series_page( string $seed, array $book_ids ): string {
		$items = '';
		foreach ( $book_ids as $bid ) {
			$items .= '<li><a href="' . esc_url( get_permalink( $bid ) ) . '">' . esc_html( get_the_title( $bid ) ) . '</a></li>';
		}
		$list = "<!-- wp:list -->\n<ul class=\"wp-block-list\">{$items}</ul>\n<!-- /wp:list -->";
		return sheaf_seed_blurb( $seed ) . "\n\n" . $list;
	}

	/**
	 * Upsert a chapter by (book, slug). Returns its ID.
	 */
	function sheaf_seed_chapter( int $book_id, string $slug, string $title, int $order, string $content, bool $is_section = false ): int {
		\Sheaf\Books::set_book_context( $book_id );

		$existing = get_posts(
			[
				'post_type'   => \Sheaf\Chapters::POST_TYPE,
				'name'        => $slug,
				'post_status' => 'any',
				'numberposts' => 1,
				'meta_key'    => \Sheaf\Books::BOOK_META,
				'meta_value'  => $book_id,
			]
		);
		$data = [
			'post_title'   => $title,
			'post_type'    => \Sheaf\Chapters::POST_TYPE,
			'post_status'  => 'publish',
			'post_name'    => $slug,
			'menu_order'   => $order,
			'post_content' => $content,
			'meta_input'   => [
				\Sheaf\Books::BOOK_META    => $book_id,
				\Sheaf\Chapters::SECTION_META => $is_section,
			],
		];
		if ( $existing ) {
			$data['ID'] = $existing[0]->ID;
			$id         = (int) $existing[0]->ID;
			wp_update_post( $data );
		} else {
			$id = (int) wp_insert_post( $data );
		}

		\Sheaf\Books::set_book_context( 0 );
		\Sheaf\Words::refresh( $id );
		return $id;
	}
}

// --- Structure: containers, series, books, standalone pages -----------------

$novels = sheaf_seed_page( 'novels', 'Novels' );
$fiction = sheaf_seed_page( 'fiction', 'Fiction' );

// The Long War — a series with two books. Each book page carries a blurb +
// its TOC; the series page (set below, once its books exist) links to them.
$long_war = sheaf_seed_page( 'long-war', 'The Long War', $novels );
$embers   = sheaf_seed_page( 'embers', 'Embers', $long_war, sheaf_seed_book_page( 'embers' ) );
$ashfall  = sheaf_seed_page( 'ashfall', 'Ashfall', $long_war, sheaf_seed_book_page( 'ashfall' ) );

// Gearfall — a second series (trilogy index) with two books here. (Titles are
// invented so the fixtures never reuse a real book's name.)
$gearfall  = sheaf_seed_page( 'gearfall', 'Gearfall', $novels );
$mainspring = sheaf_seed_page( 'mainspring', 'Mainspring', $gearfall, sheaf_seed_book_page( 'mainspring' ) );
$stormgear = sheaf_seed_page( 'stormgear', 'Stormgear', $gearfall, sheaf_seed_book_page( 'stormgear' ) );

// Wintering — a book with chapters that is NOT part of any series.
$wintering = sheaf_seed_page( 'wintering', 'Wintering', $novels, sheaf_seed_book_page( 'wintering' ) );

// Now that the books exist, give each series page a blurb + links to its books.
sheaf_seed_page( 'long-war', 'The Long War', $novels, sheaf_seed_series_page( 'long-war', [ $embers, $ashfall ] ) );
sheaf_seed_page( 'gearfall', 'Gearfall', $novels, sheaf_seed_series_page( 'gearfall', [ $mainspring, $stormgear ] ) );

// Standalone single-page novel (no chapters).
sheaf_seed_page( 'the-ashen-compact', 'The Ashen Compact', $novels, sheaf_seed_filler( 'ashen-compact' ) );

// Novella as a single post, plus a hand-authored child Page (author's note).
$asterism = sheaf_seed_page( 'asterism', 'Asterism', $fiction, sheaf_seed_filler( 'asterism' ) );
sheaf_seed_page( 'ship-design', 'On the Ship Design', $asterism, sheaf_seed_filler( 'ship-design' ) );

// Ordinary site pages.
$about = sheaf_seed_page( 'about', 'About' );
sheaf_seed_page( 'met', 'About the Author', $about );

// --- Chapters: five per book; "prologue" reused across five books -----------

$chapters = [
	$embers    => [
		[ 'prologue', 'Prologue', 0 ],
		[ '1-the-cold-road', 'The Cold Road', 1 ],
		[ '2-smoke-and-salt', 'Smoke and Salt', 2 ],
		[ '13-resignations', 'Resignations', 3 ],
		[ 'interlude-letters', 'Interlude: Letters', 4 ],
	],
	$ashfall   => [
		[ 'prologue', 'Prologue', 0 ],
		[ '1-grey-morning', 'Grey Morning', 1 ],
		[ '2-the-levee', 'The Levee', 2 ],
		[ '3-floodlight', 'Floodlight', 3 ],
		[ 'epilogue', 'Epilogue', 4 ],
	],
	// Mainspring shows section dividers interleaved with chapters.
	$mainspring => [
		[ 'part-i-wind-up', 'Part I: Wind-Up', 0, true ],
		[ 'prologue', 'Prologue', 1 ],
		[ '1', 'Chapter One', 2 ],
		[ '2', 'Chapter Two', 3 ],
		[ 'part-ii-escapement', 'Part II: Escapement', 4, true ],
		[ '3', 'Chapter Three', 5 ],
		[ '4', 'Chapter Four', 6 ],
	],
	$stormgear => [
		[ 'prologue', 'Prologue', 0 ],
		[ '10-ashpath', 'Chapter Ten', 1 ],
		[ '11-the-gate', 'Chapter Eleven', 2 ],
		[ '12-skyfire', 'Skyfire', 3 ],
		[ '13-aftermath', 'Chapter Thirteen', 4 ],
	],
	$wintering => [
		[ 'prologue', 'Prologue', 0 ],
		[ '1-first-frost', 'First Frost', 1 ],
		[ '2-the-hollow', 'The Hollow', 2 ],
		[ '3-thaw', 'Thaw', 3 ],
		[ '4-last-light', 'Last Light', 4 ],
	],
];

foreach ( $chapters as $book_id => $list ) {
	foreach ( $list as $c ) {
		$is_section = isset( $c[3] ) ? (bool) $c[3] : false;
		$content    = $is_section
			? sheaf_seed_section_text( $c[0] )
			: sheaf_seed_filler( $c[0] . '-' . $book_id );
		sheaf_seed_chapter( (int) $book_id, $c[0], $c[1], (int) $c[2], $content, $is_section );
	}
}

// A blog post elsewhere on the site (kept under /%postname%/).
if ( ! get_page_by_path( 'title-text', OBJECT, 'post' ) ) {
	wp_insert_post(
		[
			'post_title'   => 'Title Text',
			'post_name'    => 'title-text',
			'post_type'    => 'post',
			'post_status'  => 'publish',
			'post_content' => sheaf_seed_filler( 'blog' ),
		]
	);
}

flush_rewrite_rules();

WP_CLI::success( 'Sheaf seed complete. Books: Embers, Ashfall, Mainspring, Stormgear, Wintering (5 chapters each).' );
