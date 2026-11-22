# Finance Fact Library: to-do list handover

*Running to-do list and handover. Started 21 November 2026 from the claude.ai "Finance fact library to-do list" chat; rewritten 22 November 2026 after the first long Claude Code session. Read alongside `docs/api-costs.md` (key setup and spend) and `docs/page-checklist.md` (the per-page routine).*

---

## 1. What this chat was for

This chat held the project's running to-do list. Items were added by pasting them in, ticked off when Richard confirmed them (or when another chat showed them done), and the full list was reprinted on request. The actual build work happened in other chats; this one only tracked status.

From now on, the list lives in this file. Update it in the repo as items are done or added.

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
| **Claude Code on the server** | Claude Code can SSH to the server with exactly `ssh -p 65002 -o BatchMode=yes -o ConnectTimeout=10 u888389356@31.170.164.28 '<command>'` (allowed in `.claude/settings.json`). Read-only steps and exports are fine; anything that writes content or the database on the server is asked about first. Hostinger's deploy applies migrations automatically on every push. |
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
- Official pages a page's text rests on that no figure card cites, read by the AI check, Propose fix, drafting and polish, and fetched daily by `check-sources.php` so a change is noticed. 27 are registered across 13 pages (`data/imports/reference-sources-2026-11.csv`). Allowlisted domains only, and the importer refuses a page the server cannot read.
- `excerpt_from` (migration 031) starts reading a long page at a phrase, because the AI check cuts each source at 50,000 characters.
- Evaluation and results so far: `docs/reference-sources-evaluation.md`.

**Content**
- **14 UK pages finished and live**, each checked claim by claim against its sources, rewritten where needed, and verified on the live site: Income Tax Personal Allowance, Bank of England Base Rate, Carer's Allowance, Capital Gains Tax Rates, Buy-to-Let Mortgage Rates, 2-Year Fixed Mortgage Rates, Pension Tax Relief, State Pension Amount, Child Benefit, Approved Mileage Rates, Average Savings Rates, Corporation Tax Rates, National Minimum Wage and National Living Wage, Lifetime ISA. The Cash ISA Allowance page also had a follow-up (22 Nov): over-65s card note restored, safeguards section corrected. A second pass (22 Nov) acted on the first AI re-check for Capital Gains Tax, 2-Year Fixed, Carer's Allowance and Pension Tax Relief.
- **Card notes fixed:** the Base Rate page's SVR and 2-year fixed notes, the savings Bank Rate note, and the two daily-job notes in `scripts/fetch-boe-rates.php` (it rewrites them at 07:00, so the fix had to be in the code).
- **NS&I variable rates** updated (4.05, 4.00, 4.00, 3.90) and the four held proposals rejected. **ISA sources** moved to newsletter 23 and the Junior ISA page.
- `import-facts.php` gained an optional `source_match_text` column (used for the Australian shiftworker leave card).

**Cost and spend**
- The `ffl-sources` spend rise on 20 and 21 Nov most likely came from the Australia launch backlog in `check-page-text.php` (5 pages a night, up from 1 or 2) plus `check-sources.php` asking the AI about the same unresolved figures every day. That is my reading of the cron history, not confirmed against the Console. Fixed: `check-sources.php` no longer asks about a figure that already has a held proposal. Check the Console's daily total once the backlog clears (about 23 Nov).

**Housekeeping**
- Admin queue wording now says when nightly drafting is paused. `CLAUDE.md` and the page checklist are current. Plain commit messages, no Co-Authored-By.

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
- [ ] **Decide on `.claude/settings.json`**: it holds the SSH permission rules and shows as modified. It has been left out of every commit.

### Bank Rate (17 Dec 2026)
- [x] Alert built and live (see section 3). On a decision day it compares the Bank's page with `uk_base_rate` from 12:30 London time and emails an "ACTION" message with a draft CSV (`data/imports/bank-rate-YYYY-MM-DD.csv`), a short "held" confirmation, or a "page not updated / unreadable" warning at the 17:00 run.
- [ ] **17 Dec 2026:** check the email arrived, review the draft CSV, then run `php scripts/import-facts.php <file>` on the Mac (MAMP) and then the server. The importer refuses a future `effective_from`, so run it on or after the announcement day. The weekly job does not update Bank Rate.
- [ ] Add the 2028 MPC dates to `BANK_RATE_MPC_DATES` when the Bank publishes them.

### Page text: the routine is built, now apply it
The routine is in `docs/page-checklist.md`: free triage, then read the sources, fix, close-copy check, reference sources, import on MAMP then the server, check the live page. Each page is a round of reading its sources, fixing the text, importing it and checking the live page, and then the nightly AI check re-reads it (roughly $0.05 a page by my estimate; the real figure is in the Console).
- [x] 14 pages done (section 3).
- [ ] **Apply the same routine to the other written UK pages**, in this order (most AI findings first): VAT Rates (5), Council Tax Bands (3), Universal Credit Rates (1, but it is the largest card page), Minimum Pension Age (3), Premium Bonds (3), Cash ISA Allowance (3), Deposit Protection (3), Buying Your First Home (3), Tenancy Deposits (3). Check the Buy-to-Let style traps in the list below.
- [ ] **Australian written pages** (14, in the drip) need the same treatment before or as they go live. Cash Rate Target is a rate page and needs a real rewrite.
- [ ] **Draft the 30 UK pages that have no explanation yet.** By category: Mortgages 1 (standard-variable-rate); Pensions 5 (auto-enrolment-pension, pension-annual-allowance, pension-credit, state-pension-age, tax-free-pension-lump-sum); Savings 4 (help-to-save, junior-isa-allowance, nsandi-savings-rates, personal-savings-allowance); Tax 13 (dividend-tax-rates, employee-national-insurance, income-tax-rates-and-bands, inheritance-tax-gifts, inheritance-tax-thresholds, making-tax-digital-income-tax, marriage-allowance, scottish-income-tax-rates, scottish-stamp-duty-lbtt, self-employed-national-insurance, stamp-duty-land-tax-rates, trading-and-property-allowances, welsh-stamp-duty-ltt); Work & Benefits 7 (employer-payroll-costs, pip-and-attendance-allowance, statutory-holiday-entitlement, statutory-maternity-paternity-pay, statutory-redundancy-pay, statutory-sick-pay, student-loan-repayment-thresholds). This is paid AI work: about $0.43 a page, about $13 in all. Nightly drafting stays paused (`DRAFTS_PAUSED`).
- [ ] **Record the AI results as they arrive.** `check-page-text.php` re-checks 5 pages a night (19 were waiting on 21 Nov). Add each batch to `docs/reference-sources-evaluation.md`; the second-pass pages are due a second look. One run is a sample, not a verdict (the checker is not stable).
- *Page-specific traps:* Buy-to-Let (don't say it's dearer than residential; the Bank's figure is lower), Council Tax Bands (England uses 1991 values, Wales 2003), Tenancy Deposits (England only), Carer's Allowance (earnings limit is a cliff edge; Scotland has Carer Support Payment), Universal Credit (health element rules differ for conditions reported before 6 April 2026), rate pages (Base Rate, NS&I, Average Savings Rates, Buy-to-Let: no rate in any field and no "currently").
- *Rules learned:* a heading must be true of everything under it; check which pages a rule covers (UK property against UK residential property); a meta description over 200 characters as typed is cut off; a source the server cannot read (Registers of Scotland returns HTTP 403) cannot be a reference source.

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
- [ ] Australia drip: about 3 pages a week, running (`run-jobs.php` every 5 minutes; keep exactly one worker row).
- [ ] Country-neutral pieces (staging, review step, drip scheduler that only publishes a page once its explanation is written) are partly built; see `docs/handover-2026-11-21-australia-launch.md`.
- [ ] Then New Zealand, Canada and the US, each launching at about 15 to 20 pages.

### Ongoing
- [ ] Keep financial-facts.com registered and redirecting for at least a year (renew if the renewal date comes sooner).

---

## 5. Key dates

| Date | What |
|---|---|
| **17 Dec 2026** | MPC decision: the Bank Rate watch should email you; update Bank Rate |
| **6 Apr 2027** | ISA reform rules come into force: update the ISA page wording. Also: Making Tax Digital threshold drops to £30,000, cash ISA limit for under-65s becomes £12,000, new savings tax rates apply. |
| **Every April** | Most UK rates, allowances and benefit amounts change |
| **Oct 2027 (approx.)** | Earliest point to let financial-facts.com lapse |

---

## 6. Working preferences to carry over

- Deliver changed or new files as files, not inline code, and list each with its full path from the project root and whether it's **new** or a **replacement**, in a table
- Content and database changes must go through the provided tooling (e.g. the CSV importer), never manual phpMyAdmin edits; build a workaround if needed
- Verify figures fully against sources before handing them over; don't ask Richard to re-check them
- Test imports on MAMP first, then run on the server over SSH
- **No `Co-Authored-By` lines in commit messages.** Plain messages only (Richard's instruction, 21 Nov 2026; earlier commits were rewritten to match).
- Ask before anything that writes to the live content or database, pushes, or changes cron. Read-only checks over SSH are fine.
- One commit per page of text work; after each push, wait for the deploy, run the page-text dry run, then the real import, then the triage, then check the live page.
- Run the free checks (triage, close-copy scoring, length checks) before paying for any AI pass.
