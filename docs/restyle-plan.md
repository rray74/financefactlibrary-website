# Restyle plan: whole site on daisyUI

Started 25 November 2026. Decisions (Richard, 25 Nov 2026):
1. **All components use daisyUI** (card, navbar, breadcrumbs, table, stat, alert, btn, badge, footer, menu and so on). Plain
   Tailwind only for layout and spacing.
2. **Scope: public site and admin editor.**
3. **Full identity change.** Start from a built-in daisyUI theme and adjust it, not from the current navy, paper and
   index-card look.
4. **Fully responsive** (requirement, added 25 Nov 2026). Every page works from a 320px phone up to a wide desktop: mobile
   first, no horizontal page scroll, tap targets at least 44px, wide tables scroll inside their own container, text never
   clipped, navigation usable on touch.
5. **Light and dark mode** (requirement, added 25 Nov 2026). Two matching themes, light and dark. Default follows the
   visitor's device setting (`prefers-color-scheme`), with a visible toggle that remembers the choice. Replaces the
   current light-only design.

`docs/design-style.md` describes the current (pre-restyle) design. It is rewritten in Step 6 and is wrong from Step 3 on.

## Steps

Each step ends with a check and a commit. Nothing is pushed until the end of Step 6 (a push deploys the live site).

### Step 1: Choose the theme (Richard)
- Compare candidate built-in themes on a sample subject page (`theme-compare.html`, built in the scratchpad).
- Pick a light theme and a dark theme that belong together (for example corporate with business, or nord with a dark
  counterpart). Check both on the sample page, including figure cards, alerts and tables.
- Pick fonts (the current Fraunces, IBM Plex Sans and Mono are not required). Keep to one or two families.
- Output: the chosen theme name and any colour tweaks, recorded here.

### Step 2: Theme and base CSS
- `src/input.css`: replace the custom `financefactlibrary` theme with the chosen built-in theme, plus any tweaks. Delete
  `.fact-card` and `.article-body` once the templates no longer use them (Steps 3 to 5 replace them).
- Configure both themes in `@plugin "daisyui"` (one default light, one `prefersdark`), so the site follows the device
  setting. Add a theme toggle (daisyUI `theme-controller` or `swap`) that stores the choice in `localStorage` (in a
  try/catch) and is applied before first paint, in `<head>`, to avoid a flash of the wrong theme.
- Use theme tokens only (`bg-base-100`, `text-base-content`, `border-base-300`), never fixed hex or `bg-white`, so every
  component works in both modes. Remove the hard-coded `#ffffff` and navy values.
- Update the font links in `includes/header.php` and the `@theme` font mapping.
- Run `npm run build` (node is on Richard's Mac terminal, not in the Claude shell) and commit the compiled CSS with the source.

### Step 3: Shared shell
- `includes/header.php`: `navbar` with a mobile menu (dropdown or drawer; five or more category links don't fit on a phone),
  the theme toggle, and daisyUI `breadcrumbs` for the trail (scrollable or truncated on narrow screens).
- `includes/footer.php`: daisyUI `footer`.
- Check one page at phone and desktop width before continuing, since every page changes.

### Step 4: Subject page (`public/fact.php`)
The page most visitors see, and the most components:
- Figure cards: `card` (or `stat` for number-led tiles), `badge` for the region tag.
- Explanation text: `renderExplanation()` in `includes/functions.php` (paragraph, h3 and list classes); consider the daisyUI
  `prose`-style approach or Tailwind typography.
- Worked-example `table`, "What's changed" as `timeline` or a `list`, preview banner as an `alert`.
- Check against real pages: a long one, one with regional tags, one with worked examples, one in preview mode.

### Step 5: Everything else public
- Browse pages: `index.php`, `category.php`, `subcategory.php`, plus the country switch.
- Long-text pages: `about.php`, `how-we-check.php`, `disclaimer.php`, `privacy.php`, `article.php` (build the long-text layout once).
- Error and 404 pages, and optional extras (favicon, social share image). The site has none today.

### Step 6: Admin editor (`public/admin/`, 8 pages, about 3,200 lines)
- Uses `includes/admin-nav.php` and `includes/admin-auth.php`. Restyle login, subjects list, subject editor (the biggest),
  review, lessons and preview with daisyUI forms (`input`, `textarea`, `select`, `fieldset`), `btn`, `alert`, `tabs`, `badge`
  and `table`.
- Do not change behaviour, form field names or the job and AI flows: markup and classes only. Test every form on MAMP.

### Step 7: Docs and checks
- Rewrite `docs/design-style.md` for the new design (palette tokens, type, component patterns, and when to add custom CSS).
- Update the CLAUDE.md line if it still describes the old look.
- Responsive check on MAMP for every page type and the admin, at 320, 375, 768, 1024 and 1440px: no horizontal page
  scroll, nothing clipped, menus and forms usable by touch, long figures and long page names wrap.
- Light and dark check for every page type: contrast of text, links, badges, alerts and table rows in both modes,
  keyboard focus visible in both, no hard-coded colours left, no flash of the wrong theme on load, toggle remembers the
  choice, and the device setting is followed on first visit.
- Keyboard focus, and each live and hidden country.

### Step 8: Ship
- Commit per step (plain messages, no `Co-Authored-By`). Richard approves, then one push deploys everything.
- After deploy, check the live site on a phone and spot-check pages in each country.

## Risks to watch
- Tailwind only compiles classes it sees, so a class added to a template is missing until `npm run build` is run and the
  compiled CSS is committed.
- `renderExplanation()` builds HTML classes in PHP, not templates, so it needs its own pass.
- The admin pages are large and contain behaviour: restyle without touching logic.
- Dark mode doubles the visual testing: each page type is checked in both themes at every width.
- Don't hard-code colours anywhere (PHP strings in `renderExplanation()` and the admin included), or dark mode breaks.
- Every page shares the header and footer, so Step 3 changes the whole site at once.
