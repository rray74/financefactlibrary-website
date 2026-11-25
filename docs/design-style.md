# Finance Fact Library: design style guide

How the site looks and how to build to it. This covers visual design only. For writing rules (spelling, dates, money,
tone) see `docs/house-style/`. Written 25 November 2026 from the live code: `src/input.css` (theme and custom classes),
`includes/header.php`, `includes/footer.php`, `public/fact.php` and `renderExplanation()` in `includes/functions.php`.
If this file and the code disagree, the code wins: fix this file.

**Restyle in progress (from 25 November 2026):** the public site is now on daisyUI with light and dark themes (Steps 1 to 5
of `docs/restyle-plan.md`) and the admin editor (Step 6). Step 7 (final checks) is still to do. This guide is updated in the same commit as each restyle step.

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

*Rewritten for the restyle. Applied in the code in Step 2 (25 November 2026).*

Tailwind CSS 4 plus daisyUI 5, compiled by the Tailwind CLI.

- Source: `src/input.css`. Output: `public/assets/css/tailwind.css` (minified, committed).
- After any change to a template class or `src/input.css`, run `npm run build` and commit the compiled CSS. Tailwind only
  includes classes it can see in the templates, so a class that isn't in the compiled file will not show.
- `assetUrl()` adds the file's modified time to the CSS and JS addresses, so a rebuild is never missed by caches.
- **Themes: daisyUI's built-in `light` and `dark`**, loaded as `themes: light --default, dark --prefersdark`. The device
  setting picks the theme on a first visit, and a toggle in the header lets the visitor override it and remembers the choice.
- **No `data-theme` on `<html>`** by default: with none set, daisyUI follows the device. The script in `<head>` of
  `includes/header.php` adds `data-theme="light"` or `"dark"` only when the visitor has chosen one (stored in `localStorage`
  under `ffl-theme`, in try/catch, applied before first paint so there is no flash). Any button with the attribute
  `data-theme-toggle` switches to the other theme and keeps its label and `aria-pressed` right. Don't hard-code a
  `data-theme` anywhere else.
- **Changing the palette later:** add an override block in `src/input.css` named `light` (and one named `dark`) using
  `@plugin "daisyui/theme"`, set only the tokens that change (primary, secondary and so on), then rebuild. Templates need no
  edits, because they only use tokens.
- **Changing fonts later:** the three families are mapped in the `@theme` block in `src/input.css` and loaded in
  `includes/header.php`. Change them in those two places only.

## Colour

*Rewritten for the restyle (Step 1). The palette is daisyUI's stock `light` and `dark`, kept as a placeholder to be changed
later.*

Body text is `text-base-content` (set on `<body>`). `text-primary` is the brand colour, not the text colour. Use tokens only (`bg-base-100`, `text-base-content`, `btn-primary`, `border-base-300`). Each colour has a `-content` token for
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

*Rewritten through Step 5.* Type scale in use (don't invent new steps without a reason):

| Element | Classes |
|---|---|
| Wordmark | `font-display text-lg sm:text-2xl font-semibold tracking-tight` |
| Page title (h1) | `font-display text-4xl font-semibold mb-3` (hero variant: `text-4xl sm:text-5xl leading-tight`) |
| Section heading (h2) | `font-display text-2xl font-semibold mb-3` |
| Sub-heading (h3, in explanations) | `font-display text-xl font-semibold mt-6 mb-2` |
| Intro / lead paragraph | `text-lg opacity-80 max-w-xl` |
| Body | default size, `leading-relaxed` in explanations |
| Small print and notes | `text-sm`; `text-xs opacity-70` for source lines |
| Section label | `font-mono text-xs uppercase tracking-wide opacity-70` |
| Figure value | `font-display text-3xl font-semibold tabular-nums break-words` (`text-2xl` in small cards) |

Rules:
- Every number that should line up uses `tabular-nums` (table cells, figure values, counts).
- Headings are semibold serif, sentence case. Don't use `font-bold` or all-caps headings. All-caps is for section labels only.
- Secondary text is made lighter with opacity, in steps: `opacity-80` (intro, supporting), `opacity-70` (captions, labels,
  source lines, empty states). Don't go below 70 for text people need to read.
- One h1 per page. Headings go in order (h1, h2, h3), not chosen for size.

## Layout and spacing

*Rewritten for the restyle (Steps 3 to 5).*

- Page width: `max-w-4xl mx-auto px-4 sm:px-6`, the same in the header, `<main>` and footer. `<main>` padding is
  `py-8 sm:py-10`. Side padding is 16px on a phone and 24px from `sm` up.
- Breakpoints used: `sm` (640px) for two-column grids and the wider footer; `md` (768px) for the header's desktop links.
- Check every page at 320, 375, 768, 1024 and 1440px. No horizontal page scroll at any width. Wide tables sit in
  `overflow-x-auto`.
- Touch targets are at least 44px: use daisyUI's default button size (3rem), not `btn-sm`, for anything tapped on a phone.

- Reading width: prose and lists are capped, `max-w-2xl` for the explanation, `max-w-xl` for intros and short paragraphs.
  Never let body text run the full 4xl width. 
- Vertical rhythm between page sections: `mb-8` to `mb-14` (`mt-14` before the explanation, worked examples and "What's
  changed"). Between paragraphs: `mb-4`. Between a heading and its content: `mb-2` to `mb-4`.
- Figure grids: `grid sm:grid-cols-2 gap-4 sm:gap-5`. One column on a phone, two from `sm`.
- Borders are 1px, from tokens (`border-base-300`, or `border-base-content/20` where a border must be clearly visible). Use
  daisyUI's radii (`rounded-box`); don't set fixed corner sizes.

## Components

*Public components restyled in Steps 3 to 5; admin components in Step 6 (see Admin editor below).*

### Header
*Restyled in Step 3.* `includes/header.php`, a daisyUI `navbar` in a `bg-base-100` bar with a `border-b border-base-300`.
- Top row: the wordmark (`font-display`, from `SITE_NAME`) and the light and dark toggle. The toggle is a `btn btn-ghost`
  with a half-filled circle icon; the words "Dark mode" or "Light mode" show from `sm` up, and the accessible name
  ("Switch to dark mode") is always set. It is wired by the script in `<head>` through the `data-theme-toggle` attribute.
- Below `md`: a hamburger `btn btn-square` opens a daisyUI `dropdown` with a `menu` of the country's categories. It needs no
  JavaScript. From `md` up the same links sit on a second row (`menu menu-horizontal`), so any number of categories fits at
  any width. Links come from the country's categories, never typed into a page.
- The wordmark is `text-lg` on a phone and `text-2xl` from `sm`, so it fits beside the hamburger and toggle at 320px.

### Footer
*Restyled in Step 3.* `includes/footer.php`, a daisyUI `footer` on `bg-base-200` with `border-t border-base-300`. It stacks on a
phone and sits in two columns from `sm` (`footer sm:footer-horizontal`). Column one is an "About this site" `footer-title`
and `link link-hover` links (About, How we check our figures, Disclaimer, Privacy); column two is the copyright line and the
independent-information statement. Both are on every page, and the disclaimer link must stay.

### Breadcrumb
*Restyled in Step 3.* daisyUI `breadcrumbs text-sm mb-2` around a `<ul>`, inside `<nav aria-label="Breadcrumb">`. Used on
the subject page (Category, Subcategory) and the subcategory page (Category, then the current page as plain text). The
component scrolls sideways inside itself if the trail is too wide for a phone. Don't build breadcrumbs by hand.

### Figure card
*Restyled in Step 4.* A daisyUI card on the page surface, in a `sm:grid-cols-2 gap-4 sm:gap-5` grid (one column on a phone):

```
<div class="card card-border border-base-content/20 bg-base-100">
  <div class="card-body p-5 gap-1"> ... </div>
</div>
```

Contents, in this order: label (`text-sm opacity-80`) with an optional region badge, the value
(`font-display text-3xl font-semibold tabular-nums break-words`), an optional context line (`text-sm opacity-80`), the source
line (`text-xs opacity-70`: linked source name, "since" date, "checked" date, separated by `&middot;`), then an optional
"More on ... &rarr;" link (`link link-primary text-xs`). The border is `base-content` at 20% so it shows in both themes.
Use `tabular-nums` on every figure that should line up. The old `.fact-card` class has been deleted from the CSS.

### Region badge
*Restyled in Step 4.* daisyUI `badge badge-outline badge-sm ml-1` next to the label of a figure that applies to one region.

### Links
*Restyled in Step 4.* daisyUI `link`. Source links and other inline links: `link`. Links that lead to another page of the
site, including those in the explanation text: `link link-primary`. Navigation links have no underline (menu items).
External source links open in a new tab with `target="_blank" rel="noopener"`.

### Tables (worked examples)
*Restyled in Step 4.* A daisyUI `table` inside `overflow-x-auto rounded-box border border-base-300 bg-base-100`. The wrapper
scrolls sideways on a phone, so a wide table never widens the page. Header cells are `whitespace-nowrap`; the first column is
`font-semibold`, number columns `tabular-nums`. A `text-xs opacity-70` note sits under the table. No zebra striping.

### Change list ("What's changed")
*Restyled in Step 4.* daisyUI `timeline timeline-vertical timeline-compact max-w-2xl`. Each `<li>` has a small dot in
`timeline-middle` and a `timeline-end mb-6` block: the date (`<time class="text-xs opacity-70 block">`) then one sentence with
old and new values in `font-semibold tabular-nums`. Don't add `timeline-snap-icon`: it breaks the compact layout.

### Explanation text
*Restyled in Step 4.* Built in PHP by `renderExplanation()` in `includes/functions.php`, so classes are set there, not in a
template: paragraphs `mb-4`, h2 `font-display text-2xl font-semibold mt-8 mb-3`, h3 `font-display text-xl font-semibold mt-6
mb-2`, lists `list-disc` or `list-decimal pl-6 mb-4 space-y-1`, links `link link-primary`. Wrapped in `max-w-2xl
leading-relaxed`. No images, callouts or tables inside explanations.

### Preview banner (admin and previews only)
*Restyled in Step 4.* A daisyUI `alert alert-warning` with `role="alert"`, because the view is not public. Don't
use it for anything a visitor sees.

### Link cards (home, category and subcategory pages)
*Restyled in Step 5.* The whole card is one link, so the whole card is the tap target:

```
<a href="..." class="card card-border border-base-content/20 bg-base-100 hover:bg-base-200 transition-colors">
  <div class="card-body p-5 gap-1"> <h3 class="card-title font-display text-xl">...</h3> <p class="text-sm opacity-80">...</p> </div>
</a>
```
In a `grid sm:grid-cols-2 gap-4 sm:gap-5`. The country picker on the main home page uses the same card with
`card-title font-display text-2xl`.

### Subject lists (country home page)
*Restyled in Step 5.* Under each category: an h2 link (`link link-hover`) over a `border-b border-base-300` rule, then for each
subcategory a small mono label and a list of `link link-hover inline-block py-2` links (the padding keeps each one a
comfortable tap target).

### Section label
*Restyled in Step 5.* The small uppercase label above a heading or list: `font-mono text-xs uppercase tracking-wide opacity-70`.
It's neutral text, not a brand colour, so it keeps its contrast whatever the palette becomes.

### Long-text pages (About, How we check, Disclaimer, Privacy)
*Restyled in Step 5.* One `article.max-w-2xl`: h1 `font-display text-4xl font-semibold mb-6`, a `text-lg opacity-80` lead, h2
`font-display text-2xl font-semibold mb-3`, paragraphs `mb-4` or `mb-8` before the next heading, lists `list-disc pl-6
space-y-1`, links `link link-primary`, and a `text-sm opacity-70` "Last updated" line. The live totals on How we check are a
daisyUI `stats stats-vertical sm:stats-horizontal` (stacked on a phone), and the publisher list is a daisyUI `list` with
`list-row` and `list-col-grow`.

### Article page
*Restyled in Step 5.* The body is stored HTML with no classes, so the wrapper styles its children with Tailwind arbitrary
variants (`max-w-2xl leading-relaxed [&_p]:mb-4 [&_h2]:font-display ...`). "Figures used" are the same daisyUI figure card as
the subject page, smaller (`card-body p-4`). The old `.article-body` CSS is deleted.

### Error pages
*Restyled in Step 5.* `showErrorPage()` in `includes/functions.php`: an h1 (`font-display text-4xl`), the message in
`opacity-80`, and a `btn btn-primary` back to the home page. Still `noindex` and sent with the real 404 or 410 status.

## Admin editor

*Restyled in Step 6 (25 November 2026).* The admin pages (`public/admin/`, with `includes/admin-nav.php`) use the same theme
and the same rules as the public site: daisyUI components, tokens only, light and dark. Behaviour, form field names and the
job and AI flows are unchanged: classes and markup only.

- **Section nav:** daisyUI `tabs tabs-border` (Dashboard, Subjects, Review, Lessons), the current one `tab-active` with
  `aria-current="page"`, and Log out pushed right with `ml-auto`. The tabs wrap onto two rows on a phone.
- **Flash message:** a daisyUI `alert` (`alert-success`, `alert-info` or `alert-error`), set in `admin-nav.php`.
- **Panels:** the same card as the public site, `card card-border border-base-content/20 bg-base-100`, with `p-5` directly on
  it (admin panels don't use `card-body`). Whole-card links add `hover:bg-base-200 transition-colors`.
- **Tables:** daisyUI `table table-sm`, inside `overflow-x-auto rounded-box border border-base-300 bg-base-100`. Cells take
  their padding from the table, so don't add `py-*` or `pr-*` to `td` and `th`. Header cells keep the small mono label style.
  Wide tables scroll inside their box on a phone; the page itself never widens.
- **Status badges:** daisyUI `badge badge-sm whitespace-nowrap`: `badge-outline` for normal and live states, solid `badge-error`
  for a problem. Soft badges and plain red text are too faint on the page surface, so use the solid badge.
- **Forms:** daisyUI `input`, `textarea`, `select`, `radio`, `checkbox` and `btn` with their default borders (daisyUI 5 has no
  `-bordered` modifiers). Sizes `input-sm`, `btn-sm` and `btn-xs` are allowed in the admin's dense tables and rows, because
  the editor is a desktop tool; use default sizes for anything that stands alone.
- **Confirmation dialog** (`ask()` in `subject.php`): a daisyUI `modal` built in JavaScript (`modal`, `modal-box`,
  `modal-action`, a solid `alert alert-warning` for the warning line), so it follows the theme. No inline colours.
- **Links and toggles:** `link` or `link link-hover`; `<summary>` toggles are `cursor-pointer link`.
- No hard-coded colours remain in the admin. Don't add `<style>` blocks or `style=` colours.

### Other daisyUI components
*Rewritten in Step 4.* Every component is a daisyUI component (card, badge, table, timeline, alert, navbar, menu, dropdown,
footer, breadcrumbs, link, btn, and the admin form controls). Plain Tailwind is for layout, spacing and type only. Use a
component's own modifiers (`btn-primary`, `alert-warning`) rather than recolouring it with utility classes, and check any new
component in light and dark and at 320px before using it.

## Motion

*Rewritten in Step 5.* Only two things move: link cards change background on hover and links change colour, both with
`transition-colors`. No animation on load, no scroll effects, no carousels. Respect `prefers-reduced-motion` if anything else
is ever added.

## Accessibility

*Rewritten in Step 5. The stock palette's contrast has not been measured: check it, in both themes, before relying on it,
and again after any palette override.*

- Body text is `base-content` on `base-100`. Check any new colour pairing, especially brand colours used as text or as a
  button fill, in both themes.
- Meaning is never carried by colour alone.
- Links are identifiable in body text (daisyUI `link` underlines). Keep visible focus outlines: don't remove them with
  `outline-none` unless a replacement is added.
- Icon-only buttons (the menu button and theme toggle) have an `aria-label`; decorative icons are `aria-hidden="true"`.
- Tap targets are at least 44px on a phone: default-size buttons, `py-2` on text links in lists, whole-card links.
- `<html lang>` comes from the country's locale (for example `en-GB`). Don't hard-code it.
- Navigation, breadcrumbs, tables and headings use real elements (`<nav aria-label>`, `<table>`, `<h1>` to `<h3>`), and
  pages carry breadcrumb JSON-LD.
- The site has no photos or illustrations. Add alt text if any are ever added.

## Adding or changing design

1. Reuse a pattern above first. Most pages need no new classes.
2. If a pattern is missing, build it from daisyUI components first. Only if that can't be done, add a named class to
   `src/input.css` with a comment saying why, and list it in this guide.
3. Run `npm run build`, check the page at phone width and at desktop width on MAMP, and commit the compiled
   `public/assets/css/tailwind.css` with the change.
4. Update this file in the same commit.
5. Changing the palette or fonts is expected (see Stack) but affects every page: check each page type in both themes.
