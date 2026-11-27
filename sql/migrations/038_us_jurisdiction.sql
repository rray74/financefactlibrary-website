-- 038_us_jurisdiction.sql
--
-- Adds the United States as a country, hidden, with its 50 states and the District of Columbia as sub-regions.
-- It has the same shape as Canada (migration 035) and is the first step of the US launch (plan:
-- docs/content-maps/us-content-map.md, decision A, approved 27 November 2026).
--
-- THE UNITED STATES IS HIDDEN. is_active is 0, so the public site does not list it and /us/ does not exist yet.
-- Launch is a single change: set is_active to 1 on the US row (a later launch migration does that, as 037 did for Canada).
--
-- THE COUNTRY ROW
--   code                   US (the value used in jurisdiction_code in CSV files)
--   url_prefix             us (public addresses will start /us/)
--   currency_code, locale  USD, en-US
--   tax_year_start_*       month 1, day 1. The US tax year for individuals is the calendar year. The code that
--                          works out {tax_year} reads the same dates from COUNTRY_TAX_YEARS in
--                          includes/placeholders.php (already 1 and 1, single style), so the two agree.
--
-- THE SUB-REGIONS (jurisdiction_code for a figure that applies in one state or in DC)
--   Codes follow ISO 3166-2 (US-CA and so on), as the UK's GB-SCT and Canada's CA-ON do. States set their own
--   income tax, minimum wage, leave rules, sales tax and transfer taxes, so a figure can be marked with its own
--   region. Like the UK nations and the Canadian provinces they take no URL prefix, currency or locale of their
--   own, and show as active because the country row alone decides whether anything is public. The territories
--   (Puerto Rico, Guam, the US Virgin Islands, American Samoa and the Northern Mariana Islands) have different
--   tax and benefit rules and are deliberately not given rows.
--
-- No semicolons appear inside the comments or text in this file, so it is safe for any runner
-- that splits a migration into statements on semicolons.

INSERT INTO `jurisdictions`
  (`parent_id`, `code`, `name`, `url_prefix`, `currency_code`, `locale`, `tax_year_start_month`, `tax_year_start_day`, `is_active`)
VALUES
  (NULL, 'US', 'United States', 'us', 'USD', 'en-US', 1, 1, 0);

INSERT INTO `jurisdictions` (`parent_id`, `code`, `name`, `is_active`)
SELECT `id`, 'US-AL', 'Alabama', 1 FROM `jurisdictions` WHERE `code` = 'US'
UNION ALL
SELECT `id`, 'US-AK', 'Alaska', 1 FROM `jurisdictions` WHERE `code` = 'US'
UNION ALL
SELECT `id`, 'US-AZ', 'Arizona', 1 FROM `jurisdictions` WHERE `code` = 'US'
UNION ALL
SELECT `id`, 'US-AR', 'Arkansas', 1 FROM `jurisdictions` WHERE `code` = 'US'
UNION ALL
SELECT `id`, 'US-CA', 'California', 1 FROM `jurisdictions` WHERE `code` = 'US'
UNION ALL
SELECT `id`, 'US-CO', 'Colorado', 1 FROM `jurisdictions` WHERE `code` = 'US'
UNION ALL
SELECT `id`, 'US-CT', 'Connecticut', 1 FROM `jurisdictions` WHERE `code` = 'US'
UNION ALL
SELECT `id`, 'US-DE', 'Delaware', 1 FROM `jurisdictions` WHERE `code` = 'US'
UNION ALL
SELECT `id`, 'US-DC', 'District of Columbia', 1 FROM `jurisdictions` WHERE `code` = 'US'
UNION ALL
SELECT `id`, 'US-FL', 'Florida', 1 FROM `jurisdictions` WHERE `code` = 'US'
UNION ALL
SELECT `id`, 'US-GA', 'Georgia', 1 FROM `jurisdictions` WHERE `code` = 'US'
UNION ALL
SELECT `id`, 'US-HI', 'Hawaii', 1 FROM `jurisdictions` WHERE `code` = 'US'
UNION ALL
SELECT `id`, 'US-ID', 'Idaho', 1 FROM `jurisdictions` WHERE `code` = 'US'
UNION ALL
SELECT `id`, 'US-IL', 'Illinois', 1 FROM `jurisdictions` WHERE `code` = 'US'
UNION ALL
SELECT `id`, 'US-IN', 'Indiana', 1 FROM `jurisdictions` WHERE `code` = 'US'
UNION ALL
SELECT `id`, 'US-IA', 'Iowa', 1 FROM `jurisdictions` WHERE `code` = 'US'
UNION ALL
SELECT `id`, 'US-KS', 'Kansas', 1 FROM `jurisdictions` WHERE `code` = 'US'
UNION ALL
SELECT `id`, 'US-KY', 'Kentucky', 1 FROM `jurisdictions` WHERE `code` = 'US'
UNION ALL
SELECT `id`, 'US-LA', 'Louisiana', 1 FROM `jurisdictions` WHERE `code` = 'US'
UNION ALL
SELECT `id`, 'US-ME', 'Maine', 1 FROM `jurisdictions` WHERE `code` = 'US'
UNION ALL
SELECT `id`, 'US-MD', 'Maryland', 1 FROM `jurisdictions` WHERE `code` = 'US'
UNION ALL
SELECT `id`, 'US-MA', 'Massachusetts', 1 FROM `jurisdictions` WHERE `code` = 'US'
UNION ALL
SELECT `id`, 'US-MI', 'Michigan', 1 FROM `jurisdictions` WHERE `code` = 'US'
UNION ALL
SELECT `id`, 'US-MN', 'Minnesota', 1 FROM `jurisdictions` WHERE `code` = 'US'
UNION ALL
SELECT `id`, 'US-MS', 'Mississippi', 1 FROM `jurisdictions` WHERE `code` = 'US'
UNION ALL
SELECT `id`, 'US-MO', 'Missouri', 1 FROM `jurisdictions` WHERE `code` = 'US'
UNION ALL
SELECT `id`, 'US-MT', 'Montana', 1 FROM `jurisdictions` WHERE `code` = 'US'
UNION ALL
SELECT `id`, 'US-NE', 'Nebraska', 1 FROM `jurisdictions` WHERE `code` = 'US'
UNION ALL
SELECT `id`, 'US-NV', 'Nevada', 1 FROM `jurisdictions` WHERE `code` = 'US'
UNION ALL
SELECT `id`, 'US-NH', 'New Hampshire', 1 FROM `jurisdictions` WHERE `code` = 'US'
UNION ALL
SELECT `id`, 'US-NJ', 'New Jersey', 1 FROM `jurisdictions` WHERE `code` = 'US'
UNION ALL
SELECT `id`, 'US-NM', 'New Mexico', 1 FROM `jurisdictions` WHERE `code` = 'US'
UNION ALL
SELECT `id`, 'US-NY', 'New York', 1 FROM `jurisdictions` WHERE `code` = 'US'
UNION ALL
SELECT `id`, 'US-NC', 'North Carolina', 1 FROM `jurisdictions` WHERE `code` = 'US'
UNION ALL
SELECT `id`, 'US-ND', 'North Dakota', 1 FROM `jurisdictions` WHERE `code` = 'US'
UNION ALL
SELECT `id`, 'US-OH', 'Ohio', 1 FROM `jurisdictions` WHERE `code` = 'US'
UNION ALL
SELECT `id`, 'US-OK', 'Oklahoma', 1 FROM `jurisdictions` WHERE `code` = 'US'
UNION ALL
SELECT `id`, 'US-OR', 'Oregon', 1 FROM `jurisdictions` WHERE `code` = 'US'
UNION ALL
SELECT `id`, 'US-PA', 'Pennsylvania', 1 FROM `jurisdictions` WHERE `code` = 'US'
UNION ALL
SELECT `id`, 'US-RI', 'Rhode Island', 1 FROM `jurisdictions` WHERE `code` = 'US'
UNION ALL
SELECT `id`, 'US-SC', 'South Carolina', 1 FROM `jurisdictions` WHERE `code` = 'US'
UNION ALL
SELECT `id`, 'US-SD', 'South Dakota', 1 FROM `jurisdictions` WHERE `code` = 'US'
UNION ALL
SELECT `id`, 'US-TN', 'Tennessee', 1 FROM `jurisdictions` WHERE `code` = 'US'
UNION ALL
SELECT `id`, 'US-TX', 'Texas', 1 FROM `jurisdictions` WHERE `code` = 'US'
UNION ALL
SELECT `id`, 'US-UT', 'Utah', 1 FROM `jurisdictions` WHERE `code` = 'US'
UNION ALL
SELECT `id`, 'US-VT', 'Vermont', 1 FROM `jurisdictions` WHERE `code` = 'US'
UNION ALL
SELECT `id`, 'US-VA', 'Virginia', 1 FROM `jurisdictions` WHERE `code` = 'US'
UNION ALL
SELECT `id`, 'US-WA', 'Washington', 1 FROM `jurisdictions` WHERE `code` = 'US'
UNION ALL
SELECT `id`, 'US-WV', 'West Virginia', 1 FROM `jurisdictions` WHERE `code` = 'US'
UNION ALL
SELECT `id`, 'US-WI', 'Wisconsin', 1 FROM `jurisdictions` WHERE `code` = 'US'
UNION ALL
SELECT `id`, 'US-WY', 'Wyoming', 1 FROM `jurisdictions` WHERE `code` = 'US';
