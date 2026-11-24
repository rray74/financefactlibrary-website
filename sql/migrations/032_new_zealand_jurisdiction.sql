-- 032_new_zealand_jurisdiction.sql
--
-- Adds New Zealand as a country, hidden, with its sixteen regions as sub-regions. It has the same
-- shape as Australia and its states (migration 022), and it is the first step of the New Zealand
-- launch (plan: docs/content-maps/nz-content-map.md).
--
-- NEW ZEALAND IS HIDDEN. is_active is 0, so the public site does not list it and /nz/ does not
-- exist yet. Nothing is shown to visitors until the country is launched, which is a single change:
-- set is_active to 1 on the NZ row (no other row needs touching). Until then, pages and figures
-- can be imported and checked in private.
--
-- THE COUNTRY ROW
--   code                   NZ (the value used in jurisdiction_code in CSV files)
--   url_prefix             nz (public addresses will start /nz/)
--   currency_code, locale  NZD, en-NZ
--   tax_year_start_*       month 4, day 1. The New Zealand tax year runs from 1 April to
--                          31 March. The code that works out {tax_year} and tax-year-end dates
--                          reads the same dates from COUNTRY_TAX_YEARS in includes/placeholders.php
--                          (already 4 and 1, written 2026–27 with an en dash), so the two must agree.
--
-- THE SUB-REGIONS (jurisdiction_code for a figure that applies in one region)
--   Codes follow ISO 3166-2, as the UK's GB-SCT and Australia's AU-NSW do. Richard decided on
--   23 November 2026 to add them now, so that a figure such as a regional price cap can be marked
--   with its region once the source table has been read. Like the UK's nations and Australia's
--   states they take no URL prefix, currency or locale of their own, and show as active because
--   the country row alone decides whether anything is public. Four of them (Gisborne, Tasman,
--   Nelson and Marlborough) are unitary authorities rather than regional councils, and are kept as
--   regions here because ISO lists them as regions.
--
-- No semicolons appear inside the comments or text in this file, so it is safe for any runner
-- that splits a migration into statements on semicolons.

INSERT INTO `jurisdictions`
  (`parent_id`, `code`, `name`, `url_prefix`, `currency_code`, `locale`, `tax_year_start_month`, `tax_year_start_day`, `is_active`)
VALUES
  (NULL, 'NZ', 'New Zealand', 'nz', 'NZD', 'en-NZ', 4, 1, 0);

INSERT INTO `jurisdictions` (`parent_id`, `code`, `name`, `is_active`)
SELECT `id`, 'NZ-NTL', 'Northland', 1 FROM `jurisdictions` WHERE `code` = 'NZ'
UNION ALL
SELECT `id`, 'NZ-AUK', 'Auckland', 1 FROM `jurisdictions` WHERE `code` = 'NZ'
UNION ALL
SELECT `id`, 'NZ-WKO', 'Waikato', 1 FROM `jurisdictions` WHERE `code` = 'NZ'
UNION ALL
SELECT `id`, 'NZ-BOP', 'Bay of Plenty', 1 FROM `jurisdictions` WHERE `code` = 'NZ'
UNION ALL
SELECT `id`, 'NZ-GIS', 'Gisborne', 1 FROM `jurisdictions` WHERE `code` = 'NZ'
UNION ALL
SELECT `id`, 'NZ-HKB', 'Hawke''s Bay', 1 FROM `jurisdictions` WHERE `code` = 'NZ'
UNION ALL
SELECT `id`, 'NZ-TKI', 'Taranaki', 1 FROM `jurisdictions` WHERE `code` = 'NZ'
UNION ALL
SELECT `id`, 'NZ-MWT', 'Manawatū-Whanganui', 1 FROM `jurisdictions` WHERE `code` = 'NZ'
UNION ALL
SELECT `id`, 'NZ-WGN', 'Wellington', 1 FROM `jurisdictions` WHERE `code` = 'NZ'
UNION ALL
SELECT `id`, 'NZ-TAS', 'Tasman', 1 FROM `jurisdictions` WHERE `code` = 'NZ'
UNION ALL
SELECT `id`, 'NZ-NSN', 'Nelson', 1 FROM `jurisdictions` WHERE `code` = 'NZ'
UNION ALL
SELECT `id`, 'NZ-MBH', 'Marlborough', 1 FROM `jurisdictions` WHERE `code` = 'NZ'
UNION ALL
SELECT `id`, 'NZ-WTC', 'West Coast', 1 FROM `jurisdictions` WHERE `code` = 'NZ'
UNION ALL
SELECT `id`, 'NZ-CAN', 'Canterbury', 1 FROM `jurisdictions` WHERE `code` = 'NZ'
UNION ALL
SELECT `id`, 'NZ-OTA', 'Otago', 1 FROM `jurisdictions` WHERE `code` = 'NZ'
UNION ALL
SELECT `id`, 'NZ-STL', 'Southland', 1 FROM `jurisdictions` WHERE `code` = 'NZ';
