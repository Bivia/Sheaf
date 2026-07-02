<?php
/**
 * Public template tags for theme and custom-template authors.
 *
 * Thin global wrappers over Sheaf's static classes, so templates can branch on
 * and read full-book-scrolling data without touching internals. All are safe to
 * call in the loop on a chapter; the book-scoped ones accept an explicit id.
 *
 * @package Sheaf
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Whether a chapter belongs to a book with full-book scrolling enabled.
 *
 * @param int|WP_Post|null $post Chapter (default: current post).
 */
function sheaf_is_scroll_reader( $post = null ): bool {
	$book_id = sheaf_scroll_book_id( $post );
	return $book_id > 0 && \Sheaf\Scroll_Settings::enabled( $book_id );
}

/**
 * The book (Page) id a chapter belongs to, or 0 if none / not a chapter.
 *
 * @param int|WP_Post|null $post Chapter (default: current post).
 */
function sheaf_scroll_book_id( $post = null ): int {
	$post = get_post( $post );
	if ( ! $post instanceof WP_Post || \Sheaf\Chapters::POST_TYPE !== $post->post_type ) {
		return 0;
	}
	return (int) \Sheaf\Books::get_book_id( (int) $post->ID );
}

/**
 * The full-book "spine" — the same payload the reader gets in window.SheafScroll
 * (book meta, resolved settings, and every chapter's id/title/url/words/minutes/
 * pages/section flag). Bootstrap your own reader from this. Empty array if the
 * chapter has no scroll-enabled book.
 *
 * @param int $book_id    Book Page id (default: current chapter's book).
 * @param int $chapter_id Entry chapter id (default: current chapter).
 * @return array<string,mixed>
 */
function sheaf_scroll_spine( int $book_id = 0, int $chapter_id = 0 ): array {
	return \Sheaf\Frontend::spine( $book_id, $chapter_id );
}

/**
 * The cumulative page map for a book: total_pages, total_words, and per-chapter
 * start_page/pages/words/is_section in reading order.
 *
 * @param int $book_id Book Page id (default: current chapter's book).
 * @return array<string,mixed>
 */
function sheaf_book_page_map( int $book_id = 0 ): array {
	$book_id = $book_id ?: sheaf_scroll_book_id();
	return $book_id ? \Sheaf\Pages::book_map( $book_id ) : [];
}

/**
 * A book's estimated total pseudo-page count.
 *
 * @param int $book_id Book Page id (default: current chapter's book).
 */
function sheaf_book_pages( int $book_id = 0 ): int {
	$book_id = $book_id ?: sheaf_scroll_book_id();
	return $book_id ? \Sheaf\Pages::book_total( $book_id ) : 0;
}

/**
 * A chapter's estimated pseudo-page span (0 for a section marker).
 *
 * @param int|WP_Post|null $post Chapter (default: current post).
 */
function sheaf_chapter_pages( $post = null ): int {
	$post = get_post( $post );
	if ( ! $post instanceof WP_Post ) {
		return 0;
	}
	$book_id = sheaf_scroll_book_id( $post );
	if ( ! $book_id ) {
		return 0;
	}
	$map = \Sheaf\Pages::book_map( $book_id );
	return (int) ( $map['chapters'][ (int) $post->ID ]['pages'] ?? 0 );
}
