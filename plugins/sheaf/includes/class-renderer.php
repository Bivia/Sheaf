<?php
/**
 * HTML for the table of contents and breadcrumbs.
 *
 * Shortcodes and blocks both call into here, so the markup lives in one place.
 *
 * @package Sheaf
 */

namespace Sheaf;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Renderer {

	/**
	 * Resolve which book a TOC should describe, given an explicit id (0 = auto).
	 *
	 * Auto-detection: on a chapter, its book; on a Page, the Page itself.
	 */
	public static function resolve_book_id( int $book_id = 0 ): int {
		if ( $book_id ) {
			return $book_id;
		}
		if ( is_singular( Chapters::POST_TYPE ) ) {
			return Books::get_book_id( (int) get_queried_object_id() );
		}
		if ( is_page() ) {
			return (int) get_queried_object_id();
		}
		return 0;
	}

	/**
	 * Table of contents for a book.
	 *
	 * @param int   $book_id Book Page ID (0 = auto-detect from the current view).
	 * @param array $args    { Optional.
	 *     @type bool $reading_time Legacy switch: false forces the per-item meta
	 *                              off. When true (default), the book's own
	 *                              "TOC included per-chapter info" setting decides
	 *                              what each item shows.
	 * }
	 */
	public static function toc( int $book_id = 0, array $args = [] ): string {
		$args = array_merge( [ 'reading_time' => true ], $args );

		$book_id = self::resolve_book_id( $book_id );
		if ( ! $book_id ) {
			return '';
		}

		$chapters = Books::get_chapters( $book_id );
		if ( ! $chapters ) {
			return '';
		}

		$settings = Scroll_Settings::get( $book_id );

		// The book setting names the per-item meta; a shortcode/block passing
		// reading_time="no" is an explicit override that suppresses it.
		$meta = false === $args['reading_time'] ? 'none' : (string) $settings['toc_meta'];

		// Only the page-number meta needs the cumulative page map — compute once.
		$map = 'page_number' === $meta ? Pages::book_map( $book_id ) : [ 'chapters' => [] ];

		$current = (int) get_queried_object_id();

		$items = '';
		foreach ( $chapters as $chapter ) {
			$id         = (int) $chapter->ID;
			$is_current = ( $chapter->ID === $current );

			// Section dividers ("Part I") are styled differently and carry no meta.
			if ( Chapters::is_section( $id ) ) {
				$items .= sprintf(
					'<li class="sheaf-toc__item sheaf-toc__item--section%1$s"><a class="sheaf-toc__part" href="%2$s"%3$s>%4$s</a></li>',
					$is_current ? ' is-current' : '',
					esc_url( get_permalink( $chapter ) ),
					$is_current ? ' aria-current="page"' : '',
					esc_html( get_the_title( $chapter ) )
				);
				continue;
			}

			$start = (int) ( $map['chapters'][ $id ]['start_page'] ?? 0 );

			$items .= sprintf(
				'<li class="sheaf-toc__item%1$s"><a href="%2$s"%3$s>%4$s</a>%5$s</li>',
				$is_current ? ' is-current' : '',
				esc_url( get_permalink( $chapter ) ),
				$is_current ? ' aria-current="page"' : '',
				esc_html( get_the_title( $chapter ) ),
				self::toc_meta( $id, $meta, $start )
			);
		}

		// The list style is a whitelisted token or a sanitised identifier, so it
		// is safe to inline; esc_attr guards the attribute regardless.
		$list_style = Scroll_Settings::list_style_css( $settings );

		return sprintf(
			'<nav class="sheaf-toc" aria-label="%1$s"><ol class="sheaf-toc__list" style="list-style-type:%2$s">%3$s</ol></nav>',
			esc_attr__( 'Table of contents', 'sheaf' ),
			esc_attr( $list_style ),
			$items
		);
	}

	/**
	 * The per-item meta span for one chapter, per the book's TOC meta setting:
	 * reading time (with the exact word count in the title), a word count, or the
	 * chapter's estimated start page. Returns '' when there is nothing to show.
	 */
	private static function toc_meta( int $chapter_id, string $meta, int $start_page ): string {
		if ( 'reading_time' === $meta ) {
			return self::reading_time_meta( $chapter_id );
		}

		if ( 'word_count' === $meta ) {
			$words = Words::get( $chapter_id );
			if ( $words < 1 ) {
				return '';
			}
			return sprintf(
				' <span class="sheaf-toc__meta">%s</span>',
				/* translators: %s: number of words. */
				esc_html( sprintf( _n( '%s word', '%s words', $words, 'sheaf' ), number_format_i18n( $words ) ) )
			);
		}

		if ( 'page_number' === $meta && $start_page > 0 ) {
			return sprintf(
				' <span class="sheaf-toc__meta">%s</span>',
				/* translators: %s: estimated page number a chapter begins on. */
				esc_html( sprintf( __( 'p. %s', 'sheaf' ), number_format_i18n( $start_page ) ) )
			);
		}

		return '';
	}

	/**
	 * The "5 min" reading-time span for a chapter, with the exact word count in
	 * the title attribute. Returns '' if the chapter has no counted words.
	 */
	private static function reading_time_meta( int $chapter_id ): string {
		$words = Words::get( $chapter_id );
		if ( $words < 1 ) {
			return '';
		}
		$minutes = Words::reading_minutes( $words );

		return sprintf(
			' <span class="sheaf-toc__meta" title="%1$s">%2$s</span>',
			/* translators: %s: number of words. */
			esc_attr( sprintf( _n( '%s word', '%s words', $words, 'sheaf' ), number_format_i18n( $words ) ) ),
			/* translators: %d: reading time in minutes. */
			esc_html( sprintf( _n( '%d min', '%d min', $minutes, 'sheaf' ), $minutes ) )
		);
	}

	/**
	 * Chapter navigation for a single chapter, in one of five styles (see
	 * Scroll_Settings::NAV_STYLE). An empty $style resolves the book's setting.
	 *
	 *  - back_to_book:    one link back to the book page.
	 *  - prev_next:       previous / next, direction words only.
	 *  - title_only:      previous / next, chapter titles only.
	 *  - prev_next_title: previous / next, direction word + title (default).
	 *  - toc_select:      a drop-down of every chapter (JS navigates on change).
	 *
	 * Sections are part of the prev/next sequence. Returns '' off-book, or when a
	 * prev/next style has no neighbour on either side.
	 */
	public static function chapter_nav( int $chapter_id = 0, string $style = '' ): string {
		$chapter_id = $chapter_id ?: (int) get_queried_object_id();
		if ( ! $chapter_id ) {
			return '';
		}

		$book_id = Books::get_book_id( $chapter_id );
		if ( ! $book_id ) {
			return '';
		}

		if ( '' === $style ) {
			$style = (string) Scroll_Settings::get( $book_id )['chapter_nav_style'];
		}
		if ( ! in_array( $style, Scroll_Settings::NAV_STYLE, true ) ) {
			$style = 'prev_next_title';
		}

		if ( 'back_to_book' === $style ) {
			return self::nav_back_to_book( $book_id );
		}

		$chapters = Books::get_chapters( $book_id );

		if ( 'toc_select' === $style ) {
			return self::nav_toc_select( $chapters, $chapter_id );
		}

		$index = null;
		foreach ( $chapters as $i => $chapter ) {
			if ( (int) $chapter->ID === $chapter_id ) {
				$index = $i;
				break;
			}
		}
		if ( null === $index ) {
			return '';
		}

		$prev = $chapters[ $index - 1 ] ?? null;
		$next = $chapters[ $index + 1 ] ?? null;
		if ( ! $prev && ! $next ) {
			return '';
		}

		$show_dir   = 'title_only' !== $style;
		$show_title = 'prev_next' !== $style;

		return sprintf(
			'<nav class="sheaf-chapter-nav" aria-label="%1$s">%2$s%3$s</nav>',
			esc_attr__( 'Chapter navigation', 'sheaf' ),
			self::nav_link( $prev, 'prev', __( 'Previous', 'sheaf' ), $show_dir, $show_title ),
			self::nav_link( $next, 'next', __( 'Next', 'sheaf' ), $show_dir, $show_title )
		);
	}

	/**
	 * One prev/next link. An aria-label always carries the direction and title,
	 * so the link stays meaningful even when only one of them is shown.
	 */
	private static function nav_link( ?\WP_Post $post, string $rel, string $dir_label, bool $show_dir, bool $show_title ): string {
		if ( ! $post ) {
			return '';
		}
		$title = get_the_title( $post );
		$aria  = 'prev' === $rel
			/* translators: %s: chapter title. */
			? sprintf( __( 'Previous chapter: %s', 'sheaf' ), $title )
			/* translators: %s: chapter title. */
			: sprintf( __( 'Next chapter: %s', 'sheaf' ), $title );

		$inner = '';
		if ( $show_dir ) {
			$inner .= sprintf( '<span class="sheaf-chapter-nav__dir">%s</span>', esc_html( $dir_label ) );
		}
		if ( $show_dir && $show_title ) {
			$inner .= ' ';
		}
		if ( $show_title ) {
			$inner .= sprintf( '<span class="sheaf-chapter-nav__title">%s</span>', esc_html( $title ) );
		}

		return sprintf(
			'<a class="sheaf-chapter-nav__link sheaf-chapter-nav__%1$s" rel="%1$s" href="%2$s" aria-label="%3$s">%4$s</a>',
			esc_attr( $rel ),
			esc_url( get_permalink( $post ) ),
			esc_attr( $aria ),
			$inner
		);
	}

	/**
	 * "Back to <book>" — a single link to the book page instead of prev/next.
	 */
	private static function nav_back_to_book( int $book_id ): string {
		return sprintf(
			'<nav class="sheaf-chapter-nav sheaf-chapter-nav--back" aria-label="%1$s"><a class="sheaf-chapter-nav__link sheaf-chapter-nav__book" href="%2$s">%3$s</a></nav>',
			esc_attr__( 'Chapter navigation', 'sheaf' ),
			esc_url( (string) get_permalink( $book_id ) ),
			/* translators: %s: book title. */
			esc_html( sprintf( __( 'Back to %s', 'sheaf' ), get_the_title( $book_id ) ) )
		);
	}

	/**
	 * A drop-down of every chapter, the current one selected; assets/
	 * chapter-nav-select.js navigates to the chosen option's value on change.
	 * Option values are permalinks, so it degrades to a harmless inert control
	 * without JS.
	 *
	 * @param \WP_Post[] $chapters
	 */
	private static function nav_toc_select( array $chapters, int $chapter_id ): string {
		if ( ! $chapters ) {
			return '';
		}

		$options = '';
		foreach ( $chapters as $chapter ) {
			$options .= sprintf(
				'<option value="%1$s"%2$s>%3$s</option>',
				esc_url( get_permalink( $chapter ) ),
				selected( (int) $chapter->ID, $chapter_id, false ),
				esc_html( get_the_title( $chapter ) )
			);
		}

		return sprintf(
			'<nav class="sheaf-chapter-nav sheaf-chapter-nav--select" aria-label="%1$s"><select class="sheaf-chapter-nav__select" aria-label="%2$s">%3$s</select></nav>',
			esc_attr__( 'Chapter navigation', 'sheaf' ),
			esc_attr__( 'Jump to chapter', 'sheaf' ),
			$options
		);
	}

	/**
	 * Breadcrumb trail for a chapter or a Page.
	 */
	public static function breadcrumbs( int $object_id = 0 ): string {
		$object_id = $object_id ?: (int) get_queried_object_id();
		if ( ! $object_id ) {
			return '';
		}

		$post = get_post( $object_id );
		if ( ! $post instanceof \WP_Post ) {
			return '';
		}

		$crumbs = [];

		if ( Chapters::POST_TYPE === $post->post_type ) {
			$book = Books::get_book( $object_id );
			if ( $book ) {
				foreach ( Books::ancestors( $book->ID ) as $ancestor ) {
					$crumbs[] = [ get_permalink( $ancestor ), get_the_title( $ancestor ) ];
				}
				$crumbs[] = [ get_permalink( $book ), get_the_title( $book ) ];
			}
			$crumbs[] = [ '', get_the_title( $post ) ];
		} else {
			foreach ( Books::ancestors( $object_id ) as $ancestor ) {
				$crumbs[] = [ get_permalink( $ancestor ), get_the_title( $ancestor ) ];
			}
			$crumbs[] = [ '', get_the_title( $post ) ];
		}

		if ( count( $crumbs ) < 2 ) {
			return '';
		}

		$parts = [];
		foreach ( $crumbs as $crumb ) {
			[ $url, $label ] = $crumb;
			$parts[]         = $url
				? sprintf( '<a href="%s">%s</a>', esc_url( $url ), esc_html( $label ) )
				: sprintf( '<span aria-current="page">%s</span>', esc_html( $label ) );
		}

		return sprintf(
			'<nav class="sheaf-breadcrumbs" aria-label="%1$s">%2$s</nav>',
			esc_attr__( 'Breadcrumb', 'sheaf' ),
			implode( ' <span class="sheaf-breadcrumbs__sep" aria-hidden="true">&rsaquo;</span> ', $parts )
		);
	}
}
