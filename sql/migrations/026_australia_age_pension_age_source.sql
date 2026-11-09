-- 026_australia_age_pension_age_source.sql
--
-- Adds one source to the Australian allowlist: Services Australia's "Who can get
-- it" page for the Age Pension. It states the Age Pension age, which is not on
-- the rates page (how-much-age-pension-you-can-get), so the Age Pension page
-- needs both. The address is the page's own, without the ?context= suffix that
-- some links add.
--
-- BEFORE A FIGURE IS TAKEN FROM IT, read the page live on your own machine with
--   php scripts/read-source.php --url=https://www.servicesaustralia.gov.au/who-can-get-age-pension --find="Age Pension age is"
-- and check it with scripts/check-source-access.php. The address was seen in a
-- search result on 9 November 2026, which showed the page stating the age, but a
-- search result is not a live read. The Services Australia site needs browser
-- headers, which includes/source-pages.php now sends after a first refusal.
--
-- After 022 to 025 there are 14 Australian sources, and after this there are 15.
--
-- No semicolons appear inside the comments or text in this file, so it is safe
-- for any runner that splits a migration into statements on semicolons.

INSERT INTO `sources` (`jurisdiction_id`, `publisher`, `title`, `url`, `source_type`, `is_allowlisted`)
VALUES
  ((SELECT `id` FROM `jurisdictions` WHERE `code` = 'AU'), 'Services Australia', 'Age Pension: who can get it', 'https://www.servicesaustralia.gov.au/who-can-get-age-pension', 'primary', 1);
