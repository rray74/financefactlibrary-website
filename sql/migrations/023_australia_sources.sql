-- 023_australia_sources.sql
--
-- The official-source allowlist for the Australian pilot. Each row is a page
-- on an official Australian Government site that the pilot's figures will
-- cite. Rows are marked allowlisted, which is what lets the weekly source
-- check read a page and what the AI proposer's allowlist gate looks for.
--
-- ONLY PAGES CHECKED ON 4 NOVEMBER 2026 ARE HERE. Each URL below came back
-- from a search as a live page on its agency's own site, and each page states
-- the kind of figure the content map plans to take from it. A page the map
-- needs but that was not confirmed is left out on purpose and is listed in
-- docs/content-maps/au.md under "To confirm". A figure must never be sourced
-- from an unconfirmed page.
--
-- WHEN YOU BUILD A CSV, copy the URL from here exactly into source_url. Fact
-- rows are linked to a source by that URL, and a differently written URL
-- would make a second, non-allowlisted source for the same page.
--
-- TWO ATO PAGES ARE ONE LONG DOCUMENT SPLIT INTO NUMBERED PARTS. The contribution
-- caps are in part 2 of the key super rates page and the preservation age
-- table is in part 4, so the address ends in ?page=2 or ?page=4. That is how
-- the ATO serves them. If the ATO reorganises, the check will report the
-- figure as not found and the address needs updating.
--
-- publisher is the name shown on a figure's card, so it is the full name of
-- the body (see au.md). licence is left empty because the reuse terms of each
-- site were not checked. Only figures are taken, never the wording of a page.
--
-- No semicolons appear inside the comments or text in this file, so it is
-- safe for any runner that splits a migration into statements on semicolons.
-- Run after 022, which creates the Australian country row these rows use.

INSERT INTO `sources` (`jurisdiction_id`, `publisher`, `title`, `url`, `source_type`, `is_allowlisted`)
VALUES
  ((SELECT `id` FROM `jurisdictions` WHERE `code` = 'AU'), 'Australian Taxation Office', 'Tax rates: Australian resident', 'https://www.ato.gov.au/rates/individual-income-tax-rates/', 'primary', 1),
  ((SELECT `id` FROM `jurisdictions` WHERE `code` = 'AU'), 'Australian Taxation Office', 'How to claim the tax-free threshold', 'https://www.ato.gov.au/individuals-and-families/jobs-and-employment-types/tax-free-threshold/how-to-claim-the-tax-free-threshold', 'primary', 1),
  ((SELECT `id` FROM `jurisdictions` WHERE `code` = 'AU'), 'Australian Taxation Office', 'What is the Medicare levy?', 'https://www.ato.gov.au/individuals-and-families/medicare-and-private-health-insurance/medicare-levy/what-is-the-medicare-levy', 'primary', 1),
  ((SELECT `id` FROM `jurisdictions` WHERE `code` = 'AU'), 'Australian Taxation Office', 'Registering for GST', 'https://www.ato.gov.au/businesses-and-organisations/gst-excise-and-indirect-taxes/gst/registering-for-gst', 'primary', 1),
  ((SELECT `id` FROM `jurisdictions` WHERE `code` = 'AU'), 'Australian Taxation Office', 'CGT discount', 'https://www.ato.gov.au/Individuals/Capital-gains-tax/CGT-discount/', 'primary', 1),
  ((SELECT `id` FROM `jurisdictions` WHERE `code` = 'AU'), 'Australian Taxation Office', 'Key super rates and thresholds: contributions caps', 'https://www.ato.gov.au/Rates/key-superannuation-rates-and-thresholds/?page=2', 'primary', 1),
  ((SELECT `id` FROM `jurisdictions` WHERE `code` = 'AU'), 'Australian Taxation Office', 'Key super rates and thresholds: preservation age', 'https://www.ato.gov.au/rates/key-superannuation-rates-and-thresholds/?page=4', 'primary', 1),
  ((SELECT `id` FROM `jurisdictions` WHERE `code` = 'AU'), 'Australian Taxation Office', 'About FHSS release amounts', 'https://www.ato.gov.au/individuals-and-families/super-for-individuals-and-families/super/withdrawing-and-using-your-super/early-access-to-super/first-home-super-saver-scheme/about-fhss-release-amounts', 'primary', 1),
  ((SELECT `id` FROM `jurisdictions` WHERE `code` = 'AU'), 'Reserve Bank of Australia', 'Cash rate target', 'https://www.rba.gov.au/cash-rate-target-overview.html', 'primary', 1),
  ((SELECT `id` FROM `jurisdictions` WHERE `code` = 'AU'), 'Services Australia', 'Age Pension: how much you can get', 'https://www.servicesaustralia.gov.au/how-much-age-pension-you-can-get', 'primary', 1),
  ((SELECT `id` FROM `jurisdictions` WHERE `code` = 'AU'), 'Services Australia', 'JobSeeker Payment', 'https://www.servicesaustralia.gov.au/jobseeker-payment', 'primary', 1),
  ((SELECT `id` FROM `jurisdictions` WHERE `code` = 'AU'), 'Services Australia', 'Parental Leave Pay: how much you can get', 'https://www.servicesaustralia.gov.au/how-much-parental-leave-pay-you-can-get', 'primary', 1),
  ((SELECT `id` FROM `jurisdictions` WHERE `code` = 'AU'), 'Fair Work Ombudsman', 'Minimum wages', 'https://www.fairwork.gov.au/minimum-wages', 'primary', 1),
  ((SELECT `id` FROM `jurisdictions` WHERE `code` = 'AU'), 'Fair Work Commission', 'National Employment Standards', 'https://fwc.gov.au/agreements-awards/minimum-wages-and-conditions/national-employment-standards', 'primary', 1),
  ((SELECT `id` FROM `jurisdictions` WHERE `code` = 'AU'), 'Housing Australia', 'First Home Guarantee FAQs', 'https://www.housingaustralia.gov.au/first-home-guarantee-faqs', 'primary', 1),
  ((SELECT `id` FROM `jurisdictions` WHERE `code` = 'AU'), 'Housing Australia', 'Home Guarantee Scheme: property types and price caps', 'https://www.housingaustralia.gov.au/node/5990', 'primary', 1);
