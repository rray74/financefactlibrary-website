-- 040_us_launch.sql
--
-- The United States launch. Switches the US country on: is_active goes from 0 to 1, so /us/ and its landing
-- page go live and the sitemap lists the country. Migration 038 created the row hidden on purpose.
-- The 20 draft pages (subject ids 118 to 137) stay drafts and are published one at a time by the drip
-- job (drip_country), which only waited while is_active was 0. The drip is queued separately on the
-- server with scripts/run-jobs.php --enqueue=drip_country, not by this migration.
-- Richard decided on 27 November 2026 to launch without waiting for January, because every indexed
-- figure carries its own tax year (2027, or 2026 for the Earned Income Tax Credit) and the Federal Reserve
-- cards are re-read after each FOMC decision (docs/TODO.md key dates).
-- This is a one-line data change and is safe to run once, and running it again changes nothing.
-- Only the US top-level row is touched. The fifty-one state and district rows stay as they are.

UPDATE `jurisdictions` SET `is_active` = 1 WHERE `code` = 'US' AND `parent_id` IS NULL;
