-- Page-writing procedure, step 3: AI first drafts of a subject page's
-- four text fields, made nightly by scripts/draft-pages.php or on demand
-- by the editor's "Draft this page" button.
--
-- One row per subject: a new draft replaces the old one. Drafts never
-- touch the live page; the editor shows each field's draft under it with
-- a "Use draft" button. The row is deleted when the page is saved with an
-- explanation, since by then the draft has done its job.
--
-- No foreign key to subjects, so this works whatever type subjects.id
-- has. A draft for a deleted subject is simply never shown.
CREATE TABLE subject_drafts (
    subject_id INT NOT NULL PRIMARY KEY,
    intro TEXT NULL,
    explanation MEDIUMTEXT NULL,
    meta_title VARCHAR(255) NULL,
    meta_description VARCHAR(255) NULL,
    -- Things to check before using the draft, one per line: numbers that
    -- aren't among the page's figures, and placeholder problems.
    problems TEXT NULL,
    -- 'nightly' or 'editor', and the Claude model that wrote it.
    made_by VARCHAR(20) NOT NULL DEFAULT 'nightly',
    model VARCHAR(100) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
