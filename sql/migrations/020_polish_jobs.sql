-- The polish queue (includes/ai-polish.php): pages waiting to be drafted and
-- polished in the background. The editor's "Draft and polish in the
-- background" button adds a row; scripts/run-polish-jobs.php, run every
-- minute by cron, takes the oldest 'queued' row, does the work (about ten
-- minutes) and marks it 'done' or 'failed' with a note.
--
-- A page can have only one 'queued' or 'running' job at a time (checked in
-- queuePolishJob(), so a second click does nothing). A row stuck on
-- 'running' for 45 minutes is marked failed so the page can be queued again.
--
-- No foreign key to subjects, as with subject_drafts (012): it works
-- whatever type subjects.id has.
CREATE TABLE polish_jobs (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    subject_id INT NOT NULL,
    status ENUM('queued', 'running', 'done', 'failed') NOT NULL DEFAULT 'queued',
    requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    started_at DATETIME NULL,
    finished_at DATETIME NULL,
    -- What happened, in a sentence: the polish summary, or the error.
    note TEXT NULL,
    KEY idx_polish_status (status, id),
    KEY idx_polish_subject (subject_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
