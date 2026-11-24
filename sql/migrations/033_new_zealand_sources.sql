-- 033_new_zealand_sources.sql
--
-- The official-source allowlist rows for the New Zealand launch, the same job as migration 023
-- did for Australia. Each row is a page on an official New Zealand Government site that the launch
-- pages' figures will cite. Rows are marked allowlisted, which is what lets the daily source check
-- read a page and what the AI proposer's allowlist gate looks for. Run after 032, which creates the
-- New Zealand country row these rows use.
--
-- ONLY PAGES READ ON 23 NOVEMBER 2026 ARE HERE, each with scripts/read-source.php (the address came
-- from the site's own sitemap or links, never from a search). Every one answered and showed the kind
-- of figure the content map plans to take from it. Left out on purpose, because no readable official
-- page was found: the Reserve Bank of New Zealand pages (Cloudflare refuses the fetcher from the Mac,
-- the UK VPN, the New Zealand VPN and the server), the Kainga Ora First Home Loan pages and ACC (a
-- JavaScript challenge), and the page that states the paid parental leave payment. A figure must
-- never be sourced from an unconfirmed page. docs/content-maps/nz-content-map.md has the list.
--
-- WHEN YOU BUILD A CSV, copy the URL from here exactly into source_url. Fact rows are linked to a
-- source by that URL, and a differently written URL would make a second, non-allowlisted source.
--
-- ONE ADDRESS CHANGES EVERY APRIL. Work and Income puts the year in the benefit rates address
-- (benefit-rates-april-2026), so the next April needs a new row and a new source_url on the card, or the
-- daily check reports the figure as not found.
--
-- publisher is the name shown on a figure's card, so it is the full name of the body. licence is left
-- empty because the reuse terms of each site were not checked. Only figures are taken, never the
-- wording of a page.
--
-- No semicolons appear inside the comments or text in this file, so it is safe for any runner that
-- splits a migration into statements on semicolons.

INSERT INTO `sources` (`jurisdiction_id`, `publisher`, `title`, `url`, `source_type`, `is_allowlisted`)
VALUES
  ((SELECT `id` FROM `jurisdictions` WHERE `code` = 'NZ'), 'Inland Revenue', 'Tax rates for individuals', 'https://www.ird.govt.nz/income-tax/income-tax-for-individuals/tax-codes-and-tax-rates-for-individuals/tax-rates-for-individuals', 'primary', 1),
  ((SELECT `id` FROM `jurisdictions` WHERE `code` = 'NZ'), 'Inland Revenue', 'ACC earners'' levy rates', 'https://www.ird.govt.nz/income-tax/income-tax-for-individuals/acc-clients-and-carers/acc-earners-levy-rates', 'primary', 1),
  ((SELECT `id` FROM `jurisdictions` WHERE `code` = 'NZ'), 'Inland Revenue', 'Registering for GST', 'https://www.ird.govt.nz/gst/registering-for-gst', 'primary', 1),
  ((SELECT `id` FROM `jurisdictions` WHERE `code` = 'NZ'), 'Inland Revenue', 'Charging GST', 'https://www.ird.govt.nz/gst/charging-gst', 'primary', 1),
  ((SELECT `id` FROM `jurisdictions` WHERE `code` = 'NZ'), 'Inland Revenue', 'The bright-line test', 'https://www.ird.govt.nz/property/buying-and-selling/when-you-need-to-pay/the-brightline-test', 'primary', 1),
  ((SELECT `id` FROM `jurisdictions` WHERE `code` = 'NZ'), 'Inland Revenue', 'The bright-line test: start and end dates', 'https://www.ird.govt.nz/property/buying-and-selling/when-you-need-to-pay/the-brightline-test/start-and-end-dates', 'primary', 1),
  ((SELECT `id` FROM `jurisdictions` WHERE `code` = 'NZ'), 'Inland Revenue', 'Repaying my student loan when I earn salary or wages', 'https://www.ird.govt.nz/student-loans/living-in-new-zealand-with-a-student-loan/repaying-my-student-loan-when-i-earn-salary-or-wages', 'primary', 1),
  ((SELECT `id` FROM `jurisdictions` WHERE `code` = 'NZ'), 'Inland Revenue', 'Multi-rate PIEs and prescribed investor rates', 'https://www.ird.govt.nz/roles/portfolio-investment-entities/multi-rate-pies-and-prescribed-investor-rates', 'primary', 1),
  ((SELECT `id` FROM `jurisdictions` WHERE `code` = 'NZ'), 'Inland Revenue', 'KiwiSaver: employee contributions', 'https://www.ird.govt.nz/kiwisaver/kiwisaver-individuals/employee-contributions', 'primary', 1),
  ((SELECT `id` FROM `jurisdictions` WHERE `code` = 'NZ'), 'Inland Revenue', 'KiwiSaver: employer contributions', 'https://www.ird.govt.nz/kiwisaver/kiwisaver-employers/contributions-and-deductions/employer-contributions-to-kiwisaver-and-complying-funds', 'primary', 1),
  ((SELECT `id` FROM `jurisdictions` WHERE `code` = 'NZ'), 'Inland Revenue', 'Getting the KiwiSaver government contribution', 'https://www.ird.govt.nz/kiwisaver/kiwisaver-individuals/growing-my-kiwisaver-account/getting-the-kiwisaver-government-contribution', 'primary', 1),
  ((SELECT `id` FROM `jurisdictions` WHERE `code` = 'NZ'), 'Inland Revenue', 'Getting my KiwiSaver for my first home', 'https://www.ird.govt.nz/kiwisaver/kiwisaver-individuals/getting-my-kiwisaver-funds-early/getting-my-kiwisaver-for-my-first-home', 'primary', 1),
  ((SELECT `id` FROM `jurisdictions` WHERE `code` = 'NZ'), 'Inland Revenue', 'Working for Families: Family Tax Credit', 'https://www.ird.govt.nz/working-for-families/types/family-tax-credit', 'primary', 1),
  ((SELECT `id` FROM `jurisdictions` WHERE `code` = 'NZ'), 'Inland Revenue', 'Working for Families: In-work Tax Credit', 'https://www.ird.govt.nz/working-for-families/types/in-work-tax-credit', 'primary', 1),
  ((SELECT `id` FROM `jurisdictions` WHERE `code` = 'NZ'), 'Inland Revenue', 'Working for Families: Best Start', 'https://www.ird.govt.nz/working-for-families/types/best-start', 'primary', 1),
  ((SELECT `id` FROM `jurisdictions` WHERE `code` = 'NZ'), 'Inland Revenue', 'Working for Families: Minimum Family Tax Credit', 'https://www.ird.govt.nz/working-for-families/types/minimum-family-tax-credit', 'primary', 1),
  ((SELECT `id` FROM `jurisdictions` WHERE `code` = 'NZ'), 'Employment New Zealand', 'Minimum wage rates and types', 'https://www.employment.govt.nz/pay-and-hours/pay-and-wages/minimum-wage/minimum-wage-rates-and-types', 'primary', 1),
  ((SELECT `id` FROM `jurisdictions` WHERE `code` = 'NZ'), 'Employment New Zealand', 'Annual holidays', 'https://www.employment.govt.nz/leave-and-holidays/annual-holidays', 'primary', 1),
  ((SELECT `id` FROM `jurisdictions` WHERE `code` = 'NZ'), 'Employment New Zealand', 'Sick leave', 'https://www.employment.govt.nz/leave-and-holidays/sick-leave', 'primary', 1),
  ((SELECT `id` FROM `jurisdictions` WHERE `code` = 'NZ'), 'Work and Income', 'New Zealand Superannuation', 'https://www.workandincome.govt.nz/eligibility/seniors/superannuation', 'primary', 1),
  ((SELECT `id` FROM `jurisdictions` WHERE `code` = 'NZ'), 'Work and Income', 'New Zealand Superannuation: who can get it', 'https://www.workandincome.govt.nz/eligibility/seniors/superannuation/who-can-get-it', 'primary', 1),
  ((SELECT `id` FROM `jurisdictions` WHERE `code` = 'NZ'), 'Work and Income', 'New Zealand Superannuation: how much you can get', 'https://www.workandincome.govt.nz/eligibility/seniors/superannuation/how-much-you-can-get', 'primary', 1),
  ((SELECT `id` FROM `jurisdictions` WHERE `code` = 'NZ'), 'Work and Income', 'Jobseeker Support', 'https://www.workandincome.govt.nz/products/a-z-benefits/jobseeker-support.html', 'primary', 1),
  ((SELECT `id` FROM `jurisdictions` WHERE `code` = 'NZ'), 'Work and Income', 'Benefit rates at 1 April 2026', 'https://www.workandincome.govt.nz/products/benefit-rates/benefit-rates-april-2026', 'primary', 1),
  ((SELECT `id` FROM `jurisdictions` WHERE `code` = 'NZ'), 'Tenancy Services', 'Charging a general bond', 'https://www.tenancy.govt.nz/rent-bond-and-bills/bond/charging-a-bond/charging-a-general-bond/', 'primary', 1),
  ((SELECT `id` FROM `jurisdictions` WHERE `code` = 'NZ'), 'Tenancy Services', 'Increasing rent', 'https://www.tenancy.govt.nz/rent-bond-and-bills/rent/increasing-rent/', 'primary', 1);
