<?php
/**
 * Unit tests for the .docx reader's direct-formatting detection
 * (Sheaf\Docx_Reader::parse_direct, surfaced as run['direct']). CLI-only.
 *
 *   wpenv run cli wp eval-file wp-content/plugins/sheaf/tools/test-docx.php
 *
 * Builds a tiny real .docx in a temp file, reads it, and inspects the IR.
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

$content_types = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
	. '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
	. '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
	. '<Default Extension="xml" ContentType="application/xml"/>'
	. '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
	. '</Types>';

$rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
	. '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
	. '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
	. '</Relationships>';

$document = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
	. '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>'
	. '<w:p>'
	. '<w:r><w:t xml:space="preserve">plain </w:t></w:r>'
	. '<w:r><w:rPr><w:rFonts w:ascii="Courier New" w:hAnsi="Courier New"/><w:sz w:val="20"/><w:color w:val="00B050"/></w:rPr><w:t>green mono</w:t></w:r>'
	. '</w:p>'
	. '<w:p><w:r><w:rPr><w:highlight w:val="yellow"/></w:rPr><w:t>marked</w:t></w:r></w:p>'
	. '</w:body></w:document>';

$tmp = tempnam( sys_get_temp_dir(), 'sheafdocx' );
$zip = new ZipArchive();
$zip->open( $tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE );
$zip->addFromString( '[Content_Types].xml', $content_types );
$zip->addFromString( '_rels/.rels', $rels );
$zip->addFromString( 'word/document.xml', $document );
$zip->close();

try {
	$ir     = \Sheaf\Docx_Reader::read( $tmp );
	$blocks = $ir['blocks'];

	// Collect runs by text across all blocks.
	$direct = [];
	foreach ( $blocks as $block ) {
		foreach ( (array) ( $block['runs'] ?? [] ) as $run ) {
			$direct[ trim( (string) $run['text'] ) ] = (array) ( $run['direct'] ?? [] );
		}
	}

	$check( 'Courier New' === ( $direct['green mono']['font-family'] ?? '' ), 'reads direct font-family' );
	$check( '10pt' === ( $direct['green mono']['font-size'] ?? '' ), 'reads direct font-size (sz 20 -> 10pt)' );
	$check( '#00b050' === ( $direct['green mono']['color'] ?? '' ), 'reads direct color' );
	$check( [] === ( $direct['plain'] ?? [ 'x' ] ), 'plain run has no direct formatting' );
	$check( 'yellow' === ( $direct['marked']['background-color'] ?? '' ), 'reads highlight as background-color' );
} finally {
	@unlink( $tmp );
}

WP_CLI::log( '' );
WP_CLI::log( "Passed: $pass   Failed: $fail" );
if ( $fail > 0 ) {
	WP_CLI::error( "$fail docx-reader check(s) failed." );
}
WP_CLI::success( 'Docx-reader direct-formatting checks passed.' );
