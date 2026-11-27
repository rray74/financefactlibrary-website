# United States content map (plan)

Status: **plan only, first written on 27 November 2026.** Nothing is built: no figures, page text, CSVs, migrations or code exist for the
US, and the server and database were not touched. The tables hold **no figure values**: a value goes into a CSV only after it has been
read off its official page on the day the CSV is built (`core.md`). Where a page's kind of figure is named, it is a label, not a value.

Everything about sources was read from the Mac on 27 November 2026 with `scripts/read-source.php` (about 100 addresses, no search
summaries used). **VPN state: the Mac's exit address showed GB, so the VPN was off or set to the UK.** Nothing was retested with the VPN
set to the US (see "Retest queue"). Addresses are **read** (the fetcher returned the page) or **to find**. Where the Read column says
OK, the page answered and showed the kind of figure named, or (marked "figure elsewhere") it answered but the figure sits on another page.
Addresses came from a site's own links (the IRS tax topics index, the IRS newsroom, the Fed's monetary policy links, the FDIC's
deposit insurance links) except those marked † , which I typed from the site's structure and which happened to read: confirm each
† address against its parent page's links on build day. Written in the shape of `ca-content-map.md`, which is the template.

Target: about 15 to 20 pages at launch, imported as hidden drafts, then `is_active = 1` on the `US` row and a drip. This plan proposes **20**.

## The finding that shapes everything: 2027 figures are already published

On 27 November 2026 the IRS, SSA, CMS and FHFA have all published their 2027 figures, but the IRS's own "current year" pages still show
earlier years. So the US build meets, on day one, what Canada met only for EI.

| Body | What is already out | What lags |
|---|---|---|
| IRS | 2027 inflation adjustments (newsroom release, read) and the 2027 retirement plan limits release (read) | The "Federal income tax rates and brackets" page, last reviewed 27 July 2026, shows **2025** rates. The 401(k) topic page states the 2026 limit only. The standard deduction page is an online tool and states no figure in the text the fetcher sees |
| SSA | 2027 contribution and benefit base, the 2027 SSI amounts and the cost-of-living adjustment payable in January 2027 (all read) | Nothing seen |
| CMS | 2027 Medicare Part A and B premiums fact sheet (read), and the medicare.gov costs page already quotes 2027 amounts beside 2026 | Nothing seen |
| FHFA | 2027 conforming loan limits (the data page lists them) | The news release address the page names first is last year's: find the 2027 release from the page's link |
| Federal Reserve | 2027 FOMC meeting schedule revised on 12 November 2026 (press release, read) | No stable page states the target range as text (see page 10) |

Consequence (Decision G): a card built now would be a 2026 figure that is out of date on 1 January. The importer refuses a future
`effective_from`, so 2027 figures cannot be imported before 1 January 2027. **Recommended: read and draft everything in December, import
the figures that do not change on 1 January at once, and import the indexed pages' 2027 cards on or after 1 January 2027, launching in the
first week of January.** No page then has to be refreshed straight after launch.

## What already exists in code for the US

| Where | What is already there |
|---|---|
| `includes/placeholders.php`, `COUNTRY_TAX_YEARS` | `'US' => month 1, day 1, style 'single'`. Calendar year, `{tax_year}` shows the year of the page's latest card. Needs no change |
| `includes/functions.php`, `COUNTRY_WORDING` | `US`: short "the US", adjective "US", sources "the IRS, the Social Security Administration and the Federal Reserve". Could be widened to name the other bodies (optional, ask first) |
| `includes/text-checks.php`, `COUNTRY_TEXT_RULES` | No `US` entry. Optional code change, ask first (Decision J) |
| `jurisdictions` row, sources, allowlist | **Nothing yet.** No `US` row, no US domain in `FACT_SOURCE_ALLOWLIST`, no `docs/house-style/us.md`. Next free migration: **038** (the folder ends at 037) |
| Homepage, About, legal pages | Already mention the US (check wording when launching) |

## Can the site read them? (tested 27 November 2026, Mac, VPN off or UK)

| Site | Result | Notes |
|---|---|---|
| `irs.gov` | **Read**, every address tried (about 50) | Several pages state figures as plain text. Some pages are tools or tables the fetcher flattens: confirm each figure is in the text before planning a card. The same page resolves under two addresses in places (see "Same page, two addresses") |
| `ssa.gov` | **Read** (cost-of-living tables and the benefit planner). `curl` is refused with 403 but the project's fetcher is not | Two pages gave HTTP 403: `/oact/cola/maxbenefit.html` and `/benefits/retirement/planner/maxfamily.html`. Retest |
| `federalreserve.gov` | **Read** (policy, FOMC, press releases, H.15) | Fed decisions are dated press releases, not a stable rate page |
| `newyorkfed.org` | **Read** (effective federal funds rate page) | States the effective rate, not the target range. Not needed unless Decision K goes that way |
| `fdic.gov` | **Read** | `/deposit-insurance` and `/resources/deposit-insurance/` are one page. Per-depositor limit is on `understanding-deposit-insurance` |
| `ncua.gov` | **Read** (share insurance page) | Credit unions. Second wave |
| `cms.gov` and `medicare.gov` | **Read** | Both state premiums and deductibles |
| `fhfa.gov` | **Read** | |
| `dol.gov` | **Mixed.** Read: the minimum wage overview, the state minimum wage table, the minimum wage history, FMLA, overtime overview, unemployment insurance. **HTTP 403:** `/agencies/whd/minimum-wage/federal`, `/agencies/whd/flsa/overtime`, `/agencies/whd/fact-sheets/2-flsa-overview` and the `/agencies/whd/state/...` leave pages | The 403s are on deeper pages of a site that reads elsewhere, so it looks like rate limiting or bot rules, not geography. Retest with the VPN set to the US and from the server |
| `consumerfinance.gov` | **Read** | Owning a home and "Ask CFPB" pages. Mostly no figures. Second wave |
| `hud.gov` | **Read** but the buying pages are thin (under 800 characters) | FHA loan limits page **not found** (my guess 404). Second wave |
| `fiscaldata.treasury.gov`, `home.treasury.gov`, `federalregister.gov` | **Read** | Not needed at launch |
| `treasurydirect.gov` | **Fails: SSL certificate error in MAMP's PHP** (`curl` reads it fine) | Looks like the Mac's PHP certificate bundle, not the site. Retest from the server. I bonds are second wave until then |
| `studentaid.gov` | **Fails: page comes back almost empty** (needs JavaScript) | Federal student loan interest rates cannot be built from this site. Second wave. Not fixed by a VPN |
| `ed.gov`, `fha.com` | 403 | `fha.com` is not an official site. Ignore |
| State sites (FTB, NY Tax, Texas Comptroller, Florida Revenue, Washington DOR, Illinois DOR, NJ Taxation, Mass DOR, CDTFA, NY Labor) | **Read** (home pages or one page each) | `dir.ca.gov` failed with the same certificate error as TreasuryDirect. Each state is its own domain, so the state layer needs a domain per state (Decision A) |

Server read test (`check-source-access.php --country=US`) is **not run**: it needs source rows first, and a server run needs Richard's OK.

### Retest queue (before any page rests on these)

1. DOL `/agencies/whd/minimum-wage/federal`, the `whd/state/...` pages, SSA `maxbenefit` and `maxfamily` (403): Mac with the VPN on and set to the US, then the server.
2. `treasurydirect.gov` and `dir.ca.gov` (certificate error): the server.
3. `studentaid.gov` (JavaScript): confirm on the server. If still empty, no US student loan rate page at launch.
4. Check the VPN state before blaming any site. This session could not set it.

## Things that differ from the UK, Australia, New Zealand and Canada

1. **A federal layer and a state layer.** Fifty states and the District of Columbia set their own income tax (or none), minimum wage,
   leave rules, sales tax, transfer taxes and unemployment benefits. There is no single official source for most of them. Decision A.
2. **No national sales tax and no national VAT-style page.** Sales tax is entirely state and local, so there is no federal sales tax page.
3. **Tax law changed in 2025 and the IRS's pages still name it.** The 2027 release's title refers to a 2025 law, and the 2026 release's to
   "the One, Big, Beautiful Bill". Pages on tax must say only what the IRS pages say about the change, and are dated-change pages.
4. **Filing years.** A "tax year 2026" return is filed in 2027. The IRS writes "tax year 2027" for amounts that apply to income earned in
   2027. The page `{tax_year}` is the year of the figures, never the filing year.
5. **Social Security is run by the SSA, and Medicare by CMS.** Retirement pages rest on `ssa.gov`, and Medicare on `cms.gov` and `medicare.gov`.
   Medicare is also a health-insurance subject: Decision F keeps it under Social Security & Retirement.
6. **Employer-based retirement and health accounts** (401(k), IRA, HSA) have limits indexed each 1 January with phase-outs by filing
   status. These are the pages with the most cards.
7. **The Federal Reserve sets a target range, not a single rate.** A rate page whose card is a range. Decision K.
8. **Deposit insurance is two schemes:** the FDIC (banks) and the NCUA (credit unions). Launch takes the FDIC only.
9. **Money.** "$" is also the Canadian, Australian and New Zealand sign. Decision B.
10. **Spelling, dates and punctuation.** American English, "January 1, 2027", the serial comma. Decision C.
11. **No Quebec-style carve-out**, but **Puerto Rico and the other territories** have their own tax systems (Puerto Rico's own income tax).
    The launch pages are about the 50 states and DC and say so once on the About text, not page by page. Decision A.

## To do

Kept up to date as the work goes. Last updated 27 November 2026.

- [x] Plan written and sources tested (27 November).
- [ ] **Richard settles Decisions A to K** (below) and approves the page list.
- [ ] Slug check on the local database (SQL below).
- [ ] `FACT_SOURCE_ALLOWLIST`: eight domains (approval first, a code change).
- [ ] Migrations 038 (US row, inactive, 51 regions) and 039 (source rows). Test on MAMP.
- [ ] `docs/house-style/us.md`, modelled on `ca.md`.
- [ ] `check-source-access.php --country=US` on the Mac, then the server (ask first).
- [ ] Page 17 (Minimum wage) first, as the reference page.
- [ ] Batches, preflight, a launch migration (041 or the next free number), the drip.

## Decisions waiting (recommendation first)

**A. The state layer, and which states launch.** Options:
1. **Recommended: add 51 sub-jurisdiction rows (50 states and DC, codes `US-AL` to `US-WY`, `US-DC`, ISO 3166-2, as `CA-ON` and `AU-NSW` did) in migration 038. No state gets its own page at launch.** Federal pages carry one sentence where a state's rule differs ("your state may set a higher
   wage" and so on), and say nothing where a rule is federal everywhere. The first state pages come in the second wave, starting with a single
   **State minimum wage** page built from the DOL's one state table (which covers all states in one place and was updated 5 November 2026), then
   the states with the most Search Console impressions once federal pages are live. Territories are not given rows (Puerto Rico, Guam,
   the Virgin Islands, American Samoa and the Northern Mariana Islands each have different tax and benefit rules): say so in `us.md`.
2. Federal only, no state rows yet (add them when the first state page is built).
3. One or two states at launch (California and New York, say). Not recommended: each needs its own domain, its own law and its own tax
   year, and the launch would carry two thin state pages instead of two more federal ones.

**B. Money: bare "$"** (recommended, as CA, AU and NZ do), currency code `USD`. "US$" only on a page that compares countries. Alternative:
"US$" throughout. The site already labels each page's country in its address and title, so the sign is not ambiguous in context.

**C. Language and style.** Recommended: **American English** (color, labor, center, -ize, license for noun and verb, defense, check
for a bank check), **dates as "January 1, 2027"** (month, day, comma, year, and a comma after the year mid-sentence, as the federal government
writes them), **double quotation marks**, **no em dashes**, ranges in words, and the **serial comma** ("A, B, and C"). The serial comma is the one
place `us.md` differs from `ca.md`, which drops it: American official writing keeps it, and the IRS and SSA pages I read do. Alternative: drop it for site-wide
consistency. Official names exactly as written (Internal Revenue Service (IRS) at first mention, then IRS; Social Security Administration (SSA);
Federal Reserve; Federal Deposit Insurance Corporation (FDIC); Department of Labor (DOL); Centers for Medicare & Medicaid Services (CMS);
Federal Housing Finance Agency (FHFA)). Never use another country's term for a US thing (not "pension" for a 401(k), not "ISA" for an IRA).

**D. Tax year.** The calendar year, written 2027 on a page of 2027 figures (`COUNTRY_TAX_YEARS['US']` is already the single style).
Needs no code change.

**E. Allowlist (a code change, so asking first).** Recommended: each body's own domain, not `.gov`: `irs.gov`, `ssa.gov`,
`federalreserve.gov`, `fdic.gov`, `dol.gov`, `cms.gov`, `medicare.gov` and `fhfa.gov` (eight, all read from the Mac). Held back until a
page needs them: `consumerfinance.gov`, `hud.gov`, `ncua.gov`, `treasurydirect.gov` (certificate error), `newyorkfed.org` (Decision K),
`studentaid.gov` (does not read), `legislation`-type sources (`congress.gov`, `govinfo.gov`: not tested, add only if a dispute needs the statute), and any state domain.

**F. Category names.** Recommended: **Tax; Savings & Investments; Social Security & Retirement; Mortgages & Property; Work & Benefits.**
Medicare sits in the third because its card pages belong with Social Security's. Alternative: add a sixth, "Health Costs", once Medicare Part D, ACA
premium credits and the Health Savings Account have more than two pages.

**G. Launch date and which year the figures carry.** Recommended: **build in December, launch in the first week of January 2027 on the 2027
figures** (the plan in "The finding that shapes everything"). Pages with no January change (minimum wage, FMLA, FDIC, the Fed rate, home sale
exclusion if built) are imported as soon as built. Alternative: launch in December on 2026 figures and refresh every indexed page after 1 January
(about twelve key dates and a second round of text checks within a fortnight of launch). Not recommended.

**H. Drip pace and order.** Australia (3 a week, about the first week of January), New Zealand (3 a week, until about mid-January) and Canada (3 a week, until
about mid-January) are all dripping at once. Recommended: **start the US drip at launch at 3 a week**, running with whatever is left of Canada and New Zealand, which gives
about 6 to 7 weeks. The drip publishes in subject id order, so **build order is publishing order**: build the pages people search for most (income tax
rates, standard deduction, Social Security) early in January's batch and the niche ones last. Alternative: 4 a week, since the US is the larger audience.

**I. Slug suffix and wording.** Recommended: `-united-states` on generic slugs (as `-canada` and `-new-zealand`), and the plain US name for US-only subjects (FICA, FMLA,
FDIC). Text says "the United States" at first mention and "the US" or "US" after it, as `COUNTRY_WORDING` already does for the adjective.

**J. Text rules code change (ask first, optional).** A `US` entry in `COUNTRY_TEXT_RULES` (em dash, ordinal date, a date written day-month-year, single quotation marks) so the nightly check
applies US wording rules, as NZ got. Not needed to start.

**K. How the rate page (page 10) is sourced.** The Fed states the target range in each FOMC statement (a dated address such as
`/newsevents/pressreleases/monetary20261028a.htm`), not on a stable page: the policy tools, FOMC and monetary policy pages, H.15 and the New York Fed EFFR page
do not state the target range as text. Options: (1) **Recommended: the card cites the latest FOMC statement and is re-sourced at each meeting** (eight a year; the importer
takes a new `source_url` with each new value) with the stable FOMC calendar page as a reference source; (2) card the effective federal funds rate from the New York Fed page
instead (reads, needs `newyorkfed.org`, and is a different number that moves daily); (3) leave the rate page out of the launch (launch set 19). Rate-page rules from
`core.md` apply either way: no rate in any field, no direction, `review_frequency_days` 42, title names the subject.

## The launch set: 20 pages proposed

Fact keys would start `us_`. Slugs are proposed; the collision check settles them. **Read** says what was seen on 27 November 2026. "2027" means a
2027 figure is already published (see above). **Gate** = the figures are indexed and the 2027 cards import on or after 1 January 2027 (Decision G); "none" = import when built.

### Tax (6 pages)

| # | Page and slug | Why | Planned figures (labels only) | Source (read 27 Nov) | Read | Changes when |
|---|---|---|---|---|---|---|
| 1 | **Federal income tax rates** `income-tax-rates-united-states` | The anchor tax page. Dated-change page (2025 law) | Rates, and bracket thresholds by filing status | 2027 figures: IRS newsroom `.../irs-releases-tax-inflation-adjustments-for-tax-year-2027-taxpayers-continue-to-benefit-from-the-working-families-tax-cuts` (states rates and the top thresholds as text). 2026: `.../irs-releases-tax-inflation-adjustments-for-tax-year-2026-including-amendments-from-the-one-big-beautiful-bill`. Reference only: `irs.gov/filing/federal-income-tax-rates-and-brackets` | OK. **Lags: the rates page shows 2025.** The release may not list every filing status's every bracket: **read the Revenue Procedure the release cites** for the full table | 1 January (indexed) and on law change. Gate |
| 2 | **Standard deduction** `standard-deduction-united-states` | The tax-free amount, like AU's threshold | Standard deduction by filing status, extra for age 65 or blindness | The two newsroom releases above (the 2027 release states filing-status amounts as text). `irs.gov/credits-deductions/individuals/standard-deduction` is the online tool: **states no figure**, reference only | OK in the releases | 1 January. Gate |
| 3 | **Capital gains tax** `capital-gains-tax-united-states` | The US counterpart of the UK and Canadian pages. Rates fixed in law, thresholds indexed | Long-term rates and the income thresholds, the holding period, the net investment income tax rate | `irs.gov/taxtopics/tc409` (capital gains and losses), `.../tc559` (net investment income tax), thresholds from the 2027 release (all read) | OK, thresholds in the release | Thresholds 1 January. Gate |
| 4 | **Social Security and Medicare payroll tax (FICA)** `fica-payroll-tax` | Deductions through payroll, with the wage base and the additional Medicare tax | Employee and employer rates, the Social Security wage base, the additional Medicare tax rate and threshold | `irs.gov/taxtopics/tc751` and `.../tc560` (additional Medicare tax), wage base from `ssa.gov/oact/cola/cbb.html` (all read; the SSA page already states the 2027 base) | OK | Wage base 1 January. Gate |
| 5 | **Self-employment tax** `self-employment-tax-united-states` | Same rates as page 4 paid by the self-employed, with the deduction for half | Rate, the earnings floor, the half deduction | `irs.gov/taxtopics/tc554` (read), wage base from SSA | OK | 1 January (wage base). Gate |
| 6 | **Estate and gift tax** `estate-and-gift-tax-united-states` | The federal wealth transfer tax. **Dated-change page**: the exclusion amount was changed by the 2025 law (my recollection, unverified) | Basic exclusion amount, annual gift exclusion, top rate | `irs.gov/businesses/small-businesses-self-employed/estate-tax` and `.../gift-tax` (both read; the annual gift exclusion and exclusion amount come from the releases or a page to find) | OK, figures to find | 1 January and on law change. Gate |

### Savings and investments (5 pages)

| # | Page and slug | Why | Planned figures | Source | Read | Changes when |
|---|---|---|---|---|---|---|
| 7 | **IRA contribution limits** `ira-contribution-limits` | Traditional and Roth in one page, with the Roth income phase-out by filing status | Annual limit, catch-up, Roth phase-out ranges, traditional deduction phase-outs | `irs.gov/retirement-plans/roth-iras`, `.../traditional-iras`, `.../plan-participant-employee/retirement-topics-ira-contribution-limits` (all read) and the 2027 retirement limits release `.../401k-limit-increases-to-25500-for-2027-ira-limit-remains-7500`† | OK | 1 January. Gate |
| 8 | **401(k) contribution limit** `401k-contribution-limit` | The workplace plan limit | Elective deferral limit, catch-up amounts, the total annual additions limit | `irs.gov/retirement-plans/plan-participant-employee/retirement-topics-401k-and-profit-sharing-plan-contribution-limits` (read, **states the 2026 limit only**) and the 2027 release above | OK. **Lags: the topic page is a year behind** | 1 January. Gate |
| 9 | **Health savings account limits** `hsa-contribution-limits` | The tax-advantaged health account | Annual limit for self-only and family coverage, catch-up, high-deductible plan minimums and out-of-pocket maximums | IRS Publication 969 (`irs.gov/publications/p969`, read, 112,500 characters, longer than the fetch limit so use `--find`) | Pub 969 read. **The IRS HSA page address I guessed returned 404: find the current-year limits notice from the IRS newsroom or the pub's links.** A candidate to drop first | 1 January. Gate |
| 10 | **Federal funds rate** `federal-funds-rate` | **The rate page** | Target range (see Decision K) | `federalreserve.gov/newsevents/pressreleases/monetary20261028a.htm` (read, states the target range and the Committee's vote), `.../monetarypolicy/fomccalendars.htm` (read, the meeting schedule through 2027) | OK. The stable pages do not state the range | At each FOMC meeting. Read the calendar live, never from memory |
| 11 | **FDIC deposit insurance** `fdic-deposit-insurance` | Like FSCS and CDIC. Fixed in law | Standard insurance amount per depositor, per ownership category, per insured bank | `fdic.gov/resources/deposit-insurance/understanding-deposit-insurance/` (read, states the amount), `.../brochures/deposits-at-a-glance` and `.../insured-deposits` (read) | OK | Rarely. None |

### Social Security and retirement (3 pages)

| # | Page and slug | Why | Planned figures | Source | Read | Changes when |
|---|---|---|---|---|---|---|
| 12 | **Social Security retirement age** `social-security-retirement-age` | Full retirement age by birth year, and early and delayed retirement effects | Full retirement age by birth year, the reduction and credit per month, the earliest age | `ssa.gov/benefits/retirement/planner/agereduction.html` and `.../delayret.html` (read) | OK. Fixed in law | Rarely. None |
| 13 | **Social Security cost-of-living adjustment and benefit base** `social-security-cola` | The yearly uprating, with the taxable maximum and the quarters of coverage amount | The percentage, the contribution and benefit base, the quarter of coverage amount | `ssa.gov/oact/cola/latestCOLA.html`, `.../cbb.html`, `.../QC.html`, `.../colaseries.html` (all read; the 2027 values are already stated) | OK, 2027 published | Each October. Gate |
| 14 | **Medicare Part B premium** `medicare-part-b-premium` | The monthly premium and the deductible, with the income-related surcharge | Part B standard premium and deductible, Part A premium (for those who buy it), the Part A deductible | `cms.gov/newsroom/fact-sheets/2027-medicare-parts-b-premiums-deductibles`† (read, 2027) and the 2026 fact sheet (read), `medicare.gov/basics/costs/medicare-costs` (read, shows 2026 and 2027) | OK, 2027 published | 1 January. Gate |

### Mortgages and property (2 pages)

| # | Page and slug | Why | Planned figures | Source | Read | Changes when |
|---|---|---|---|---|---|---|
| 15 | **Conforming loan limits** `conforming-loan-limits` | The ceiling for mortgages the government-backed firms buy, with high-cost areas. Indexed | Baseline limit, the ceiling for high-cost areas, limits for two to four units | `fhfa.gov/data/conforming-loan-limit` (read; the 2027 limits are listed, the figures sit in linked files), `.../news/news-release/fhfa-announces-conforming-loan-limit-values-for-2026`† (read, last year's) | OK. **Find the 2027 news release from the data page's link.** Dated-change risk if FHFA revises | Each late November. Gate |
| 16 | **Selling your home: the gain exclusion** `home-sale-gain-exclusion` | Fixed in law, with a two-year ownership and use test | Exclusion for single and joint filers, the ownership and use periods | `irs.gov/taxtopics/tc701` (read) | OK | Rarely. None |

### Work and benefits (4 pages)

| # | Page and slug | Why | Planned figures | Source | Read | Changes when |
|---|---|---|---|---|---|---|
| 17 | **Minimum wage** `minimum-wage-united-states` (**the reference page**) | One federal rate, one source family, and the state layer from one table. No January gate | Federal minimum wage, the tipped and youth cash rates if the page states them | `dol.gov/agencies/whd/minimum-wage` (read, states the federal rate and its effective date), `dol.gov/general/topic/wages/minimumwage` (read), `.../minimum-wage/history`† (read), state table `.../minimum-wage/state` (read, updated 5 November 2026). `.../minimum-wage/federal` is **403** | OK, via the overview page | Only when Congress changes it. States change on their own dates |
| 18 | **Overtime pay** `overtime-pay-united-states` | Time and a half over a weekly hours threshold, with exemptions by salary and duties. **Dated-change page**: the salary threshold rules have changed through litigation and rulemaking (my recollection, unverified) | The overtime hours threshold, the multiplier, the exempt salary level if the page states it | `dol.gov/agencies/whd/overtime` and `dol.gov/general/topic/wages/overtimepay` (both read, overview only). `.../flsa/overtime` and the fact sheet are **403**: retest | OK, figures to confirm | When the rule changes. Read the page |
| 19 | **Family and Medical Leave Act** `family-and-medical-leave-act` | Unpaid, job-protected leave, fixed in law. States add paid leave | Weeks of leave, employer size and hours worked tests, the leave periods | `dol.gov/agencies/whd/fmla` (read) | OK. Fixed in law | Rarely. None |
| 20 | **Earned income tax credit** `earned-income-tax-credit` | The refundable credit for lower-income workers, with income limits by children and status. Indexed | Maximum credit by number of children, the investment income limit, the income limits | `irs.gov/credits-deductions/individuals/earned-income-tax-credit` and `.../earned-income-tax-credit-eitc` (**same page**, read), the tables page `.../earned-income-tax-credit/earned-income-and-earned-income-tax-credit-eitc-tables` (read) | OK. **Find the table of maximum credits and limits for 2027**; it may be a separate tool | 1 January. Gate |

Ranking if 20 is too many: drop 9 (HSA, no confirmed address) and 6 (estate and gift tax, dated-change and indexed) first. Promote the Child Tax
Credit if there is room (it reads at two addresses, same page).

## What each page exercises

| Pipeline part | Pages |
|---|---|
| Reference page that settles `us.md` | 17 |
| Rate page (checked every 42 days or less) | 10 |
| State layer | 17 (the DOL state table), 19 (states add paid leave), 3, 1 (state tax note) |
| 2027 figures already published, import on or after 1 January | 1, 2, 3, 4, 5, 6, 7, 8, 9, 13, 14, 15, 20 |
| Figures with no January change | 10, 11, 12, 16, 17, 19 |
| Dated change (law changed or proposed) | 1, 6, 18 and any page citing the 2025 law |
| Source page lags its own newer release | 1, 8, 15 |
| Same page, two addresses | 20, 11 (and CTC in the second wave) |
| Same subject in other countries (slug suffix) | 1, 2, 3, 5, 6, 17, 18 |
| Shared card | 4 and 5 (wage base and rates), 7 and 8 (IRA and 401(k) share the 2027 release) |

## Slugs

Subject slugs are unique across all countries. A repository search of `data/imports/` on 27 November 2026 found none of the slugs below. **Run
this on the local database before importing anything** and rename any row it returns:

```
SELECT slug, status FROM subjects WHERE slug IN (
  'income-tax-rates-united-states','standard-deduction-united-states','capital-gains-tax-united-states','fica-payroll-tax',
  'self-employment-tax-united-states','estate-and-gift-tax-united-states','ira-contribution-limits','401k-contribution-limit',
  'hsa-contribution-limits','federal-funds-rate','fdic-deposit-insurance','social-security-retirement-age',
  'social-security-cola','medicare-part-b-premium','conforming-loan-limits','home-sale-gain-exclusion',
  'minimum-wage-united-states','overtime-pay-united-states','family-and-medical-leave-act','earned-income-tax-credit');
```

Public addresses will be `/us/<category>/<subcategory>/<slug>/`. Take the real address from the sitemap.

## The jurisdiction row (migration 038, check the folder first)

| Column | Value |
|---|---|
| `code`, `name`, `url_prefix` | `US`, United States, `us` |
| `currency_code`, `locale` | `USD`, `en-US` |
| `tax_year_start_month`, `tax_year_start_day` | 1, 1 (agrees with `COUNTRY_TAX_YEARS`) |
| `is_active` | **0** until launch (a later migration sets it to 1, as 037 did for Canada) |
| Sub-regions (Decision A) | 51 rows: `US-AL`, `US-AK`, `US-AZ`, `US-AR`, `US-CA`, `US-CO`, `US-CT`, `US-DE`, `US-DC`, `US-FL`, `US-GA`, `US-HI`, `US-ID`, `US-IL`, `US-IN`, `US-IA`, `US-KS`, `US-KY`, `US-LA`, `US-ME`, `US-MD`, `US-MA`, `US-MI`, `US-MN`, `US-MS`, `US-MO`, `US-MT`, `US-NE`, `US-NV`, `US-NH`, `US-NJ`, `US-NM`, `US-NY`, `US-NC`, `US-ND`, `US-OH`, `US-OK`, `US-OR`, `US-PA`, `US-RI`, `US-SC`, `US-SD`, `US-TN`, `US-TX`, `US-UT`, `US-VT`, `US-VA`, `US-WA`, `US-WV`, `US-WI`, `US-WY` |

No semicolons in migration comments or text. Source rows (migration 039, modelled on 036) carry `publisher` set to the body's full name (Internal Revenue
Service, Social Security Administration, Board of Governors of the Federal Reserve System, Federal Deposit Insurance Corporation, Department of Labor, Centers for Medicare & Medicaid
Services, Federal Housing Finance Agency).

## The source allowlist

`FACT_SOURCE_ALLOWLIST` in `includes/fact-writer.php` is a code change and **needs Richard's approval before any edit**. It matches a domain and all its
subdomains, so adding `gov` as a whole would admit every government site.

| Domain | Needed for | Status |
|---|---|---|
| `irs.gov` | Pages 1 to 9, 16, 20 | **Needed.** Reads |
| `ssa.gov` | Pages 4, 5, 12, 13 | **Needed.** Reads (two deeper pages 403) |
| `federalreserve.gov` | Page 10 | **Needed.** Reads |
| `fdic.gov` | Page 11 | **Needed.** Reads |
| `cms.gov`, `medicare.gov` | Page 14 | **Needed.** Read |
| `fhfa.gov` | Page 15 | **Needed.** Reads |
| `dol.gov` | Pages 17, 18, 19 | **Needed.** Reads on some pages, 403 on others. Add once the pages used are confirmed to read from the server |
| `consumerfinance.gov`, `hud.gov`, `ncua.gov` | Second wave | Not at launch. Read |
| `treasurydirect.gov`, `studentaid.gov` | I bonds, student loan rates | Not at launch. Failures noted above |
| `newyorkfed.org` | Decision K option 2 | Only if chosen |
| A state's own domain (`ftb.ca.gov`, `tax.ny.gov` and so on) | Second wave | One per state page, added when built |

Reference sources can only use an allowlisted domain, so the allowlist change comes **before** any reference source (the lesson from AU, NZ and Canada).

## Same page, two addresses (reuse one source row)

These resolved to the same page on 27 November 2026 (same character count), so a source row is made for one address and cards reuse it:

- `irs.gov/credits-deductions/individuals/earned-income-tax-credit` and `.../earned-income-tax-credit-eitc`
- `irs.gov/credits-deductions/individuals/child-tax-credit` and `.../child-tax-credit-and-credit-for-other-dependents`
- `fdic.gov/deposit-insurance` and `fdic.gov/resources/deposit-insurance/`

## Order of work

1. Richard settles Decisions A to K and approves the page list. Run the slug check.
2. Allowlist change (approval first). Migrations 038 and 039 (jurisdiction row with 51 regions, source rows). Test on MAMP.
3. Write `docs/house-style/us.md` from `ca.md`.
4. Run `check-source-access.php --country=US` on the Mac, then the server (ask first). Retest the queue above.
5. **Page 17 first (reference page).** Card audit, sources read live, CSV as a hidden draft, text, claims table, review. Add its "Reference page" section to `us.md`.
6. December batches, pages with no January gate: **Batch A** 17, 19, 11, 16, 12, 10 (read the FOMC calendar live).
7. First week of January 2027, after the 2027 `effective_from` dates are no longer in the future: **Batch B** (tax) 1, 2, 3, 4, 5; **Batch C** (savings) 7, 8, 9; **Batch D** (Social Security and Medicare) 13, 14; **Batch E** 15, 18, 20, 6.
8. Preflight on the server, set `is_active = 1` (launch migration), check `/us/` answers 200, queue the drip with
   `php scripts/run-jobs.php --enqueue=drip_country --payload='{"country":"US","per_week":3}' --unique=drip_country:US`. Keep one `run-jobs.php` row in hPanel.

## Lessons from the UK, AU, NZ and Canada builds, applied to the US

| Lesson | What it means for the US |
|---|---|
| Card audit first | Every card checked against its source before text. No card note without a source |
| Read sources before drafting | The 27 November reading only confirmed the kind of figure. Each page is read in full again on its build day |
| Allowlist before reference sources | Add all eight domains in one change (Decision E) |
| An official page can lag a newer release (CRA's child benefit page; FCAC's down payment text against its own table) | **Already seen:** the IRS rates page shows 2025, the 401(k) topic page shows 2026 and the 2027 limits are on newsroom releases. Read the Revenue Procedure or release, and where two sources disagree, the legislation or the second source |
| Same page under two addresses | Three pairs found (above). Reuse the existing source row |
| Dated-change pages need a flag and a key date | Pages 1, 6, 18 (and any citing the 2025 law). Each gets a key date in `docs/TODO.md` when built |
| Rate-page rules | Page 10 only: no rate or direction, title names the subject, FOMC dates read live from the calendar page, never from memory |
| Figures indexed on 1 January or quarterly | The 13 pages marked Gate. Social Security's cost-of-living adjustment is each October, Medicare's premiums each November, FHFA's limits each November |
| Short official pages beat padded text | IRS tax topics and SSA pages are short and formulaic: restructure sentences, rerun the near-copy check |
| Check the VPN before blaming a site | Not done for the DOL and SSA 403s: queue above |
| A search is fine to FIND an address, never a figure source | No search summary was used. Capital gains, estate and gift and overtime rule history marked "my recollection" must be read from the IRS and DOL |
| `{tax_year}` fills from the latest card | A page with a 2027 card and a 2026 previous value shows 2027, which is the intent here. Leave the year out of the title only where a page mixes years |
| Do not guess addresses | Guessed addresses returned 404 (the IRS HSA page, the RMD page, the HUD FHA limits page). Take links from the parent page |

## Second wave (the drip, not in the launch set)

| Page | Why it waits |
|---|---|
| State minimum wages (one page from the DOL state table) | Needs Decision A. First state page, no new domain |
| State income tax (by state), state sales tax, transfer taxes | One domain per state, and no single official table |
| State unemployment benefits | DOL comparison pages not found, state sites |
| Child Tax Credit | `irs.gov` pages read, two addresses, same page |
| Supplemental Security Income | SSA SSI payment page read (2027 figures present) |
| Social Security earnings test and substantial gainful activity | SSA pages read |
| Required minimum distributions | The FAQ page read, the topic page address guessed wrongly |
| FHA loan limits and mortgage insurance, private mortgage insurance | HUD page not found, CFPB page read |
| I bonds and Treasury savings | `treasurydirect.gov` certificate error |
| Federal student loan interest rates and repayment plans | `studentaid.gov` needs JavaScript |
| Individual taxpayer rules for new deductions in the 2025 law (tips, overtime, seniors, car loan interest) | Dated-change, read the IRS pages as they appear |
| Credit union share insurance (NCUA) | Page read |
| Medicare Part D, ACA premium tax credits | Dated-change, source pages not found |
| Unemployment insurance federal overview | DOL page read, no figures |
| Estimated tax payments and penalties | `tc306` read |

## Build log

| Page | Status |
|---|---|
| Planning, 27 November 2026 | Plan written. About 100 addresses tested from the Mac (VPN off or UK: the exit address showed GB). IRS, SSA, Federal Reserve, FDIC, CMS, Medicare, FHFA and most DOL pages read. Failures: DOL federal minimum wage and several `whd` pages (403), SSA `maxbenefit` and `maxfamily` (403), `studentaid.gov` (empty without JavaScript), `treasurydirect.gov` and `dir.ca.gov` (certificate error in MAMP's PHP), `ed.gov` (403). Found: the IRS rates page shows 2025, the 401(k) page shows 2026, and 2027 figures are already out from the IRS, SSA, CMS and FHFA, so the launch is planned for the first week of January 2027. No figure entered anywhere. |
