<?php
/**
 * Unit tests for the Word-style import mapping (Sheaf\Import_Serializer +
 * Sheaf\Import). CLI-only.
 *
 *   wpenv run cli wp eval-file wp-content/plugins/sheaf/tools/test-import-styles.php
 *
 * Snapshots and restores the real style-set library, so it is safe to run on a
 * live site.
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

$private = function ( string $class, string $method ) {
	$m = new ReflectionMethod( $class, $method );
	$m->setAccessible( true );
	return $m;
};

$snapshot   = get_option( \Sheaf\Style_Sets::OPTION, [] );
$post_backup = $_POST;

try {
	/* ---- Serializer: character style -> inline span ----------------------- */

	$blocks = [
		[
			'type'  => 'paragraph',
			'style' => '',
			'runs'  => [
				[ 'text' => 'Hello ', 'style' => '' ],
				[ 'text' => 'BEEP', 'style' => 'ComputerVoice' ],
			],
		],
	];
	$settings = \Sheaf\Import_Serializer::sanitize_settings(
		[
			'keep_emphasis'     => true,
			'keep_named_styles' => true,
			'style_map'         => [ 'ComputerVoice' => 'sheaf-style-talking-monsters-computer-voice' ],
		]
	);
	$html = \Sheaf\Import_Serializer::to_blocks( $blocks, $settings );
	$check( false !== strpos( $html, '<span class="sheaf-style-talking-monsters-computer-voice">BEEP</span>' ), 'character style -> inline span' );

	// With named-style mapping OFF, the same map is ignored (opt-in gate).
	$off = \Sheaf\Import_Serializer::sanitize_settings(
		[ 'style_map' => [ 'ComputerVoice' => 'sheaf-style-talking-monsters-computer-voice' ] ]
	);
	$html_off = \Sheaf\Import_Serializer::to_blocks( $blocks, $off );
	$check( false === strpos( $html_off, '<span class=' ), 'named-style mapping is gated off when unchecked' );

	/* ---- Serializer: paragraph style -> block-style class ----------------- */

	$blocks = [
		[
			'type'  => 'paragraph',
			'style' => 'Verse',
			'runs'  => [ [ 'text' => 'A line of verse', 'style' => '' ] ],
		],
	];
	$settings = \Sheaf\Import_Serializer::sanitize_settings(
		[
			'keep_named_styles' => true,
			'block_style_map'   => [ 'Verse' => 'is-style-sheaf-poetry-verse' ],
		]
	);
	$html = \Sheaf\Import_Serializer::to_blocks( $blocks, $settings );
	$check( false !== strpos( $html, '"className":"is-style-sheaf-poetry-verse"' ), 'paragraph style -> block className attr' );
	$check( false !== strpos( $html, '<p class="is-style-sheaf-poetry-verse">' ), 'paragraph style -> block class on <p>' );

	// An unmapped paragraph stays a plain <p>.
	$plain = \Sheaf\Import_Serializer::to_blocks( $blocks, \Sheaf\Import_Serializer::default_settings() );
	$check( false !== strpos( $plain, "<p>A line of verse</p>" ), 'unmapped paragraph stays plain' );

	/* ---- collect_styles: counts + excludes structural styles -------------- */

	$entries = [
		[
			'error'  => '',
			'blocks' => [
				[ 'type' => 'heading', 'level' => 2, 'style' => 'Heading1', 'runs' => [ [ 'text' => 'T', 'style' => '' ] ] ],
				[ 'type' => 'paragraph', 'style' => 'Verse', 'runs' => [ [ 'text' => 'x', 'style' => 'ComputerVoice' ] ] ],
				[ 'type' => 'paragraph', 'style' => 'Verse', 'runs' => [ [ 'text' => 'y', 'style' => '' ] ] ],
				[ 'type' => 'list', 'ordered' => false, 'items' => [ [ [ 'text' => 'i', 'style' => 'ComputerVoice' ] ] ] ],
			],
		],
		[ 'error' => 'skipped', 'blocks' => [ [ 'type' => 'paragraph', 'style' => 'Ignored', 'runs' => [] ] ] ],
	];
	$collect = $private( '\Sheaf\Import', 'collect_styles' );
	$found   = $collect->invoke( null, $entries );
	$check( 2 === ( $found['para']['Verse'] ?? 0 ), 'collect_styles counts paragraph style (2)' );
	$check( ! isset( $found['para']['Heading1'] ), 'collect_styles excludes heading style' );
	$check( ! isset( $found['para']['Ignored'] ), 'collect_styles skips errored entries' );
	$check( 2 === ( $found['char']['ComputerVoice'] ?? 0 ), 'collect_styles counts character style across runs + list items (2)' );

	/* ---- read_choices: validates classes and new:<set> directives --------- */

	$_POST['char_map'] = [
		'Existing' => 'sheaf-style-ok',
		'Forged'   => 'sheaf-style-not-allowed',
		'Empty'    => '',
		'NewOK'    => 'new:dox',
		'NewBad'   => 'new:nope',
	];
	$opts_in = [
		'inline' => [ [ 'class' => 'sheaf-style-ok', 'label' => 'OK', 'set' => 'Dox', 'set_slug' => 'dox' ] ],
		'block'  => [],
		'sets'   => [ [ 'slug' => 'dox', 'label' => 'Dox' ] ],
	];
	$choices = $private( '\Sheaf\Import', 'read_choices' )->invoke( null, 'char_map', $opts_in, 'inline' );
	$check( 'sheaf-style-ok' === ( $choices['Existing'] ?? '' ), 'read_choices keeps an allowed class' );
	$check( ! isset( $choices['Forged'] ), 'read_choices drops a non-allowed class' );
	$check( ! isset( $choices['Empty'] ), 'read_choices drops an ignored mapping' );
	$check( 'new:dox' === ( $choices['NewOK'] ?? '' ), 'read_choices keeps new:<set> for an active set' );
	$check( ! isset( $choices['NewBad'] ), 'read_choices drops new:<set> for an unknown set' );
	unset( $_POST['char_map'] );

	$ecm = $private( '\Sheaf\Import', 'existing_class_map' )->invoke( null, [ 'A' => 'sheaf-style-ok', 'B' => 'new:dox', 'C' => '' ] );
	$check( [ 'A' => 'sheaf-style-ok' ] === $ecm, 'existing_class_map keeps only existing classes' );

	/* ---- style_options: splits a book's active styles, lists sets --------- */

	delete_option( \Sheaf\Style_Sets::OPTION );
	$set = \Sheaf\Style_Sets::save_set( 'Talking Monsters' );
	\Sheaf\Style_Sets::save_style( $set, [ 'label' => 'Computer Voice', 'kind' => 'inline', 'props' => [ 'font-family' => 'monospace' ] ] );
	\Sheaf\Style_Sets::save_style( $set, [ 'label' => 'Verse', 'kind' => 'block', 'props' => [ 'text-align' => 'center' ] ] );

	$book = (int) wp_insert_post(
		[
			'post_type'   => 'page',
			'post_title'  => 'Import Style Book',
			'post_status' => 'publish',
		]
	);
	update_post_meta( $book, \Sheaf\Style_Sets::BOOK_META, [ $set ] );

	$opts = $private( '\Sheaf\Import', 'style_options' )->invoke( null, $book );
	$check( 1 === count( $opts['inline'] ), 'style_options returns one inline option' );
	$check( 1 === count( $opts['block'] ), 'style_options returns one block option' );
	$check( 'sheaf-style-talking-monsters-computer-voice' === ( $opts['inline'][0]['class'] ?? '' ), 'style_options inline class' );
	$check( 'is-style-sheaf-talking-monsters-verse' === ( $opts['block'][0]['class'] ?? '' ), 'style_options block class' );
	$check( 1 === count( $opts['sets'] ) && $set === ( $opts['sets'][0]['slug'] ?? '' ), 'style_options lists the active set' );
	$check( $set === ( $opts['inline'][0]['set_slug'] ?? '' ), 'style_options inline carries set_slug' );

	/* ---- resolve_style_choices C3: new:<set> creates a style -------------- */

	$resolve = $private( '\Sheaf\Import', 'resolve_style_choices' );
	$data_c3 = [
		'book'     => $book,
		'entries'  => [ [ 'error' => '', 'blocks' => [ [ 'type' => 'paragraph', 'style' => '', 'runs' => [ [ 'text' => 'x', 'style' => 'Info' ] ] ] ] ] ],
		'settings' => [ 'keep_named_styles' => true, 'char_choices' => [ 'Info' => 'new:' . $set ], 'para_choices' => [], 'style_map' => [], 'block_style_map' => [], 'new_set' => '' ],
	];
	$out_c3 = $resolve->invoke( null, $data_c3 );
	$sd     = \Sheaf\Style_Sets::get_set( $set );
	$check( isset( $sd['styles']['info'] ), 'C3 creates a new style from a new:<set> choice' );
	$check( \Sheaf\Style_Sets::style_class( $set, 'info' ) === ( $out_c3['settings']['style_map']['Info'] ?? '' ), 'C3 maps the Word style to the new class' );

	wp_delete_post( $book, true );

	/* ---- resolve_style_choices C2: new set from found styles -------------- */

	$book2   = (int) wp_insert_post( [ 'post_type' => 'page', 'post_title' => 'No Sets Book', 'post_status' => 'publish' ] );
	$data_c2 = [
		'book'     => $book2,
		'entries'  => [ [ 'error' => '', 'blocks' => [ [ 'type' => 'paragraph', 'style' => 'Bar', 'runs' => [ [ 'text' => 'x', 'style' => 'Foo' ] ] ] ] ] ],
		'settings' => [ 'keep_named_styles' => true, 'char_choices' => [], 'para_choices' => [], 'style_map' => [], 'block_style_map' => [], 'new_set' => 'From Import' ],
	];
	$out_c2  = $resolve->invoke( null, $data_c2 );
	$active2 = \Sheaf\Style_Sets::active_sets( $book2 );
	$check( 1 === count( $active2 ), 'C2 activates exactly one new set on the book' );
	$sd2 = \Sheaf\Style_Sets::get_set( $active2[0] ?? '' );
	$check( $sd2 && isset( $sd2['styles']['foo'] ) && isset( $sd2['styles']['bar'] ), 'C2 set contains the found styles' );
	$check( '' !== ( $out_c2['settings']['style_map']['Foo'] ?? '' ), 'C2 maps the character style' );
	$check( '' !== ( $out_c2['settings']['block_style_map']['Bar'] ?? '' ), 'C2 maps the paragraph style' );

	wp_delete_post( $book2, true );
} finally {
	$_POST = $post_backup;
	update_option( \Sheaf\Style_Sets::OPTION, $snapshot );
}

WP_CLI::log( '' );
WP_CLI::log( "Passed: $pass   Failed: $fail" );
if ( $fail > 0 ) {
	WP_CLI::error( "$fail import-style check(s) failed." );
}
WP_CLI::success( 'Import style-mapping checks passed.' );
