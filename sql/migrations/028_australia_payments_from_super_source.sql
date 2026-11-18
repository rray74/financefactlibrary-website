-- 028_australia_payments_from_super_source.sql
--
-- Adds the ATO's "Payments from super" page to the Australian sources, so the
-- daily source check can find the preservation age table on it. The page was read
-- on 18 November 2026 with scripts/read-source.php (page last updated
-- 16 September 2026).
--
-- After 027 there are 16 Australian sources, and after this there are 17.
--
-- No semicolons appear inside the comments or text in this file, so it is safe
-- for any runner that splits a migration into statements on semicolons.

INSERT INTO `sources` (`jurisdiction_id`, `publisher`, `title`, `url`, `source_type`, `is_allowlisted`)
VALUES
  ((SELECT `id` FROM `jurisdictions` WHERE `code` = 'AU'), 'Australian Taxation Office', 'Payments from super: preservation age', 'https://www.ato.gov.au/tax-rates-and-codes/key-superannuation-rates-and-thresholds/payments-from-super', 'primary', 1);
