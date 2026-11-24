-- 034_new_zealand_launch.sql
--
-- The New Zealand launch, 24 November 2026. Switches the NZ country on: is_active goes from 0 to 1,
-- so /nz/ and its landing page go live and the sitemap lists the country. Migration 032 created the
-- row hidden on purpose. The 16 draft pages (subject ids 73 to 88) stay drafts and are published one
-- at a time by the drip job (drip_country, 3 a week), which started waiting only while is_active was 0.
-- This is a one-line data change and is safe to run once; running it again changes nothing.
-- Only the NZ top-level row is touched.

UPDATE `jurisdictions` SET `is_active` = 1 WHERE `code` = 'NZ' AND `parent_id` IS NULL;
