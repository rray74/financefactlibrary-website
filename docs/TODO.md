# Finance Fact Library: to-do list

*The running to-do list for the project. Started 21 November 2026 as a handover from the claude.ai "Finance fact library to-do list" chat (the handover is done, so the file is now simply the list); last updated 24 November 2026, night; API costs re-read 24 Nov (balance $3.71, needs a top-up). Read alongside `docs/api-costs.md` (key setup and spend) and `docs/page-checklist.md` (the per-page routine).*

---

## 1. How this file is used

The to-do list lives in this file. Items are added as they come up and ticked off when done, and Claude Code updates it in the repo as work finishes. Section 3 is the record of what has been done, section 4 is the open list, section 5 the key dates and section 6 the working rules.

---

## 2. Project at a glance

| | |
|---|---|
| **Site** | financefactlibrary.com (renamed from financial-facts.com in Oct 2026) |
| **Stack** | PHP, MySQL, Tailwind CSS, DaisyUI |
| **Hosting** | Hostinger (existing plan); site, database, admin app and cron jobs all live there |
| **Repo** | `rray74/financefactlibrary-website` |
| **Local** | `_financefactlibrary-website` on MAMP |
| **Deploy** | Push from VS Code to GitHub, which deploys to Hostinger over SSH |
| **Server app folder** | `~/domains/financefactlibrary.com/app` |
| **Content changes** | Only via CSV files and `scripts/import-facts.php`: test on MAMP first, then run on the server over SSH. No manual phpMyAdmin edits. |
| **Claude Code on the server** | Claude Code can SSH to the server with exactly `ssh -p 65002 -o BatchMode=yes -o ConnectTimeout=10 u888389356@31.170.164.28 '<command>'` (allowed in `.claude/settings.local.json`, which is personal to this Mac and git-ignored). Read-only steps and exports are fine; anything that writes content or the database on the server is asked about first. Hostinger's deploy applies migrations automatically on every push. |
| **Shell path on the Mac** | In some Claude Code sessions the shell path is stripped to `/usr/bin:/bin:/usr/sbin:/sbin`, so `php` is not found. Fix: `export PATH=/opt/homebrew/bin:/usr/local/bin:$PATH` (Homebrew PHP 8.4; MAMP's database still connects). |
| **Anthropic API keys** | Split by purpose (5 Nov 2026): `ffl-pages` (live editor, polish jobs, nightly drafting), `ffl-multicountry` (multi-country work and local testing), `ffl-sources` (`check-sources.php`, `check-page-text.php`). Scripts choose a key with `AI_KEY_PURPOSE`. Retire the old `ff-website` key once it shows no spend. |

---

## 3. What has been completed

### Done in Claude Code, 24 Nov 2026 (latest): card notes, the first read of the nightly checks, and six accuracy fixes

- **Drip checked:** the queue has one job, no failures, last release Medicare Levy; the next is due 24 Nov 14:40.
- **Three unsourced card notes fixed** (`ff19a99`, `data/imports/card-notes-fix-2026-11.csv`, imported on MAMP and the server): Junior ISA (dropped "doesn't count towards the parent's own ISA allowance": no official page says it), Student Loan Plan 2 (now "stays the same from 6 April 2027 to 5 April 2028") and Plan 5 ("first Plan 5 loans came into repayment from April 2026"). Three reference sources added (two Student Loans Company announcements, and the gov.wales Land Transaction Tax guide, which confirms "no first-time buyers' relief in Wales"). The Bank Rate SVR note had already been reworded. New key date below: Plan 5 threshold £26,025 from 6 April 2027.
- **Nightly check results read for the first time** (read-only, `subject_text_checks`): 32 pages have a result, all written the older way; none of the Claude Code pages has been checked yet (68 of 72 queued, 5 a night, so done by about 7 Dec). Richard chose to wait rather than pay for an early run. 26 of the 32 results were already stale (text changed since the check), so findings were tested against the live text. Of 105 findings, about 8 were real and still present; the rest were fixed, or style-only (52).
- **Six accuracy fixes** (one commit and push each, imported on the server, live-checked): Premium Bonds (`c4d9928`: which prizes need a claim form), Pension Tax Relief (`90547fc`: heading, and no charge for serious ill health or death), Carer's Allowance (`04d60e7`: EEA and Switzerland residence exception), Cash ISA Allowance (`8585f0a`: removed an unsourced "regulations come into force that day"), 2-Year Fixed Mortgage Rates (`6cfa9bb`: fees wording, early repayment fee, one unsourced sentence removed) and Cash Rate Target (`05558f2`, still a draft: the cash rate, not market rates, trades inside the band). Their review stamps still show the earlier date (the importer carries it across, and the stamp rule is never to overwrite a time).
- **Left open on purpose:** Premium Bonds child limit and the Council Tax Bands appeal route (no source found or read yet); the style findings.
- Lessons: a stored nightly result only counts if its text hash matches the live text (`textCheckHash`); the RBA page does not read from the Mac (SSL certificate error) but reads fine on the server, so run its near-copy check there.

### Done in Claude Code, 24 Nov 2026 (earlier still): the last four Australian pages, so every planned page is written

- **HELP Loan Repayments, the Australian Government 5% Deposit Scheme (formerly the First Home Guarantee), the Financial Claims Scheme and the Capital Gains Tax Discount** written and deployed one at a time (`87adc85`, `3abf76d`, `ec0a5be`, `10afbf1`): figures (`au-batch-13.csv` to `au-batch-16.csv`), reference sources, text, review stamp. All four are hidden drafts in the drip queue.
- **Sources moved:** the First Home Guarantee was renamed and its price caps now sit on `firsthomebuyers.gov.au`; `fcs.gov.au` redirects to `apra.gov.au`. Both domains were added to `FACT_SOURCE_ALLOWLIST` (approved, tested on MAMP first).
- **CGT:** the ATO confirms the Budget reform is law. From 1 July 2027 the 50% discount is replaced by cost base indexation for gains that accrue from then, with a 30% minimum tax on certain gains.
- **A web-search summary was wrong:** it gave $67,000 as the 2026–27 HELP threshold; the ATO page says $69,528. Always read the official page itself.
- All 53 UK and 19 Australian pages are written: 72 pages.

### Done in Claude Code, 24 Nov 2026 (earlier): the last two UK pages, so every UK page is written

- **NS&I Savings Rates and Standard Variable Rate (SVR)** written, previewed on MAMP, then committed and deployed one at a time (`b7429c3`, `ff8dbc8`), imported on the server, checked live and stamped reviewed. 9 reference sources added (the seven NS&I product pages, an FCA mortgage page and a Bank of England explainer).
- **All 53 UK pages now have an explanation**, so the server has 68 written pages (53 UK, 15 Australia). The 30 UK pages that had none on 22 Nov were all written in Claude Code between 22 and 24 Nov, each previewed by Richard, then committed, deployed, imported and checked live one at a time.
- **Rate pages (NS&I, SVR):** no rate in any field, no rate placeholder, no minimum or maximum deposit, nothing about rising or falling. Titles name the subject. The live rate cards still show on the page. The text describes how the products work (variable against fixed, access, tax, protection; what an SVR is, why a deal ends on one, how it differs from a tracker).
- **Card note not sourced:** the Bank Rate card's note says "SVRs are typically set several points above base rate". The FCA says a lender's variable rate "isn't directly linked to an external rate". The SVR page does not repeat the card's claim.

### Done in Claude Code, 23 to 24 Nov 2026 (earlier): seven more pages

- **Tax-Free Pension Lump Sum, Inheritance Tax on Gifts, Making Tax Digital for Income Tax, Stamp Duty Land Tax Rates (England and NI), Welsh Stamp Duty (LTT), Trading and Property Allowances and Employer Payroll Costs** written, previewed on MAMP, then committed and deployed one at a time (`b0b90b3`, `379b41a`, `5ea5fc1`, `291b7c6`, `d93a913`, `6bd4c8e`, `077cf26`), imported on the server, checked live and stamped reviewed. 10 reference sources added.
- Twenty-eight UK pages written since 22 Nov, so the server then had 66 written pages (51 UK, 15 Australia). The last two UK pages followed on 24 Nov (see above).
- **Mistakes in my own drafts caught at the claim-by-claim read:** a heading on Welsh LTT that said the opposite of the truth (Wales has the higher nil-rate threshold), Class 1B described as replacing Class 1A, and the care-worker exception attached to the wrong Employment Allowance rule.
- **Card note not verifiable:** the Welsh LTT card note "Wales has no separate first-time buyer relief" has no source I could find, so the page text does not repeat it.

### Done in Claude Code, 23 Nov 2026 (earlier): six more pages

- **Personal Savings Allowance, Help to Save, Statutory Maternity and Paternity Pay, Statutory Redundancy Pay, Statutory Holiday Entitlement and Pension Credit** written, previewed on MAMP, then committed and deployed one at a time (`0afb22e`, `210e1aa`, `245dfaf`, `d2d7d8e`, `3cb35c0`, `5127555`), imported on the server, checked live and stamped reviewed. 18 reference sources added.
- **Replaced a MAMP-only draft:** Personal Savings Allowance already had an older reviewed draft (19 Oct) on MAMP that was never on the server, with 17 program findings. The new text replaces it.
- **Gotcha:** `{tax_year}` fills from the page's latest card, so a page with cards for April 2027 (Personal Savings Allowance) would have shown 2027/28. Its title has no year. A placeholder for a value stored as "238.00" fills as "£238.00", so Pension Credit's title avoids it.
- Twenty-one UK pages written since 22 Nov, so the server has 59 written pages (44 UK, 15 Australia).

### Done in Claude Code, 23 Nov 2026 (later): six more pages

- **Pension Annual Allowance, Dividend Tax Rates, Scottish Income Tax Rates, State Pension Age, Employee and Employer National Insurance, PIP and Attendance Allowance** written, previewed on MAMP, then committed and deployed one at a time (`b21b943`, `0c608d1`, `b2bda56`, `5a3f27c`, `e7a8281`, `1ca889b`), imported on the server, checked live and stamped reviewed. 16 reference sources added.
- **Card fixed:** `spa_born_before_april_1960` said State Pension age 66 "if born before 6 April 1960", but the DWP timetable shows 66 only for births from 6 October 1954 to 5 April 1960 (earlier births reached it younger, and women's dates differed before December 1953). The label now says "State Pension age if born 6 October 1954 to 5 April 1960" (`data/imports/state-pension-age-card-label-2026-11.csv`). The key name stays as it was, because keys cannot be renamed.
- **Sources met:** the gov.scot Scottish Income Tax page still calls its 2026-27 bands "proposed"; HMRC's GOV.UK Scottish Income Tax page confirms them and the cards match. The State Pension age timetable is a 2014 paper with some stale policy wording, so the page uses only its legislated tables.
- **Mistakes caught in my own drafts at the claim-by-claim read:** a May 1960 birthday example that was wrong for 6 to 31 May births (State Pension Age), and an unsupported claim that employers keep paying National Insurance after State Pension age (Employee and Employer NI). The program checks do not catch these; the read does.
- Fifteen UK pages written since 22 Nov, so the server has 53 written pages (38 UK, 15 Australia).

### Done in Claude Code, 23 Nov 2026: three more pages

- **Self-Employed National Insurance, Scottish Stamp Duty (LBTT) and Student Loan Repayment Thresholds** written, previewed on MAMP by Richard, then committed and deployed one page at a time (`44adf72`, `f88d034`, `da9d015`), imported on the server, checked live and stamped reviewed. 10 reference sources added. The previews-first, one-commit-per-page flow worked and is the pattern to repeat.
- **Source problems met and cleared:** the gov.scot LBTT card source returned HTTP 202 and the server's SSH timed out, both while the Mac's VPN was off; both worked once it was on. A proposed move of the LBTT card sources to Revenue Scotland was reverted (gov.scot confirms the 2026-27 bands, the £175,000 first-time buyer ceiling and the flat 8% supplement). If a source suddenly cannot be read, check the VPN before changing anything.
- Nine UK pages written since 22 Nov, so the server has 47 written pages (32 UK, 15 Australia).

### Done in Claude Code, 22 Nov 2026 (night): the drafting test and six new pages

- **Claude Code drafting is now the default for new pages** (`docs/content-creation-process.md`, `CLAUDE.md`). Tested on Marriage Allowance, Statutory Sick Pay and Auto-Enrolment (`docs/claude-code-drafting-test.md`): $0 of API spend for drafting, Richard's review under 5 minutes a page (against 5 to 25 on the API route), no page needing Claude in the browser, plan usage a tiny fraction of the monthly allowance (Richard's report, not measured). The API drafter stays as a fallback and nightly drafting stays paused.
- **Six UK pages written, deployed, imported on the server and stamped reviewed:** Marriage Allowance, Statutory Sick Pay, Auto-Enrolment Thresholds and Minimum Contributions, Income Tax Rates and Bands (England, Wales and NI), Inheritance Tax Thresholds and Rates, Junior ISA Allowance. 19 reference sources were added across them. Remaining triage notes are fixed legal figures (for example 12 months, ages 16 and 17, the £37,700 basic rate band held to 5 April 2031).
- **Card checks done while drafting:** the Auto-Enrolment age-22 card now cites GOV.UK's joining page (`data/imports/ae-minimum-age-source-2026-11.csv`); the Statutory Sick Pay card note on waiting days and the earnings test was checked and is right (HMRC guidance, added as a reference source); the earnings trigger note ("more than") is right (Pensions Act 2008, section 3); "UK" against "Great Britain" on Auto-Enrolment is not an error (Northern Ireland's own Act has the same age and trigger). The nil-rate band, residence nil-rate band and its taper are all held to 5 April 2031 (HMRC "Inheritance Tax: thresholds", 26 Nov 2025); the HMRC thresholds table still shows the residence band ending in 2030 and GOV.UK's residence band guidance still says it rises with inflation, both out of date.
- **`scripts/mark-page-reviewed.php`** (new): records a person's review of imported page text using the editor's own function. Dry run first; stamp only a page whose dry run says "was: never" (a time means Richard saved it in the editor).
- A grammar slip of mine ("with a 8% minimum") reached the live Auto-Enrolment meta description and was fixed in a second commit. Placeholders next to "a" or "an" need rewording; read the filled-in text.

### Done in Claude Code, 21 to 22 Nov 2026

**Alerts and checks**
- **Bank Rate watch** (`scripts/check-bank-rate.php`, `includes/bank-rate-watch.php`): emails on MPC decision days, with a draft CSV. Cron 13:00, 15:00, 17:00. Tested with simulated days; first real run is 17 Dec 2026.
- **Free triage script** (`scripts/triage-page-text.php`): runs the program checks over every written page, ranks them, `--details` / `--page=<slug>` / `--csv`. No AI, read-only.
- **Three new program checks** in `quickTextChecks()`: small typed figures such as "2%" and "£5" (L34), hedge words "usually / mostly / generally" unless a source page says them (L28, L9), and a unit repeated after a placeholder (L38).
- **Meta text guard** (83811c9): the editor allowed 255 characters but the database holds 100 (title) and 200 (description) and is not in strict mode, so text was silently cut off mid-word (Capital Gains Tax and Child Benefit were live with cut-off descriptions). One shared limit counted as typed, refused in the editor and in `import-facts.php`, a live counter in the editor, and the quick checks flag text over or exactly at the limit.

**Reference sources** (migrations 030 and 031, `scripts/import-reference-sources.php`)
- Official pages a page's text rests on that no figure card cites, read by the AI check, Propose fix, drafting and polish, and fetched daily by `check-sources.php` so a change is noticed. 46 are registered across 23 pages as of 22 Nov night (`data/imports/reference-sources-2026-11.csv`). Allowlisted domains only, and the importer refuses a page the server cannot read.
- `excerpt_from` (migration 031) starts reading a long page at a phrase, because the AI check cuts each source at 50,000 characters.
- Evaluation and results so far: `docs/reference-sources-evaluation.md`.

**Content**
- **All 23 written UK pages have had the full routine and are live**, each checked claim by claim against its sources, rewritten where needed, and verified on the live site (one commit per page). The first 14: Income Tax Personal Allowance, Bank of England Base Rate, Carer's Allowance, Capital Gains Tax Rates, Buy-to-Let Mortgage Rates, 2-Year Fixed Mortgage Rates, Pension Tax Relief, State Pension Amount, Child Benefit, Approved Mileage Rates, Average Savings Rates, Corporation Tax Rates, National Minimum Wage and National Living Wage, Lifetime ISA. Then the "first 8" priority list (22 Nov): VAT Rates and Registration Thresholds, Council Tax Bands, Universal Credit Rates, Minimum Pension Age, Premium Bonds, Buying Your First Home, Deposit Protection (FSCS), Tenancy Deposits in England. Cash ISA Allowance (the 23rd) was checked claim by claim last: the text was correct, so the only change was a reference source (GOV.UK How ISAs work); it had earlier had the over-65s card note and safeguards fixes. Income Tax Personal Allowance's two close copies were reworded (22 Nov). A second pass acted on the first AI re-check for Capital Gains Tax, 2-Year Fixed, Carer's Allowance and Pension Tax Relief.
- **Tenancy Deposits and the Renters' Rights Act (22 Nov).** GOV.UK's deposit protection pages still carry a banner saying private renting rules changed on 1 May 2026, so the page was re-checked against the current guidance. The deposit figures did not change (5 weeks under £50,000 annual rent, 6 weeks at £50,000 or more, holding deposit 1 week, protection within 30 days, court up to 3 times). The wording did: most assured shorthold tenancies became assured periodic tenancies on 1 May 2026, the Tenant Fees Act now covers assured periodic tenancies, student accommodation and licences (England only) and excludes social housing, shared ownership, holiday lets and rent above £100,000, the early-termination payment is now a payment when the tenant gives too little notice, and damages for breach of contract are an allowed payment. The text now says so, and the two current GOV.UK guides are reference sources (see the card decision under "Needs Richard").
- **Tenancy Deposits cards updated** (`data/imports/tenancy-deposits-cards-2026-11.csv`): see the done item under "Needs Richard".
- **Remaining program findings on the first-8 pages are judged acceptable**: "usually" where the source says it (Council Tax HMO bands, the SDLT non-resident surcharge) and fixed legal figures (Minimum Pension Age 50 and 11, Universal Credit 35 and 250, Buying Your First Home 183 and 12). The AI check can still disagree. (An earlier summary of these pages said "no program findings"; that was wrong for these four.)
- **Card notes fixed:** the Base Rate page's SVR and 2-year fixed notes, the savings Bank Rate note, and the two daily-job notes in `scripts/fetch-boe-rates.php` (it rewrites them at 07:00, so the fix had to be in the code).
- **NS&I variable rates** updated (4.05, 4.00, 4.00, 3.90) and the four held proposals rejected. **ISA sources** moved to newsletter 23 and the Junior ISA page.
- `import-facts.php` gained an optional `source_match_text` column (used for the Australian shiftworker leave card).

**Cost and spend**
- The `ffl-sources` spend rise on 20 and 21 Nov most likely came from the Australia launch backlog in `check-page-text.php` (5 pages a night, up from 1 or 2) plus `check-sources.php` asking the AI about the same unresolved figures every day. That is my reading of the cron history, not confirmed against the Console. Fixed: `check-sources.php` no longer asks about a figure that already has a held proposal. Check the Console's daily total once the backlog clears (about 23 Nov).

**Housekeeping**
- Admin queue wording now says when nightly drafting is paused. `CLAUDE.md` and the page checklist are current. Plain commit messages, no Co-Authored-By.
- **SSH permission moved out of the repo (22 Nov).** The two `ssh -p 65002 ...` allow rules now live in `.claude/settings.local.json`, git-ignored (a line in `.gitignore`), and `.claude/settings.json` is back to its committed deny rules only, so `git status` is clean.
- **This file renamed** from `docs/TODO-HANDOVER.md` to `docs/TODO.md` (22 Nov), now that the handover is complete.

---

### Earlier, in the claude.ai chat

#### Domain switchover (Oct 2026)
- `robots.txt` updated to point `Sitemap:` at the new domain
- Sitemap resubmitted in the new Search Console property
- Live site clicked through: home, a category page, a subject page, About, Disclaimer, Privacy and `/admin/`
- Local `SITE_URL` in `config/database.local.php` and the `.example` placeholder updated
- Homepage indexing requested via URL Inspection in the new property
- Test email to `info@financefactlibrary.com` sent
- **Change of Address dropped.** Search Console's tool kept failing on the old non-www homepage fetch (most likely Hostinger's edge handling of Google's fetcher). For a site this young it adds little, and the 301 redirects do the real work.

#### Cron and housekeeping
- Cron job outputs checked (`fetch-search-console.php` and the two weekly jobs)
- Old database deleted in hPanel
- `config/database.local.php.bak` removed from the server
- Old **financial-facts** Google Cloud project shut down

#### Content and site
- Homepage intro and legal pages (Disclaimer, Privacy, About) revised for the US, Canada, Australia and New Zealand
- All five UK categories completed: Tax, Savings & Investments, Pensions, Mortgages & Property, Work & Benefits. The site went from 27 to 53 pages (26 new).
- **Base Rate page fix confirmed live** (commit `2356b02`, 16 Oct 2026):
  - Bank Rate is now the first figure card, ahead of linked figures
  - The "What's changed" query excludes unchanged values, so the bogus SVR "6.58% to 6.58%" entry is gone
  - The wrong "4.25% to 3.75%" entry for 18 Dec 2025 is gone; only the correct "4% to 3.75%" remains
  - `reorder-figure-cards.csv` run once on the server
- 5 November 2026 Bank Rate update done

#### Multi-country
- Pilot country chosen: **Australia** (2 Nov 2026), built with its eight states and territories as sub-regions
- Australia launched at 15 written pages and switched on (`is_active = 1`) on the server on 19 Nov 2026

---

## 4. Open to-do list (as of 22 Nov 2026)

### Needs Richard
- [x] **"AS TYPED" warning tested (26 Nov 2026, Richard's screenshot):** on the VAT page, a 216-character meta description showed the red "AS TYPED it is 216 characters, over the 200 the page can hold, so it cannot be saved" message, and the meta title showed its own length warning.
- [x] **API balance:** Richard tops up when the Console emails a low-balance notice. No reminders needed (26 Nov 2026).
- [x] **Student Loan card notes:** already fixed on the live site (checked 26 Nov): the Plan 2 note now says the Student Loans Company has confirmed the threshold stays the same from 6 April 2027 to 5 April 2028, and the Plan 5 note says the first Plan 5 loans came into repayment from April 2026.
- [x] **Bank Rate card note:** no change needed (26 Nov). The "several points above base rate" sentence is on `uk_base_rate_svr`, a retired card that no page shows. The live SVR card (`boe_svr`) says an SVR is set by the lender, not tied to Bank Rate.
- [x] **Welsh LTT card note:** the sentence "Wales has no separate first-time buyer relief" was removed (26 Nov, `data/imports/ltt-nil-rate-note-2026-11-26.csv`): the Welsh Revenue Authority's rates page does not say it either way. Imported on MAMP; server import and push waiting for Richard's go.
- [x] **Junior ISA card note:** already fixed on the live site (checked 26 Nov): the parent-allowance sentence is gone.
- [ ] **Look at the Console's daily `ffl-sources` total once the nightly backlog clears (about 30 Nov, see below).** It will show whether the unexplained $0.25 days have settled to the $0.12 average.
- [x] **Tenancy Deposits cards updated (22 Nov, `data/imports/tenancy-deposits-cards-2026-11.csv`).** The cap and holding deposit cards now cite the current GOV.UK fees guide, the 6-week cards say it applies from £50,000 up to £100,000 (the Act does not cover higher rents), and the court payment label no longer says "on top of the deposit" (GOV.UK only says up to 3 times the original deposit). Values unchanged. The protection deadline and penalty cards keep their current sources.

### Bank Rate (17 Dec 2026)
- [x] Alert built and live (see section 3). On a decision day it compares the Bank's page with `uk_base_rate` from 12:30 London time and emails an "ACTION" message with a draft CSV (`data/imports/bank-rate-YYYY-MM-DD.csv`), a short "held" confirmation, or a "page not updated / unreadable" warning at the 17:00 run.
- [ ] **17 Dec 2026:** check the email arrived, review the draft CSV, then run `php scripts/import-facts.php <file>` on the Mac (MAMP) and then the server. The importer refuses a future `effective_from`, so run it on or after the announcement day. The weekly job does not update Bank Rate.
- [ ] Add the 2028 MPC dates to `BANK_RATE_MPC_DATES` when the Bank publishes them.

### New Zealand follow-ups (added 25 Nov 2026)
NZ is live with 25 pages built (Minimum Wage published, 24 hidden drafts for the drip). Work on the calendar:
- [x] **26 Nov 2026 nightly check batch (Self-Employed NI, Dividend Tax Rates, Inheritance Tax Thresholds, Scottish LBTT, Welsh LTT): done.** 26 findings; real ones fixed one commit per page (`cade302`, `76b87db`, `eeb550b`, `a274bc3`, `8c079e8`), pages removed from the sample, batches recorded (`76df127`). Next batch: 27 Nov, after the 05:30 run (Personal Savings Allowance, State Pension Age, Pension Annual Allowance, Auto-Enrolment Thresholds, Statutory Sick Pay).
- [ ] **27 Nov 2026, after 06:02 UTC:** check the first NZ drip run on the server (`php scripts/run-jobs.php --status`). Expect job #2 to have published "ACC Earners' Levy" (subject 74), the next slot about 56 hours later, no failures, and the live ACC page returning 200. Then spot-check the NZ drip about once a week until all 24 drafts are out (about 8 weeks).
- [x] **One `run-jobs.php` cron row confirmed in hPanel (26 Nov 2026, Richard's screenshots):** a single `*/5 * * * *` row. The other rows are fetch-search-console 06:00, draft-pages 05:00, check-sources 07:30, send-alerts 08:00, fetch-boe-rates 07:00, check-bank-rate 13:00, 15:00 and 17:00, and check-page-text 05:30 with `--pages-file=data/nightly-check-sample.txt`.
- [x] **Nightly AI check for NZ pages decided (26 Nov 2026, Richard): all pages are eligible for the nightly check.** The cap on the fixed UK sample stays until it finishes (about 30 Nov). After the assessment, the cron goes back to checking every written page in a switched-on country (NZ included), so about 100 pages queue at 5 a night (about $7 over about 20 nights at $0.07 a page). Top up the balance first. Richard tops up when the Console emails a low-balance notice, so no reminders about it.
- [x] **`NZ` entry in `COUNTRY_TEXT_RULES` (26 Nov 2026, Richard approved):** em dashes, ordinal dates, a comma between month and year, and double quotation marks are now program-checked for NZ pages (no "programme" rule: NZ English keeps it). Triage over all 25 NZ pages found nothing from the new rules. On the server after the next push.
- [ ] **Retest the held NZ pages** when their sources change (both retested 26 Nov 2026, still held): Student loan repayments (IRD has no 2026-27 threshold; retest all 73 IRD student-loan pages) and Official Cash Rate (rbnz.govt.nz returns HTTP 403). See the key dates below.
- [ ] **Remaining NZ second-wave pages** (all optional; nine second-wave pages were built on 25 Nov): Winter Energy Payment, Family Boost, LVR rules and mortgage rates (RBNZ unreadable), ACC weekly compensation (acc.co.nz unreadable). Plan: `docs/content-maps/nz-content-map.md`.
- [x] **Canada plan written (25 Nov 2026):** `docs/content-maps/ca-content-map.md` (20 pages proposed, sources tested from the Mac, six decisions waiting for Richard: provinces, money, spelling and dates, allowlist, category names, drip pace). Next: Richard's decisions, slug check, allowlist and migrations 035 and 036. The US follows.
- [x] **Bug in `scripts/read-source.php` fixed (26 Nov 2026):** `--find` no longer crashes when a match sits near the end of a short page or `--around` is larger than the page.

### Page text: the routine is built, now apply it
The routine is in `docs/page-checklist.md`: free triage, then read the sources, fix, close-copy check, reference sources, import on MAMP then the server, check the live page. Each page is a round of reading its sources, fixing the text, importing it and checking the live page, and then the nightly AI check re-reads it (roughly $0.05 a page by my estimate; the real figure is in the Console).
- [x] All 23 written UK pages done (section 3), including the whole "first 8" priority list and Cash ISA Allowance. Six more UK pages were written on 22 Nov night (see section 3), and three more on 23 Nov, and thirty more on 23 and 24 Nov, so the server now has 68 written pages (53 UK, 15 Australia) and no UK page without an explanation.
- [x] **Near-copy check built into the triage (22 Nov).** `php scripts/triage-page-text.php --page=<slug>` now runs it by default, `--close-copies` runs it for the whole list, and `--no-close-copies` skips it. It checks the filled-in text against the card and reference sources, reports an unreadable source as a finding, and stops flagging a hedge word a source says itself. The whole-list run found close copies only on Australian pages and a MAMP-only draft; all 15 Australian pages have since been fixed (below).
- [x] **All 15 Australian written pages done (22 Nov), one commit each, imported on the server** (13 are drafts for the drip, Medicare Levy and Tax-Free Threshold are live and were checked live). Each was read claim by claim against its Services Australia, ATO, Fair Work or RBA source and reworded until the near-copy check showed none. Real errors fixed: compassionate leave shown as unpaid for everyone (Personal and Carer's Leave), the pre-birth claim rule reversed (Parental Leave Pay), the Medicare levy and surcharge described as always charged (Income Tax Rates), an over-broad dependent-child rule (JobSeeker), figure history (Super Guarantee Rate). The four national Australian domains (`ato.gov.au`, `fairwork.gov.au`, `rba.gov.au`, `servicesaustralia.gov.au`) were added to `FACT_SOURCE_ALLOWLIST` because none was on it, so reference sources were impossible; 9 Australian reference sources now exist. Remaining notes on these pages are fixed legal figures (90 days, ages 55, 60, 75, 30 June). The nightly AI check will re-read them; results to be recorded.
- [x] **All UK pages drafted in Claude Code (22 to 24 Nov 2026).** None is left without an explanation. Nightly drafting stays paused (`DRAFTS_PAUSED`). From here the work on UK pages is keeping them right: the nightly checks, figure updates (see key dates) and the dated text listed below.
- [ ] **Nightly check capped to a fixed sample (24 Nov 2026).** The 05:30 cron now runs `check-page-text.php --pages-file=data/nightly-check-sample.txt`: the 30 UK pages written in Claude Code, 5 a night, so it finishes about 1 Dec and then does nothing. Other pages (older UK, Australia, New Zealand) are not checked meanwhile. When the 30 have results: assess them (below), then decide whether the check stays off, rotates, or runs only on a source change. Optional later code change: stop a page being re-queued after a fix made in response to a check.
- [ ] **Record the AI results as they arrive.** *(Superseded by the capped sample above: only the 30 sample pages will have new results.)* *Update 24 Nov: 68 of 72 written pages are queued, so the Claude Code pages should all be checked by about 7 Dec; Richard decided to wait for the nightly runs (a note is saved to read the stored results then). Read them with a read-only query on `subject_text_checks` and compare findings per page with the older-page baseline (105 findings on 32 pages: 36 house style, 24 missing exception, 12 contradicts source).* As of 22 Nov evening nothing new had run since the first batch: all 38 written pages (23 UK, 15 Australia) were queued as "text changed since its last check", 5 a night from 05:30 on 23 Nov. The baseline for every page (stored check before the rewrites, kinds of finding) and what to look for are in `docs/reference-sources-evaluation.md`, section "Third pass". Add each night's results there; read any `contradicts source`, `likely wrong` or `missing exception`. Six Australian pages have never had a check. The Console's daily `ffl-sources` total should show the backlog clearing. **The thirty pages written on 22 to 24 Nov (Marriage Allowance, Statutory Sick Pay, Auto-Enrolment, Income Tax Rates and Bands, Inheritance Tax Thresholds, Junior ISA, Self-Employed National Insurance, Scottish Stamp Duty, Student Loan Repayment Thresholds, Pension Annual Allowance, Dividend Tax Rates, Scottish Income Tax Rates, State Pension Age, Employee and Employer NI, PIP and Attendance Allowance, Personal Savings Allowance, Help to Save, Statutory Maternity and Paternity Pay, Statutory Redundancy Pay, Statutory Holiday Entitlement, Pension Credit, Tax-Free Pension Lump Sum, Inheritance Tax on Gifts, Making Tax Digital for Income Tax, Stamp Duty Land Tax Rates, Welsh Stamp Duty LTT, Trading and Property Allowances, Employer Payroll Costs, NS&I Savings Rates, Standard Variable Rate) join that queue, which lengthens it by about six or seven days (my reading); in effect every UK page is now in the queue. Their first results are the independent second opinion on the Claude Code route: record them in `docs/claude-code-drafting-test.md` (row "Nightly AI check, first run") and in `docs/reference-sources-evaluation.md`. If the check finds substantive problems that the claim-by-claim read missed, revisit the new default.**
- *Page-specific traps:* Buy-to-Let (don't say it's dearer than residential; the Bank's figure is lower), Council Tax Bands (England uses 1991 values, Wales 2003), Tenancy Deposits (England only, and written for assured periodic tenancies after the Renters' Rights Act; the old collection page predates it), Carer's Allowance (earnings limit is a cliff edge; Scotland has Carer Support Payment), Universal Credit (health element rules differ for conditions reported before 6 April 2026), rate pages (Base Rate, NS&I, Average Savings Rates, Buy-to-Let: no rate in any field and no "currently").
- *Rules learned (22 Nov night):* when GOV.UK pages disagree with each other or look stale, read the legislation or HMRC's own policy paper and leave the disputed detail out of the text until it is settled; HMRC tables can lag (the residence band end date); a page with any card checked every 42 days or less (the NS&I Junior ISA rate on Junior ISA) counts as a rate page for the "no rate in any field" rule; a placeholder next to "a" or "an" can read wrongly once filled in ("a 8%"), so reword around it; GOV.UK guides have sub-pages with slugs that do not match their titles (`passing-on-home`, `gifts`), so take the links from the page rather than guessing; a typed figure fixed in law with its end date given (£37,700 to 5 April 2031) is acceptable and shows as a triage note.
- *Rules learned:* a heading must be true of everything under it; check which pages a rule covers (UK property against UK residential property); a meta description over 200 characters as typed is cut off; a source the server cannot read (Registers of Scotland returns HTTP 403) cannot be a reference source; a GOV.UK page with a "rules are changing" banner needs the linked guidance read too, because the page body can lag the law; run `triage-page-text.php --page=<slug>` (it now includes the near-copy check) before importing; lesson L13 forbids referring to "the cards on this page" in text, so search new text for "card"; the Services Australia, ATO and Fair Work pages are short and formulaic, so close paraphrases of them score high and need restructuring, not word swaps.

### ISA page (Cash ISA Allowance)
- [x] Card sources updated and imported on MAMP and the server (21 Nov).
- [x] Over-65s line restored (22 Nov): the HMRC policy paper published 17 Sept 2026 states it ("for investors aged 65 or over the annual cash ISA limit will remain at £20000") and is attached as a reference source. The page's cash cut for under-65s from 6 April 2027 is confirmed by the same paper.
- [ ] **After 6 April 2027:** change "due to come into force" to "in force".

### Small items
- [x] `scripts/read-source.php --find=... --around=N` crash: fixed 26 Nov 2026 (see above).
- [ ] NS&I Green Savings Bond: the server has no `effective_from` (the change was approved on the review page on 30 Oct). Cosmetic; `import-facts.php` cannot fill it in for an unchanged value. Left as is.
- [ ] Universal Credit Rates' meta description is exactly 200 characters and complete. The quick check shows "may have been cut off" on it; harmless.

### Phase 3 step 1 (job queue and AI-proposed new figures)
- [ ] The work from an earlier chat was never committed and is not in the repo, so there is nothing to give a migration number to: it would be a rebuild. Park it, or scope it again when wanted. Next free migration number is **032**.

### Multi-country expansion
- [ ] Australia drip: about 3 pages a week (`run-jobs.php` every 5 minutes; keep exactly one worker row). About 17 of the 19 written pages are drafts waiting for it, so they publish through about the first week of January. Nothing is waiting on page text.
- [x] **The last four Australian pages** are written and on the server (24 Nov); see the content map.
- [ ] **Medicare levy low-income thresholds:** add to the Medicare Levy page when the ATO sets the 2026–27 ones.
- [ ] Country-neutral pieces (staging, review step, drip scheduler that only publishes a page once its explanation is written) are partly built; see `docs/handover-2026-11-21-australia-launch.md`.
- [ ] Then New Zealand, Canada and the US, each launching at about 15 to 20 pages.

### Ongoing
- [ ] Keep financial-facts.com registered and redirecting for at least a year (renew if the renewal date comes sooner).

---

## 5. Key dates

| Date | What |
|---|---|
| **25 Nov to about 1 Dec 2026** | The nightly AI check works through the fixed 30-page sample, 5 a night from 05:30 (cron changed 24 Nov); then it does nothing. Record results in `docs/claude-code-drafting-test.md` and `docs/reference-sources-evaluation.md` |
| **8 Dec 2026** | RBA cash rate decision (2.30 pm Sydney time): the Australian cash rate card is source-checked daily, so check it updates |
| **17 Dec 2026** | MPC decision: the Bank Rate watch should email you; update Bank Rate |
| **6 Apr 2027** | ISA reform rules come into force: update the ISA page wording. Also: Making Tax Digital threshold drops to £30,000, cash ISA limit for under-65s becomes £12,000, new savings tax rates apply. |
| **After 6 April 2027** | Personal Savings Allowance text: the paragraph saying savings tax rates are "due to rise from April 2027" needs rewording; also the Cash ISA wording noted above |
| **After 6 April 2027 (Making Tax Digital)** | Making Tax Digital for Income Tax text names the 2026, 2027 and 2028 stages and says partnerships have no start date yet; reword once the 2027 stage has started or the partnership date is set |
| **After 1 July 2027 (Capital Gains Tax Discount, Australia)** | The page describes the 50% discount and the change from 1 July 2027 (indexation, 30% minimum tax); reword once the change has started |
| **6 April 2027 (Student Loan Repayment Thresholds)** | The Student Loans Company announcement of 12 Nov 2026 says the Plan 5 threshold rises to £26,025 from 6 April 2027 and the Plan 2 threshold stays the same until 5 April 2028: update the Plan 5 card (and check the page text) on the day |
| **1 April 2027 (Canada, Federal Minimum Wage)** | The federal rate is adjusted every April 1. Re-read the Service Canada pay page, update `ca_mw_federal` (new value, `previous_value` 18.15, `effective_from` 2027-04-01) and re-check the page text. The page's source is `.../federal-labour-standards/pay-deductions.html`; the CPI rule is on the ESDC news release |
| **January 2027 (Canada, Batch A)** | Every 1 January the federal bands, basic personal amount, CPP and CPP2 limits are indexed. Re-read the CRA current-year tax rates page, the indexation page and the two payroll pages, update the cards (new value, `previous_value`, `effective_from`) and re-check the text. Retest the CRA CPP page: it already shows a 2027 row with a rate of 5.75% (against 5.95% for 2026), which may be an error on the page. The GST/HST rates and the $30,000 small supplier limit change only with the law |
| **After 31 Dec 2028 (Canada, Home Buyers' Plan)** | The text says repayment relief (first repayment in the fifth year) applies to a first withdrawal made from January 1, 2022 to December 31, 2028, and the `ca_hbp_defer` card says the same. Re-read the CRA Home Buyers' Plan page before then (it may extend the relief again) and reword once it has ended |
| **January 2027 (Canada, Batch B)** | The TFSA dollar limit and the RRSP dollar limit change each January 1 (the CRA limits table already lists a 2027 RRSP limit and a 2026 TFSA row). Update `ca_tfsa_limit` and `ca_rrsp_limit` (`previous_value`, `effective_from`), and re-check the FHSA, HBP and RRSP pages. The FHSA limits and HBP limit change only with the law |
| **1 January, 1 April, 1 July, 1 October (Canada, OAS and GIS)** | Old Age Security and the Guaranteed Income Supplement are indexed quarterly. The cards hold the October to December 2026 maximums (`ca_oas_max_65`, `ca_oas_max_75`, `ca_gis_single_max`, `ca_gis_couple_max`), and the Service Canada payments page says OAS rises 0.4% for January to March 2027. Re-read the OAS payments page and the GIS benefit-amount page each quarter, update the cards (`effective_from`), and check the income limits (reset each July) and the recovery tax threshold |
| **January 2027 (Canada, Batch C)** | Update EI premiums (`ca_ei_*`: the CRA table already lists a 2027 row), re-read the CPP retirement maximum and average on the Service Canada CPP amount page (maximum is dated January 2026, average July 2026) and re-check the three pages |
| **1 July each year (Canada, CCB and CGEB)** | The Canada child benefit and the Canada Groceries and Essentials Benefit are indexed on July 1 and their payment year runs July to June. Re-read the CRA indexation page (CCB cards) and the CGEB payment-amounts and who-is-eligible pages, update the cards (`previous_value`, `effective_from`) and re-check both texts. The CRA's own CCB pages lagged the indexation page in 2026 (still showing July 2025 to June 2026), so check which page is current before trusting either |
| **2031 (Canada, CGEB)** | The text says the July 2026 25% increase to the CGEB is meant to last 5 years, from 2026 to 2031. Re-read the CGEB main page before 2031 and reword once the period ends |
| **January 2027 (Canada, EI regular benefits)** | Re-read the Service Canada EI benefit-amount page: the $729 weekly maximum is tied to the yearly maximum insurable earnings ($68,900 for 2026) and changes each January. Check the page for any temporary EI measures that change the hours needed |
| **April 2028** | Help to Save text: the last paragraph says eligibility will widen then; reword once it has |
| **Every April** | Most UK rates, allowances and benefit amounts change |
| **20 March and 20 September** | Services Australia updates payment rates (Age Pension, JobSeeker): the cards are source-checked daily |
| **1 July** | Most Australian rates change (Minimum Wage, super guarantee, Parental Leave Pay, tax thresholds): re-read the Australian pages' figures and wording |
| **26 Nov 2026** | After the 05:30 nightly check: read and record the second batch of five UK results (see section 4, New Zealand follow-ups) |
| **27 Nov 2026 onwards (New Zealand)** | The NZ drip (job #2, 3 a week) publishes the 24 remaining draft pages in id order, one about every 56 hours from 06:02 on 27 Nov; check `php scripts/run-jobs.php --status` on the server after the first run |
| **When IRD updates its student loan pages (New Zealand)** | The Student loan repayments page is held: no readable IRD page states the repayment threshold for 1 April 2026 to 31 March 2027 (IRD's pages give the 2026 tax year only). Retest the IRD student loan pages, then build the page (see `docs/content-maps/nz-content-map.md`) |
| **When the Reserve Bank site is readable (New Zealand)** | The Official Cash Rate page is held: rbnz.govt.nz returns HTTP 403 from the Mac and the server. Retest before building, and read the Monetary Policy Committee's decision calendar live |
| **31 March 2027 (Working for Families, New Zealand)** | The temporary In-Work Tax Credit increase (7,670 a year, 147 a week) ends, and IRD says it may fall back sooner to 97 a week if petrol stays under $3 a litre for four weeks. Re-read the IRD In-Work Tax Credit page in March 2027, then update the card and the text paragraph |
| **1 April (New Zealand)** | Most NZ rates change: minimum wage, ACC earners' levy, NZ Superannuation, Jobseeker Support and the other benefits, the Working for Families tax credits, income tax bands. The Work and Income benefit rates page has the year in its address (`benefit-rates-april-2026`), so the reference source needs a new address each April. Re-read each NZ page's figures and check the KiwiSaver contribution rates for staged changes |
| **1 April 2027 (Accommodation Supplement, New Zealand)** | The homeowner entry threshold rises from 30% to 40% of the relevant base rate for most homeowners (law passed 25 Aug 2026). The Accommodation Supplement page has two cards and a section on it: reword the "now" card and section once it starts, and re-read Work and Income's page |
| **1 April each year (Accommodation Supplement, New Zealand)** | The 12 maximum payments and 8 Jobseeker Support entry thresholds cite the dated `benefit-rates-april-2026` page. Each April find the new year's address, update the card source URLs, and re-read the figures. The area definitions (from 1 April 2018) should be re-read at the same time |
| **Each year (Boarding houses, New Zealand)** | Re-read Tenancy Services' four boarding house pages (bond maximum, rent in advance, rent increase notice, inspection notice, notice to end, the healthy homes penalty). The Tenancy bonds and rent increases page mentions boarding houses only in the note on its rent increase notice card (28 days' written notice), so check that note still agrees with the Boarding houses page whenever either is changed (checked 26 Nov 2026: they agree) |
| **Each quarter: 1 January, 1 April, 1 July, 1 October (Fringe benefit tax, New Zealand)** | Inland Revenue reviews the prescribed interest rate for low-interest loans every quarter. The Fringe benefit tax page has two cards (6.07% for 1 October to 31 December 2026, 6.32% from 1 January 2027) and a paragraph naming both. Re-read IRD's prescribed interest rates page each quarter, update the cards and reword the paragraph so it says nothing out of date |
| **1 April each year (Fringe benefit tax, New Zealand)** | Re-read IRD's FBT pages: the single and alternate rates and the $180,000 level, the $300, $1,200 and $22,500 limits, the vehicle percentages and minimum tax book values (Investment Boost values applied from 1 April 2026), and the filing due dates (20 July, 20 October, 20 January and 31 May) |
| **1 April each year (Veteran's Pension, New Zealand)** | Veteran's Pension rates rise every 1 April with the average wage. Re-read Work and Income's "how much you can get" page and update the three rate cards (they currently match the NZ Super rates, which the source does not say outright). The $160, $250, 30% and 70% income rules should be re-checked at the same time |
| **1 July (New Zealand)** | The KiwiSaver government contribution year runs 1 July to 30 June, and the paid parental leave maximum and self-employed minimum are reset every 1 July (811.05 and 239.50 a week from 1 July 2026): re-read both pages |
| **6 Aug 2028 (New Zealand)** | The Employment Leave Act replaces the Holidays Act. The Annual holidays and Sick and bereavement leave pages carry a paragraph on this; reword both once it starts, and read Employment New Zealand's guidance as it appears |
| **21 Feb 2027 (New Zealand)** | The high income earner dismissal rules' 12-month transition period ends. The Redundancy and notice periods page has a paragraph on the transition and a card for the end date; reword the paragraph and re-read Employment New Zealand's page then |
| **Each July (New Zealand)** | The $200,000 remuneration threshold for high income earners is reviewed each July and changes only if average ordinary weekly earnings rise. Re-read Employment New Zealand's page and update the Redundancy and notice periods card if it changes |
| **Each autumn, before the 2028 dates are due (New Zealand)** | The Public holidays and anniversary days page carries 2027 dates only (Good Friday, Easter Monday, King's Birthday, Matariki, Labour Day and the 12 provincial anniversary days). When Employment New Zealand publishes the 2028 table, replace the 13 date cards and re-run the page checks. The page's wording on exceptions (Taranaki, Hawke's Bay, Marlborough, Canterbury, Westland, Otago, Southland) should be re-read against the source at the same time |
| **Whenever the NZ income tax bands change (New Zealand)** | The Resident withholding tax on interest page has its own cards for the four income band limits (from 31 July 2024) and the rates, taken from IRD's RWT rate page. They repeat the Income tax rates bands, so when either changes, re-read the IRD RWT rate page and update both pages |
| **1 April each year (New Zealand)** | The Child support page carries four yearly figures (living allowance, supported living allowance, minimum rate, inflation factor) from IRD's child support rates page, which lists each year. Add the new year's row to the cards and keep the old values as previous values. The care thresholds (28% and 35%) are fixed in the sharing-care page and only need a re-read |
| **1 April each year, with the Budget (New Zealand)** | Re-read IRD's Independent earner tax credit page. The page's six cards (income limits $24,000, $66,000 and $70,000, $10 a week, $520 a year, 13% reduction) date from 31 July 2024 and the source pages were last updated in 2024 and 2025. Nothing there says they change yearly, but a Budget change would show on that page first |
| **Oct 2027 (approx.)** | Earliest point to let financial-facts.com lapse |

---

## 6. Working preferences to carry over

- Deliver changed or new files as files, not inline code, and list each with its full path from the project root and whether it's **new** or a **replacement**, in a table
- Content and database changes must go through the provided tooling (e.g. the CSV importer), never manual phpMyAdmin edits; build a workaround if needed
- Verify figures fully against sources before handing them over; don't ask Richard to re-check them
- Test imports on MAMP first, then run on the server over SSH
- **No `Co-Authored-By` lines in commit messages.** Plain messages only (Richard's instruction, 21 Nov 2026; earlier commits were rewritten to match).
- Ask before anything that writes to the live content or database, pushes, or changes cron. Read-only checks over SSH are fine.
- Richard's standing authority for page-text work (22 Nov 2026): one commit per page, push, deploy, import and check live without asking each time, and stop only if a card or figure looks wrong, a code, migration, cron or settings change is needed, a content decision is needed, a source cannot be read or contradicts, a deploy or import misbehaves, or anything would cost money.
- Richard's standing authority for review stamps (22 Nov 2026): after he approves a page and it is deployed and imported on the server, run `scripts/mark-page-reviewed.php` there (dry run first) without asking. Stamp only when the dry run says "was: never"; a time means he saved it in the editor and it must not be overwritten. (A stamp that was only mine, from before he approved a later edit, was re-stamped once on 22 Nov, and said so.)
- New pages are drafted in Claude Code and shown to Richard on the MAMP address before anything is committed; he approves, then commit, push, deploy, import and check live.
- One commit per page of text work; after each push, wait for the deploy, run the page-text dry run, then the real import, then the triage, then check the live page.
- Run the free checks (triage, close-copy scoring, length checks) before paying for any AI pass.
