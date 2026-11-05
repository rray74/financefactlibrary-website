-- 025_australia_readable_sources.sql
--
-- Swaps two Australian sources that this site cannot read for two that it can.
-- A source the fetcher cannot download is no use to the writer, the AI check or
-- the weekly source check, so it should not be in the allowlist.
--
-- Found with scripts/check-source-access.php, run on 5 November 2026 both on a
-- Mac and on the Hostinger server. Every address below gave the same answer on
-- both machines.
--
-- REMOVED
--   Fair Work Commission, National Employment Standards. Its site sits behind a
--   JavaScript challenge ("Azure WAF: please enable JavaScript"), which no header
--   can get past. It was refused on every variant that was tried.
--   Housing Australia, First Home Guarantee FAQs. Refused on every variant on the
--   Mac (an "Access denied" page). It was not tried on the server, so it can be
--   added again if it turns out to be readable there.
--
-- ADDED (both read in full, and neither is on a site that refused)
--   Fair Work Ombudsman, Introduction to the National Employment Standards. The
--   Ombudsman's own fact sheet carries the same leave entitlements as the
--   Commission's page, so the annual leave and personal leave pages can use it.
--   Needs browser headers, which includes/source-pages.php now sends after a first
--   attempt fails.
--   Housing Australia, Property price caps. The page with the table of caps for
--   every state and territory, which the First Home Guarantee page is built on.
--   Read with no extra headers. The page changes when the caps change, so a figure
--   must be read from the live page and never from a copy or a search snippet.
--
-- No row has a figure attached yet, so nothing that cites these is affected.
-- After 022 to 024 there are 14 Australian sources, and after this there are
-- still 14.
--
-- No semicolons appear inside the comments or text in this file, so it is safe
-- for any runner that splits a migration into statements on semicolons.

DELETE FROM `sources`
WHERE `url` IN (
  'https://fwc.gov.au/agreements-awards/minimum-wages-and-conditions/national-employment-standards',
  'https://www.housingaustralia.gov.au/first-home-guarantee-faqs'
);

INSERT INTO `sources` (`jurisdiction_id`, `publisher`, `title`, `url`, `source_type`, `is_allowlisted`)
VALUES
  ((SELECT `id` FROM `jurisdictions` WHERE `code` = 'AU'), 'Fair Work Ombudsman', 'Introduction to the National Employment Standards', 'https://www.fairwork.gov.au/tools-and-resources/fact-sheets/minimum-workplace-entitlements/introduction-to-the-national-employment-standards', 'primary', 1),
  ((SELECT `id` FROM `jurisdictions` WHERE `code` = 'AU'), 'Housing Australia', 'Property price caps', 'https://www.housingaustralia.gov.au/support-buy-home/property-price-caps', 'primary', 1);
