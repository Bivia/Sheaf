<?php
/**
 * Unit tests for the front-end toolbar navigation (Sheaf\Admin_Bar) and the
 * Books::is_book() helper it leans on. CLI-only.
 *
 *   wpenv run cli wp eval-file wp-content/plugins/sheaf/tools/test-admin-bar.php
 *
 * Creates and deletes its own throwaway posts, and snapshots the global query
 * and current user, so it is safe to run on a live site.
 *
 * @package Sheaf
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

if ( ! class_exists( 'WP_Admin_Bar' ) ) {
	require_once ABSPATH . WPINC . '/class-wp-admin-bar.php';
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
wp_set_current_user( 1 ); // An admin, so edit-capability checks pass.

global $wp_query, $wp_the_query;
$saved_query     = $wp_query;
$saved_the_query = $wp_the_query;

$book  = (int) wp_insert_post( [ 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'AB Test Book' ] );
$plain = (int) wp_insert_post( [ 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'AB Plain Page' ] );
$chap  = (int) wp_insert_post( [ 'post_type' => \Sheaf\Chapters::POST_TYPE, 'post_status' => 'publish', 'post_title' => 'AB Chapter' ] );
update_post_meta( $chap, \Sheaf\Books::BOOK_META, $book );

// Point the main query at a post, so is_page()/is_singular() answer for it.
$view = function ( array $args ) {
	global $wp_query, $wp_the_query;
	$wp_query     = new WP_Query();
	$wp_query->query( $args );
	$wp_the_query = $wp_query;
};

// A toolbar seeded like core leaves it: a "+ New" parent to hang children on.
$make_bar = function (): WP_Admin_Bar {
	$bar = new WP_Admin_Bar();
	$bar->add_node( [ 'id' => 'new-content', 'title' => 'New' ] );
	return $bar;
};

try {
	// --- Books::is_book() -------------------------------------------------
	$check( \Sheaf\Books::is_book( $book ), 'is_book: true for a page with a chapter' );
	$check( ! \Sheaf\Books::is_book( $plain ), 'is_book: false for a page with none' );
	$check( ! \Sheaf\Books::is_book( 0 ), 'is_book: false for 0' );

	// --- Viewing a book page ---------------------------------------------
	$view( [ 'page_id' => $book ] );
	$bar = $make_bar();
	$bar->add_node( [ 'id' => 'edit', 'title' => 'Edit Page', 'href' => 'EDIT_URL' ] );
	\Sheaf\Admin_Bar::nodes( $bar );

	$new = $bar->get_node( 'new-' . \Sheaf\Chapters::POST_TYPE );
	$check( $new && false !== strpos( (string) $new->href, 'sheaf_book=' . $book ), 'book page: +New Chapter pre-selects the page' );
	$edit = $bar->get_node( 'edit' );
	$check( $edit && 'Edit Book' === $edit->title, 'book page: "Edit Page" becomes "Edit Book"' );
	$check( $edit && 'EDIT_URL' === $edit->href, 'book page: the edit link is preserved' );

	// --- Viewing a plain (non-book) page ---------------------------------
	$view( [ 'page_id' => $plain ] );
	$bar = $make_bar();
	$bar->add_node( [ 'id' => 'edit', 'title' => 'Edit Page', 'href' => 'EDIT_URL' ] );
	\Sheaf\Admin_Bar::nodes( $bar );

	$new = $bar->get_node( 'new-' . \Sheaf\Chapters::POST_TYPE );
	$check( $new && false !== strpos( (string) $new->href, 'sheaf_book=' . $plain ), 'plain page: +New Chapter pre-selects the page' );
	$check( 'Edit Page' === $bar->get_node( 'edit' )->title, 'plain page: "Edit Page" is left alone' );

	// --- Viewing a chapter -----------------------------------------------
	$view( [ 'p' => $chap, 'post_type' => \Sheaf\Chapters::POST_TYPE ] );
	$bar = $make_bar();
	\Sheaf\Admin_Bar::nodes( $bar );

	$new = $bar->get_node( 'new-' . \Sheaf\Chapters::POST_TYPE );
	$check( $new && false !== strpos( (string) $new->href, 'sheaf_book=' . $book ), 'chapter: +New Chapter pre-selects the chapter’s book' );
	$edit = $bar->get_node( 'edit' );
	$check( $edit && 'Edit Chapter' === $edit->title, 'chapter: "Edit Chapter" is added' );
	$check( $edit && false !== strpos( (string) $edit->href, 'post=' . $chap ), 'chapter: the edit link targets the chapter' );

	// --- No "+ New" menu to hang on --------------------------------------
	$view( [ 'page_id' => $book ] );
	$bar = new WP_Admin_Bar(); // No "new-content" parent.
	\Sheaf\Admin_Bar::nodes( $bar );
	$check( null === $bar->get_node( 'new-' . \Sheaf\Chapters::POST_TYPE ), 'absent "+ New" menu: no Chapter item added' );
} finally {
	wp_delete_post( $chap, true );
	wp_delete_post( $book, true );
	wp_delete_post( $plain, true );
	$wp_query     = $saved_query;
	$wp_the_query = $saved_the_query;
	wp_set_current_user( $orig_user );
}

WP_CLI::log( '' );
WP_CLI::log( "Passed: $pass   Failed: $fail" );
if ( $fail > 0 ) {
	WP_CLI::error( "$fail admin-bar check(s) failed." );
}
WP_CLI::success( 'Admin-bar navigation checks passed.' );
