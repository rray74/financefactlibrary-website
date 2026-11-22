# Test: drafting in Claude Code instead of the API drafter

*Set up 22 November 2026. Question: is it cheaper and better to have Claude Code draft a page (reading the sources itself, running the free checks, fixing its own findings) than to use "Draft and polish in the background" and then review claim by claim? See `docs/content-creation-process.md` for the normal process.*

## The three test pages

| # | Page (slug) | Why this one |
|---|---|---|
| 1 | Marriage Allowance (`marriage-allowance`) | Simple, one HMRC source set, no nation differences. The easy case |
| 2 | Statutory Sick Pay (`statutory-sick-pay`) | Medium: eligibility conditions and exceptions that are easy to get wrong |
| 3 | Auto-Enrolment Thresholds and Minimum Contributions (`auto-enrolment-pension`) | Medium to hard: several thresholds, high in the impressions queue |

A mix on purpose: three hard pages would make the test look worse than the baseline pages were. Swap any page if you prefer (the slugs come from the 30-page list in `docs/TODO.md`; check the slug exists before starting).

## The method for each page (Claude Code does all of this)

1. List the page's figure cards and their sources from the local database (read-only). Stop if a card looks wrong or stale.
2. Read each source live with `scripts/read-source.php`. Add a reference source (Step 3 of the process doc) for any claim no card cites.
3. Draft the four fields, following `docs/house-style/core.md` and `uk.md`, with the approved lessons.
4. Run `php scripts/triage-page-text.php --page=<slug>` (includes the near-copy check). Rewrite until the program findings are clean.
5. Re-read every sentence against its source (the claim-by-claim pass) and fix.
6. Import on MAMP with `scripts/import-page-text.php --dry-run`, then for real. Show Richard the finished text.
7. After Richard's review: one commit per page, then server import and live check, as in the standing authority in `docs/TODO.md`.

**Rules for the test:** no API drafter, no polish job, no "Check with AI" or Propose fix in the editor, so no AI spend for the draft. The nightly `check-page-text.php` will still read the page, and its result is the independent second opinion.

## What to record for each page

| Measure | Page 1 | Page 2 | Page 3 |
|---|---|---|---|
| Claude Code session usage (rough: how long, how much of the allowance) | | | |
| Richard's review time (minutes, from handover to approval) | | | |
| Number of edits Richard asked for | | | |
| Program findings on first draft / after fixes | | | |
| Real errors found at the claim-by-claim read (and what) | | | |
| API cost (should be $0 for drafting) | | | |
| Nightly AI check, first run: kinds of finding | | | |
| Needed Claude in the browser? | | | |

## Baseline to compare against (API route)

- AI cost: about $0.43 a page (range $0.29 to $0.67, 14 pages, `docs/drafting-loop-progress.md`).
- Richard's time: 5 to 25 minutes a page after the draft, aim of about 25 minutes from finished job to saved page.
- Quality: all 38 written pages needed the full claim-by-claim rewrite after the AI draft; real errors fixed are listed in `docs/TODO.md` section 3. Nightly check baseline: `docs/reference-sources-evaluation.md`, "Third pass".

## Decision after page 3

- **Adopt** Claude Code drafting for new pages if: review time is the same or lower, the claim-by-claim pass finds fewer or no substantive errors, and the usage on the plan is sustainable.
- **Keep the API route** if review time rises or the plan's usage limits make 15 to 20 pages per country impractical.
- Either way, record the result here and update `docs/content-creation-process.md` (Step 4) and `CLAUDE.md`.

## Results

*Filled in 22 November 2026 after all three pages were live. Blank cells need Richard.*

| Measure | Marriage Allowance | Statutory Sick Pay | Auto-Enrolment |
|---|---|---|---|
| Claude Code session usage | | | |
| Richard's review time (minutes) | | | |
| Edits Richard asked for | 0 | 0 | 0 |
| Program findings, first import | 3 (close copies) | 7 (6 close copies, 1 typed figure) | 1 (typed figure, a fixed legal one) |
| Findings after adding reference sources | 3 more close copies | none new | none |
| Reword rounds before clean | 2 | 4 | 0 |
| Findings left at the end | 0 | 1 (12 and 23: fixed legal figures) | 1 (12: fixed legal figure) |
| Real errors found at the claim-by-claim read | 3 in my own draft (an unsupported "most people", an overstated tax-code claim, a heading that wasn't always true) | 0 | 0 (dropped one jargon-heavy exclusion) |
| Errors found only after going live | 0 | 0 | 1: "with a 8% minimum" in the meta description (my wording); fixed in a second commit |
| API cost for drafting | $0 | $0 | $0 |
| Reference sources added | 2 | 4 | 2 |
| Card or card-note problems found | 0 | 1 (card note on abolished waiting days not confirmed by the GOV.UK pages read) | 3 (trigger "more than" against GOV.UK "at least"; age-22 card cites a page that doesn't say 22; UK against Great Britain) |
| Needed Claude in the browser | No | No | No |
| Nightly AI check, first run | Not run yet | Not run yet | Not run yet |

### What this shows so far

- Drafting cost nothing in API spend, and no page needed Claude in the browser.
- The near-copy check does most of the iteration. GOV.UK lists and short formulaic pages (sick pay exclusions, fit notes) are where close copies cluster, so those need restructuring, not word swaps.
- Reading the sources as part of drafting turned up card problems that the API route would not have noticed (see the table). That is a benefit independent of cost.
- Claude Code made its own errors: three at the claim-by-claim read on page 1, and one grammar slip that reached the live site on page 3. Placeholders make meta text easy to get wrong ("a 8%"), and the checks do not catch it.
- Not yet known: Richard's review minutes, plan usage, and what the nightly AI check says about these three pages. The decision rule above cannot be applied until those are in.
