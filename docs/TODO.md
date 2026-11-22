# Finance Fact Library: to-do list

*The running to-do list for the project. Started 21 November 2026 as a handover from the claude.ai "Finance fact library to-do list" chat (the handover is done, so the file is now simply the list); last updated 22 November 2026, evening. Read alongside `docs/api-costs.md` (key setup and spend) and `docs/page-checklist.md` (the per-page routine).*

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
- [ ] **Open any page in the admin editor, type a long meta description, and check the red "AS TYPED" warning appears.** It is the one part of the meta guard that could not be tested without the login.
- [ ] **Top up and check the API balance** (Console). It was $4.94 on 21 Nov, about 11 pages of drafting. Nothing paid is running without asking.
- [ ] **Look at the Console's daily `ffl-sources` total once the nightly backlog clears (about 30 Nov, see below).** It will show whether the unexplained $0.25 days have settled to the $0.12 average.
- [x] **Tenancy Deposits cards updated (22 Nov, `data/imports/tenancy-deposits-cards-2026-11.csv`).** The cap and holding deposit cards now cite the current GOV.UK fees guide, the 6-week cards say it applies from £50,000 up to £100,000 (the Act does not cover higher rents), and the court payment label no longer says "on top of the deposit" (GOV.UK only says up to 3 times the original deposit). Values unchanged. The protection deadline and penalty cards keep their current sources.

### Bank Rate (17 Dec 2026)
- [x] Alert built and live (see section 3). On a decision day it compares the Bank's page with `uk_base_rate` from 12:30 London time and emails an "ACTION" message with a draft CSV (`data/imports/bank-rate-YYYY-MM-DD.csv`), a short "held" confirmation, or a "page not updated / unreadable" warning at the 17:00 run.
- [ ] **17 Dec 2026:** check the email arrived, review the draft CSV, then run `php scripts/import-facts.php <file>` on the Mac (MAMP) and then the server. The importer refuses a future `effective_from`, so run it on or after the announcement day. The weekly job does not update Bank Rate.
- [ ] Add the 2028 MPC dates to `BANK_RATE_MPC_DATES` when the Bank publishes them.

### Page text: the routine is built, now apply it
The routine is in `docs/page-checklist.md`: free triage, then read the sources, fix, close-copy check, reference sources, import on MAMP then the server, check the live page. Each page is a round of reading its sources, fixing the text, importing it and checking the live page, and then the nightly AI check re-reads it (roughly $0.05 a page by my estimate; the real figure is in the Console).
- [x] All 23 written UK pages done (section 3), including the whole "first 8" priority list and Cash ISA Allowance. The server has 38 written pages (23 UK, 15 Australia) and 30 UK pages with no explanation yet.
- [x] **Near-copy check built into the triage (22 Nov).** `php scripts/triage-page-text.php --page=<slug>` now runs it by default, `--close-copies` runs it for the whole list, and `--no-close-copies` skips it. It checks the filled-in text against the card and reference sources, reports an unreadable source as a finding, and stops flagging a hedge word a source says itself. The whole-list run found close copies only on Australian pages and a MAMP-only draft; all 15 Australian pages have since been fixed (below).
- [x] **All 15 Australian written pages done (22 Nov), one commit each, imported on the server** (13 are drafts for the drip, Medicare Levy and Tax-Free Threshold are live and were checked live). Each was read claim by claim against its Services Australia, ATO, Fair Work or RBA source and reworded until the near-copy check showed none. Real errors fixed: compassionate leave shown as unpaid for everyone (Personal and Carer's Leave), the pre-birth claim rule reversed (Parental Leave Pay), the Medicare levy and surcharge described as always charged (Income Tax Rates), an over-broad dependent-child rule (JobSeeker), figure history (Super Guarantee Rate). The four national Australian domains (`ato.gov.au`, `fairwork.gov.au`, `rba.gov.au`, `servicesaustralia.gov.au`) were added to `FACT_SOURCE_ALLOWLIST` because none was on it, so reference sources were impossible; 9 Australian reference sources now exist. Remaining notes on these pages are fixed legal figures (90 days, ages 55, 60, 75, 30 June). The nightly AI check will re-read them; results to be recorded.
- [ ] **Draft the 30 UK pages that have no explanation yet.** By category: Mortgages 1 (standard-variable-rate); Pensions 5 (auto-enrolment-pension, pension-annual-allowance, pension-credit, state-pension-age, tax-free-pension-lump-sum); Savings 4 (help-to-save, junior-isa-allowance, nsandi-savings-rates, personal-savings-allowance); Tax 13 (dividend-tax-rates, employee-national-insurance, income-tax-rates-and-bands, inheritance-tax-gifts, inheritance-tax-thresholds, making-tax-digital-income-tax, marriage-allowance, scottish-income-tax-rates, scottish-stamp-duty-lbtt, self-employed-national-insurance, stamp-duty-land-tax-rates, trading-and-property-allowances, welsh-stamp-duty-ltt); Work & Benefits 7 (employer-payroll-costs, pip-and-attendance-allowance, statutory-holiday-entitlement, statutory-maternity-paternity-pay, statutory-redundancy-pay, statutory-sick-pay, student-loan-repayment-thresholds). This is paid AI work: about $0.43 a page, about $13 in all. Nightly drafting stays paused (`DRAFTS_PAUSED`).
- [ ] **Record the AI results as they arrive.** As of 22 Nov evening nothing new has run since the first batch: all 38 written pages (23 UK, 15 Australia) are queued as "text changed since its last check", 5 a night from 05:30 on 23 Nov, so about 8 nights (to roughly 30 Nov). The baseline for every page (stored check before the rewrites, kinds of finding) and what to look for are in `docs/reference-sources-evaluation.md`, section "Third pass". Add each night's results there; read any `contradicts source`, `likely wrong` or `missing exception`. Six Australian pages have never had a check. The Console's daily `ffl-sources` total should show the backlog clearing.
- *Page-specific traps:* Buy-to-Let (don't say it's dearer than residential; the Bank's figure is lower), Council Tax Bands (England uses 1991 values, Wales 2003), Tenancy Deposits (England only, and written for assured periodic tenancies after the Renters' Rights Act; the old collection page predates it), Carer's Allowance (earnings limit is a cliff edge; Scotland has Carer Support Payment), Universal Credit (health element rules differ for conditions reported before 6 April 2026), rate pages (Base Rate, NS&I, Average Savings Rates, Buy-to-Let: no rate in any field and no "currently").
- *Rules learned:* a heading must be true of everything under it; check which pages a rule covers (UK property against UK residential property); a meta description over 200 characters as typed is cut off; a source the server cannot read (Registers of Scotland returns HTTP 403) cannot be a reference source; a GOV.UK page with a "rules are changing" banner needs the linked guidance read too, because the page body can lag the law; run `triage-page-text.php --page=<slug>` (it now includes the near-copy check) before importing; lesson L13 forbids referring to "the cards on this page" in text, so search new text for "card"; the Services Australia, ATO and Fair Work pages are short and formulaic, so close paraphrases of them score high and need restructuring, not word swaps.

### ISA page (Cash ISA Allowance)
- [x] Card sources updated and imported on MAMP and the server (21 Nov).
- [x] Over-65s line restored (22 Nov): the HMRC policy paper published 17 Sept 2026 states it ("for investors aged 65 or over the annual cash ISA limit will remain at £20000") and is attached as a reference source. The page's cash cut for under-65s from 6 April 2027 is confirmed by the same paper.
- [ ] **After 6 April 2027:** change "due to come into force" to "in force".

### Small items
- [ ] NS&I Green Savings Bond: the server has no `effective_from` (the change was approved on the review page on 30 Oct). Cosmetic; `import-facts.php` cannot fill it in for an unchanged value. Left as is.
- [ ] Universal Credit Rates' meta description is exactly 200 characters and complete. The quick check shows "may have been cut off" on it; harmless.

### Phase 3 step 1 (job queue and AI-proposed new figures)
- [ ] The work from an earlier chat was never committed and is not in the repo, so there is nothing to give a migration number to: it would be a rebuild. Park it, or scope it again when wanted. Next free migration number is **032**.

### Multi-country expansion
- [ ] Australia drip: about 3 pages a week, running (`run-jobs.php` every 5 minutes; keep exactly one worker row). 13 of the 15 written pages are drafts waiting for it, so they publish through about the end of December. All 15 have now had the full routine, so nothing is waiting on page text.
- [ ] **Four more Australian pages** are planned (15 of 19 written; see `docs/content-maps/au-content-map.md`). They need figures read live, a CSV, then the same page routine.
- [ ] Country-neutral pieces (staging, review step, drip scheduler that only publishes a page once its explanation is written) are partly built; see `docs/handover-2026-11-21-australia-launch.md`.
- [ ] Then New Zealand, Canada and the US, each launching at about 15 to 20 pages.

### Ongoing
- [ ] Keep financial-facts.com registered and redirecting for at least a year (renew if the renewal date comes sooner).

---

## 5. Key dates

| Date | What |
|---|---|
| **23 Nov to about 30 Nov 2026** | The nightly AI check re-reads all 38 written pages, 5 a night from 05:30; record results in `docs/reference-sources-evaluation.md` |
| **8 Dec 2026** | RBA cash rate decision (2.30 pm Sydney time): the Australian cash rate card is source-checked daily, so check it updates |
| **17 Dec 2026** | MPC decision: the Bank Rate watch should email you; update Bank Rate |
| **6 Apr 2027** | ISA reform rules come into force: update the ISA page wording. Also: Making Tax Digital threshold drops to £30,000, cash ISA limit for under-65s becomes £12,000, new savings tax rates apply. |
| **Every April** | Most UK rates, allowances and benefit amounts change |
| **20 March and 20 September** | Services Australia updates payment rates (Age Pension, JobSeeker): the cards are source-checked daily |
| **1 July** | Most Australian rates change (Minimum Wage, super guarantee, Parental Leave Pay, tax thresholds): re-read the Australian pages' figures and wording |
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
- One commit per page of text work; after each push, wait for the deploy, run the page-text dry run, then the real import, then the triage, then check the live page.
- Run the free checks (triage, close-copy scoring, length checks) before paying for any AI pass.
