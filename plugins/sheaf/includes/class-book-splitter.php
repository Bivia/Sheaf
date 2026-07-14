<?php
/**
 * Split one document's IR into chapters, for whole-book import.
 *
 * Given the block list Docx_Reader produced (with its 'breaks' annotations) and
 * the set of split signals the author chose, this walks the blocks and cuts them
 * into chapters at every chosen signal:
 *
 *   - page      — a page break before the block
 *   - section   — a Word section break before the block
 *   - blanks    — three or more blank paragraphs before the block
 *   - heading1/2/3 — a Word Heading 1/2/3 paragraph (its text becomes the title)
 *   - symbols   — a line of symbols only (a scene-break glyph such as "•••")
 *
 * Two rules make the cuts behave the way a manuscript expects:
 *
 *   - Collapse. Consecutive boundaries separated only by whitespace count as one
 *     break (a page break, then blank lines, then a Heading 1 → a single split).
 *     This falls out of "only split when the current chapter already has
 *     content": a second boundary reached before any content is absorbed.
 *   - Front matter. Whatever precedes the first boundary (a title page, a table
 *     of contents) becomes the first chapter, which the author can delete.
 *
 * Each chapter is titled by the heading that began it (removed from the body) or,
 * failing that, by promoting its first paragraph (also removed).
 *
 * @package Sheaf
 */

namespace Sheaf;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Book_Splitter {

	/** The split signals an author may choose. */
	public const SIGNALS = [ 'page', 'section', 'heading1', 'heading2', 'heading3', 'symbols', 'blanks' ];

	/** How many consecutive blank paragraphs count as a "blank lines" break. */
	private const BLANK_RUN = 3;

	/**
	 * Split IR blocks into chapters on the chosen signals.
	 *
	 * @param array               $blocks  Docx_Reader IR blocks (with 'breaks').
	 * @param array<string,bool>  $signals Which signals to split on (SIGNALS keys).
	 * @return array<int,array{title:string,blocks:array}> Chapters, in order.
	 */
	public static function split( array $blocks, array $signals ): array {
		$chapters = [];
		$current  = []; // Blocks accumulated for the chapter being built.
		$title    = ''; // Title captured from a boundary heading, if any.

		foreach ( $blocks as $block ) {
			if ( self::is_boundary( $block, $signals ) ) {
				// Only cut when the current chapter already holds content; this
				// collapses consecutive boundaries into a single break.
				if ( $current ) {
					$chapters[] = self::finalize( $current, $title );
					$current    = [];
					$title      = '';
				}

				// A heading boundary supplies the (next) chapter's title and is
				// dropped from the body; a symbol boundary is dropped outright.
				$heading = self::boundary_heading_title( $block, $signals );
				if ( null !== $heading ) {
					if ( '' === $title ) {
						$title = $heading;
					}
					continue;
				}
				if ( self::is_symbol_boundary( $block, $signals ) ) {
					continue;
				}
				// A page/section/blank boundary: the block itself is real content
				// and starts the new chapter — fall through to keep it.
			}

			$current[] = $block;
		}

		if ( $current || '' !== $title ) {
			$chapters[] = self::finalize( $current, $title );
		}

		return $chapters;
	}

	/**
	 * Whether a chapter break begins at this block, per the chosen signals.
	 *
	 * @param array<string,bool> $signals
	 */
	private static function is_boundary( array $block, array $signals ): bool {
		$breaks = isset( $block['breaks'] ) && is_array( $block['breaks'] ) ? $block['breaks'] : [];

		if ( ! empty( $signals['page'] ) && ! empty( $breaks['page'] ) ) {
			return true;
		}
		if ( ! empty( $signals['section'] ) && ! empty( $breaks['section'] ) ) {
			return true;
		}
		if ( ! empty( $signals['blanks'] ) && (int) ( $breaks['blanks'] ?? 0 ) >= self::BLANK_RUN ) {
			return true;
		}
		if ( 'heading' === ( $block['type'] ?? '' ) ) {
			$sig = self::heading_signal( (int) ( $block['level'] ?? 0 ) );
			if ( '' !== $sig && ! empty( $signals[ $sig ] ) ) {
				return true;
			}
		}
		if ( 'separator' === ( $block['type'] ?? '' ) && ! empty( $signals['symbols'] ) ) {
			return true;
		}
		return false;
	}

	/**
	 * If this block is a heading acting as a boundary, its text (to title the
	 * chapter it begins); otherwise null.
	 *
	 * @param array<string,bool> $signals
	 */
	private static function boundary_heading_title( array $block, array $signals ): ?string {
		if ( 'heading' !== ( $block['type'] ?? '' ) ) {
			return null;
		}
		$sig = self::heading_signal( (int) ( $block['level'] ?? 0 ) );
		if ( '' === $sig || empty( $signals[ $sig ] ) ) {
			return null;
		}
		return trim( self::runs_text( $block['runs'] ?? [] ) );
	}

	/**
	 * @param array<string,bool> $signals
	 */
	private static function is_symbol_boundary( array $block, array $signals ): bool {
		return 'separator' === ( $block['type'] ?? '' ) && ! empty( $signals['symbols'] );
	}

	/**
	 * The signal key for an IR heading level. Docx_Reader maps Word "Heading 1"
	 * to IR level 2 (level 1 is the chapter title), so a Word Heading N is IR
	 * level N+1.
	 */
	private static function heading_signal( int $ir_level ): string {
		$word = $ir_level - 1;
		return ( $word >= 1 && $word <= 3 ) ? 'heading' . $word : '';
	}

	/**
	 * Turn accumulated blocks into a chapter: settle its title (the captured
	 * heading, or the first paragraph promoted and removed) and its body.
	 *
	 * @return array{title:string,blocks:array}
	 */
	private static function finalize( array $blocks, string $title ): array {
		$blocks = array_values( $blocks );

		if ( '' === $title ) {
			foreach ( $blocks as $i => $block ) {
				if ( ! in_array( $block['type'] ?? '', [ 'paragraph', 'heading', 'quote' ], true ) ) {
					continue;
				}
				$text = trim( self::runs_text( $block['runs'] ?? [] ) );
				if ( '' === $text ) {
					continue;
				}
				$title = $text;
				unset( $blocks[ $i ] ); // Promoted to the title, so drop from body.
				break;
			}
		}

		return [
			'title'  => $title,
			'blocks' => array_values( $blocks ),
		];
	}

	/**
	 * Concatenate the text of a set of runs.
	 *
	 * @param array<int,array<string,mixed>> $runs
	 */
	private static function runs_text( array $runs ): string {
		$text = '';
		foreach ( $runs as $run ) {
			$text .= (string) ( $run['text'] ?? '' );
		}
		return $text;
	}
}
