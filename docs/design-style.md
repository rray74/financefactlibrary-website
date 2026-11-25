# Finance Fact Library: design style guide

How the site looks and how to build to it. This covers visual design only. For writing rules (spelling, dates, money,
tone) see `docs/house-style/`. Written 25 November 2026 from the live code: `src/input.css` (theme and custom classes),
`includes/header.php`, `includes/footer.php`, `public/fact.php` and `renderExplanation()` in `includes/functions.php`.
If this file and the code disagree, the code wins: fix this file.

**Restyle in progress (from 25 November 2026):** the site is being rebuilt on daisyUI with light and dark themes (see
`docs/restyle-plan.md`). This guide is updated in the same commit as each restyle step. Until a section is rewritten it
describes the old navy-and-paper design and is marked as such.

## Principles

- **A reference library, not a SaaS product.** The look is an index card on paper in ink: warm off-white, navy ink, hard
  edges, flat offset shadows. No gradients, blur shadows, glass, rounded pill buttons or stock illustration.
- **The figure is the hero.** A number is the biggest thing on its card, set in the display serif, with its source and dates
  small underneath. Trust comes from showing the source, "since" date and "checked" date on every figure.
- **Quiet and readable.** Generous white space, one column of text, hierarchy through size and weight, not colour.
- **Same on every country page.** Country pages differ in content, never in layout or colour.

## Stack

Tailwind CSS 4 plus daisyUI 5, compiled by the Tailwind CLI. The theme is a single custom daisyUI theme,
`financefactlibrary` (set on `<html data-theme="financefactlibrary">`), with `themes: false` so no stock theme is bundled.

- Source: `src/input.css`. Output: `public/assets/css/tailwind.css` (minified, committed).
- After any change to a template class or `src/input.css`, run `npm run build` and commit the compiled CSS. Tailwind only
  includes classes it can see in the templates, so a class that isn't in the compiled file will not show.
- `assetUrl()` adds the file's modified time to the CSS and JS addresses, so a rebuild is never missed by caches.
- Light only. There is no dark mode (`color-scheme: light`). Don't add `dark:` classes.

## Colour

Always use the theme tokens (`text-primary`, `bg-base-100`, `border-primary`), never raw hex in a template. Each colour has a
matching `-content` colour for text that sits on top of it; keep both when changing a theme value.

| Role | Token | Hex | Use |
|---|---|---|---|
| Paper (page background) | `base-100` | `#f7f5f0` | Page background, `bg-base-100` on `<body>`, header and footer |
| Paper, darker | `base-200`, `base-300` | `#eeebe3`, `#e0dcd0` | Second surfaces for daisyUI components only |
| Ink navy | `primary`, `neutral`, `base-content` | `#1b2a4a` | All body text, headings, borders, card shadow |
| Sage | `secondary`, `success` | `#6b8f71` | Small labels (breadcrumbs, dates, region tags), "more on" links |
| Mustard | `accent`, `warning` | `#c9a227` | Link hover only |
| Info blue | `info` | `#3b5a87` | Informational alerts |
| Brick red | `error` | `#b3452c` | Errors only |
| Card white | n/a | `#ffffff` | Inside `.fact-card` only (the one hard-coded colour, in the CSS) |

Rules:
- Body text is navy on paper, never pure black or grey. Soften with opacity (see Text) rather than a grey colour.
- Sage is for small mono labels and secondary links. Don't use it for body text (too low a contrast at body size).
- Mustard is a hover colour, not a fill. Don't use it as text on paper.
- Red only for something wrong. Don't use red or green to say a number rose or fell: pages state facts, not judgements.
- Filled daisyUI components (buttons, alerts) already take the right text colour from the `-content` tokens.

## Typography

Three families, loaded from Google Fonts in `includes/header.php` and mapped in `@theme`:

| Family | Tailwind | Weights | Use |
|---|---|---|---|
| Fraunces (serif) | `font-display` | 400, 600, 700 | Site name, page and section headings, figure values |
| IBM Plex Sans | `font-sans` (the body default) | 400, 500, 600 | Body text, navigation, tables, notes |
| IBM Plex Mono | `font-mono` | 500 | Small uppercase labels, dates, the footer disclaimer |

Type scale in use (don't invent new steps without a reason):

| Element | Classes |
|---|---|
| Wordmark | `font-display text-2xl font-semibold tracking-tight` |
| Page title (h1) | `font-display text-4xl font-semibold mb-3` (hero variant: `text-4xl sm:text-5xl leading-tight`) |
| Section heading (h2) | `font-display text-2xl font-semibold mb-3` |
| Sub-heading (h3, in explanations) | `font-display text-xl font-semibold mt-6 mb-2` |
| Intro / lead paragraph | `text-lg opacity-80 max-w-xl` |
| Body | default size, `leading-relaxed` in explanations |
| Small print and notes | `text-sm`, `text-xs opacity-60` for source lines |
| Label (breadcrumb, table head, dates) | `font-mono text-xs uppercase tracking-wide` (+ `text-secondary` for breadcrumbs and dates) |
| Figure value | `fact-value font-display text-3xl font-semibold text-primary` (`text-2xl` in tight lists) |

Rules:
- Every number that should line up uses the `fact-value` class (tabular figures). Use it in table cells and figure cards.
- Headings are semibold serif, sentence case. Don't use `font-bold` or all-caps headings. All-caps is for mono labels only.
- Secondary text is made lighter with opacity, in steps: `opacity-80` (intro, supporting), `opacity-70` (captions, empty
  states), `opacity-60` (source lines), `opacity-50` (separators). Don't go below 50.
- One h1 per page. Headings go in order (h1, h2, h3), not chosen for size.

## Layout and spacing

- Page width: `max-w-4xl mx-auto px-6`, the same in the header, `<main>` and footer. Page padding is `py-10` for `<main>`.
- Reading width: prose and lists are capped, `max-w-2xl` for the explanation, `max-w-xl` for intros and short paragraphs,
  and `.article-body` at 68ch with a 1.7 line height. Never let body text run the full 4xl width.
- Vertical rhythm between page sections: `mb-8` to `mb-14` (`mt-14` before the explanation, worked examples and "What's
  changed"). Between paragraphs: `mb-4`. Between a heading and its content: `mb-2` to `mb-4`.
- Figure grids: `grid sm:grid-cols-2 gap-5` (or `gap-6` for larger cards). One column on a phone, two from `sm`. Three
  columns (`sm:grid-cols-3 gap-4`) only for small tiles.
- Mobile first. Every page must work at phone width with no horizontal page scroll. Wide tables sit in
  `overflow-x-auto`.
- Borders are 2px navy (`border-b-2 border-primary`) for structure (header, footer, table heads, section rules) and 1px at
  20% (`border-b border-primary/20`) for row dividers. Corners are square: don't add `rounded-*` except on the small region
  tag.

## Components

### Header and footer
- Header: paper, `border-b-2 border-primary`. The wordmark is left, the category links right (`text-sm`, `gap-6`,
  `hover:text-accent transition-colors`). The wordmark and links come from `SITE_NAME` and the country's categories: don't
  type them into a page.
- Footer: `border-t-2 border-primary`, trust links (About, How we check our figures, Disclaimer, Privacy), the copyright
  line and the independent-information statement in `font-mono text-xs opacity-70`. Both include files are shared by every
  page.

### Breadcrumb
`font-mono text-xs uppercase tracking-wide text-secondary mb-4`, levels joined by a `/` in `opacity-50`, links
`hover:text-accent`. It sits above the h1 and shows Category / Subcategory.

### Figure card (the signature component)
`<div class="fact-card p-5">`, defined in `src/input.css`:
- White background, 2px navy border, a solid 4px offset navy shadow (no blur), and the top-right corner clipped by 16px so
  it reads as an index card.
- On hover it lifts 2px up and left and the shadow grows to 6px. It's deliberately flat otherwise.
- Padding `p-4` to `p-6`. `p-5` is the standard. Use `p-6 block` when the whole card is a link.

Contents, in this order: label (`text-sm opacity-70`), optional region tag, value, optional context line
(`text-sm opacity-80`), then the source line (`text-xs opacity-60`: linked source name, "since" date, "checked" date,
separated by `&middot;`), then an optional "More on ... &rarr;" link in `text-xs text-secondary hover:text-accent`.

The clipped corner uses `clip-path`, so it also clips any outline or shadow that would cross the corner. Don't put anything
in the top-right 16px.

### Region tag
A small label for a figure that applies to one region: `font-mono text-xs uppercase tracking-wide ml-1 px-1.5 py-0.5
rounded bg-secondary/10 text-secondary`. The one place rounded corners are used.

### Links
- Inline links in body text and source lines: `underline hover:text-accent`.
- Navigation and breadcrumb links: no underline, `hover:text-accent`.
- External source links open in a new tab with `target="_blank" rel="noopener"`.

### Tables (worked examples)
`w-full text-sm border-collapse` inside `overflow-x-auto`. Header row: `border-b-2 border-primary text-left font-mono text-xs
uppercase tracking-wide`, cells `py-2 pr-4`. Body rows: `border-b border-primary/20`, cells `py-3 pr-4`; the first cell is
`font-semibold` and number cells use `fact-value`. Followed by a `text-xs opacity-60 mt-3` note. No zebra striping, no
cell borders, no coloured header.

### Change list ("What's changed")
A `space-y-3 max-w-2xl` list. Each item has a left rule `border-l-2 border-primary/30 pl-4`, a mono date label in
`text-secondary` above it, then one sentence with old and new values in `fact-value font-semibold`.

### Explanation text
Rendered by `renderExplanation()`: paragraphs `mb-4`, h3 sub-headings as above, lists `list-disc` (or `list-decimal`)
`pl-6 mb-4 space-y-1`. No images, callout boxes or tables inside explanations.

### Preview banner (admin and previews only)
`mb-6 border-2 border-dashed border-secondary px-4 py-3 text-sm`. A dashed border means "not public". Don't use it for
anything a visitor sees.

### daisyUI components
Used mainly in the admin editor (buttons, inputs, alerts). They take the theme colours automatically. On the public site,
prefer plain Tailwind with the patterns above. If a daisyUI component is added to a public page, check its text and
border contrast against the paper background first (inputs once had near-invisible borders until `base-content` was set).

## Motion

Only two things move: figure cards lift on hover (0.15s ease) and links change colour (`transition-colors`). No
animation on load, no scroll effects, no carousels. Respect reduced motion if anything else is ever added.

## Accessibility

- Navy on paper is high contrast (well above WCAG AA). Check any new colour pairing before using it, especially sage
  and mustard text on paper and white.
- Meaning is never carried by colour alone.
- Links are identifiable in body text (underlined). Keep visible focus outlines: don't remove them with `outline-none`
  unless a replacement is added.
- `<html lang>` comes from the country's locale (for example `en-GB`). Don't hard-code it.
- Images (if ever added) need alt text. The site currently uses no images or icons.
- Breadcrumbs, figures and tables use real `<nav>`, `<table>` and heading elements, and pages carry breadcrumb JSON-LD.

## Adding or changing design

1. Reuse a pattern above first. Most pages need no new classes.
2. If a pattern is missing, add it to `src/input.css` (a named class with a comment saying why) rather than repeating a long
   class string across templates.
3. Run `npm run build`, check the page at phone width and at desktop width on MAMP, and commit the compiled
   `public/assets/css/tailwind.css` with the change.
4. Update this file in the same commit.
5. Ask before changing the palette, fonts or the figure card: they're the site's identity and affect every page.
