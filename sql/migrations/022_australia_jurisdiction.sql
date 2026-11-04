-- 022_australia_jurisdiction.sql
--
-- Adds Australia as a country, hidden, with its eight states and territories
-- as sub-regions. This is the same shape as the United Kingdom and its four
-- nations (migration 001), and it is the first step of building the
-- Australian pilot.
--
-- AUSTRALIA IS HIDDEN. is_active is 0, so the public site does not list it and
-- /au/ does not exist yet. Nothing is shown to visitors until the country is
-- launched, which is a single change: set is_active to 1 on the AU row (no
-- other row needs touching). Until then, pages and figures can be imported
-- and checked in private.
--
-- THE COUNTRY ROW
--   code                   AU (the value used in jurisdiction_code in CSV files)
--   url_prefix             au (public addresses will start /au/)
--   currency_code, locale  AUD, en-AU
--   tax_year_start_*       month 7, day 1. The Australian tax year runs from
--                          1 July to 30 June. The code that works out {tax_year}
--                          and tax-year-end dates reads the same dates from
--                          COUNTRY_TAX_YEARS in includes/placeholders.php, so
--                          the two must agree.
--
-- THE SUB-REGIONS (jurisdiction_code for a figure that applies in one state)
--   Codes follow ISO 3166-2, the same way the UK's are GB-SCT, GB-WLS and so on.
--   A figure for one state sits in Australia's categories and is marked with the
--   state's code. Like the UK's nations, they take no URL prefix, currency or
--   locale of their own, and show as active because the country row alone
--   decides whether anything is public.
--
-- No semicolons appear inside the comments or text in this file, so it is safe
-- for any runner that splits a migration into statements on semicolons.

INSERT INTO `jurisdictions`
  (`parent_id`, `code`, `name`, `url_prefix`, `currency_code`, `locale`, `tax_year_start_month`, `tax_year_start_day`, `is_active`)
VALUES
  (NULL, 'AU', 'Australia', 'au', 'AUD', 'en-AU', 7, 1, 0);

INSERT INTO `jurisdictions` (`parent_id`, `code`, `name`, `is_active`)
SELECT `id`, 'AU-NSW', 'New South Wales', 1 FROM `jurisdictions` WHERE `code` = 'AU'
UNION ALL
SELECT `id`, 'AU-VIC', 'Victoria', 1 FROM `jurisdictions` WHERE `code` = 'AU'
UNION ALL
SELECT `id`, 'AU-QLD', 'Queensland', 1 FROM `jurisdictions` WHERE `code` = 'AU'
UNION ALL
SELECT `id`, 'AU-WA', 'Western Australia', 1 FROM `jurisdictions` WHERE `code` = 'AU'
UNION ALL
SELECT `id`, 'AU-SA', 'South Australia', 1 FROM `jurisdictions` WHERE `code` = 'AU'
UNION ALL
SELECT `id`, 'AU-TAS', 'Tasmania', 1 FROM `jurisdictions` WHERE `code` = 'AU'
UNION ALL
SELECT `id`, 'AU-ACT', 'Australian Capital Territory', 1 FROM `jurisdictions` WHERE `code` = 'AU'
UNION ALL
SELECT `id`, 'AU-NT', 'Northern Territory', 1 FROM `jurisdictions` WHERE `code` = 'AU';
