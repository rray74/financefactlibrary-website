-- Learning from edits (includes/lessons.php, public/admin/lessons.php).
--
-- draft_edit_records keeps, for each page, the AI draft and the text it was
-- saved as, so the differences can be studied. One row is made when a page
-- is saved with an explanation while it still has a draft (the draft itself
-- is then cleared, as before). learned_at is when lessons were last looked
-- for in the record.
--
-- writing_lessons holds the general rules learned from those differences
-- (or written by hand). Approved lessons are sent to the AI with every
-- draft and every AI check, alongside docs/house-style/. A lesson starts as
-- 'proposed' and does nothing until it's approved on /admin/lessons.php.
CREATE TABLE draft_edit_records (
    id INT AUTO_INCREMENT PRIMARY KEY,
    subject_id INT NOT NULL,
    draft_intro TEXT NULL,
    draft_explanation MEDIUMTEXT NULL,
    draft_meta_title VARCHAR(255) NULL,
    draft_meta_description VARCHAR(255) NULL,
    final_intro TEXT NULL,
    final_explanation MEDIUMTEXT NULL,
    final_meta_title VARCHAR(255) NULL,
    final_meta_description VARCHAR(255) NULL,
    draft_made_at DATETIME NULL,
    -- How much of the explanation was changed, 0 (identical) to 100.
    explanation_change_pct TINYINT UNSIGNED NULL,
    saved_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    learned_at DATETIME NULL,
    INDEX idx_edit_records_learned (learned_at),
    INDEX idx_edit_records_subject (subject_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE writing_lessons (
    id INT AUTO_INCREMENT PRIMARY KEY,
    lesson TEXT NOT NULL,
    -- 'all', or a country code such as 'GB' for a rule that only applies there.
    scope VARCHAR(10) NOT NULL DEFAULT 'all',
    -- proposed (waiting for a decision), approved (in use), rejected, retired.
    status VARCHAR(10) NOT NULL DEFAULT 'proposed',
    -- edit (found by the AI in an edit), manual (typed on the lessons page),
    -- chat (written up from a review).
    origin VARCHAR(10) NOT NULL DEFAULT 'edit',
    -- The wording from the draft and from the saved text that showed it.
    example_before TEXT NULL,
    example_after TEXT NULL,
    record_id INT NULL,
    subject_id INT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    decided_at DATETIME NULL,
    INDEX idx_lessons_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- The first lessons, from reviewing the National Minimum Wage page
-- (21 October 2026). Approved from the start.
INSERT INTO writing_lessons (lesson, scope, status, origin, decided_at) VALUES
('A heading must be true of the text under it. If the text qualifies the heading with an exception or a condition, the heading must say so (for example "Your age sets the rate, not your job" above text that says apprentices have their own rate).', 'all', 'approved', 'chat', NOW()),
('Say each rule once. If two sections cover the same rule, merge them into one section instead of repeating it.', 'all', 'approved', 'chat', NOW()),
('Never state a count or total that a source does not state, such as "two rates" when the source lists four. Describe the rates without counting them, or count only what the source counts.', 'all', 'approved', 'chat', NOW()),
('Include the exceptions, special cases and "what does not count" points that the sources list. Readers most often get these wrong, so they are the reason the page is worth reading.', 'all', 'approved', 'chat', NOW()),
('When a source gives a worked example, use it, with placeholders for the figures, rather than inventing a different one.', 'all', 'approved', 'chat', NOW()),
('Describe amounts exactly as the source does. If only the part of a charge above a limit counts, say the difference counts, not that a charge above the limit counts.', 'all', 'approved', 'chat', NOW()),
('Leave out a section that only says a rule is the same everywhere (for example "the same in all four nations"), unless readers commonly assume it differs.', 'all', 'approved', 'chat', NOW()),
('Where the source documents a recent change to an age band or threshold that older guidance still shows, say so in a sentence or two, since people comparing sources get confused by the old figure.', 'all', 'approved', 'chat', NOW());
