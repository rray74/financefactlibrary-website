-- 027_australia_super_guarantee_source.sql
--
-- Adds the ATO's super guarantee page to the Australian sources, so the daily
-- source check can find the super guarantee rate and the maximum contribution
-- base on it. The page was read on 16 November 2026 with scripts/read-source.php
-- and shows both figures (page last updated 17 April 2026).
--
-- After 026 there are 15 Australian sources, and after this there are 16.
--
-- No semicolons appear inside the comments or text in this file, so it is safe
-- for any runner that splits a migration into statements on semicolons.

INSERT INTO `sources` (`jurisdiction_id`, `publisher`, `title`, `url`, `source_type`, `is_allowlisted`)
VALUES
  ((SELECT `id` FROM `jurisdictions` WHERE `code` = 'AU'), 'Australian Taxation Office', 'Super guarantee rates and maximum contribution base', 'https://www.ato.gov.au/tax-rates-and-codes/key-superannuation-rates-and-thresholds/super-guarantee', 'primary', 1);
