# Finance Fact Library: design style guide

How the site looks and how to build to it. This covers visual design only. For writing rules (spelling, dates, money,
tone) see `docs/house-style/`. Written 25 November 2026 from the live code: `src/input.css` (theme and custom classes),
`includes/header.php`, `includes/footer.php`, `public/fact.php` and `renderExplanation()` in `includes/functions.php`.
If this file and the code disagree, the code wins: fix this file.

**Restyle in progress (from 25 November 2026):** the site is being rebuilt on daisyUI with light and dark themes (see
`docs/restyle-plan.md`). This guide is updated in the same commit as each restyle step. Until a section is rewritten it
describes the old navy-and-paper design and is marked as such.

## Principles

*Rewritten for the restyle (Step 1, 25 November 2026).*

- **A reference library.** Calm, plain and easy to scan. Standard daisyUI components, used as they come, with no custom
  component CSS unless a pattern can't be built from daisyUI and Tailwind layout classes. Anything custom is named, commented
  and listed in this guide.
- **The figure is the hero.** A number is the biggest thing on its card, with its source and dates small underneath. Trust
  comes from showing the source, "since" date and "checked" date on every figure.
- **Theme tokens only.** Colour comes from daisyUI tokens, never fixed hex values or `bg-white`, so the palette can be
  changed in one place later and light and dark both work.
- **Fully responsive.** Mobile first, from a 320px phone up to a wide desktop. No horizontal page scroll, tap targets at
  least 44px, wide tables scroll inside their own container.
- **Light and dark.** Both are first-class. Every component is checked in both.
- **Same on every country page.** Country pages differ in content, never in layout or colour.

## Stack

*Rewritten for the restyle (Step 1). The compiled setup below is applied in Step 2: until then the code still has the old
custom theme.*

Tailwind CSS 4 plus daisyUI 5, compiled by the Tailwind CLI.

- Source: `src/input.css`. Output: `public/assets/css/tailwind.css` (minified, committed).
- After any change to a template class or `src/input.css`, run `npm run build` and commit the compiled CSS. Tailwind only
  includes classes it can see in the templates, so a class that isn't in the compiled file will not show.
- `assetUrl()` adds the file's modified time to the CSS and JS addresses, so a rebuild is never missed by caches.
- **Themes: daisyUI's built-in `light` and `dark`**, loaded as `themes: light --default, dark --prefersdark`. The device
  setting picks the theme on a first visit, and a toggle in the header lets the visitor override it and remembers the choice.
- **Changing the palette later:** add an override block in `src/input.css` named `light` (and one named `dark`) using
  `@plugin "daisyui/theme"`, set only the tokens that change (primary, secondary and so on), then rebuild. Templates need no
  edits, because they only use tokens.
- **Changing fonts later:** the three families are mapped in the `@theme` block in `src/input.css` and loaded in
  `includes/header.php`. Change them in those two places only.

## Colour

*Rewritten for the restyle (Step 1). The palette is daisyUI's stock `light` and `dark`, kept as a placeholder to be changed
later.*

Use tokens only (`bg-base-100`, `text-base-content`, `btn-primary`, `border-base-300`). Each colour has a `-content` token for
text that sits on it. Never use a raw hex value, `bg-white` or `text-black`.

| Role | Token | Use |
|---|---|---|
| Page and card surfaces | `base-100`, `base-200`, `base-300` | Page background, cards, and progressively darker (light) or deeper (dark) surfaces |
| Text and borders | `base-content` | Body text, headings; soften with opacity |
| Brand colour | `primary` | Main buttons, key links and highlights |
| Second brand colour | `secondary` | Secondary buttons and labels |
| Accent | `accent` | Occasional emphasis, not body text |
| Neutral | `neutral` | Dark UI surfaces such as a tooltip |
| Status | `info`, `success`, `warning`, `error` | Alerts and badges that mean what they say |

Current stock values (from `node_modules/daisyui/theme/light.css` and `dark.css`, in OKLCH): the primary is an indigo
(`oklch(45% 0.24 277)` light, `oklch(58% 0.233 277)` dark), secondary a pink (`oklch(65% 0.241 354)`), accent a teal
(`oklch(77% 0.152 182)`). Light surfaces are white to light grey; dark surfaces are a dark blue-grey. Both themes use 0.5rem
box and selector radii and 0.25rem field radii.

Rules:
- **Don't give a hue a meaning.** The palette will change, so never write "the pink one" in a template or rely on a colour
  to mean something. Use the role (`primary`, `secondary`) instead.
- Red and green are only for errors and success, never to say a number rose or fell: pages state facts, not judgements.
- Meaning is never carried by colour alone.
- Check contrast again whenever a token is overridden, in both themes.

## Typography

*Rewritten for the restyle (Step 1). Fonts are unchanged from the old design and may change later.*

Three families, loaded from Google Fonts in `includes/header.php` and mapped in the `@theme` block of `src/input.css`:

| Family | Tailwind | Weights | Use |
|---|---|---|---|
| Fraunces (serif) | `font-display` | 400, 600, 700 | Site name, page and section headings, figure values |
| IBM Plex Sans | `font-sans` (the body default) | 400, 500, 600 | Body text, navigation, tables, notes |
| IBM Plex Mono | `font-mono` | 500 | Small uppercase labels and dates |

The type scale, the label style and the opacity steps below are the old design's. They are replaced as each page type is
restyled (Steps 3 to 5); only the three families above are current.

*Pre-restyle, replaced in Steps 3 to 5:*

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

*Pre-restyle, replaced in Steps 3 to 6. The responsive and light and dark requirements above apply to all new work.*

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

*Pre-restyle, replaced in Steps 3 to 6. The responsive and light and dark requirements above apply to all new work.*

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

*Pre-restyle, replaced in Steps 3 to 6. The responsive and light and dark requirements above apply to all new work.*

Only two things move: figure cards lift on hover (0.15s ease) and links change colour (`transition-colors`). No
animation on load, no scroll effects, no carousels. Respect reduced motion if anything else is ever added.

## Accessibility

*Pre-restyle, replaced in Steps 3 to 6. The responsive and light and dark requirements above apply to all new work.*

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
