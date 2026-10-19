-- Page-writing procedure, step 4: the AI check of a subject page's text
-- against its figures and the source pages they cite
-- (includes/text-checks.php), run from the editor's "Check with AI"
-- button. Step 5's weekly check will use the same table.
--
-- One row per subject, the latest check. text_hash fingerprints the text
-- that was checked, so the editor can say when the text has changed
-- since. No foreign key, as with subject_drafts (migration 012).
CREATE TABLE subject_text_checks (
    subject_id INT NOT NULL PRIMARY KEY,
    text_hash CHAR(64) NOT NULL,
    -- JSON list of {field, kind, quote, problem}. Empty list = no problems.
    findings MEDIUMTEXT NOT NULL,
    -- JSON list of {name, url, read, error}: the source pages read.
    sources TEXT NOT NULL,
    -- Findings thrown away because they misquoted the text.
    dropped INT NOT NULL DEFAULT 0,
    model VARCHAR(100) NULL,
    checked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
