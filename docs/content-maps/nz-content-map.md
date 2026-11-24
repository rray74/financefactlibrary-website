# New Zealand content map (plan)

Status: **plan only, first written on 23 November 2026.** Nothing is built: no figures, page text, CSVs, migrations or code
exist for New Zealand yet, and the server and database were not touched. The tables below hold **no figure values**: a value
goes into a CSV only after it has been read off its official source page on the day the CSV is built, as `core.md` and the
Australian handover require. Where a page's *kind* of figure is named below (a threshold, a weekly rate), it is a label, not a value.

Everything about sources below was found by reading the sites on 23 November 2026 from the Mac with `scripts/read-source.php`
and `scripts/check-source-access.php`, **with the VPN state unknown** (see "Can the site read them?": several failures look like
the same VPN symptom met on the UK work, so they must be retested with the VPN on). Addresses were taken from each site's own
sitemap or links, not guessed or taken from a search summary. Written in the shape of `au-content-map.md`, which is the template.

Target: about 15 to 20 pages at launch, all imported as hidden drafts, then `is_active = 1` on the `NZ` row and a drip of
2 to 4 pages a week. This plan proposes **20**, with two named as the first to drop.

## What already exists in code for New Zealand

Found by reading the repo (nothing was changed):

| Where | What is already there |
|---|---|
| `includes/placeholders.php`, `COUNTRY_TAX_YEARS` | `'NZ' => month 4, day 1, style 'slash'`. So `{tax_year}` would show **2026/27** (UK style). IRD writes its years in more than one way, so the style is a decision below |
| `includes/functions.php`, `COUNTRY_WORDING` | `NZ`: short "New Zealand", adjective "New Zealand", sources "Inland Revenue, Work and Income and the Reserve Bank of New Zealand" |
| `includes/text-checks.php`, `COUNTRY_TEXT_RULES` | Only `AU` has program-checked style rules. NZ has none, so a `nz.md` rule that a program could check (spelling, date format, quotation marks) would need a **code change, to be asked about first** |
| `docs/house-style/au.md` | Already says "Official Cash Rate" is New Zealand's name for its own rate |
| Jurisdiction row, sources, allowlist | **Nothing yet.** No `NZ` row in `jurisdictions`, no NZ domain in `FACT_SOURCE_ALLOWLIST` |

## To do

Kept up to date as the work goes. `[x]` is done, `[ ]` is still to do. Last updated 23 November 2026.

### Planning (this document)

- [x] Read the AU map, process, handover, house style and TODO (23 November).
- [x] Find and test the official addresses (23 November, from the Mac; results below).
- [x] **Retested with the VPN on (set to New Zealand), 23 November:** `legislation.govt.nz`, `fma.govt.nz` and `tenancy.govt.nz` now read. RBNZ (Cloudflare 403), Kāinga Ora and ACC (Imperva) are still refused on every variant, so the VPN does not fix them. Then tried the VPN set to the UK (still refused) and the Hostinger server (read-only `check-source-access.php` over SSH, with Richard's OK): **all three are refused on every variant from the server too** (RBNZ Cloudflare 403; Kāinga Ora and ACC return HTTP 200 with 0 characters). So pages 7, 8 and 12 cannot be built on the site's fetcher. **Decision needed: move them to the second wave, or find another official page that reads, or keep a hand-refreshed saved copy.**
- [ ] **Ask Richard before running `check-source-access.php` over SSH** for the same failures (the server's address is often treated differently).
- [ ] Richard decides the items under "Decisions waiting" and approves the page list.
- [ ] Slug collision check on the local database (SQL below).

### After the plan is approved (not part of this job)

- [x] **Allowlist and tax-year style (23 November, approved, tested on MAMP, not yet committed):** `ird.govt.nz`, `employment.govt.nz`, `workandincome.govt.nz` and `tenancy.govt.nz` added to `FACT_SOURCE_ALLOWLIST` (`rbnz.govt.nz` left out until it reads); `COUNTRY_TAX_YEARS['NZ']` changed to the dash style, so `{tax_year}` shows 2026–27.
- [x] **Migrations 032 and 033 written and applied on MAMP (23 November, not yet committed or on the server):** the `NZ` row (inactive, tax year 1 April) with 16 regions (the macron in Manawatū-Whanganui stores and reads back correctly) and 26 allowlisted source rows (all read that day). Left out of 033 on purpose: RBNZ, Kāinga Ora, ACC and the paid parental leave payment page.
- [ ] (was) Migration 032 for the `NZ` jurisdiction row (inactive); migration 033 for the source rows. Check the folder for the next free number before using it.
- [ ] `docs/house-style/nz.md`, modelled on `au.md` (points listed below).
- [ ] Optional code change: `COUNTRY_TEXT_RULES` entry for NZ once `nz.md` is settled.
- [ ] Build the pages in Claude Code, one batch at a time, using `docs/content-creation-process.md` (card audit, sources read live, claims table, one commit per page).
- [ ] Preflight, switch on, start the drip (steps under "Order of work").

### Decision on the unreadable pages (23 November, Richard)

- [x] **Pages 8 (Depositor Compensation Scheme) and 12 (First Home Loan) move to the second wave.** The launch set is now **18 pages** (still counting page 7). Both rest on sites that refuse every machine tried (Mac, UK VPN, NZ VPN, server). Promote them if the sites ever read.
- [x] **Page 7 (Official Cash Rate) stays in the plan, built last (Batch E).** No readable official source was found: a web search (standard mode) returned only news and market sites, and the Treasury, Stats NZ and Beehive pages did not read. **Retest RBNZ before Batch E.** If it is still refused, move page 7 to the second wave too (launch set 17, with no rate page, so the rate-page rules go untested until a rate page joins). No figure was taken from the search results.

### Decisions waiting

Settled by Richard on 23 November 2026:

- [x] **1. Tax year style:** the dash, **2026–27** (as Australia). Needs a one-line code change to `COUNTRY_TAX_YEARS` for `NZ` (`'style' => 'dash'`): ask before editing.
- [x] **3. Spelling:** New Zealand English as the government writes it: British -ise spellings, "programme", dates as 1 April 2027, single quotation marks, no em dashes, official names exactly as each body writes them (macrons kept).
- [x] **5. ACC weekly compensation:** second wave.
- [x] **Category name:** "KiwiSaver & Retirement", unless further research says otherwise.
- [x] **6. Regional rows:** add the 16 regional sub-jurisdiction rows in migration 032 (a schema change, so ask first).
- [x] **13. Drip:** run NZ's drip alongside Australia's.

Still open: 2 (NZ$ or $), 4 (whether titles say "capital gains tax"), 7 (macrons and the importer), 9 (Inland Revenue or IRD in text), 12 (Family Boost and Child Support), and what to do about the three unreadable sites.

### Loose ends

- [ ] (fill in as they come up)

## The launch set: 20 pages proposed, 18 after the 23 November decision (pages 8 and 12 moved to the second wave; see the To do section)

Fact keys would start `nz_`. Slugs are proposed; the collision check settles the final ones. **Rate page** means a page with a
card checked every 42 days or less: no rate in any field, no rate placeholder, nothing about rising or falling, no minimum or
maximum deposit, titles name the subject. Only page 7 is a rate page in this set.
**Read** shows the result of the 23 November test from the Mac (full table further down): OK = read and states the kind of figure
named; BLOCKED = the fetcher is refused.

### Tax (5 pages, 4 subcategories)

| # | Page and proposed slug | Why it is in | Planned figures (labels only) | Source address (read 23 Nov) | Read | Changes when |
|---|---|---|---|---|---|---|
| 1 | **Income tax rates** `income-tax-rates-new-zealand` | The anchor tax page, like AU page 2. Tests the NZ tax year (1 April) and `{tax_year}` | Bracket thresholds and marginal rates for individuals | `https://www.ird.govt.nz/income-tax/income-tax-for-individuals/tax-codes-and-tax-rates-for-individuals/tax-rates-for-individuals` | OK | 1 April, or when a tax change is legislated |
| 2 | **ACC earners' levy** `acc-earners-levy` | ACC has no UK or AU equivalent: a levy taken through PAYE that replaces suing for personal injury. Tests a figure pair (rate and maximum earnings) set by year | Earners' levy rate, maximum liable earnings | `https://www.ird.govt.nz/income-tax/income-tax-for-individuals/acc-clients-and-carers/acc-earners-levy-rates` (IRD carries it, so the unreadable ACC site is not needed) | OK | 1 April |
| 3 | **GST** `gst-new-zealand` | A fixed-in-law rate plus a registration threshold, like AU page 4. Needs the suffix because AU already holds `gst-registration-threshold` and Canada will want a GST page | GST rate, registration turnover threshold | `https://www.ird.govt.nz/gst/registering-for-gst` (threshold, read) and `https://www.ird.govt.nz/gst/charging-gst` (rate, read) | OK | When the law changes |
| 4 | **Bright-line test** `bright-line-test` | **The dated-change page.** NZ has no general capital gains tax, so this is the page that explains tax on selling residential property. Its period was changed from 1 July 2024, and IRD keeps a separate page for property sold before that date | The bright-line period, the date it applies from | `https://www.ird.govt.nz/property/buying-and-selling/when-you-need-to-pay/the-brightline-test`, plus `…/the-brightline-test/start-and-end-dates` (both read; the page names 1 July 2024 and the older period) | OK | When the law changes. Dated wording risk: write only what the page states |
| 5 | **Student loan repayments** `student-loan-repayments-new-zealand` | The tax-collected loan, like AU page 6 and the UK page. Tests a threshold plus a flat rate, a pay-period table and an overseas rule | Repayment threshold (annual), repayment rate, possibly the pay-period thresholds | `https://www.ird.govt.nz/student-loans/living-in-new-zealand-with-a-student-loan/repaying-my-student-loan-when-i-earn-salary-or-wages` (read, states both) | OK | 1 April |

Subcategories: Income tax (1, 2), GST and property tax (3, 4), Study loans (5). (PIE tax, a tax on investments, sits in Savings.)

### Savings and investments (3 pages, 2 subcategories)

| # | Page and proposed slug | Why it is in | Planned figures | Source address | Read | Changes when |
|---|---|---|---|---|---|---|
| 6 | **PIE tax rates (prescribed investor rates)** `pie-tax-rates` | How most NZ managed funds and KiwiSaver funds are taxed; no UK or AU equivalent. A fixed-in-law rate set | Prescribed investor rates, the income bands that set them | `https://www.ird.govt.nz/roles/portfolio-investment-entities/multi-rate-pies-and-prescribed-investor-rates` | OK, but the 10.5% and 17.5% rates did not show as text (28% did). **Read the whole page before building**, and add a second IRD page if it does not state them | When the law changes |
| 7 | **Official Cash Rate** `official-cash-rate` | **The rate page**, like AU page 7 | Official Cash Rate (1) | `https://www.rbnz.govt.nz/monetary-policy/about-monetary-policy/the-official-cash-rate` (to confirm: the address came from memory, never read) | **BLOCKED** (Cloudflare 403 on every variant from the Mac). Retest with VPN, then from the server | At the Monetary Policy Committee's scheduled decisions (read the calendar live; do not rely on memory) |
| 8 | **Depositor Compensation Scheme** `depositor-compensation-scheme` | Deposit protection, like UK FSCS and AU Financial Claims Scheme. Fixed in law | Protected amount per depositor per institution | An RBNZ page. **Address not found yet**: the RBNZ site refuses the fetcher, so a page cannot be confirmed. `dcs.govt.nz` also answers 403 | **BLOCKED** | Rarely |

Subcategories: Savings and interest rates (7, 8), Investing (6).

### KiwiSaver and retirement (3 pages, 2 subcategories)

| # | Page and proposed slug | Why it is in | Planned figures | Source address | Read | Changes when |
|---|---|---|---|---|---|---|
| 9 | **KiwiSaver contributions** `kiwisaver-contributions` | KiwiSaver is NZ's workplace pension (UK auto-enrolment, AU super guarantee). Employee rate choices, default rate, employer contribution | Employee contribution rates, default rate, minimum employer contribution | `https://www.ird.govt.nz/kiwisaver/kiwisaver-individuals/employee-contributions` and `https://www.ird.govt.nz/kiwisaver/kiwisaver-employers/contributions-and-deductions/employer-contributions-to-kiwisaver-and-complying-funds` (both read, both state rates). **Dated wording risk:** these rates have been the subject of announced changes (my recollection, not read). Read the pages and the legislation, and check for any dated or staged wording before building | OK | When the law changes |
| 10 | **KiwiSaver government contribution** `kiwisaver-government-contribution` | A dated-change page. The government's annual contribution was changed in 2025 (my recollection, unverified); the page must say only what the IRD page states | Contribution per dollar, annual maximum, age range, any income test | `https://www.ird.govt.nz/kiwisaver/kiwisaver-individuals/growing-my-kiwisaver-account/getting-the-kiwisaver-government-contribution` | OK ($ amounts present) | When the law changes; the contribution year runs 1 July to 30 June |
| 11 | **NZ Superannuation** `new-zealand-superannuation` | The public pension, like UK State Pension and AU Age Pension. Tests an eligibility age plus rates set by year | Eligibility age, residence requirement, weekly or fortnightly rates by circumstance | `https://www.workandincome.govt.nz/eligibility/seniors/superannuation` (who and age), `…/superannuation/who-can-get-it` and `…/superannuation/how-much-you-can-get` (rates; all three read). **The old address `…/products/a-z-benefits/nz-superannuation.html` is now a one-paragraph stub** pointing to these | OK | 1 April (annual adjustment) |

Subcategories: KiwiSaver (9, 10), NZ Super (11).

### Mortgages and property (3 pages, 1 subcategory: First home buyers; page 14 could sit in a Renting subcategory)

| # | Page and proposed slug | Why it is in | Planned figures | Source address | Read | Changes when |
|---|---|---|---|---|---|---|
| 12 | **First Home Loan** `first-home-loan` | **The regional test.** Kāinga Ora's low-deposit loans have price caps that differ by region (the NZ answer to AU page 13). The site was renamed or reorganised before (AU page 13), so read for the current name | Minimum deposit, then the price caps by region, new build and existing | `https://kaingaora.govt.nz/home-ownership/first-home-loan/` (to confirm: the exact page and where the caps table sits are not known) | **BLOCKED** (Imperva JavaScript challenge, 0 characters from every variant). Cannot be a card source unless the server reads it | When Kāinga Ora revises the caps |
| 13 | **KiwiSaver first home withdrawal** `kiwisaver-first-home-withdrawal` | Cross-links page 9 and 12; shows a page that rests on one IRD guide. A rule fixed in law | Minimum membership period, amount that must stay in the account | `https://www.ird.govt.nz/kiwisaver/kiwisaver-individuals/getting-my-kiwisaver-funds-early/getting-my-kiwisaver-for-my-first-home` | OK, but the keywords "three years" and "1,000" did not appear as typed. **Read the full page and check the wording before planning the cards** | When the law changes |
| 14 | **Tenancy bonds and rent increases** `tenancy-bonds-and-rent-increases` | NZ tenancy law differs from the UK (no deposit schemes: the bond is lodged with Tenancy Services). Same job as UK Tenancy Deposits; **dated-change risk** (the sitemap shows 2025 bond service changes, and pet bonds are mentioned) | Maximum bond, minimum interval between rent increases, notice period | `https://www.tenancy.govt.nz/rent-bond-and-bills/bond/charging-a-bond/charging-a-general-bond/` and `https://www.tenancy.govt.nz/rent-bond-and-bills/rent/increasing-rent/` (both read; the first call to the site timed out and a later one worked, so the site is flaky) | OK, intermittent | When the law changes |

### Work and benefits (6 pages, 3 subcategories)

| # | Page and proposed slug | Why it is in | Planned figures | Source address | Read | Changes when |
|---|---|---|---|---|---|---|
| 15 | **Minimum wage** `minimum-wage-new-zealand` (**the reference page**) | Simple, one source, no regional layer: the right page to settle `nz.md` first, as the tax-free threshold did for AU. Also tests several pay types on one page | Adult minimum wage, and the starting-out and training rates if the page still lists them | `https://www.employment.govt.nz/pay-and-hours/pay-and-wages/minimum-wage/minimum-wage-rates-and-types` (read; the parent page `…/minimum-wage` has no dollar figures) | OK | 1 April (to confirm from the page) |
| 16 | **Annual holidays** `annual-holidays-new-zealand` | Fixed-in-law leave, like AU page 16 | Weeks of paid annual holiday | `https://www.employment.govt.nz/leave-and-holidays/annual-holidays` | OK | Rarely |
| 17 | **Sick and bereavement leave** `sick-and-bereavement-leave` | Like AU page 17 (personal and carer's leave) | Paid sick days, bereavement days by relationship, family violence leave days (to confirm which pages hold which) | `https://www.employment.govt.nz/leave-and-holidays/sick-leave` (read). A guessed `…/sick-leave/bereavement-leave` address was a 404, so find the real address in the page's links first | OK, one address to find | Rarely |
| 18 | **Paid parental leave** `paid-parental-leave` | Like AU page 19 and the UK maternity and paternity pay page. IRD pays it, so the payment sits on IRD | Weeks of payment, maximum weekly payment | `https://www.employment.govt.nz/leave-and-holidays/parental-leave` (read, no dollar or week figures), `https://www.ird.govt.nz/paid-parental-leave/working-out-your-entitlement` (read, 2,106 characters and no weeks or dollars). **The page that states the payment cap and the weeks is not found yet**: look in the IRD paid parental leave overview and the employment.govt.nz paid parental leave pages | Pages OK, **figures not located** | When the cap is reset (read the page for the date) |
| 19 | **Jobseeker Support** `jobseeker-support` | Like AU page 18. Rates on a dated page | Age range, weekly rates by circumstance | `https://www.workandincome.govt.nz/products/a-z-benefits/jobseeker-support.html` (read, states $ amounts), and the rates table `https://www.workandincome.govt.nz/products/benefit-rates/benefit-rates-april-2026` (read, about 340 $ figures) | OK. **The rates address is dated and changes every April** (see lessons) | 1 April |
| 20 | **Working for Families tax credits** `working-for-families-tax-credits` | The NZ family benefit, paid by IRD: four credits, income-tested. Tests a page with many cards and abatement thresholds, like AU's price-cap page for card volume | Family Tax Credit, In-Work Tax Credit, Best Start, Minimum Family Tax Credit amounts and abatement thresholds | `https://www.ird.govt.nz/working-for-families/types/family-tax-credit`, `…/in-work-tax-credit`, `…/best-start`, `…/minimum-family-tax-credit` (all read; each has about 40 to 50 $ figures) | OK | 1 April. **Card count would be high; split into two pages if the text will not fit 800 words** |

Subcategories: Pay and leave (15 to 18), Government payments (19, 20). Page 18 could sit under either.

Rough count: about 70 to 90 figures, depending on pages 12 and 20. **Pages to drop first if 20 is too many: 6 (PIE) and 13 (KiwiSaver first home withdrawal).** Both can join the drip as second-wave pages.

## What each page exercises

| Pipeline part | Pages |
|---|---|
| Reference page (settles `nz.md` and the CSV shape) | 15 |
| Rate page (checked every 42 days or less, no rate in any field) | 7 |
| Regional layer, from one source | 12 (if the caps table is readable) |
| Figures that change on 1 April (`{tax_year}`, `previous_value`, `effective_from`) | 1, 2, 5, 11, 15, 19, 20 |
| Payments adjusted each April, with an address that includes the year | 19 (and 11 if rates are on a dated page) |
| Dated change: law changed or changing, so wording and dates matter | 4, 9, 10, 14 |
| Same subject in two countries (the slug rule, suffix needed) | 1, 3, 5, 15, 16 |
| Figures fixed in law | 3, 4, 8, 13, 16, 17 |
| Shared card across pages (`also` row, owner imported first) | 9 and 13 (KiwiSaver), 11 and 9 if the age is shared |
| Income-tested benefit with abatement | 20 |
| Page built on an unreadable site (needs the server or a manual look) | 7, 8, 12 |

## Things that differ from the UK and Australia, and need a decision

1. **(Settled: dash, 2026–27.)** **Tax year: 1 April to 31 March.** Already coded as `month 4, day 1`. The code's NZ style is `'slash'` (2026/27) copied from the UK. IRD writes years in more than one way (its student loan page said "2026 tax year"). **Decision: slash (2026/27), the AU dash (2026–27), or a single year (the 2027 tax year, which is what IRD calls the year ending 31 March 2027)?** This also changes how `{tax_year}` reads and how the weekly check flags a "year" tag.
2. **Rate and rounding wording.** NZ official pages mostly write "$", "%" and "per week". **Decision: bare "$" (as AU) or "NZ$" for the first mention?** The site's currency code would be `NZD`.
3. **Category names.** Categories belong to one country (`jurisdiction_id`), so NZ can name its own. Proposed: Tax; Savings & Investments; **KiwiSaver & Retirement**; Mortgages & Property; Work & Benefits. **Decision: "KiwiSaver & Retirement" or "Retirement" or "Super & Retirement" (AU)?**
4. **No capital gains tax.** NZ has none in general; the bright-line test and "intention" rules tax some property sales. Page 4 explains the gap. **Decision: leave "capital gains tax" out of page titles, or include it as a plain search term?** (Other pages must not say "there is no capital gains tax" unless a source says so in those words.)
5. **ACC (accident compensation).** No UK or AU equivalent. Page 2 covers the earners' levy. **Decision: also a page on ACC weekly compensation and entitlements in the launch set, or second wave?** (acc.co.nz is unreadable, so it would rest on IRD and a reference source from the server.)
6. **Regional layers.** NZ has no states or territories, so there is **no regional tax** to model. The regional layers are: First Home Loan price caps (page 12), Accommodation Supplement areas, provincial anniversary days, and regional council rates. **Decision: add 16 regional sub-jurisdiction rows (ISO codes such as `NZ-AUK`) in migration 032, as AU got eight, or only when the caps table is read and shows which regions it uses?** I propose to wait for the table.
7. **Māori names and macrons.** Official sites use both (Te Pūtea Matua, Kāinga Ora, Te Whatu Ora). **Decision: macrons in names as the source writes them (Kāinga Ora, Manawatū-Whanganui), and does the importer, sitemap and URL handling cope with them?** The slugs would stay ASCII.
8. **Spelling.** New Zealand English follows British spelling with some differences (-ise is standard, and "programme" is used for most things). **Decision: which dictionary governs `nz.md`?** I propose the Oxford New Zealand English line: British spelling, "programme", dates as "1 April 2027", no ordinals. Also whether to use the AU single-quote and no-em-dash rules.
9. **Names to use as the source writes them:** Inland Revenue (IRD or Inland Revenue, not "the IRS"); Work and Income (part of the Ministry of Social Development); Employment New Zealand (MBIE); Tenancy Services (MBIE); Reserve Bank of New Zealand; Kāinga Ora. **Decision: use "Inland Revenue" or "IRD" in text?** The site's wording constant says "Inland Revenue".
10. **No national tax-free threshold or Medicare equivalent.** NZ funds health through general tax, so no levy page exists.
11. **Student loans** are collected through the pay system and carry no interest for borrowers living in NZ (page 5 must say only what the source says, and the interest and fees page is a separate IRD page).
12. **Working for Families, Best Start and Family Boost** overlap in subject. The launch set takes the four IRD credits as one page; **Decision: leave Family Boost (a childcare payment announced in 2025, my recollection, unverified) and Child Support to the second wave.**
13. **Drip pace.** AU is 3 a week, which will be running until about early January. **Decision: start NZ's drip once AU's has finished, or run both?**

## Slugs

Subject slugs are unique across all countries, because the public site finds a page by its slug alone. Rule used here: a
plain descriptive slug, with `-new-zealand` added where the subject is generic and Canada or the US will want it
(income tax rates, GST, student loans, minimum wage, annual holidays). Names unique to NZ (KiwiSaver, ACC, bright-line, Working for
Families, First Home Loan) carry no suffix. A repository search found none of the slugs below in any CSV in `data/imports/`
(23 November 2026), but that does not cover the database. Run this on the local database before importing anything. Any row it
returns needs a different New Zealand slug:

```
SELECT slug, status FROM subjects WHERE slug IN (
  'income-tax-rates-new-zealand','acc-earners-levy','gst-new-zealand','bright-line-test',
  'student-loan-repayments-new-zealand','pie-tax-rates','official-cash-rate',
  'depositor-compensation-scheme','kiwisaver-contributions','kiwisaver-government-contribution',
  'new-zealand-superannuation','first-home-loan','kiwisaver-first-home-withdrawal',
  'tenancy-bonds-and-rent-increases','minimum-wage-new-zealand','annual-holidays-new-zealand',
  'sick-and-bereavement-leave','paid-parental-leave','jobseeker-support',
  'working-for-families-tax-credits');
```

Public addresses will be `/nz/<category>/<subcategory>/<slug>/`. Take the real address from the sitemap, never from the slug.

## The jurisdiction row

A migration (**032**, to be checked against the folder before it is used) modelled on `022_australia_jurisdiction.sql`:

| Column | Value |
|---|---|
| `code` | `NZ` (the value used in `jurisdiction_code` in CSVs) |
| `name` | New Zealand |
| `url_prefix` | `nz` |
| `currency_code`, `locale` | `NZD`, `en-NZ` |
| `tax_year_start_month`, `tax_year_start_day` | 4, 1 (must agree with `COUNTRY_TAX_YEARS` in `includes/placeholders.php`, which already says so) |
| `is_active` | **0**: hidden until launch. Launch is one change: set it to 1 on the `NZ` row |
| Sub-regions | None proposed yet (decision 6) |

The migration must contain no semicolons in comments or text, like the AU ones, so any runner that splits on semicolons works.
Source rows (migration 033, modelled on 023 to 029) would then carry the addresses from the tables above, with `publisher` set to the
body's full name (Inland Revenue, Work and Income, Employment New Zealand, Tenancy Services, Reserve Bank of New Zealand, Kāinga Ora).

## The source allowlist

`FACT_SOURCE_ALLOWLIST` in `includes/fact-writer.php` is a code change and **needs Richard's approval before any edit**. It matches a
domain and all its subdomains, and the AU note explains why not to add `gov.au` as a whole. The same applies to `govt.nz`: it would
admit every government site, so list each body's own domain.

| Domain | Needed for | Status |
|---|---|---|
| `ird.govt.nz` | Pages 1, 2, 3, 4, 5, 6, 9, 10, 13, 18 (payment), 20 | **Needed.** Reads from the Mac |
| `employment.govt.nz` | Pages 15, 16, 17, 18 | **Needed.** Reads from the Mac |
| `workandincome.govt.nz` | Pages 11, 19 | **Needed.** Reads from the Mac |
| `tenancy.govt.nz` | Page 14 | **Needed.** Reads, but flaky (a timeout and a 502 on the first tries, then fine) |
| `rbnz.govt.nz` | Pages 7, 8 | **Needed**, but refused from the Mac. Add it only once it can be read from the server (the importers refuse a source the site cannot read) |
| `kaingaora.govt.nz` | Page 12 | **Needed if page 12 stays**, but refused from the Mac (JavaScript challenge) |
| `legislation.govt.nz` | Settling disputed details (the UK work often needed the legislation) | **Optional.** HTTP 202 with the VPN off (the symptom the UK work saw); reads with the VPN on |
| `acc.co.nz` | ACC pages | **Not needed at launch.** IRD carries the levy rates. Refused from the Mac (Imperva) |
| `fma.govt.nz` | KiwiSaver provider regulation | **Not needed.** HTTP 403 from the Mac. Out of the launch set |
| `msd.govt.nz`, `studylink.govt.nz`, `business.govt.nz`, `govt.nz` | Parent bodies, student support, company registration, the portal | **Not needed.** All read from the Mac, so they stay available if a page needs one later |
| `kiwisaver.govt.nz` | A KiwiSaver site run by IRD | **Not tested.** IRD's own pages already cover page 9, 10 and 13 |

Reference sources (`import-reference-sources.php`) can only use an allowlisted domain, so the allowlist change must come **before**
any reference source. This was the lesson from the AU build, where none of the four national domains was on the list when it was time to add them.

### Can the site read them?

A page in the allowlist is no use if `includes/source-pages.php` cannot download it. Tested 23 November 2026 from the Mac (first with the
VPN state unknown, then retested with the VPN on and set to New Zealand), with `scripts/read-source.php` for each address and `scripts/check-source-access.php` for the failures:

| Site | Result | What it needs |
|---|---|---|
| Inland Revenue (`ird.govt.nz`) | **Read** (every address tried, from the sitemap) | Nothing extra |
| Employment New Zealand (`employment.govt.nz`) | **Read** | Nothing extra. The `/pay-and-hours/pay/minimum-wage` address I tried first is a 404: the page is under `/pay-and-hours/pay-and-wages/` |
| Work and Income (`workandincome.govt.nz`) | **Read** | Nothing extra |
| Tenancy Services (`tenancy.govt.nz`) | **Read** (VPN on, second test). Earlier it was unreliable: First calls timed out (30 s), then a 502, then answered. One variant that read it only worked with a plain browser agent, which is not an option without the site's terms checked | Retry; check the terms before changing the fetcher |
| Reserve Bank of New Zealand (`rbnz.govt.nz`) | **Refused: HTTP 403 from Cloudflare** ("Website unavailable") on all five variants, **also with the VPN on and set to New Zealand** | **Retest with the VPN on, then from the server.** Ask before any SSH |
| Kāinga Ora (`kaingaora.govt.nz`) | **Refused: Imperva JavaScript challenge**; HTTP 200 but 0 characters on every variant | The server, or no card from this site |
| ACC (`acc.co.nz`) | **Refused: Imperva** ("Request unsuccessful. Incapsula incident") | Not needed at launch |
| `legislation.govt.nz` | **HTTP 202 with the VPN state unknown; read with the VPN on (NZ)** | Needs the VPN on from the Mac. Check from the server |
| FMA (`fma.govt.nz`) | **HTTP 403 first; read with the VPN on (NZ)** | Out of scope |
| `msd.govt.nz`, `studylink.govt.nz`, `business.govt.nz`, `govt.nz` | Read | Not needed |

**Stop and ask.** Pages 7, 8 and 12 are the three that depend on a site the fetcher cannot read, and I did not find the address
of the Depositor Compensation Scheme page at all. Before any of them is built, Richard decides: retest with VPN, check from the server, or
move the page to the second wave. Run `scripts/check-source-access.php --country=NZ` on the Mac and on the server for every new
country, once the sources are in the table.

### Pages found but not yet confirmed to state the figure

| Needed for | What to find |
|---|---|
| Page 7 | The RBNZ page that states the Official Cash Rate. The address above is from memory and was never read |
| Page 8 | The page that states the Depositor Compensation Scheme protected amount |
| Page 6 | The page or table that states the full set of prescribed investor rates (the read page showed only part) |
| Page 13 | The wording on the KiwiSaver first home page for the minimum period and the amount that must stay |
| Page 17 | The bereavement and family violence leave addresses on employment.govt.nz |
| Page 18 | The page that states the paid parental leave payment cap and weeks |
| Page 12 | The Kāinga Ora address with the price caps, and the region list it uses |

## Order of work

1. Richard approves the page list and settles the decisions. Run the slug check.
2. **Allowlist and migrations** (approval first): `FACT_SOURCE_ALLOWLIST`, migration 032 (the jurisdiction row, inactive), migration 033
   (source rows). Test on MAMP, then they apply on the server with the deploy. Deploy applies migrations automatically on every push.
3. Write `docs/house-style/nz.md` from `au.md` (see below).
4. Run `scripts/check-source-access.php --country=NZ` on the Mac and, after asking, on the server. Decide the unreadable pages (7, 8, 12).
5. **Page 15 first (the reference page):** card audit, sources read live, CSV as a hidden draft, text, claims table, review. Add the
   "Reference page" section to `nz.md` once it is approved.
6. Then batches of about four, in this order so the hard pages are tested early (with the unreadable ones waiting for the retest):
   - Batch A (settles the tax year and NZ voice): 1 Income tax rates, 3 GST, 5 Student loan repayments, 2 ACC earners' levy.
   - Batch B (retirement and the shared cards): 9 KiwiSaver contributions, 10 KiwiSaver government contribution, 11 NZ Superannuation, 13 KiwiSaver first home withdrawal.
   - Batch C (benefits): 19 Jobseeker Support, 20 Working for Families, 16 Annual holidays, 17 Sick and bereavement leave, 18 Paid parental leave.
   - Batch D (dated change and property): 4 Bright-line test, 14 Tenancy bonds and rent increases, 12 First Home Loan (if readable).
   - Batch E (rates and protection): 7 Official Cash Rate, 8 Depositor Compensation Scheme, 6 PIE tax rates.
7. Preflight on the server (`NZ 0`, the draft subjects counted), set `is_active = 1` on the `NZ` row, check `/nz/` answers 200, then start the drip:
   `php scripts/run-jobs.php --enqueue=drip_country --payload='{"country":"NZ","per_week":3}' --unique=drip_country:NZ`.
   Keep exactly one `run-jobs.php` row in hPanel.

### How the CSVs would be laid out

Same as the AU files (`docs/content-maps/au-content-map.md`, "How the CSVs are laid out"): 28 columns in the order of
`data/imports/mortgages-boe-2026-10.csv`; `jurisdiction_code` is `NZ` on every row; the first row of a page creates its category,
subcategory and subject with `subject_status` set to `draft`; intro and meta text go on a later row than the figures they name;
`source_url` is copied exactly from the allowlist; `last_updated` is the date the figure was read; `review_frequency_days` is blank
except for the rate (42 or less); limits are counted as stored (meta description 200, meta title 100) so keep keys short (`nz_ocr`,
`nz_min_wage_adult`); `tax_year` is set only for a figure that changes each year, with `previous_value` and `effective_from`;
an `also` row shares another page's card, with the owner imported first. Page text moves with `export-page-text.php` and
`import-page-text.php`.

### `nz.md`, the house style file (to write, modelled on `au.md`)

Points to settle when it is written: spelling dictionary (decision 8); dates as 1 April 2027; money (decision 2); numbers with a comma
from four digits; single quotation marks and the no-em-dash rule (as AU) or not; the tax year and `{tax_year}` style (decision 1);
official names (Inland Revenue, Work and Income, Employment New Zealand, Tenancy Services, Reserve Bank of New Zealand, Kāinga Ora, Accident
Compensation Corporation); "Official Cash Rate" as the Reserve Bank's name for its rate; a section on regions (there are no states,
so say where a region differs, name it, and say nothing where it does not); and a rate-page section pointing to `core.md`.
A "Reference page" section is added after page 15 is approved.

## Lessons from the UK and Australian builds, applied to New Zealand

| Lesson | What it means for NZ |
|---|---|
| **Card audit first** (UK: a label that said 66 for everyone, an age card that cited a page that does not say it) | Every card on every NZ page is checked against its source before text is written. Card notes without a source are not repeated in text |
| **Read sources before drafting** with `scripts/read-source.php`, including sub-pages | Done for the addresses above, but only to confirm the kind of figure. Each page is read in full again on its build day. Several keyword checks above found a *different* wording than I expected (PIE rates, KiwiSaver first home), so nothing is assumed |
| **Allowlist before reference sources** (AU: no national domain was on the list, so no reference source could be added) | Add every needed NZ domain in the first allowlist change, and test each address from the server |
| **Check whether official pages have been renamed or moved** (AU: the First Home Guarantee became the 5% Deposit Scheme and its caps moved sites; fcs.gov.au redirects to APRA) | Seen already: the NZ Super A-Z page on Work and Income is now a stub that points to `/eligibility/seniors/superannuation`; the minimum wage page moved under `pay-and-wages`; **benefit rates sit on a page whose address contains the year** (`benefit-rates-april-2026`), so the card source will need a new address every April (and `check-sources.php` will report "not found" until it is changed) |
| **Never take a figure from a web-search summary** (AU: a summary gave the wrong HELP threshold) | I used no web search for this plan. Every figure is read live from the official page on the day the CSV is built |
| **A site that refuses the fetcher is checked before a page is built on it** (the Fair Work Commission) | Pages 7, 8 and 12 are in that position. Resolve before building |
| **Dated wording to track** | Bright-line (dated change), KiwiSaver contributions and government contribution, tenancy changes, the Official Cash Rate, anything written "from 1 April". Each page with dated wording is added to the key dates in `docs/TODO.md` when it is built |
| **Rate-page rules** | Only page 7 in the launch set. No rate in any field, no placeholder, no rising or falling, no minimum deposit, title names the subject |
| **Text limits count the stored text with placeholders** | Short fact keys |
| **Short source pages make close copies easy** (the AU government pages) | Score with the near-copy checker and restructure, not word swaps. IRD and Employment NZ pages are short and formulaic |
| **A short page beats a padded one** | Pages with thin sources (NZ Super, parental leave) stay short |
| **Check the VPN before blaming the site** (UK: gov.scot HTTP 202 and SSH timeouts with the VPN off) | The `legislation.govt.nz` 202 is the same symptom. Retest the failures with the VPN on before drawing conclusions |
| **A value in a card note must be sourced** | No card note without a source |
| **Don't build the page on a guessed address** (AU: a guessed ATO address gave 403) | Every address here came from a sitemap or a page's links, or is marked "to confirm" |
| **`{tax_year}` fills from the latest card** | If a page has a card for a future April, the title omits the year. NZ's April boundary means this will happen on every page with a card read before 1 April |

## Second wave (the drip, not in the launch set)

Each needs its sources found first.

| Page | Why it waits |
|---|---|
| Resident withholding tax on interest | IRD page read and states the rates; could join the launch set if the count allows |
| ACC weekly compensation and entitlements | Rests on acc.co.nz, which refuses the fetcher |
| Child Support | IRD page read; formula-based, so likely few cards |
| Family Boost (childcare tax credit) | New, dated, my recollection only |
| Accommodation Supplement | Area layers (a regional test) |
| Public holidays and anniversary days by region | A regional test; employment.govt.nz page read |
| Residential rent increases and boarding houses | Builds on page 14 |
| LVR (loan-to-value) rules | RBNZ source, unreadable for now |
| Variable and fixed mortgage rates | RBNZ statistical table: needs its own fetcher, like the RBA's |
| Fringe benefit tax rates | IRD, changed recently (an IRD update says so) |
| Independent Earner Tax Credit | IRD page exists; small |
| Redundancy, minimum notice | Employment NZ |
| Veteran's Pension, Winter Energy Payment | Work and Income |

## Build log

| Page | Status |
|---|---|
| Planning, 23 November 2026 | Plan written. About 40 NZ addresses tested from the Mac. IRD, Employment NZ and Work and Income read. RBNZ (Cloudflare 403), Kāinga Ora (JavaScript challenge) and ACC (Imperva) refused. `legislation.govt.nz` returned HTTP 202. `tenancy.govt.nz` timed out then answered. No figure was entered anywhere |
| Source retests, 23 November 2026 | VPN set to NZ: `legislation.govt.nz`, `fma.govt.nz`, `tenancy.govt.nz` read. VPN set to UK and the server: RBNZ, Kāinga Ora and ACC still refused on all variants |
| 15 Minimum wage (reference page), 24 November 2026 | `data/imports/nz-01-minimum-wage.csv`: four cards read live from Employment New Zealand's minimum wage rates and types page (adult per hour and for a 40-hour week, starting-out and training per hour; effective 1 April 2026; previous values from the previous-rates page on the same site). Text 582 words, 0 close copies after rewrites (the one triage note is the fixed ages and credits). Page text in `page-text-NZ-minimum-wage-new-zealand-2026-11-24.csv`. Approved by Richard, committed (`532be6e`), imported on the server, stamped reviewed. It is a hidden draft in an inactive country, so there is no live page to check. No reference sources were needed. The site also lists 'Changes to leave coming in 2028' (Employment Leave Act): read it before the annual holidays and sick leave pages |
| ACC earners' levy, Income tax rates and GST, 24 November 2026 | Cards read live from Inland Revenue (`nz-02-acc-earners-levy.csv`, `nz-03-income-tax-rates.csv`, `nz-04-gst.csv`): the ACC levy rate, maximum earnings and maximum levy for 2026–27 with 2025–26 as previous; five income tax bands and rates from 1 April 2025 (the IRD tax rates page says 'last updated 3 June 2025', and IRD's about-tax-codes page of 7 May 2026 shows the same bands in its PAYE table, so they are treated as in force); the GST rate and registration threshold. Four reference sources (about-tax-codes, secondary-tax-codes, voluntary GST registration). Texts 185 to 408 words, 0 close copies. Approved by Richard after previewing with the new admin preview page; one commit each (`44dbf7c`, `c6ef10c`, `9a72eea`), imported on the server, stamped reviewed |
| Student loan repayments, 24 November 2026: HELD | No readable IRD page states the repayment threshold for the 2027 tax year (1 April 2026 to 31 March 2027). The main page says 'For the 2026 tax year the annual repayment threshold is...' (last updated 27 Nov 2025), and the self-employed page lists the 2026 and 2025 tax years only. All 22 IRD student loan pages were checked, and so were StudyLink's repayment pages. The PAYE tables are PDFs the fetcher cannot read. Retest after IRD updates its pages, and the 12% rate is unchanged on the page that was read |
| Batch B: KiwiSaver contributions, KiwiSaver government contribution, NZ Superannuation, KiwiSaver first home withdrawal, 24 November 2026 | Cards read live from Inland Revenue and Work and Income (`nz-05` to `nz-08`), first category `kiwisaver-and-retirement`, subcategories `kiwisaver` and `nz-super`. KiwiSaver contributions: default and employer minimum 3.5%, highest choice 10%, reduced employer rate 3%; no staged or future change on any page read. Government contribution: $260.72 maximum, $1,042.86 of own money, 25c per dollar, $180,000 income limit (checked: 25% of $1,042.86 is $260.72); two reference sources (the benefits page and the overview page). NZ Super: fortnightly before-tax rates $1,294.74, $1,191.14 and $984.28, age 65, rates from the Work and Income page updated 2 July 2026; effective 1 April 2026. First home withdrawal: 3 years and $1,000 stay in the account, confirmed on the KiwiSaver benefits page; the IRD first-home page is short (last updated 28 May 2024) and Kāinga Ora's own page cannot be read, so the text is 262 words. All four approved, imported on the server and stamped reviewed. Keep an eye on the 1 April NZ Super update and the 1 July government contribution year |
| Batch C: Annual holidays, Sick and bereavement leave, Jobseeker Support, Working for Families, Paid parental leave, 24 November 2026 | `nz-09` to `nz-13`. Annual holidays (4 weeks, 12 months, 8% pay-as-you-go, 1 week cash-up, 14 days closedown notice) and Sick and bereavement leave (10 days, 6 months, 20 days accumulation, 3 and 1 days bereavement) rest on Employment New Zealand; all leave pages say that the Employment Leave Act replaces the Holidays Act on 6 August 2028 and the current rules apply until then. Jobseeker Support cards cite the stable Work and Income Jobseeker Support page (updated 2 November 2026), with the dated rates table (`benefit-rates-april-2026`) as a reference source only, so the April address change does not touch the cards; the Parental Assistance Test for 18- and 19-year-olds has applied since 2 November 2026. Working for Families (10 cards from four IRD pages): the Family Tax Credit and Minimum Family Tax Credit pages do not label their tax year, so they were treated as 2026-27 on the evidence that they were updated in February 2026 and IRD's current chart is the 2027 chart; the In-Work Tax Credit increase to $7,670 is temporary to 31 March 2027 and may reduce to $97 a week if petrol falls below $3 a litre for four weeks. Paid parental leave: the payment figures are on the IRD paid parental leave pages (maximum $811.05 and self-employed minimum $239.50 a week from 1 July 2026, 26 weeks), confirmed by Employment New Zealand. All five approved, imported on the server and stamped reviewed. The Hostinger auto-deploy stalled once (Working for Families, commit 6c9ea05) and was pulled by hand on the server |
| Batch D: Bright-line test, Tenancy bonds and rent increases, 24 November 2026 | `nz-14`, `nz-15`. Bright-line: 2 years from 1 July 2024 and the 50% main home tests, with the 5 and 10 year periods for earlier sales in the text only; no statement that New Zealand has no capital gains tax. Tenancy: 4 weeks general bond, 2 weeks pet bond (pets kept from 1 December 2025), 60 days notice, 12 months between increases; first category `mortgages-and-property`, subcategory `renting`. Both approved, imported on the server and stamped reviewed |
| Batch E findings, 24 November 2026 | Official Cash Rate: the Reserve Bank site still returns HTTP 403 from the Mac (no VPN) and from the server on its home, monetary policy and OCR pages, so page 7 stays in the second wave. PIE tax rates: the investor and PIE pages state the three rates (10.5%, 17.5%, 28%) and the 28% default, but the income bands that decide the rate sit in IRD's interactive tool and the IR861 PDF, which the fetcher cannot read. Richard chose to build the page with the rates only and point to IRD's tool for the bands |
| (pages: add a row as each batch is built, with the CSV name, the sources read, the dates, and any figure that a web-search summary got wrong) | |
