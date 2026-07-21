<?php
/**
 * Contextual navigation in the front-end admin toolbar.
 *
 * Chapters are hidden from the admin bar by default (the CPT sets no
 * show_in_admin_bar, and its menu lives under "Sheafs"), so WordPress adds
 * neither a "+ New → Chapter" entry nor an "Edit Chapter" link on its own. This
 * class adds book-aware toolbar items instead:
 *
 *  - "+ New → Chapter" while viewing any Page or a chapter, with the book the
 *    chapter would join pre-selected (the Page itself, or the chapter's book).
 *  - "Edit Book" in place of "Edit Page" when the Page being viewed is a book.
 *  - "Edit Chapter" while viewing a chapter.
 *
 * @package Sheaf
 */

namespace Sheaf;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Admin_Bar {

	public static function register(): void {
		// After core's new-content (70) and edit (80) nodes, so the "+ New" menu
		// exists to hang a child on and the "edit" node exists to relabel.
		add_action( 'admin_bar_menu', [ self::class, 'nodes' ], 90 );
	}

	/**
	 * Add or adjust the toolbar nodes for the object currently being viewed.
	 */
	public static function nodes( \WP_Admin_Bar $bar ): void {
		if ( is_page() ) {
			$page_id = (int) get_queried_object_id();
			self::add_new_chapter( $bar, $page_id );
			if ( Books::is_book( $page_id ) ) {
				self::relabel_edit_book( $bar );
			}
		} elseif ( is_singular( Chapters::POST_TYPE ) ) {
			$chapter_id = (int) get_queried_object_id();
			self::add_new_chapter( $bar, Books::get_book_id( $chapter_id ) );
			self::add_edit_chapter( $bar, $chapter_id );
		}
	}

	/**
	 * Add "Chapter" to the "+ New" menu, pre-selecting the given book (0 = none)
	 * so the chapter editor opens with its Book selector already set. Skipped for
	 * users who cannot create chapters, or if the "+ New" menu is absent.
	 */
	private static function add_new_chapter( \WP_Admin_Bar $bar, int $book_id ): void {
		if ( ! current_user_can( 'edit_posts' ) || ! $bar->get_node( 'new-content' ) ) {
			return;
		}

		$href = admin_url( 'post-new.php?post_type=' . Chapters::POST_TYPE );
		if ( $book_id ) {
			$href = add_query_arg( 'sheaf_book', $book_id, $href );
		}

		$bar->add_node(
			[
				'parent' => 'new-content',
				'id'     => 'new-' . Chapters::POST_TYPE,
				'title'  => __( 'Chapter', 'sheaf' ),
				'href'   => $href,
			]
		);
	}

	/**
	 * Rename the core "Edit Page" node to "Edit Book", keeping its link. Only the
	 * label changes; if core did not add the node (no edit rights) there is
	 * nothing to rename.
	 */
	private static function relabel_edit_book( \WP_Admin_Bar $bar ): void {
		if ( ! $bar->get_node( 'edit' ) ) {
			return;
		}
		// add_node() merges with the existing node, so passing only the title keeps
		// its href, meta, and placement intact.
		$bar->add_node(
			[
				'id'    => 'edit',
				'title' => __( 'Edit Book', 'sheaf' ),
			]
		);
	}

	/**
	 * Add an "Edit Chapter" node while viewing a chapter (core adds none, since
	 * chapters are kept out of the admin bar).
	 */
	private static function add_edit_chapter( \WP_Admin_Bar $bar, int $chapter_id ): void {
		if ( ! current_user_can( 'edit_post', $chapter_id ) ) {
			return;
		}
		$link = get_edit_post_link( $chapter_id );
		if ( ! $link ) {
			return;
		}
		$bar->add_node(
			[
				'id'    => 'edit',
				'title' => __( 'Edit Chapter', 'sheaf' ),
				'href'  => $link,
			]
		);
	}
}
