-- Text the weekly source check looks for instead of the figure, for
-- figures a source page writes in words (scripts/check-sources.php).
-- Optional: blank means look for the number, as before.
ALTER TABLE facts ADD COLUMN source_match_text VARCHAR(255) NULL;

-- GOV.UK writes the statutory redundancy multipliers in words, so the
-- check could never find 0.5, 1 and 1.5 on the page. These are its exact
-- words (https://www.gov.uk/redundancy-your-rights/redundancy-pay, checked
-- 19 Oct 2026). "one and half", without an "a", is how GOV.UK writes it.
UPDATE facts SET source_match_text = 'half a week''s pay for each full year you were under 22'
WHERE fact_key = 'redundancy_weeks_under_22';
UPDATE facts SET source_match_text = 'one week''s pay for each full year you were 22 or older'
WHERE fact_key = 'redundancy_weeks_22_to_40';
UPDATE facts SET source_match_text = 'one and half week''s pay for each full year you were 41 or older'
WHERE fact_key = 'redundancy_weeks_41_plus';
