=== Sheaf ===
Contributors: sheaf
Requires at least: 7.0
Requires PHP: 8.3
Stable tag: 0.12.0
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
  Under the book's Display settings, each book chooses what its breadcrumbs
  contain (none; the full trail ending at the book title; the book with a
  "pg X of Y" position; book and chapter; the full trail — offered only when the
  book sits under a parent; or the full trail ending in a chapter drop-down) and
  where they sit (above the chapter title as a
  small eyebrow, or top / bottom / both of the content), and likewise what
  its chapter navigation contains (none, back-to-book, previous/next, chapter
  titles, or a full-contents drop-down) and where it sits. Either is switched off
  by choosing a style of "None". The breadcrumb styles are previewed with the
  book's own titles as you choose. Both remain filterable via
  `sheaf_auto_breadcrumbs` / `sheaf_auto_chapter_nav`.
* Style Sets — reusable styling activated per book. Editor Styles are named
  inline/paragraph styles an author applies while writing; Page Styles are CSS
  scoped to a book's chapters that restyle them wholesale (body font, paragraph
  indent and spacing, chapter-title case, and so on) with nothing to apply by
  hand. Activate a set on a Book to style that book's chapters. See
  docs/page-styles.md.
* Import — bring chapters in from Word (.docx): one file per chapter, or split a
  whole-book file into chapters at page breaks, section breaks, headings, a line
  of symbols (a scene-break glyph), or blank gaps. See docs/whole-book-import.md.

The byline, date, and related-post lists around a chapter come from your theme,
not from Sheaf. To drop them, give chapters their own template in the Site
Editor — everything Sheaf adds travels with the content and keeps working. See
docs/chapter-template.md.

== Roadmap ==
* Addressable text versions that comments can reference and link to.

Full-book scrolling (arrive at any chapter, scroll through the whole book in
place) has shipped as a per-book Full-book scrolling setting. Theme and custom-template
authors: see docs/full-book-scrolling.md for the template tags, filters, data
model, and CSS classes.

== Changelog ==

= 0.12.0 =
* The front-end admin toolbar now carries book-aware navigation. While viewing
  a Page or a chapter, the "+ New" menu gains a "Chapter" item that opens the
  editor with the book already selected — the Page itself, or the chapter's
  book. The toolbar's "Edit Page" reads "Edit Book" on a book, and an "Edit
  Chapter" link appears while reading a chapter.

= 0.11.0 =
* Two new breadcrumb styles: the book title with the chapter's "pg X of Y"
  position, and the full trail ending at the book title (no chapter after it).
* New "Above the title" breadcrumb placement — a small eyebrow over the chapter
  heading (book, and the series above it, in quiet type), on block themes.
* The full-trail option is hidden for a top-level book, where it could not be
  told apart from "Book and chapter".
* The breadcrumb page position is now `<em>pg X<span> of Y</span></em>`, so a
  style set can restyle or hide the meta; its leading comma is gone.
* Full-book scrolling now hides the breadcrumb trail as well as the chapter
  navigation while active — the page already stands for the whole book.
* Applied inline and block styles now out-specify a book's page-style baseline,
  so a deliberately applied style wins without needing !important.

= 0.10.0 =
* Breadcrumbs style: each book now chooses what a chapter's breadcrumb trail
  contains — the book and chapter, the full trail, or the full trail ending in a
  drop-down of the book's chapters. Pick from previews rendered with the book's
  own titles, so you choose the trail itself rather than a description of it.
* Switching the breadcrumbs or the chapter navigation off is now a style of
  "None", and the placement fields offer only top, bottom, and top and bottom.
  Books that had either switched off stay off.
* Breadcrumbs now come before the chapter navigation at the top of a chapter as
  well as the bottom, instead of the two reading in opposite orders.
* The book screen groups its chapter links under the Chapters heading and adds
  "Bulk Edit Chapters", opening the chapter list filtered to that book.
* New docs/chapter-template.md: the byline, date, and related posts around a
  chapter come from the theme, not from Sheaf. It covers giving chapters their
  own template in the Site Editor, and what Sheaf keeps supplying when you do.

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
