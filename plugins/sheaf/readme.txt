=== Sheaf ===
Contributors: sheaf
Requires at least: 7.0
Requires PHP: 8.3
Stable tag: 0.9.0
License: GPLv2 or later

Publish novels as one chapter per post, organised into books and series.

== Description ==

Sheaf lets a novelist publish a book as a series of posts — one chapter each —
gathered under a book "main page". Books and series are ordinary, hand-authored
WordPress Pages (so a series can also hold non-book content), and their page
hierarchy gives you nesting, URLs, and breadcrumbs for free.

Model
* Chapter — the `sheaf_chapter` custom post type. Belongs to exactly one book
  (stored as the book Page's ID). Reading order uses the core "Order" field
  (menu_order), never the chapter number, so prologues and interludes sort
  naturally.
* Book / Series / World — ordinary hierarchical Pages you build yourself.
* Chapter URLs nest under the book page, e.g.
  /novels/long-war/embers/13-resignations.

Display (all opt-in except the chapter breadcrumb)
* `[sheaf_toc]` / "Sheaf: Table of Contents" block — a book's chapters in order.
  Auto-detects the book on a book page or chapter; override with
  `[sheaf_toc book="123"]` or `[sheaf_toc book="page-slug"]`. The list marker and
  the per-chapter info (reading time, word count, or page number) are set per
  book under the book's Display settings.
* `[sheaf_breadcrumbs]` / "Sheaf: Breadcrumbs" block — the hierarchy trail.
* Single chapter views show breadcrumbs and chapter navigation automatically.
  Under the book's Display settings, each book chooses where its breadcrumbs sit
  (top / bottom / both / none) and how its chapter navigation looks (back-to-book,
  previous/next, chapter titles, or a full-contents drop-down). Both remain
  filterable via `sheaf_auto_breadcrumbs` / `sheaf_auto_chapter_nav`.
* Style Sets — reusable styling activated per book. Editor Styles are named
  inline/paragraph styles an author applies while writing; Page Styles are CSS
  scoped to a book's chapters that restyle them wholesale (body font, paragraph
  indent and spacing, chapter-title case, and so on) with nothing to apply by
  hand. Activate a set on a Book to style that book's chapters. See
  docs/page-styles.md.
* Import — bring chapters in from Word (.docx): one file per chapter, or split a
  whole-book file into chapters at page breaks, section breaks, headings, a line
  of symbols (a scene-break glyph), or blank gaps. See docs/whole-book-import.md.

== Roadmap ==
* Addressable text versions that comments can reference and link to.

Full-book scrolling (arrive at any chapter, scroll through the whole book in
place) has shipped as a per-book Full-book scrolling setting. Theme and custom-template
authors: see docs/full-book-scrolling.md for the template tags, filters, data
model, and CSS classes.

== Changelog ==

= 0.9.0 =
* Page Styles: each style set can now carry free-form CSS scoped to the books
  that activate it, restyling every chapter wholesale — body font, paragraph
  indent and spacing, chapter-title case, and so on — with nothing to apply by
  hand. The Style Sets screen gains Editor Styles / Page Styles tabs, a
  two-column editor with a live preview, and targeted blocks for per-scenario
  styling; page styles also preview in the chapter editor. See docs/page-styles.md.
* Whole-book import: import a whole book from a single Word (.docx) file and split
  it into chapters at the breaks you choose — page breaks, Word section breaks,
  Heading 1-3, a line of symbols (a scene-break glyph), or three or more blank
  lines — with a preview to fix titles and drop front matter before creating the
  drafts. See docs/whole-book-import.md.
* The Sheafs menu now offers a single "Chapters" item — opening the chapter list,
  where WordPress's bulk actions can publish many imported drafts at once — in
  place of the separate "New Chapter" and "Import Chapters" items.
* Faster, lighter Word import: the .docx reader parses large manuscripts several
  times faster, and import sessions are stored outside the database so very large
  books no longer fail to preview.

= 0.8.0 =
* Reorganised the per-book Book settings screen. Display settings now control
  the table-of-contents list marker (a preset, a CSS keyword, or a quoted
  bullet such as "⁂") and per-chapter info (reading time, word count, or page
  number), where the breadcrumb trail sits (top / bottom / both / none), and the
  chapter navigation placement and style (back-to-book, previous/next, chapter
  titles, or a full table-of-contents drop-down).
* Full-book scrolling is now a single toggle in its own section. A reader can
  still drop to one chapter at a time, so the chapter navigation applies there
  too.
* Every book setting auto-saves as you change it — the Save button is gone,
  matching the chapter-reorder and style-set screens.

= 0.7.1 =
* Moved the repository to the Bivia organization; point the built-in update
  checker and release links at the new location.

= 0.7.0 =
* Full-book scrolling: arrive at any chapter and scroll through the whole book
  in place, with a per-book Display setting for chapter titles, chapter/section
  breaks, pseudo page numbers, and a floating table of contents.
* Fragment delivery over the canonical chapter URL (so views still count),
  bounded-memory load/unload, book-level heading/breadcrumbs, and a remembered
  single-chapter opt-out.
* Public template tags and filters plus docs/full-book-scrolling.md for themes
  and custom templates.

= 0.1.0 =
* Initial scaffold: chapter CPT, book linkage + ordering, nested URLs,
  breadcrumbs, and the TOC/breadcrumbs shortcodes and blocks.
