-- 029_australia_jobseeker_sources.sql
--
-- Adds two Services Australia pages to the Australian sources: the JobSeeker
-- Payment rates page and the page on who can get it. Both were read on
-- 18 November 2026 with scripts/read-source.php. The rates page shows the maximum
-- fortnightly rates from 20 September 2026 (last updated 29 October 2026), and the
-- eligibility page states the age range.
--
-- After 028 there are 17 Australian sources, and after this there are 19.
--
-- No semicolons appear inside the comments or text in this file, so it is safe
-- for any runner that splits a migration into statements on semicolons.

INSERT INTO `sources` (`jurisdiction_id`, `publisher`, `title`, `url`, `source_type`, `is_allowlisted`)
VALUES
  ((SELECT `id` FROM `jurisdictions` WHERE `code` = 'AU'), 'Services Australia', 'JobSeeker Payment: how much you can get', 'https://www.servicesaustralia.gov.au/how-much-jobseeker-payment-you-can-get', 'primary', 1),
  ((SELECT `id` FROM `jurisdictions` WHERE `code` = 'AU'), 'Services Australia', 'JobSeeker Payment: who can get it', 'https://www.servicesaustralia.gov.au/who-can-get-jobseeker-payment', 'primary', 1);
