<?php
/**
 * Estimated page counts for books and chapters.
 *
 * "Pages" are pseudo-pages derived from word count (there is no real
 * pagination) — used for the full-book scroll reader's "page X of Y" position
 * indicator and for enriching word-count/reading-time displays. The rate is
 * filterable via sheaf_words_per_page, mirroring how Words handles reading
 * speed. Numbering is cumulative across a book in reading order; section
 * dividers carry no words and occupy no pages.
 *
 * @package Sheaf
 */

namespace Sheaf;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Pages {

	/** Fallback words-per-page (a typical trade-paperback novel page). */
	public const DEFAULT_WORDS_PER_PAGE = 300;

	/**
	 * Words per pseudo-page. Adjust with the sheaf_words_per_page filter.
	 */
	public static function words_per_page(): int {
		$wpp = (int) apply_filters( 'sheaf_words_per_page', self::DEFAULT_WORDS_PER_PAGE );
		return $wpp > 0 ? $wpp : self::DEFAULT_WORDS_PER_PAGE;
	}

	/**
	 * Pages a word count spans (at least 1 for any real content, 0 for none).
	 */
	public static function for_words( int $words ): int {
		if ( $words < 1 ) {
			return 0;
		}
		return max( 1, (int) ceil( $words / self::words_per_page() ) );
	}

	/**
	 * Cumulative page map for a book, computed live from cached chapter word
	 * counts in reading order. A chapter's start_page is the page its first word
	 * falls on; sections span no pages but keep the start_page of the position
	 * they sit at.
	 *
	 * @return array{
	 *     total_pages:int,
	 *     total_words:int,
	 *     chapters:array<int,array{start_page:int,pages:int,words:int,is_section:bool}>
	 * }
	 */
	public static function book_map( int $book_id ): array {
		$wpp          = self::words_per_page();
		$before_words = 0; // Running word total preceding the current chapter.
		$total_words  = 0;
		$chapters     = [];

		foreach ( Books::get_chapters( $book_id ) as $chapter ) {
			$id         = (int) $chapter->ID;
			$is_section = Chapters::is_section( $id );
			$words      = $is_section ? 0 : Words::get( $id );

			$chapters[ $id ] = [
				'start_page' => max( 1, (int) floor( $before_words / $wpp ) + 1 ),
				'pages'      => $is_section ? 0 : self::for_words( $words ),
				'words'      => $words,
				'is_section' => $is_section,
			];

			$before_words += $words;
			$total_words  += $words;
		}

		return [
			'total_pages' => self::for_words( $total_words ),
			'total_words' => $total_words,
			'chapters'    => $chapters,
		];
	}

	/**
	 * A book's estimated total page count.
	 */
	public static function book_total( int $book_id ): int {
		return self::book_map( $book_id )['total_pages'];
	}
}
