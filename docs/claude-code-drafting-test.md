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
| Claude Code session usage | Within the monthly allowance | Within the monthly allowance | Within the monthly allowance |
| Richard's review time (minutes) | under 5 | under 5 | under 5 |
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
| Nightly AI check, first run | See "Nightly check on the Claude Code pages" below (15 pages checked 25 to 27 Nov) | Same | Same |

### What this shows so far

- Drafting cost nothing in API spend, and no page needed Claude in the browser.
- The near-copy check does most of the iteration. GOV.UK lists and short formulaic pages (sick pay exclusions, fit notes) are where close copies cluster, so those need restructuring, not word swaps.
- Reading the sources as part of drafting turned up card problems that the API route would not have noticed (see the table). That is a benefit independent of cost.
- Claude Code made its own errors: three at the claim-by-claim read on page 1, and one grammar slip that reached the live site on page 3. Placeholders make meta text easy to get wrong ("a 8%"), and the checks do not catch it.
- Richard's review time was under 5 minutes a page, against 5 to 25 minutes on the API route, and no page needed edits.
- A check of the three card concerns raised while drafting found that two card notes were right (Statutory Sick Pay's waiting days, and the earnings trigger's "more than") and one card source was wrong (the age-22 card), which is now fixed. Reading the legislation settled each one. The GOV.UK pages were looser than the Act in one place ("at least" against "more than").
- Auto-enrolment: "UK" against "Great Britain" is not a real error. Northern Ireland has its own Act (Pensions (No. 2) Act (Northern Ireland) 2008), and its section 3 has the same age (at least 22) and trigger (more than £10,000) as the Great Britain Act. The 2026/27 Northern Ireland order was not found, so the Northern Ireland qualifying earnings band is not confirmed from an official page. The page text was left as it is.
- Plan usage: Richard reports the three sessions used a tiny fraction of the monthly allowance, so it is recorded as "within the monthly allowance" and is not a constraint for now. It was not measured, and should be looked at again if a country launch (15 to 20 pages) is drafted in one month.
- Still not known: what the nightly AI check says about these pages (due from about 23 to 30 November). Every other part of the decision rule is met: review time is lower, no substantive errors survived to review, and usage is sustainable.

## Nightly check on the Claude Code pages (25 to 27 Nov 2026)

The 05:30 run was capped to a fixed sample of the 30 UK pages written in Claude Code on 22 to 24 Nov, five a night. Fifteen are done; 15 remain (3 more nights, to about 30 Nov).

| Batch | Pages | Findings | Needed a real text change | Figure errors |
|---|---|---|---|---|
| 25 Nov | Junior ISA, SDLT, Income Tax Rates and Bands, Scottish Income Tax, Employee NI | 24 | about 12 | 0 |
| 26 Nov | Self-Employed NI, Dividend Tax Rates, Inheritance Tax Thresholds, Scottish LBTT, Welsh LTT | 26 (5, 3, 7, 4, 7) | about half (fixed on all five pages), mostly wording | 0 |
| 27 Nov | Personal Savings Allowance, State Pension Age, Pension Annual Allowance, Auto-Enrolment Thresholds, Statutory Sick Pay | 24 (7, 3, 4, 9, 1) | 16, about two thirds (fixed on four pages; Statutory Sick Pay left as it was) | 0 |

What the 26 Nov findings were (each read against the source with `scripts/read-source.php` before fixing):

- Real accuracy problems: a meta description that read as if 6% Class 4 applied to all profits (Self-Employed NI); a "closes it" certainty that the source contradicts (voluntary payments do not always raise the pension); "larger amounts" with no £10,000 point (Dividend); a "four bands" count the source never gives (LBTT); two headings that overstated (Welsh "higher threshold", IHT "100% relief"); the Welsh replacement-home rule given for one direction only and for companies too.
- Real omissions worth a clause: trust and descendant conditions on the residence band, the taper basis (assets less debts, no reliefs deducted), the LBTT repayment conditions, the Welsh £40,000 other-dwelling test, voluntary NI exceptions.
- Style: HMRC not spelled out (two pages), repeated sentences (four pages), an own-consequence sentence about the border (two pages).
- Not acted on: findings asking for detail the page deliberately leaves out (share fishermen's Class 2 rate, relief interactions, the taper-relief detail on gifts).
- No finding on any page was a wrong figure, and no source went unread.

What the 27 Nov findings were (each read against the source with `scripts/read-source.php` before fixing):

- Real accuracy problems: the age 68 sentence in State Pension Age read as if everyone born from 6 April 1977 reaches 68, when only those born from 6 April 1978 do (1977 to 1978 births get 67 plus months); "not taxed at all" for ISA interest dropped the source's "usually"; the Auto-Enrolment re-enrolment heading and "dismiss" without "unfairly"; the employer-pays-more rule stated for every scheme when the source says some; a "two thresholds" count and a Savings tax-code heading that overstated.
- Real omissions worth a clause: the pension carry-forward condition (all of this year's allowance must be used), foreign and children's accounts, the £10,000 Self Assessment point, relief at source for non-taxpayers, the re-enrolment exceptions, how to opt out.
- Not acted on: Statutory Sick Pay's one finding (the text is right: the source says the same, the "special rules" are an omission), Great Britain against UK in Auto-Enrolment (the GOV.UK page we cite says UK), the State Pension end-of-month rule, the reduced lump sum allowances and the alternative annual allowance, niche auto-enrolment exclusions, one unneeded-section finding.
- Two near-copies were introduced by the first fix wording and caught by the free triage, then reworded.
- No finding was a wrong figure.

Notes: fixing a page changes its text hash, so each fixed page was taken out of `data/nightly-check-sample.txt` (see `docs/page-checklist.md`). A fix pass now takes about 5 to 10 minutes a page including reading the source. The check costs about $0.07 a page. Meta descriptions are the awkward fix: the 200-character limit counts placeholders as typed while the 140 to 155 target counts them filled, so a description with several figures has little room.

