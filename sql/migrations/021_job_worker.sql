-- 021_job_worker.sql
--
-- Gets the existing `jobs` table ready for the job worker (scripts/run-jobs.php).
-- The table was created earlier but has never held a row, so this is safe to run
-- on the live database. It only ADDS things; no existing column changes.
--
-- NUMBERING: this assumes 021 is the next free number in sql/migrations. If it is
-- not (check with `ls sql/migrations | tail -3`), rename the file to the next
-- number before running it. Nothing inside the file refers to its own number.
--
-- What is added:
--
--   unique_key    Stops the same piece of work being queued twice while it is still
--                 waiting. Example: 'drip_country:AU'. The worker clears it when a
--                 job finishes or fails for good, so the same work can be queued
--                 again later. NULL means "no de-duplication"; any number of rows
--                 can be NULL, so ordinary jobs are unaffected.
--
--   result_note   A short, human-readable line about what the job last did, for
--                 example "Published Income Tax Personal Allowance. Next slot in
--                 56h". Shown by `php scripts/run-jobs.php --status`. last_error
--                 stays for errors only.
--
--   idx_running_lock   Lets the worker find jobs stuck in 'running' (a worker that
--                      vanished mid-run) without scanning the whole table.

ALTER TABLE `jobs`
  ADD COLUMN `unique_key`  VARCHAR(120) DEFAULT NULL AFTER `job_type`,
  ADD COLUMN `result_note` VARCHAR(500) DEFAULT NULL AFTER `last_error`,
  ADD UNIQUE KEY `uniq_unique_key` (`unique_key`),
  ADD KEY `idx_running_lock` (`status`, `locked_at`);
