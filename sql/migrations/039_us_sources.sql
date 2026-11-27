-- 039_us_sources.sql
--
-- The official-source allowlist rows for the US launch, the same job as migration 036 did for Canada. Each row is
-- a page on an official US federal site that the launch pages' figures will cite. Rows are marked allowlisted,
-- which is what lets the daily source check read a page and what the AI proposer's allowlist gate looks for.
-- Run after 038, which creates the US country row these rows use.
--
-- ONLY PAGES READ ON 27 NOVEMBER 2026 ARE HERE, each with scripts/read-source.php, and each answered and showed the
-- kind of figure the content map plans to take from it. Left out on purpose: the DOL federal minimum wage page and
-- other dol.gov pages that answered 403, two ssa.gov pages that answered 403, the IRS standard deduction page (an online
-- tool that states no figure), IRS Publication 969, the FHFA news release for 2027 and the IRS rates and brackets page
-- (still shows 2025, so it is a reference source at most). A figure must never be sourced from an unconfirmed page.
-- Add those rows in a later migration as each page is found and read.
--
-- WHEN YOU BUILD A CSV, copy the URL from here exactly into source_url. Fact rows are linked to a source by that URL,
-- and a differently written URL would make a second, non-allowlisted source. The IRS EITC page also answers at
-- .../earned-income-tax-credit-eitc and the FDIC page at /deposit-insurance, both the same page: use the address here.
--
-- publisher is the name shown on a figure's card, so it is the full name of the body. licence is left empty because the
-- reuse terms of each site were not checked. Only figures are taken, never the wording of a page.
--
-- No semicolons appear inside the comments or text in this file, so it is safe for any runner that splits a
-- migration into statements on semicolons.

INSERT INTO `sources` (`jurisdiction_id`, `publisher`, `title`, `url`, `source_type`, `is_allowlisted`)
VALUES
  ((SELECT `id` FROM `jurisdictions` WHERE `code` = 'US'), 'Internal Revenue Service', 'IRS releases tax inflation adjustments for tax year 2027', 'https://www.irs.gov/newsroom/irs-releases-tax-inflation-adjustments-for-tax-year-2027-taxpayers-continue-to-benefit-from-the-working-families-tax-cuts', 'primary', 1),
  ((SELECT `id` FROM `jurisdictions` WHERE `code` = 'US'), 'Internal Revenue Service', 'IRS releases tax inflation adjustments for tax year 2026', 'https://www.irs.gov/newsroom/irs-releases-tax-inflation-adjustments-for-tax-year-2026-including-amendments-from-the-one-big-beautiful-bill', 'primary', 1),
  ((SELECT `id` FROM `jurisdictions` WHERE `code` = 'US'), 'Internal Revenue Service', '401(k) limit and IRA limit for 2027', 'https://www.irs.gov/newsroom/401k-limit-increases-to-25500-for-2027-ira-limit-remains-7500', 'primary', 1),
  ((SELECT `id` FROM `jurisdictions` WHERE `code` = 'US'), 'Internal Revenue Service', 'Retirement topics: 401(k) and profit-sharing plan contribution limits', 'https://www.irs.gov/retirement-plans/plan-participant-employee/retirement-topics-401k-and-profit-sharing-plan-contribution-limits', 'primary', 1),
  ((SELECT `id` FROM `jurisdictions` WHERE `code` = 'US'), 'Internal Revenue Service', 'Retirement topics: IRA contribution limits', 'https://www.irs.gov/retirement-plans/plan-participant-employee/retirement-topics-ira-contribution-limits', 'primary', 1),
  ((SELECT `id` FROM `jurisdictions` WHERE `code` = 'US'), 'Internal Revenue Service', 'Roth IRAs', 'https://www.irs.gov/retirement-plans/roth-iras', 'primary', 1),
  ((SELECT `id` FROM `jurisdictions` WHERE `code` = 'US'), 'Internal Revenue Service', 'Traditional IRAs', 'https://www.irs.gov/retirement-plans/traditional-iras', 'primary', 1),
  ((SELECT `id` FROM `jurisdictions` WHERE `code` = 'US'), 'Internal Revenue Service', 'Topic no. 409, Capital gains and losses', 'https://www.irs.gov/taxtopics/tc409', 'primary', 1),
  ((SELECT `id` FROM `jurisdictions` WHERE `code` = 'US'), 'Internal Revenue Service', 'Topic no. 559, Net investment income tax', 'https://www.irs.gov/taxtopics/tc559', 'primary', 1),
  ((SELECT `id` FROM `jurisdictions` WHERE `code` = 'US'), 'Internal Revenue Service', 'Topic no. 751, Social Security and Medicare withholding rates', 'https://www.irs.gov/taxtopics/tc751', 'primary', 1),
  ((SELECT `id` FROM `jurisdictions` WHERE `code` = 'US'), 'Internal Revenue Service', 'Topic no. 560, Additional Medicare tax', 'https://www.irs.gov/taxtopics/tc560', 'primary', 1),
  ((SELECT `id` FROM `jurisdictions` WHERE `code` = 'US'), 'Internal Revenue Service', 'Topic no. 554, Self-employment tax', 'https://www.irs.gov/taxtopics/tc554', 'primary', 1),
  ((SELECT `id` FROM `jurisdictions` WHERE `code` = 'US'), 'Internal Revenue Service', 'Topic no. 701, Sale of your home', 'https://www.irs.gov/taxtopics/tc701', 'primary', 1),
  ((SELECT `id` FROM `jurisdictions` WHERE `code` = 'US'), 'Internal Revenue Service', 'Estate tax', 'https://www.irs.gov/businesses/small-businesses-self-employed/estate-tax', 'primary', 1),
  ((SELECT `id` FROM `jurisdictions` WHERE `code` = 'US'), 'Internal Revenue Service', 'Gift tax', 'https://www.irs.gov/businesses/small-businesses-self-employed/gift-tax', 'primary', 1),
  ((SELECT `id` FROM `jurisdictions` WHERE `code` = 'US'), 'Internal Revenue Service', 'Earned Income Tax Credit (EITC)', 'https://www.irs.gov/credits-deductions/individuals/earned-income-tax-credit', 'primary', 1),
  ((SELECT `id` FROM `jurisdictions` WHERE `code` = 'US'), 'Internal Revenue Service', 'EITC tables', 'https://www.irs.gov/credits-deductions/individuals/earned-income-tax-credit/earned-income-and-earned-income-tax-credit-eitc-tables', 'primary', 1),
  ((SELECT `id` FROM `jurisdictions` WHERE `code` = 'US'), 'Social Security Administration', 'Contribution and benefit base', 'https://www.ssa.gov/oact/cola/cbb.html', 'primary', 1),
  ((SELECT `id` FROM `jurisdictions` WHERE `code` = 'US'), 'Social Security Administration', 'Latest cost-of-living adjustment', 'https://www.ssa.gov/oact/cola/latestCOLA.html', 'primary', 1),
  ((SELECT `id` FROM `jurisdictions` WHERE `code` = 'US'), 'Social Security Administration', 'Quarter of coverage', 'https://www.ssa.gov/oact/cola/QC.html', 'primary', 1),
  ((SELECT `id` FROM `jurisdictions` WHERE `code` = 'US'), 'Social Security Administration', 'Cost-of-living adjustments', 'https://www.ssa.gov/oact/cola/colaseries.html', 'primary', 1),
  ((SELECT `id` FROM `jurisdictions` WHERE `code` = 'US'), 'Social Security Administration', 'Retirement age and benefit reduction', 'https://www.ssa.gov/benefits/retirement/planner/agereduction.html', 'primary', 1),
  ((SELECT `id` FROM `jurisdictions` WHERE `code` = 'US'), 'Social Security Administration', 'Delayed retirement credits', 'https://www.ssa.gov/benefits/retirement/planner/delayret.html', 'primary', 1),
  ((SELECT `id` FROM `jurisdictions` WHERE `code` = 'US'), 'Board of Governors of the Federal Reserve System', 'FOMC meeting calendars and information', 'https://www.federalreserve.gov/monetarypolicy/fomccalendars.htm', 'primary', 1),
  ((SELECT `id` FROM `jurisdictions` WHERE `code` = 'US'), 'Board of Governors of the Federal Reserve System', 'Federal Reserve issues FOMC statement, October 28, 2026', 'https://www.federalreserve.gov/newsevents/pressreleases/monetary20261028a.htm', 'primary', 1),
  ((SELECT `id` FROM `jurisdictions` WHERE `code` = 'US'), 'Federal Deposit Insurance Corporation', 'Understanding deposit insurance', 'https://www.fdic.gov/resources/deposit-insurance/understanding-deposit-insurance/', 'primary', 1),
  ((SELECT `id` FROM `jurisdictions` WHERE `code` = 'US'), 'Federal Deposit Insurance Corporation', 'Deposit insurance at a glance', 'https://www.fdic.gov/resources/deposit-insurance/brochures/deposits-at-a-glance', 'primary', 1),
  ((SELECT `id` FROM `jurisdictions` WHERE `code` = 'US'), 'Federal Deposit Insurance Corporation', 'Your insured deposits', 'https://www.fdic.gov/resources/deposit-insurance/brochures/insured-deposits', 'primary', 1),
  ((SELECT `id` FROM `jurisdictions` WHERE `code` = 'US'), 'Department of Labor', 'Minimum wage', 'https://www.dol.gov/agencies/whd/minimum-wage', 'primary', 1),
  ((SELECT `id` FROM `jurisdictions` WHERE `code` = 'US'), 'Department of Labor', 'State minimum wage laws', 'https://www.dol.gov/agencies/whd/minimum-wage/state', 'primary', 1),
  ((SELECT `id` FROM `jurisdictions` WHERE `code` = 'US'), 'Department of Labor', 'History of changes to the minimum wage law', 'https://www.dol.gov/agencies/whd/minimum-wage/history', 'primary', 1),
  ((SELECT `id` FROM `jurisdictions` WHERE `code` = 'US'), 'Department of Labor', 'Overtime pay', 'https://www.dol.gov/agencies/whd/overtime', 'primary', 1),
  ((SELECT `id` FROM `jurisdictions` WHERE `code` = 'US'), 'Department of Labor', 'Family and Medical Leave Act', 'https://www.dol.gov/agencies/whd/fmla', 'primary', 1),
  ((SELECT `id` FROM `jurisdictions` WHERE `code` = 'US'), 'Centers for Medicare & Medicaid Services', '2027 Medicare Parts A and B premiums and deductibles', 'https://www.cms.gov/newsroom/fact-sheets/2027-medicare-parts-b-premiums-deductibles', 'primary', 1),
  ((SELECT `id` FROM `jurisdictions` WHERE `code` = 'US'), 'Centers for Medicare & Medicaid Services', '2026 Medicare Parts A and B premiums and deductibles', 'https://www.cms.gov/newsroom/fact-sheets/2026-medicare-parts-b-premiums-deductibles', 'primary', 1),
  ((SELECT `id` FROM `jurisdictions` WHERE `code` = 'US'), 'Centers for Medicare & Medicaid Services', 'Medicare costs', 'https://www.medicare.gov/basics/costs/medicare-costs', 'primary', 1),
  ((SELECT `id` FROM `jurisdictions` WHERE `code` = 'US'), 'Federal Housing Finance Agency', 'Conforming loan limits', 'https://www.fhfa.gov/data/conforming-loan-limit', 'primary', 1);
