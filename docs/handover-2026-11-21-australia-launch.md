# Handover: Australia launch (chat summary), 21 November 2026

Written at the end of the Claude chat, ready to move the work into Claude Code. Read this together with the
cost document and `docs/content-maps/au-content-map.md` (the content map is the working to-do list and is
up to date as of 20 November).

## 1. The project in one paragraph

financefactlibrary.com is a PHP/MySQL (MariaDB) site on Hostinger, repo `rray74/financefactlibrary-website`.
Local copy runs on MAMP at http://finance-fact-library:8888/. Live code is at
`~/domains/financefactlibrary.com/app` over SSH, and the server **auto-deploys on every push**, so `git pull`
there usually says "Already up to date". The site is being expanded country by country: country-neutral pieces
(done), **Australia as the pilot (launched 19 November 2026)**, then New Zealand, Canada and the US. Each
country launches at about 15 to 20 pages, then drips 2 to 4 a week.

## 2. Where we have got to

**Australia is live.** `is_active = 1` on the `AU` jurisdiction (id 6, url prefix `au`, tax year from 1 July,
written `2026–27` with an en dash).

| Item | State |
|---|---|
| Pages built | 15 of the 19 planned, all imported as hidden drafts with figures and text on the server |
| Published | 1 so far: **Tax-Free Threshold**, published by hand at 22:35 on 19 November. Live at `/au/tax/income-tax/tax-free-threshold/` (200) and in the sitemap |
| Drip | Job #1 (`drip_country`, AU, 3 a week). After the first page it said "Next slot in 2d 8h", so the second page is due about **Sunday 22 November, 06:35** |
| Cron | One hPanel Cron Jobs row, `*/5 * * * *`, `/usr/bin/php /home/u888389356/domains/financefactlibrary.com/app/scripts/run-jobs.php`. A touch test proved hPanel fires new rows on the 5-minute mark. Not yet proven end to end for the worker, because the next drip was not due |
| Migrations | 027 to 029 applied locally and on the server. Allowlist is 19 official sources |
| Content map | Updated and pushed on 20 November (15 of 19, launch steps, new lessons) |

### The 15 pages built (hidden drafts until the drip publishes them)

Tax-free threshold, Medicare levy, Income tax rates, Minimum wage, Age Pension (batches 1 to 3, earlier);
Super contribution caps (batch 04), GST registration threshold (05), Super guarantee rate (06),
Preservation age (07), JobSeeker Payment (08), Annual leave and Personal and carer's leave (09),
Parental Leave Pay (10), Cash rate target (11), First Home Super Saver Scheme (12).
Figures and sources for each batch are in the build log at the end of the content map.

### Still to build (4 pages)

1. **First Home Guarantee** (page 13): Housing Australia price caps page, the state-layer test (about 16 figures),
   same category (`mortgages-and-property`) and subcategory (`first-home-buyers`) as the FHSS page. I had just
   asked Richard to look up the address with
   `SELECT url FROM sources WHERE url LIKE '%housingaustralia%';` on the **local** MySQL. Not yet answered.
2. **HELP loan repayments** (page 6): find the ATO study and training loan thresholds address first.
3. **Financial Claims Scheme** (page 8): find the fcs.gov.au page that states the protected amount.
4. **Capital gains tax discount** (page 5): address is already in the allowlist. Read the ATO's Federal Budget
   alert first, because the discount may change.

## 3. What is next, in order

1. **Sunday 22 November, after about 06:35:** on the server run `php scripts/run-jobs.php --status` and
   `curl -s https://financefactlibrary.com/sitemap.xml | grep -c "/au/"`. The second page should have published
   with nobody running the worker. This is the proof that the cron row works. It is on the task list.
2. **Cron tidy-up** (not confirmed done): delete the `/usr/bin/date` test row in hPanel, then on the server
   `rm ~/cron-touch.txt ~/cron-test.txt`. Exactly one `run-jobs.php` row should remain.
3. **Build the last four pages** (above), starting with First Home Guarantee. Then update the content map and
   check each new page joins the drip.
4. **Optional:** a heartbeat line in `scripts/run-jobs.php` (one timestamp per run, into the log) so the worker's
   health is visible within five minutes. The file has not been read in full yet (only its first 45 lines).
5. **Optional:** open the nine "not yet reviewed" pages (Super guarantee rate onwards) in the admin and save each
   to mark it reviewed. They publish regardless.
6. Then the loose ends (section 6), then **New Zealand, Canada and the US**, reusing the country files, content
   maps and these lessons.

## 4. How the work is done (the pipeline)

- **Content only via CSV** and `scripts/import-facts.php`. Page explanations only via
  `scripts/import-page-text.php`. **Schema changes only as migration scripts** in `sql/migrations/`, never in phpMyAdmin.
- Facts CSV: 28 columns in the same order as `data/imports/mortgages-boe-2026-10.csv`; `jurisdiction_code` is `AU`
  on every row; the first row of a page creates its category, subcategory and subject with `subject_status` `draft`.
- Placeholders `{fact:key}` and `{tax_year}` must name facts on **earlier rows**, so a page's intro and meta text go
  on a **last row that repeats the first fact key**. `link_type` `also` shares an existing card (the owner is imported first).
- Limits are counted as stored, with placeholders still in: meta description 200 characters, meta title 100.
  Use short fact keys.
- Units used: `%`, `$`, `years`, `days`, `weeks`. History via `previous_value` and `effective_from`. Rate rows use
  `review_frequency_days` 42. `tax_year` is only set for figures that change each year.
- Page text moves between machines with `php scripts/export-page-text.php --country=AU --out=data/imports/<name>.csv`
  (the `--out` path is relative to where it is run) and `import-page-text.php` (fills only empty fields, never
  changes figures or status, never marks reviewed; `--overwrite` replaces differing pages, `--dry-run` reports only).
- **Read every figure live** with `php scripts/read-source.php --url=... [--find=... --around=N | --chars=N]`
  before it goes in a CSV. The ATO, Services Australia, Fair Work Ombudsman and RBA are readable from the Mac and the
  server. (The Claude chat could not reach the ATO, so Richard ran the reads and pasted the output. Claude Code on his
  own machine may not have that limit.)
- Text is checked with the close-copy checker (`findCloseCopies` in `includes/text-checks.php`, threshold 0.6):
  rewrite and rescore until zero close copies. Short source pages make close copies easy.
- House style is in `docs/house-style/core.md`, `uk.md` and `au.md`: Macquarie spelling for Australia, no em dashes,
  dates like 1 July 2026, no hand-written worked examples with numbers, no typed figures that change (use
  placeholders), no typed rate on rate pages, short pages are better than padded ones.
- Jobs: `includes/jobs.php`, `scripts/run-jobs.php` (`--dry-run`, `--status`, `--enqueue=...`), handlers in
  `includes/job-handlers/` (the drip is `drip_country`).

## 5. Standing rules Richard has set

- Share every changed file as a new or replacement file with its project-root path, in an easy-to-read table.
- Explanatory code comments in anything changed.
- Verify every figure against the official source before handing over.
- **Give terminal commands one at a time**, and say which terminal: Mac prompt is
  `richardray@Richards-iMac _financefactlibrary-website %`, server prompt is `[u888389356@uk-fast-web1304 app]$`.
  Output pasted into the wrong terminal has been harmless but wastes a round trip.
- **Do not spend AI credits unnecessarily.** Run the free checks (close-copy scoring, length checks) yourself
  instead of paying for an AI pass.
- Keep draft pages hidden until the drip publishes them.
- Always show the full updated file after changing it.

## 6. Open items and loose ends

- ~~Run the UK base rate update~~ Done: the 5 November update is in `data/imports/mortgages-boe-2026-11.csv` (committed 12 November). Next: 17 December 2026.
- Add a "Reference page" section to `docs/house-style/au.md` (page 1 is approved).
- Lessons approved on one machine (`writing_lessons`) do not transfer between machines: approve again on the
  server or build an export. Optional guard in `includes/lessons.php` against a country style file with no
  "house style" heading.
- Optional: ATO "more than one job" page as a cited source for page 1.
- Files never looked at: `includes/admin-queries.php`, `includes/fact-writer.php`, `includes/review-flags.php`,
  `scripts/run-polish-jobs.php`, `includes/ai-polish.php`, `includes/ai-fixes.php`, and the RBA lending-rate fetcher.
- Optional stricter check for hand-written worked examples (the Medicare levy draft once copied an ATO example).
- Two UK pages flagged by the nightly check: State Pension Amount and Premium Bonds.
- Do **not** deploy or import the two `trial-…personal-allowance.csv` files. Delete them when no longer needed.
- Medicare levy low-income thresholds: add when the ATO sets the 2026–27 ones. GST **rate** is not covered by the
  GST registration page.
- `AI_AUTO_APPLY` defaults to false, so the nightly source check only proposes and never changes a figure.
- Nightly jobs (all in hPanel): fetch-search-console 06:00, draft-pages 05:00 (`DRAFTS_PAUSED` is true on the
  server, so nothing is drafted), check-page-text 05:30, check-sources 07:30, send-alerts 08:00, fetch-boe-rates 07:00.

## 7. Lessons and gotchas from this chat

- **Public addresses** are `/au/<category>/<subcategory>/<slug>/`. Take the real address from the sitemap, do not
  guess it from the slug (`/au/tax-free-threshold` is a 404).
- **hPanel cron:** a custom job that redirects to a file (`>> logs/run-jobs.log`) wrote nothing, and View Output does not
  work for custom jobs. Use the plain form the other jobs use, and test a new row with `/usr/bin/touch ~/file` then
  `ls -l --time-style=full-iso`. `crontab -l` on the server is always empty (hPanel keeps its own list).
  Do not leave two rows running the same worker (it happened once, from a second row nobody remembers creating;
  possibly Hostinger's assistant).
- **A quiet worker is not a broken one:** `run-jobs.php` prints only when a job is due.
- **SQL gotcha:** never compare a bound value with a text literal (collation error 1267 on the live server).
- Before pushing a page-text export, check its word counts against the pages. GST once carried the super guarantee
  text (320 words instead of 504) because a page was saved into the wrong page in the admin; restored with
  `import-page-text.php --overwrite`.
- A guessed ATO address gave HTTP 403. Take real addresses from `SELECT url FROM sources ...`.
- Source-page readability: the ATO needs browser headers after a first refusal; Services Australia and the Fair Work
  Ombudsman need them after a dropped connection; the Fair Work Commission cannot be read by any fetcher;
  `scripts/check-source-access.php` tests every source from the machine it runs on.
- The importer rejects a placeholder that names a fact created on the same row.

## 8. Where things live

| Thing | Location |
|---|---|
| Content map and to-do list | `docs/content-maps/au-content-map.md` |
| House style | `docs/house-style/core.md`, `uk.md`, `au.md` |
| Australian CSVs | `data/imports/au-01-tax-free-threshold.csv`, `au-batch-02.csv` to `au-batch-12.csv` |
| Page-text CSVs | `data/imports/page-text-AU-*.csv` (combined: `page-text-AU-2026-11-19.csv`) |
| Migrations | `sql/migrations/` (023 to 029 for Australia) |
| Local DB | `/Applications/MAMP/Library/bin/mysql -u root -p --socket=/Applications/MAMP/tmp/mysql/mysql.sock finance_facts_library` |
| Live DB user | `u888389356_ffl` |

## 9. Note on the cost document

I have not seen the cost document, so nothing here depends on it. This handover is the narrative and status record;
the cost document should stay the record of spend. The AI spend guidance in section 5 (free checks first) is the
link between the two.
