-- 035_canada_jurisdiction.sql
--
-- Adds Canada as a country, hidden, with its ten provinces and three territories as sub-regions. It has
-- the same shape as New Zealand (migration 032) and Australia (migration 022), and it is the first step
-- of the Canada launch (plan: docs/content-maps/ca-content-map.md, decision A, approved 25 November 2026).
--
-- CANADA IS HIDDEN. is_active is 0, so the public site does not list it and /ca/ does not exist yet.
-- Nothing is shown to visitors until the country is launched, which is a single change: set is_active
-- to 1 on the CA row (a later launch migration does that, as 034 did for New Zealand).
--
-- THE COUNTRY ROW
--   code                   CA (the value used in jurisdiction_code in CSV files)
--   url_prefix             ca (public addresses will start /ca/)
--   currency_code, locale  CAD, en-CA
--   tax_year_start_*       month 1, day 1. The Canadian tax year is the calendar year. The code that
--                          works out {tax_year} reads the same dates from COUNTRY_TAX_YEARS in
--                          includes/placeholders.php (already 1 and 1, written 2026), so the two agree.
--
-- THE SUB-REGIONS (jurisdiction_code for a figure that applies in one province or territory)
--   Codes follow ISO 3166-2 (CA-ON and so on), as the UK's GB-SCT and Australia's AU-NSW do. Provinces
--   and territories set their own income tax, minimum wage, leave rules and land transfer taxes, so a
--   figure can be marked with its own region. Like the UK's nations and Australia's states they take no
--   URL prefix, currency or locale of their own, and show as active because the country row alone
--   decides whether anything is public. Quebec (CA-QC) runs its own pension and parental plans, so
--   pages on the Canada Pension Plan and Employment Insurance must say where Quebec differs.
--
-- No semicolons appear inside the comments or text in this file, so it is safe for any runner
-- that splits a migration into statements on semicolons.

INSERT INTO `jurisdictions`
  (`parent_id`, `code`, `name`, `url_prefix`, `currency_code`, `locale`, `tax_year_start_month`, `tax_year_start_day`, `is_active`)
VALUES
  (NULL, 'CA', 'Canada', 'ca', 'CAD', 'en-CA', 1, 1, 0);

INSERT INTO `jurisdictions` (`parent_id`, `code`, `name`, `is_active`)
SELECT `id`, 'CA-AB', 'Alberta', 1 FROM `jurisdictions` WHERE `code` = 'CA'
UNION ALL
SELECT `id`, 'CA-BC', 'British Columbia', 1 FROM `jurisdictions` WHERE `code` = 'CA'
UNION ALL
SELECT `id`, 'CA-MB', 'Manitoba', 1 FROM `jurisdictions` WHERE `code` = 'CA'
UNION ALL
SELECT `id`, 'CA-NB', 'New Brunswick', 1 FROM `jurisdictions` WHERE `code` = 'CA'
UNION ALL
SELECT `id`, 'CA-NL', 'Newfoundland and Labrador', 1 FROM `jurisdictions` WHERE `code` = 'CA'
UNION ALL
SELECT `id`, 'CA-NS', 'Nova Scotia', 1 FROM `jurisdictions` WHERE `code` = 'CA'
UNION ALL
SELECT `id`, 'CA-NT', 'Northwest Territories', 1 FROM `jurisdictions` WHERE `code` = 'CA'
UNION ALL
SELECT `id`, 'CA-NU', 'Nunavut', 1 FROM `jurisdictions` WHERE `code` = 'CA'
UNION ALL
SELECT `id`, 'CA-ON', 'Ontario', 1 FROM `jurisdictions` WHERE `code` = 'CA'
UNION ALL
SELECT `id`, 'CA-PE', 'Prince Edward Island', 1 FROM `jurisdictions` WHERE `code` = 'CA'
UNION ALL
SELECT `id`, 'CA-QC', 'Quebec', 1 FROM `jurisdictions` WHERE `code` = 'CA'
UNION ALL
SELECT `id`, 'CA-SK', 'Saskatchewan', 1 FROM `jurisdictions` WHERE `code` = 'CA'
UNION ALL
SELECT `id`, 'CA-YT', 'Yukon', 1 FROM `jurisdictions` WHERE `code` = 'CA';
