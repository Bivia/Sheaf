<?php
/**
 * Front-end shortcodes and the automatic chapter breadcrumb.
 *
 * - [sheaf_toc book="123|slug"]   table of contents (opt-in, anywhere)
 * - [sheaf_breadcrumbs]           breadcrumb trail (opt-in, anywhere)
 * - Breadcrumbs are also auto-prepended to single chapter views (the one
 *   piece of automatic chrome, since chapters are plugin-presented). The TOC
 *   is never auto-injected. Both behaviours are filterable.
 *
 * @package Sheaf
 */

namespace Sheaf;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Frontend {

	public static function register(): void {
		add_shortcode( 'sheaf_toc', [ self::class, 'toc_shortcode' ] );
		add_shortcode( 'sheaf_breadcrumbs', [ self::class, 'breadcrumbs_shortcode' ] );
		add_shortcode( 'sheaf_chapter_nav', [ self::class, 'chapter_nav_shortcode' ] );
		add_filter( 'the_content', [ self::class, 'auto_breadcrumbs' ], 9 );
		add_filter( 'the_content', [ self::class, 'auto_chapter_nav' ], 11 );
		add_filter( 'body_class', [ self::class, 'body_class' ] );

		// Themes navigate chapters by post date (a "previous"/"next" chapter from
		// some other book). Reading order is by book + menu_order, so suppress the
		// theme's adjacency for chapters; our chapter_nav provides the real links.
		add_filter( 'get_previous_post_where', [ self::class, 'suppress_chapter_adjacency' ], 10, 5 );
		add_filter( 'get_next_post_where', [ self::class, 'suppress_chapter_adjacency' ], 10, 5 );
	}

	/**
	 * Make a chapter have no date-based adjacent post, so the theme's built-in
	 * previous/next navigation finds nothing and renders nothing.
	 *
	 * @param string        $where The adjacent-post WHERE clause.
	 * @param bool          $in_same_term  Unused.
	 * @param int[]|string  $excluded_terms Unused.
	 * @param string        $taxonomy Unused.
	 * @param \WP_Post|null $post The post being navigated from.
	 */
	public static function suppress_chapter_adjacency( $where, $in_same_term = false, $excluded_terms = '', $taxonomy = '', $post = null ): string {
		if ( $post instanceof \WP_Post && Chapters::POST_TYPE === $post->post_type ) {
			return $where . ' AND 0 = 1';
		}
		return (string) $where;
	}

	/**
	 * Add a CSS hook when viewing a section divider, so it can be styled
	 * differently from a normal chapter.
	 */
	public static function body_class( array $classes ): array {
		if ( is_singular( Chapters::POST_TYPE ) && Chapters::is_section( (int) get_queried_object_id() ) ) {
			$classes[] = 'sheaf-section';
		}
		return $classes;
	}

	public static function toc_shortcode( $atts ): string {
		$atts    = shortcode_atts(
			[
				'book'         => '',
				'reading_time' => 'yes',
			],
			$atts,
			'sheaf_toc'
		);
		$book_id = self::resolve_book_attr( (string) $atts['book'] );
		return Renderer::toc(
			$book_id,
			[ 'reading_time' => self::is_truthy( $atts['reading_time'] ) ]
		);
	}

	public static function breadcrumbs_shortcode( $atts ): string {
		return Renderer::breadcrumbs();
	}

	public static function chapter_nav_shortcode( $atts ): string {
		return Renderer::chapter_nav();
	}

	/**
	 * Append previous/next links to a single chapter's content.
	 */
	public static function auto_chapter_nav( string $content ): string {
		if ( ! is_singular( Chapters::POST_TYPE ) || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}

		/** Filter: return false to disable automatic chapter prev/next links. */
		if ( ! apply_filters( 'sheaf_auto_chapter_nav', true ) ) {
			return $content;
		}

		return $content . Renderer::chapter_nav( (int) get_the_ID() );
	}

	/**
	 * Prepend breadcrumbs to a single chapter's content.
	 */
	public static function auto_breadcrumbs( string $content ): string {
		if ( ! is_singular( Chapters::POST_TYPE ) || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}

		/** Filter: return false to disable automatic chapter breadcrumbs. */
		if ( ! apply_filters( 'sheaf_auto_breadcrumbs', true ) ) {
			return $content;
		}

		return Renderer::breadcrumbs( (int) get_the_ID() ) . $content;
	}

	/**
	 * Interpret a shortcode boolean attribute ("no"/"0"/"false" = false).
	 */
	private static function is_truthy( string $value ): bool {
		return ! in_array( strtolower( trim( $value ) ), [ 'no', '0', 'false', 'off', '' ], true );
	}

	/**
	 * Turn a shortcode "book" attribute (numeric ID or a Page path/slug) into
	 * a book Page ID. Empty falls back to auto-detection in the Renderer.
	 */
	private static function resolve_book_attr( string $value ): int {
		$value = trim( $value );
		if ( '' === $value ) {
			return 0;
		}
		if ( ctype_digit( $value ) ) {
			return (int) $value;
		}
		$page = get_page_by_path( $value, OBJECT, 'page' );
		return $page ? (int) $page->ID : 0;
	}
}
