# The chapter template — recipe

A chapter page is built by two things: **Sheaf** supplies the chapter and the
book-aware chrome around it, and **your theme** supplies the page furniture — the
byline ("Written by …"), the publication date, categories, related posts. Sheaf
never touches the theme's furniture, so the way to change it is to give chapters
their own template.

This is worth doing for most novels. A theme's single-post template is built for
blog posts, so it tends to show a byline, a date, and a "More posts" list — all
of which are noise on a chapter, and some of which render as empty leftovers
because chapters have no categories.

- [What Sheaf adds, and what the theme adds](#what-sheaf-adds-and-what-the-theme-adds)
- [Create a chapter template](#create-a-chapter-template)
- [The one block you must keep](#the-one-block-you-must-keep)
- [What is safe to remove](#what-is-safe-to-remove)
- [Full-book scrolling and the template](#full-book-scrolling-and-the-template)
- [Classic themes](#classic-themes)

## What Sheaf adds, and what the theme adds

Everything Sheaf puts on a chapter page travels with the **content**, not with
the template:

| Comes from | What |
| --- | --- |
| Sheaf, via the content | breadcrumbs, chapter navigation, the full-book scrolling region |
| Sheaf, via the `<body>` class | page styles (a book's active style sets) |
| Your theme's template | title, byline, date, categories, comments, related posts, header and footer |

That split is what makes a custom template safe: you can rebuild the page around
the chapter however you like, and the breadcrumbs, navigation, page styles, and
scrolling all keep working — because none of them live in the template.

## Create a chapter template

In the admin, go to **Appearance → Editor → Templates**, press **+** (Add New
Template), and choose **Single item: Chapter**. When asked which chapters it
applies to, choose **all** — that saves the template as `single-sheaf_chapter`,
and WordPress then uses it for every chapter on the site, in place of the theme's
single-post template.

The quickest way to start is to open the theme's existing single template, note
what it contains, and build the chapter template with the same header and footer
template parts, the post title, and the post content — leaving out the rest.

Nothing here is per-book: it is one template for all chapters, site-wide. Styling
that varies from book to book belongs in page styles instead (see
[page-styles.md](page-styles.md)).

## The one block you must keep

Keep the **Post Content** block. Sheaf's breadcrumbs, chapter navigation, and the
region the full-book reader splices are all added *to the content*, so they
appear wherever that block is — and they disappear entirely if you remove it.

Everything else is yours to arrange. Note that the chapter **title** comes from
the theme's Post Title block: drop it and chapters render untitled. (Full-book
scrolling has its own chapter-title setting, which is separate from this.)

If you want readers to comment on chapters, keep the theme's **Comments** block
too.

## What is safe to remove

On a novel, all of these are usually worth removing:

- **The byline** — author name, "Written by", and similar labels.
- **The date** — a chapter's publication date is rarely meaningful to a reader.
- **Categories and tags** — chapters have no taxonomies, so these render empty.
  This is why a theme byline can come out as a dangling "in" with nothing after
  it.
- **Previous/next post links** the theme supplies. Sheaf deliberately suppresses
  the theme's idea of an adjacent post (which would be some other book's chapter,
  ordered by date), so these render nothing useful. Sheaf's own chapter
  navigation, in reading order, replaces them — configure it under the book's
  **Display settings**.
- **"More posts" / related posts** lists, which pull in unrelated posts.

## Full-book scrolling and the template

The template affects only the first page a reader lands on. As they scroll, the
reader fetches each further chapter as a body-only fragment that Sheaf serves
directly, before any template runs — so those chapters are never rendered through
the chapter template, and the theme's furniture never repeats down the page.

This means you can safely design the chapter template as a *single-chapter* view
(with the title and comments and whatever else) without worrying about it
duplicating on a scrolling book.

## Classic themes

This recipe needs a block theme. A classic theme has no Site Editor, and prints
its byline directly from PHP with no way for a plugin to intercept it. The
fallback there is page styles: CSS scoped to a book's chapters can hide the
theme's byline markup, though the selectors will be specific to that theme.
