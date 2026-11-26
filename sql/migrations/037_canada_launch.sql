-- 037_canada_launch.sql
--
-- The Canada launch. Switches the CA country on: is_active goes from 0 to 1, so /ca/ and its landing
-- page go live and the sitemap lists the country. Migration 035 created the row hidden on purpose.
-- The 20 draft pages (subject ids 98 to 117) stay drafts and are published one at a time by the drip
-- job (drip_country), which only waited while is_active was 0. The drip is queued separately on the
-- server with scripts/run-jobs.php --enqueue=drip_country, not by this migration.
-- This is a one-line data change and is safe to run once, and running it again changes nothing.
-- Only the CA top-level row is touched. The thirteen province and territory rows stay as they are.

UPDATE `jurisdictions` SET `is_active` = 1 WHERE `code` = 'CA' AND `parent_id` IS NULL;
