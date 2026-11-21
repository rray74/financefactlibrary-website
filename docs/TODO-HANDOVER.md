# Finance Fact Library: to-do list handover

*Summary of the claude.ai "Finance fact library to-do list" chat, for moving day-to-day work into Claude Code. Written 21 November 2026. Read alongside the cost document.*

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
| **Anthropic API keys** | Split by purpose (5 Nov 2026): `ffl-pages` (live editor, polish jobs, nightly drafting), `ffl-multicountry` (multi-country work and local testing), `ffl-sources` (`check-sources.php`, `check-page-text.php`). Scripts choose a key with `AI_KEY_PURPOSE`. Retire the old `ff-website` key once it shows no spend. |

---

## 3. What was completed during this chat

### Domain switchover (Oct 2026)
- `robots.txt` updated to point `Sitemap:` at the new domain
- Sitemap resubmitted in the new Search Console property
- Live site clicked through: home, a category page, a subject page, About, Disclaimer, Privacy and `/admin/`
- Local `SITE_URL` in `config/database.local.php` and the `.example` placeholder updated
- Homepage indexing requested via URL Inspection in the new property
- Test email to `info@financefactlibrary.com` sent
- **Change of Address dropped.** Search Console's tool kept failing on the old non-www homepage fetch (most likely Hostinger's edge handling of Google's fetcher). For a site this young it adds little, and the 301 redirects do the real work.

### Cron and housekeeping
- Cron job outputs checked (`fetch-search-console.php` and the two weekly jobs)
- Old database deleted in hPanel
- `config/database.local.php.bak` removed from the server
- Old **financial-facts** Google Cloud project shut down

### Content and site
- Homepage intro and legal pages (Disclaimer, Privacy, About) revised for the US, Canada, Australia and New Zealand
- All five UK categories completed: Tax, Savings & Investments, Pensions, Mortgages & Property, Work & Benefits. The site went from 27 to 53 pages (26 new).
- **Base Rate page fix confirmed live** (commit `2356b02`, 16 Oct 2026):
  - Bank Rate is now the first figure card, ahead of linked figures
  - The "What's changed" query excludes unchanged values, so the bogus SVR "6.58% to 6.58%" entry is gone
  - The wrong "4.25% to 3.75%" entry for 18 Dec 2025 is gone; only the correct "4% to 3.75%" remains
  - `reorder-figure-cards.csv` run once on the server
- 5 November 2026 Bank Rate update done

### Multi-country
- Pilot country chosen: **Australia** (2 Nov 2026), built with its eight states and territories as sub-regions
- Australia launched at 15 written pages and switched on (`is_active = 1`) on the server on 19 Nov 2026

---

## 4. Open to-do list (as of 21 Nov 2026)

### Bank Rate alert on MPC decision days
- [x] Built and live (21 Nov 2026): `scripts/check-bank-rate.php` (logic and decision dates in `includes/bank-rate-watch.php`). Three hPanel cron rows run it at 13:00, 15:00 and 17:00 daily; it only acts on MPC decision days.
- On a decision day (from 12:30 London) it compares the Bank's page with `uk_base_rate`. A difference gives an immediate "ACTION" email with a draft CSV (`data/imports/bank-rate-YYYY-MM-DD.csv`); a held rate gives a short confirmation; a page not yet updated, or unreadable, is emailed on the 17:00 run.
- It never changes the database. You still run `import-facts.php` (MAMP first, then the server).
- Add the 2028 MPC dates to `BANK_RATE_MPC_DATES` when the Bank publishes them.
- [ ] After the first real decision (17 Dec 2026), check the email arrived and the draft CSV imports cleanly.

### Commit and sync
- [x] Patched `scripts/check-sources.php` committed (12 Nov, `44a6050`)
- [x] `mortgages-boe-2026-11.csv` and `nsandi-green-2026-11.csv` committed (12 Nov)
- [x] NS&I Green Savings Bond (checked 21 Nov 2026): MAMP and server both show 4.73%, matching the live NS&I page (Issue 10). Only the dates differ: the server's 4.45 to 4.73 change was approved on the review page on 30 Oct with no `effective_from` (MAMP has 20 Oct). `import-facts.php` only marks an unchanged value verified, so it can't fill that date in. Cosmetic; left as is.

### Bank of England base rate (the alert drafts the CSV; the import is still manual)
- [ ] **17 Dec 2026:** after the decision, use the draft CSV the watch emails you (or make one from the template `mortgages-boe-2026-11.csv`). Set `value`, the date in the `context` text, `last_updated` and `effective_from`, then run:
  ```
  php scripts/import-facts.php data/imports/base-rate-update.csv
  ```
  The importer refuses a future `effective_from`, so run it on or after the announcement day. The weekly job does not update Bank Rate.

### ISA reform page (Cash ISA Allowance, `cash-isa-allowance`)
- [x] Card sources updated (21 Nov 2026) in `data/imports/isa-sources-2026-11.csv`: the £20,000 and £12,000 figures now cite GOV.UK's Tax-free savings newsletter 23 (September 2026); the Junior ISA £9,000 cites GOV.UK's Junior ISA page. Imported on MAMP and on the server (21 Nov 2026); sources confirmed on both.
- [ ] Optional: find an official GOV.UK page that states people aged 65 and over can still put £20,000 into cash ISAs, then restore that line in the context text (newsletter 23 doesn't say it).
- [ ] **After 6 April 2027:** revisit the page and change "due to come into force" to "in force".

### Page text (every page, all four fields)
- [ ] Set up the procedure first: a repeatable page-by-page routine of draft → rewrite in Richard's own words → figures check, covering intro, explanation, meta title and meta description
- [ ] Add a regular content check, so pages are reviewed again after they're written
- [ ] Work through every page, starting with Universal Credit, Council Tax Bands, Base Rate, Carer's Allowance, Redundancy Pay, VAT, Marriage Allowance and Tenancy Deposits
- [ ] Keep the rate pages (Base Rate, NS&I, Average Savings Rates, Buy-to-Let) free of "currently X%" wording
- [ ] Plan for scale: the same procedure must handle hundreds of pages once other countries are added
- *Page-specific traps noted earlier:* Buy-to-Let (don't say it's dearer than residential; the Bank's figure is currently lower), Council Tax Bands (England uses 1991 values, Wales 2003), Tenancy Deposits (England only), Carer's Allowance (earnings limit is a cliff edge; Scotland has Carer Support Payment), Universal Credit (health element rules differ for conditions reported before 6 April 2026).

### Phase 3 step 1 (uncommitted)
- [ ] The job queue and AI-proposed new figures work from an earlier chat was never committed. Its migration was numbered 012, but `012_subject_drafts.sql` now uses that number, so if the work is picked up again, give its migration the next free number in `sql/migrations`.

### Multi-country expansion
- [ ] Start the Australia drip: about 3 pages a week
- [ ] Build the country-neutral pieces (job worker, staging, review step, drip scheduler that only publishes a page once its explanation is written)
- [ ] Then New Zealand, Canada and the US, each launching at about 15–20 pages

### Ongoing
- [ ] Keep financial-facts.com registered and redirecting for at least a year (renew if the renewal date comes sooner)

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
