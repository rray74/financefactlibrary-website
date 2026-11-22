# How content is created for the site

*Written 22 November 2026, updated the same day after the Claude Code drafting test (`docs/claude-code-drafting-test.md`). This is the end-to-end view of how a page goes from nothing to live. It summarises, and points to, the detailed docs rather than replacing them: `docs/page-checklist.md` (per-page finishing routine), `docs/house-style/` (writing rules), `docs/api-costs.md` (keys and spend), `docs/drafting-loop-progress.md` (how the AI loop was built) and `docs/content-maps/au-content-map.md` (the Australia plan and build log).*

---

## 1. What a page is

Every page is a **Subject** inside Category → Subcategory. It has two kinds of content, made in two different ways:

| Part | What it is | How it gets in |
|---|---|---|
| **Figure cards** | Facts (a rate, threshold, age, date) each with a source, unit, effective date and history | CSV → `scripts/import-facts.php` |
| **Page text** | Four fields: meta title, meta description, intro, explanation. AI-drafted, human-reviewed | Editor in `public/admin/`, or CSV → `scripts/import-page-text.php` |

The text refers to the cards with placeholders (`{fact:key}`, `{tax_year}`), so a figure is only ever stored once and the text updates itself when the card does.

## 2. Ground rules

- **Content and database changes only go through tooling.** CSV plus an importer, or a migration for schema. No phpMyAdmin edits.
- **Test on MAMP first**, then run on the server over SSH.
- **Every figure is read live** from its official page with `scripts/read-source.php` before it goes in a CSV. Nothing from memory.
- **Facts, not advice.** Plain English, second person, official names, no dating words ("currently", "now", "new"), no typed figures that change, no figure history, no worked examples. Full rules in `docs/house-style/core.md` plus `uk.md` or `au.md`.
- **One country per page.** Never adapt one country's page into another's. Each is written from that country's own rules and sources.
- **Source domains are allowlisted** (`FACT_SOURCE_ALLOWLIST`). A source the site cannot read is refused by the importers.
- **AI costs money.** Run the free checks first. No bulk AI jobs without asking. Every AI script sets `AI_KEY_PURPOSE` in its first lines (`ffl-pages`, `ffl-multicountry`, `ffl-sources`). Model: `claude-sonnet-5-5`.

## 3. The pipeline, step by step

### Step 1: Plan the page
- Choose the subject from the country's content map (`docs/content-maps/`) or the Ready to write queue in the admin (ordered by Search Console impressions).
- Decide its category and subcategory, and the official sources it rests on.
- Watch out for pages that differ by nation within a country (UK income tax, Student Loan, LBTT) or are rate pages: they carry the most review.

### Step 2: Build the figure cards (CSV)
1. Read each figure live from its official source: `php scripts/read-source.php --url=... [--find=... --around=N | --chars=N]`.
2. Write the facts CSV (same 28 columns as `data/imports/mortgages-boe-2026-11.csv`). The first row of a page creates its category, subcategory and subject, with `subject_status` = `draft`. Country pages set `jurisdiction_code` on every row.
3. Rules that catch people out:
   - A placeholder must name a fact on an **earlier row**, so the intro and meta text go on a last row that repeats the first fact key.
   - Limits are counted as typed, placeholders unexpanded: meta description 200 characters, meta title 100 (the importer refuses more).
   - Rate facts (checked every 42 days or less) use `review_frequency_days` 42. `tax_year` is only set for figures that change each year.
   - `source_match_text` is an optional column for a source that writes a figure in words.
4. Dry run, then import: `php scripts/import-facts.php <file>` (on MAMP first, then the server).

### Step 3: Add reference sources (if needed)
If a claim in the text comes from an official page that no card cites (a freeze until 2031, a tax code, how claiming affects other benefits), register it so the AI checks can see it and so correct text is not flagged as unsupported.

- CSV like `data/imports/reference-sources-2026-11.csv`, run with `php scripts/import-reference-sources.php --dry-run <file>`.
- Very long pages are cut at 50,000 characters, so give the row an `excerpt_from` phrase.
- `--list=<slug>` shows a page's reference sources. They are fetched daily by `check-sources.php` so a change is noticed.

### Step 4: Draft the text (in Claude Code, by default)
**New pages are drafted in a Claude Code session, not by the API drafter.** This became the default on 22 November 2026 after a test on three pages (Marriage Allowance, Statutory Sick Pay, Auto-Enrolment): $0 of API spend for drafting, review time under 5 minutes a page against 5 to 25 on the API route, no page needing Claude in the browser, and card problems found along the way. Results: `docs/claude-code-drafting-test.md`. It used a tiny fraction of the monthly plan allowance (reported, not measured).

For each page, Claude Code:
1. Lists the page's figure cards and their sources (read-only) and stops if a card looks wrong or stale.
2. Reads every source live with `scripts/read-source.php`, including the other pages of a GOV.UK guide, and adds a reference source (Step 3) for any claim no card cites. Where two sources disagree, it checks the legislation and does not state the disputed detail in the text.
3. Drafts the four fields to `docs/house-style/core.md` plus the country file, in its own words and structure (the near-copy check punishes following a source's order).
4. Writes `data/imports/page-text-<CC>-<slug>-<date>.csv` and imports it on MAMP with `import-page-text.php --dry-run --overwrite`, then for real.
5. Runs `php scripts/triage-page-text.php --page=<slug> --details` and rewrites until nothing is left except fixed legal figures, then re-reads every sentence against its source.
6. Shows Richard the MAMP page address and the findings. Richard reviews.

After approval: one commit per page, push, wait for the deploy, then `import-reference-sources.php` and `import-page-text.php` on the server (dry run first), run the triage there, and check the live page's title, description, headings and that no `{fact:` is left unfilled.

**Watch the meta text when placeholders sit next to articles.** "with a {fact:x} minimum" filled to "with a 8% minimum" and went live. Reword around the placeholder, and read the filled-in meta text, not the typed one.

**The API route is still there** as a fallback: in the editor click **Draft and polish in the background**, then run `php scripts/run-polish-jobs.php` (or wait for cron) and click **Use draft** on all four fields. Nightly auto-drafting (`draft-pages.php`) stays **paused** (`DRAFTS_PAUSED`): review time is the bottleneck and drafts that wait go stale.

### Step 5: Free triage first
Before paying for any AI check:

- `php scripts/triage-page-text.php` ranks every written page by program findings (`--details`, `--page=<slug>`, `--csv`).
- `--page=<slug>` also runs the near-copy check against the page's source pages; `--close-copies` does it for every page. No sentence may follow a source's wording at 60% or more.
- The program checks cover: dating words, hedge words ("usually", "mostly", "generally"), typed figures that equal a card (including small ones like "2%" and "£5"), a unit repeated after a placeholder, rates on rate pages, and meta-length limits.

### Step 6: AI check and fixes (API route only)
For a page drafted in Claude Code, the claim-by-claim read in Step 4 replaces this step, and the nightly `check-page-text.php` is the independent second opinion (record its first result for each new page in `docs/reference-sources-evaluation.md`). The steps below are for pages drafted by the API route, or when a reviewer wants a second pass.

1. **Check with AI** once.
2. **Propose fixes for all findings.** Each fix is the smallest change for one finding and is program-checked before it is offered.
3. Read every proposal. Apply it only if it is true to the source, in your own words, and says nothing the sources don't. Only then use "Apply all proposals".
4. Substance findings (contradicts a source, wrong, missing exception or regional difference): fix them or decide the checker is wrong. Style findings: ignore unless they are a typed figure, a stated rate or a dating word.
5. Skip findings that ask for forbidden things: changing typed figures, figure history, or a section that only repeats the cards.
6. **Stop** after two AI checks. The checker raises a different point each run.

### Step 7: Human review (claim by claim)
Read the text against the official sources, not just the checker output. This is where real errors have been found on past pages (a reversed claim rule, a benefit shown as always unpaid, outdated law from a stale source). Also:

- Intro and meta description must not promise anything the headings don't cover. Meta description 140 to 155 characters, meta title 42 or fewer, counted with figures filled in.
- Rate pages: no rate or odds in any field, not even as a placeholder, and nothing about rising or falling.
- Check the near-copy result is clean.
- Spelling: British for the UK (no Oxford comma), Macquarie for Australia.

### Step 8: Save, publish and verify
- **Save**, then hard-refresh the live page (Cmd+Shift+R).
- **UK**: set the subject live when reviewed.
- **Other countries**: pages stay hidden drafts until the **drip publisher** releases them (`drip_country` job, handled by `scripts/run-jobs.php` every 5 minutes; Australia is 3 a week). The drip only publishes a page once its explanation is written. Keep exactly one worker row in hPanel.
- Verify live: the public address comes from the sitemap, not from guessing the slug (`/au/<category>/<subcategory>/<slug>/`).
- Click **Look for lessons** and approve a lesson only if it is general and doesn't contradict house style.
- **Record the review.** Saving the page in the editor stamps `text_reviewed_at`. For text that arrived by import and that a person has approved, `php scripts/mark-page-reviewed.php --dry-run --page=<slug>[,<slug>]` does the same, using the editor's own function. Run it only for pages a person has actually reviewed, and check the dry run first: a page already saved in the editor shows its real time, and re-stamping would replace it.

### Step 9: Record the cost
For a Claude Code draft there is no per-page API cost for drafting, so there is nothing to record beyond the nightly check's spend (`ffl-sources`). For the API route, note the job's "AI use" line and the line after each Propose fix: a page has averaged about $0.43, with 5 to 25 minutes of review time.

## 4. Moving text between machines

Page text lives in the database, so it moves by CSV:

- `php scripts/export-page-text.php --country=AU --out=data/imports/<name>.csv`
- `php scripts/import-page-text.php [--dry-run] [--overwrite] <file>`: fills empty fields only by default, never changes figures or status, never marks a page reviewed.
- Before pushing an export, compare its word counts with the pages. A page saved into the wrong page in the admin once put the super guarantee text on the GST page.
- Approved lessons do not transfer between machines: approve again on the server.

## 5. After a page is live: keeping it right

| Routine | When | What it does |
|---|---|---|
| `check-sources.php` | 07:30 daily | Re-reads every card source and reference source. The AI proposes a change when a figure moves; it only proposes, never applies (`AI_AUTO_APPLY` is false) |
| `check-page-text.php` | 05:30 daily | AI check of written pages that are due. Skips countries that aren't switched on |
| `fetch-boe-rates.php` | 07:00 daily | Updates the Bank of England-linked rate cards |
| `check-bank-rate.php` | 13:00, 15:00, 17:00 | On an MPC decision day, emails a draft CSV. Update the Bank Rate CSV and run `import-facts.php` on or after the announcement day (importer refuses a future `effective_from`). Next decision: 17 Dec 2026 |
| `send-alerts.php` | 08:00 daily | Alert emails |
| `fetch-search-console.php` | 06:00 daily | Impressions, which order the queue |
| `run-jobs.php` | Every 5 minutes | Job worker, including the country drip |

When a check flags a change, the fix goes through the same route as new content: update the CSV, import it, then re-run the page checklist for any text that depended on it.

## 6. Launching a new country

1. Write the country's house style file (`docs/house-style/<cc>.md`) and a content map.
2. Add the jurisdiction, allowlisted official domains and sources by migration (next free number: check `sql/migrations/`).
3. Build 15 to 20 pages with Steps 1 to 7, all as hidden drafts.
4. Switch the country on (`is_active = 1`) and set the drip (2 to 4 pages a week).
5. Australia was the pilot (launched 19 Nov 2026). New Zealand, Canada and the US follow the same route.

## 7. Quick reference: which tool for what

| Need | Tool |
|---|---|
| Read a figure from an official page | `scripts/read-source.php` |
| Add or change figure cards | CSV + `scripts/import-facts.php` |
| Add a supporting official page | CSV + `scripts/import-reference-sources.php` |
| Rank pages by problems, free | `scripts/triage-page-text.php` |
| Check every source is readable from this machine | `scripts/check-source-access.php` |
| Draft page text (default) | A Claude Code session: sources read live, CSV, `import-page-text.php`, triage |
| Draft and polish page text (fallback) | Editor button + `scripts/run-polish-jobs.php` |
| Record a person's review of imported text | `scripts/mark-page-reviewed.php` |
| Move page text between machines | `scripts/export-page-text.php`, `scripts/import-page-text.php` |
| Check job queue | `php scripts/run-jobs.php --status` |
| Schema change | Migration in `sql/migrations/` |

## 8. Known weak spots

- The AI check is noisy, partly because some card sources are out of date. It is a prompt to look, not a verdict.
- A self-contradiction in the text cannot be caught by program checks. Only the claim-by-claim read finds it.
- Lessons are per machine, and lesson deletion is on hold.
- Some regulators' pages (e.g. the Fair Work Commission) cannot be read by any fetcher, so those cards need a manual look.
- The Claude Code route was tested on three pages and the nightly AI check had not yet read them when it was adopted. If that check shows substantive findings the claim-by-claim read missed, revisit this.
- Claude Code makes its own mistakes (three at the read on Marriage Allowance, and a grammar slip in a live meta description). The review step and the live check are not optional.
