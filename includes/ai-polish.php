<?php
/**
 * The polish loop: take a draft through the AI check and a rewrite of just
 * what the check found, up to three times, so the text that reaches you has
 * already been through the same checks you would otherwise run by hand.
 *
 *   draft (ai-drafts.php, with its automatic rewrite)
 *     -> AI check (aiTextCheck, text-checks.php)
 *     -> rewrite ONLY the findings, from the source pages
 *     -> AI check again ... up to POLISH_MAX_CHECKS checks
 *     -> the best version is kept, not the last
 *
 * Why "best, not last": the AI check is not repeatable (the same text has
 * passed one run and failed the next) and a rewrite can fix one thing and
 * break another. Every version is checked once, and the one with the fewest
 * findings wins (a substance finding weighs 3, a style finding 1; a tie keeps
 * the earlier version). A rewrite is also refused outright, before it costs
 * another check, if it breaks the program checks (length, placeholders,
 * near-copies, typed figures) or throws away most of the text.
 *
 * What it does NOT do: judge what is missing. The check only sees problems
 * in what was written, so a clean result means "the checker found nothing",
 * not "nothing is wrong". You still read every page before saving.
 *
 * It takes about ten minutes a page (three checks of up to two minutes, two
 * rewrites of about one), too long for a web request, so it runs in the
 * background: overnight from scripts/draft-pages.php, or on demand through
 * the polish_jobs queue (migration 020) that scripts/run-polish-jobs.php
 * works through, one job per run, started every minute by cron. The editor's
 * "Draft and polish in the background" button adds a job.
 *
 * Nothing here changes a live page. The result is stored as the page's AI
 * draft (subject_drafts), with the last check kept beside it
 * (subject_text_checks) so the editor can show it.
 */

/** Most AI checks per page. That is up to two rewrites: there is no rewrite after the last check. */
const POLISH_MAX_CHECKS = 3;

/** How much a finding counts when choosing the best version. */
const POLISH_WEIGHT_SUBSTANCE = 3;
const POLISH_WEIGHT_STYLE = 1;

/** A job still "running" after this many minutes is taken to have died. */
const POLISH_STALE_MINUTES = 45;

/** Polishing can be switched off with define('POLISH_ENABLED', false); in config/database.local.php. */
function polishEnabled(): bool
{
    return !defined('POLISH_ENABLED') || (bool) POLISH_ENABLED;
}

/** The weight of a list of findings: substance 3, style 1. Lower is better. */
function polishScore(array $findings): int
{
    $score = 0;
    foreach ($findings as $finding) {
        $score += in_array($finding['kind'], TEXT_CHECK_STYLE_KINDS, true) ? POLISH_WEIGHT_STYLE : POLISH_WEIGHT_SUBSTANCE;
    }
    return $score;
}

/** One finding as a plain sentence: "Heading doesn't match its text in the Explanation: "..." — reason". */
function polishFindingLine(array $finding): string
{
    $kind = TEXT_CHECK_KINDS[$finding['kind']] ?? $finding['kind'];
    $field = TEXT_FIELD_LABELS[$finding['field']] ?? $finding['field'];
    return "$kind, in the $field: \"{$finding['quote']}\" — {$finding['problem']}";
}

// ------------------------------------------------------------
// The rewrite
// ------------------------------------------------------------

/** The instructions for a rewrite of what the check found, then the house style and the lessons. */
function polishRewriteInstructions(string $countryPrefix, string $countryCode): string
{
    $limit = EXPLANATION_MAX_WORDS;
    $job = <<<TXT
You polish a draft of the four text fields of a page on Finance Fact Library, a personal finance reference site: intro, meta_title, meta_description and explanation.

A checker has read the draft against the official source pages and the house style, and listed problems. Fix ONLY those problems. Leave everything else exactly as it is: do not rewrite sentences that are not listed, do not shorten other text, keep every placeholder, and keep the order of the sections unless a fix needs it.

How to fix each kind of problem:
- Missing exception or condition: add the missing point in one or two plain sentences, using ONLY what the source pages in the request say, in your own words. Work it into the paragraph it belongs to. Add a new section only if no section fits.
- Contradicts a source, probably wrong, overstated, or an unsupported claim: say what the source says, in the strength the source uses ("could" stays "could"; "usually" stays "usually"). If the source does not support a claim at all, remove it.
- Heading that doesn't match its text: change the heading or move the point so they agree. A heading states a point; it is not a label.
- Reads as advice: state the rule instead of telling the reader what to do ("DWP has to be told", not "tell DWP").
- Too close to a source: restate the point in your own words AND a different sentence structure.
- Duplicated, or a section that is not needed: merge or remove it.
- Never state a count, an age, a date or a rule that the source pages do not give.

The explanation may grow to make room for what was missing, but must stay within {$limit} words in all. If it would not fit, tighten the wording of unlisted text only where the meaning is unchanged.

Reply with the four fields between tags, in this order, and nothing else:
<intro>...</intro>
<meta_title>...</meta_title>
<meta_description>...</meta_description>
<explanation>
...
</explanation>
The explanation keeps the editor's format from the house style, with real line breaks.

The house style follows, then the lessons learned from past edits. The rewrite must follow them too.

TXT;

    return $job . writingGuidanceText($countryPrefix, $countryCode);
}

/**
 * The request for a rewrite: the draft, the numbered problems, the
 * placeholders it may use, and the source pages.
 *
 * $fields   intro, explanation, meta_title, meta_description (placeholders unfilled)
 * $findings from aiTextCheck()
 */
function polishRewriteRequest(array $subject, array $facts, array $fields, array $findings, string $sourceBlock): string
{
    $placeholders = ['- {tax_year}: the tax year of the figures'];
    foreach ($facts as $fact) {
        $placeholders[] = '- {fact:' . $fact['fact_key'] . '}: ' . $fact['label'] . ' (' . formatFactValue($fact) . ')';
    }

    $numbered = [];
    foreach (array_values($findings) as $i => $finding) {
        $numbered[] = ($i + 1) . '. ' . polishFindingLine($finding);
    }

    return "Page: {$subject['name']}\n"
        . "Country: {$subject['country_name']}\n\n"
        . "The draft:\n"
        . '<intro>' . (string) ($fields['intro'] ?? '') . "</intro>\n"
        . '<meta_title>' . (string) ($fields['meta_title'] ?? '') . "</meta_title>\n"
        . '<meta_description>' . (string) ($fields['meta_description'] ?? '') . "</meta_description>\n"
        . "<explanation>\n" . (string) ($fields['explanation'] ?? '') . "\n</explanation>\n\n"
        . "Problems the checker found (fix these and nothing else):\n" . implode("\n", $numbered) . "\n\n"
        . "Placeholders you may use:\n" . implode("\n", $placeholders) . "\n\n"
        . "Source pages (the only source of anything you add):\n" . $sourceBlock;
}

/**
 * Why a rewrite should not be used, or NULL if it is fine. Cheap program
 * checks, run before the rewrite is spent on another AI check.
 */
function polishRejects(PDO $pdo, array $subject, array $published, array $read, array $before, array $after): ?string
{
    $wordsBefore = preg_match_all('/\S+/u', (string) $before['explanation']);
    $wordsAfter = preg_match_all('/\S+/u', (string) $after['explanation']);

    if (trim((string) $after['explanation']) === '') {
        return 'it came back with no explanation';
    }
    if ($wordsAfter > EXPLANATION_MAX_WORDS) {
        return "the explanation grew to $wordsAfter words, over the limit of " . EXPLANATION_MAX_WORDS;
    }
    if ($wordsBefore <= EXPLANATION_MAX_WORDS && $wordsAfter < $wordsBefore * DRAFT_REVISION_MIN_LENGTH_SHARE) {
        return "it cut the explanation from $wordsBefore to $wordsAfter words";
    }

    $checksAfter = draftChecks($pdo, $after, $published, $read, $subject);
    if ($checksAfter['placeholder_errors']) {
        return 'it used a placeholder that is not valid';
    }
    $checksBefore = draftChecks($pdo, $before, $published, $read, $subject);
    if (draftScore($checksAfter) > draftScore($checksBefore)) {
        return 'it made the program checks worse (' . draftScore($checksBefore) . ' problems before, '
            . draftScore($checksAfter) . ' after)';
    }
    return null;
}

// ------------------------------------------------------------
// The loop
// ------------------------------------------------------------

/**
 * Take a draft through up to $maxChecks AI checks, rewriting what each
 * finds, and return the best version.
 *
 * $subject, $facts   as for draftSubjectPage()
 * $draft             the draft's four fields (placeholders unfilled)
 * $retryTokens       a larger token ceiling for the rewrite if all its room
 *                    went on reasoning (0: don't retry)
 *
 * Returns:
 *   fields    the four fields of the best version
 *   result    that version's aiTextCheck() result (null if no check ran)
 *   findings  the findings left in it
 *   counts    findings found by each check, in order, e.g. [7, 3, 1]
 *   best      which check's version was kept (1 = the draft as it came)
 *   clean     true if the kept version had no findings
 *   notes     things that stopped or limited the loop, in sentences
 *   tokens    tokens written by each rewrite
 *   problems  plain lines for the draft's red box: the program-check problems
 *             in the kept text, then the findings left
 */
function polishSubjectPage(PDO $pdo, array $subject, array $facts, array $draft, int $maxChecks = POLISH_MAX_CHECKS, int $retryTokens = 0): array
{
    $published = array_values(array_filter($facts, fn($f) => $f['status'] === 'published'));
    $read = readSourcePagesForFacts($published, DRAFT_SOURCE_CHARACTERS_EACH);
    $sourceBlock = requestSourceBlock($read['sources'], $read['texts']);

    $current = [];
    foreach (DRAFT_FIELDS as $field) {
        $current[$field] = (string) ($draft[$field] ?? '');
    }

    $versions = [];   // each checked version: ['fields', 'result', 'score']
    $counts = [];
    $notes = [];
    $tokens = [];
    $best = null;

    for ($check = 1; $check <= $maxChecks; $check++) {
        try {
            $result = aiTextCheck($subject, $published, $current);
        } catch (Throwable $e) {
            $notes[] = "Check $check could not run (" . $e->getMessage() . '), so polishing stopped there.';
            break;
        }

        $score = polishScore($result['findings']);
        $versions[] = ['fields' => $current, 'result' => $result, 'score' => $score];
        $counts[] = count($result['findings']);
        // A tie keeps the earlier version: only a strictly lower score replaces it.
        if ($best === null || $score < $versions[$best]['score']) {
            $best = count($versions) - 1;
        }

        // Clean, or out of checks: no rewrite after the last check.
        if (!$result['findings'] || $check === $maxChecks) {
            break;
        }

        try {
            $reply = callClaude(
                polishRewriteInstructions((string) $subject['country_prefix'], (string) $subject['country_code']),
                polishRewriteRequest($subject, $published, $current, $result['findings'], $sourceBlock),
                DRAFT_MAX_TOKENS,
                null,
                draftModel(),
                true,
                $retryTokens
            );
            $revised = parsePageDraft($reply, claudeLastCallInfo());
            $tokens[] = (int) (claudeLastCallInfo()['output_tokens'] ?? 0);
        } catch (Throwable $e) {
            $notes[] = "The rewrite after check $check failed (" . $e->getMessage() . '), so polishing stopped there.';
            break;
        }

        if (!empty($revised['truncated'])) {
            $notes[] = "The rewrite after check $check ran past the length limit, so polishing stopped there.";
            break;
        }
        foreach (DRAFT_FIELDS as $field) {
            if ($revised[$field] === null) {
                $revised[$field] = $current[$field]; // a field it left out keeps its text
            }
        }
        $revised = array_intersect_key($revised, array_flip(DRAFT_FIELDS));

        $why = polishRejects($pdo, $subject, $published, $read, $current, $revised);
        if ($why !== null) {
            $notes[] = "The rewrite after check $check was not used: $why.";
            break;
        }
        $current = $revised;
    }

    if ($best === null) {
        // No check ran at all: the draft goes back as it was.
        return [
            'fields' => $current, 'result' => null, 'findings' => [], 'counts' => [], 'best' => 0,
            'clean' => false, 'notes' => $notes, 'tokens' => $tokens, 'problems' => [],
        ];
    }

    $kept = $versions[$best];
    $keptChecks = draftChecks($pdo, $kept['fields'], $published, $read, $subject);
    $problems = array_merge(
        draftProblemLines($keptChecks, false),
        array_map(fn($f) => 'AI check: ' . polishFindingLine($f), $kept['result']['findings'])
    );

    return [
        'fields'   => $kept['fields'],
        'result'   => $kept['result'],
        'findings' => $kept['result']['findings'],
        'counts'   => $counts,
        'best'     => $best + 1,
        'clean'    => !$kept['result']['findings'],
        'notes'    => $notes,
        'tokens'   => $tokens,
        'problems' => $problems,
    ];
}

/** What the polish did, as a sentence for the draft panel, e.g. "Polished: 3 checks found 7, 3 and 1 problems; kept the version after check 3, with 1 left." */
function polishNote(array $polish): string
{
    if (!$polish['counts']) {
        return implode(' ', $polish['notes']) ?: 'Not polished.';
    }
    $counts = $polish['counts'];
    $last = array_pop($counts);
    $found = $counts ? implode(', ', $counts) . ' and ' . $last : (string) $last;
    $checks = count($polish['counts']);

    $line = 'Polished: ' . $checks . ' check' . ($checks === 1 ? '' : 's') . ' found ' . $found
        . ' problem' . (count($polish['counts']) === 1 && $last === 1 ? '' : 's') . '.';
    if ($polish['clean']) {
        $line .= $polish['best'] === 1 ? ' The first draft was already clean.' : " The version after check {$polish['best']} came back clean.";
    } else {
        $line .= $polish['best'] === 1
            ? ' The first draft was kept as the best version, with ' . count($polish['findings']) . ' left.'
            : " The best version (after check {$polish['best']}) is kept, with " . count($polish['findings']) . ' left.';
    }
    return trim($line . ' ' . implode(' ', $polish['notes']));
}

// ------------------------------------------------------------
// Draft, polish and store: what the nightly job and the queue both run
// ------------------------------------------------------------

/**
 * Draft a page, polish it, and store the result as the page's AI draft.
 * Two automatic rewrite rounds as in the nightly job, then the polish loop.
 * Returns the stored draft (with 'polish' = the polishSubjectPage() result,
 * or NULL when polishing is switched off).
 *
 * The last AI check is also stored in subject_text_checks (replacing the
 * page's earlier one) when the page has no saved explanation, so that once
 * you use the draft the editor shows that check against the text in the
 * boxes. A page that already has saved text keeps its own check.
 */
function polishPageEndToEnd(PDO $pdo, array $subject, array $facts, string $madeBy, int $retryTokens = DRAFT_RETRY_MAX_TOKENS_NIGHTLY): array
{
    $subjectId = (int) $subject['id'];

    $draft = draftSubjectPage($pdo, $subject, $facts, DRAFT_REVISIONS_NIGHTLY, DRAFT_REVISION_TIME_BUDGET_NIGHTLY, $retryTokens);
    $polish = null;

    if (polishEnabled()) {
        $polish = polishSubjectPage($pdo, $subject, $facts, $draft, POLISH_MAX_CHECKS, $retryTokens);
        if ($polish['result'] !== null) {
            foreach (DRAFT_FIELDS as $field) {
                $draft[$field] = $polish['fields'][$field];
            }
            $draft['problems'] = $polish['problems'];
        }
        // The note keeps the draft's own summary and details, with the polish between them.
        [$summary, $details] = array_pad(explode(' | Details: ', (string) $draft['rewrite_note'], 2), 2, '');
        $details .= $polish['tokens'] ? ' Polish rewrites: ' . implode(', ', array_map('number_format', $polish['tokens'])) . ' tokens written.' : '';
        $draft['rewrite_note'] = trim($summary . ' ' . polishNote($polish)) . ' | Details: ' . trim($details);
    }

    saveSubjectDraft($pdo, $subjectId, $draft, $madeBy);

    if ($polish && $polish['result'] !== null && trim((string) ($subject['explanation'] ?? '')) === '') {
        saveSubjectTextCheck($pdo, $subjectId, $polish['result'], textCheckHash($polish['fields']));
    }

    return $draft + ['polish' => $polish];
}

// ------------------------------------------------------------
// The background queue (polish_jobs, migration 020)
// ------------------------------------------------------------

/**
 * Add a page to the queue. Returns the job id, or NULL if the page already
 * has a job waiting or running (so a second click does nothing).
 */
function queuePolishJob(PDO $pdo, int $subjectId): ?int
{
    $open = $pdo->prepare("SELECT id FROM polish_jobs WHERE subject_id = :sid AND status IN ('queued', 'running') LIMIT 1");
    $open->execute(['sid' => $subjectId]);
    if ($open->fetch()) {
        return null;
    }
    $pdo->prepare("INSERT INTO polish_jobs (subject_id, status) VALUES (:sid, 'queued')")->execute(['sid' => $subjectId]);
    return (int) $pdo->lastInsertId();
}

/** The page's most recent job, or NULL. */
function getPolishJob(int $subjectId): ?array
{
    $pdo = getDbConnection();
    $stmt = $pdo->prepare('SELECT * FROM polish_jobs WHERE subject_id = :sid ORDER BY id DESC LIMIT 1');
    $stmt->execute(['sid' => $subjectId]);
    return $stmt->fetch() ?: null;
}

/**
 * Jobs stuck on "running" for longer than $minutes (the script died) are
 * marked failed, so the page can be queued again. Returns how many.
 */
function resetStalePolishJobs(PDO $pdo, int $minutes = POLISH_STALE_MINUTES): int
{
    $stmt = $pdo->prepare(
        "UPDATE polish_jobs
         SET status = 'failed', finished_at = NOW(), note = 'Stopped without finishing (the script was probably cut off).'
         WHERE status = 'running' AND started_at < (NOW() - INTERVAL :minutes MINUTE)"
    );
    $stmt->bindValue('minutes', $minutes, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->rowCount();
}

/**
 * Take the oldest waiting job and mark it running. The update only succeeds
 * for one caller, so two scripts started together can't both run it.
 */
function claimNextPolishJob(PDO $pdo): ?array
{
    $next = $pdo->query("SELECT id FROM polish_jobs WHERE status = 'queued' ORDER BY id LIMIT 1")->fetch();
    if (!$next) {
        return null;
    }
    $claim = $pdo->prepare("UPDATE polish_jobs SET status = 'running', started_at = NOW() WHERE id = :id AND status = 'queued'");
    $claim->execute(['id' => $next['id']]);
    if ($claim->rowCount() !== 1) {
        return null; // another run took it
    }
    $job = $pdo->prepare('SELECT * FROM polish_jobs WHERE id = :id');
    $job->execute(['id' => $next['id']]);
    return $job->fetch() ?: null;
}

/** Record how a job ended. */
function finishPolishJob(PDO $pdo, int $jobId, string $status, string $note): void
{
    $pdo->prepare('UPDATE polish_jobs SET status = :status, finished_at = NOW(), note = :note WHERE id = :id')
        ->execute(['status' => $status, 'note' => substr($note, 0, 2000), 'id' => $jobId]);
}

/**
 * Run the next waiting job, if any. $work does the job and returns a note
 * for the record; if it throws, the job is marked failed with the message.
 * Returns ['job' => the job, 'status' => 'done' or 'failed', 'note' => ...],
 * or NULL if nothing was waiting.
 */
function runNextPolishJob(PDO $pdo, callable $work): ?array
{
    resetStalePolishJobs($pdo);
    $job = claimNextPolishJob($pdo);
    if (!$job) {
        return null;
    }
    try {
        $note = (string) $work($job);
        $status = 'done';
    } catch (Throwable $e) {
        $note = $e->getMessage();
        $status = 'failed';
    }
    finishPolishJob($pdo, (int) $job['id'], $status, $note);
    return ['job' => $job, 'status' => $status, 'note' => $note];
}
