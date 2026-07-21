<?php
/**
 * Unit tests for private-chapter visibility in a book (Sheaf\Books). CLI-only.
 *
 *   wpenv run cli wp eval-file wp-content/plugins/sheaf/tools/test-chapter-visibility.php
 *
 * A book's chapter list (the TOC, breadcrumb select, page numbers, and the
 * full-book spine all route through Books::get_chapters) shows published
 * chapters to everyone and private chapters only to a reader allowed to see
 * them; routing to a private chapter's URL follows the same gate. Creates and
 * deletes its own throwaway posts and restores the current user, so it is safe
 * on a live site.
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

$orig_user = get_current_user_id();

$book = (int) wp_insert_post( [ 'post_type' => 'page', 'post_status' => 'private', 'post_title' => 'CV Private Book' ] );

$pub = (int) wp_insert_post(
	[
		'post_type'   => \Sheaf\Chapters::POST_TYPE,
		'post_status' => 'publish',
		'post_title'  => 'CV Public Chapter',
		'post_name'   => 'cv-public',
		'menu_order'  => 1,
	]
);
$priv = (int) wp_insert_post(
	[
		'post_type'   => \Sheaf\Chapters::POST_TYPE,
		'post_status' => 'private',
		'post_title'  => 'CV Private Chapter',
		'post_name'   => 'cv-private',
		'menu_order'  => 2,
	]
);
update_post_meta( $pub, \Sheaf\Books::BOOK_META, $book );
update_post_meta( $priv, \Sheaf\Books::BOOK_META, $book );

$ids = function ( array $posts ): array {
	return array_map( static fn( $p ) => (int) $p->ID, $posts );
};

try {
	// --- A reader allowed to see private posts (an admin) -----------------
	wp_set_current_user( 1 );
	$check( in_array( 'private', \Sheaf\Books::readable_statuses(), true ), 'authorised: readable_statuses includes private' );

	$got = $ids( \Sheaf\Books::get_chapters( $book ) );
	$check( [ $pub, $priv ] === $got, 'authorised: get_chapters lists both, in order' );
	$check( \Sheaf\Books::is_book( $book ), 'authorised: page is recognised as a book' );

	$resolved = \Sheaf\Books::get_chapter_in_book( 'cv-private', $book );
	$check( $resolved && (int) $resolved->ID === $priv, 'authorised: private chapter URL resolves' );

	// --- A reader who may not see private posts (logged out) --------------
	wp_set_current_user( 0 );
	$check( [ 'publish' ] === \Sheaf\Books::readable_statuses(), 'public: readable_statuses is publish-only' );

	$got = $ids( \Sheaf\Books::get_chapters( $book ) );
	$check( [ $pub ] === $got, 'public: get_chapters hides the private chapter' );

	$check( null === \Sheaf\Books::get_chapter_in_book( 'cv-private', $book ), 'public: private chapter URL does not resolve' );
	$resolved = \Sheaf\Books::get_chapter_in_book( 'cv-public', $book );
	$check( $resolved && (int) $resolved->ID === $pub, 'public: published chapter URL still resolves' );
} finally {
	wp_delete_post( $priv, true );
	wp_delete_post( $pub, true );
	wp_delete_post( $book, true );
	wp_set_current_user( $orig_user );
}

WP_CLI::log( '' );
WP_CLI::log( "Passed: $pass   Failed: $fail" );
if ( $fail > 0 ) {
	WP_CLI::error( "$fail chapter-visibility check(s) failed." );
}
WP_CLI::success( 'Chapter-visibility checks passed.' );
