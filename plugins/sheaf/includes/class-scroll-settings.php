<?php
/**
 * Per-book "full-book scrolling" display settings.
 *
 * A book is a WP Page, so these live in one array meta (_sheaf_scroll) on that
 * Page. The whole feature is off unless `enabled` is true; the remaining
 * options only take effect while it is. Storage holds a fully sanitised array,
 * so get() just backfills defaults for any key added in a later version.
 *
 * The chapter-break "*_html" fields hold author-entered divider markup. The
 * author is trusted (they already publish HTML), any tags/attributes are
 * allowed, and this screen is gated to edit_posts — so the markup is stored
 * verbatim and printed as-is on the front end, the same trust boundary
 * Style_Sets uses for raw CSS. lint_html() is a best-effort well-formedness
 * check that warns but never strips.
 *
 * @package Sheaf
 */

namespace Sheaf;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Scroll_Settings {

	/** Array meta on the book Page. */
	public const META = '_sheaf_scroll';

	/**
	 * Break styles, in the order the dropdown presents them. Shared by the
	 * chapter break and the optional special section break.
	 */
	public const BREAKS = [ 'none', 'blank_lines', 'hr', 'page_break', 'hr_page_break' ];

	/** Break choices that carry author HTML (so the textarea applies). */
	public const HTML_BREAKS = [ 'hr', 'hr_page_break' ];

	/**
	 * Curated `list-style-type` tokens offered for the table of contents, plus
	 * the sentinel `custom` (which pairs with toc_list_style_custom). Any token
	 * here is emitted verbatim into an inline style; `custom` is sanitised to a
	 * bare identifier before use.
	 */
	public const LIST_STYLES = [
		'none',
		'disc',
		'circle',
		'square',
		'decimal',
		'decimal-leading-zero',
		'lower-roman',
		'upper-roman',
		'lower-alpha',
		'upper-alpha',
		'lower-greek',
	];

	/** What (if anything) each TOC item shows after the chapter title. */
	public const TOC_META = [ 'none', 'reading_time', 'word_count', 'page_number' ];

	/** Where the chapter breadcrumb trail is inserted on a single chapter view. */
	public const BREADCRUMB_POS = [ 'top', 'bottom', 'both', 'none' ];

	/** Where the chapter prev/next navigation is inserted (separate-page mode). */
	public const NAV_POS = [ 'none', 'top', 'bottom', 'both' ];

	/** What the chapter navigation contains (separate-page mode). */
	public const NAV_STYLE = [ 'back_to_book', 'prev_next', 'title_only', 'prev_next_title', 'toc_select' ];

	/**
	 * Factory defaults. A book with no saved settings reads exactly this.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults(): array {
		return [
			// Display settings — independent of the reading mode below.
			'toc_list_style'         => 'none',
			'toc_list_style_custom'  => '',
			'toc_meta'               => 'reading_time',
			'breadcrumbs'            => 'top',

			// Reading mode: separate pages (default) unless full-book scrolling.
			'enabled'                => false,

			// Separate-page chapter navigation.
			'chapter_nav_at'         => 'bottom',
			'chapter_nav_style'      => 'prev_next_title',

			// Full-book scrolling options (apply only while `enabled`).
			'chapter_titles'         => true,
			'chapter_break'          => 'page_break',
			'chapter_break_html'     => '',
			'special_section_breaks' => false,
			'section_break'          => 'page_break',
			'section_break_html'     => '',
			'show_page_numbers'      => false,
			'show_full_toc'          => false,
		];
	}

	/**
	 * The settings for a book, defaults backfilled.
	 *
	 * @return array<string,mixed>
	 */
	public static function get( int $book_id ): array {
		$saved = $book_id ? get_post_meta( $book_id, self::META, true ) : '';
		if ( ! is_array( $saved ) ) {
			return self::defaults();
		}
		// Stored data is already sanitised; merge so keys added later still have
		// a value, then coerce types defensively against hand-edited meta.
		return self::coerce( array_merge( self::defaults(), $saved ) );
	}

	/** Whether full-book scrolling is switched on for a book. */
	public static function enabled( int $book_id ): bool {
		return (bool) self::get( $book_id )['enabled'];
	}

	/**
	 * Persist a sanitised settings array for a book.
	 *
	 * @param array<string,mixed> $clean Already through sanitize()/from_request().
	 */
	public static function save( int $book_id, array $clean ): void {
		update_post_meta( $book_id, self::META, self::sanitize( $clean ) );
	}

	/**
	 * Build a settings array from a submitted form ($_POST, unslashed by the
	 * caller). Fields live under the sheaf_scroll[...] key. Form semantics: an
	 * absent checkbox means false, so this must see the whole submitted set.
	 *
	 * @param array<string,mixed> $post
	 * @return array<string,mixed>
	 */
	public static function from_request( array $post ): array {
		$raw = ( isset( $post['sheaf_scroll'] ) && is_array( $post['sheaf_scroll'] ) )
			? $post['sheaf_scroll']
			: [];
		return self::sanitize( $raw );
	}

	/**
	 * Clamp a raw settings array to known keys and types. Selects fall back to
	 * their default when the value isn't a known break; missing booleans are
	 * false (form semantics); HTML is kept verbatim (trimmed).
	 *
	 * @param array<string,mixed> $raw
	 * @return array<string,mixed>
	 */
	public static function sanitize( array $raw ): array {
		$d = self::defaults();

		$bool  = static fn( string $k ): bool => ! empty( $raw[ $k ] );
		$break = static function ( string $k, string $fallback ) use ( $raw ): string {
			$v = ( isset( $raw[ $k ] ) && is_string( $raw[ $k ] ) ) ? $raw[ $k ] : '';
			return in_array( $v, self::BREAKS, true ) ? $v : $fallback;
		};
		// Clamp a value to a known set, falling back to the default when unknown.
		$enum = static function ( string $k, array $allowed, string $fallback ) use ( $raw ): string {
			$v = ( isset( $raw[ $k ] ) && is_string( $raw[ $k ] ) ) ? $raw[ $k ] : '';
			return in_array( $v, $allowed, true ) ? $v : $fallback;
		};
		// The TOC list style is either the `custom` sentinel or a known token.
		$list = 'custom' === ( $raw['toc_list_style'] ?? '' )
			? 'custom'
			: $enum( 'toc_list_style', self::LIST_STYLES, $d['toc_list_style'] );

		return [
			'toc_list_style'         => $list,
			'toc_list_style_custom'  => self::clean_custom_marker( $raw['toc_list_style_custom'] ?? '' ),
			'toc_meta'               => $enum( 'toc_meta', self::TOC_META, $d['toc_meta'] ),
			'breadcrumbs'            => $enum( 'breadcrumbs', self::BREADCRUMB_POS, $d['breadcrumbs'] ),
			'chapter_nav_at'         => $enum( 'chapter_nav_at', self::NAV_POS, $d['chapter_nav_at'] ),
			'chapter_nav_style'      => $enum( 'chapter_nav_style', self::NAV_STYLE, $d['chapter_nav_style'] ),
			'enabled'                => $bool( 'enabled' ),
			'chapter_titles'         => $bool( 'chapter_titles' ),
			'chapter_break'          => $break( 'chapter_break', $d['chapter_break'] ),
			'chapter_break_html'     => self::clean_html( $raw['chapter_break_html'] ?? '' ),
			'special_section_breaks' => $bool( 'special_section_breaks' ),
			'section_break'          => $break( 'section_break', $d['section_break'] ),
			'section_break_html'     => self::clean_html( $raw['section_break_html'] ?? '' ),
			'show_page_numbers'      => $bool( 'show_page_numbers' ),
			'show_full_toc'          => $bool( 'show_full_toc' ),
		];
	}

	/**
	 * Value=>label map for a break dropdown, in presentation order.
	 *
	 * @return array<string,string>
	 */
	public static function break_choices(): array {
		return [
			'none'          => __( 'None', 'sheaf' ),
			'blank_lines'   => __( 'Four blank lines', 'sheaf' ),
			'hr'            => __( 'HTML divider', 'sheaf' ),
			'page_break'    => __( 'Page break', 'sheaf' ),
			'hr_page_break' => __( 'HTML divider, then page break', 'sheaf' ),
		];
	}

	/**
	 * TOC list-style options for the admin dropdown, grouped for <optgroup>s.
	 * The everyday choices (and the `custom` sentinel) sit up top; the rest
	 * follow. Values map to `list-style-type` tokens; `custom` pairs with a
	 * free-text field.
	 *
	 * @return array<string,array<string,string>>
	 */
	public static function list_style_groups(): array {
		return [
			__( 'Common', 'sheaf' ) => [
				'none'    => __( 'None', 'sheaf' ),
				'custom'  => __( 'Custom…', 'sheaf' ),
				'disc'    => __( 'Disk (bullet)', 'sheaf' ),
				'decimal' => __( 'Decimal (numbered)', 'sheaf' ),
			],
			__( 'More', 'sheaf' ) => [
				'circle'               => __( 'Circle', 'sheaf' ),
				'square'               => __( 'Square', 'sheaf' ),
				'decimal-leading-zero' => __( 'Decimal, leading zero', 'sheaf' ),
				'lower-roman'          => __( 'Lower roman (i, ii, iii)', 'sheaf' ),
				'upper-roman'          => __( 'Upper roman (I, II, III)', 'sheaf' ),
				'lower-alpha'          => __( 'Lower alpha (a, b, c)', 'sheaf' ),
				'upper-alpha'          => __( 'Upper alpha (A, B, C)', 'sheaf' ),
				'lower-greek'          => __( 'Lower greek (α, β, γ)', 'sheaf' ),
			],
		];
	}

	/**
	 * Value=>label map for the "per-chapter info" TOC dropdown.
	 *
	 * @return array<string,string>
	 */
	public static function toc_meta_choices(): array {
		return [
			'none'         => __( 'None', 'sheaf' ),
			'reading_time' => __( 'Reading time', 'sheaf' ),
			'word_count'   => __( 'Word count', 'sheaf' ),
			'page_number'  => __( 'Page number in book', 'sheaf' ),
		];
	}

	/**
	 * Value=>label map for the breadcrumb-placement dropdown.
	 *
	 * @return array<string,string>
	 */
	public static function breadcrumb_choices(): array {
		return [
			'top'    => __( 'Top', 'sheaf' ),
			'bottom' => __( 'Bottom', 'sheaf' ),
			'both'   => __( 'Top and bottom', 'sheaf' ),
			'none'   => __( 'None', 'sheaf' ),
		];
	}

	/**
	 * Value=>label map for "display chapter navigation at".
	 *
	 * @return array<string,string>
	 */
	public static function nav_pos_choices(): array {
		return [
			'none'   => __( 'None', 'sheaf' ),
			'top'    => __( 'Top', 'sheaf' ),
			'bottom' => __( 'Bottom', 'sheaf' ),
			'both'   => __( 'Top and bottom', 'sheaf' ),
		];
	}

	/**
	 * Value=>label map for the chapter-navigation style. The "back_to_book"
	 * label is generic here; the admin screen substitutes the book's title.
	 *
	 * @return array<string,string>
	 */
	public static function nav_style_choices(): array {
		return [
			'back_to_book'    => __( 'Back to the book', 'sheaf' ),
			'prev_next'       => __( 'Previous / next only', 'sheaf' ),
			'title_only'      => __( 'Chapter titles only', 'sheaf' ),
			'prev_next_title' => __( 'Previous / next with chapter titles', 'sheaf' ),
			'toc_select'      => __( 'Full TOC drop-down', 'sheaf' ),
		];
	}

	/**
	 * The `list-style-type` value to emit for a book's TOC, resolving the
	 * `custom` sentinel. A custom value is either a CSS keyword / @counter-style
	 * name (emitted bare, e.g. `lower-armenian`) or a literal marker string
	 * (emitted quoted, e.g. `"⁂"`) — see custom_list_style(). Empty → none.
	 */
	public static function list_style_css( array $settings ): string {
		$style = (string) ( $settings['toc_list_style'] ?? 'none' );
		if ( 'custom' !== $style ) {
			return in_array( $style, self::LIST_STYLES, true ) ? $style : 'none';
		}
		return self::custom_list_style( self::clean_custom_marker( $settings['toc_list_style_custom'] ?? '' ) );
	}

	/**
	 * Resolve a cleaned custom value to a `list-style-type` value:
	 *  - empty → `none`
	 *  - already quoted ("…" or '…') → a normalised double-quoted string
	 *  - a bare CSS identifier (keyword / @counter-style name) → itself
	 *  - anything else (a raw symbol like `→`) → quoted as a marker string
	 * The input has already been stripped of characters that could escape the
	 * declaration or the style attribute (see clean_custom_marker), and the
	 * caller escapes the attribute, so quoting here is purely about CSS meaning.
	 */
	private static function custom_list_style( string $value ): string {
		$value = trim( $value );
		if ( '' === $value ) {
			return 'none';
		}
		$len = strlen( $value );
		if ( $len >= 2
			&& ( ( '"' === $value[0] && '"' === $value[ $len - 1 ] )
				|| ( "'" === $value[0] && "'" === $value[ $len - 1 ] ) )
		) {
			return '"' . str_replace( '"', '', substr( $value, 1, -1 ) ) . '"';
		}
		if ( preg_match( '/^[A-Za-z][A-Za-z0-9-]*$/', $value ) ) {
			return $value;
		}
		return '"' . str_replace( '"', '', $value ) . '"';
	}

	/**
	 * Best-effort well-formedness check on author divider HTML. Returns a list
	 * of human-readable problems (empty = clean). It never rewrites the markup;
	 * the caller surfaces these as a non-blocking warning.
	 *
	 * @return string[]
	 */
	public static function lint_html( string $html ): array {
		$html = trim( $html );
		if ( '' === $html ) {
			return [];
		}

		$prev = libxml_use_internal_errors( true );
		libxml_clear_errors();

		$doc = new \DOMDocument();
		// Wrap in a container with an encoding hint so a bare fragment parses and
		// stray/unbalanced tags surface as libxml errors.
		$doc->loadHTML(
			'<?xml encoding="UTF-8"?><div>' . $html . '</div>',
			LIBXML_NONET
		);

		$messages = [];
		foreach ( libxml_get_errors() as $error ) {
			// 801 = XML_HTML_UNKNOWN_TAG: libxml's HTML parser doesn't recognise
			// SVG, MathML or custom-element tags and calls them "invalid". They are
			// valid HTML5 and explicitly allowed here (any tag, trusted author), so
			// they are not malformation — only structural errors (mismatched
			// nesting, stray end tags, …) should warn.
			if ( 801 === (int) $error->code ) {
				continue;
			}
			$message = trim( (string) $error->message );
			if ( '' !== $message ) {
				$messages[] = $message;
			}
		}

		libxml_clear_errors();
		libxml_use_internal_errors( $prev );

		return array_values( array_unique( $messages ) );
	}

	/**
	 * Divider markup for a break value ('' when the break carries no HTML).
	 */
	public static function break_html( array $settings, string $field ): string {
		$break = (string) ( $settings[ $field ] ?? '' );
		if ( ! in_array( $break, self::HTML_BREAKS, true ) ) {
			return '';
		}
		$html_field = 'section_break' === $field ? 'section_break_html' : 'chapter_break_html';
		return (string) ( $settings[ $html_field ] ?? '' );
	}

	/**
	 * Coerce a merged settings array to the right scalar types, guarding against
	 * meta that was hand-edited or written by an older version.
	 *
	 * @param array<string,mixed> $s
	 * @return array<string,mixed>
	 */
	private static function coerce( array $s ): array {
		$d      = self::defaults();
		$in_set = static function ( $v, array $allowed, string $fallback ): string {
			return in_array( $v, $allowed, true ) ? (string) $v : $fallback;
		};
		return [
			'toc_list_style'         => 'custom' === $s['toc_list_style'] ? 'custom' : $in_set( $s['toc_list_style'], self::LIST_STYLES, $d['toc_list_style'] ),
			'toc_list_style_custom'  => self::clean_custom_marker( $s['toc_list_style_custom'] ),
			'toc_meta'               => $in_set( $s['toc_meta'], self::TOC_META, $d['toc_meta'] ),
			'breadcrumbs'            => $in_set( $s['breadcrumbs'], self::BREADCRUMB_POS, $d['breadcrumbs'] ),
			'chapter_nav_at'         => $in_set( $s['chapter_nav_at'], self::NAV_POS, $d['chapter_nav_at'] ),
			'chapter_nav_style'      => $in_set( $s['chapter_nav_style'], self::NAV_STYLE, $d['chapter_nav_style'] ),
			'enabled'                => (bool) $s['enabled'],
			'chapter_titles'         => (bool) $s['chapter_titles'],
			'chapter_break'          => in_array( $s['chapter_break'], self::BREAKS, true ) ? (string) $s['chapter_break'] : $d['chapter_break'],
			'chapter_break_html'     => (string) $s['chapter_break_html'],
			'special_section_breaks' => (bool) $s['special_section_breaks'],
			'section_break'          => in_array( $s['section_break'], self::BREAKS, true ) ? (string) $s['section_break'] : $d['section_break'],
			'section_break_html'     => (string) $s['section_break_html'],
			'show_page_numbers'      => (bool) $s['show_page_numbers'],
			'show_full_toc'          => (bool) $s['show_full_toc'],
		];
	}

	/**
	 * Store divider HTML verbatim (trimmed). Not sanitised by design: the author
	 * is trusted and any tag/attribute is allowed (see the class doc block).
	 */
	private static function clean_html( $html ): string {
		return is_string( $html ) ? trim( $html ) : '';
	}

	/**
	 * Clean a custom list-style value while preserving both intended forms — a
	 * keyword/@counter-style name and a quoted marker string like "⁂". Only the
	 * characters that could escape the inline declaration, the style attribute,
	 * or the tag are stripped: `; { } < > ( ) \` and control characters. Quotes,
	 * spaces and arbitrary symbols survive; the result is capped in length.
	 */
	private static function clean_custom_marker( $value ): string {
		if ( ! is_string( $value ) ) {
			return '';
		}
		$value = preg_replace( '/[;{}<>()\\\\]/', '', $value );
		$value = preg_replace( '/[\x00-\x1F]+/', '', (string) $value );
		$value = trim( (string) $value );
		return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, 64 ) : substr( $value, 0, 64 );
	}
}
