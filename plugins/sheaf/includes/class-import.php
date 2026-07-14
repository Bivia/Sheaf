<?php
/**
 * The "Import chapters" screen: upload Word files, preview, create drafts.
 *
 * Most chapters are written outside WordPress, so this imports .docx Word
 * files — one file per chapter. The flow is two-step: upload (with cleaning
 * settings and a target book) parses each file into the IR (Docx_Reader) and
 * stashes it in a per-user transient; the preview step lets the author fix
 * detected titles and adjust settings before creating draft chapters
 * (Import_Serializer → block markup). Drafts append to the end of the book's
 * reading order, ready to edit and publish.
 *
 * @package Sheaf
 */

namespace Sheaf;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Import {

	private const CAPABILITY  = 'edit_posts';
	private const PAGE        = 'sheaf-import';
	private const NONCE_UP    = 'sheaf_import_upload';
	private const NONCE_CREATE = 'sheaf_import_create';
	private const TRANSIENT   = 'sheaf_import_';
	private const TTL         = HOUR_IN_SECONDS;
	private const MAX_BYTES   = 26214400; // 25 MB per file.

	public static function register(): void {
		// Priority 11 so this lands after Books_Admin's submenus (New Chapter).
		add_action( 'admin_menu', [ self::class, 'add_page' ], 11 );
		add_action( 'admin_post_' . self::NONCE_UP, [ self::class, 'handle_upload' ] );
		add_action( 'admin_post_' . self::NONCE_CREATE, [ self::class, 'handle_create' ] );

		// Keep the Sheafs menu highlighted on our screen, and add an "Import"
		// button to the core chapter list + a post-import success notice.
		add_filter( 'submenu_file', [ self::class, 'highlight_submenu' ] );
		add_action( 'admin_head-edit.php', [ self::class, 'listing_button' ] );
		add_action( 'admin_notices', [ self::class, 'imported_notice' ] );
	}

	/**
	 * URL of the import screen, optionally pre-selecting a book.
	 */
	public static function url( int $book_id = 0 ): string {
		$args = [ 'page' => self::PAGE ];
		if ( $book_id ) {
			$args['sheaf_book'] = $book_id;
		}
		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}

	public static function add_page(): void {
		add_submenu_page(
			Books_Admin::MENU_SLUG,
			__( 'Import Chapters', 'sheaf' ),
			__( 'Import Chapters', 'sheaf' ),
			self::CAPABILITY,
			self::PAGE,
			[ self::class, 'render' ]
		);
	}

	/**
	 * Highlight the Import submenu while on the import screen.
	 */
	public static function highlight_submenu( ?string $submenu_file ): ?string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only check.
		if ( isset( $_GET['page'] ) && self::PAGE === $_GET['page'] ) {
			return self::PAGE;
		}
		return $submenu_file;
	}

	/**
	 * Add an "Import chapters" button beside "Add New" on the chapter list.
	 */
	public static function listing_button(): void {
		if ( Chapters::POST_TYPE !== ( $GLOBALS['typenow'] ?? '' ) ) {
			return;
		}
		printf(
			'<script>document.addEventListener("DOMContentLoaded",function(){var a=document.querySelector(".wrap a.page-title-action");if(!a){return;}var i=document.createElement("a");i.href=%s;i.className="page-title-action";i.textContent=%s;a.insertAdjacentElement("afterend",i);});</script>',
			wp_json_encode( self::url() ),
			wp_json_encode( __( 'Import chapters', 'sheaf' ) )
		);
	}

	/**
	 * Show how many chapters were imported, back on the chapter list.
	 */
	public static function imported_notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only notice.
		$count = isset( $_GET['sheaf_imported'] ) ? absint( $_GET['sheaf_imported'] ) : 0;
		if ( ! $count || Chapters::POST_TYPE !== ( $GLOBALS['typenow'] ?? '' ) ) {
			return;
		}
		printf(
			'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
			esc_html(
				sprintf(
					/* translators: %s: number of chapters. */
					_n( '%s chapter imported as a draft.', '%s chapters imported as drafts.', $count, 'sheaf' ),
					number_format_i18n( $count )
				)
			)
		);
	}

	/**
	 * Screen router: upload form, or the preview of a parsed upload.
	 */
	public static function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to import chapters.', 'sheaf' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation.
		$token = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : '';
		$data  = $token ? self::load( $token ) : null;

		echo '<div class="wrap">';
		echo '<h1 class="wp-heading-inline">' . esc_html__( 'Import chapters', 'sheaf' ) . '</h1>';
		echo '<hr class="wp-header-end">';

		self::render_errors();

		if ( $data ) {
			self::render_preview( $token, $data );
		} else {
			self::render_upload_form();
		}
		echo '</div>';
	}

	private static function render_errors(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only notice.
		$error = isset( $_GET['sheaf_error'] ) ? sanitize_key( $_GET['sheaf_error'] ) : '';
		if ( ! $error ) {
			return;
		}
		$messages = [
			'nofiles' => __( 'No readable .docx files were uploaded. Please choose one or more Word files.', 'sheaf' ),
			'expired' => __( 'That import session has expired. Please upload the files again.', 'sheaf' ),
		];
		$message = $messages[ $error ] ?? __( 'Something went wrong with the import.', 'sheaf' );
		printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html( $message ) );
	}

	private static function render_upload_form(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only pre-fill.
		$book = isset( $_GET['sheaf_book'] ) ? absint( $_GET['sheaf_book'] ) : 0;

		echo '<p class="description">' . esc_html__( 'Upload one or more Word (.docx) files. Each file becomes a draft chapter — or, for a whole book in one file, split it into chapters at the breaks you choose. Word formatting is cleaned up on import; you can fix titles and order on the next screen.', 'sheaf' ) . '</p>';

		echo '<form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::NONCE_UP );
		printf( '<input type="hidden" name="action" value="%s">', esc_attr( self::NONCE_UP ) );

		echo '<table class="form-table" role="presentation"><tbody>';

		// Target book.
		echo '<tr><th scope="row"><label for="sheaf-import-book">' . esc_html__( 'Add to book', 'sheaf' ) . '</label></th><td>';
		self::book_select( $book );
		echo '<p class="description">' . esc_html__( 'Imported chapters are assigned to this book and appended to the end of its reading order. You can change this per chapter later.', 'sheaf' ) . '</p>';
		echo '</td></tr>';

		// Files.
		echo '<tr><th scope="row"><label for="sheaf-import-files">' . esc_html__( 'Word files', 'sheaf' ) . '</label></th><td>';
		echo '<input type="file" id="sheaf-import-files" name="sheaf_files[]" accept=".docx" multiple required>';
		echo '<p class="description">' . esc_html__( 'Select multiple files to import several chapters at once.', 'sheaf' ) . '</p>';
		echo '</td></tr>';

		// Split mode: one chapter per file, or split a whole-book file.
		echo '<tr><th scope="row">' . esc_html__( 'Chapters', 'sheaf' ) . '</th><td>';
		echo '<fieldset>';
		echo '<label><input type="radio" name="sheaf_mode" value="single" checked> ' . esc_html__( 'Each file is one chapter', 'sheaf' ) . '</label><br>';
		echo '<label><input type="radio" name="sheaf_mode" value="split"> ' . esc_html__( 'Split each file into chapters (a whole book in one file)', 'sheaf' ) . '</label>';
		echo '<div id="sheaf-split-signals" style="margin:.6em 0 0 1.8em;display:none">';
		echo '<p class="description" style="margin:.2em 0 .4em">' . esc_html__( 'Start a new chapter at each break you select:', 'sheaf' ) . '</p>';
		$signal_labels = [
			'page'     => __( 'Page break', 'sheaf' ),
			'section'  => __( 'Section break (Word)', 'sheaf' ),
			'heading1' => __( 'Heading 1', 'sheaf' ),
			'heading2' => __( 'Heading 2', 'sheaf' ),
			'heading3' => __( 'Heading 3', 'sheaf' ),
			'symbols'  => __( 'A line of symbols only (e.g. •••, * * *)', 'sheaf' ),
			'blanks'   => __( 'Three or more blank lines', 'sheaf' ),
		];
		foreach ( $signal_labels as $key => $label ) {
			printf(
				'<label style="display:block;margin:.15em 0"><input type="checkbox" name="sheaf_split[]" value="%1$s"%2$s> %3$s</label>',
				esc_attr( $key ),
				checked( 'page' === $key, true, false ),
				esc_html( $label )
			);
		}
		echo '<p class="description" style="margin:.4em 0 0">' . esc_html__( 'Consecutive breaks (say a page break then a heading) count as one. Anything before the first break becomes the first chapter, which you can delete.', 'sheaf' ) . '</p>';
		echo '</div>';
		echo '</fieldset>';
		echo '</td></tr>';

		// Cleaning settings.
		echo '<tr><th scope="row">' . esc_html__( 'Keep formatting', 'sheaf' ) . '</th><td>';
		self::settings_fields( Import_Serializer::default_settings() );
		echo '</td></tr>';

		echo '</tbody></table>';
		?>
		<script>
		( function () {
			var box = document.getElementById( 'sheaf-split-signals' );
			function sync() {
				var m = document.querySelector( 'input[name="sheaf_mode"]:checked' );
				if ( box ) { box.style.display = ( m && 'split' === m.value ) ? '' : 'none'; }
			}
			document.querySelectorAll( 'input[name="sheaf_mode"]' ).forEach( function ( r ) {
				r.addEventListener( 'change', sync );
			} );
			sync();
		} )();
		</script>
		<?php

		submit_button( __( 'Upload and preview', 'sheaf' ) );
		echo '</form>';
	}

	/**
	 * The "Add to book" selector: a books-only dropdown (plus "unassigned") with
	 * a "Show all pages" toggle that swaps in the full page list — mirroring the
	 * Book selector on the chapter editor.
	 */
	private static function book_select( int $selected ): void {
		$book_ids = Books::all_book_ids();
		if ( $selected && ! in_array( $selected, $book_ids, true ) ) {
			$book_ids[] = $selected;
		}

		// Books-only selector (the default).
		echo '<select name="sheaf_book" id="sheaf-import-book">';
		printf( '<option value="0">%s</option>', esc_html__( '— Unassigned —', 'sheaf' ) );
		foreach ( $book_ids as $bid ) {
			printf(
				'<option value="%1$d"%2$s>%3$s</option>',
				(int) $bid,
				selected( $selected, (int) $bid, false ),
				esc_html( get_the_title( (int) $bid ) )
			);
		}
		echo '</select>';

		// The full page list, hidden and disabled until "show all pages" is
		// ticked. Disabled controls aren't submitted, so only one value is sent.
		$all = (string) wp_dropdown_pages(
			[
				'name'              => 'sheaf_book',
				'id'                => 'sheaf-import-book-all',
				'selected'          => $selected,
				'show_option_none'  => __( '— Unassigned —', 'sheaf' ),
				'option_none_value' => 0,
				'echo'              => 0,
			]
		);
		$all = preg_replace( '/<select /', '<select disabled ', $all, 1 );
		echo ' <span id="sheaf-import-book-all-wrap" style="display:none">' . $all . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_dropdown_pages output.

		printf(
			'<p><label><input type="checkbox" id="sheaf-import-book-allpages"> %s</label></p>',
			esc_html__( 'Show all pages', 'sheaf' )
		);
		echo '<p class="description" id="sheaf-import-book-allpages-note" style="display:none">'
			. esc_html__( 'Adding a Chapter to a Page turns that Page into a Book.', 'sheaf' )
			. '</p>';
		?>
		<script>
		( function () {
			var cb    = document.getElementById( 'sheaf-import-book-allpages' );
			var books = document.getElementById( 'sheaf-import-book' );
			var wrap  = document.getElementById( 'sheaf-import-book-all-wrap' );
			var all   = document.getElementById( 'sheaf-import-book-all' );
			var note  = document.getElementById( 'sheaf-import-book-allpages-note' );
			if ( ! cb || ! books || ! all ) { return; }
			cb.addEventListener( 'change', function () {
				if ( cb.checked ) {
					all.value = books.value;
					books.disabled = true;  books.style.display = 'none';
					all.disabled = false;   wrap.style.display = '';
					note.style.display = '';
				} else {
					var match = Array.prototype.some.call( books.options, function ( o ) { return o.value === all.value; } );
					books.value = match ? all.value : '0';
					all.disabled = true;    wrap.style.display = 'none';
					books.disabled = false; books.style.display = '';
					note.style.display = 'none';
				}
			} );
		} )();
		</script>
		<?php
	}

	/**
	 * The "keep formatting" checkboxes, reflecting the current settings.
	 *
	 * @param array<string,mixed> $settings
	 */
	private static function settings_fields( array $settings ): void {
		$fields = [
			'keep_headings'   => __( 'Headings', 'sheaf' ),
			'keep_emphasis'   => __( 'Bold / italic / underline', 'sheaf' ),
			'keep_lists'      => __( 'Lists', 'sheaf' ),
			'keep_blockquote' => __( 'Block quotes', 'sheaf' ),
			'keep_links'        => __( 'Links', 'sheaf' ),
			'scene_breaks'      => __( 'Scene breaks (e.g. “* * *”) as separators', 'sheaf' ),
			'keep_named_styles'   => __( 'Named custom styles and formatting', 'sheaf' ),
			'keep_unnamed_styles' => __( 'Ad hoc/unnamed custom styles and formatting', 'sheaf' ),
		];
		echo '<fieldset>';
		foreach ( $fields as $key => $label ) {
			printf(
				'<label style="display:block;margin:.2em 0"><input type="checkbox" name="settings[%1$s]" value="1"%2$s> %3$s</label>',
				esc_attr( $key ),
				checked( ! empty( $settings[ $key ] ), true, false ),
				esc_html( $label )
			);
		}
		echo '<p class="description">' . esc_html__( 'Anything not kept is converted to plain paragraphs. Images are not imported.', 'sheaf' ) . '</p>';
		echo '</fieldset>';
	}

	/**
	 * Read settings from a submitted form, including the Word-style mappings
	 * (validated against the target book's active style-set styles).
	 *
	 * @return array<string,mixed>
	 */
	private static function settings_from_request( int $book, array $entries = [] ): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce checked by the caller.
		$raw = isset( $_POST['settings'] ) && is_array( $_POST['settings'] )
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			? array_map( 'sanitize_text_field', wp_unslash( $_POST['settings'] ) )
			: [];
		$settings = Import_Serializer::sanitize_settings( $raw );

		// The author's per-Word-style choices: "" (ignore), an existing style's
		// class, or "new:<set>" (create one). Kept so the dropdowns remember the
		// selection; the existing-class ones become the preview maps.
		$options                     = self::style_options( $book );
		$settings['char_choices']    = self::read_choices( 'char_map', $options, 'inline' );
		$settings['para_choices']    = self::read_choices( 'para_map', $options, 'block' );
		$settings['style_map']       = self::existing_class_map( $settings['char_choices'] );
		$settings['block_style_map'] = self::existing_class_map( $settings['para_choices'] );

		// Direct (unnamed) formatting choices, keyed by cluster id; the existing-
		// class ones resolve to a signature => class preview map. Runs map to
		// inline styles; whole paragraphs map to block styles.
		$run_direct  = self::read_direct_choices( self::collect_direct( $entries ), $options, 'direct_map', 'inline' );
		$para_direct = self::read_direct_choices( self::collect_direct_paragraphs( $entries ), $options, 'direct_para_map', 'block' );
		$settings['direct_choices']      = $run_direct['choices'];
		$settings['direct_style_map']    = $run_direct['map'];
		$settings['direct_para_choices'] = $para_direct['choices'];
		$settings['direct_block_map']    = $para_direct['map'];

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce checked by the caller.
		$settings['new_set'] = isset( $_POST['new_set'] ) ? sanitize_text_field( wp_unslash( $_POST['new_set'] ) ) : '';

		return $settings;
	}

	/**
	 * Read the direct-formatting choices for one cluster set from the request,
	 * keyed by cluster id, validated against the book's active styles. Returns
	 * both the raw choices (to redisplay) and the existing-class map (signature
	 * => class) the serializer applies now. $kind selects which styles are valid
	 * targets: 'inline' for run clusters, 'block' for paragraph clusters.
	 *
	 * @param array<string,array<string,mixed>> $clusters collect_direct* output.
	 * @param array<string,mixed>               $options  style_options() output.
	 * @param string                            $field    POST field name.
	 * @param string                            $kind     'inline' or 'block'.
	 * @return array{choices:array<string,string>,map:array<string,string>}
	 */
	private static function read_direct_choices( array $clusters, array $options, string $field, string $kind ): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce checked by the caller.
		$raw = isset( $_POST[ $field ] ) && is_array( $_POST[ $field ] )
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			? (array) wp_unslash( $_POST[ $field ] )
			: [];

		$classes = [];
		foreach ( (array) ( $options[ $kind ] ?? [] ) as $opt ) {
			$classes[ $opt['class'] ] = true;
		}
		$sets = [];
		foreach ( (array) ( $options['sets'] ?? [] ) as $set ) {
			$sets[ $set['slug'] ] = true;
		}

		$choices = [];
		$map     = [];
		foreach ( $clusters as $id => $cluster ) {
			$choice = (string) ( $raw[ $id ] ?? '' );
			if ( 0 === strpos( $choice, 'new:' ) ) {
				$set = sanitize_key( substr( $choice, 4 ) );
				if ( isset( $sets[ $set ] ) ) {
					$choices[ $id ] = 'new:' . $set;
				}
			} else {
				$class = sanitize_html_class( $choice );
				if ( '' !== $class && isset( $classes[ $class ] ) ) {
					$choices[ $id ]                     = $class;
					$map[ $cluster['signature'] ] = $class;
				}
			}
		}
		return [
			'choices' => $choices,
			'map'     => $map,
		];
	}

	/**
	 * Read a Word-style => choice map from the request, validated against the
	 * book's active styles. A choice is "" (ignore), an existing style's class,
	 * or "new:<set-slug>" for a set the book actually activates.
	 *
	 * @param array<string,mixed> $options style_options() output.
	 * @return array<string,string>
	 */
	private static function read_choices( string $field, array $options, string $kind ): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce checked by the caller.
		$raw = isset( $_POST[ $field ] ) && is_array( $_POST[ $field ] )
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			? (array) wp_unslash( $_POST[ $field ] )
			: [];

		$classes = [];
		foreach ( (array) ( $options[ $kind ] ?? [] ) as $opt ) {
			$classes[ $opt['class'] ] = true;
		}
		$sets = [];
		foreach ( (array) ( $options['sets'] ?? [] ) as $set ) {
			$sets[ $set['slug'] ] = true;
		}

		$out = [];
		foreach ( $raw as $word => $choice ) {
			$choice = (string) $choice;
			if ( 0 === strpos( $choice, 'new:' ) ) {
				$set = sanitize_key( substr( $choice, 4 ) );
				if ( isset( $sets[ $set ] ) ) {
					$out[ (string) $word ] = 'new:' . $set;
				}
			} else {
				$class = sanitize_html_class( $choice );
				if ( '' !== $class && isset( $classes[ $class ] ) ) {
					$out[ (string) $word ] = $class;
				}
			}
		}
		return $out;
	}

	/**
	 * The subset of choices that are existing classes (ready to apply now). The
	 * "new:<set>" choices are resolved later, at create time.
	 *
	 * @param array<string,string> $choices
	 * @return array<string,string>
	 */
	private static function existing_class_map( array $choices ): array {
		$map = [];
		foreach ( $choices as $word => $choice ) {
			if ( '' !== $choice && 0 !== strpos( $choice, 'new:' ) ) {
				$map[ $word ] = $choice;
			}
		}
		return $map;
	}

	/**
	 * The style-set styles a book activates, split by kind, as mapping options:
	 * inline styles (for Word character styles) and block styles (for Word
	 * paragraph styles). Each option carries the CSS class the content will
	 * receive plus labels for the dropdown.
	 *
	 * @return array{inline:array<int,array<string,string>>,block:array<int,array<string,string>>}
	 */
	private static function style_options( int $book ): array {
		$out = [
			'inline' => [],
			'block'  => [],
			'sets'   => [],
		];
		foreach ( Style_Sets::active_sets( $book ) as $set ) {
			$set_data = Style_Sets::get_set( $set );
			if ( ! $set_data ) {
				continue;
			}
			$set_label    = '' !== (string) ( $set_data['label'] ?? '' ) ? (string) $set_data['label'] : (string) $set;
			$out['sets'][] = [
				'slug'  => (string) $set,
				'label' => $set_label,
			];
			foreach ( (array) ( $set_data['styles'] ?? [] ) as $style => $def ) {
				$kind  = in_array( $def['kind'] ?? 'inline', Style_Sets::KINDS, true ) ? (string) $def['kind'] : 'inline';
				$label = '' !== (string) ( $def['label'] ?? '' ) ? (string) $def['label'] : (string) $style;
				$out[ 'block' === $kind ? 'block' : 'inline' ][] = [
					'class'    => Style_Sets::css_class( (string) $set, (string) $style, $kind ),
					'label'    => $label,
					'set'      => $set_label,
					'set_slug' => (string) $set,
				];
			}
		}
		return $out;
	}

	/**
	 * Distinct Word styles used across the parsed entries, with occurrence
	 * counts: character styles (on runs) and paragraph styles (on plain
	 * paragraphs). Structural styles already consumed as headings/quotes are
	 * not offered.
	 *
	 * @param array<int,array<string,mixed>> $entries
	 * @return array{char:array<string,int>,para:array<string,int>}
	 */
	private static function collect_styles( array $entries ): array {
		$char = [];
		$para = [];
		foreach ( $entries as $entry ) {
			if ( '' !== (string) ( $entry['error'] ?? '' ) ) {
				continue;
			}
			foreach ( (array) ( $entry['blocks'] ?? [] ) as $block ) {
				if ( 'paragraph' === ( $block['type'] ?? '' ) && '' !== (string) ( $block['style'] ?? '' ) ) {
					$name          = (string) $block['style'];
					$para[ $name ] = ( $para[ $name ] ?? 0 ) + 1;
				}

				$run_groups = [];
				if ( isset( $block['runs'] ) ) {
					$run_groups[] = $block['runs'];
				}
				if ( isset( $block['items'] ) ) {
					foreach ( $block['items'] as $item ) {
						$run_groups[] = $item;
					}
				}
				foreach ( $run_groups as $runs ) {
					foreach ( (array) $runs as $run ) {
						$s = (string) ( $run['style'] ?? '' );
						if ( '' !== $s ) {
							$char[ $s ] = ( $char[ $s ] ?? 0 ) + 1;
						}
					}
				}
			}
		}
		ksort( $char );
		ksort( $para );
		return [
			'char' => $char,
			'para' => $para,
		];
	}

	/**
	 * Merge the named-style definitions (styleId => {name, type, props}) across all
	 * parsed entries, so the import can give a created style its human label, font
	 * and layout. Earlier entries win on conflict (definitions are normally
	 * identical across a batch). Errored entries are skipped.
	 *
	 * @param array<int,array<string,mixed>> $entries
	 * @return array<string,array<string,mixed>>
	 */
	private static function style_definitions( array $entries ): array {
		$defs = [];
		foreach ( $entries as $entry ) {
			if ( '' !== (string) ( $entry['error'] ?? '' ) ) {
				continue;
			}
			foreach ( (array) ( $entry['styles'] ?? [] ) as $id => $def ) {
				if ( is_array( $def ) && ! isset( $defs[ (string) $id ] ) ) {
					$defs[ (string) $id ] = $def;
				}
			}
		}
		return $defs;
	}

	/**
	 * Resolve a Word style's definition into a label, CSS props (with web-font
	 * substitution applied) and the catalog family to embed — ready to create a
	 * style-set style that carries the Word style's font and layout. Falls back to
	 * the styleId as the label when the style has no human name.
	 *
	 * @param array<string,array<string,mixed>> $defs style_definitions() output.
	 * @return array{0:string,1:array<string,string>,2:string} [ label, props, embed-family ]
	 */
	private static function style_from_definition( array $defs, string $word ): array {
		$def   = (array) ( $defs[ $word ] ?? [] );
		$label = '' !== (string) ( $def['name'] ?? '' ) ? (string) $def['name'] : $word;
		list( $props, $embed ) = self::apply_font_substitution( (array) ( $def['props'] ?? [] ) );
		return [ $label, $props, $embed ];
	}

	/**
	 * Cluster ad-hoc/unnamed (direct) run formatting across the parsed entries.
	 * Runs that carry direct formatting but no named character style are grouped
	 * by their canonical signature; each cluster keeps a stable id, the props, an
	 * occurrence count and a sample of the affected text. Ordered by count.
	 *
	 * @param array<int,array<string,mixed>> $entries
	 * @return array<string,array<string,mixed>> id => cluster
	 */
	private static function collect_direct( array $entries ): array {
		$clusters = [];
		foreach ( $entries as $entry ) {
			if ( '' !== (string) ( $entry['error'] ?? '' ) ) {
				continue;
			}
			foreach ( (array) ( $entry['blocks'] ?? [] ) as $block ) {
				$groups = [];
				if ( isset( $block['runs'] ) ) {
					$groups[] = $block['runs'];
				}
				if ( isset( $block['items'] ) ) {
					foreach ( $block['items'] as $item ) {
						$groups[] = $item;
					}
				}
				foreach ( $groups as $runs ) {
					foreach ( (array) $runs as $run ) {
						$direct = (array) ( $run['direct'] ?? [] );
						// Only pure direct formatting — named styles are handled elsewhere.
						if ( ! $direct || '' !== (string) ( $run['style'] ?? '' ) ) {
							continue;
						}
						$signature = Import_Serializer::direct_signature( $direct );
						if ( '' === $signature ) {
							continue;
						}
						$id = substr( md5( $signature ), 0, 12 );
						if ( ! isset( $clusters[ $id ] ) ) {
							$clusters[ $id ] = [
								'id'        => $id,
								'signature' => $signature,
								'props'     => $direct,
								'count'     => 0,
								'sample'    => '',
							];
						}
						++$clusters[ $id ]['count'];
						$text = trim( (string) $run['text'] );
						if ( '' === $clusters[ $id ]['sample'] && '' !== $text ) {
							$clusters[ $id ]['sample'] = mb_substr( $text, 0, 60 );
						}
					}
				}
			}
		}

		uasort(
			$clusters,
			static function ( $a, $b ) {
				return $b['count'] <=> $a['count'];
			}
		);
		return $clusters;
	}

	/**
	 * Cluster ad-hoc/unnamed (direct) *paragraph* formatting across the parsed
	 * entries — the block-level counterpart to collect_direct(). Plain paragraphs
	 * (no named paragraph style) that carry direct alignment/indent/spacing are
	 * grouped by signature; each cluster keeps a stable id, the props, a count and
	 * a text sample. This is what an academic bibliography's hanging-indent layout
	 * clusters into. Ordered by count.
	 *
	 * @param array<int,array<string,mixed>> $entries
	 * @return array<string,array<string,mixed>> id => cluster
	 */
	private static function collect_direct_paragraphs( array $entries ): array {
		$clusters = [];
		foreach ( $entries as $entry ) {
			if ( '' !== (string) ( $entry['error'] ?? '' ) ) {
				continue;
			}
			foreach ( (array) ( $entry['blocks'] ?? [] ) as $block ) {
				// Only plain paragraphs — named styles are handled elsewhere, and
				// headings/quotes are structural.
				if ( 'paragraph' !== ( $block['type'] ?? '' ) || '' !== (string) ( $block['style'] ?? '' ) ) {
					continue;
				}
				$direct = (array) ( $block['direct'] ?? [] );
				if ( ! $direct ) {
					continue;
				}
				$signature = Import_Serializer::direct_signature( $direct );
				if ( '' === $signature ) {
					continue;
				}
				$id = substr( md5( $signature ), 0, 12 );
				if ( ! isset( $clusters[ $id ] ) ) {
					$clusters[ $id ] = [
						'id'        => $id,
						'signature' => $signature,
						'props'     => $direct,
						'count'     => 0,
						'sample'    => '',
					];
				}
				++$clusters[ $id ]['count'];
				$text = trim( Import_Serializer::to_text( [ $block ] ) );
				if ( '' === $clusters[ $id ]['sample'] && '' !== $text ) {
					$clusters[ $id ]['sample'] = mb_substr( $text, 0, 60 );
				}
			}
		}

		uasort(
			$clusters,
			static function ( $a, $b ) {
				return $b['count'] <=> $a['count'];
			}
		);
		return $clusters;
	}

	/**
	 * A human-readable description of a direct-formatting cluster's props, used as
	 * a label and as the name when the cluster becomes a new style. Character
	 * formatting (font/size/colour) reads as bare values ("Courier New, 10pt");
	 * paragraph formatting (alignment/indent/spacing) gets a short label per
	 * value ("align justify, left 36pt, indent -18pt"), since the values alone
	 * would be cryptic.
	 *
	 * @param array<string,string> $props
	 */
	private static function describe_direct( array $props ): string {
		$order       = [ 'font-family', 'font-size', 'font-weight', 'font-style', 'color', 'background-color' ];
		$para_labels = [
			'text-align'    => __( 'align', 'sheaf' ),
			'margin-left'   => __( 'left', 'sheaf' ),
			'margin-right'  => __( 'right', 'sheaf' ),
			'text-indent'   => __( 'indent', 'sheaf' ),
			'margin-top'    => __( 'space above', 'sheaf' ),
			'margin-bottom' => __( 'space below', 'sheaf' ),
			'line-height'   => __( 'line height', 'sheaf' ),
		];
		$parts = [];
		foreach ( $order as $key ) {
			if ( ! empty( $props[ $key ] ) ) {
				$parts[] = (string) $props[ $key ];
			}
		}
		foreach ( $props as $key => $value ) {
			if ( in_array( $key, $order, true ) || '' === (string) $value ) {
				continue;
			}
			$parts[] = isset( $para_labels[ $key ] ) ? $para_labels[ $key ] . ' ' . $value : (string) $value;
		}
		return $parts ? implode( ', ', $parts ) : __( 'Plain text', 'sheaf' );
	}

	/**
	 * Handle the upload: parse each .docx to IR, stash, redirect to preview.
	 */
	public static function handle_upload(): void {
		check_admin_referer( self::NONCE_UP );
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to import chapters.', 'sheaf' ) );
		}

		// A whole-book file can be large: a couple of seconds and a few hundred MB
		// to parse. Give the request room so a big upload doesn't time out or hit
		// the memory ceiling.
		wp_raise_memory_limit( 'admin' );
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 120 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- best-effort; may be disabled.
		}

		$book     = isset( $_POST['sheaf_book'] ) ? absint( $_POST['sheaf_book'] ) : 0;
		$settings = self::settings_from_request( $book );
		$files    = self::normalize_files();
		list( $split, $signals ) = self::split_request();

		$entries = [];
		foreach ( $files as $file ) {
			foreach ( self::read_file( $file, $split, $signals ) as $entry ) {
				$entries[] = $entry;
			}
		}

		if ( ! $entries ) {
			wp_safe_redirect( add_query_arg( 'sheaf_error', 'nofiles', self::url( $book ) ) );
			exit;
		}

		$token = wp_generate_password( 24, false );
		self::store(
			$token,
			[
				'user'     => get_current_user_id(),
				'book'     => $book,
				'settings' => $settings,
				'entries'  => $entries,
			]
		);

		wp_safe_redirect( add_query_arg( 'token', $token, self::url( $book ) ) );
		exit;
	}

	/**
	 * Normalize the PHP multi-file upload array into a list of single files.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function normalize_files(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce checked in handle_upload.
		if ( empty( $_FILES['sheaf_files'] ) || ! is_array( $_FILES['sheaf_files']['name'] ) ) {
			return [];
		}
		$raw   = $_FILES['sheaf_files']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- field-by-field below.
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		$files = [];
		$count = count( $raw['name'] );
		for ( $i = 0; $i < $count; $i++ ) {
			if ( (int) $raw['error'][ $i ] !== UPLOAD_ERR_OK ) {
				continue;
			}
			$files[] = [
				'name' => sanitize_file_name( (string) $raw['name'][ $i ] ),
				'tmp'  => (string) $raw['tmp_name'][ $i ],
				'size' => (int) $raw['size'][ $i ],
			];
		}
		return $files;
	}

	/**
	 * The whole-book split request: whether to split, and which signals to split
	 * on. Split is only on when the mode is "split" and at least one signal is
	 * chosen (otherwise it falls back to one chapter per file).
	 *
	 * @return array{0:bool,1:array<string,bool>}
	 */
	private static function split_request(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce checked in handle_upload.
		$mode = isset( $_POST['sheaf_mode'] ) ? sanitize_key( wp_unslash( $_POST['sheaf_mode'] ) ) : 'single';
		if ( 'split' !== $mode ) {
			return [ false, [] ];
		}
		$signals = [];
		if ( isset( $_POST['sheaf_split'] ) && is_array( $_POST['sheaf_split'] ) ) {
			foreach ( wp_unslash( $_POST['sheaf_split'] ) as $s ) {
				$s = sanitize_key( (string) $s );
				if ( in_array( $s, Book_Splitter::SIGNALS, true ) ) {
					$signals[ $s ] = true;
				}
			}
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		return [ ! empty( $signals ), $signals ];
	}

	/**
	 * Validate and parse one uploaded file into preview entries. Normally one
	 * entry per file; in split mode the file's chapters (Book_Splitter) each
	 * become their own entry.
	 *
	 * @param array<string,mixed> $file
	 * @param array<string,bool>  $signals
	 * @return array<int,array<string,mixed>> One or more entries (never empty).
	 */
	private static function read_file( array $file, bool $split = false, array $signals = [] ): array {
		$name = (string) $file['name'];
		$ext  = strtolower( (string) pathinfo( $name, PATHINFO_EXTENSION ) );

		$base = [
			'name'   => $name,
			'title'  => self::title_from_filename( $name ),
			'blocks' => [],
			'styles' => [],
			'images' => 0,
			'tables' => 0,
			'error'  => '',
		];

		if ( 'docx' !== $ext ) {
			$base['error'] = __( 'Not a .docx Word file — skipped.', 'sheaf' );
			return [ $base ];
		}
		if ( $file['size'] > self::MAX_BYTES || ! is_uploaded_file( (string) $file['tmp'] ) ) {
			$base['error'] = __( 'File is too large or could not be read — skipped.', 'sheaf' );
			return [ $base ];
		}

		try {
			// In split mode keep the leading heading (each chapter finds its own
			// title); otherwise let the reader promote it to the file's title.
			$ir = Docx_Reader::read( (string) $file['tmp'], ! $split );
		} catch ( \Throwable $e ) {
			$base['error'] = $e->getMessage();
			return [ $base ];
		}

		$styles = (array) ( $ir['styles'] ?? [] );

		if ( ! $split ) {
			$entry           = $base;
			$entry['blocks'] = $ir['blocks'];
			$entry['styles'] = $styles;
			$entry['images'] = (int) $ir['images'];
			$entry['tables'] = (int) $ir['tables'];
			if ( '' !== trim( (string) $ir['title'] ) ) {
				$entry['title'] = sanitize_text_field( $ir['title'] );
			}
			return [ $entry ];
		}

		// Split the file into chapters.
		$chapters = Book_Splitter::split( $ir['blocks'], $signals );
		if ( ! $chapters ) {
			$base['error'] = __( 'No chapters were found with the chosen split points.', 'sheaf' );
			return [ $base ];
		}

		$entries = [];
		$n       = 0;
		foreach ( $chapters as $chapter ) {
			++$n;
			$title = trim( (string) ( $chapter['title'] ?? '' ) );
			if ( '' === $title ) {
				$title = sprintf(
					/* translators: 1: source file name, 2: chapter number. */
					__( '%1$s — %2$d', 'sheaf' ),
					self::title_from_filename( $name ),
					$n
				);
			}
			$entries[] = [
				'name'   => $name,
				'title'  => sanitize_text_field( $title ),
				'blocks' => $chapter['blocks'],
				'styles' => $styles,
				'images' => 0,
				'tables' => 0,
				'error'  => '',
			];
		}
		// Attribute the file's skipped-media counts to its first chapter, so the
		// "images skipped" notice still surfaces once.
		$entries[0]['images'] = (int) $ir['images'];
		$entries[0]['tables'] = (int) $ir['tables'];

		return $entries;
	}

	/**
	 * A reasonable chapter title from a filename (sans extension/separators).
	 */
	private static function title_from_filename( string $name ): string {
		$base = pathinfo( $name, PATHINFO_FILENAME );
		$base = str_replace( [ '_', '-' ], ' ', $base );
		$base = trim( (string) preg_replace( '/\s+/', ' ', $base ) );
		return '' === $base ? __( 'Untitled chapter', 'sheaf' ) : $base;
	}

	/**
	 * Render the preview: per-file title, word count, snippet, warnings, plus
	 * the settings to adjust. Two actions: update the preview, or create drafts.
	 *
	 * @param array<string,mixed> $data
	 */
	private static function render_preview( string $token, array $data ): void {
		$settings   = Import_Serializer::sanitize_settings( (array) $data['settings'] );
		$book       = (int) $data['book'];
		$entries    = (array) $data['entries'];
		$importable = 0;

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::NONCE_CREATE );
		printf( '<input type="hidden" name="action" value="%s">', esc_attr( self::NONCE_CREATE ) );
		printf( '<input type="hidden" name="token" value="%s">', esc_attr( $token ) );

		$book_label = $book ? get_the_title( $book ) : __( 'Unassigned', 'sheaf' );
		printf(
			'<p class="description">%s</p>',
			esc_html(
				sprintf(
					/* translators: %s: book title. */
					__( 'Review the chapters below, then create them as drafts in: %s', 'sheaf' ),
					$book_label
				)
			)
		);

		echo '<table class="wp-list-table widefat fixed striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Title', 'sheaf' ) . '</th>';
		echo '<th style="width:6em">' . esc_html__( 'Words', 'sheaf' ) . '</th>';
		echo '<th>' . esc_html__( 'Preview', 'sheaf' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $entries as $i => $entry ) {
			if ( '' !== $entry['error'] ) {
				printf(
					'<tr><td><strong>%1$s</strong></td><td>—</td><td><span class="description" style="color:#b32d2e">%2$s</span></td></tr>',
					esc_html( $entry['name'] ),
					esc_html( $entry['error'] )
				);
				continue;
			}
			++$importable;

			$content = Import_Serializer::to_blocks( $entry['blocks'], $settings );
			$words   = Words::count_in( $content );
			$snippet = Import_Serializer::to_text( $entry['blocks'], 40 );

			echo '<tr><td>';
			printf(
				'<input type="text" class="large-text" name="titles[%1$d]" value="%2$s">',
				(int) $i,
				esc_attr( $entry['title'] )
			);
			printf( '<div class="row-actions"><span>%s</span></div>', esc_html( $entry['name'] ) );
			echo '</td>';
			printf( '<td>%s</td>', esc_html( number_format_i18n( $words ) ) );
			echo '<td>';
			printf( '<span class="description">%s</span>', esc_html( $snippet ) );
			foreach ( self::warnings( $entry ) as $warning ) {
				printf( '<br><span class="description" style="color:#996800">%s</span>', esc_html( $warning ) );
			}
			echo '</td></tr>';
		}

		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'Keep formatting', 'sheaf' ) . '</h2>';
		self::settings_fields( $settings );

		// Pass the raw settings (sanitize_settings drops the choice fields the
		// mapping UI needs to remember the author's selections).
		self::render_style_mapping( $book, $entries, (array) $data['settings'] );

		echo '<p class="submit">';
		printf(
			'<button type="submit" name="sheaf_action" value="preview" class="button">%s</button> ',
			esc_html__( 'Update preview', 'sheaf' )
		);
		if ( $importable > 0 ) {
			printf(
				'<button type="submit" name="sheaf_action" value="create" class="button button-primary">%s</button> ',
				esc_html(
					sprintf(
						/* translators: %s: number of chapters. */
						_n( 'Create %s draft', 'Create %s drafts', $importable, 'sheaf' ),
						number_format_i18n( $importable )
					)
				)
			);
		}
		printf(
			'<a href="%s" class="button-link">%s</a>',
			esc_url( self::url( $book ) ),
			esc_html__( 'Start over', 'sheaf' )
		);
		echo '</p>';
		echo '</form>';
	}

	/**
	 * Human-readable warnings for an entry (e.g. dropped images/tables).
	 *
	 * @param array<string,mixed> $entry
	 * @return string[]
	 */
	private static function warnings( array $entry ): array {
		$out = [];
		if ( $entry['images'] > 0 ) {
			$out[] = sprintf(
				/* translators: %s: number of images. */
				_n( '%s image skipped (not imported).', '%s images skipped (not imported).', $entry['images'], 'sheaf' ),
				number_format_i18n( $entry['images'] )
			);
		}
		if ( $entry['tables'] > 0 ) {
			$out[] = sprintf(
				/* translators: %s: number of tables. */
				_n( '%s table skipped.', '%s tables skipped.', $entry['tables'], 'sheaf' ),
				number_format_i18n( $entry['tables'] )
			);
		}
		return $out;
	}

	/**
	 * The "Word styles" section of the preview: map each named Word style found
	 * in the uploaded files to one of the target book's active style-set styles
	 * (or leave it ignored). Character styles map to inline styles, paragraph
	 * styles to block styles.
	 *
	 * @param array<int,array<string,mixed>> $entries
	 * @param array<string,mixed>            $settings
	 */
	private static function render_style_mapping( int $book, array $entries, array $settings ): void {
		$want_named  = ! empty( $settings['keep_named_styles'] );
		$want_direct = ! empty( $settings['keep_unnamed_styles'] );
		if ( ! $want_named && ! $want_direct ) {
			return; // Both mappings are opt-in (the "Keep formatting" checkboxes).
		}

		$detected      = $want_named ? self::collect_styles( $entries ) : [ 'char' => [], 'para' => [] ];
		$clusters      = $want_direct ? self::collect_direct( $entries ) : [];
		$para_clusters = $want_direct ? self::collect_direct_paragraphs( $entries ) : [];
		$has_named  = $detected['char'] || $detected['para'];
		$has_direct = ! empty( $clusters ) || ! empty( $para_clusters );
		if ( ! $has_named && ! $has_direct ) {
			return;
		}

		$options = self::style_options( $book );

		// No active sets: offer to create one from everything found (C2).
		if ( ! $options['sets'] ) {
			echo '<h2>' . esc_html__( 'Custom styles', 'sheaf' ) . '</h2>';
			self::render_create_set_from_found( $detected, $clusters, $para_clusters, $settings );
			return;
		}

		if ( $has_named ) {
			echo '<h2>' . esc_html__( 'Word styles', 'sheaf' ) . '</h2>';
			echo '<p class="description">' . esc_html__( 'Map named Word styles found in these files to your style-set styles. You can add a found style to a set as a new style, map it to an existing one, or ignore it (imported as plain text).', 'sheaf' ) . '</p>';

			echo '<table class="wp-list-table widefat fixed striped"><thead><tr>';
			echo '<th>' . esc_html__( 'Word style', 'sheaf' ) . '</th>';
			echo '<th style="width:7em">' . esc_html__( 'Uses', 'sheaf' ) . '</th>';
			echo '<th>' . esc_html__( 'Maps to', 'sheaf' ) . '</th>';
			echo '</tr></thead><tbody>';

			$defs = self::style_definitions( $entries );
			self::mapping_rows( __( 'Character styles', 'sheaf' ), $detected['char'], 'char_map', $options, 'inline', (array) ( $settings['char_choices'] ?? [] ), $defs );
			self::mapping_rows( __( 'Paragraph styles', 'sheaf' ), $detected['para'], 'para_map', $options, 'block', (array) ( $settings['para_choices'] ?? [] ), $defs );

			echo '</tbody></table>';
		}

		if ( $clusters ) {
			self::render_direct_mapping(
				$clusters,
				$options,
				(array) ( $settings['direct_choices'] ?? [] ),
				'inline',
				'direct_map',
				'run',
				__( 'Unnamed styles', 'sheaf' ),
				__( 'Ad-hoc formatting found in these files (font, size or colour applied without a Word style). Map each to a style-set style, or ignore it. Use the bulk control to apply one choice to several at once.', 'sheaf' )
			);
		}

		if ( $para_clusters ) {
			self::render_direct_mapping(
				$para_clusters,
				$options,
				(array) ( $settings['direct_para_choices'] ?? [] ),
				'block',
				'direct_para_map',
				'para',
				__( 'Unnamed paragraph styles', 'sheaf' ),
				__( 'Whole-paragraph formatting found without a Word style (alignment, indentation or spacing — e.g. an academic bibliography’s hanging indent). Map each to a paragraph style-set style, or ignore it.', 'sheaf' )
			);
		}
	}

	/**
	 * One sub-group of the Word-style mapping table. Each found style can map to
	 * "— Ignore —", a new style in one of the book's sets ("new:<set>"), or an
	 * existing style (its class), grouped by set.
	 *
	 * @param array<string,int>                 $detected Word styleId => uses.
	 * @param array<string,mixed>               $options  style_options() output.
	 * @param array<string,string>              $choices  Word styleId => current choice.
	 * @param array<string,array<string,mixed>> $defs     style_definitions() output.
	 */
	private static function mapping_rows( string $heading, array $detected, string $field, array $options, string $kind, array $choices, array $defs = [] ): void {
		if ( ! $detected ) {
			return;
		}
		printf( '<tr><th colspan="3" scope="rowgroup">%s</th></tr>', esc_html( $heading ) );

		foreach ( $detected as $name => $count ) {
			$selected = (string) ( $choices[ (string) $name ] ?? '' );
			// Prefer the style's human name (w:name) over its styleId for display.
			$def_name = (string) ( $defs[ (string) $name ]['name'] ?? '' );
			$display  = '' !== $def_name ? $def_name : (string) $name;

			echo '<tr>';
			printf( '<td><code>%s</code></td>', esc_html( $display ) );
			printf( '<td>%s</td>', esc_html( number_format_i18n( $count ) ) );
			echo '<td>';
			self::style_select( $field . '[' . (string) $name . ']', $options, $kind, $selected, $display );
			echo '</td></tr>';
		}
	}

	/**
	 * A "Maps to" <select>: "— Ignore —", a new style in each set ("new:<slug>",
	 * labelled with $new_label), and the existing styles of each set + kind.
	 *
	 * @param array<string,mixed> $options style_options() output.
	 */
	private static function style_select( string $name, array $options, string $kind, string $selected, string $new_label, string $class = '' ): void {
		printf(
			'<select%1$s%2$s>',
			'' !== $name ? ' name="' . esc_attr( $name ) . '"' : '',
			'' !== $class ? ' class="' . esc_attr( $class ) . '"' : ''
		);
		printf( '<option value=""%1$s>%2$s</option>', selected( $selected, '', false ), esc_html__( '— Ignore —', 'sheaf' ) );

		foreach ( $options['sets'] as $set ) {
			$new_val = 'new:' . $set['slug'];
			printf(
				'<option value="%1$s"%2$s>%3$s</option>',
				esc_attr( $new_val ),
				selected( $selected, $new_val, false ),
				esc_html(
					sprintf(
						/* translators: 1: set name, 2: style label. */
						__( '%1$s › %2$s [new style]', 'sheaf' ),
						$set['label'],
						$new_label
					)
				)
			);
			foreach ( $options[ $kind ] as $opt ) {
				if ( ( $opt['set_slug'] ?? '' ) !== $set['slug'] ) {
					continue;
				}
				printf(
					'<option value="%1$s"%2$s>%3$s</option>',
					esc_attr( $opt['class'] ),
					selected( $selected, $opt['class'], false ),
					esc_html( $set['label'] . ' › ' . $opt['label'] )
				);
			}
		}
		echo '</select>';
	}

	/**
	 * An "Unnamed (paragraph) styles" section: each direct-formatting cluster with
	 * a sample, a description, occurrence count, and a "Maps to" select. A bulk
	 * control applies one choice to all selected rows at once (client-side). Used
	 * for both run clusters (inline) and paragraph clusters (block); $slug scopes
	 * the bulk control so the two tables don't drive each other.
	 *
	 * @param array<string,array<string,mixed>> $clusters
	 * @param array<string,mixed>               $options  style_options() output.
	 * @param array<string,string>              $choices  cluster id => choice.
	 * @param string                            $kind     'inline' or 'block'.
	 * @param string                            $field    POST field name.
	 * @param string                            $slug     unique slug for scoping.
	 * @param string                            $heading  section heading.
	 * @param string                            $desc     section description.
	 */
	private static function render_direct_mapping( array $clusters, array $options, array $choices, string $kind, string $field, string $slug, string $heading, string $desc ): void {
		$wrap     = 'sheaf-direct-' . $slug;
		$is_block = 'block' === $kind;

		echo '<div class="sheaf-direct-section" id="' . esc_attr( $wrap ) . '">';
		echo '<h2>' . esc_html( $heading ) . '</h2>';
		echo '<p class="description">' . esc_html( $desc ) . '</p>';

		echo '<p class="sheaf-direct-bulk">';
		echo '<label><input type="checkbox" class="sheaf-direct-all"> ' . esc_html__( 'Select all', 'sheaf' ) . '</label> ';
		self::style_select( '', $options, $kind, '', __( 'selected styles', 'sheaf' ), 'sheaf-direct-bulk-select' );
		echo ' <button type="button" class="button sheaf-direct-apply">' . esc_html__( 'Apply to selected', 'sheaf' ) . '</button>';
		echo '</p>';

		echo '<table class="wp-list-table widefat fixed striped"><thead><tr>';
		echo '<th style="width:2em"></th>';
		echo '<th>' . esc_html__( 'Sample', 'sheaf' ) . '</th>';
		echo '<th>' . esc_html__( 'Formatting', 'sheaf' ) . '</th>';
		echo '<th style="width:6em">' . esc_html__( 'Uses', 'sheaf' ) . '</th>';
		echo '<th>' . esc_html__( 'Maps to', 'sheaf' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $clusters as $id => $cluster ) {
			$selected = (string) ( $choices[ $id ] ?? '' );
			$decl     = Style_Sets::declarations( [ 'props' => $cluster['props'] ] );
			// A block sample needs a block element for alignment/indent to show.
			$tag = $is_block ? 'div' : 'span';
			echo '<tr>';
			printf( '<td><input type="checkbox" class="sheaf-direct-cb" value="%s"></td>', esc_attr( $id ) );
			printf( '<td><%1$s style="%2$s">%3$s</%1$s></td>', $tag, esc_attr( $decl ), esc_html( '' !== $cluster['sample'] ? $cluster['sample'] : '—' ) );
			$font = Fonts::primary_family( (string) ( $cluster['props']['font-family'] ?? '' ) );
			$note = '';
			if ( '' !== $font ) {
				$sub = Fonts::substitute( $font );
				if ( '' !== $sub ) {
					/* translators: 1: Word font, 2: web-font equivalent. */
					$note = sprintf( __( 'font %1$s → %2$s (embedded)', 'sheaf' ), $font, $sub );
				} elseif ( Fonts::in_catalog( $font ) ) {
					/* translators: %s: web-font family name. */
					$note = sprintf( __( 'font %s (embedded)', 'sheaf' ), $font );
				}
			}
			printf(
				'<td><code>%1$s</code>%2$s</td>',
				esc_html( self::describe_direct( $cluster['props'] ) ),
				'' !== $note ? '<br><span class="description">' . esc_html( $note ) . '</span>' : ''
			);
			printf( '<td>%s</td>', esc_html( number_format_i18n( $cluster['count'] ) ) );
			echo '<td>';
			self::style_select( $field . '[' . $id . ']', $options, $kind, $selected, self::describe_direct( $cluster['props'] ), 'sheaf-direct-select' );
			echo '</td></tr>';
		}

		echo '</tbody></table>';
		echo '</div>';
		self::direct_bulk_script( $wrap );
	}

	/**
	 * Client-side bulk control for an Unnamed-styles table: "select all" and
	 * "apply to selected" (copy the bulk choice into each ticked row's select),
	 * scoped to the section with id $wrap so multiple tables stay independent.
	 */
	private static function direct_bulk_script( string $wrap ): void {
		?>
		<script>
		( function () {
			var root = document.getElementById( <?php echo wp_json_encode( $wrap ); ?> );
			if ( ! root ) { return; }
			var all = root.querySelector( '.sheaf-direct-all' );
			var apply = root.querySelector( '.sheaf-direct-apply' );
			var bulk = root.querySelector( '.sheaf-direct-bulk-select' );
			function boxes() { return root.querySelectorAll( '.sheaf-direct-cb' ); }
			if ( all ) {
				all.addEventListener( 'change', function () {
					boxes().forEach( function ( box ) { box.checked = all.checked; } );
				} );
			}
			if ( apply && bulk ) {
				apply.addEventListener( 'click', function () {
					boxes().forEach( function ( box ) {
						if ( ! box.checked ) { return; }
						var row = box.closest( 'tr' );
						var select = row ? row.querySelector( '.sheaf-direct-select' ) : null;
						if ( select ) { select.value = bulk.value; }
					} );
				} );
			}
		} )();
		</script>
		<?php
	}

	/**
	 * When the target book has no style sets but the files carry named styles,
	 * offer to create a set containing all of them (C2). Leaving the name blank
	 * skips the styles (imported as plain text).
	 *
	 * @param array{char:array<string,int>,para:array<string,int>} $detected
	 * @param array<string,array<string,mixed>>                    $clusters      run clusters.
	 * @param array<string,array<string,mixed>>                    $para_clusters paragraph clusters.
	 * @param array<string,mixed>                                  $settings
	 */
	private static function render_create_set_from_found( array $detected, array $clusters, array $para_clusters, array $settings ): void {
		$total = count( $detected['char'] ) + count( $detected['para'] ) + count( $clusters ) + count( $para_clusters );

		echo '<p class="description">' . esc_html(
			sprintf(
				/* translators: %s: number of custom styles. */
				_n(
					'%s custom style was found, but this book has no style sets. Name a new set to create from it:',
					'%s custom styles were found, but this book has no style sets. Name a new set to create from them:',
					$total,
					'sheaf'
				),
				number_format_i18n( $total )
			)
		) . '</p>';

		printf(
			'<p><label>%1$s <input type="text" name="new_set" value="%2$s" class="regular-text" placeholder="%3$s"></label></p>',
			esc_html__( 'New style set name', 'sheaf' ),
			esc_attr( (string) ( $settings['new_set'] ?? '' ) ),
			esc_attr__( 'e.g. Strange Voices', 'sheaf' )
		);

		echo '<ul style="margin-left:1.4em;list-style:disc">';
		foreach ( array_keys( $detected['char'] ) as $word ) {
			printf( '<li><code>%s</code> — %s</li>', esc_html( (string) $word ), esc_html__( 'inline style', 'sheaf' ) );
		}
		foreach ( array_keys( $detected['para'] ) as $word ) {
			printf( '<li><code>%s</code> — %s</li>', esc_html( (string) $word ), esc_html__( 'paragraph style', 'sheaf' ) );
		}
		foreach ( $clusters as $cluster ) {
			printf( '<li><code>%s</code> — %s</li>', esc_html( self::describe_direct( $cluster['props'] ) ), esc_html__( 'unnamed formatting', 'sheaf' ) );
		}
		foreach ( $para_clusters as $cluster ) {
			printf( '<li><code>%s</code> — %s</li>', esc_html( self::describe_direct( $cluster['props'] ) ), esc_html__( 'unnamed paragraph formatting', 'sheaf' ) );
		}
		echo '</ul>';
		echo '<p class="description">' . esc_html__( 'Leave the name blank to import these as plain text.', 'sheaf' ) . '</p>';
	}

	/**
	 * Handle the preview form: update settings/titles, or create the drafts.
	 */
	public static function handle_create(): void {
		check_admin_referer( self::NONCE_CREATE );
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to import chapters.', 'sheaf' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above.
		$token = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '';
		$data  = $token ? self::load( $token ) : null;
		if ( ! $data ) {
			wp_safe_redirect( add_query_arg( 'sheaf_error', 'expired', self::url() ) );
			exit;
		}

		// Fold the submitted settings and edited titles back into the session.
		$data['settings'] = self::settings_from_request( (int) $data['book'], (array) $data['entries'] );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above.
		$titles = isset( $_POST['titles'] ) && is_array( $_POST['titles'] )
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			? array_map( 'sanitize_text_field', wp_unslash( $_POST['titles'] ) )
			: [];
		foreach ( $data['entries'] as $i => $entry ) {
			if ( isset( $titles[ $i ] ) && '' !== trim( $titles[ $i ] ) ) {
				$data['entries'][ $i ]['title'] = $titles[ $i ];
			}
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above.
		$action = isset( $_POST['sheaf_action'] ) ? sanitize_key( $_POST['sheaf_action'] ) : 'preview';

		if ( 'create' !== $action ) {
			self::store( $token, $data );
			wp_safe_redirect( add_query_arg( 'token', $token, self::url( (int) $data['book'] ) ) );
			exit;
		}

		// Create any new style sets / styles the author chose, then import.
		$data    = self::resolve_style_choices( $data );
		$created = self::create_drafts( $data );
		self::forget( $token );

		$book = (int) $data['book'];
		if ( $book ) {
			// Land on the book's reading-order screen so the author can slot the
			// new drafts into place straight away.
			$redirect = add_query_arg(
				[
					'post_type'      => Chapters::POST_TYPE,
					'page'           => Books_Admin::MENU_SLUG,
					'book'           => $book,
					'sheaf_imported' => $created,
				],
				admin_url( 'edit.php' )
			);
		} else {
			// Unassigned imports have no book page; fall back to the chapter list.
			$redirect = add_query_arg(
				[
					'post_type'      => Chapters::POST_TYPE,
					'sheaf_imported' => $created,
				],
				admin_url( 'edit.php' )
			);
		}
		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Turn the author's style choices into real styles just before importing:
	 *  - a "new set" name (C2) creates a set from every found style and activates
	 *    it on the book;
	 *  - "new:<set>" choices (C3) create a style named after the Word style.
	 * The resulting classes are folded into the style maps the serializer uses.
	 *
	 * @param array<string,mixed> $data
	 * @return array<string,mixed>
	 */
	private static function resolve_style_choices( array $data ): array {
		$settings    = (array) $data['settings'];
		$want_named  = ! empty( $settings['keep_named_styles'] );
		$want_direct = ! empty( $settings['keep_unnamed_styles'] );
		if ( ! $want_named && ! $want_direct ) {
			return $data;
		}

		$book          = (int) $data['book'];
		$detected      = $want_named ? self::collect_styles( (array) $data['entries'] ) : [ 'char' => [], 'para' => [] ];
		$defs          = $want_named ? self::style_definitions( (array) $data['entries'] ) : [];
		$clusters      = $want_direct ? self::collect_direct( (array) $data['entries'] ) : [];
		$para_clusters = $want_direct ? self::collect_direct_paragraphs( (array) $data['entries'] ) : [];
		$style_map        = (array) ( $settings['style_map'] ?? [] );
		$block_map        = (array) ( $settings['block_style_map'] ?? [] );
		$direct_map       = (array) ( $settings['direct_style_map'] ?? [] );
		$direct_block_map = (array) ( $settings['direct_block_map'] ?? [] );

		// C2: build a whole new set from everything found (only when there are no
		// sets to choose from in the first place).
		$new_set_name = trim( (string) ( $settings['new_set'] ?? '' ) );
		if ( '' !== $new_set_name && ! Style_Sets::active_sets( $book ) ) {
			$set = Style_Sets::save_set( $new_set_name );
			foreach ( array_keys( $detected['char'] ) as $word ) {
				// Carry the Word style's own font/size/colour and human label.
				list( $label, $props, $embed ) = self::style_from_definition( $defs, (string) $word );
				if ( '' !== $embed ) {
					Fonts::install_from_catalog( $embed );
				}
				$style                       = Style_Sets::save_style( $set, [ 'label' => $label, 'kind' => 'inline', 'props' => $props ] );
				$style_map[ (string) $word ] = Style_Sets::style_class( $set, $style );
			}
			foreach ( array_keys( $detected['para'] ) as $word ) {
				// Carry the Word style's layout (and any font) and human label.
				list( $label, $props, $embed ) = self::style_from_definition( $defs, (string) $word );
				if ( '' !== $embed ) {
					Fonts::install_from_catalog( $embed );
				}
				$style                       = Style_Sets::save_style( $set, [ 'label' => $label, 'kind' => 'block', 'props' => $props ] );
				$block_map[ (string) $word ] = Style_Sets::css_class( $set, $style, 'block' );
			}
			foreach ( $clusters as $cluster ) {
				// Direct clusters carry their actual props (with web-font substitution),
				// so the new style works at once.
				$label                 = self::describe_direct( $cluster['props'] );
				list( $props, $embed ) = self::apply_font_substitution( $cluster['props'] );
				if ( '' !== $embed ) {
					Fonts::install_from_catalog( $embed );
				}
				$style                               = Style_Sets::save_style( $set, [ 'label' => $label, 'kind' => 'inline', 'props' => $props ] );
				$direct_map[ $cluster['signature'] ] = Style_Sets::style_class( $set, $style );
			}
			foreach ( $para_clusters as $cluster ) {
				// Paragraph clusters become block styles carrying their layout props
				// (alignment/indent/spacing). No font substitution — they carry none.
				$label                                     = self::describe_direct( $cluster['props'] );
				$style                                     = Style_Sets::save_style( $set, [ 'label' => $label, 'kind' => 'block', 'props' => $cluster['props'] ] );
				$direct_block_map[ $cluster['signature'] ] = Style_Sets::css_class( $set, $style, 'block' );
			}
			if ( $book ) {
				$active   = Style_Sets::active_sets( $book );
				$active[] = $set;
				update_post_meta( $book, Style_Sets::BOOK_META, array_values( array_unique( $active ) ) );
			}
		}

		// C3: resolve "new:<set>" choices into freshly-created styles.
		$style_map        = array_merge( $style_map, self::create_new_styles( (array) ( $settings['char_choices'] ?? [] ), 'inline', $book, $defs ) );
		$block_map        = array_merge( $block_map, self::create_new_styles( (array) ( $settings['para_choices'] ?? [] ), 'block', $book, $defs ) );
		$direct_map       = array_merge( $direct_map, self::create_new_direct( $clusters, (array) ( $settings['direct_choices'] ?? [] ), $book, 'inline' ) );
		$direct_block_map = array_merge( $direct_block_map, self::create_new_direct( $para_clusters, (array) ( $settings['direct_para_choices'] ?? [] ), $book, 'block' ) );

		$settings['style_map']        = $style_map;
		$settings['block_style_map']  = $block_map;
		$settings['direct_style_map'] = $direct_map;
		$settings['direct_block_map'] = $direct_block_map;
		$data['settings']             = $settings;
		return $data;
	}

	/**
	 * Create a style for every "new:<set>" direct-cluster choice, named by its
	 * formatting and carrying its props, and return a signature => class map.
	 * $kind is 'inline' for run clusters or 'block' for paragraph clusters; the
	 * font substitution is a no-op for paragraph clusters (they carry no font).
	 *
	 * @param array<string,array<string,mixed>> $clusters
	 * @param array<string,string>              $choices  cluster id => choice.
	 * @return array<string,string>
	 */
	private static function create_new_direct( array $clusters, array $choices, int $book, string $kind = 'inline' ): array {
		$active = Style_Sets::active_sets( $book );
		$map    = [];
		foreach ( $clusters as $id => $cluster ) {
			$choice = (string) ( $choices[ $id ] ?? '' );
			if ( 0 !== strpos( $choice, 'new:' ) ) {
				continue;
			}
			$set = sanitize_key( substr( $choice, 4 ) );
			if ( '' === $set || ! in_array( $set, $active, true ) ) {
				continue;
			}
			$label              = self::describe_direct( $cluster['props'] );
			list( $props, $embed ) = self::apply_font_substitution( $cluster['props'] );
			if ( '' !== $embed ) {
				Fonts::install_from_catalog( $embed );
			}
			$style = Style_Sets::save_style( $set, [ 'label' => $label, 'kind' => $kind, 'props' => $props ] );
			if ( '' === $style ) {
				continue;
			}
			$map[ $cluster['signature'] ] = 'block' === $kind
				? Style_Sets::css_class( $set, $style, 'block' )
				: Style_Sets::style_class( $set, $style );
		}
		return $map;
	}

	/**
	 * Apply web-font substitution to a direct cluster's props: swap a Word/system
	 * font for its free equivalent, and report the catalog family to embed (the
	 * substitute, or the original font if it is itself a catalog family).
	 *
	 * @param array<string,string> $props
	 * @return array{0:array<string,string>,1:string} [ props, embed-family ]
	 */
	private static function apply_font_substitution( array $props ): array {
		$value = (string) ( $props['font-family'] ?? '' );
		if ( '' === $value ) {
			return [ $props, '' ];
		}
		$primary    = Fonts::primary_family( $value );
		$substitute = Fonts::substitute( $primary );
		if ( '' !== $substitute ) {
			$props['font-family'] = $substitute;
			return [ $props, $substitute ];
		}
		if ( Fonts::in_catalog( $primary ) ) {
			return [ $props, $primary ];
		}
		return [ $props, '' ];
	}

	/**
	 * Create a style for every "new:<set>" choice, carrying the Word style's own
	 * definition (human label, font/size/colour, and — for paragraph styles —
	 * layout), with web-font substitution applied. Returns a Word-style => class
	 * map. Only sets the book activates are used.
	 *
	 * @param array<string,string>              $choices
	 * @param array<string,array<string,mixed>> $defs    style_definitions() output.
	 * @return array<string,string>
	 */
	private static function create_new_styles( array $choices, string $kind, int $book, array $defs ): array {
		$active = Style_Sets::active_sets( $book );
		$map    = [];
		foreach ( $choices as $word => $choice ) {
			if ( 0 !== strpos( (string) $choice, 'new:' ) ) {
				continue;
			}
			$set = sanitize_key( substr( (string) $choice, 4 ) );
			if ( '' === $set || ! in_array( $set, $active, true ) ) {
				continue;
			}
			list( $label, $props, $embed ) = self::style_from_definition( $defs, (string) $word );
			if ( '' !== $embed ) {
				Fonts::install_from_catalog( $embed );
			}
			$style = Style_Sets::save_style( $set, [ 'label' => $label, 'kind' => $kind, 'props' => $props ] );
			if ( '' === $style ) {
				continue;
			}
			$map[ (string) $word ] = 'block' === $kind
				? Style_Sets::css_class( $set, $style, 'block' )
				: Style_Sets::style_class( $set, $style );
		}
		return $map;
	}

	/**
	 * Create one draft chapter per importable entry, appended to the book.
	 *
	 * @param array<string,mixed> $data
	 * @return int Number of drafts created.
	 */
	private static function create_drafts( array $data ): int {
		$book     = (int) $data['book'];
		$settings = Import_Serializer::sanitize_settings( (array) $data['settings'] );
		$order    = Books::next_menu_order( $book );
		$created  = 0;

		// Scope chapter-slug uniqueness to the target book during insertion.
		Books::set_book_context( $book );

		foreach ( (array) $data['entries'] as $entry ) {
			if ( '' !== $entry['error'] ) {
				continue;
			}

			$postarr = [
				'post_type'    => Chapters::POST_TYPE,
				'post_status'  => 'draft',
				'post_title'   => $entry['title'],
				'post_content' => Import_Serializer::to_blocks( $entry['blocks'], $settings ),
				'menu_order'   => $order,
			];
			if ( $book ) {
				$postarr['meta_input'] = [ Books::BOOK_META => $book ];
			}

			$id = wp_insert_post( $postarr, true );
			if ( ! is_wp_error( $id ) && $id ) {
				++$created;
				++$order;
			}
		}

		Books::set_book_context( 0 );

		return $created;
	}

	/* ---- Per-user transient session storage -------------------------------- */

	private static function store( string $token, array $data ): void {
		set_transient( self::TRANSIENT . $token, $data, self::TTL );
	}

	/**
	 * Load a session, but only for the user who created it.
	 *
	 * @return array<string,mixed>|null
	 */
	private static function load( string $token ): ?array {
		$data = get_transient( self::TRANSIENT . $token );
		if ( ! is_array( $data ) || (int) ( $data['user'] ?? 0 ) !== get_current_user_id() ) {
			return null;
		}
		return $data;
	}

	private static function forget( string $token ): void {
		delete_transient( self::TRANSIENT . $token );
	}
}
