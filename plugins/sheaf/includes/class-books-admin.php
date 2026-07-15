<?php
/**
 * The "Sheafs" admin menu and its "Books" screens.
 *
 * Provides the plugin's top-level menu — Books, Chapters, New Chapter — landing
 * on the Books list. Books are any Page with chapters: the list shows each
 * book's series/context, chapter count and total words; a per-book settings
 * page reorders chapters by drag and drop (jquery-ui-sortable + an AJAX save),
 * with room scaffolded for future per-book settings.
 *
 * @package Sheaf
 */

namespace Sheaf;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Books_Admin {

	/** Top-level menu slug; other Sheaf screens hang their submenus off it. */
	public const MENU_SLUG = 'sheaf-books';

	private const PAGE        = self::MENU_SLUG;
	private const CAPABILITY  = 'edit_posts';
	private const NONCE        = 'sheaf_reorder';
	private const STYLE_NONCE  = 'sheaf_book_style_sets';
	private const SCROLL_NONCE = 'sheaf_scroll_settings';

	/** Hook suffix of our submenu page, for asset scoping. */
	private static string $hook = '';

	public static function register(): void {
		add_action( 'admin_menu', [ self::class, 'add_page' ] );
		add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue' ] );
		add_action( 'wp_ajax_sheaf_reorder', [ self::class, 'ajax_reorder' ] );
		add_action( 'wp_ajax_sheaf_book_style_sets', [ self::class, 'ajax_save_book_sets' ] );
		add_action( 'wp_ajax_sheaf_scroll_settings', [ self::class, 'ajax_save_scroll' ] );

		// Keep the "Sheafs" menu open/highlighted on the (menu-hidden) chapter
		// list and editor screens.
		add_filter( 'parent_file', [ self::class, 'highlight_parent' ] );
		add_filter( 'submenu_file', [ self::class, 'highlight_submenu' ] );
	}

	/**
	 * Register the "Sheafs" top-level menu: Books, Chapters.
	 */
	public static function add_page(): void {
		self::$hook = (string) add_menu_page(
			__( 'Sheafs', 'sheaf' ),
			__( 'Sheafs', 'sheaf' ),
			self::CAPABILITY,
			self::PAGE,
			[ self::class, 'render' ],
			'dashicons-book',
			25
		);

		// First submenu repeats the parent slug, which both labels it "Books" and
		// makes the top-level "Sheafs" link land on the Books list.
		add_submenu_page(
			self::PAGE,
			__( 'Books', 'sheaf' ),
			__( 'Books', 'sheaf' ),
			self::CAPABILITY,
			self::PAGE,
			[ self::class, 'render' ]
		);
		// A "Chapters" link to the native chapter list — where authors manage all
		// chapters, including bulk actions like publishing many imported drafts at
		// once. "Add New" and "Import chapters" are buttons on that screen.
		add_submenu_page(
			self::PAGE,
			__( 'Chapters', 'sheaf' ),
			__( 'Chapters', 'sheaf' ),
			self::CAPABILITY,
			'edit.php?post_type=' . Chapters::POST_TYPE
		);
	}

	/**
	 * Treat the chapter screens as children of the Sheafs menu.
	 */
	public static function highlight_parent( string $parent_file ): string {
		if ( Chapters::POST_TYPE === ( $GLOBALS['typenow'] ?? '' ) ) {
			return self::PAGE;
		}
		return $parent_file;
	}

	/**
	 * Highlight the right Sheafs submenu for the current chapter screen.
	 */
	public static function highlight_submenu( ?string $submenu_file ): ?string {
		if ( Chapters::POST_TYPE !== ( $GLOBALS['typenow'] ?? '' ) ) {
			return $submenu_file;
		}
		// The chapter list, editor and "add new" all live under "Chapters".
		return 'edit.php?post_type=' . Chapters::POST_TYPE;
	}

	public static function enqueue( string $hook ): void {
		if ( $hook !== self::$hook ) {
			return;
		}
		// Version by file mtime so edits bust the browser cache during active
		// development (the asset is mounted live and changes between requests).
		$asset = SHEAF_DIR . 'assets/admin-reorder.js';
		$ver   = file_exists( $asset ) ? (string) filemtime( $asset ) : SHEAF_VERSION;
		wp_enqueue_script(
			'sheaf-reorder',
			SHEAF_URL . 'assets/admin-reorder.js',
			[ 'jquery', 'jquery-ui-sortable' ],
			$ver,
			true
		);
		wp_localize_script(
			'sheaf-reorder',
			'SheafReorder',
			[
				'ajax'       => admin_url( 'admin-ajax.php' ),
				'nonce'      => wp_create_nonce( self::NONCE ),
				'savingText' => __( 'Saving…', 'sheaf' ),
				'savedText'  => __( 'Order saved.', 'sheaf' ),
				'failedText' => __( 'Save failed.', 'sheaf' ),
			]
		);

		// Auto-save for the per-book "Style sets" checkboxes.
		$ss_asset = SHEAF_DIR . 'assets/admin-book-style-sets.js';
		$ss_ver   = file_exists( $ss_asset ) ? (string) filemtime( $ss_asset ) : SHEAF_VERSION;
		wp_enqueue_script(
			'sheaf-book-style-sets',
			SHEAF_URL . 'assets/admin-book-style-sets.js',
			[],
			$ss_ver,
			true
		);
		wp_localize_script(
			'sheaf-book-style-sets',
			'SheafBookStyleSets',
			[
				'ajax'       => admin_url( 'admin-ajax.php' ),
				'nonce'      => wp_create_nonce( self::STYLE_NONCE ),
				'savingText' => __( 'Saving…', 'sheaf' ),
				'savedText'  => __( 'Saved.', 'sheaf' ),
				'failedText' => __( 'Save failed.', 'sheaf' ),
			]
		);

		// Gray out / reveal the dependent fields as toggles change, and auto-save
		// the whole settings form over AJAX (no Save button).
		$scroll_asset = SHEAF_DIR . 'assets/admin-book-scroll.js';
		$scroll_ver   = file_exists( $scroll_asset ) ? (string) filemtime( $scroll_asset ) : SHEAF_VERSION;
		wp_enqueue_script(
			'sheaf-book-scroll',
			SHEAF_URL . 'assets/admin-book-scroll.js',
			[],
			$scroll_ver,
			true
		);
		wp_localize_script(
			'sheaf-book-scroll',
			'SheafBookScroll',
			[
				'ajax'       => admin_url( 'admin-ajax.php' ),
				'nonce'      => wp_create_nonce( self::SCROLL_NONCE ),
				'savingText' => __( 'Saving…', 'sheaf' ),
				'savedText'  => __( 'Saved.', 'sheaf' ),
				'failedText' => __( 'Save failed.', 'sheaf' ),
				'warnPrefix' => __( 'Divider HTML may be malformed:', 'sheaf' ),
			]
		);
	}

	/**
	 * Router for the page: a single book's settings, or the books list.
	 */
	public static function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to manage books.', 'sheaf' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation.
		$book_id = isset( $_GET['book'] ) ? absint( $_GET['book'] ) : 0;

		echo '<div class="wrap">';
		if ( $book_id && Books::get_chapters_for_admin( $book_id ) ) {
			self::render_book( $book_id );
		} else {
			self::render_list();
		}
		echo '</div>';
	}

	private static function render_list(): void {
		$book_ids = Books::all_book_ids();

		echo '<h1 class="wp-heading-inline">' . esc_html__( 'Books', 'sheaf' ) . '</h1>';
		echo '<p class="description">' . esc_html__( 'Every Page that has chapters assigned to it appears here.', 'sheaf' ) . '</p>';

		// Surface orphaned chapters (e.g. left behind when a book Page is
		// deleted) with a link to the list, where they can be bulk-assigned.
		$unassigned = Books::unassigned_chapter_count();
		if ( $unassigned ) {
			$url = add_query_arg(
				[
					'post_type'        => Chapters::POST_TYPE,
					'sheaf_unassigned' => 1,
				],
				admin_url( 'edit.php' )
			);
			printf(
				'<p><a href="%1$s">%2$s</a></p>',
				esc_url( $url ),
				esc_html(
					sprintf(
						/* translators: %s: number of unassigned chapters. */
						_n( '%s chapter is not assigned to a book — assign it', '%s chapters are not assigned to a book — assign them', $unassigned, 'sheaf' ),
						number_format_i18n( $unassigned )
					)
				)
			);
		}

		if ( ! $book_ids ) {
			echo '<p>' . esc_html__( 'No books yet. Assign a chapter to a Page using the Book selector on the chapter editor.', 'sheaf' ) . '</p>';
			return;
		}

		echo '<table class="wp-list-table widefat fixed striped">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'Book', 'sheaf' ) . '</th>';
		echo '<th>' . esc_html__( 'Series / context', 'sheaf' ) . '</th>';
		echo '<th>' . esc_html__( 'Chapters', 'sheaf' ) . '</th>';
		echo '<th>' . esc_html__( 'Words', 'sheaf' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $book_ids as $book_id ) {
			$chapters = Books::get_chapters_for_admin( $book_id );
			$words    = 0;
			$count    = 0;
			foreach ( $chapters as $chapter ) {
				$words += Words::get( (int) $chapter->ID );
				if ( ! Chapters::is_section( (int) $chapter->ID ) ) {
					++$count; // Section dividers are not counted as chapters.
				}
			}

			// Series / context = the book's ancestor Pages, each linked to the
			// page it names.
			$ancestors = Books::ancestors( $book_id );
			if ( $ancestors ) {
				$links = array_map(
					static function ( \WP_Post $page ): string {
						return sprintf(
							'<a href="%1$s">%2$s</a>',
							esc_url( (string) get_permalink( $page ) ),
							esc_html( get_the_title( $page ) )
						);
					},
					$ancestors
				);
				$context = implode( ' › ', $links );
			} else {
				$context = '<span aria-hidden="true">—</span>';
			}

			$manage = add_query_arg(
				[
					'post_type' => Chapters::POST_TYPE,
					'page'      => self::PAGE,
					'book'      => $book_id,
				],
				admin_url( 'edit.php' )
			);
			$chapters_url = add_query_arg(
				[
					'post_type'  => Chapters::POST_TYPE,
					'sheaf_book' => $book_id,
				],
				admin_url( 'edit.php' )
			);
			$add_url = add_query_arg(
				[
					'post_type'  => Chapters::POST_TYPE,
					'sheaf_book' => $book_id,
				],
				admin_url( 'post-new.php' )
			);

			echo '<tr>';
			printf(
				'<td><strong><a href="%1$s">%2$s</a></strong><div class="row-actions"><span><a href="%3$s">%4$s</a> | </span><span><a href="%5$s">%6$s</a> | </span><span><a href="%7$s">%8$s</a></span></div></td>',
				esc_url( $manage ),
				esc_html( get_the_title( $book_id ) ),
				esc_url( (string) get_edit_post_link( $book_id ) ),
				esc_html__( 'Edit page', 'sheaf' ),
				esc_url( $add_url ),
				esc_html__( 'Add chapter', 'sheaf' ),
				esc_url( Import::url( $book_id ) ),
				esc_html__( 'Import', 'sheaf' )
			);
			echo '<td>' . $context . '</td>'; // Links built and escaped above.
			printf(
				'<td><a href="%1$s">%2$s</a></td>',
				esc_url( $chapters_url ),
				esc_html( number_format_i18n( $count ) )
			);
			printf( '<td>%s</td>', esc_html( number_format_i18n( $words ) ) );
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	private static function render_book( int $book_id ): void {
		$chapters = Books::get_chapters_for_admin( $book_id );
		$back     = add_query_arg(
			[
				'post_type' => Chapters::POST_TYPE,
				'page'      => self::PAGE,
			],
			admin_url( 'edit.php' )
		);
		$add_url  = add_query_arg(
			[
				'post_type'  => Chapters::POST_TYPE,
				'sheaf_book' => $book_id,
			],
			admin_url( 'post-new.php' )
		);

		$permalink = (string) get_permalink( $book_id );
		$edit_page = (string) get_edit_post_link( $book_id );

		echo '<style>
			.sheaf-back{display:inline-block;margin:.6em 0 .2em;color:#646970;text-decoration:none;font-size:13px}
			.sheaf-back:hover,.sheaf-back:focus{color:#2271b1}
			.sheaf-book-heading{margin:0 0 .4em}
			.sheaf-book-heading .wp-heading-inline{margin:0}
			.sheaf-book-title{text-decoration:none;color:inherit}
			.sheaf-book-title:hover,.sheaf-book-title:focus{color:#2271b1}
			.sheaf-book-heading .row-actions{left:auto;visibility:visible}
			.sheaf-chapter-links{margin:.2em 0 .6em;font-size:13px}
			.sheaf-chapter-links .sep{color:#c3c4c7;margin:0 .2em}
			.sheaf-rule{border:0;border-top:1px solid #dcdcde;margin:2.5em 0 1.2em}
		</style>';

		// "Back to the list" link, sitting above the title — muted and unstyled,
		// not a button.
		printf(
			'<a href="%1$s" class="sheaf-back">&larr; %2$s</a>',
			esc_url( $back ),
			esc_html__( 'All Books', 'sheaf' )
		);

		// Title links to the live book page; the management actions reveal on hover.
		echo '<div class="sheaf-book-heading">';
		printf(
			'<h1 class="wp-heading-inline"><a href="%1$s" class="sheaf-book-title">%2$s</a></h1>',
			esc_url( $permalink ),
			esc_html( get_the_title( $book_id ) )
		);

		$actions   = [];
		$actions[] = sprintf( '<span class="view"><a href="%s">%s</a></span>', esc_url( $permalink ), esc_html__( 'View Book', 'sheaf' ) );
		if ( $edit_page ) {
			$actions[] = sprintf( '<span class="edit"><a href="%s">%s</a></span>', esc_url( $edit_page ), esc_html__( 'Edit Book Page', 'sheaf' ) );
		}
		echo '<div class="row-actions">' . implode( ' | ', $actions ) . '</div>'; // Links built and escaped above.
		echo '</div>';

		echo '<hr class="wp-header-end">';

		echo '<hr class="sheaf-rule">';
		echo '<h2>' . esc_html__( 'Chapters', 'sheaf' ) . '</h2>';

		// The chapter list, filtered to this book — where WordPress's bulk actions
		// can publish or edit many chapters at once.
		$bulk_url = add_query_arg(
			[
				'post_type'  => Chapters::POST_TYPE,
				'sheaf_book' => $book_id,
			],
			admin_url( 'edit.php' )
		);

		$links   = [];
		$links[] = sprintf( '<a href="%s">%s</a>', esc_url( $bulk_url ), esc_html__( 'Bulk Edit Chapters', 'sheaf' ) );
		$links[] = sprintf( '<a href="%s">%s</a>', esc_url( $add_url ), esc_html__( 'Add New Chapter', 'sheaf' ) );
		$links[] = sprintf( '<a href="%s">%s</a>', esc_url( Import::url( $book_id ) ), esc_html__( 'Import Chapters', 'sheaf' ) );
		echo '<p class="sheaf-chapter-links">' . implode( ' <span class="sep">|</span> ', $links ) . '</p>'; // Links built and escaped above.

		echo '<p class="description">' . esc_html__( 'Drag a chapter by its handle to set the reading order — changes save automatically.', 'sheaf' ) . '</p>';
		echo '<p id="sheaf-reorder-status" class="description" aria-live="polite"></p>';

		self::reorder_styles();
		self::render_chapters_table( $book_id, $chapters );

		self::render_style_sets( $book_id );

		self::render_display_settings( $book_id );
	}

	/**
	 * The per-book settings form. Two sections:
	 *  - "Display settings": table of contents, breadcrumbs, and chapter
	 *    navigation. These apply to single-chapter views — which a reader always
	 *    gets when reading one chapter at a time, even on a scrolling book — so
	 *    they must be configurable regardless of the scrolling toggle.
	 *  - "Full-book scrolling": the opt-in continuous-scroll reader and its
	 *    options, grayed out (disabled) until the feature is enabled.
	 * Every field auto-saves over AJAX (assets/admin-book-scroll.js), like the
	 * chapter-reorder and style-set screens — there is no Save button. Disabled
	 * fields are still read by the serializer, so graying out never discards a
	 * configured value.
	 */
	private static function render_display_settings( int $book_id ): void {
		$s = Scroll_Settings::get( $book_id );

		// <option> list from a flat value=>label map.
		$options = static function ( array $choices, string $selected ): string {
			$out = '';
			foreach ( $choices as $value => $label ) {
				$out .= sprintf(
					'<option value="%s"%s>%s</option>',
					esc_attr( (string) $value ),
					selected( $selected, (string) $value, false ),
					esc_html( $label )
				);
			}
			return $out;
		};
		$breaks = Scroll_Settings::break_choices();

		echo '<hr class="sheaf-rule">';
		echo '<h2>' . esc_html__( 'Display settings', 'sheaf' ) . '</h2>';

		echo '<style>
			.sheaf-scroll-settings .sheaf-scroll-html{margin-top:.6em}
			.sheaf-scroll-settings .sheaf-scroll-section-break{margin:.6em 0 0;padding-left:1.2em;border-left:3px solid #dcdcde}
			.sheaf-scroll-settings .sheaf-toc-custom{margin-left:.6em}
			.sheaf-scroll-settings .sheaf-scroll-disabled{opacity:.5}
			.sheaf-scroll-enable{margin:.2em 0 .6em}
		</style>';

		printf( '<div class="sheaf-scroll-settings" data-book="%d">', (int) $book_id );

		echo '<table class="form-table" role="presentation"><tbody>';

		// Table of contents list style + custom keyword/marker field.
		$style_options = '';
		foreach ( Scroll_Settings::list_style_groups() as $group => $items ) {
			$style_options .= sprintf( '<optgroup label="%s">%s</optgroup>', esc_attr( $group ), $options( $items, (string) $s['toc_list_style'] ) );
		}
		printf(
			'<tr><th scope="row"><label for="sheaf-toc-list-style">%1$s</label></th><td>
				<select id="sheaf-toc-list-style" name="sheaf_scroll[toc_list_style]">%2$s</select>
				<span class="sheaf-toc-custom"%3$s><input type="text" name="sheaf_scroll[toc_list_style_custom]" value="%4$s" class="regular-text" placeholder="%5$s"></span>
				<p class="description">%6$s</p>
			</td></tr>',
			esc_html__( 'Table of contents bullet style', 'sheaf' ),
			$style_options,
			'custom' === $s['toc_list_style'] ? '' : ' style="display:none"',
			esc_attr( (string) $s['toc_list_style_custom'] ),
			esc_attr__( 'e.g. lower-armenian or "⁂"', 'sheaf' ),
			esc_html__( 'The list marker for the table of contents. “Custom” takes a CSS list-style-type or @counter-style keyword (e.g. lower-armenian), or a quoted marker string (e.g. "⁂").', 'sheaf' )
		);

		// TOC per-chapter info.
		printf(
			'<tr><th scope="row"><label for="sheaf-toc-meta">%1$s</label></th><td>
				<select id="sheaf-toc-meta" name="sheaf_scroll[toc_meta]">%2$s</select>
				<p class="description">%3$s</p>
			</td></tr>',
			esc_html__( 'Table of contents per-chapter info', 'sheaf' ),
			$options( Scroll_Settings::toc_meta_choices(), (string) $s['toc_meta'] ),
			esc_html__( 'What each table-of-contents entry shows after the chapter title.', 'sheaf' )
		);

		// Breadcrumb display.
		printf(
			'<tr><th scope="row"><label for="sheaf-breadcrumbs">%1$s</label></th><td>
				<select id="sheaf-breadcrumbs" name="sheaf_scroll[breadcrumbs]">%2$s</select>
				<p class="description">%3$s</p>
			</td></tr>',
			esc_html__( 'Breadcrumb display', 'sheaf' ),
			$options( Scroll_Settings::breadcrumb_choices(), (string) $s['breadcrumbs'] ),
			esc_html__( 'Where the breadcrumb trail is placed on a chapter page.', 'sheaf' )
		);

		// Chapter navigation placement.
		printf(
			'<tr><th scope="row"><label for="sheaf-nav-at">%1$s</label></th><td>
				<select id="sheaf-nav-at" name="sheaf_scroll[chapter_nav_at]">%2$s</select>
				<p class="description">%3$s</p>
			</td></tr>',
			esc_html__( 'Display chapter navigation at', 'sheaf' ),
			$options( Scroll_Settings::nav_pos_choices(), (string) $s['chapter_nav_at'] ),
			esc_html__( 'Where the previous/next chapter navigation is placed on a chapter page.', 'sheaf' )
		);

		// Chapter navigation style (the "back" option shows the book's title).
		$nav_styles                 = Scroll_Settings::nav_style_choices();
		$nav_styles['back_to_book'] = sprintf(
			/* translators: %s: book title. */
			__( 'Back to “%s”', 'sheaf' ),
			get_the_title( $book_id )
		);
		printf(
			'<tr><th scope="row"><label for="sheaf-nav-style">%1$s</label></th><td>
				<select id="sheaf-nav-style" name="sheaf_scroll[chapter_nav_style]">%2$s</select>
				<p class="description">%3$s</p>
			</td></tr>',
			esc_html__( 'Chapter navigation style', 'sheaf' ),
			$options( $nav_styles, (string) $s['chapter_nav_style'] ),
			esc_html__( 'What the chapter navigation contains.', 'sheaf' )
		);

		echo '</tbody></table>';

		/* ------------------------------------------------ Full-book scrolling -- */

		echo '<hr class="sheaf-rule">';
		echo '<h2>' . esc_html__( 'Full-book scrolling', 'sheaf' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Allow readers to move through the entire book in one continuous scroll. They can choose to view a single chapter at a time if they like.', 'sheaf' ) . '</p>';

		printf(
			'<p class="sheaf-scroll-enable"><label><input type="checkbox" id="sheaf-scroll-enabled" name="sheaf_scroll[enabled]" value="1"%1$s> <strong>%2$s</strong></label></p>',
			checked( $s['enabled'], true, false ),
			esc_html__( 'Enable full-book scrolling', 'sheaf' )
		);

		echo '<div class="sheaf-scroll-fullbook"><table class="form-table" role="presentation"><tbody>';

		// Chapter titles.
		printf(
			'<tr><th scope="row">%1$s</th><td><label><input type="checkbox" name="sheaf_scroll[chapter_titles]" value="1"%2$s> %3$s</label><p class="description">%4$s</p></td></tr>',
			esc_html__( 'Chapter titles', 'sheaf' ),
			checked( $s['chapter_titles'], true, false ),
			esc_html__( 'Show each chapter’s title in the scroll', 'sheaf' ),
			esc_html__( 'Off stitches chapters together with only a break between them — good for novellas of short, unnamed sections.', 'sheaf' )
		);

		// Chapter break + conditional divider HTML.
		printf(
			'<tr><th scope="row"><label for="sheaf-scroll-chapter-break">%1$s</label></th><td>
				<select id="sheaf-scroll-chapter-break" class="sheaf-scroll-break" data-html-target="chapter" name="sheaf_scroll[chapter_break]">%2$s</select>
				<p class="description">%3$s</p>
				<div class="sheaf-scroll-html sheaf-scroll-html--chapter">
					<p><label for="sheaf-scroll-chapter-html">%4$s</label></p>
					<textarea id="sheaf-scroll-chapter-html" class="large-text code" rows="3" name="sheaf_scroll[chapter_break_html]">%5$s</textarea>
					<p class="description">%6$s</p>
				</div>
			</td></tr>',
			esc_html__( 'Chapter break', 'sheaf' ),
			$options( $breaks, (string) $s['chapter_break'] ),
			esc_html__( 'How consecutive chapters are separated in the scroll.', 'sheaf' ),
			esc_html__( 'Divider HTML', 'sheaf' ),
			esc_textarea( (string) $s['chapter_break_html'] ),
			esc_html__( 'Inserted between chapters. Any HTML is allowed and stored as entered — you are trusted.', 'sheaf' )
		);

		// Special section breaks + their own break style and divider HTML.
		printf(
			'<tr><th scope="row">%1$s</th><td>
				<label><input type="checkbox" id="sheaf-scroll-special-sections" name="sheaf_scroll[special_section_breaks]" value="1"%2$s> %3$s</label>
				<div class="sheaf-scroll-section-break">
					<p><label for="sheaf-scroll-section-break">%4$s</label></p>
					<select id="sheaf-scroll-section-break" class="sheaf-scroll-break" data-html-target="section" name="sheaf_scroll[section_break]">%5$s</select>
					<div class="sheaf-scroll-html sheaf-scroll-html--section">
						<p><label for="sheaf-scroll-section-html">%6$s</label></p>
						<textarea id="sheaf-scroll-section-html" class="large-text code" rows="3" name="sheaf_scroll[section_break_html]">%7$s</textarea>
					</div>
				</div>
			</td></tr>',
			esc_html__( 'Section breaks', 'sheaf' ),
			checked( $s['special_section_breaks'], true, false ),
			esc_html__( 'Give “section” chapters a distinct break after them', 'sheaf' ),
			esc_html__( 'Section break style', 'sheaf' ),
			$options( $breaks, (string) $s['section_break'] ),
			esc_html__( 'Section divider HTML', 'sheaf' ),
			esc_textarea( (string) $s['section_break_html'] )
		);

		// Pseudo page numbers.
		printf(
			'<tr><th scope="row">%1$s</th><td><label><input type="checkbox" name="sheaf_scroll[show_page_numbers]" value="1"%2$s> %3$s</label></td></tr>',
			esc_html__( 'Page numbers', 'sheaf' ),
			checked( $s['show_page_numbers'], true, false ),
			esc_html__( 'Show an estimated “page X of Y” in the margin', 'sheaf' )
		);

		// Full TOC in the margin.
		printf(
			'<tr><th scope="row">%1$s</th><td><label><input type="checkbox" name="sheaf_scroll[show_full_toc]" value="1"%2$s> %3$s</label></td></tr>',
			esc_html__( 'Table of contents', 'sheaf' ),
			checked( $s['show_full_toc'], true, false ),
			esc_html__( 'Show the full table of contents in the margin, highlighting the current chapter', 'sheaf' )
		);

		echo '</tbody></table></div>';

		echo '<p id="sheaf-scroll-status" class="description" aria-live="polite"></p>';
		echo '<div id="sheaf-scroll-warnings"></div>';

		echo '</div>'; // .sheaf-scroll-settings
	}

	/**
	 * Auto-save the per-book settings (AJAX). The serialized form arrives under
	 * the sheaf_scroll[...] namespace, is sanitised through Scroll_Settings, and
	 * saved. Any divider-HTML lint warnings are returned so the script can show
	 * them inline (no page reload, so no transient hand-off).
	 */
	public static function ajax_save_scroll(): void {
		check_ajax_referer( self::SCROLL_NONCE, 'nonce' );

		$book_id = isset( $_POST['book'] ) ? absint( wp_unslash( $_POST['book'] ) ) : 0;
		if ( ! $book_id || 'page' !== get_post_type( $book_id ) || ! current_user_can( 'edit_post', $book_id ) ) {
			wp_send_json_error( 'forbidden', 403 );
		}

		$settings = Scroll_Settings::from_request( wp_unslash( $_POST ) );
		Scroll_Settings::save( $book_id, $settings );

		$warnings = array_values(
			array_unique(
				array_merge(
					Scroll_Settings::lint_html( (string) $settings['chapter_break_html'] ),
					Scroll_Settings::lint_html( (string) $settings['section_break_html'] )
				)
			)
		);

		wp_send_json_success( [ 'warnings' => $warnings ] );
	}

	/**
	 * Per-book style-set activation: which sets the chapter editor and importer
	 * offer for this book's chapters. Because the style CSS is global, toggling a
	 * set here neither adds nor removes styling from chapters already written —
	 * it only changes what is offered going forward.
	 */
	private static function render_style_sets( int $book_id ): void {
		$all = Style_Sets::all();

		echo '<hr class="sheaf-rule">';
		echo '<h2>' . esc_html__( 'Style sets', 'sheaf' ) . '</h2>';

		if ( ! $all ) {
			printf(
				'<p class="description">%s</p>',
				wp_kses(
					sprintf(
						/* translators: %s: URL of the Style Sets screen. */
						__( 'No style sets exist yet. <a href="%s">Create one</a> to offer named styles to this book.', 'sheaf' ),
						esc_url( Style_Sets_Admin::url() )
					),
					[ 'a' => [ 'href' => [] ] ]
				)
			);
			return;
		}

		$active = Style_Sets::active_sets( $book_id );

		echo '<p class="description">' . esc_html__( 'Choose which style sets this book’s chapters may use. This controls what the editor and importer offer; it does not change styling already applied. Changes save automatically.', 'sheaf' ) . '</p>';

		echo '<style>.sheaf-style-set-list{margin:.6em 0 .4em}.sheaf-style-set-list li{margin:.25em 0}.sheaf-style-set-list .description{margin-left:.4em}</style>';

		printf( '<ul class="sheaf-style-set-list" data-book="%d">', $book_id );
		foreach ( $all as $set => $data ) {
			$label = '' !== (string) ( $data['label'] ?? '' ) ? (string) $data['label'] : (string) $set;
			$count = count( (array) ( $data['styles'] ?? [] ) );
			printf(
				'<li><label><input type="checkbox" value="%1$s"%2$s> %3$s</label><span class="description">%4$s</span></li>',
				esc_attr( (string) $set ),
				checked( in_array( (string) $set, $active, true ), true, false ),
				esc_html( $label ),
				esc_html( sprintf( /* translators: %s: number of styles in the set. */ _n( '%s style', '%s styles', $count, 'sheaf' ), number_format_i18n( $count ) ) )
			);
		}
		echo '</ul>';
		echo '<p id="sheaf-style-set-status" class="description" aria-live="polite"></p>';
	}

	/**
	 * Auto-save a book's active style sets (AJAX), keeping only sets that still
	 * exist in the library.
	 */
	public static function ajax_save_book_sets(): void {
		check_ajax_referer( self::STYLE_NONCE, 'nonce' );

		$book_id = isset( $_POST['book'] ) ? absint( $_POST['book'] ) : 0;
		if ( ! $book_id || ! current_user_can( 'edit_post', $book_id ) ) {
			wp_send_json_error( 'forbidden', 403 );
		}

		$chosen = isset( $_POST['sets'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['sets'] ) ) : [];
		$chosen = array_values( array_intersect( $chosen, array_keys( Style_Sets::all() ) ) );

		if ( $chosen ) {
			update_post_meta( $book_id, Style_Sets::BOOK_META, $chosen );
		} else {
			delete_post_meta( $book_id, Style_Sets::BOOK_META );
		}

		wp_send_json_success( [ 'count' => count( $chosen ) ] );
	}

	/**
	 * The book's chapters as one sortable list table: drag a row by its handle
	 * to set the reading order (saved over AJAX), with the overview columns an
	 * author wants in the same rows — reading position, publish state and last
	 * edit, comments, and word count. Always scoped to a single book, so there
	 * is no "Book" column and no per-book filter.
	 *
	 * @param \WP_Post[] $chapters
	 */
	private static function render_chapters_table( int $book_id, array $chapters ): void {
		echo '<table class="wp-list-table widefat fixed striped sheaf-chapters">';
		echo '<thead><tr>';
		echo '<th scope="col" style="width:5.5em">' . esc_html__( 'Order', 'sheaf' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Title', 'sheaf' ) . '</th>';
		echo '<th scope="col" style="width:13em">' . esc_html__( 'Status', 'sheaf' ) . '</th>';
		echo '<th scope="col" style="width:6em">' . esc_html__( 'Comments', 'sheaf' ) . '</th>';
		echo '<th scope="col" style="width:9em">' . esc_html__( 'Words', 'sheaf' ) . '</th>';
		echo '</tr></thead>';

		printf( '<tbody id="sheaf-reorder" data-book="%d">', $book_id );

		if ( ! $chapters ) {
			echo '<tr class="no-items"><td colspan="5">' . esc_html__( 'No chapters yet.', 'sheaf' ) . '</td></tr>';
		}

		$i = 1;
		foreach ( $chapters as $chapter ) {
			$id         = (int) $chapter->ID;
			$is_section = Chapters::is_section( $id );

			printf( '<tr data-id="%1$d" class="%2$s">', $id, $is_section ? 'is-section' : '' );

			// Order: drag handle + reading position (sections are not numbered).
			printf(
				'<td class="sheaf-order-cell"><span class="sheaf-reorder__handle dashicons dashicons-menu" aria-hidden="true"></span> <span class="sheaf-reorder__num">%s</span></td>',
				$is_section ? '·' : esc_html( number_format_i18n( $i ) )
			);

			self::title_cell( $chapter, $is_section );
			self::status_cell( $chapter );
			self::comments_cell( $id );
			self::words_cell( $id, $is_section );

			echo '</tr>';

			if ( ! $is_section ) {
				++$i;
			}
		}

		echo '</tbody></table>';
	}

	/**
	 * Title cell: editable title link, a section tag, and the
	 * Edit / View-or-Preview / Trash row actions.
	 */
	private static function title_cell( \WP_Post $chapter, bool $is_section ): void {
		$id   = (int) $chapter->ID;
		$edit = (string) get_edit_post_link( $id );

		echo '<td>';
		if ( $edit ) {
			printf( '<strong class="sheaf-title"><a class="row-title" href="%1$s">%2$s</a></strong>', esc_url( $edit ), esc_html( get_the_title( $chapter ) ) );
		} else {
			printf( '<strong class="sheaf-title">%s</strong>', esc_html( get_the_title( $chapter ) ) );
		}
		if ( $is_section ) {
			echo ' <span class="post-state">' . esc_html__( 'Section', 'sheaf' ) . '</span>';
		}

		$actions = [];
		if ( $edit ) {
			$actions[] = sprintf( '<a href="%s">%s</a>', esc_url( $edit ), esc_html__( 'Edit', 'sheaf' ) );
		}
		if ( 'publish' === $chapter->post_status ) {
			$actions[] = sprintf( '<a href="%s">%s</a>', esc_url( (string) get_permalink( $id ) ), esc_html__( 'View', 'sheaf' ) );
		} else {
			$actions[] = sprintf( '<a href="%s">%s</a>', esc_url( get_preview_post_link( $id ) ), esc_html__( 'Preview', 'sheaf' ) );
		}
		$trash = get_delete_post_link( $id );
		if ( $trash ) {
			$actions[] = sprintf( '<a class="submitdelete" href="%s">%s</a>', esc_url( $trash ), esc_html__( 'Trash', 'sheaf' ) );
		}
		printf( '<div class="row-actions"><span>%s</span></div>', implode( ' | </span><span>', $actions ) );
		echo '</td>';
	}

	/**
	 * Status cell: publish state plus a date — when it went live, or, for
	 * everything else, when it was last edited (so stale drafts stand out).
	 */
	private static function status_cell( \WP_Post $chapter ): void {
		$obj   = get_post_status_object( get_post_status( $chapter ) );
		$label = $obj ? $obj->label : ucfirst( $chapter->post_status );

		if ( 'publish' === $chapter->post_status ) {
			/* translators: %s: date a chapter was published. */
			$when = sprintf( __( 'Published %s', 'sheaf' ), get_the_date( '', $chapter ) );
		} else {
			/* translators: %s: date a chapter was last edited. */
			$when = sprintf( __( 'Edited %s', 'sheaf' ), get_the_modified_date( '', $chapter ) );
		}

		printf(
			'<td><span class="sheaf-status">%1$s</span><br><span class="description">%2$s</span></td>',
			esc_html( $label ),
			esc_html( $when )
		);
	}

	/**
	 * Comments cell: the familiar approved-count bubble, plus a pending bubble
	 * linking to the moderation queue when comments await review. (WordPress
	 * tracks no per-reader "new/unread" state, and stores no view counts.)
	 */
	private static function comments_cell( int $id ): void {
		$approved = (int) get_comments_number( $id );
		$pending  = function_exists( 'get_pending_comments_num' ) ? (int) get_pending_comments_num( $id ) : 0;

		echo '<td class="column-comments">';
		if ( $approved || $pending ) {
			$base = admin_url( 'edit-comments.php?p=' . $id );
			echo '<div class="post-com-count-wrapper">';
			printf(
				'<a href="%1$s" class="post-com-count post-com-count-approved"><span class="comment-count-approved" aria-hidden="true">%2$s</span><span class="screen-reader-text">%3$s</span></a>',
				esc_url( $base ),
				esc_html( number_format_i18n( $approved ) ),
				/* translators: %s: number of approved comments. */
				esc_html( sprintf( _n( '%s approved comment', '%s approved comments', $approved, 'sheaf' ), number_format_i18n( $approved ) ) )
			);
			if ( $pending ) {
				printf(
					'<a href="%1$s" class="post-com-count post-com-count-pending"><span class="comment-count-pending" aria-hidden="true">%2$s</span><span class="screen-reader-text">%3$s</span></a>',
					esc_url( add_query_arg( 'comment_status', 'moderated', $base ) ),
					esc_html( number_format_i18n( $pending ) ),
					/* translators: %s: number of comments awaiting moderation. */
					esc_html( sprintf( _n( '%s comment awaiting moderation', '%s comments awaiting moderation', $pending, 'sheaf' ), number_format_i18n( $pending ) ) )
				);
			}
			echo '</div>';
		} else {
			echo '<span aria-hidden="true">—</span>';
		}
		echo '</td>';
	}

	/**
	 * Words cell: word count and reading time (sections carry neither).
	 */
	private static function words_cell( int $id, bool $is_section ): void {
		if ( $is_section ) {
			echo '<td><span aria-hidden="true">—</span></td>';
			return;
		}
		$words   = Words::get( $id );
		$minutes = Words::reading_minutes( $words );
		printf(
			'<td>%1$s<br><span class="description">%2$s</span></td>',
			esc_html( number_format_i18n( $words ) ),
			/* translators: %d: reading time in minutes. */
			esc_html( sprintf( _n( '%d min', '%d min', $minutes, 'sheaf' ), $minutes ) )
		);
	}

	/**
	 * Inline styling for the sortable chapters table — kept inline so there is
	 * no extra stylesheet to ship.
	 */
	private static function reorder_styles(): void {
		echo '<style>
			.sheaf-chapters .sheaf-order-cell{white-space:nowrap}
			.sheaf-chapters .sheaf-reorder__handle{cursor:grab;color:#787c82;vertical-align:middle}
			.sheaf-chapters .sheaf-reorder__num{display:inline-block;min-width:1.6em;text-align:right;color:#50575e}
			.sheaf-chapters tr.is-section td{background:#f0f6fc}
			.sheaf-chapters tr.is-section .sheaf-title{font-weight:600}
			.sheaf-chapters .sheaf-status{font-weight:600}
			.sheaf-reorder__placeholder td{background:#f6f7f7}
			tr.ui-sortable-helper{box-shadow:0 2px 6px rgba(0,0,0,.18);display:table}
		</style>';
	}

	/**
	 * Persist a new chapter order for a book.
	 */
	public static function ajax_reorder(): void {
		check_ajax_referer( self::NONCE, 'nonce' );

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( 'forbidden', 403 );
		}

		$book_id = isset( $_POST['book'] ) ? absint( $_POST['book'] ) : 0;
		$order   = isset( $_POST['order'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['order'] ) ) : [];

		if ( ! $book_id || ! $order ) {
			wp_send_json_error( 'bad-request', 400 );
		}

		$position = 0;
		$updated  = 0;
		foreach ( $order as $chapter_id ) {
			// Only reorder chapters that really belong to this book and that the
			// current user may edit.
			if ( (int) get_post_meta( $chapter_id, Books::BOOK_META, true ) !== $book_id ) {
				continue;
			}
			if ( ! current_user_can( 'edit_post', $chapter_id ) ) {
				continue;
			}
			wp_update_post(
				[
					'ID'         => $chapter_id,
					'menu_order' => $position,
				]
			);
			++$position;
			++$updated;
		}

		wp_send_json_success( [ 'updated' => $updated ] );
	}
}
