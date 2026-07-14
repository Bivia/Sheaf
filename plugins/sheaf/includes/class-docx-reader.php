<?php
/**
 * Read a .docx file into a neutral intermediate representation (IR).
 *
 * A .docx is a ZIP of WordprocessingML XML, which is semantic and free of the
 * mso-* inline-style clutter that Word's HTML export produces — so we choose
 * exactly what to keep. The reader walks word/document.xml into an array of
 * block nodes (paragraph / heading / list / quote / separator), each holding
 * inline "runs" with marks (bold/italic/underline/link). It also records the
 * originating Word *style name* on blocks and runs: unused today, but the hook
 * a future "style → semantic span" mapping needs (e.g. a "Telepathy" character
 * style becoming <span class="voice_telepathy">). See Import_Serializer.
 *
 * IR shape:
 *   block = [
 *     'type'    => 'paragraph'|'heading'|'list'|'quote'|'separator',
 *     'level'   => int,    // heading only (1-6)
 *     'ordered' => bool,   // list only
 *     'style'   => string, // originating Word paragraph-style name
 *     'direct'  => array,  // ad-hoc paragraph formatting (align/indent/spacing)
 *     'runs'    => run[],   // paragraph/heading/quote
 *     'items'   => run[][], // list: one run-array per item
 *     'breaks'  => array,   // {page:bool,section:bool,blanks:int} signals before
 *                           // this block, for the whole-book chapter splitter
 *   ]
 *   run = [ 'text'=>string, 'bold'=>bool, 'italic'=>bool, 'underline'=>bool,
 *           'href'=>string, 'style'=>string, 'direct'=>array ]
 *     'direct' holds ad-hoc character formatting (font/size/colour/highlight)
 *     applied inline rather than via a named style — the basis for clustering
 *     and mapping "unnamed" styles on import.
 *
 * @package Sheaf
 */

namespace Sheaf;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Docx_Reader {

	private const NS_W = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
	private const NS_R = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

	/** Guard against zip bombs: refuse documents with absurd entry counts. */
	private const MAX_ENTRIES = 5000;

	/** Hyperlink relationship id => target URL. */
	private array $relationships = [];

	/** numId => true when that list is a bullet (unordered) list. */
	private array $bullet_lists = [];

	/** styleId => ['name'=>string,'type'=>string,'props'=>array] from styles.xml. */
	private array $styles = [];

	private int $image_count = 0;
	private int $table_count = 0;
	private string $title     = '';

	/**
	 * Read a .docx file at $path into the IR.
	 *
	 * @param string $path Absolute path to the .docx file.
	 * @return array{title:string,blocks:array,images:int,tables:int,styles:array}
	 * @throws \RuntimeException When the file cannot be read or parsed.
	 */
	public static function read( string $path, bool $extract_title = true ): array {
		return ( new self() )->parse( $path, $extract_title );
	}

	private function parse( string $path, bool $extract_title = true ): array {
		if ( ! class_exists( '\ZipArchive' ) ) {
			throw new \RuntimeException( __( 'PHP ZipArchive is not available, so .docx files cannot be read on this server.', 'sheaf' ) );
		}

		$zip = new \ZipArchive();
		if ( true !== $zip->open( $path ) ) {
			throw new \RuntimeException( __( 'The file could not be opened. Is it a valid .docx Word document?', 'sheaf' ) );
		}
		if ( $zip->numFiles > self::MAX_ENTRIES ) {
			$zip->close();
			throw new \RuntimeException( __( 'The Word document has too many internal parts to import safely.', 'sheaf' ) );
		}

		$document = $zip->getFromName( 'word/document.xml' );
		$rels     = $zip->getFromName( 'word/_rels/document.xml.rels' );
		$numbering = $zip->getFromName( 'word/numbering.xml' );
		$styles    = $zip->getFromName( 'word/styles.xml' );
		$zip->close();

		if ( false === $document ) {
			throw new \RuntimeException( __( 'The file is missing its document body. Is it a valid .docx Word document?', 'sheaf' ) );
		}

		$this->relationships = $this->parse_relationships( (string) $rels );
		$this->bullet_lists  = $this->parse_numbering( (string) $numbering );
		$this->styles        = $this->parse_styles( (string) $styles );

		$blocks = $this->parse_document( $document, $extract_title );

		return [
			'title'  => $this->title,
			'blocks' => $blocks,
			'images' => $this->image_count,
			'tables' => $this->table_count,
			'styles' => $this->styles,
		];
	}

	/**
	 * Load an XML string into a DOMDocument with network access disabled.
	 */
	private function load_xml( string $xml ): ?\DOMDocument {
		if ( '' === trim( $xml ) ) {
			return null;
		}
		$dom = new \DOMDocument();
		$ok  = $dom->loadXML( $xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING );
		return $ok ? $dom : null;
	}

	/**
	 * Map hyperlink relationship ids to their external target URLs.
	 *
	 * @return array<string,string>
	 */
	private function parse_relationships( string $xml ): array {
		$dom = $this->load_xml( $xml );
		if ( ! $dom ) {
			return [];
		}
		$map = [];
		foreach ( $dom->getElementsByTagName( 'Relationship' ) as $rel ) {
			if ( 'hyperlink' === substr( (string) $rel->getAttribute( 'Type' ), -9 ) ) {
				$map[ $rel->getAttribute( 'Id' ) ] = (string) $rel->getAttribute( 'Target' );
			}
		}
		return $map;
	}

	/**
	 * Determine which numbering definitions are bullet (unordered) lists.
	 *
	 * numId -> num -> abstractNumId -> abstractNum -> lvl[0]/numFmt. A numFmt of
	 * "bullet" means an unordered list; anything else we treat as ordered.
	 *
	 * @return array<string,bool> numId => is-bullet
	 */
	private function parse_numbering( string $xml ): array {
		$dom = $this->load_xml( $xml );
		if ( ! $dom ) {
			return [];
		}
		$xpath = new \DOMXPath( $dom );
		$xpath->registerNamespace( 'w', self::NS_W );

		// abstractNumId => is-bullet (look at level 0's numFmt).
		$abstract = [];
		foreach ( $xpath->query( '//w:abstractNum' ) as $node ) {
			$id = $this->attr( $xpath, $node, '@w:abstractNumId' );
			$fmt = $this->attr( $xpath, $node, 'w:lvl[@w:ilvl="0"]/w:numFmt/@w:val' );
			if ( '' === $fmt ) {
				$fmt = $this->attr( $xpath, $node, 'w:lvl/w:numFmt/@w:val' );
			}
			$abstract[ $id ] = ( 'bullet' === $fmt );
		}

		// numId => is-bullet, via its abstractNumId.
		$map = [];
		foreach ( $xpath->query( '//w:num' ) as $node ) {
			$num_id      = $this->attr( $xpath, $node, '@w:numId' );
			$abstract_id = $this->attr( $xpath, $node, 'w:abstractNumId/@w:val' );
			$map[ $num_id ] = $abstract[ $abstract_id ] ?? false;
		}
		return $map;
	}

	/**
	 * Read the style definitions from word/styles.xml: each character or paragraph
	 * style's id, its human name (w:name) and the CSS props its formatting maps to.
	 * Character styles contribute their run formatting (font/size/colour); paragraph
	 * styles contribute that plus their layout (alignment/indent/spacing). This is
	 * what lets a named Word style ("Bibliography", "Computer Voice") become a
	 * style-set style that actually carries its font and layout on import.
	 *
	 * v1 reads each style's OWN rPr/pPr only — w:basedOn inheritance is not
	 * resolved, so a style that relies on its parent for a property won't carry it.
	 *
	 * @return array<string,array{name:string,type:string,props:array<string,string>}>
	 */
	private function parse_styles( string $xml ): array {
		$dom = $this->load_xml( $xml );
		if ( ! $dom ) {
			return [];
		}
		$xpath = new \DOMXPath( $dom );
		$xpath->registerNamespace( 'w', self::NS_W );

		$styles = [];
		foreach ( $xpath->query( '//w:style' ) as $style ) {
			if ( ! $style instanceof \DOMElement ) {
				continue;
			}
			$type = $this->attr( $xpath, $style, '@w:type' );
			if ( 'character' !== $type && 'paragraph' !== $type ) {
				continue; // Table and numbering styles don't map to our styles.
			}
			$id = $this->attr( $xpath, $style, '@w:styleId' );
			if ( '' === $id ) {
				continue;
			}
			$name = $this->attr( $xpath, $style, 'w:name/@w:val' );

			$props = $this->parse_direct( $style ); // rPr: font/size/colour.
			if ( 'paragraph' === $type ) {
				$props = array_merge( $props, $this->parse_direct_paragraph( $style ) ); // pPr: layout.
			}

			$styles[ $id ] = [
				'name'  => '' !== $name ? $name : $id,
				'type'  => $type,
				'props' => $props,
			];
		}
		return $styles;
	}

	/**
	 * Walk the document body into IR blocks.
	 *
	 * Each emitted block also carries a 'breaks' annotation describing the
	 * structural signals that occurred *before* it — a page break, a Word section
	 * break, and how many blank paragraphs preceded it. Word drops these from the
	 * visible IR (blank paragraphs and page breaks would otherwise vanish), but
	 * they are exactly what the whole-book splitter needs to find chapter
	 * boundaries. The annotation is inert for the one-file-one-chapter path.
	 *
	 * @param bool $extract_title Promote a leading heading to the chapter title
	 *                            and remove it (the one-chapter behaviour). Split
	 *                            mode passes false so each chapter keeps its own.
	 */
	private function parse_document( string $xml, bool $extract_title = true ): array {
		$dom = $this->load_xml( $xml );
		if ( ! $dom ) {
			throw new \RuntimeException( __( 'The Word document could not be parsed.', 'sheaf' ) );
		}
		$xpath = new \DOMXPath( $dom );
		$xpath->registerNamespace( 'w', self::NS_W );
		$xpath->registerNamespace( 'r', self::NS_R );

		$body = $xpath->query( '/w:document/w:body' )->item( 0 );
		if ( ! $body ) {
			return [];
		}

		$blocks      = [];
		$list_buffer = null; // Accumulates consecutive list items into one block.
		// Structural signals seen since the last emitted block, attached to the
		// next one as its 'breaks'.
		$pending = [
			'page'    => false,
			'section' => false,
			'blanks'  => 0,
		];

		foreach ( $body->childNodes as $node ) {
			if ( ! $node instanceof \DOMElement ) {
				continue;
			}
			$local = $node->localName;

			if ( 'tbl' === $local ) {
				++$this->table_count;
				$blocks      = $this->flush_list( $blocks, $list_buffer );
				$list_buffer = null;
				continue;
			}
			if ( 'p' !== $local ) {
				continue;
			}

			$this->count_media( $node );
			$runs = $this->parse_runs( $node );
			$ppr  = $this->child( $node, 'pPr' );

			// Structural signals for splitting: a page break before/in this
			// paragraph, and a paragraph-level section break (which ends a section
			// after this paragraph, so it applies to the *next* block).
			$page_here = $this->has_page_break_before( $ppr ) || $this->has_page_break( $node );
			$sect_here = null !== $ppr && null !== $this->child( $ppr, 'sectPr' );

			// A list item: buffer it so consecutive items become one list block.
			$num_id = $this->num_id( $ppr );
			if ( '' !== $num_id ) {
				$ordered = ! ( $this->bullet_lists[ $num_id ] ?? false );
				if ( null === $list_buffer || $list_buffer['ordered'] !== $ordered ) {
					$blocks       = $this->flush_list( $blocks, $list_buffer );
					$list_buffer  = [
						'type'    => 'list',
						'ordered' => $ordered,
						'style'   => '',
						'items'   => [],
						'breaks'  => $this->breaks_before( $pending, $page_here ),
					];
					$pending = [ 'page' => false, 'section' => false, 'blanks' => 0 ];
				}
				$list_buffer['items'][] = $runs;
				if ( $sect_here ) {
					$pending['section'] = true;
				}
				continue;
			}

			// Any non-list paragraph ends a run of list items.
			$blocks      = $this->flush_list( $blocks, $list_buffer );
			$list_buffer = null;

			$block = $this->paragraph_block( $node, $ppr, $runs );
			if ( null === $block ) {
				// An empty paragraph: not emitted, but it carries split signals.
				if ( $page_here ) {
					$pending['page'] = true;
				} elseif ( ! $sect_here ) {
					++$pending['blanks']; // A plain blank line.
				}
				if ( $sect_here ) {
					$pending['section'] = true;
				}
				continue;
			}

			$block['breaks'] = $this->breaks_before( $pending, $page_here );
			$pending         = [ 'page' => false, 'section' => false, 'blanks' => 0 ];
			if ( $sect_here ) {
				$pending['section'] = true; // Section break after this paragraph.
			}
			$blocks[] = $block;
		}

		$blocks = $this->flush_list( $blocks, $list_buffer );

		return $extract_title ? $this->extract_title( $blocks ) : array_values( $blocks );
	}

	/**
	 * The 'breaks' annotation for a block: the pending signals plus any page break
	 * that belongs to this paragraph itself.
	 *
	 * @param array{page:bool,section:bool,blanks:int} $pending
	 * @return array{page:bool,section:bool,blanks:int}
	 */
	private function breaks_before( array $pending, bool $page_here ): array {
		return [
			'page'    => $pending['page'] || $page_here,
			'section' => $pending['section'],
			'blanks'  => $pending['blanks'],
		];
	}

	/**
	 * Whether a paragraph carries w:pageBreakBefore (present-but-no-value = on).
	 */
	private function has_page_break_before( ?\DOMElement $ppr ): bool {
		if ( null === $ppr ) {
			return false;
		}
		$node = $this->child( $ppr, 'pageBreakBefore' );
		return null !== $node && $this->toggle_on( $node );
	}

	/**
	 * Whether a paragraph contains a page-break run (<w:br w:type="page">).
	 */
	private function has_page_break( \DOMElement $p ): bool {
		foreach ( $p->getElementsByTagNameNS( self::NS_W, 'br' ) as $br ) {
			if ( 'page' === $br->getAttributeNS( self::NS_W, 'type' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The list numbering id on a paragraph (w:pPr/w:numPr/w:numId/@w:val), or ''.
	 */
	private function num_id( ?\DOMElement $ppr ): string {
		$num_pr = null !== $ppr ? $this->child( $ppr, 'numPr' ) : null;
		$num_id = null !== $num_pr ? $this->child( $num_pr, 'numId' ) : null;
		return null !== $num_id ? trim( $num_id->getAttributeNS( self::NS_W, 'val' ) ) : '';
	}

	/**
	 * The paragraph-style name on a paragraph (w:pPr/w:pStyle/@w:val), or ''.
	 */
	private function pstyle( ?\DOMElement $ppr ): string {
		$ps = null !== $ppr ? $this->child( $ppr, 'pStyle' ) : null;
		return null !== $ps ? trim( $ps->getAttributeNS( self::NS_W, 'val' ) ) : '';
	}

	/**
	 * The first direct child element with the given local name, or null.
	 */
	private function child( \DOMElement $el, string $local ): ?\DOMElement {
		foreach ( $el->childNodes as $n ) {
			if ( $n instanceof \DOMElement && $local === $n->localName ) {
				return $n;
			}
		}
		return null;
	}

	/**
	 * Whether a boolean toggle element (e.g. <w:b/>) is on: present-but-no-value
	 * means on; an explicit 0/false/off means off.
	 */
	private function toggle_on( \DOMElement $n ): bool {
		$val = $n->getAttributeNS( self::NS_W, 'val' );
		if ( '' === $val ) {
			return true;
		}
		return ! in_array( strtolower( $val ), [ '0', 'false', 'off' ], true );
	}

	/**
	 * Append a buffered list block to the output, if any.
	 */
	private function flush_list( array $blocks, ?array $list_buffer ): array {
		if ( null !== $list_buffer && ! empty( $list_buffer['items'] ) ) {
			$blocks[] = $list_buffer;
		}
		return $blocks;
	}

	/**
	 * Turn a non-list paragraph into a heading / quote / separator / paragraph
	 * block, or null if it is empty and not a separator.
	 */
	private function paragraph_block( \DOMElement $p, ?\DOMElement $ppr, array $runs ): ?array {
		$style = $this->pstyle( $ppr );
		$text  = trim( $this->runs_text( $runs ) );

		// A separator paragraph: only scene-break glyphs (e.g. "* * *", "#").
		if ( '' !== $text && preg_match( '/^[\s*#~·•—–\-]{1,40}$/u', $text ) && ! preg_match( '/[\p{L}\p{N}]/u', $text ) ) {
			return [ 'type' => 'separator' ];
		}

		if ( '' === $text ) {
			return null; // Drop empty paragraphs (Word emits many).
		}

		$direct = $this->ppr_direct( $ppr );

		$level = $this->heading_level( $style );
		if ( $level > 0 ) {
			return [
				'type'   => 'heading',
				'level'  => $level,
				'style'  => $style,
				'direct' => $direct,
				'runs'   => $runs,
			];
		}

		if ( $this->is_quote_style( $style ) ) {
			return [
				'type'   => 'quote',
				'style'  => $style,
				'direct' => $direct,
				'runs'   => $runs,
			];
		}

		return [
			'type'   => 'paragraph',
			'style'  => $style,
			'direct' => $direct,
			'runs'   => $runs,
		];
	}

	/**
	 * Heading level (HTML hN) for a Word paragraph style, or 0 if not a heading.
	 * Word "Heading 1" maps to <h2>, since <h1> is the chapter title; "Title"
	 * is treated as a heading too (and becomes the chapter title downstream).
	 */
	private function heading_level( string $style ): int {
		$key = strtolower( str_replace( ' ', '', $style ) );
		if ( 'title' === $key ) {
			return 2;
		}
		if ( preg_match( '/^heading([1-6])$/', $key, $m ) ) {
			return min( 6, (int) $m[1] + 1 );
		}
		return 0;
	}

	private function is_quote_style( string $style ): bool {
		$key = strtolower( str_replace( ' ', '', $style ) );
		return in_array( $key, [ 'quote', 'intensequote', 'blockquote' ], true );
	}

	/**
	 * Use the first heading/title block as the chapter title and remove it from
	 * the body, so the title is not repeated under the post heading.
	 */
	private function extract_title( array $blocks ): array {
		foreach ( $blocks as $i => $block ) {
			if ( 'heading' === $block['type'] ) {
				$this->title = trim( $this->runs_text( $block['runs'] ) );
				unset( $blocks[ $i ] );
				return array_values( $blocks );
			}
			// Only a leading heading counts as the title; stop at real content.
			if ( in_array( $block['type'], [ 'paragraph', 'quote', 'list' ], true ) ) {
				break;
			}
		}
		return $blocks;
	}

	/**
	 * Count images and embedded objects in a paragraph (we skip them, but warn).
	 */
	private function count_media( \DOMElement $p ): void {
		foreach ( [ 'drawing', 'pict', 'object' ] as $tag ) {
			$this->image_count += $p->getElementsByTagNameNS( self::NS_W, $tag )->length;
		}
	}

	/**
	 * Extract inline runs from a paragraph, descending into hyperlinks so their
	 * runs carry the link target.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function parse_runs( \DOMElement $p ): array {
		$runs = [];
		foreach ( $p->childNodes as $child ) {
			if ( ! $child instanceof \DOMElement ) {
				continue;
			}
			if ( 'hyperlink' === $child->localName ) {
				$rid  = $child->getAttributeNS( self::NS_R, 'id' );
				$href = $this->relationships[ $rid ] ?? '';
				foreach ( $child->childNodes as $r ) {
					if ( ! $r instanceof \DOMElement || 'r' !== $r->localName ) {
						continue;
					}
					$run = $this->parse_run( $r );
					if ( '' !== $run['text'] ) {
						$run['href'] = $href;
						$runs[]      = $run;
					}
				}
			} elseif ( 'r' === $child->localName ) {
				$run = $this->parse_run( $child );
				if ( '' !== $run['text'] ) {
					$runs[] = $run;
				}
			}
		}
		return $runs;
	}

	/**
	 * Parse a single run: its text and bold/italic/underline marks + char style.
	 * Walks the run and its w:rPr once (this is the document's hot path — tens of
	 * thousands of runs — so it avoids per-property XPath queries).
	 *
	 * @return array<string,mixed>
	 */
	private function parse_run( \DOMElement $r ): array {
		$text = '';
		$rpr  = null;
		foreach ( $r->childNodes as $node ) {
			if ( ! $node instanceof \DOMElement ) {
				continue;
			}
			switch ( $node->localName ) {
				case 't':
					$text .= $node->textContent;
					break;
				case 'tab':
					$text .= ' ';
					break;
				case 'br':
				case 'cr':
					$text .= "\n";
					break;
				case 'rPr':
					$rpr = $node;
					break;
			}
		}

		$props = $this->rpr_props( $rpr );
		return [
			'text'      => $text,
			'bold'      => $props['bold'],
			'italic'    => $props['italic'],
			'underline' => $props['underline'],
			'href'      => '',
			'style'     => $props['style'],
			'direct'    => $props['direct'],
		];
	}

	/**
	 * Read a run's w:rPr in a single pass: the bold/italic/underline marks, its
	 * character-style id, and the direct (ad-hoc) character formatting.
	 *
	 * @return array{bold:bool,italic:bool,underline:bool,style:string,direct:array<string,string>}
	 */
	private function rpr_props( ?\DOMElement $rpr ): array {
		$out = [
			'bold'      => false,
			'italic'    => false,
			'underline' => false,
			'style'     => '',
			'direct'    => [],
		];
		if ( null === $rpr ) {
			return $out;
		}

		$direct    = [];
		$highlight = '';
		$shade     = '';
		foreach ( $rpr->childNodes as $n ) {
			if ( ! $n instanceof \DOMElement ) {
				continue;
			}
			switch ( $n->localName ) {
				case 'b':
					$out['bold'] = $this->toggle_on( $n );
					break;
				case 'i':
					$out['italic'] = $this->toggle_on( $n );
					break;
				case 'u':
					$v                = $n->getAttributeNS( self::NS_W, 'val' );
					$out['underline'] = '' !== $v && 'none' !== $v;
					break;
				case 'rStyle':
					$out['style'] = trim( $n->getAttributeNS( self::NS_W, 'val' ) );
					break;
				case 'rFonts':
					$font = $n->getAttributeNS( self::NS_W, 'ascii' );
					if ( '' === $font ) {
						$font = $n->getAttributeNS( self::NS_W, 'hAnsi' );
					}
					if ( '' !== $font ) {
						$direct['font-family'] = $font;
					}
					break;
				case 'sz':
					$sz = $n->getAttributeNS( self::NS_W, 'val' );
					if ( is_numeric( $sz ) ) {
						$direct['font-size'] = rtrim( rtrim( number_format( (float) $sz / 2, 1 ), '0' ), '.' ) . 'pt';
					}
					break;
				case 'color':
					$color = $n->getAttributeNS( self::NS_W, 'val' );
					if ( '' !== $color && 'auto' !== $color && preg_match( '/^[0-9A-Fa-f]{6}$/', $color ) ) {
						$direct['color'] = '#' . strtolower( $color );
					}
					break;
				case 'highlight':
					$h = $n->getAttributeNS( self::NS_W, 'val' );
					if ( '' !== $h && 'none' !== $h ) {
						$highlight = $h; // A named colour (e.g. "yellow").
					}
					break;
				case 'shd':
					$shade = $n->getAttributeNS( self::NS_W, 'fill' );
					break;
			}
		}

		// Highlight wins over shading, matching the original precedence.
		if ( '' !== $highlight ) {
			$direct['background-color'] = $highlight;
		} elseif ( '' !== $shade && 'auto' !== $shade && preg_match( '/^[0-9A-Fa-f]{6}$/', $shade ) ) {
			$direct['background-color'] = '#' . strtolower( $shade );
		}

		$out['direct'] = $direct;
		return $out;
	}

	/**
	 * Direct (ad-hoc) character formatting on a run or style element — font,
	 * size, colour and highlight applied inline rather than through a named
	 * character style. These are what an "unnamed styles" import clusters and
	 * maps. Bold/italic/underline are left out: they are emphasis, handled
	 * separately. Delegates to rpr_props (a single w:rPr pass).
	 *
	 * @return array<string,string> CSS-style props (empty when the run is plain).
	 */
	private function parse_direct( \DOMElement $el ): array {
		return $this->rpr_props( $this->child( $el, 'rPr' ) )['direct'];
	}

	/**
	 * Direct (ad-hoc) paragraph formatting from a paragraph or style element's
	 * w:pPr — alignment, indentation and spacing. The block-level counterpart to
	 * parse_direct: the basis for clustering "unnamed" paragraph styles on import.
	 *
	 * @return array<string,string> CSS-style props (empty when the paragraph is plain).
	 */
	private function parse_direct_paragraph( \DOMElement $el ): array {
		return $this->ppr_direct( $this->child( $el, 'pPr' ) );
	}

	/**
	 * Read a w:pPr's alignment/indentation/spacing into CSS props in one pass.
	 * Word measures are twips (1/20 point).
	 *
	 * @return array<string,string>
	 */
	private function ppr_direct( ?\DOMElement $ppr ): array {
		$out = [];
		if ( null === $ppr ) {
			return $out;
		}

		foreach ( $ppr->childNodes as $n ) {
			if ( ! $n instanceof \DOMElement ) {
				continue;
			}
			switch ( $n->localName ) {
				case 'jc':
					// Word "both" is full justification; "start"/"end" are the
					// writing-direction-relative forms of left/right.
					$jc  = $n->getAttributeNS( self::NS_W, 'val' );
					$map = [ 'both' => 'justify', 'center' => 'center', 'right' => 'right', 'end' => 'right', 'left' => 'left', 'start' => 'left' ];
					if ( isset( $map[ $jc ] ) ) {
						$out['text-align'] = $map[ $jc ];
					}
					break;
				case 'ind':
					// left/start -> margin-left, right/end -> margin-right. A
					// hanging indent is margin-left plus a negative text-indent;
					// firstLine is a positive text-indent.
					$left = $n->getAttributeNS( self::NS_W, 'left' );
					if ( '' === $left ) {
						$left = $n->getAttributeNS( self::NS_W, 'start' );
					}
					if ( is_numeric( $left ) ) {
						$out['margin-left'] = $this->twips_pt( $left );
					}
					$right = $n->getAttributeNS( self::NS_W, 'right' );
					if ( '' === $right ) {
						$right = $n->getAttributeNS( self::NS_W, 'end' );
					}
					if ( is_numeric( $right ) ) {
						$out['margin-right'] = $this->twips_pt( $right );
					}
					$hanging = $n->getAttributeNS( self::NS_W, 'hanging' );
					$first   = $n->getAttributeNS( self::NS_W, 'firstLine' );
					if ( is_numeric( $hanging ) && 0.0 !== (float) $hanging ) {
						$out['text-indent'] = '-' . $this->twips_pt( $hanging );
					} elseif ( is_numeric( $first ) && 0.0 !== (float) $first ) {
						$out['text-indent'] = $this->twips_pt( $first );
					}
					break;
				case 'spacing':
					// before/after -> margin-top/bottom (kept even at 0, since "0"
					// is a deliberate tight setting). line -> line-height: "auto"
					// mode is 240ths of a line (a unitless multiplier);
					// exact/atLeast store twips.
					$before = $n->getAttributeNS( self::NS_W, 'before' );
					if ( is_numeric( $before ) ) {
						$out['margin-top'] = $this->twips_pt( $before );
					}
					$after = $n->getAttributeNS( self::NS_W, 'after' );
					if ( is_numeric( $after ) ) {
						$out['margin-bottom'] = $this->twips_pt( $after );
					}
					$line = $n->getAttributeNS( self::NS_W, 'line' );
					$rule = $n->getAttributeNS( self::NS_W, 'lineRule' );
					if ( is_numeric( $line ) && 0.0 !== (float) $line ) {
						if ( 'exact' === $rule || 'atLeast' === $rule ) {
							$out['line-height'] = $this->twips_pt( $line );
						} else {
							$out['line-height'] = rtrim( rtrim( number_format( (float) $line / 240, 2 ), '0' ), '.' );
						}
					}
					break;
			}
		}

		return $out;
	}

	/**
	 * Convert a twips measurement (1/20 point) to a CSS "pt" string, trimming
	 * trailing zeros: "720" -> "36pt", "360" -> "18pt", "210" -> "10.5pt".
	 */
	private function twips_pt( string $twips ): string {
		return rtrim( rtrim( number_format( (float) $twips / 20, 1 ), '0' ), '.' ) . 'pt';
	}

	/**
	 * Concatenate the text of a set of runs.
	 *
	 * @param array<int,array<string,mixed>> $runs
	 */
	private function runs_text( array $runs ): string {
		$text = '';
		foreach ( $runs as $run ) {
			$text .= $run['text'];
		}
		return $text;
	}

	/**
	 * First string value of an XPath expression relative to a context node.
	 */
	private function attr( \DOMXPath $xpath, \DOMNode $context, string $query ): string {
		$node = $xpath->query( $query, $context )->item( 0 );
		return $node ? trim( (string) $node->nodeValue ) : '';
	}
}
