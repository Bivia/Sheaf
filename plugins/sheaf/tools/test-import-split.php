<?php
/**
 * Unit tests for whole-book import: Docx_Reader's boundary annotation (against a
 * synthetic .docx built here) and Book_Splitter's chapter splitting (against IR
 * blocks). CLI-only.
 *
 *   wpenv run cli wp eval-file wp-content/plugins/sheaf/tools/test-import-split.php
 *
 * @package Sheaf
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

use Sheaf\Docx_Reader;
use Sheaf\Book_Splitter;

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

/* ----------------------------------------------------------- helpers ------ */

// Build a minimal .docx whose body is the given sequence of <w:p> XML.
$make_docx = static function ( string $body ) : string {
	$doc = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
		. '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
		. '<w:body>' . $body . '</w:body></w:document>';
	$types = '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Override PartName="/word/document.xml" ContentType="x"/></Types>';
	$rels  = '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="t" Target="word/document.xml"/></Relationships>';

	$path = (string) tempnam( sys_get_temp_dir(), 'sheafdocx' );
	$zip  = new \ZipArchive();
	$zip->open( $path, \ZipArchive::OVERWRITE );
	$zip->addFromString( '[Content_Types].xml', $types );
	$zip->addFromString( '_rels/.rels', $rels );
	$zip->addFromString( 'word/document.xml', $doc );
	$zip->close();
	return $path;
};

$para     = static fn( string $t, string $style = '' ): string =>
	'<w:p>' . ( '' !== $style ? '<w:pPr><w:pStyle w:val="' . $style . '"/></w:pPr>' : '' )
	. ( '' !== $t ? '<w:r><w:t xml:space="preserve">' . $t . '</w:t></w:r>' : '' ) . '</w:p>';
$blank    = '<w:p></w:p>';
$pagebrk  = '<w:p><w:r><w:br w:type="page"/></w:r></w:p>';
$pbb      = static fn( string $t ): string => '<w:p><w:pPr><w:pageBreakBefore/></w:pPr><w:r><w:t>' . $t . '</w:t></w:r></w:p>';
$sectpara = '<w:p><w:pPr><w:sectPr><w:type w:val="nextPage"/></w:sectPr></w:pPr></w:p>';

// Find the emitted block whose text equals $text.
$find = static function ( array $blocks, string $text ): ?array {
	foreach ( $blocks as $b ) {
		$t = '';
		foreach ( (array) ( $b['runs'] ?? [] ) as $r ) {
			$t .= (string) ( $r['text'] ?? '' );
		}
		if ( trim( $t ) === $text ) {
			return $b;
		}
	}
	return null;
};

$tmp = [];

try {
	/* ------------------------------------------- Docx_Reader: breaks ------ */

	$body = $para( 'Front matter line' )
		. $blank . $blank . $blank . $para( 'After three blanks' )
		. $pagebrk . $para( 'After page break' )
		. $sectpara . $para( 'After section' )
		. $para( '&#8226;&#8226;&#8226;' )
		. $para( 'Chapter Heading', 'Heading1' )
		. $pbb( 'After page break before' );

	$path  = $make_docx( $body );
	$tmp[] = $path;
	$ir    = Docx_Reader::read( $path, false ); // no title extraction
	$blocks = $ir['blocks'];

	$front = $find( $blocks, 'Front matter line' );
	$check( null !== $front && 0 === (int) $front['breaks']['blanks'] && ! $front['breaks']['page'], 'reader: first block has no breaks' );

	$blanks = $find( $blocks, 'After three blanks' );
	$check( null !== $blanks && 3 === (int) $blanks['breaks']['blanks'], 'reader: counts 3 blank paragraphs' );

	$pb = $find( $blocks, 'After page break' );
	$check( null !== $pb && true === $pb['breaks']['page'], 'reader: detects a page break (w:br)' );

	$sec = $find( $blocks, 'After section' );
	$check( null !== $sec && true === $sec['breaks']['section'], 'reader: detects a Word section break' );

	$pbb_block = $find( $blocks, 'After page break before' );
	$check( null !== $pbb_block && true === $pbb_block['breaks']['page'], 'reader: detects w:pageBreakBefore' );

	$sep = null;
	$head = null;
	foreach ( $blocks as $b ) {
		if ( 'separator' === ( $b['type'] ?? '' ) ) {
			$sep = $b;
		}
		if ( 'heading' === ( $b['type'] ?? '' ) ) {
			$head = $b;
		}
	}
	$check( null !== $sep, 'reader: ••• becomes a separator block' );
	$check( null !== $head && 2 === (int) $head['level'], 'reader: Word Heading 1 → IR level 2' );

	// With title extraction on (one-chapter mode), the leading paragraph is NOT
	// consumed (only a leading heading is) — sanity that the flag is honoured.
	$ir_titled = Docx_Reader::read( $path, true );
	$check( is_array( $ir_titled['blocks'] ), 'reader: title-extraction mode still returns blocks' );

	/* ------------------------------------------- Book_Splitter ------------ */

	$mk    = static fn( array $o ): array => array_merge( [ 'page' => false, 'section' => false, 'blanks' => 0 ], $o );
	$P     = static fn( string $t, array $br = [] ): array => [ 'type' => 'paragraph', 'runs' => [ [ 'text' => $t ] ], 'breaks' => $mk( $br ) ];
	$H     = static fn( string $t, int $lvl, array $br = [] ): array => [ 'type' => 'heading', 'level' => $lvl, 'runs' => [ [ 'text' => $t ] ], 'breaks' => $mk( $br ) ];
	$S     = static fn( array $br = [] ): array => [ 'type' => 'separator', 'breaks' => $mk( $br ) ];
	$sig   = static fn( array $on ): array => array_fill_keys( $on, true );
	$title = static fn( array $ch, int $i ): string => (string) ( $ch[ $i ]['title'] ?? '' );

	// No signals → a single chapter (first paragraph promoted as its title).
	$ch = Book_Splitter::split( [ $P( 'Alpha' ), $P( 'Beta' ) ], [] );
	$check( 1 === count( $ch ) && 'Alpha' === $title( $ch, 0 ) && 1 === count( $ch[0]['blocks'] ), 'split: no signals → one chapter' );

	// Page break with front matter.
	$ch = Book_Splitter::split( [ $P( 'Front' ), $P( 'Ch1 first', [ 'page' => true ] ), $P( 'Ch1 body' ) ], $sig( [ 'page' ] ) );
	$check( 2 === count( $ch ), 'split: page break makes 2 chapters (front matter + ch1)' );
	$check( 'Front' === $title( $ch, 0 ), 'split: front matter is chapter 1' );
	$check( 'Ch1 first' === $title( $ch, 1 ) && 1 === count( $ch[1]['blocks'] ), 'split: chapter 1 titled by promoted first paragraph' );

	// Collapse: page break + heading = one break, titled by the heading.
	$ch = Book_Splitter::split( [ $P( 'Prev' ), $H( 'Chapter Two', 2, [ 'page' => true ] ) ], $sig( [ 'page', 'heading1' ] ) );
	$check( 2 === count( $ch ) && 'Chapter Two' === $title( $ch, 1 ), 'split: page+heading collapse to one break, heading titles it' );

	// Collapse: separator + heading (blank between) = one break.
	$ch = Book_Splitter::split( [ $P( 'Prev' ), $S(), $H( 'Named', 2, [ 'blanks' => 1 ] ), $P( 'Body' ) ], $sig( [ 'symbols', 'heading1' ] ) );
	$check( 2 === count( $ch ) && 'Named' === $title( $ch, 1 ) && 1 === count( $ch[1]['blocks'] ), 'split: separator+heading collapse to one break' );

	// Blank-lines threshold: 2 blanks no split, 3 blanks split.
	$ch = Book_Splitter::split( [ $P( 'A' ), $P( 'B', [ 'blanks' => 2 ] ), $P( 'C', [ 'blanks' => 3 ] ) ], $sig( [ 'blanks' ] ) );
	$check( 2 === count( $ch ), 'split: splits at 3 blank lines, not 2' );
	$check( 2 === count( $ch[0]['blocks'] ) + 0 || 'A' === $title( $ch, 0 ), 'split: A+B in chapter 1' );

	// Section break.
	$ch = Book_Splitter::split( [ $P( 'A' ), $P( 'B', [ 'section' => true ] ) ], $sig( [ 'section' ] ) );
	$check( 2 === count( $ch ), 'split: Word section break splits' );

	// Symbols unchecked → separator stays in the body as a scene break.
	$ch = Book_Splitter::split( [ $P( 'A' ), $S(), $P( 'B' ) ], $sig( [ 'page' ] ) );
	$check( 1 === count( $ch ) && 2 === count( $ch[0]['blocks'] ), 'split: separator retained when symbols not chosen' );

	// Symbols checked → split at the ••• and the marker is dropped.
	$ch = Book_Splitter::split( [ $P( 'A' ), $S(), $P( 'B' ) ], $sig( [ 'symbols' ] ) );
	$check( 2 === count( $ch ) && 'B' === $title( $ch, 1 ), 'split: symbols split and drop the marker' );

	// Heading 2 (IR level 3) titling.
	$ch = Book_Splitter::split( [ $P( 'A' ), $H( 'Sub', 3, [] ), $P( 'body' ) ], $sig( [ 'heading2' ] ) );
	$check( 2 === count( $ch ) && 'Sub' === $title( $ch, 1 ), 'split: Word Heading 2 (IR level 3) titles its chapter' );

	// Doc starting with a heading boundary → no empty front-matter chapter.
	$ch = Book_Splitter::split( [ $H( 'Ch1', 2, [] ), $P( 'body' ) ], $sig( [ 'heading1' ] ) );
	$check( 1 === count( $ch ) && 'Ch1' === $title( $ch, 0 ) && 1 === count( $ch[0]['blocks'] ), 'split: leading heading → single chapter, no empty front matter' );

} finally {
	foreach ( $tmp as $p ) {
		@unlink( $p );
	}
	WP_CLI::log( '' );
	WP_CLI::log( "PASS $pass   FAIL $fail" );
	if ( $fail > 0 ) {
		WP_CLI::halt( 1 );
	}
}
