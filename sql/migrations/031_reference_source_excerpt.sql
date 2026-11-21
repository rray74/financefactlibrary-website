-- 031_reference_source_excerpt.sql
--
-- Lets a reference source say where to START READING a long page. Added 21 Nov 2026.
--
-- WHY. The AI check reads at most the first 50000 characters of each source page (30000 for
-- drafting). Some official pages are far longer, for example a Bank of England Monetary Policy
-- Report is about 177000 characters, and the passage a page's text relies on can sit well past
-- the cut. The check would then never see it and would flag correct text as unsupported.
--
-- excerpt_from is a phrase from the page. When it is set, the page is read from a little before
-- the first place that phrase appears (capitals and spacing ignored), and the usual character
-- limit counts from there. Blank means read from the start, as before.
--
-- No semicolons appear inside the comments in this file, so it is safe for the runner that
-- splits a migration into statements on semicolons.

ALTER TABLE subject_reference_sources ADD COLUMN excerpt_from VARCHAR(255) NULL AFTER note;
