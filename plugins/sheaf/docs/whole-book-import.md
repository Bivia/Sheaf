# Whole-book import — reference

Most authors write one chapter per file, and Sheaf imports those the obvious way:
each `.docx` becomes one draft chapter. But many manuscripts are a *whole book in
one file*. Sheaf can split such a file into chapters at the breaks you choose.

- [The two modes](#the-two-modes)
- [Split signals](#split-signals)
- [How the split behaves](#how-the-split-behaves)
- [Chapter titles](#chapter-titles)
- [Large manuscripts](#large-manuscripts)
- [Sharing a manuscript for support](#sharing-a-manuscript-for-support)

## The two modes

On **Sheafs → Import Chapters**, under **Chapters**:

- **Each file is one chapter** — the default; one `.docx` → one draft.
- **Split each file into chapters** — reveals a checklist of break signals; one
  `.docx` → many drafts.

Either way you land on the same preview screen, where you can fix every chapter's
title and delete any you don't want before creating the drafts.

## Split signals

Tick any combination; a new chapter starts at **each** occurrence of **any**
ticked signal:

| Signal | What it matches |
| --- | --- |
| **Page break** | A page break — Word's `Ctrl+Enter`, or "page break before" on a paragraph. |
| **Section break (Word)** | Word's structural *Section Break* (Layout → Breaks → Section Breaks). |
| **Heading 1 / 2 / 3** | A paragraph in Word's Heading 1, 2 or 3 style. The heading's text becomes the chapter title. |
| **A line of symbols only** | A line with no letters or numbers — a scene-break glyph such as `•••`, `***`, or `* * *`. |
| **Three or more blank lines** | A gap of three or more empty paragraphs. |

If your chapters are separated by a page break (a very common manuscript
convention), tick just **Page break**. If scenes within a chapter are marked with
`•••` and you want each scene as its own chapter too, add **A line of symbols
only**.

## How the split behaves

- **Consecutive breaks collapse.** A page break followed by blank lines followed
  by a heading counts as **one** break, not three — so you never get empty
  chapters between markers.
- **Front matter becomes the first chapter.** Anything before the first break — a
  title page, a table of contents — is gathered into chapter one, which you can
  delete on the preview screen.
- **Markers are consumed.** A symbol line or a heading that starts a chapter is
  not repeated in the chapter body.

## Chapter titles

- If a chapter begins with a **heading**, the heading text is its title.
- Otherwise Sheaf promotes the chapter's **first paragraph** to the title and
  removes it from the body. This suits manuscripts whose chapter titles are plain
  text ("Chapter One", a title, a subtitle) rather than Word headings.
- A chapter with no usable title falls back to the file name plus its number.

Every title is editable on the preview screen, so fix any the detection gets
wrong before creating the drafts.

## Large manuscripts

Whole-book files are big — a 200,000-word novel is ~12 MB of internal XML. Sheaf
parses one in roughly two seconds and raises the request's memory and time limits
during import so a large upload does not time out. The detected chapters are held
for the preview step and created as ordinary draft chapters, appended to the end
of the target book's reading order.

## Sharing a manuscript for support

If you need to share a manuscript to reproduce an import problem without exposing
the real text, the repository ships `scramble-docx.py`: it randomises every
letter and digit — in the body, headers/footers, and hidden metadata — while
leaving all formatting (page breaks, section breaks, heading styles, symbol
lines, blank paragraphs) exactly intact. Run it locally:

```
python3 scramble-docx.py "My Manuscript.docx"
```

It writes `My Manuscript.scrambled.docx`, which imports and splits identically but
is unintelligible.
