-- 036_canada_sources.sql
--
-- The official-source allowlist rows for the Canada launch, the same job as migration 033 did for New
-- Zealand. Each row is a page on an official Canadian federal site that the launch pages' figures will
-- cite. Rows are marked allowlisted, which is what lets the daily source check read a page and what
-- the AI proposer's allowlist gate looks for. Run after 035, which creates the Canada country row
-- these rows use.
--
-- ONLY PAGES READ ON 25 NOVEMBER 2026 ARE HERE, each with scripts/read-source.php, and each answered
-- and showed the kind of figure the content map plans to take from it (a table of rates, a limit,
-- a maximum amount or a stated rate). Addresses came from the pages' own links or the sites' own
-- navigation, never from a search. Left out on purpose, because no page that states the figure has been
-- found yet: the basic personal amount, the GST/HST registration threshold, the RRSP dollar limit
-- page, the Home Buyers' Plan, capital gains, mortgage down payment rules, the federal minimum
-- wage and the Canada Groceries and Essentials Benefit amounts. A figure must never be sourced from an
-- unconfirmed page. Add those rows in a later migration as each page is found and read.
--
-- WHEN YOU BUILD A CSV, copy the URL from here exactly into source_url. Fact rows are linked to a
-- source by that URL, and a differently written URL would make a second, non-allowlisted source.
--
-- SOME ADDRESSES NEED CARE. The CRA's federal tax rates page is the current-year page, so a card
-- on it needs a new look each January to see whether the address still carries the current year. The
-- Employment Insurance table already lists a 2027 row.
--
-- publisher is the name shown on a figure's card, so it is the full name of the body. licence is left
-- empty because the reuse terms of each site were not checked. Only figures are taken, never the
-- wording of a page.
--
-- No semicolons appear inside the comments or text in this file, so it is safe for any runner that
-- splits a migration into statements on semicolons.

INSERT INTO `sources` (`jurisdiction_id`, `publisher`, `title`, `url`, `source_type`, `is_allowlisted`)
VALUES
  ((SELECT `id` FROM `jurisdictions` WHERE `code` = 'CA'), 'Canada Revenue Agency', 'Tax rates and income brackets for the current year', 'https://www.canada.ca/en/revenue-agency/services/tax/individuals/tax-rates-brackets/current-year.html', 'primary', 1),
  ((SELECT `id` FROM `jurisdictions` WHERE `code` = 'CA'), 'Canada Revenue Agency', 'Charge and collect GST/HST: which rate', 'https://www.canada.ca/en/revenue-agency/services/tax/businesses/topics/gst-hst-businesses/charge-collect-which-rate.html', 'primary', 1),
  ((SELECT `id` FROM `jurisdictions` WHERE `code` = 'CA'), 'Canada Revenue Agency', 'CPP contribution rates, maximums and exemptions', 'https://www.canada.ca/en/revenue-agency/services/tax/businesses/topics/payroll/payroll-deductions-contributions/canada-pension-plan-cpp/cpp-contribution-rates-maximums-exemptions.html', 'primary', 1),
  ((SELECT `id` FROM `jurisdictions` WHERE `code` = 'CA'), 'Canada Revenue Agency', 'EI premium rates and maximums', 'https://www.canada.ca/en/revenue-agency/services/tax/businesses/topics/payroll/payroll-deductions-contributions/employment-insurance-ei/ei-premium-rates-maximums.html', 'primary', 1),
  ((SELECT `id` FROM `jurisdictions` WHERE `code` = 'CA'), 'Canada Revenue Agency', 'Tax-free savings account (TFSA)', 'https://www.canada.ca/en/revenue-agency/services/tax/individuals/topics/tax-free-savings-account.html', 'primary', 1),
  ((SELECT `id` FROM `jurisdictions` WHERE `code` = 'CA'), 'Canada Revenue Agency', 'MP, DB, RRSP, DPSP, ALDA, TFSA limits, YMPE and the YAMPE', 'https://www.canada.ca/en/revenue-agency/services/tax/registered-plans-administrators/pspa/mp-rrsp-dpsp-tfsa-limits-ympe.html', 'primary', 1),
  ((SELECT `id` FROM `jurisdictions` WHERE `code` = 'CA'), 'Canada Revenue Agency', 'First home savings account (FHSA)', 'https://www.canada.ca/en/revenue-agency/services/tax/individuals/topics/first-home-savings-account.html', 'primary', 1),
  ((SELECT `id` FROM `jurisdictions` WHERE `code` = 'CA'), 'Canada Revenue Agency', 'Contributing to your FHSA', 'https://www.canada.ca/en/revenue-agency/services/tax/individuals/topics/first-home-savings-account/contributing-your-fhsa.html', 'primary', 1),
  ((SELECT `id` FROM `jurisdictions` WHERE `code` = 'CA'), 'Canada Revenue Agency', 'Canada child benefit: how we calculate your CCB', 'https://www.canada.ca/en/revenue-agency/services/child-family-benefits/canada-child-benefit-overview/canada-child-benefit-we-calculate-your-ccb.html', 'primary', 1),
  ((SELECT `id` FROM `jurisdictions` WHERE `code` = 'CA'), 'Canada Revenue Agency', 'Canada Groceries and Essentials Benefit (CGEB)', 'https://www.canada.ca/en/revenue-agency/services/child-family-benefits/canada-groceries-essentials-benefit.html', 'primary', 1),
  ((SELECT `id` FROM `jurisdictions` WHERE `code` = 'CA'), 'Service Canada', 'Old Age Security: payment amounts', 'https://www.canada.ca/en/services/benefits/publicpensions/cpp/old-age-security/payments.html', 'primary', 1),
  ((SELECT `id` FROM `jurisdictions` WHERE `code` = 'CA'), 'Service Canada', 'Old Age Security pension: benefit amount', 'https://www.canada.ca/en/services/benefits/publicpensions/cpp/old-age-security/benefit-amount.html', 'primary', 1),
  ((SELECT `id` FROM `jurisdictions` WHERE `code` = 'CA'), 'Service Canada', 'Canada Pension Plan retirement pension: how much you could receive', 'https://www.canada.ca/en/services/benefits/publicpensions/cpp/cpp-benefit/amount.html', 'primary', 1),
  ((SELECT `id` FROM `jurisdictions` WHERE `code` = 'CA'), 'Service Canada', 'Employment Insurance regular benefits: how much you could receive', 'https://www.canada.ca/en/services/benefits/ei/ei-regular-benefit/benefit-amount.html', 'primary', 1),
  ((SELECT `id` FROM `jurisdictions` WHERE `code` = 'CA'), 'Bank of Canada', 'Policy interest rate', 'https://www.bankofcanada.ca/core-functions/monetary-policy/key-interest-rate/', 'primary', 1),
  ((SELECT `id` FROM `jurisdictions` WHERE `code` = 'CA'), 'Canada Deposit Insurance Corporation', 'What is covered', 'https://www.cdic.ca/depositors/whats-covered/', 'primary', 1);
