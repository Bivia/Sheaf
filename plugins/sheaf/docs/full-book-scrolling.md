# Full-book scrolling — developer reference

Sheaf can turn a chapter page into a continuous scroll through the whole book:
the reader arrives at any chapter's canonical URL, and later/earlier chapters
load in place as they scroll toward the book's real start and end. It is
**progressive enhancement** — the plugin owns the behaviour and the data; your
theme owns the presentation. Each scrolled-in chapter is rendered by the theme,
so it inherits the theme's typography and content width.

This document is for theme and custom-template authors. For how it works for
authors/editors, see the per-book **Display settings** on the book's admin page.

- [Enabling it](#enabling-it)
- [How delivery works](#how-delivery-works)
- [The data model (`window.SheafScroll` / `sheaf_scroll_spine()`)](#the-data-model)
- [Template tags](#template-tags)
- [Filters](#filters)
- [CSS class reference](#css-class-reference)
- [Building your own reader](#building-your-own-reader)

## Enabling it

Per book, on the book Page's admin screen, under **Display settings** →
*Enable full-book scrolling*. The other options there (chapter titles, chapter
and section breaks, page numbers, full table of contents) shape what the bundled
reader shows. Nothing renders differently until the box is checked.

## How delivery works

A chapter with the feature on is served normally, but its body is wrapped in a
marker region:

```html
<div class="sheaf-chapter" data-chapter-id="132" data-page-start="13">…</div>
```

When the reader needs an adjacent chapter, it re-fetches that chapter's **real
canonical URL** with a request header:

```
X-Sheaf-Fragment: 1
```

The server then returns just the `.sheaf-chapter` region (no theme chrome), with
`X-Sheaf-Fragment: 1` on the response and `Vary: X-Sheaf-Fragment` so caches
don't mix the two. If the book doesn't have the feature on, the fragment request
gets `204 No Content` — a signal to fall back to normal navigation.

Because each load hits the canonical URL, server-log analytics still count every
chapter view.

## The data model

The reader is handed the whole book's structure up front. With the bundled
reader it's the global `window.SheafScroll`; from PHP it's
[`sheaf_scroll_spine()`](#template-tags). Same shape either way:

```jsonc
{
  "bookId":     114,
  "bookTitle":  "Ashfall",
  "bookUrl":    "https://example.com/novels/long-war/ashfall/",
  "bookCrumbs": "<nav class=\"sheaf-breadcrumbs\">…</nav>", // book-level trail
  "currentId":  132,          // the entry chapter this page loaded as
  "totalPages": 21,           // estimated pseudo-pages for the whole book
  "settings": {
    "chapterTitles":        true,
    "chapterBreak":         "hr_page_break",
    "chapterBreakHtml":     "<svg …>…</svg>",  // author divider markup, verbatim
    "specialSectionBreaks": false,
    "sectionBreak":         "page_break",
    "sectionBreakHtml":     "",
    "showPageNumbers":      true,
    "showFullToc":          true,
    "sidebar":              true               // render the bundled sidebar?
  },
  "chapters": [               // reading order (menu_order)
    {
      "id":        129,
      "title":     "Prologue",
      "url":       "https://example.com/…/prologue/",
      "words":     1200,
      "minutes":   5,         // estimated reading time
      "startPage": 1,
      "pages":     4,
      "isSection": false      // a section marker spans 0 pages
    }
    // …
  ]
}
```

`chapterBreak` / `sectionBreak` are one of: `none`, `blank_lines`, `page_break`,
`hr`, `hr_page_break`. The `*Html` fields carry the author's divider markup for
the `hr*` choices and are stored **verbatim** (author-trusted, `edit_posts`).

## Template tags

Global functions, safe to call in the loop on a chapter. The book-scoped ones
default to the current chapter's book when given `0`.

| Function | Returns |
| --- | --- |
| `sheaf_is_scroll_reader( $post = null )` | `bool` — is this chapter in a scroll-enabled book? |
| `sheaf_scroll_book_id( $post = null )` | `int` — the chapter's book Page id (0 if none) |
| `sheaf_scroll_spine( $book_id = 0, $chapter_id = 0 )` | `array` — the payload above (empty if no scroll book) |
| `sheaf_book_page_map( $book_id = 0 )` | `array` — `total_pages`, `total_words`, per-chapter `start_page/pages/words/is_section` |
| `sheaf_book_pages( $book_id = 0 )` | `int` — the book's estimated total pages |
| `sheaf_chapter_pages( $post = null )` | `int` — a chapter's page span (0 for a section) |

```php
if ( sheaf_is_scroll_reader() ) {
    printf( 'Page %d of %d', sheaf_chapter_pages(), sheaf_book_pages() );
}
```

## Filters

| Filter | Signature | Purpose |
| --- | --- | --- |
| `sheaf_words_per_page` | `int $words` | Words per pseudo-page (default 300). |
| `sheaf_scroll_spine` | `array $spine, int $book_id, int $chapter_id` | Mutate the data payload (add fields, adjust chapters). |
| `sheaf_scroll_reader` | `bool $enabled, int $book_id, int $chapter_id` | Return `false` to suppress the bundled `reader.js`/`reader.css` and build your own. |
| `sheaf_scroll_sidebar` | `bool $show, int $book_id` | Return `false` to keep the scroll engine but drop the bundled position sidebar. |

Related existing filters: `sheaf_words_per_minute` (reading-time rate),
`sheaf_auto_breadcrumbs`, `sheaf_auto_chapter_nav`.

```php
// Roll your own reader UI: keep fragment delivery, drop the bundled JS.
add_filter( 'sheaf_scroll_reader', '__return_false' );
```

## CSS class reference

Everything the reader emits, for styling or for building your own. Server-side
markup is stable API; the rest is what the bundled `reader.js` produces.

**Server-rendered (always present when the feature is on):**

| Selector | Meaning |
| --- | --- |
| `.sheaf-chapter` | A chapter's body region. `data-chapter-id`, `data-page-start`. The fragment endpoint returns exactly this element. |

**Added by the bundled reader:**

| Selector | Meaning |
| --- | --- |
| `body.sheaf-scroll-active` | Set while the reader is running. |
| `.sheaf-slot` | Wraps each chapter's place in the scroll. `data-index`, `data-chapter-id`, `data-loaded` (`1`/`0` — an unloaded slot is a height-preserving spacer). |
| `.sheaf-chapter-title` | An in-flow chapter title (`--section` variant for section markers). |
| `.sheaf-break` | The gap before a chapter: `--none`, `--blank-lines`, `--page-break`, `--hr`, `--hr-page-break`. |
| `.sheaf-rail` | The position sidebar (`--hidden` when the margin is too narrow). |
| `.sheaf-rail__book` | Book title (links to the book). |
| `.sheaf-rail__page` | "Page X of Y". |
| `.sheaf-rail__here` / `__chapter` / `__time` | Current chapter + time-to-next (when the full TOC is off). |
| `.sheaf-rail__toc` / `__toc-item` | Floating TOC; the current entry gets `.is-current` (`--section` variant for sections). |
| `.sheaf-rail__toggle` | "Read one chapter at a time" control. |
| `.sheaf-view-toggle` / `__btn` | "Read the whole book" control on an opted-out plain chapter. |

The reader also hides `.sheaf-chapter-nav` (Sheaf's single-chapter prev/next)
while active, since the scroll makes it redundant.

## Building your own reader

The stable contract is the `.sheaf-chapter` wrapper, the fragment protocol, and
the spine data — not the bundled `reader.js`. To build a custom two-column
reader:

1. Branch on `sheaf_is_scroll_reader()` in your template.
2. Disable the bundled reader: `add_filter( 'sheaf_scroll_reader', '__return_false' )`
   (or keep it and only replace the sidebar with `sheaf_scroll_sidebar`).
3. Bootstrap your JS with the structure from `sheaf_scroll_spine()` — print it
   as JSON in your template.
4. As the reader scrolls, fetch adjacent `chapters[].url` with the
   `X-Sheaf-Fragment: 1` header and splice the returned `.sheaf-chapter` into
   your layout. Preserve scroll position across insertions above the viewport.
5. Update the URL to the visible chapter with `history.replaceState()` so views
   still count and the address stays shareable.

The reader is opt-out-remembered per book in `localStorage` under
`sheaf-scroll-optout-<bookId>`; honour that key if you want your reader to share
the bundled reader's preference.
