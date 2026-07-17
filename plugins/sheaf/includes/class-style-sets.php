<?php
/**
 * The style-set library.
 *
 * Authors define named "style sets" — e.g. a set "Talking Monsters" holding the
 * styles "Computer Voice" (monospace) and "Telepathy" (cursive). Each style is a
 * small bag of CSS properties (plus an optional raw-CSS escape hatch) and a kind:
 * "inline" (a rich-text span, like bold) or "block" (a whole paragraph).
 *
 * The library is a single global option, shared across the whole site. A Book
 * Page activates zero or more sets (Style_Sets::BOOK_META); that drives which
 * styles the editor offers for the book's chapters and the import mapper — NOT
 * the front-end CSS, which is emitted globally and keyed on each style's class
 * alone, so a class means the same thing everywhere. Authors who want a style to
 * differ in one book override it with their own higher-specificity CSS via the
 * hierarchy body classes (see Frontend::hierarchy_classes()).
 *
 * @package Sheaf
 */

namespace Sheaf;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Style_Sets {

	/** Option holding the whole library. */
	public const OPTION = 'sheaf_style_sets';

	/** Book Page meta: the set slugs active on that book. */
	public const BOOK_META = '_sheaf_style_sets';

	/** A style applies to an inline run or to a whole block. */
	public const KINDS = [ 'inline', 'block' ];

	/**
	 * Per-set "page styles": free-form CSS scoped to the set's body class, so it
	 * restyles every chapter in a book that activates the set. Stored on the set
	 * as an ordered list of blocks — [ 'extra' => class chain, 'css' => body ] —
	 * where the base block has an empty 'extra'. Unlike inline/block styles
	 * (keyed on a class alone and emitted the same everywhere), page styles are
	 * the one surface that truly depends on activation: the CSS is emitted
	 * globally but only bites where Frontend::body_class has added the set's
	 * "sheaf-styleset-<slug>" class — i.e. that book's chapters.
	 */
	public const PAGE_STYLES = 'page_styles';

	/**
	 * CSS properties an author may set through the constrained form. Anything
	 * else must go through the raw-CSS escape hatch. Whitelisting keeps the
	 * generated CSS predictable and blocks property-name shenanigans.
	 */
	public const ALLOWED_PROPS = [
		'font-family',
		'font-size',
		'font-weight',
		'font-style',
		'font-variant',
		'line-height',
		'letter-spacing',
		'text-transform',
		'text-align',
		'text-indent',
		'color',
		'background-color',
		'margin-top',
		'margin-bottom',
		'margin-left',
		'margin-right',
	];

	// --- Read -----------------------------------------------------------------

	/**
	 * The whole library.
	 *
	 * @return array<string,array>
	 */
	public static function all(): array {
		$raw = get_option( self::OPTION, [] );
		return is_array( $raw ) ? $raw : [];
	}

	public static function get_set( string $set ): ?array {
		$all = self::all();
		return isset( $all[ $set ] ) ? $all[ $set ] : null;
	}

	public static function get_style( string $set, string $style ): ?array {
		$s = self::get_set( $set );
		return ( $s && isset( $s['styles'][ $style ] ) ) ? $s['styles'][ $style ] : null;
	}

	// --- Write ----------------------------------------------------------------

	/**
	 * Create a set (empty $set) or rename an existing one. Returns the set key.
	 */
	public static function save_set( string $label, string $set = '' ): string {
		$all = self::all();
		if ( '' === $set ) {
			$set = self::unique_key( sanitize_title( $label ), array_keys( $all ) );
		}
		if ( '' === $set ) {
			$set = self::unique_key( 'style-set', array_keys( $all ) );
		}
		if ( ! isset( $all[ $set ] ) ) {
			$all[ $set ] = [
				'label'  => '',
				'styles' => [],
			];
		}
		$all[ $set ]['label'] = sanitize_text_field( $label );
		self::put( $all );
		return $set;
	}

	public static function delete_set( string $set ): void {
		$all = self::all();
		unset( $all[ $set ] );
		self::put( $all );
	}

	/**
	 * Create (empty $style) or update a style within a set. Returns the style key
	 * ('' if the set does not exist).
	 *
	 * @param array $data { @type string $label; @type string $kind;
	 *                      @type array $props; @type string $css }
	 */
	public static function save_style( string $set, array $data, string $style = '' ): string {
		$all = self::all();
		if ( ! isset( $all[ $set ] ) ) {
			return '';
		}
		$label    = sanitize_text_field( $data['label'] ?? '' );
		$existing = array_keys( $all[ $set ]['styles'] ?? [] );
		if ( '' === $style ) {
			$style = self::unique_key( sanitize_title( $label ), $existing );
		}
		if ( '' === $style ) {
			$style = self::unique_key( 'style', $existing );
		}
		$kind = in_array( $data['kind'] ?? '', self::KINDS, true ) ? $data['kind'] : 'inline';

		$all[ $set ]['styles'][ $style ] = [
			'label' => $label,
			'kind'  => $kind,
			'props' => self::sanitize_props( (array) ( $data['props'] ?? [] ) ),
			'css'   => self::sanitize_raw_css( (string) ( $data['css'] ?? '' ) ),
		];
		self::put( $all );
		return $style;
	}

	public static function delete_style( string $set, string $style ): void {
		$all = self::all();
		unset( $all[ $set ]['styles'][ $style ] );
		self::put( $all );
	}

	// --- Derived --------------------------------------------------------------

	/**
	 * The CSS class marking inline text that carries this style, e.g.
	 * "sheaf-style-talking-monsters-computer-voice".
	 */
	public static function style_class( string $set, string $style ): string {
		return 'sheaf-style-' . $set . '-' . $style;
	}

	/**
	 * The block-style variation name for a block-kind style. WordPress turns the
	 * registered name into the "is-style-<name>" class on the block, so this is
	 * the part we control.
	 */
	public static function block_style_name( string $set, string $style ): string {
		return 'sheaf-' . $set . '-' . $style;
	}

	/**
	 * The class the *content* actually carries for a style — the one the global
	 * CSS targets. Inline styles use our own span class; block styles use the
	 * "is-style-<name>" class WordPress applies for a paragraph block-style
	 * variation. Either way a class means the same thing everywhere it appears.
	 */
	public static function css_class( string $set, string $style, string $kind ): string {
		return 'block' === $kind
			? 'is-style-' . self::block_style_name( $set, $style )
			: self::style_class( $set, $style );
	}

	/**
	 * The front-end selector for an applied inline/block style: its class,
	 * repeated so the rule carries three classes' worth of specificity — (0,3,0).
	 *
	 * An applied style is placed deliberately on a run of text and must win over
	 * the book's page-style baseline, whose scoped rules reach (0,2,2) for a
	 * pattern as ordinary as `body.sheaf-styleset-x .entry-content p` and would
	 * otherwise out-specify a single-class applied rule. Repeating the same class
	 * raises the weight without narrowing what it matches, so the style keeps
	 * applying wherever the class appears — an excerpt, a widget — which a
	 * location scope like `.single-sheaf_chapter .entry-content` would prevent.
	 *
	 * Three is the minimum that clears the baseline: doubling only ties the class
	 * column and loses on the trailing element. An author who wants a page style
	 * to win the other way takes their own selector past three classes.
	 */
	public static function applied_selector( string $set, string $style, string $kind ): string {
		$class = '.' . self::css_class( $set, $style, $kind );
		return $class . $class . $class;
	}

	/**
	 * The CSS declaration body for a style (no selector, no braces): the
	 * whitelisted props followed by the raw-CSS escape hatch.
	 */
	public static function declarations( array $style ): string {
		$out = [];
		foreach ( (array) ( $style['props'] ?? [] ) as $prop => $value ) {
			if ( in_array( $prop, self::ALLOWED_PROPS, true ) && '' !== $value ) {
				$out[] = $prop . ': ' . $value;
			}
		}
		$decls = implode( '; ', $out );
		$raw   = trim( (string) ( $style['css'] ?? '' ) );
		if ( '' !== $raw ) {
			$decls = '' !== $decls ? $decls . '; ' . $raw : $raw;
		}
		return $decls;
	}

	/**
	 * Book Page IDs that currently activate a given set.
	 *
	 * @return int[]
	 */
	public static function books_using( string $set ): array {
		global $wpdb;
		// The meta is a serialized array; each slug appears quoted ("slug"), so a
		// LIKE on the quoted form can't partial-match a longer slug.
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value LIKE %s",
				self::BOOK_META,
				'%' . $wpdb->esc_like( '"' . $set . '"' ) . '%'
			)
		);
		return array_map( 'intval', $ids );
	}

	/**
	 * Set slugs activated on a book Page, filtered to sets that still exist.
	 *
	 * @return string[]
	 */
	public static function active_sets( int $book_id ): array {
		$raw = get_post_meta( $book_id, self::BOOK_META, true );
		$raw = is_array( $raw ) ? $raw : [];
		return array_values( array_intersect( $raw, array_keys( self::all() ) ) );
	}

	// --- Page styles ----------------------------------------------------------

	/**
	 * The <body> class that activates a set's page styles, e.g.
	 * "sheaf-styleset-super-liminal". Frontend::body_class adds this to a chapter
	 * whose book activates the set; compile_page_css scopes the CSS to it.
	 */
	public static function styleset_body_class( string $set ): string {
		return 'sheaf-styleset-' . $set;
	}

	/**
	 * The stored page-styles blocks for a set (empty array if none), normalised
	 * to [ 'extra' => string, 'css' => string ] rows for the admin form.
	 *
	 * @return array<int,array{extra:string,css:string}>
	 */
	public static function get_page_styles( string $set ): array {
		$s = self::get_set( $set );
		if ( ! $s || empty( $s[ self::PAGE_STYLES ] ) || ! is_array( $s[ self::PAGE_STYLES ] ) ) {
			return [];
		}
		$out = [];
		foreach ( $s[ self::PAGE_STYLES ] as $block ) {
			$out[] = [
				'extra' => (string) ( $block['extra'] ?? '' ),
				'css'   => (string) ( $block['css'] ?? '' ),
			];
		}
		return $out;
	}

	/**
	 * Replace a set's page-styles blocks. Each input block is
	 * [ 'extra' => class chain, 'css' => author CSS ]; the base block's 'extra'
	 * is empty. Blocks with an invalid selector or with no usable CSS are
	 * dropped. Returns the warnings gathered while cleaning, for the admin.
	 *
	 * @param array<int,array{extra?:string,css?:string}> $blocks
	 * @return string[]
	 */
	public static function save_page_styles( string $set, array $blocks ): array {
		$all = self::all();
		if ( ! isset( $all[ $set ] ) ) {
			return [];
		}
		$clean    = [];
		$warnings = [];
		foreach ( $blocks as $block ) {
			$extra = self::clean_extra_selector( (string) ( $block['extra'] ?? '' ) );
			if ( null === $extra ) {
				$warnings[] = sprintf(
					/* translators: %s: the rejected class chain. */
					__( 'Ignored an invalid selector "%s".', 'sheaf' ),
					(string) ( $block['extra'] ?? '' )
				);
				continue;
			}
			$filtered = self::filter_page_css( (string) ( $block['css'] ?? '' ) );
			$warnings = array_merge( $warnings, $filtered['warnings'] );
			if ( '' === $filtered['css'] ) {
				continue; // empty (or fully-stripped) block: nothing to store.
			}
			$clean[] = [
				'extra' => $extra,
				'css'   => $filtered['css'],
			];
		}
		if ( $clean ) {
			$all[ $set ][ self::PAGE_STYLES ] = $clean;
		} else {
			unset( $all[ $set ][ self::PAGE_STYLES ] );
		}
		self::put( $all );
		return array_values( array_unique( $warnings ) );
	}

	/**
	 * The compiled, scoped stylesheet for a set's page styles: each block wrapped
	 * in `body.sheaf-styleset-<set>[.extra…] { … }`. Relies on native CSS nesting
	 * for any nested rules the author wrote inside a block. Empty if none.
	 */
	public static function compile_page_css( string $set ): string {
		$css = '';
		foreach ( self::get_page_styles( $set ) as $block ) {
			$inner = trim( $block['css'] );
			if ( '' === $inner ) {
				continue;
			}
			$selector = 'body.' . self::styleset_body_class( $set );
			if ( '' !== $block['extra'] ) {
				$selector .= '.' . $block['extra'];
			}
			$css .= $selector . " {\n" . $inner . "\n}\n";
		}
		return $css;
	}

	/**
	 * The compiled page CSS for every set in the library, concatenated. Emitted
	 * once, globally, and gated per-chapter by the body class (see
	 * Frontend::print_style_css).
	 */
	public static function page_css(): string {
		$css = '';
		foreach ( array_keys( self::all() ) as $set ) {
			$css .= self::compile_page_css( (string) $set );
		}
		return $css;
	}

	// --- Internals ------------------------------------------------------------

	/**
	 * Validate the extra classes chained onto a targeted block, e.g.
	 * "sheaf-section" or "sheaf-book-114.sheaf-section" (the leading dot is added
	 * when compiling, never typed here). Returns the cleaned chain — '' is valid
	 * and means the base block — or null if it is not a usable class chain.
	 */
	public static function clean_extra_selector( string $extra ): ?string {
		$extra = trim( $extra );
		if ( '' === $extra ) {
			return '';
		}
		// Word chars (incl. underscore), dot and hyphen only — the class-chain
		// alphabet from the spec's /^[\w_.\-]*$/.
		if ( ! preg_match( '/^[\w.\-]+$/', $extra ) ) {
			return null;
		}
		// No leading/trailing dot and no empty ".." segment, so compiling can
		// never emit an empty class token.
		if ( '.' === $extra[0] || '.' === substr( $extra, -1 ) || false !== strpos( $extra, '..' ) ) {
			return null;
		}
		return $extra;
	}

	/**
	 * Clean one author page-CSS block for safe emission inside its scoping
	 * wrapper. Input is trusted (admins only), so this catches mistakes rather
	 * than attackers: it keeps CSS comments, removes at-rules (no
	 * @media/@import/@font-face here), neutralises anything that could close the
	 * <style> element or inject script, and — critically — verifies the braces
	 * balance, because the result is emitted literally inside
	 * `body.sheaf-styleset-… { … }` and a stray brace would let a rule escape
	 * that scope or break the whole stylesheet. Unbalanced input yields ''.
	 *
	 * The walk is string- and comment-aware so a brace or "@" inside a quoted
	 * value (e.g. content: "}") is never miscounted.
	 *
	 * @return array{css:string,warnings:string[]}
	 */
	public static function filter_page_css( string $css ): array {
		$warnings = [];
		$len      = strlen( $css );
		$out      = '';
		$depth    = 0;
		$i        = 0;

		while ( $i < $len ) {
			$c = $css[ $i ];

			// Comments /* … */ — kept verbatim, but their interior is not parsed,
			// so a brace or "@" inside a comment is never counted. Any "</style"
			// they contain is neutralised by the post-pass below.
			if ( '/' === $c && $i + 1 < $len && '*' === $css[ $i + 1 ] ) {
				$end   = strpos( $css, '*/', $i + 2 );
				$stop  = ( false === $end ) ? $len : $end + 2;
				$out  .= substr( $css, $i, $stop - $i );
				$i     = $stop;
				continue;
			}

			// Strings — copied verbatim; their contents are never parsed.
			if ( '"' === $c || "'" === $c ) {
				$j = $i + 1;
				while ( $j < $len && $css[ $j ] !== $c ) {
					if ( '\\' === $css[ $j ] ) {
						++$j; // skip the escaped character
					}
					++$j;
				}
				$out .= substr( $css, $i, $j - $i + 1 );
				$i    = $j + 1;
				continue;
			}

			// At-rules — unsupported here; drop @word and its statement or block.
			if ( '@' === $c ) {
				$warnings[] = __( 'At-rules (such as @media, @import or @font-face) are not supported in page styles and were removed.', 'sheaf' );
				$i          = self::skip_at_rule( $css, $i, $len );
				continue;
			}

			if ( '{' === $c ) {
				++$depth;
			} elseif ( '}' === $c ) {
				--$depth;
				if ( $depth < 0 ) {
					$warnings[] = __( 'A stray "}" left the CSS unbalanced, so the rule was not saved.', 'sheaf' );
					return [
						'css'      => '',
						'warnings' => array_values( array_unique( $warnings ) ),
					];
				}
			}

			$out .= $c;
			++$i;
		}

		if ( 0 !== $depth ) {
			$warnings[] = __( 'A "{" was never closed, so the rule was not saved.', 'sheaf' );
			return [
				'css'      => '',
				'warnings' => array_values( array_unique( $warnings ) ),
			];
		}

		// Neutralise <style> break-out and legacy script vectors. Done after the
		// string-aware pass: a real "</style" closes the element regardless of CSS
		// string context, so it is stripped even inside quotes; these keywords
		// have no legitimate use in author CSS.
		$out = preg_replace( '#</\s*style#i', '', $out );
		$out = preg_replace( '/(?:javascript|expression)\s*:?\s*\(?/i', '', (string) $out );

		return [
			'css'      => trim( (string) $out ),
			'warnings' => array_values( array_unique( $warnings ) ),
		];
	}

	/**
	 * Given $css[$start] === '@', return the offset just past the at-rule: the
	 * ';' that ends a statement at-rule, or the '}' that closes a block at-rule,
	 * whichever the top level reaches first. String- and comment-aware.
	 */
	private static function skip_at_rule( string $css, int $start, int $len ): int {
		$i     = $start + 1;
		$depth = 0;
		while ( $i < $len ) {
			$c = $css[ $i ];

			if ( '/' === $c && $i + 1 < $len && '*' === $css[ $i + 1 ] ) {
				$end = strpos( $css, '*/', $i + 2 );
				$i   = ( false === $end ) ? $len : $end + 2;
				continue;
			}
			if ( '"' === $c || "'" === $c ) {
				$j = $i + 1;
				while ( $j < $len && $css[ $j ] !== $c ) {
					if ( '\\' === $css[ $j ] ) {
						++$j;
					}
					++$j;
				}
				$i = $j + 1;
				continue;
			}
			if ( ';' === $c && 0 === $depth ) {
				return $i + 1; // end of a statement at-rule (e.g. @import …;)
			}
			if ( '{' === $c ) {
				++$depth;
			} elseif ( '}' === $c ) {
				--$depth;
				if ( $depth <= 0 ) {
					return $i + 1; // end of a block at-rule (e.g. @media …{…})
				}
			}
			++$i;
		}
		return $len;
	}

	private static function put( array $all ): void {
		update_option( self::OPTION, $all );
	}

	private static function unique_key( string $base, array $taken ): string {
		if ( '' === $base ) {
			return '';
		}
		if ( ! in_array( $base, $taken, true ) ) {
			return $base;
		}
		$i = 2;
		while ( in_array( $base . '-' . $i, $taken, true ) ) {
			++$i;
		}
		return $base . '-' . $i;
	}

	private static function sanitize_props( array $props ): array {
		$clean = [];
		foreach ( $props as $prop => $value ) {
			$prop = strtolower( trim( (string) $prop ) );
			if ( ! in_array( $prop, self::ALLOWED_PROPS, true ) ) {
				continue;
			}
			$value = self::sanitize_css_value( (string) $value );
			if ( '' !== $value ) {
				$clean[ $prop ] = $value;
			}
		}
		return $clean;
	}

	/**
	 * A single CSS value: strip anything that could break out of the declaration
	 * or inject script — tags, braces, angle brackets, semicolons, url(javascript).
	 */
	private static function sanitize_css_value( string $value ): string {
		$value = wp_strip_all_tags( $value );
		$value = preg_replace( '/[<>{};]/', '', (string) $value );
		$value = preg_replace( '/(?:javascript|expression)\s*:?\s*\(?/i', '', (string) $value );
		return trim( (string) $value );
	}

	/**
	 * The raw-CSS escape hatch: a declaration list only (no selectors). Strip
	 * braces/tags so an author can't add rules or close the <style> element;
	 * semicolons stay, since they separate declarations.
	 */
	private static function sanitize_raw_css( string $css ): string {
		$css = wp_strip_all_tags( $css );
		$css = str_replace( [ '{', '}', '<', '>' ], '', $css );
		$css = preg_replace( '/(?:javascript|expression)\s*:?\s*\(?/i', '', (string) $css );
		return trim( (string) $css );
	}
}
