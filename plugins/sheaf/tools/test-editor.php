<?php
/**
 * Unit tests for the editor payload builder (Style_Sets_Editor::styles_for_book),
 * which feeds the chapter editor's Styles controls. CLI-only.
 *
 *   wpenv run cli wp eval-file wp-content/plugins/sheaf/tools/test-editor.php
 *
 * Snapshots and restores the real style-set library, so it is safe on a live site.
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

$snapshot = get_option( \Sheaf\Style_Sets::OPTION, [] );

try {
	$build = new ReflectionMethod( '\Sheaf\Style_Sets_Editor', 'styles_for_book' );
	$build->setAccessible( true );

	// Unassigned book → no styles.
	$check( [] === $build->invoke( null, 0 ), 'no styles for an unassigned book' );

	$set = \Sheaf\Style_Sets::save_set( 'Voices' );
	$in  = \Sheaf\Style_Sets::save_style( $set, [ 'label' => 'Computer Voice', 'kind' => 'inline', 'props' => [ 'font-family' => 'monospace' ] ] );
	$bl  = \Sheaf\Style_Sets::save_style( $set, [ 'label' => 'Verse', 'kind' => 'block', 'props' => [ 'text-align' => 'center' ] ] );

	$book = (int) wp_insert_post(
		[
			'post_type'   => 'page',
			'post_title'  => 'Editor Payload Book',
			'post_status' => 'publish',
		]
	);

	// Book with no active sets → no styles.
	$check( [] === $build->invoke( null, $book ), 'no styles when the book has no active sets' );

	// Activate the set: the payload should mirror its styles.
	update_post_meta( $book, \Sheaf\Style_Sets::BOOK_META, [ $set ] );
	$payload = $build->invoke( null, $book );
	$check( 2 === count( $payload ), 'payload carries both active styles' );

	$by_title = [];
	foreach ( $payload as $p ) {
		$by_title[ $p['title'] ] = $p;
	}

	$cv = $by_title['Computer Voice'] ?? [];
	$check( 'inline' === ( $cv['kind'] ?? '' ), 'inline style tagged inline' );
	$check( \Sheaf\Style_Sets::style_class( $set, $in ) === ( $cv['class'] ?? '' ), 'inline payload class matches style_class' );
	$check( 'sheaf/' . \Sheaf\Style_Sets::style_class( $set, $in ) === ( $cv['name'] ?? '' ), 'inline payload format name' );

	$vs = $by_title['Verse'] ?? [];
	$check( 'block' === ( $vs['kind'] ?? '' ), 'block style tagged block' );
	$check( \Sheaf\Style_Sets::block_style_name( $set, $bl ) === ( $vs['blockName'] ?? '' ), 'block payload name matches block_style_name' );

	wp_delete_post( $book, true );
} finally {
	update_option( \Sheaf\Style_Sets::OPTION, $snapshot );
}

WP_CLI::log( '' );
WP_CLI::log( "Passed: $pass   Failed: $fail" );
if ( $fail > 0 ) {
	WP_CLI::error( "$fail editor-payload check(s) failed." );
}
WP_CLI::success( 'Editor payload checks passed.' );
