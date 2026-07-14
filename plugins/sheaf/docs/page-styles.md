# Page styles — reference

A style set holds two kinds of styling:

- **Editor Styles** — named inline and block styles an author *applies* to words,
  sentences, or paragraphs from the chapter editor (each adds a class).
- **Page Styles** — free-form CSS that restyles **every chapter** in any book
  that activates the set, with nothing to apply by hand.

Page styles are the way to set a book's typography wholesale: the body font, the
paragraph indent and spacing, drop-cap-free first paragraphs, uppercase chapter
titles, and so on.

- [How activation works](#how-activation-works)
- [Writing page styles](#writing-page-styles)
- [Additional targeted blocks](#additional-targeted-blocks)
- [What you can and cannot write](#what-you-can-and-cannot-write)
- [Selectors to target](#selectors-to-target)
- [How it is emitted](#how-it-is-emitted)
- [In the editor](#in-the-editor)

## How activation works

Activate a set on a Book (the book Page's admin screen, or **Bulk assign** on the
Style Sets screen). Every chapter in that book then carries the set's body class:

```html
<body class="… sheaf-styleset-super-liminal">
```

Page styles are written *scoped to that class*, so they only ever affect the
chapters of books that opted in. A book can activate several sets; each adds its
own `sheaf-styleset-<slug>` class, and where two rules collide the more specific
selector (or the later set) wins.

The class is added to **chapters only** — never to the book's landing Page.

## Writing page styles

On the **Style Sets** screen, open a set and choose the **Page Styles** tab. The
base block is already scoped for you:

```css
body.sheaf-styleset-super-liminal {
    /* your CSS goes here */
}
```

You write only what goes *inside* the braces — the scoping wrapper is fixed and
added automatically. For example:

```css
.entry-content {
    font-family: "Times New Roman", serif;

    p {
        margin: 0;
        text-indent: 2.5em;
    }
    p:first-child {
        text-indent: 0;
    }
}
```

That sets the content font, removes the gaps between paragraphs and indents them,
and leaves the first paragraph flush. Nested rules like the `p { … }` above use
native CSS nesting — write them as you would in any modern stylesheet.

## Additional targeted blocks

**Add Additional Targeted Block** creates another scoped block whose selector
chains extra classes onto the set's body class. Type the class(es) into the small
field in the selector line:

```css
body.sheaf-styleset-super-liminal.sheaf-section {
    h1 { text-transform: uppercase; }
}
```

This one only affects *section* chapters. You can chain several classes, dot
separated — for example `sheaf-book-114.sheaf-section` to target one book's
sections. Useful hooks Sheaf already puts on the `<body>`:

- `sheaf-section` — a section (part-divider) chapter.
- `sheaf-book-<id>` — a specific book, by id.
- `sheaf-chapter-<id>` — a specific chapter, by id.
- readable path classes such as `sheaf-novels-long-war-embers`.

To remove an additional block, use its **Remove** button. A block left empty is
simply dropped when you save.

The **Live preview** beside the blocks shows your CSS applied to sample chapter
content, updated as you type. Each additional targeted block has a **Show in
preview** switch, so you can see a targeted scenario (say, a section chapter) on
its own or combined with others — the base block always shows.

## What you can and cannot write

Page styles are authored by administrators, and validation exists to catch
mistakes rather than to police a trusted author. On save, Sheaf:

- **requires balanced braces** — an unclosed or stray `}` drops that block, so a
  mistake can never leak past the scope or break the site stylesheet;
- **removes at-rules** — `@media`, `@import`, `@font-face` and the like are not
  supported here (use the set's font styling and the Font Library for fonts);
- strips anything that could close the `<style>` element or inject script.

`url(...)` **is** allowed, for background images and textures.

Anything dropped or changed is reported in a notice after saving.

## Selectors to target

Prefer Sheaf's own, theme-independent hooks so your styles survive a theme
switch:

- `.entry-content` — the chapter's content wrapper (paragraphs, headings, etc.).
- `.sheaf-chapter` — the chapter region wrapper (present with full-book
  scrolling).

Avoid theme-specific markup like `main div.entry-content` unless you know your
theme — the leading `main`/`div` may differ between themes.

## How it is emitted

All sets' page styles are compiled once into the single
`<style id="sheaf-style-sets">` block in the document head, each rule wrapped in
its `body.sheaf-styleset-<slug>[.extra…] { … }` scope. Because the CSS is gated
by the body class, it is inert on any chapter whose book has not activated the
set. See `Sheaf\Style_Sets::compile_page_css()` and
`Sheaf\Frontend::print_style_css()`.

## In the editor

When you edit a chapter whose book activates a set, Sheaf stamps the set's body
class (and an `entry-content` hook) onto the editor canvas and injects the page
CSS, so page styles preview live while you write. If you change the chapter's
book in the editor, save and reload to refresh — a notice reminds you.
