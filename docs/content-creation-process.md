# How content is created for the site

*Written 22 November 2026, updated 24 November 2026 after the Claude Code route had written all 30 remaining UK pages (`docs/claude-code-drafting-test.md` has the test; section 9 below lists what was learned and what changes). This is the end-to-end view of how a page goes from nothing to live. It summarises, and points to, the detailed docs rather than replacing them: `docs/page-checklist.md` (per-page finishing routine), `docs/house-style/` (writing rules), `docs/api-costs.md` (keys and spend), `docs/drafting-loop-progress.md` (how the AI loop was built) and `docs/content-maps/au-content-map.md` (the Australia plan and build log).*

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
1. **Audits the cards.** Lists the page's figure cards, their labels, notes and sources (read-only) and checks each against its source. A wrong label, a source that does not state the figure, or a note with no source is raised and fixed through a card CSV (`import-facts.php`) before any text is written. Examples found this way: a State Pension age label that said 66 for everyone born before 6 April 1960 (it holds only from 6 October 1954), and an age-22 card citing a page that does not say 22. Stop and ask when a card looks wrong.
2. Reads every source live with `scripts/read-source.php`, including the other pages of a GOV.UK guide, and adds a reference source (Step 3) for any claim no card cites. Where two sources disagree, it checks the legislation and does not state the disputed detail in the text.
3. Drafts the four fields to `docs/house-style/core.md` plus the country file, in its own words and structure (the near-copy check punishes following a source's order).
4. Writes `data/imports/page-text-<CC>-<slug>-<date>.csv` and imports it on MAMP with `import-page-text.php --dry-run --overwrite`, then for real.
5. Runs `php scripts/triage-page-text.php --page=<slug> --details` and rewrites until nothing is left except fixed legal figures, then re-reads every sentence against its source.
6. Shows Richard the MAMP page address, the findings and a **claims table** (see Step 7). Richard reviews. Several pages can be drafted together and previewed in one list, then approved or flagged.

After approval: one commit per page, push, wait for the deploy, then `import-reference-sources.php` and `import-page-text.php` on the server (dry run first), run the triage there, and check the live page's title, description, headings and that no `{fact:` is left unfilled.

**Placeholder and meta traps** (each cost iterations on real pages):
- Meta description needs about 140 to 155 characters filled in, and no more than 200 as typed, with placeholders counted at full length. A page with three or four placeholders cannot meet both, so use one or two and reword. Check both numbers before importing.
- `{tax_year}` fills from the page's latest card, so a page with cards for April 2027 fills as 2027/28. Leave the year out of the title if any card is for a future year.
- A value stored as "238.00" fills as "£238.00", so keep such a placeholder out of titles.
- A placeholder after "a" or "an" breaks when the value changes ("a 8%"). Reword around it.
- Rate pages (any card checked every 42 days or less): no rate or rate placeholder in any field, no minimum or maximum deposit, nothing about rising or falling. Titles name the subject.

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

### Step 7: Review (claim by claim, then a verifiable preview)
Two reviews happen, and the second only works because of the first.

**The writer's read.** Before showing anyone the page, Claude Code reads every sentence against its source and fixes what it finds. This is where real errors have been found, and none of them was caught by a program check: an additional-rate statement that was wrong for Blind Person's Allowance holders, a birthday example that was wrong for part of a month, an unsupported claim that employers keep paying National Insurance after State Pension age, Class 1B described as replacing Class 1A, and a heading that said the opposite of the truth. The program checks catch near-copies, typed figures, dating words, hedges and lengths, but not whether the text is true.

**The preview and claims table.** With the MAMP address, Claude Code gives a short table of the page's factual claims: the claim, the source address and the quote that supports it. A reviewer can spot-check three claims in a few minutes. Without it, a 5-minute preview is a sanity check and cannot catch the errors above. A reviewer's approval is recorded by the review stamp (Step 8), which means "approved on preview", not "saved in the editor".

Also check:

- Intro and meta description must not promise anything the headings don't cover. Meta description 140 to 155 characters, meta title 42 or fewer, counted with figures filled in.
- Rate pages: no rate or odds in any field, not even as a placeholder, and nothing about rising or falling.
- The near-copy result is clean.
- Spelling: British for the UK (no Oxford comma), Macquarie for Australia.
- Where sources conflict (a GOV.UK page against another, or against a table), read the legislation or the department's policy paper. If it cannot be settled, leave the disputed detail out of the text and flag the card note.
- Where a guide does not say which nations it covers, the page says nothing about them and the gap is noted, not guessed.

**Stop conditions.** Stop and ask when a card or figure looks wrong, a source cannot be read or contradicts another, a code, migration, cron or settings change is needed, a content decision is needed, a deploy or import misbehaves, or anything would cost money. If a source suddenly cannot be read from the Mac or the server, check the VPN before changing anything.

### Step 8: Deploy, import, verify and stamp
- **Order for each approved page, one page at a time:** commit (plain message, no Co-Authored-By), push, wait for the server to reach that commit, then on the server run the reference-sources and page-text dry runs, then the real imports, then `triage-page-text.php --page=<slug>`, then `mark-page-reviewed.php --dry-run` and, only if it says "was: never", the real stamp, then check the live page (title, a section heading, no unfilled `{fact:`, the meta description). Stop on any error.
- **Save in the editor** instead of the script if a person is reviewing there; hard-refresh the live page (Cmd+Shift+R).
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

**Acceptance rule for the nightly AI check on Claude Code pages.** `check-page-text.php` is the second opinion. For each page it reads, any "contradicts source", "likely wrong" or "missing exception" finding means the page is re-checked against its sources by hand, and the result and what was wrong go in `docs/claude-code-drafting-test.md` and `docs/reference-sources-evaluation.md`. If findings of that kind keep appearing on pages that passed the writer's read, this process is revisited (more review, or a second reader) before another country starts. Style findings are ignored unless they are a typed figure, a stated rate or a dating word.

**Keeping pages right.**
- Dated wording is tracked in the key dates in `docs/TODO.md`: Personal Savings Allowance after 6 April 2027, Making Tax Digital after the 2027 stage, Help to Save in April 2028, and the Cash ISA wording after 6 April 2027.
- A yearly refresh each April: re-check every page's typed figures (the triage lists them), dated statements and the cards that change with the tax year, using `triage-page-text.php`, then reread the pages whose cards changed.
- When a card's source changes or a new official page appears (a Budget, a policy paper, a guide moved), re-read the page's reference sources and update both the cards and the text.

## 6. Launching a new country

Pilot the claims table on the first country's pages before relying on the process there, since each country's official sites differ in readability and in how clearly they state who a rule covers.

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
- The Claude Code route has written 30 UK pages. The nightly AI check had not read them when this was written, so the claim that quality holds is not yet independently tested.
- Claude Code makes its own mistakes (the list in Step 7, and a grammar slip that reached a live meta description). The writer's read, the claims table and the live check are not optional.
- The writer and the nightly checker are the same model family, so they can share blind spots.
- Official sources can be stale or inconsistent: HMRC tables that lag a Budget, GOV.UK guides with old wording, a gov.scot page still calling confirmed bands "proposed", a 2014 State Pension age paper. Legislation or a policy paper settles it.
- Card notes can state things no source supports (open: Junior ISA, Student Loan, Welsh LTT, Bank Rate). Do not repeat an unsourced card note in page text.
- `scripts/read-source.php --find ... --around N` crashes when N is larger than the page. Use `--chars`.
- `scripts/import-facts.php` has no dry run, so test a card CSV on MAMP first.

## 9. What was learned, and changes still to make

**Learned (22 to 24 Nov 2026, 30 UK pages in Claude Code).**
- Drafting cost $0 in API spend, no page needed Claude in the browser, and review took under 5 minutes a page. Plan usage was a small share of the monthly allowance (reported, not measured; recheck for a full country launch).
- Reading the sources properly found card problems that the old route would not have found.
- The program checks do their job on form but cannot judge truth. The writer's claim-by-claim read found every substantive error before publication, apart from one grammar slip on a live meta description.
- The main risk is a review that is too light to audit. The claims table (Step 7) is the fix.
- Iteration came mostly from near-copies on formulaic official pages and from meta length limits.

**Changes made to the process (this document).** A card audit before drafting, a claims table in the preview, stop conditions, the placeholder and meta traps, a fixed deploy-and-stamp order, an acceptance rule for the nightly check, and a maintenance routine.

**Still to do (tooling).**
- A pre-import check that shows the meta description filled and as typed, and flags a placeholder after "a" or "an", a tax year taken from a future-year card, and a rate key in a title.
- One script for the post-deploy sequence in Step 8 that stops on the first error.
- Fix `scripts/read-source.php`, and add a dry run to `scripts/import-facts.php`.
- A register of dated text and typed figures per page, so the April refresh is a list rather than a search.

**Decisions waiting for Richard.**
- Nations: five pages say nothing about Northern Ireland because their guides do not say who they cover. Leave, or research?
- The unsourced card notes: source them or remove them.
- Whether to keep one page at a time, or push several approved pages together and import them one after another, stopping on the first error.
