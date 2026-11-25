# Canada content map (plan)

Status: **plan only, first written on 25 November 2026.** Nothing is built: no figures, page text, CSVs, migrations or code exist for
Canada, and the server and database were not touched. The tables hold **no figure values**: a value goes into a CSV only after it has
been read off its official page on the day the CSV is built (`core.md`). Where a page's kind of figure is named, it is a label, not a value.

Everything about sources was read from the Mac on 25 November 2026 with `scripts/read-source.php` (VPN state unknown, no search summaries
used). Addresses are either **read** (the fetcher returned the page) or **to find** (a guess that returned nothing, or a page whose figure
is not in the text the fetcher sees). Written in the shape of `nz-content-map.md`, which is the template.

Target: about 15 to 20 pages at launch, imported as hidden drafts, then `is_active = 1` on the `CA` row and a drip. This plan proposes **20**.

## What already exists in code for Canada

| Where | What is already there |
|---|---|
| `includes/placeholders.php`, `COUNTRY_TAX_YEARS` | `'CA' => month 1, day 1, style 'single'`. Calendar tax year, `{tax_year}` shows **2026**. Needs no change |
| `includes/functions.php`, `COUNTRY_WORDING` | `CA`: short "Canada", adjective "Canadian", sources "the Canada Revenue Agency, Employment and Social Development Canada and the Bank of Canada" |
| `includes/text-checks.php`, `COUNTRY_TEXT_RULES` | No `CA` entry (only AU has program-checked rules). Optional code change, ask first |
| `jurisdictions` row, sources, allowlist | **Nothing yet.** No `CA` row, no Canadian domain in `FACT_SOURCE_ALLOWLIST`. Next free migration: **035** |
| Homepage, About, legal pages | Already mention Canada |

## Can the site read them? (tested 25 November 2026, Mac)

| Site | Result | Notes |
|---|---|---|
| `canada.ca` (CRA, ESDC, Service Canada, FCAC all sit here) | **Read** | Many pages are short because tables load from links: the federal tax rates page gave 1,894 characters with no bracket figures. The EI, CPP, OAS, TFSA and FHSA pages did state figures |
| `bankofcanada.ca` | **Read** (10,652 characters on the policy interest rate page) | Whether it states the rate as text is still to confirm. No Cloudflare block, unlike the Reserve Bank of NZ |
| `cdic.ca` | **Read** the home page | The home page does not state the protected amount. Find the "what's covered" page |
| `cmhc-schl.gc.ca` | **Read** the home page | Find the page that states the minimum down payment rules |
| `ontario.ca` | **Read** (minimum wage page states the current rate, the student rate and the previous three years) | Province pages need their own domains |
| `fin.gc.ca` | Not read (command lost in the batch) | Retest if the legislation or budget notes are needed |

Server read test (`check-source-access.php --country=CA`) is **not run**: it needs the allowlist and source rows first, and the server run needs Richard's OK.
Pattern to expect from NZ: IRD-type sites read, central-bank and housing-agency sites may refuse. Here none refused on the Mac.

## Things that differ from the UK, Australia and New Zealand

1. **Provinces and territories.** Ten provinces and three territories set their own income tax (on top of the federal tax), minimum wage, leave rules,
   land transfer taxes and more. This is the biggest design decision (Decision A).
2. **Quebec is separate.** Quebec runs its own pension (QPP rather than CPP), parental plan (QPIP) and tax return (Revenu Québec). Every CPP and EI
   page needs a line saying Quebec differs, and the sources must be read for that wording, not assumed.
3. **Two official languages.** Pages are written in English only. Source names keep the English form the site uses.
4. **The GST/HST credit no longer exists under that name.** The CRA page says it was renamed the **Canada Groceries and Essentials Benefit (CGEB)** as of July 2026,
   and payments now include provincial and territorial amounts. This is the dated-change page of the set (page 19). Write only what the CRA says.
5. **GST is federal, HST is provincial-blended, PST and QST are provincial.** One GST/HST page covers the federal rate and says which provinces use which tax. The CRA page
   `charge-collect-which-rate.html` (read) carries the rate-by-province table.
6. **Registered plans are a core subject**, with no UK or AU equivalent in one set: TFSA, RRSP, FHSA, Home Buyers' Plan. Annual limits change each 1 January.
7. **Money.** Canada and the US both write "$". Proposed: bare "$" on Canadian pages, as AU and NZ do; the currency code is `CAD`. **Decision B.**
8. **Spelling and dates.** Canadian English: -our (colour), -ize or -ise (government uses both; Decision C), centre, licence (noun) and license (verb). Government dates read
   "January 1, 2027". Decide whether to follow that or the AU/NZ style "1 January 2027" (Decision C).
9. **Tax year is the calendar year**, so `{tax_year}` shows 2026 and most figures change on 1 January. The EI page already lists a 2027 row (read 25 November), so a card for a
   future year will exist on some pages: leave the year out of the title in that case, as on the NZ pages.
10. **Rate page:** the Bank of Canada policy interest rate (page 9), with the rate-page rules (no rate in any field, no rising or falling, title names the subject).
    The Bank publishes its decision dates on its own site: read them live, never from memory.

## To do

Kept up to date as the work goes. Last updated 25 November 2026.

- [x] Plan written and sources tested (25 November).
- [x] **Decisions A to F settled (25 November, Richard: "go with your recommendations").**
- [x] Slug check on the local database: 0 collisions (25 November).
- [x] `FACT_SOURCE_ALLOWLIST`: `canada.ca`, `bankofcanada.ca`, `cdic.ca`, `cmhc-schl.gc.ca` added (`includes/fact-writer.php`, not yet committed).
- [x] Migrations 035 (CA row, inactive, 13 regions) and 036 (16 source rows) written and applied on MAMP. `check-source-access.php --country=CA` from the Mac: 16 of 16 read. Not yet committed or on the server.
- [x] `docs/house-style/ca.md` written.
- [x] `check-source-access.php` on the server (25 November, with Richard's OK, by `--url` because the rows are not there yet): 16 of 16 read.
- [ ] Find the pages marked "to find" (basic personal amount, GST/HST registration threshold, RRSP limit and Home Buyers' Plan pages, capital gains, mortgage down payment, federal minimum wage, CGEB amounts) and add their source rows in a later migration (037 or later).
- [ ] Commit and deploy 035 and 036 (ask first), then build page 20 (Minimum wage) as the reference page.

## Decisions (all settled 25 November 2026 as recommended)

- **A.** Provinces: 13 sub-jurisdiction rows (done, migration 035). Federal pages first, provincial pages in the second wave.
- **B.** Money: bare "$".
- **C.** Canadian English with -ize, and dates as "January 1, 2027".
- **D.** Allowlist: `canada.ca`, `bankofcanada.ca`, `cdic.ca`, `cmhc-schl.gc.ca` (done). A province's domain is added when its page is built.
- **E.** Categories: Tax; Savings & Investments; Retirement & Pensions; Mortgages & Property; Work & Benefits.
- **F.** Drip: run Canada alongside Australia and New Zealand.

The original options are kept below for the record.

### Decisions as first put (Richard)

- **A. Provinces.** Options: (1) federal pages only at launch, with one-sentence "your province may differ" lines; (2) add 13 sub-jurisdiction rows (`CA-ON` and so on, as AU got eight and NZ
  sixteen) and give minimum wage and land transfer tax a province each, starting with Ontario, British Columbia, Alberta and Quebec. **Recommended: add the 13 rows, build federal pages first, and add provincial pages in the second wave.**
- **B. Money:** bare "$" (recommended) or "CA$".
- **C. Spelling and dates:** Canadian English with -ize/-ise settled, and "January 1, 2027" (government style) or "1 January 2027".
- **D. Allowlist.** `canada.ca` covers CRA, ESDC and Service Canada but also every other federal page. Unlike `gov.au` it is one national government, and each card source is checked page by page, so I recommend adding `canada.ca` itself. Also needed: `bankofcanada.ca`, `cdic.ca`, `cmhc-schl.gc.ca`, and a province's own domain (`ontario.ca`) if a provincial page is built. **Allowlist is a code change: ask first.**
- **E. Category names:** Tax; Savings & Investments; Retirement & Pensions; Mortgages & Property; Work & Benefits (the proposal below).
- **F. Drip:** AU runs 3 a week until early January and NZ 3 a week for about six weeks. Run Canada alongside, or start after NZ finishes?

## The launch set: 20 pages proposed

Fact keys would start `ca_`. Slugs are proposed; the collision check settles them. **Read** says what was seen on 25 November: OK, or what is still to find.

### Tax (5 pages)

| # | Page and slug | Why | Planned figures | Source (read 25 Nov) | Read | Changes when |
|---|---|---|---|---|---|---|
| 1 | **Federal income tax rates** `income-tax-rates-canada` | The anchor tax page, tests the calendar year | Federal brackets and rates | `canada.ca/.../canadian-income-tax-rates-individuals-current-previous-years.html` links to the figures: the fetcher saw no brackets, so **find the linked page that states them** | To find | 1 January (indexed) or on a law change |
| 2 | **Basic personal amount** `basic-personal-amount-canada` | The tax-free amount, like AU's threshold | The federal amount, and the maximum and minimum if the page states a range | `canada.ca/.../line-30000-basic-personal-amount.html` (read; read it in full for the figure) | OK, figure to confirm | 1 January |
| 3 | **GST/HST** `gst-hst-canada` | Federal rate plus the province table | GST rate, HST rates by province, small supplier threshold | `canada.ca/.../charge-collect-which-rate.html` (read, states the GST rate and a PST example). The registration threshold page address guessed wrongly: **find it from the CRA's GST/HST registration page** | OK, threshold to find | When the law changes |
| 4 | **Capital gains tax** `capital-gains-tax-canada` | The Canadian counterpart of the UK and US pages. **Dated-change page:** inclusion rate rules were proposed to change and the change was later cancelled (my recollection, unverified) | Inclusion rate, any lifetime exemption | CRA capital gains pages (to find) | To find | Law changes. Write only what the page says |
| 5 | **Canada Pension Plan contributions** `cpp-contributions` | Deductions through payroll, tests two tiers (CPP and CPP2) and the Quebec note | Contribution rate, maximum pensionable earnings, basic exemption, maximum employee contribution, CPP2 rate and ceiling | `canada.ca/.../cpp-contribution-rates-maximums-exemptions.html` (read, yearly table) and the CPP2 page linked from it | OK | 1 January |

### Savings and investments (5 pages)

| # | Page and slug | Why | Planned figures | Source | Read | Changes when |
|---|---|---|---|---|---|---|
| 6 | **TFSA** `tfsa-contribution-limit` | The flagship Canadian savings account | Annual dollar limit by year | `canada.ca/.../tax-free-savings-account.html` (read, the contribution-room guide) and `.../mp-rrsp-dpsp-tfsa-limits-ympe.html` (read: has a TFSA limit table by year, 2026 shown) | OK | 1 January |
| 7 | **RRSP contribution limit** `rrsp-contribution-limit` | The tax-deferred retirement account | Dollar limit, 18% earned income rule | Same CRA limits table (read). The RRSP page address I guessed (`rrsp-fhsa-limit.html`) returned nothing: **find it from the RRSP overview** | OK for the dollar limit | 1 January |
| 8 | **First Home Savings Account (FHSA)** `first-home-savings-account` | The homebuyer account | Annual limit, lifetime limit, participation room first year | `canada.ca/.../first-home-savings-account.html` (read, states the first-year room; the other limits are on linked pages) | OK, rest to find | Rarely |
| 9 | **Bank of Canada policy interest rate** `bank-of-canada-policy-rate` | **The rate page** | Policy rate (1) | `bankofcanada.ca/core-functions/monetary-policy/key-interest-rate/` (read, 10,652 characters; the rate may be in a table the fetcher flattens, **confirm it shows as text**) | OK, figure to confirm | Scheduled announcement dates. Read the calendar live |
| 10 | **CDIC deposit insurance** `cdic-deposit-insurance` | Like FSCS and the Financial Claims Scheme | Protected amount per category per member institution | CDIC "what's covered" page (the home page read but does not state it): **find it** | To find | Rarely |

### Retirement and pensions (3 pages)

| # | Page and slug | Why | Planned figures | Source | Read | Changes when |
|---|---|---|---|---|---|---|
| 11 | **Old Age Security** `old-age-security` | The basic public pension | Eligibility age, maximum monthly amounts by age band, the income recovery threshold | `canada.ca/en/services/benefits/publicpensions/cpp/old-age-security/payments.html` (read: lists maximum monthly amounts for 65 to 74 and 75 and over, with income limits) and `.../benefit-amount.html` (read) | OK | Quarterly (OAS is indexed in January, April, July and October) |
| 12 | **Guaranteed Income Supplement** `guaranteed-income-supplement` | The income-tested top-up | Maximum monthly amounts by situation, income limits | Same payments page (read) | OK | Quarterly |
| 13 | **CPP retirement pension amount** `cpp-retirement-pension` | Pairs with page 5 | Maximum monthly amount at 65, average amount, age adjustments (60 and 70) | `canada.ca/en/services/benefits/publicpensions/cpp/cpp-benefit/amount.html` (read, 7,078 characters) | OK, to read in full | 1 January |

### Mortgages and property (2 pages)

| # | Page and slug | Why | Planned figures | Source | Read | Changes when |
|---|---|---|---|---|---|---|
| 14 | **Home Buyers' Plan** `home-buyers-plan` | Withdrawing from an RRSP to buy a home | Withdrawal limit, repayment period | The CRA page (the address I tried failed): **find it from the RRSP page** | To find | Rarely |
| 15 | **Minimum down payment and mortgage insurance** `minimum-down-payment-canada` | Canada's rule that a smaller down payment needs insured mortgages | Minimum down payment tiers, the price ceiling for insured mortgages | CMHC or FCAC page (**find it**: the CMHC home page read but states no figures). A dated-change risk (rules were revised in recent years) | To find | When rules change |

### Work and benefits (5 pages)

| # | Page and slug | Why | Planned figures | Source | Read | Changes when |
|---|---|---|---|---|---|---|
| 16 | **Employment Insurance premiums** `ei-premiums` | The payroll deduction page, with a **2027 row already published** | Rate, maximum insurable earnings, maximum premiums | `canada.ca/.../ei-premium-rates-maximums.html` (read: the table lists years including 2027 and 2026) | OK | 1 January |
| 17 | **EI regular benefits** `ei-regular-benefits` | Like Jobseeker pages | Benefit rate as a share of earnings, maximum weekly amount, maximum weeks | `canada.ca/en/services/benefits/ei/ei-regular-benefit/benefit-amount.html` (read: states a share of earnings and the 45-week maximum) | OK | 1 January |
| 18 | **Canada Child Benefit** `canada-child-benefit` | The family payment | Maximum amounts per child by age, income thresholds | `canada.ca/.../canada-child-benefit-overview.html` (read; **no dollar figures on the overview**). Its sub-page "how much you can get" is linked: find the figures page | To find | Payment year runs July to June |
| 19 | **Canada Groceries and Essentials Benefit** `canada-groceries-and-essentials-benefit` | **Dated-change page**: the GST/HST credit became this in July 2026 | Amounts and income thresholds as the CRA states them | The CRA's CGEB pages (the old credit page, read, says it was renamed and points to them). **Find the CGEB amounts page** | To find | Payment year runs July to June |
| 20 | **Minimum wage** `minimum-wage-canada` (**the reference page**) | Simple, one source. Tests the provincial layer | Federal minimum wage (for federally regulated workers), then one province's general and student rates | Federal page (to find) and, if provinces are in, `ontario.ca/document/your-guide-employment-standards-act-0/minimum-wage` (read: current rate, student rate, previous three years, with the October 1 change date) | OK for Ontario | Federal April 1 (my recollection), Ontario October 1: read each page for the date |

Ranking if 20 is too many: drop 15 (minimum down payment) and 4 (capital gains) first. Both depend on pages not yet found and carry dated-change risk.

## What each page exercises

| Pipeline part | Pages |
|---|---|
| Reference page that settles `ca.md` | 20 |
| Rate page (checked every 42 days or less) | 9 |
| Quebec note (a rule that differs in Quebec) | 5, 13, 16, 18 |
| Provincial layer | 3 (HST table), 20 (minimum wage) |
| Figures that change on 1 January (`previous_value`, `effective_from`) | 1, 2, 5, 6, 7, 13, 16, 17 |
| Indexed quarterly (card sources are stable pages) | 11, 12 |
| Dated change or renamed benefit | 4, 15, 19 |
| Same subject in two countries (slug suffix `-canada`) | 1, 3, 4, 5 (CPP is unique), 20 |
| Shared card | 6 and 8 (TFSA, FHSA with the RRSP room), 5 and 13 (CPP) |

## Slugs

Subject slugs are unique across countries. Slugs above carry `-canada` where UK, AU, NZ or the US will want the same subject. A repository search found no Canada slug in any CSV; **run
this on the local database before importing anything** and rename any row it returns:

```
SELECT slug, status FROM subjects WHERE slug IN (
  'income-tax-rates-canada','basic-personal-amount-canada','gst-hst-canada','capital-gains-tax-canada',
  'cpp-contributions','tfsa-contribution-limit','rrsp-contribution-limit','first-home-savings-account',
  'bank-of-canada-policy-rate','cdic-deposit-insurance','old-age-security','guaranteed-income-supplement',
  'cpp-retirement-pension','home-buyers-plan','minimum-down-payment-canada','ei-premiums',
  'ei-regular-benefits','canada-child-benefit','canada-groceries-and-essentials-benefit','minimum-wage-canada');
```

Public addresses will be `/ca/<category>/<subcategory>/<slug>/`. Take the real address from the sitemap.

## The jurisdiction row (migration 035, check the folder first)

| Column | Value |
|---|---|
| `code`, `name`, `url_prefix` | `CA`, Canada, `ca` |
| `currency_code`, `locale` | `CAD`, `en-CA` |
| `tax_year_start_month`, `tax_year_start_day` | 1, 1 (agrees with `COUNTRY_TAX_YEARS`) |
| `is_active` | **0** until launch (a later migration sets it to 1, as `034_new_zealand_launch.sql` did) |
| Sub-regions (Decision A) | 13 rows: `CA-AB`, `CA-BC`, `CA-MB`, `CA-NB`, `CA-NL`, `CA-NS`, `CA-NT`, `CA-NU`, `CA-ON`, `CA-PE`, `CA-QC`, `CA-SK`, `CA-YT` |

No semicolons in migration comments or text. Source rows (migration 036, modelled on 033) carry `publisher` set to the body's full name (Canada Revenue Agency, Employment and Social Development Canada, Service Canada, Bank of Canada, Canada Deposit Insurance Corporation, Canada Mortgage and Housing Corporation).

## Order of work

1. Richard settles Decisions A to F and approves the page list. Run the slug check.
2. Allowlist change (approval first) and migrations 035 and 036 (jurisdiction row, sources). Test on MAMP.
3. Write `docs/house-style/ca.md` from `nz.md` (points above: spelling, dates, money, Quebec line, province rule, official names).
4. Run `check-source-access.php --country=CA` on the Mac, and on the server after asking.
5. **Page 20 first (reference page)**: card audit, sources read live, CSV as a hidden draft, text, claims table, review. Add its "Reference page" section to `ca.md`.
6. Then batches of about four:
   - Batch A (tax): 1, 2, 3, 5
   - Batch B (registered plans): 6, 7, 8, then 14
   - Batch C (public pensions): 11, 12, 13, 16
   - Batch D (benefits): 17, 18, 19
   - Batch E (rate, protection, dated): 9, 10, 4, 15
7. Preflight on the server, set `is_active = 1` (a launch migration), check `/ca/` answers 200, queue the drip with
   `php scripts/run-jobs.php --enqueue=drip_country --payload='{"country":"CA","per_week":3}' --unique=drip_country:CA`. Keep one `run-jobs.php` row in hPanel.

## Lessons from the UK, Australian and NZ builds, applied to Canada

| Lesson | What it means for Canada |
|---|---|
| Card audit first | Every card checked against its source before text. No card note without a source |
| Read sources before drafting | The 25 November reading only confirmed the kind of figure. Each page is read in full again on its build day |
| Allowlist before reference sources | Add every needed domain in one change (Decision D) |
| Renamed or moved pages | **Already seen:** the GST/HST credit is now the CGEB, and its old page is a pointer. Re-find each address from the page's own links |
| Never take a figure from a search summary | None was used. Capital gains and the rule changes marked "my recollection" must be read from the CRA |
| Dated wording to track | Capital gains, CGEB, mortgage insurance rules, any "from January 1" wording. Each goes in the key dates in `docs/TODO.md` when built |
| Rate-page rules | Page 9 only. No rate in any field, no placeholder, nothing about rising or falling, title names the subject |
| Short pages beat padded ones | CRA and Service Canada pages are short and formulaic: restructure sentences, do not swap words, and rerun the near-copy check |
| Check the VPN before blaming a site | Retest any failure with the VPN on before drawing conclusions |
| `{tax_year}` fills from the latest card | A page with a 2027 card (EI) leaves the year out of its title |

## Second wave (the drip, not in the launch set)

| Page | Why it waits |
|---|---|
| Provincial minimum wages (one page per province, or one table) | Needs Decision A and a source per province |
| Provincial land transfer tax (Ontario, British Columbia, Quebec) | Province domains |
| Provincial income tax rates | Province domains, and Quebec's separate return |
| Canada Student Loans | Source to find |
| Registered Disability Savings Plan, RESP and the Canada Education Savings Grant | CRA and ESDC pages |
| Quebec Pension Plan, QPIP | Retraite Québec and Revenu Québec sites, not tested |
| Tax on dividends and the dividend tax credit | CRA |
| Statutory holidays and annual vacation (federal and by province) | Needs Decision A |
| Parental benefits (EI maternity, parental) | ESDC pages |
| Mortgage stress test (minimum qualifying rate) | OSFI or Department of Finance |
| Canada Workers Benefit, Canada Disability Benefit | CRA, ESDC |

## Build log

| Page | Status |
|---|---|
| Groundwork, 25 November 2026 | Decisions A to F settled. Allowlist, migrations 035 and 036 and `ca.md` done on the Mac (see To do). Pages found since the plan: federal brackets are on `.../tax-rates-brackets/current-year.html` (states the bands as text), the Bank of Canada page shows its rate history as text, CDIC's `/depositors/whats-covered/` states the per-category limit, the CGEB page is `.../canada-groceries-essentials-benefit.html`, and the CCB amounts are on `.../canada-child-benefit-we-calculate-your-ccb.html`. Source rows added for those that state a figure. Basic personal amount still has no page that states it |
| Planning, 25 November 2026 | Plan written. About 30 addresses tested from the Mac. The Canada.ca, Bank of Canada, CDIC, CMHC and Ontario sites read; none refused. The GST/HST credit page says it was renamed the Canada Groceries and Essentials Benefit in July 2026. The federal tax rates page and the CCB overview read but state no figures (linked pages to find). No figure entered anywhere. A fault found in `scripts/read-source.php` (see TODO) |
