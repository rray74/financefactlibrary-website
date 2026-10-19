-- Page-writing procedure, step 5: review flags (includes/review-flags.php).
--
-- text_reviewed_at is when the page's text was last reviewed, set each
-- time the editor saves the page details. Figure changes after it, and
-- a year passing since it, flag the page for review on the dashboard.
--
-- Pages that already have an explanation are counted as reviewed now,
-- so they aren't all flagged as overdue the moment this is applied.
ALTER TABLE subjects ADD COLUMN text_reviewed_at DATETIME NULL;

UPDATE subjects SET text_reviewed_at = NOW()
WHERE COALESCE(explanation, '') <> '';
