<?php
/**
 * Job queue for Finance Fact Library.
 *
 * This file is the queue itself: putting jobs in, taking one out safely,
 * and recording how it went. It knows nothing about what any job DOES.
 * What a job does lives in a "handler", registered by job type (see
 * includes/job-handlers/). scripts/run-jobs.php is the cron script that
 * ties the two together.
 *
 * WHY A QUEUE
 *   Slow or scheduled work (publishing a page on its drip date, checking a
 *   country's sources, later calling the AI) is written as a small job row
 *   instead of being a separate cron script. Adding a country then means
 *   adding handlers and jobs, not another crontab line.
 *
 * THE jobs TABLE (see sql/migrations 021 for the two columns added to it)
 *   job_type      which handler runs it, e.g. 'drip_country'
 *   payload       JSON the handler reads, e.g. {"country":"AU","per_week":3}
 *   status        queued -> running -> done | failed
 *   priority      LOWER NUMBER RUNS FIRST (default 5)
 *   attempts      how many times a worker has started it
 *   max_attempts  after this many starts a failing job is given up on
 *   run_after     not started before this time (this is how drip-feeding waits)
 *   locked_by     which worker has it ("hostname:pid")
 *   locked_at     when that worker took it
 *   unique_key    optional; blocks a duplicate while one is still waiting
 *   last_error    why the last attempt failed
 *   result_note   what the last run did, in a line
 *
 * TIMES ARE ALL THE DATABASE'S OWN CLOCK (NOW()). Nothing here compares a
 * PHP time with a database time, so a server and database set to different
 * time zones cannot make a job run early or late. Delays are therefore given
 * as a number of seconds from now, never as a clock time.
 *
 * HOW A HANDLER ENDS A JOB
 *   return 'a short note';        the job is done
 *   throw new JobDeferred(...)    "not now, run me again later". NOT a failure
 *                                 and it does not use up an attempt. This is
 *                                 how a drip job waits for its next slot, and
 *                                 how a job that is never finished (a
 *                                 recurring one) reschedules itself.
 *   throw new JobFailedPermanently(...)   bad payload or similar; retrying
 *                                 cannot help, so fail at once.
 *   throw anything else           treated as a temporary fault: retried with
 *                                 growing delays until max_attempts.
 *
 * HANDLERS MUST BE SAFE TO RUN TWICE. If a worker is killed mid-job, the job
 * is put back in the queue after JOB_STALE_LOCK_MINUTES and runs again, and a
 * worker that was merely very slow could overlap with that second run.
 *
 * Written to run on older PHP versions too (no match, no str_contains).
 */

/** A job still 'running' after this long is assumed to belong to a dead worker. */
const JOB_STALE_LOCK_MINUTES = 20;

/** First retry waits this long; each further retry waits 5 times longer (60s, 5m, 25m...). */
const JOB_BACKOFF_BASE_SECONDS = 60;

/** ...but never longer than this (6 hours). */
const JOB_BACKOFF_MAX_SECONDS = 21600;

/** Finished jobs older than this are deleted by purgeOldJobs(). */
const JOB_KEEP_DONE_DAYS = 90;

/** Column sizes in the jobs table (last_error is TEXT, result_note is VARCHAR(500)). */
const JOB_ERROR_MAX_LENGTH = 2000;
const JOB_NOTE_MAX_LENGTH = 500;

// ------------------------------------------------------------
// How a handler tells the worker what happened
// ------------------------------------------------------------

/**
 * "Not now. Run me again in $seconds." Not a failure, and the attempt is
 * handed back so waiting never runs a job out of attempts.
 */
class JobDeferred extends RuntimeException
{
    /** @var int */
    public $seconds;

    public function __construct(int $seconds, string $reason)
    {
        parent::__construct($reason);
        // At least one second, so a deferred job can never be picked up again
        // in the same instant and spin.
        $this->seconds = max(1, $seconds);
    }
}

/** "This will never work, don't retry." The job is marked failed straight away. */
class JobFailedPermanently extends RuntimeException
{
}

// ------------------------------------------------------------
// Handler registry
// ------------------------------------------------------------

/**
 * One list of job type => handler, shared by the whole run. Call with
 * arguments to register; call with none to read the list.
 */
function jobHandlerRegistry(?string $type = null, ?callable $handler = null): array
{
    static $handlers = [];
    if ($type !== null && $handler !== null) {
        $handlers[$type] = $handler;
    }
    return $handlers;
}

/**
 * Register a handler. It is called as
 *   $handler(PDO $pdo, array $payload, array $job, array $context)
 * where $context['dry_run'] is true when the worker was started with
 * --dry-run, in which case the handler must change nothing and just say what
 * it WOULD do (by returning a note).
 */
function registerJobHandler(string $type, callable $handler): void
{
    jobHandlerRegistry($type, $handler);
}

// ------------------------------------------------------------
// Putting jobs in
// ------------------------------------------------------------

/**
 * Queue a job. Returns its id, or null if $uniqueKey is given and a job with
 * that key is already waiting (that is the point of the key, not an error).
 *
 * @param int $delaySeconds  wait this long before the job may start
 * @param int $priority      lower runs first
 */
function enqueueJob(
    PDO $pdo,
    string $type,
    array $payload = [],
    int $delaySeconds = 0,
    int $priority = 5,
    ?string $uniqueKey = null,
    int $maxAttempts = 3
): ?int {
    $stmt = $pdo->prepare(
        'INSERT INTO jobs (job_type, payload, priority, max_attempts, run_after, unique_key)
         VALUES (:type, :payload, :priority, :max_attempts, DATE_ADD(NOW(), INTERVAL :delay SECOND), :unique_key)'
    );

    try {
        $stmt->execute([
            'type'         => $type,
            'payload'      => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'priority'     => $priority,
            'max_attempts' => $maxAttempts,
            'delay'        => max(0, $delaySeconds),
            'unique_key'   => $uniqueKey,
        ]);
    } catch (PDOException $e) {
        // 1062 = duplicate key: the same unique_key is already waiting.
        if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
            return null;
        }
        throw $e;
    }

    return (int) $pdo->lastInsertId();
}

// ------------------------------------------------------------
// Taking a job out
// ------------------------------------------------------------

/**
 * Take the next due job, or null if there is none.
 *
 * TWO WORKERS CAN NEVER GET THE SAME JOB. Step 1 finds a candidate; step 2
 * claims it with an UPDATE that only succeeds if the job is STILL 'queued'.
 * The database lets exactly one of two simultaneous UPDATEs change the row, so
 * the loser sees "0 rows changed" and simply tries the next candidate. This
 * works the same on MySQL and MariaDB, which is why it is used instead of
 * SELECT ... FOR UPDATE SKIP LOCKED (not available on older versions).
 *
 * @param string[] $types  only claim these job types. The worker passes the
 *                         types it has handlers for, so a job whose handler is
 *                         missing is left alone rather than failed.
 */
function claimNextJob(PDO $pdo, string $workerId, array $types): ?array
{
    if (!$types) {
        return null;
    }

    $typeParams = [];
    $typeMarks = [];
    foreach (array_values($types) as $i => $type) {
        $typeParams['t' . $i] = $type;
        $typeMarks[] = ':t' . $i;
    }

    $find = $pdo->prepare(
        "SELECT id FROM jobs
         WHERE status = 'queued' AND run_after <= NOW() AND job_type IN (" . implode(', ', $typeMarks) . ')
         ORDER BY priority ASC, run_after ASC, id ASC
         LIMIT 1'
    );
    $claim = $pdo->prepare(
        "UPDATE jobs
         SET status = 'running', locked_by = :worker, locked_at = NOW(), attempts = attempts + 1
         WHERE id = :id AND status = 'queued'"
    );

    // A few tries, because another worker may take our candidate first.
    for ($try = 0; $try < 5; $try++) {
        $find->execute($typeParams);
        $id = $find->fetchColumn();
        if (!$id) {
            return null;
        }

        $claim->execute(['worker' => $workerId, 'id' => $id]);
        if ($claim->rowCount() === 1) {
            $row = $pdo->prepare('SELECT * FROM jobs WHERE id = :id');
            $row->execute(['id' => $id]);
            return $row->fetch() ?: null;
        }
    }

    return null;
}

// ------------------------------------------------------------
// Recording how it went
// ------------------------------------------------------------

/** Job finished. The unique_key is released so the same work can be queued again. */
function finishJob(PDO $pdo, int $jobId, string $note = ''): void
{
    $pdo->prepare(
        "UPDATE jobs
         SET status = 'done', finished_at = NOW(), unique_key = NULL, result_note = :note
         WHERE id = :id"
    )->execute(['note' => jobTruncate($note, JOB_NOTE_MAX_LENGTH), 'id' => $jobId]);
}

/**
 * Put a running job back in the queue to run again after $seconds, WITHOUT
 * counting this start as an attempt. The unique_key is kept, so a recurring
 * job cannot be queued a second time while it waits.
 */
function deferJob(PDO $pdo, int $jobId, int $seconds, string $reason): void
{
    $pdo->prepare(
        "UPDATE jobs
         SET status = 'queued',
             attempts = GREATEST(attempts, 1) - 1,
             run_after = DATE_ADD(NOW(), INTERVAL :seconds SECOND),
             locked_by = NULL, locked_at = NULL,
             result_note = :note
         WHERE id = :id AND status = 'running'"
    )->execute(['seconds' => max(1, $seconds), 'note' => jobTruncate($reason, JOB_NOTE_MAX_LENGTH), 'id' => $jobId]);
}

/**
 * Record a failed attempt. Returns 'retry' if the job was put back in the
 * queue with a delay, or 'failed' if it has run out of attempts (or $retry is
 * false) and was given up on.
 *
 * Delays grow: 60s, 5 minutes, 25 minutes... up to 6 hours.
 */
function failJob(PDO $pdo, int $jobId, string $error, bool $retry = true): string
{
    $error = jobTruncate($error, JOB_ERROR_MAX_LENGTH);

    $row = $pdo->prepare('SELECT attempts, max_attempts FROM jobs WHERE id = :id');
    $row->execute(['id' => $jobId]);
    $job = $row->fetch();
    if (!$job) {
        return 'failed';
    }

    $attempts = (int) $job['attempts'];
    if ($retry && $attempts < (int) $job['max_attempts']) {
        $delay = (int) min(JOB_BACKOFF_MAX_SECONDS, JOB_BACKOFF_BASE_SECONDS * pow(5, max(0, $attempts - 1)));
        $pdo->prepare(
            "UPDATE jobs
             SET status = 'queued', run_after = DATE_ADD(NOW(), INTERVAL :delay SECOND),
                 locked_by = NULL, locked_at = NULL, last_error = :error
             WHERE id = :id"
        )->execute(['delay' => $delay, 'error' => $error, 'id' => $jobId]);
        return 'retry';
    }

    $pdo->prepare(
        "UPDATE jobs
         SET status = 'failed', finished_at = NOW(), unique_key = NULL, last_error = :error
         WHERE id = :id"
    )->execute(['error' => $error, 'id' => $jobId]);
    return 'failed';
}

// ------------------------------------------------------------
// Housekeeping
// ------------------------------------------------------------

/**
 * Recover jobs whose worker vanished. A job that has been 'running' for more
 * than $minutes is treated like a failed attempt: it goes back in the queue,
 * or is failed if it has no attempts left. (This is what was missing when a
 * polish job once vanished mid-run and its row stayed 'running' for ever.)
 *
 * Each recovery is guarded by the lock time it saw, so a worker that finishes
 * at the very moment this runs is not overwritten. Returns how many were
 * recovered.
 */
function resetStaleJobs(PDO $pdo, int $minutes = JOB_STALE_LOCK_MINUTES): int
{
    $find = $pdo->prepare(
        "SELECT id, locked_by, locked_at FROM jobs
         WHERE status = 'running' AND locked_at < DATE_SUB(NOW(), INTERVAL :minutes MINUTE)"
    );
    $find->execute(['minutes' => $minutes]);

    $recovered = 0;
    foreach ($find->fetchAll() as $stale) {
        $message = 'Worker lost: started by ' . ($stale['locked_by'] ?: 'unknown')
            . ' at ' . $stale['locked_at'] . ' and never reported back.';

        // Still the same stuck run? Only then do we touch it.
        $check = $pdo->prepare("SELECT 1 FROM jobs WHERE id = :id AND status = 'running' AND locked_at = :locked_at");
        $check->execute(['id' => $stale['id'], 'locked_at' => $stale['locked_at']]);
        if (!$check->fetchColumn()) {
            continue;
        }

        failJob($pdo, (int) $stale['id'], $message, true);
        $recovered++;
    }

    return $recovered;
}

/**
 * Delete finished and failed jobs older than JOB_KEEP_DONE_DAYS, a batch at a
 * time. Rows in fact_changes and fact_checks that point at a deleted job keep
 * their data (their job_id becomes NULL), so no history is lost. Returns how
 * many were deleted.
 */
function purgeOldJobs(PDO $pdo, int $days = JOB_KEEP_DONE_DAYS, int $limit = 500): int
{
    $limit = max(1, (int) $limit);
    $stmt = $pdo->prepare(
        "DELETE FROM jobs
         WHERE status IN ('done', 'failed') AND finished_at < DATE_SUB(NOW(), INTERVAL :days DAY)
         LIMIT $limit"
    );
    $stmt->execute(['days' => $days]);
    return $stmt->rowCount();
}

/**
 * Cut text to at most $max CHARACTERS (that is how MySQL sizes VARCHAR(500)),
 * never leaving half a multibyte character on the end, which the database
 * would reject. Works without the mbstring extension.
 */
function jobTruncate(string $text, int $max): string
{
    // Fewer bytes than the limit means fewer characters too, so it fits.
    if (strlen($text) <= $max) {
        return $text;
    }
    // /u makes "." match whole UTF-8 characters, so we cut on a character boundary.
    if (preg_match('/^.{0,' . $max . '}/us', $text, $m)) {
        return $m[0];
    }
    // Not valid UTF-8: keep plain ASCII so the insert cannot fail.
    return preg_replace('/[^\x20-\x7E]/', '?', substr($text, 0, $max));
}
