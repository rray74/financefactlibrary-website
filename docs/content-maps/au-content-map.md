# Australia content map (pilot)

Status: **Australia launched on 19 November 2026** with 15 of the 19 planned pages written. The plan was
first written on 4 November 2026. The planning tables below hold **no figure values**: a value goes into a
CSV only after it has been read off its official source page on the day the CSV is built, as `core.md` and
the handover require (the build log at the end records the figures that were imported). The source addresses
below are the ones in migrations 023 to 029 (`sql/migrations/`).

Australia is live (`is_active = 1` since 19 November). Pages are imported as hidden drafts, written, then
released one at a time by the drip publisher (3 a week). The remaining four pages are built the same way
and join the drip as they are imported.

## To do

Kept up to date as the work goes. `[x]` is done, `[ ]` is still to do. Last updated 20 November 2026.

### Doing now (on the server)

- [x] Migrations applied on the server, through 026 (12 November).
- [x] Figures for the five pages imported on the server (12 November). The importer fix for the
      collation error is deployed.
- [x] The five pages' text is on the server (found on 12 November). The dry run of
      `php scripts/import-page-text.php --dry-run data/imports/page-text-AU-2026-11-11.csv` said all
      five were unchanged, and the pages held their explanations with the review times from the Mac.
      A real import had already run, so there was nothing left to import.
- [x] Hidden means hidden (12 November): the public address for Age Pension answers "Subject not found",
      and the sitemap holds no `/au/` addresses (`curl -s https://financefactlibrary.com/sitemap.xml | grep -c "/au/"` gave 0).
- [ ] Optional: look at one page in the live admin (Admin, Subjects, Super & Retirement, Age Pension) to see
      the cards and placeholders rendered. A database query already showed it is a draft with its explanation.
- [x] `DRAFTS_PAUSED` is true on the server (checked 12 November), so nothing is drafted overnight.

- [x] First overnight run checked (13 November): every cron job `ok`. The 07:30 source check read **302 figures
      and found all 302** (285 the day before plus the 17 Australian ones), with 0 sources unavailable and the AI
      proposing nothing. So the fetcher fix works from the server against the live ATO, Services Australia and
      Fair Work Ombudsman pages. All 17 Australian values were unchanged.
- [x] Confirmed after the first night (13 November): the sitemap has no `/au/` addresses, and the job queue is empty
      (no Australian drip job, nothing failed).
- [x] `AI_AUTO_APPLY` defaults to `false` (`includes/ai-proposer.php`, line 47), so the source check is in trial mode and holds
      every AI proposal. It cannot change a figure overnight unless it is set to `true` in `config/database.local.php`.

### Decisions waiting

- [x] **Nightly AI check on pages in a country that is not switched on: decided to skip them** (12 November).
      `scripts/check-page-text.php` now skips written pages whose country has `is_active = 0`, says how many it
      skipped, and records the count in the dashboard note. `--include-hidden` checks them anyway.
- [x] `scripts/check-page-text.php` deployed and confirmed on the server (12 November). Its dry run read
      "20 written page(s); 2 need checking" and "Skipped 5 written page(s) ... (Australia: 5)", and the
      UK row's `is_active` is 1 (`GB 1`, `AU 0`). Tonight's 05:30 run checks only the two UK pages that were due.
- [ ] A stricter check for hand-written worked examples. The writer is never told about typed
      numbers, because the draft check removes that note on purpose, and the Medicare levy draft
      copied the ATO's $76,000 example. Optional: do it if it happens again.

### Next pages (4 of 19 still to build)

- [x] Super contribution caps (page 10), 16 November: five figures (`au-batch-04.csv`), text written, on the server.
- [x] GST registration threshold (page 4), 16 November: five figures (`au-batch-05.csv`), text written, on the server.
      The page covers registration only. The GST **rate** itself is not on it and is still to add if wanted.
- [x] Super guarantee rate (page 9), 18 November: two figures (`au-batch-06.csv`), migration 027, text on the server.
- [x] Preservation age (page 11), 18 November: six figures (`au-batch-07.csv`), migration 028, text on the server.
- [x] JobSeeker Payment (page 18), 18 November: five figures and a shared Age Pension age card (`au-batch-08.csv`),
      migration 029, text on the server.
- [x] Annual leave (page 16) and Personal and carer's leave (page 17), 19 November: six figures (`au-batch-09.csv`)
      from the Fair Work Ombudsman NES fact sheet, short pages, text on the server.
- [x] Parental Leave Pay (page 19), 19 November: five figures and a shared super guarantee card (`au-batch-10.csv`),
      text on the server.
- [x] Cash rate target (page 7, the rate page), 19 November: four figures (`au-batch-11.csv`), checked every 42 days,
      text on the server.
- [x] First Home Super Saver Scheme (page 14), 19 November: four figures (`au-batch-12.csv`), new category
      Mortgages & Property with the first home buyers subcategory, text on the server.
- [x] Fifteen Australian pages have text on the server, all imported as hidden drafts (19 November). Combined page-text
      file: `data/imports/page-text-AU-2026-11-19.csv`.
- [ ] **First Home Guarantee (page 13)**: Housing Australia's price caps page, which the fetcher can read. It is the
      state-layer test (about 16 figures), in the same category and subcategory as page 14.
- [ ] **HELP loan repayments (page 6)**: find the ATO's study and training loan thresholds address, then read it live.
- [ ] **Financial Claims Scheme (page 8)**: find the page on fcs.gov.au that states the protected amount, then read it live.
- [ ] **Capital gains tax discount (page 5)**: the address is already in the allowlist (migration 024). Read the ATO's
      Federal Budget alert first, because the discount may change.
- [ ] Add the Medicare levy low-income thresholds to page 3 when the ATO sets the 2026–27 ones.
- [ ] Optional: the GST **rate** itself, which the registration page does not cover.

### Launch

- [x] **Launched 19 November 2026.** Preflight on the server showed `AU 0` and 15 draft subjects. `is_active` was then set
      to 1 on the `AU` row, `/au/` answered 200 and the landing page linked correctly. The drip was queued:
      `php scripts/run-jobs.php --enqueue=drip_country --payload='{"country":"AU","per_week":3}' --unique=drip_country:AU`
- [x] **First page published** by running the worker once by hand (22:35, 19 November): Tax-Free Threshold, then
      "Next slot in 2d 8h". It answers 200 at `/au/tax/income-tax/tax-free-threshold/` and is in the sitemap (the
      public address is `/au/<category>/<subcategory>/<slug>/`, not `/au/<slug>`).
- [x] **Cron worker running.** One hPanel Cron Jobs row runs every 5 minutes: `*/5 * * * *` with
      `/usr/bin/php /home/u888389356/domains/financefactlibrary.com/app/scripts/run-jobs.php`. A touch test confirmed
      hPanel fires new rows on the 5-minute mark. See the lessons for what did not work.
- [ ] **Check the drip on or after Sunday 22 November, about 06:35**: `php scripts/run-jobs.php --status` should show the
      second page published without a manual run, and `curl -s https://financefactlibrary.com/sitemap.xml | grep -c "/au/"`
      should have grown. Then check weekly until all 15 pages are out.
- [ ] Clean up the cron tests: delete the `/usr/bin/date` row in hPanel, then on the server
      `rm ~/cron-touch.txt ~/cron-test.txt`. Exactly one `run-jobs.php` row should remain.
- [ ] Optional: a heartbeat line in `scripts/run-jobs.php` so the log shows the worker is alive every 5 minutes
      (needs the file to be read first).
- [ ] Nine pages (super guarantee rate onwards) show "not yet reviewed" because their text was loaded by script. Open each in
      the admin and save to mark it reviewed (optional, they publish regardless).
- [ ] Optional: look at one live page in the new category (First Home Super Saver Scheme) to see the rendering.
- After launch, expect **Medicare Levy** to appear under "Overlapping subjects" on the review page at 100% against
  Income Tax Rates, because the rates page shows the levy's only card. It is advice only, and the two pages have
  different jobs.

### Loose ends

- [x] UK base rate update from the 5 November decision: done (`mortgages-boe-2026-11.csv`, committed 12 November).
- [ ] Add the "Reference page" section to `docs/house-style/au.md` now that page 1 is approved.
- [ ] Lessons approved on one machine (`writing_lessons`) do not transfer. Approve them again on the
      server, or build an export.
- [ ] Optional: a guard in `includes/lessons.php` that refuses a country style file with no "house style" heading.
- [ ] Optional: add the ATO "more than one job" page as a cited source for page 1's multi-payer paragraph.
- [ ] Unseen files still to look at: `includes/admin-queries.php`, `includes/fact-writer.php`,
      `includes/review-flags.php`, `scripts/run-polish-jobs.php`, `includes/ai-polish.php`,
      `includes/ai-fixes.php`, and the RBA lending-rate fetcher.
- [ ] New Zealand, Canada and the US afterwards, reusing all of this (country files, content maps).
- [ ] Do not deploy or import the two `trial-…personal-allowance.csv` files. Delete them when no longer needed.

### Lessons for the next country

- Read every figure live with `scripts/read-source.php`. A cached copy of a rates page can be a year old and carry no date.
- A site that refuses the fetcher is checked with `scripts/check-source-access.php` before any page is built on it.
- Text limits count the stored text with the placeholders still in it.
- In a CSV with multi-line text, a page's first heading shares a line with the start of its row, so
  `grep '^## '` undercounts by one per page. Count with a script, not grep.
- Short source pages make close-to-source sentences easy to write by accident. Score the text with the checker's own function before pasting.
- A short page is better than a padded one: the writer may only state what the cited pages say.
- Check which terminal you are in before pasting a command. The Mac prompt is `richardray@Richards-iMac` and the server prompt is `[u888389356@uk-fast-web1304 app]$`. Output pasted into the wrong one changes nothing, but a command run on the wrong machine tells you nothing.
- The server deploys on every push, so `git pull` there usually says "Already up to date" and the new files and migrations are already in place.
- Before pushing a page-text export, check its word counts against the pages. A page's text can be saved into the wrong page in the admin (GST showed 320 words instead of 504). Restore with `import-page-text.php --overwrite` from an earlier export.
- `export-page-text.php --out=` is relative to the folder it is run from. Use `--out=data/imports/<name>.csv`.
- Rows for another page's card use `link_type` `also` and need the owner's figure imported first (the Age Pension age is on the JobSeeker page this way).
- Page text can be written outside the admin: build a one-row page-text CSV and use `import-page-text.php`. It fills only empty fields and does not mark the page as reviewed.
- Public addresses carry the category and subcategory: `/au/<category>/<subcategory>/<slug>/`. Take the real address from
  the sitemap instead of guessing it from the slug.
- hPanel cron: a custom job that redirects output into a file (`>> logs/run-jobs.log`) wrote nothing, and "View Output"
  does not work for custom jobs. Use the plain form `/usr/bin/php /home/u888389356/.../scripts/script.php` that the other
  jobs use, and prove a new row with a harmless test such as `/usr/bin/touch ~/file` and `ls -l --time-style=full-iso`.
  Never leave two rows running the same worker, and `crontab -l` on the server is always empty because hPanel keeps its own list.
- A quiet worker is not a broken one: `run-jobs.php` prints only when a job is due, so a drip with days to wait shows
  nothing in between. The dry run (`--dry-run`) and `--status` are the checks.
- Never compare a bound value with a text literal in SQL. It failed on the live server's connection settings
  (error 1267) although it passed every local test. `scripts/debug-sql.php` shows the statement that failed.

## The launch set: 19 pages

The plan is to launch at 15 to 20 pages and then drip 2 to 4 a week. Slugs are
proposed, and the collision check further down settles the final ones. Fact keys
start `au_`.

### Tax (6 pages, 3 subcategories)

| # | Page and proposed slug | Planned figures (labels only) | Source | Changes when |
|---|---|---|---|---|
| 1 | **Tax-free threshold** `tax-free-threshold` (the reference page) | Tax-free threshold for a full-year resident | ATO how to claim the tax-free threshold (confirmed) | When the law changes |
| 2 | **Income tax rates** `income-tax-rates-australia` | Four bracket thresholds, four marginal rates (8) | ATO tax rates, Australian resident (confirmed) | 1 July, and when a tax cut is legislated |
| 3 | **Medicare levy** `medicare-levy` | Levy rate (1). The low-income thresholds wait: the ATO publishes them for 2025–26 only, which ended on 30 June 2026, and 2026–27 is not set yet | ATO what is the Medicare levy (confirmed) | Thresholds each year |
| 4 | **GST** `gst` | GST rate, registration turnover threshold, non-profit threshold (3) | ATO registering for GST (confirmed for the thresholds) | Rate is fixed in law |
| 5 | **Capital gains tax discount** `capital-gains-tax-discount` | Discount for individuals, minimum ownership period (2) | ATO CGT discount (confirmed). **Read first:** the page carries an alert that capital gains tax changes announced in the 2026–27 Federal Budget do not apply to Tax Time 2026, so the discount may change. Read the ATO's what's new page before writing | When the law changes, and possibly soon |
| 6 | **HELP loan repayments** `help-loan-repayments` | Repayment income thresholds and rates (about 5) | ATO study and training loan thresholds (**to confirm** the exact address) | 1 July |

Subcategories: Income tax (1 to 3), GST and capital gains tax (4 and 5), Study loans (6).

### Savings and investments (2 pages, 1 subcategory: Savings and interest rates)

| # | Page and proposed slug | Planned figures | Source | Changes when |
|---|---|---|---|---|
| 7 | **Cash rate target** `cash-rate-target` (the **rate page**) | Cash rate target (1) | RBA cash rate target (confirmed) | At eight Board meetings a year |
| 8 | **Financial Claims Scheme** `financial-claims-scheme` | Protected amount per depositor per institution (1) | fcs.gov.au (**to confirm** the page) | Rarely |

### Super and retirement (4 pages, 2 subcategories)

| # | Page and proposed slug | Planned figures | Source | Changes when |
|---|---|---|---|---|
| 9 | **Super guarantee rate** `super-guarantee-rate` | Guarantee rate, maximum contribution base (2) | ATO key super rates (**to confirm** the part of the page) | 1 July |
| 10 | **Super contribution caps** `super-contribution-caps` | Concessional cap, non-concessional cap, bring-forward amounts (3 to 4) | ATO contributions caps (confirmed) | 1 July |
| 11 | **Preservation age** `preservation-age` | Preservation age (1) | ATO key super rates, preservation age table (**to confirm** the address) | Fixed in law |
| 12 | **Age Pension** `age-pension` | Age Pension age, maximum fortnightly rate for a single person and for a couple (4) | Services Australia Age Pension rates (read live on 9 November 2026) and Who can get it (added in 026, to be read live before import) | 20 March and 20 September |

Subcategories: Super contributions (9 and 10), Retirement (11 and 12).

### Mortgages and property (2 pages, 1 subcategory: First home buyers)

| # | Page and proposed slug | Planned figures | Source | Changes when |
|---|---|---|---|---|
| 13 | **First Home Guarantee** `first-home-guarantee` (the **state test**) | Minimum deposit, then price caps for each state and territory (about 16, see below) | Housing Australia property price caps page (confirmed, readable). Read the table live, never from a copy: caps changed on 1 October 2025 and may change again | When Housing Australia revises the caps |
| 14 | **First Home Super Saver Scheme** `first-home-super-saver-scheme` | Annual release limit, total release limit (2) | ATO about FHSS release amounts (confirmed) | When the law changes |

### Work and benefits (5 pages, 2 subcategories)

| # | Page and proposed slug | Planned figures | Source | Changes when |
|---|---|---|---|---|
| 15 | **Minimum wage** `minimum-wage-australia` | National Minimum Wage per hour and per week (2) | Fair Work Ombudsman minimum wages (confirmed) | 1 July |
| 16 | **Annual leave** `annual-leave-australia` | Weeks of paid leave, extra week for some shift workers (2) | Fair Work Ombudsman NES fact sheet (confirmed, readable) | Fixed in law |
| 17 | **Personal and carer's leave** `personal-and-carers-leave` | Paid days, unpaid carer's days, compassionate days, family and domestic violence leave (4) | Fair Work Ombudsman NES fact sheet (confirmed, readable) | Fixed in law |
| 18 | **JobSeeker Payment** `jobseeker-payment` | Age range, maximum fortnightly rates by circumstance (about 5) | Services Australia JobSeeker (confirmed for eligibility, **confirm** the rates page) | 20 March and 20 September |
| 19 | **Parental Leave Pay** `parental-leave-pay` | Number of days, weekly rate (3) | Services Australia Parental Leave Pay rates (confirmed) | 1 July |

Subcategories: Pay and leave (15 to 17), Government payments (18 and 19).

About 70 figures in all, against about 5.7 a page on the UK site.

## What each page exercises

| Pipeline part | Pages |
|---|---|
| Reference page, with no UK equivalent | 1 |
| Rate page (checked every 42 days or less, no rate typed in the text) | 7 |
| State and territory layer, from a single federal source | 13 |
| Figures that change on 1 July, so `{tax_year}` and `previous_value` | 2, 3, 9, 10, 15, 19 |
| Payments indexed twice a year, on 20 March and 20 September | 12, 18 |
| Same subject in two countries (the slug rule) | 2, 15, 16 |
| Figures fixed in law, which may be typed with an end date if one is known | 4, 5, 11, 16, 17 |

Two things to check before building the pages:

- **Page 13 name.** Some lender material now calls this scheme the "5% Deposit
  Scheme". Housing Australia is the source, so use whatever name its own pages use.
- **Page 13 caps.** Housing Australia publishes caps for a capital city and
  regional centres and a lower cap for the rest of each state, and the areas are
  defined differently by state. One federal table covers all of them, which is why
  this page, not stamp duty, tests the state layer. It also means the card count
  on this page will be high.

## Slugs

Subject slugs are unique across all countries, because the public site finds a page
by its slug alone. The rule is a plain descriptive slug, with `-australia` added
only where the UK has a page on the same subject. Pages 2, 15 and 16 carry the
suffix already because I expect UK equivalents. Run this on the local database
before importing anything. Any row it returns needs a different Australian slug:

```
SELECT slug, status FROM subjects WHERE slug IN (
  'tax-free-threshold','income-tax-rates-australia','medicare-levy','gst',
  'capital-gains-tax-discount','help-loan-repayments','cash-rate-target',
  'financial-claims-scheme','super-guarantee-rate','super-contribution-caps',
  'preservation-age','age-pension','first-home-guarantee',
  'first-home-super-saver-scheme','minimum-wage-australia','annual-leave-australia',
  'personal-and-carers-leave','jobseeker-payment','parental-leave-pay');
```

The importer also refuses a clash, but checking first avoids a failed file.

## The source allowlist

Nineteen official pages are in the allowlist. Migration 023 added sixteen, 024
corrected three ATO addresses that redirect (tax rates, the CGT discount and the
contributions caps) and removed two that were only seen in a search result, 025
swapped two pages the site cannot read for two it can (see below), and 026 added
Services Australia's Age Pension eligibility page, which states the Age Pension age, 027 added the ATO super guarantee page (rate and maximum contribution base) 028 added the ATO payments from super page (the preservation age table) and 029 added the two Services Australia JobSeeker pages (rates and who can get it).

### Can the site read them?

A page in the allowlist is no use if `includes/source-pages.php` cannot download it:
the writer, the AI check and the weekly source check all read pages through it.
`scripts/check-source-access.php` tests every source from the machine it runs on. It
was run on a Mac and on the Hostinger server on 5 November 2026, with the same results:

| Site | What it needed | Result |
|---|---|---|
| Reserve Bank of Australia, Housing Australia price caps | Nothing extra | Read as it always was |
| ATO (Akamai) | Browser headers after a first refusal (HTTP 403) | Read. `source-pages.php` now retries this way |
| Services Australia, Fair Work Ombudsman | Browser headers after a dropped connection (HTTP/2 reset) | Read. Same retry |
| Fair Work Commission | A JavaScript challenge | Cannot be read by any fetcher, so it is out of the allowlist |
| Housing Australia FAQs | Refused every variant on the Mac | Out of the allowlist until retested on the server |

The retry keeps the site's honest user agent ("financefactlibrary.com data check") and
only adds the headers a browser sends. Run the script again whenever a source is added
or a figure's source starts failing, and for every new country.

These pages are needed by the plan but are not in the allowlist yet:

| Needed for | Page to find and confirm |
|---|---|
| Page 6 | The ATO's address for study and training loan repayment thresholds. The ATO's short link `ato.gov.au/ThresholdsAndRates` showed the right page, but the full address was not seen |
| Page 8 | A specific page on fcs.gov.au stating the protected amount |
| Page 4 | The ATO's GST overview, for the rate itself (the registration page is already in the allowlist) |

Each of these needs the page fetched and checked before its figure is entered.
`check-sources.php` can only verify a figure against a page that is in the table.

## Order of work

1. Apply migrations 023, 024 and 025 (022 is applied locally already), and deploy
   `includes/source-pages.php` with the retry, so the ATO pages can be read.
   **Done on the server (through 026, 12 November 2026).**
2. Run the slug check above.
3. Page 1 first, since it is the reference page: build its CSV, import it with
   `subject_status` set to `draft`, write its explanation, and approve it. Add the
   "Reference page" section to `au.md` once it is approved. **Page 1 approved on 5 November 2026.**
4. Then the other pages in the order above, in batches of about four. Page 13 goes
   in the first batch so the state layer is tested early. **In progress: 15 of 19 built (19 November).** The GST page was built as `gst-registration-threshold` (subcategory GST, under Tax) and the super pages sit in a `superannuation` subcategory, so the slugs and subcategories above differ a little from the plan.
5. Launch by setting `is_active` to 1 on the `AU` row, then
   start the drip (**done on 19 November at 15 pages, not 19, with the last four to join the drip as they are built**): `php scripts/run-jobs.php --enqueue=drip_country --payload='{"country":"AU","per_week":3}' --unique=drip_country:AU`

### How the CSVs are laid out

Settled while building page 1, and used for every page after it:

- Same 28 columns, in the same order, as the UK files (`data/imports/mortgages-boe-2026-10.csv`).
- `jurisdiction_code` is `AU` on every row, so no `--country` flag is needed.
- The first row of a page creates its category, subcategory and subject, with
  `subject_status` set to `draft`.
- A page's intro and meta text go on a **later row than the figures they name**. The
  importer refuses a placeholder that names a figure created on the same row.
- `source_url` is copied exactly from the allowlist, and the page is fetched on the day
  its CSV is built, so a redirect shows up before the CSV does.
- `last_updated` is the date the figure was read off its source page.
- `review_frequency_days` is left blank (the default of 90) except for a rate, which is 42
  or less, as the UK Bank Rate row does.
- Text is limited **as stored**, with the placeholders still in it: a meta description to
  200 characters and a meta title to 100. A long fact key counts in full, so
  `{fact:au_national_minimum_wage_hourly}` is 37 characters before it fills as "$26.44".
  Use short keys (`au_min_wage_hourly`) and check the stored length as well as the filled one.
  The importer refuses an over-long row cleanly, but only after the rest of the file has gone in.
- A page that shows another page's card uses an `also` row, and the card's owner is imported
  first (the Medicare levy page is in the file before the income tax rates page).
- Read each figure live, with `scripts/read-source.php` where a copy could be stale. A cached
  copy of a rates page can be a year old and carry no date.
- `tax_year` is left blank for a figure that is not set year by year, such as the
  tax-free threshold. A figure that is set each year (tax rates, caps, the minimum wage)
  carries `2026/27`, with `previous_value` and `effective_from`. A year tag on a
  figure that never changes would make the weekly check flag it every 1 July.

### Moving page text to the server

The figures CSV carries a page's intro and meta text but has no column for the explanation, so
finished text has to be moved separately. Write and edit wherever is convenient, then:

1. **Save** each page in the editor (the export reads what is saved, not what is in the boxes).
2. On the machine where it was written: `php scripts/export-page-text.php --country=AU`
   writes `data/imports/page-text-AU-<date>.csv`. Add `--slug=a,b` for particular pages.
3. Deploy the file with the rest of the site.
4. On the server, with the figures for those pages already imported there:
   `php scripts/import-page-text.php --dry-run data/imports/page-text-AU-<date>.csv`, then
   the same without `--dry-run`.

The import never changes a figure, a source or a page's status, never marks anything verified, and
never replaces a page that is already written on the target (it says "SKIPPED" and changes nothing)
unless `--overwrite` is given. It refuses text whose placeholders don't resolve there or that is too
long for the page's columns. The review date is carried as it was, not stamped with today. Not
carried: AI checks, AI drafts and the lessons learned from your edits.

Cost, from the UK figures in the handover: about $8 to $12 of AI use for 15 to 20
pages, plus your time.

## Second wave (the drip)

Not in the launch set. Each needs its sources found first.

| Page | Why it waits |
|---|---|
| Stamp duty on property | Needs all eight state revenue offices. Their official names for the tax differ and the sources I found disagreed, so each must be read from the revenue office itself |
| First home buyer grants and concessions by state | Same eight sources |
| Land tax by state | Same eight sources |
| Medicare levy surcharge | Builds on page 3 |
| Low income tax offset | Builds on page 2 |
| Super co-contribution | Builds on pages 9 and 10 |
| Transfer balance cap | Builds on page 12 and the ATO super pages |
| Carer Payment | Services Australia |
| Family Tax Benefit | Services Australia, changes on 1 July |
| Child Care Subsidy | Services Australia |
| Redundancy pay | Fair Work Ombudsman NES fact sheet |
| Long service leave | State laws, so state sources |
| Public holidays by state | State sources |
| Variable home loan rate | RBA statistical table, which needs its own fetcher because it is not an ordinary web page |

## Build log

| Page | Status |
|---|---|
| 1 Tax-free threshold | CSV built and tested on 4 November 2026: `data/imports/au-01-tax-free-threshold.csv`. Three figures read off the ATO page (the full threshold and the two part-year amounts). Imports as a draft with intro and meta text, and no explanation yet |
| Source readability | Diagnosed and fixed on 5 November 2026. The ATO, Services Australia and Fair Work Ombudsman refused or dropped the fetcher's plain request, so the AI check could not read the ATO page and the writer drafted page 1 from memory. `source-pages.php` now retries with browser headers. The Fair Work Commission page cannot be read by any fetcher and was replaced |
| 3 Medicare levy, 2 Income tax rates, 15 Minimum wage | CSV built and tested on 5 November 2026: `data/imports/au-batch-02.csv` (import after page 1). Ten new figures, two shared cards, all read live: the ATO's resident tax rates (2026–27) and Medicare levy pages, and the Fair Work Ombudsman's 1 July 2026 release. Previous values: the first-band rate 16% (ATO 2025–26 table), and $24.95 an hour and $948 a week (Ombudsman's 1 July 2025 release) |
| 12 Age Pension | CSV built and tested on 9 November 2026: `data/imports/au-batch-03.csv` (import after the batch before it, and after migration 026). The three rates were read live with `scripts/read-source.php` on the site owner's own machine: $1,237.70 for a single person, $933.00 each for a couple and $1,866.00 combined, from 20 September 2026. Services Australia's own notice of 8 September 2026 confirms the rises ($36.80 single, $55.60 couple combined), which give the previous values. The Age Pension age of 67 is on a second page that must be read live before import |
| Page text transfer | `scripts/export-page-text.php` and `scripts/import-page-text.php` built and tested on 9 November 2026 (63 checks, across two databases standing in for the Mac and the server). The round trip is exact, byte for byte |
| Server, 12 November 2026 | Figures for the five pages imported on the server (3, 10 and 4 created, no errors). The first run failed with a collation error (1267): the importer compared a bound value with the text `'published'`, in a statement I changed and in the original `updateSubjectStatus()`. Both now decide the date in PHP. `scripts/debug-sql.php` was added to show a failing statement. The text import is next |
| Nightly AI check, 12 November 2026 | `scripts/check-page-text.php` now skips written pages in a country that is not switched on (19 checks, run against your real script with stand-ins for the files I had not seen), with `--include-hidden` to check them on purpose. Confirmed on the server: the 5 Australian pages are skipped and the two UK pages that were due are listed |
| First overnight run, 13 November 2026 | All jobs `ok`. `check-sources.php` found 302 of 302 figures (17 more than the day before: the Australian ones) with 0 sources unavailable. `check-page-text.php` checked 2 UK pages and skipped 5 Australian ones. The 17 Australian values were unchanged |
| Super contribution caps and GST registration threshold, 16 November 2026 | `au-batch-04.csv` (five figures: concessional cap $32,500, previous $30,000, non-concessional cap $130,000, previous $120,000, carry-forward balance limit $500,000, carry-forward years 5, general transfer balance cap $2,000,000, previous $1,900,000) and `au-batch-05.csv` (GST threshold $75,000, non-profit $150,000, 21 days to register, 12 months minimum registration, 4 years backdating). All read live with `scripts/read-source.php`. Intro and meta text sit on a final row after the figures, because the importer checks placeholders row by row. Page text written and on the server |
| Super guarantee rate and Preservation age, 18 November 2026 | Migrations 027 and 028 add the two ATO pages to the allowlist. `au-batch-06.csv`: guarantee rate 12% (previous 11.5%, from 1 July 2025) and maximum contribution base $270,830 for 2026–27 (the ATO's formula checks out against the concessional cap). `au-batch-07.csv`: six preservation ages, 55 to 60, by date of birth. The explanations were written directly and scored against the ATO text with the close-copy checker (0 close copies), then loaded through one-row page-text files and a combined export. Nine pages have text on the server |
| JobSeeker Payment, 18 November 2026 | Migration 029 adds the two Services Australia pages. `au-batch-08.csv`: maximum fortnightly rates from 20 September 2026 (single $824.90, single with dependent children $883.30, partnered $755.10, single principal carer with an exemption $1,068.20), minimum age 22, and the Age Pension age card shared from page 12. No previous values: the rates page does not state them. Text written and on the server |
| Annual leave and Personal and carer's leave, 19 November 2026 | `au-batch-09.csv`, all from the Fair Work Ombudsman NES fact sheet: 4 weeks annual leave plus 1 extra week for some shiftworkers, 10 days paid sick and carer's leave, 2 days unpaid carer's leave, 2 days compassionate leave, 10 days paid family and domestic violence leave. Short pages (168 and 176 words) because the source is short. Text and figures on the server |
| Parental Leave Pay, Cash rate target and First Home Super Saver Scheme, 19 November 2026 | `au-batch-10.csv`: Parental Leave Pay from 1 July 2026, $200.94 a day (previous $189.62) and $1,004.70 a week (previous $948.10), 130 days (previous 120), 20 reserved days (previous 15) and 20 concurrent days (previous 10), plus the shared super guarantee card. `au-batch-11.csv`: the RBA cash rate target 4.85% from 4 November 2026, reviewed every 42 days, with 8 Board meetings a year and the 2 to 3% inflation target band (source: the RBA's cash rate target overview). `au-batch-12.csv`: First Home Super Saver Scheme, $15,000 a year and $50,000 in total of voluntary contributions that can be released, with 100% of non-concessional and 85% of concessional contributions counted (source: the ATO's about FHSS release amounts page). It creates the Mortgages & Property category and the first home buyers subcategory. Texts written, scored against the sources with the close-copy checker (0 close copies) and on the server |
| Launch, 19 November 2026 | Preflight: `AU 0`, 15 draft subjects. `is_active` set to 1, `/au/` answered 200, drip queued (job #1, 3 a week). First page published by hand at 22:35: Tax-Free Threshold, next slot in 2 days 8 hours. Live at `/au/tax/income-tax/tax-free-threshold/` and in the sitemap. hPanel cron row set up and proven with a touch test on 20 November |
