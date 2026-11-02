<?php
/**
 * Job worker for Finance Fact Library.
 *
 * Runs from cron every few minutes. Takes due jobs from the `jobs` table one
 * at a time, hands each to the handler for its type, and records the result.
 * The queue mechanics are in includes/jobs.php; what each job type DOES is in
 * includes/job-handlers/*.php (every file in that folder is loaded, so a new
 * country's handlers are added by dropping a file in, with no change here).
 *
 * USAGE, from the project root
 *   php scripts/run-jobs.php                  run due jobs (what cron does)
 *   php scripts/run-jobs.php --dry-run        show what each due job WOULD do;
 *                                             changes nothing, not even the queue
 *   php scripts/run-jobs.php --status         queue summary, recent failures, recent runs
 *   php scripts/run-jobs.php --enqueue=TYPE --payload='{"a":1}'
 *                            [--unique=KEY] [--delay=SECONDS] [--priority=N]
 *                                             queue a job by hand
 *   php scripts/run-jobs.php --cancel=KEY     remove a waiting job by its unique key
 *                                             (this is how you pause a drip)
 *   Options for a normal run:
 *     --budget=SECONDS   stop starting new jobs after this long (default 240)
 *     --max-jobs=N       stop after this many jobs (default 50)
 *
 * CRON (Hostinger), every 5 minutes. Send the output to a log, not /dev/null,
 * so that if something goes wrong there is something to read:
 *   0-59/5 * * * * cd ~/domains/financefactlibrary.com/app && php scripts/run-jobs.php >> logs/run-jobs.log 2>&1
 * ("0-59/5" means every 5th minute; it is the long way of writing the usual
 * star-slash-5 form, used here because star-slash would end this comment.)
 * Use the same php path your other cron lines use. The script prints nothing
 * when there is nothing to do, so the log only grows when something happens.
 *
 * WHAT KEEPS IT SAFE
 *   - Time budget: no new job is started once --budget seconds have passed, so a
 *     run ends in good time before the next cron start. A job already running is
 *     not interrupted, so handlers must be reasonably short.
 *   - Stuck jobs: a job left 'running' by a dead worker is put back in the queue
 *     after JOB_STALE_LOCK_MINUTES (see includes/jobs.php).
 *   - No overlap: if another run started within the budget window is still
 *     marked running, this run exits quietly. (Two workers could not claim the
 *     same job anyway; this just stops two runs piling up on slow jobs.)
 *   - Logged: every run that does something is a row in cron_runs. If PHP dies
 *     (out of memory, fatal error), a shutdown hook writes the reason into that
 *     row, so a run can no longer just vanish. Runs that find nothing to do
 *     write no row.
 *   - Exit code 1 if a job failed for good or the worker itself crashed; 0 if
 *     everything went to plan, including jobs that will simply be retried.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script can only be run from the command line.\n");
}

// Hostinger's command line hides errors by default; send them to stderr (the log).
ini_set('display_errors', 'stderr');
error_reporting(E_ALL);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/jobs.php';

// Load every handler file. Each registers its job types as it loads.
foreach (glob(__DIR__ . '/../includes/job-handlers/*.php') ?: [] as $handlerFile) {
    require_once $handlerFile;
}

const RUN_JOBS_SCRIPT = 'run-jobs.php';
const RUN_JOBS_DEFAULT_BUDGET = 240;
const RUN_JOBS_DEFAULT_MAX_JOBS = 50;

// ------------------------------------------------------------
// Options
// ------------------------------------------------------------

$knownOptions = ['dry-run', 'status', 'enqueue', 'payload', 'unique', 'delay', 'priority', 'cancel', 'budget', 'max-jobs'];
$options = [];
foreach (array_slice($argv, 1) as $arg) {
    if (!preg_match('/^--([a-z-]+)(?:=(.*))?$/s', $arg, $m) || !in_array($m[1], $knownOptions, true)) {
        fwrite(STDERR, "Unknown option: $arg\nSee the usage notes at the top of scripts/run-jobs.php.\n");
        exit(2);
    }
    $options[$m[1]] = $m[2] ?? true;
}

$pdo = getDbConnection();
// Handlers and the queue rely on errors being exceptions.
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$handledTypes = array_keys(jobHandlerRegistry());

if (isset($options['status'])) {
    runJobsStatus($pdo, $handledTypes);
    exit(0);
}

if (isset($options['enqueue'])) {
    exit(runJobsEnqueue($pdo, $options, $handledTypes));
}

if (isset($options['cancel'])) {
    exit(runJobsCancel($pdo, (string) $options['cancel']));
}

if (!$handledTypes) {
    fwrite(STDERR, "No job handlers are registered (is includes/job-handlers/ empty?).\n");
    exit(1);
}

$budget = isset($options['budget']) ? max(1, (int) $options['budget']) : RUN_JOBS_DEFAULT_BUDGET;
$maxJobs = isset($options['max-jobs']) ? max(1, (int) $options['max-jobs']) : RUN_JOBS_DEFAULT_MAX_JOBS;
$workerId = jobTruncate((gethostname() ?: 'host') . ':' . getmypid(), 64);

if (isset($options['dry-run'])) {
    exit(runJobsDryRun($pdo, $handledTypes));
}

exit(runJobsForReal($pdo, $handledTypes, $workerId, $budget, $maxJobs));

// ============================================================
// A normal run
// ============================================================

function runJobsForReal(PDO $pdo, array $types, string $workerId, int $budget, int $maxJobs): int
{
    // How long a run marked 'running' is trusted before we call it abandoned:
    // its budget plus two minutes for the job in flight to finish.
    $trustSeconds = $budget + 120;

    // Cheap check first: is there ANYTHING to do? If not, leave quietly with no
    // log row (a run every few minutes would otherwise fill cron_runs).
    $marks = [];
    $params = [];
    foreach (array_values($types) as $i => $type) {
        $marks[] = ':t' . $i;
        $params['t' . $i] = $type;
    }
    $stmt = $pdo->prepare(
        "SELECT
            (SELECT COUNT(*) FROM jobs WHERE status = 'queued' AND run_after <= NOW()
                AND job_type IN (" . implode(', ', $marks) . ")) AS due,
            (SELECT COUNT(*) FROM jobs WHERE status = 'running'
                AND locked_at < DATE_SUB(NOW(), INTERVAL " . (int) JOB_STALE_LOCK_MINUTES . " MINUTE)) AS stale"
    );
    $stmt->execute($params);
    $counts = $stmt->fetch();
    if ((int) $counts['due'] === 0 && (int) $counts['stale'] === 0) {
        return 0;
    }

    // Runs marked 'running' for longer than we trust never finished: say so.
    $pdo->prepare(
        "UPDATE cron_runs
         SET status = 'error', finished_at = NOW(),
             error = 'Never finished: the process was killed or timed out without reporting back.'
         WHERE script = :script AND status = 'running'
           AND started_at < DATE_SUB(NOW(), INTERVAL :seconds SECOND)"
    )->execute(['script' => RUN_JOBS_SCRIPT, 'seconds' => $trustSeconds]);

    // Is another run genuinely still going? Then step aside.
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM cron_runs WHERE script = :script AND status = 'running'");
    $stmt->execute(['script' => RUN_JOBS_SCRIPT]);
    if ((int) $stmt->fetchColumn() > 0) {
        return 0;
    }

    // Log this run, and make sure a crash is recorded rather than vanishing.
    $pdo->prepare('INSERT INTO cron_runs (script) VALUES (:script)')->execute(['script' => RUN_JOBS_SCRIPT]);
    $runId = (int) $pdo->lastInsertId();
    $finished = false;

    register_shutdown_function(function () use ($pdo, $runId, &$finished) {
        if ($finished) {
            return;
        }
        $last = error_get_last();
        $why = $last
            ? 'PHP stopped: ' . $last['message'] . ' in ' . basename($last['file']) . ' line ' . $last['line']
            : 'The script stopped without finishing.';
        try {
            $pdo->prepare("UPDATE cron_runs SET status = 'error', error = :e, finished_at = NOW() WHERE id = :id")
                ->execute(['e' => jobTruncate($why, JOB_ERROR_MAX_LENGTH), 'id' => $runId]);
        } catch (Throwable $ignored) {
            // The database itself may be the problem; nothing more we can do.
        }
        fwrite(STDERR, runJobsStamp() . "RUN $runId STOPPED: $why\n");
    });

    $handlers = jobHandlerRegistry();
    $startedAt = time();
    $done = 0;       // jobs that finished or deferred normally
    $retried = 0;    // attempts that failed and will be retried
    $failed = 0;     // jobs given up on
    $notes = [];

    try {
        $recovered = resetStaleJobs($pdo);
        if ($recovered > 0) {
            echo runJobsStamp() . "Recovered $recovered job(s) left running by a worker that vanished.\n";
            $notes[] = "$recovered recovered";
        }

        $started = 0;
        // Budget is checked BEFORE taking each job, never mid-job.
        while ($started < $maxJobs && (time() - $startedAt) < $budget) {
            $job = claimNextJob($pdo, $workerId, $types);
            if (!$job) {
                break;
            }
            $started++;

            $label = '#' . $job['id'] . ' ' . $job['job_type'];
            $payload = json_decode((string) $job['payload'], true);

            try {
                if (!is_array($payload)) {
                    throw new JobFailedPermanently('The job payload is not valid JSON.');
                }
                $note = (string) $handlers[$job['job_type']]($pdo, $payload, $job, ['dry_run' => false]);
                finishJob($pdo, (int) $job['id'], $note);
                $done++;
                echo runJobsStamp() . "$label done. $note\n";
            } catch (JobDeferred $e) {
                deferJob($pdo, (int) $job['id'], $e->seconds, $e->getMessage());
                $done++;
                echo runJobsStamp() . "$label: " . $e->getMessage() . "\n";
            } catch (JobFailedPermanently $e) {
                failJob($pdo, (int) $job['id'], $e->getMessage(), false);
                $failed++;
                fwrite(STDERR, runJobsStamp() . "$label FAILED for good: " . $e->getMessage() . "\n");
            } catch (Throwable $e) {
                $outcome = failJob($pdo, (int) $job['id'], get_class($e) . ': ' . $e->getMessage(), true);
                if ($outcome === 'failed') {
                    $failed++;
                } else {
                    $retried++;
                }
                fwrite(STDERR, runJobsStamp() . "$label failed ($outcome): " . $e->getMessage() . "\n");
            }
        }

        $purged = purgeOldJobs($pdo);
        if ($purged > 0) {
            $notes[] = "$purged old job(s) deleted";
        }

        $summary = "$done ok, $retried to retry, $failed failed" . ($notes ? '. ' . implode(', ', $notes) : '');
        $pdo->prepare(
            "UPDATE cron_runs
             SET status = :status, jobs_processed = :done, jobs_failed = :failed, error = :note, finished_at = NOW()
             WHERE id = :id"
        )->execute([
            'status' => ($failed + $retried) > 0 ? 'error' : 'ok',
            'done'   => $done,
            'failed' => $failed + $retried,
            'note'   => $summary,
            'id'     => $runId,
        ]);
        $finished = true;

        // Non-zero only for work that is lost, not for jobs that will be retried.
        return $failed > 0 ? 1 : 0;
    } catch (Throwable $e) {
        // The worker itself broke (for example the database went away).
        $finished = true;
        fwrite(STDERR, runJobsStamp() . 'WORKER FAILED: ' . $e->getMessage() . "\n");
        try {
            $pdo->prepare("UPDATE cron_runs SET status = 'error', error = :e, finished_at = NOW() WHERE id = :id")
                ->execute(['e' => jobTruncate($e->getMessage(), JOB_ERROR_MAX_LENGTH), 'id' => $runId]);
        } catch (Throwable $ignored) {
        }
        return 1;
    }
}

// ============================================================
// --dry-run
// ============================================================

/**
 * Call each due job's handler with dry_run = true and print what it says.
 * Nothing is claimed, deferred, finished or published, so this can be run as
 * often as you like, including against the live database.
 */
function runJobsDryRun(PDO $pdo, array $types): int
{
    $marks = [];
    $params = [];
    foreach (array_values($types) as $i => $type) {
        $marks[] = ':t' . $i;
        $params['t' . $i] = $type;
    }
    $stmt = $pdo->prepare(
        "SELECT * FROM jobs
         WHERE status = 'queued' AND run_after <= NOW() AND job_type IN (" . implode(', ', $marks) . ')
         ORDER BY priority ASC, run_after ASC, id ASC'
    );
    $stmt->execute($params);
    $jobs = $stmt->fetchAll();

    echo "DRY RUN: nothing will be changed.\n";
    if (!$jobs) {
        echo "No jobs are due.\n";
        return 0;
    }

    $handlers = jobHandlerRegistry();
    foreach ($jobs as $job) {
        $label = '#' . $job['id'] . ' ' . $job['job_type'];
        $payload = json_decode((string) $job['payload'], true);
        try {
            if (!is_array($payload)) {
                throw new JobFailedPermanently('The job payload is not valid JSON.');
            }
            echo "$label: " . $handlers[$job['job_type']]($pdo, $payload, $job, ['dry_run' => true]) . "\n";
        } catch (Throwable $e) {
            echo "$label: would fail (" . $e->getMessage() . ")\n";
        }
    }
    return 0;
}

// ============================================================
// --status, --enqueue, --cancel
// ============================================================

function runJobsStatus(PDO $pdo, array $types): void
{
    echo "Handlers loaded: " . ($types ? implode(', ', $types) : '(none)') . "\n\n";

    echo "QUEUE\n";
    $rows = $pdo->query(
        "SELECT job_type, status, COUNT(*) AS n FROM jobs GROUP BY job_type, status ORDER BY job_type, status"
    )->fetchAll();
    if (!$rows) {
        echo "  (no jobs)\n";
    }
    foreach ($rows as $row) {
        echo sprintf("  %-20s %-8s %d\n", $row['job_type'], $row['status'], $row['n']);
    }

    echo "\nWAITING OR RUNNING (next 15)\n";
    $rows = $pdo->query(
        "SELECT id, job_type, status, unique_key, run_after, attempts, result_note
         FROM jobs WHERE status IN ('queued', 'running') ORDER BY run_after ASC LIMIT 15"
    )->fetchAll();
    if (!$rows) {
        echo "  (none)\n";
    }
    foreach ($rows as $row) {
        echo sprintf(
            "  #%d %s%s [%s] due %s, attempts %d\n      %s\n",
            $row['id'],
            $row['job_type'],
            $row['unique_key'] ? ' (' . $row['unique_key'] . ')' : '',
            $row['status'],
            $row['run_after'],
            $row['attempts'],
            $row['result_note'] ?: '(nothing recorded yet)'
        );
    }

    echo "\nFAILED OR RETRYING (latest 10 with an error)\n";
    $rows = $pdo->query(
        "SELECT id, job_type, status, attempts, max_attempts, last_error
         FROM jobs WHERE last_error IS NOT NULL ORDER BY id DESC LIMIT 10"
    )->fetchAll();
    if (!$rows) {
        echo "  (none)\n";
    }
    foreach ($rows as $row) {
        echo sprintf("  #%d %s [%s] attempt %d of %d\n      %s\n",
            $row['id'], $row['job_type'], $row['status'], $row['attempts'], $row['max_attempts'], $row['last_error']);
    }

    echo "\nRECENT WORKER RUNS\n";
    $stmt = $pdo->prepare(
        "SELECT id, status, jobs_processed, jobs_failed, started_at, finished_at, error
         FROM cron_runs WHERE script = :script ORDER BY id DESC LIMIT 5"
    );
    $stmt->execute(['script' => RUN_JOBS_SCRIPT]);
    $rows = $stmt->fetchAll();
    if (!$rows) {
        echo "  (no runs logged; runs with nothing to do are not logged)\n";
    }
    foreach ($rows as $row) {
        echo sprintf("  run %d [%s] %s, %d ok, %d failed. %s\n",
            $row['id'], $row['status'], $row['started_at'], $row['jobs_processed'], $row['jobs_failed'], $row['error'] ?? '');
    }
}

function runJobsEnqueue(PDO $pdo, array $options, array $handledTypes): int
{
    $type = (string) $options['enqueue'];
    if (!in_array($type, $handledTypes, true)) {
        fwrite(STDERR, "There is no handler for job type '$type'. Handlers loaded: " . implode(', ', $handledTypes) . "\n");
        return 1;
    }

    $payload = [];
    if (isset($options['payload']) && $options['payload'] !== true) {
        $payload = json_decode((string) $options['payload'], true);
        if (!is_array($payload)) {
            fwrite(STDERR, "--payload must be valid JSON, for example --payload='{\"country\":\"GB\"}'\n");
            return 1;
        }
    }

    $id = enqueueJob(
        $pdo,
        $type,
        $payload,
        isset($options['delay']) ? (int) $options['delay'] : 0,
        isset($options['priority']) ? (int) $options['priority'] : 5,
        isset($options['unique']) && $options['unique'] !== true ? (string) $options['unique'] : null
    );

    if ($id === null) {
        echo "Not queued: a job with that --unique key is already waiting. See --status.\n";
        return 0;
    }
    echo "Queued job #$id ($type).\n";
    return 0;
}

function runJobsCancel(PDO $pdo, string $key): int
{
    if ($key === '' || $key === '1') {
        fwrite(STDERR, "Give the job's unique key, for example --cancel=drip_country:GB\n");
        return 1;
    }

    // Only a WAITING job can be removed. One that is running this second is left
    // alone; try again in a minute.
    $stmt = $pdo->prepare("DELETE FROM jobs WHERE unique_key = :key AND status = 'queued'");
    $stmt->execute(['key' => $key]);
    if ($stmt->rowCount() > 0) {
        echo "Removed the waiting job '$key'.\n";
        return 0;
    }

    $stmt = $pdo->prepare("SELECT status FROM jobs WHERE unique_key = :key");
    $stmt->execute(['key' => $key]);
    $status = $stmt->fetchColumn();
    echo $status
        ? "The job '$key' is $status right now, so it cannot be removed. Try again shortly.\n"
        : "No waiting job has the key '$key'.\n";
    return $status ? 1 : 0;
}

/** Time stamp for log lines. */
function runJobsStamp(): string
{
    return date('Y-m-d H:i:s') . ' ';
}
