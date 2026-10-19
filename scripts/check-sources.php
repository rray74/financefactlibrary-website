<?php
/**
 * Checks that every published figure still appears on its official
 * source page (GOV.UK, HMRC, DWP, the Scottish and Welsh governments,
 * NS&I and so on).
 *
 * Usage, from the project root:
 *   php scripts/check-sources.php --dry-run   report only, write nothing
 *   php scripts/check-sources.php             check and record results
 *   php scripts/check-sources.php --no-ai     skip the AI step (below)
 *
 * For each allowlisted source page it:
 *   1. downloads the page and reduces it to plain text,
 *   2. looks for each figure that cites the page (e.g. 12570 for a £12,570
 *      fact, matching "£12,570" or "12570" on the page),
 *   3. marks figures it finds as verified (fact_checks: unchanged), and
 *      records figures it can't find as not_found.
 *
 * A figure missing from its page usually means the page now shows a new
 * value (after a Budget, or a new tax year). It appears under "Needs a
 * look" on /admin/review.php, and then (Phase 2):
 *
 *   4. if ANTHROPIC_API_KEY is set, the missing figures for each page are
 *      sent to Claude with the page text, and it proposes what the page
 *      now says, quoting the passage. includes/ai-proposer.php gates each
 *      proposal (quote really on the page, change not too large, already
 *      in effect) and either holds it under "Held for review" or, if
 *      AI_AUTO_APPLY is on and every gate passed, publishes it.
 *
 * With --dry-run the AI is still asked, so you can see its proposals,
 * but nothing is written. This script itself never changes a figure;
 * only the gated AI step can, and only with AI_AUTO_APPLY on.
 *
 * Figures for a tax year that has ended (e.g. 2026/27 after 5 April 2027)
 * are flagged too, even if their page still shows them, because pages for
 * a specific year never change.
 *
 * Limits worth knowing: this confirms a figure is still on the page, not
 * that it's in the right place on it. Very short numbers (like 2 or 8)
 * could in theory match something unrelated. Text facts (non-numbers)
 * aren't checked. Bank of England data facts are skipped, because
 * scripts/fetch-boe-rates.php checks those against the Bank's database.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script can only be run from the command line.\n");
}

ini_set('display_errors', 'stderr');
error_reporting(E_ALL);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/fact-writer.php';
require_once __DIR__ . '/../includes/ai-proposer.php';
// fetchPageText() lives there now, shared with the editor's AI text check.
require_once __DIR__ . '/../includes/source-pages.php';

// SKIP_SOURCE_URLS (sources handled by another job) is in includes/source-pages.php.

/** Pause between page downloads, to be polite to the sites we read. */
const SECONDS_BETWEEN_REQUESTS = 1;

$options = getopt('', ['dry-run', 'no-ai']);
$dryRun = isset($options['dry-run']);

// The AI step runs when a key is configured, unless --no-ai is given.
$useAi = !isset($options['no-ai']) && aiProposalsAvailable();

$pdo = getDbConnection();

// The AI step waits on each call with the connection idle; keep it open
// (Hostinger's MySQL drops idle connections quickly).
if ($useAi) {
    keepDbConnectionOpenForAi($pdo);
}

$runId = null;
if (!$dryRun) {
    $pdo->prepare("INSERT INTO cron_runs (script) VALUES ('check-sources.php')")->execute();
    $runId = (int) $pdo->lastInsertId();
}

$counts = ['found' => 0, 'not_found' => 0, 'tax_year_ended' => 0, 'skipped' => 0, 'source_errors' => 0,
           // What the AI step did with the not-found figures.
           'ai_applied' => 0, 'ai_held' => 0, 'ai_verified' => 0, 'ai_errors' => 0];

try {
    // Allowlisted sources that at least one published fact cites.
    $sources = $pdo->query(
        "SELECT DISTINCT src.*
         FROM sources src
         JOIN facts f ON f.source_id = src.id AND f.status = 'published'
         WHERE src.is_allowlisted = 1
         ORDER BY src.id"
    )->fetchAll();

    echo $dryRun ? "DRY RUN: nothing will be written.\n" : '';
    echo $useAi
        ? 'AI step on (' . ANTHROPIC_MODEL . ', ' . (AI_AUTO_APPLY ? 'publishes proposals that pass every check' : 'trial mode: holds every proposal') . ").\n\n"
        : "AI step off.\n\n";

    foreach ($sources as $i => $source) {
        if (in_array($source['url'], SKIP_SOURCE_URLS, true)) {
            continue;
        }
        if ($i > 0) {
            sleep(SECONDS_BETWEEN_REQUESTS);
        }

        echo $source['publisher'] . "\n  " . $source['url'] . "\n";

        $stmt = $pdo->prepare(
            "SELECT * FROM facts WHERE source_id = :sid AND status = 'published' ORDER BY fact_key"
        );
        $stmt->execute(['sid' => $source['id']]);
        $facts = $stmt->fetchAll();

        try {
            $text = fetchPageText($source['url']);
        } catch (Throwable $e) {
            echo '  SOURCE ERROR: ' . $e->getMessage() . "\n\n";
            $counts['source_errors']++;
            if (!$dryRun) {
                $pdo->prepare('UPDATE sources SET last_fetched_at = NOW(), last_fetch_status = :s WHERE id = :id')
                    ->execute(['s' => substr('error: ' . $e->getMessage(), 0, 30), 'id' => $source['id']]);
                foreach ($facts as $fact) {
                    recordCheck($pdo, $fact, 'source_error', null);
                }
            }
            continue;
        }

        if (!$dryRun) {
            $pdo->prepare(
                "UPDATE sources SET last_fetched_at = NOW(), last_fetch_status = 'ok', content_hash = :h WHERE id = :id"
            )->execute(['h' => hash('sha256', $text), 'id' => $source['id']]);
        }

        // Figures not found on this page, for the AI step after the loop.
        $missing = [];

        foreach ($facts as $fact) {
            // A figure for a tax year that has finished needs replacing,
            // even if its source page still shows it (pages for a specific
            // year, like "2026 to 2027", never change).
            if (taxYearHasEnded($fact['tax_year'] ?? null)) {
                echo "  !  {$fact['fact_key']}: tax year {$fact['tax_year']} has ended\n";
                $counts['tax_year_ended']++;
                if (!$dryRun) {
                    recordCheck($pdo, $fact, 'not_found', 'tax_year_ended');
                }
                continue;
            }

            // A fact with "text to look for" (migration 015) is checked by that
            // text instead of its number: for figures the page writes in
            // words, like "one and half week's pay".
            if (trim((string) ($fact['source_match_text'] ?? '')) !== '') {
                if (pageContainsText($text, $fact['source_match_text'])) {
                    echo "  ✓  {$fact['fact_key']}: found \"{$fact['source_match_text']}\"\n";
                    $counts['found']++;
                    if (!$dryRun) {
                        markFactVerified($pdo, (int) $fact['id']);
                    }
                } else {
                    echo "  ✗  {$fact['fact_key']}: \"{$fact['source_match_text']}\" NOT FOUND on the page\n";
                    $counts['not_found']++;
                    $missing[] = $fact;
                    if (!$dryRun) {
                        recordCheck($pdo, $fact, 'not_found', null);
                    }
                }
                continue;
            }

            $candidates = valueCandidates($fact);
            if (!$candidates) {
                echo "  -  {$fact['fact_key']}: not a number, not checked\n";
                $counts['skipped']++;
                continue;
            }

            $found = null;
            foreach ($candidates as $candidate) {
                if (preg_match('/(?<![\d.])' . preg_quote($candidate, '/') . '(?!\d|\.\d)/', $text)) {
                    $found = $candidate;
                    break;
                }
            }

            if ($found !== null) {
                echo "  ✓  {$fact['fact_key']}: found $found\n";
                $counts['found']++;
                if (!$dryRun) {
                    markFactVerified($pdo, (int) $fact['id']);
                }
            } else {
                echo "  ✗  {$fact['fact_key']}: " . implode(' / ', $candidates) . " NOT FOUND on the page\n";
                $counts['not_found']++;
                $missing[] = $fact;
                if (!$dryRun) {
                    recordCheck($pdo, $fact, 'not_found', null);
                }
            }
        }

        // --- AI step: ask what the page now says for the missing figures. ---
        // One request per page, covering all its missing figures. Figures
        // past their tax year aren't sent: their page is often for that
        // year only, so the new figure is usually on a different page.
        if ($useAi && $missing) {
            try {
                $aiResults = proposeUpdatesFromPage($pdo, $source, $text, $missing, $dryRun);
                foreach ($missing as $fact) {
                    $result = $aiResults[$fact['id']] ?? ['outcome' => 'skipped', 'message' => 'no result'];
                    echo "     AI  {$fact['fact_key']}: {$result['message']}\n";
                    if ($result['outcome'] === 'applied') {
                        $counts['ai_applied']++;
                    } elseif ($result['outcome'] === 'held') {
                        $counts['ai_held']++;
                    } elseif ($result['outcome'] === 'verified') {
                        $counts['ai_verified']++;
                    }
                }
            } catch (Throwable $e) {
                // An AI failure never stops the check. The figures simply
                // stay under "Needs a look", as they would without AI.
                echo '     AI  ERROR: ' . $e->getMessage() . "\n";
                $counts['ai_errors']++;
            }
        }
        echo "\n";
    }

    $summary = "{$counts['found']} found, {$counts['not_found']} not found, "
        . "{$counts['tax_year_ended']} past their tax year, "
        . "{$counts['skipped']} not checkable, {$counts['source_errors']} sources unavailable";
    if ($useAi) {
        $summary .= ". AI: {$counts['ai_applied']} published, {$counts['ai_held']} held, "
            . "{$counts['ai_verified']} confirmed unchanged, {$counts['ai_errors']} pages failed";
    }

    // Figures the AI published or confirmed no longer need a look.
    $stillNeedALook = $counts['not_found'] - $counts['ai_applied'] - $counts['ai_verified']
        + $counts['tax_year_ended'] + $counts['source_errors'];
    echo "Done. $summary.\n";

    if ($runId) {
        $pdo->prepare(
            "UPDATE cron_runs SET status = :status, jobs_processed = :done, jobs_failed = :failed,
             error = :note, finished_at = NOW() WHERE id = :id"
        )->execute([
            'status' => $counts['source_errors'] + $counts['ai_errors'] > 0 ? 'error' : 'ok',
            'done'   => $counts['found'] + $counts['ai_applied'] + $counts['ai_verified'],
            'failed' => $stillNeedALook,
            'note'   => $summary,
            'id'     => $runId,
        ]);
    }

    // Non-zero exit when something needs a look, so the cron job reports it.
    exit($stillNeedALook > 0 ? 1 : 0);

} catch (Throwable $e) {
    fwrite(STDERR, 'FAILED: ' . $e->getMessage() . "\n");
    if ($runId) {
        $pdo->prepare("UPDATE cron_runs SET status = 'error', error = :e, finished_at = NOW() WHERE id = :id")
            ->execute(['e' => $e->getMessage(), 'id' => $runId]);
    }
    exit(1);
}

// ============================================================

/**
 * True if the page text contains the given words, ignoring capitals,
 * spacing, and curly versus straight quotes and apostrophes (GOV.UK
 * writes "week’s" with a curly apostrophe; people type "week's").
 */
function pageContainsText(string $pageText, string $lookFor): bool
{
    $normalise = fn(string $s) => strtolower(trim(preg_replace('/\s+/u', ' ',
        str_replace(["\u{2019}", "\u{2018}", "\u{201C}", "\u{201D}"], ["'", "'", '"', '"'], $s))));
    $needle = $normalise($lookFor);
    // The page text has thousands separators removed (see fetchPageText()),
    // so remove them from the words looked for too: "£1,000" -> "£1000".
    $needle = preg_replace('/(\d),(\d{3})(?!\d)/', '$1$2', $needle);
    return $needle !== '' && strpos($normalise($pageText), $needle) !== false;
}

/**
 * The ways a figure might be written on its source page, without
 * thousands separators: e.g. 241.30 -> "241.30", "241.3"; 12570 -> "12570";
 * 8.00 -> "8.00", "8". Returns an empty list for text facts.
 */
function valueCandidates(array $fact): array
{
    if ($fact['value_numeric'] === null || in_array($fact['value_type'], ['text', 'date'], true)) {
        return [];
    }

    $asEntered = str_replace(',', '', trim($fact['value']));
    $number = (float) $fact['value_numeric'];

    $candidates = [$asEntered];
    // Without trailing zeros: 241.30 -> 241.3, 8.00 -> 8.
    $trimmed = rtrim(rtrim(number_format($number, 4, '.', ''), '0'), '.');
    $candidates[] = $trimmed;
    // Money with pence is also written with two decimals: 3.5 -> 3.50.
    if ($fact['value_type'] === 'currency' && strpos($trimmed, '.') !== false) {
        $candidates[] = number_format($number, 2, '.', '');
    }
    // Large round sums are often written in words: 2000000 -> "2 million" or "2m".
    if ($number >= 1000000 && round($number / 1000000, 2) * 1000000 == $number) {
        $millions = rtrim(rtrim(number_format($number / 1000000, 2, '.', ''), '0'), '.');
        $candidates[] = $millions . ' million';
        $candidates[] = $millions . 'm';
    }

    return array_values(array_unique(array_filter($candidates, fn($c) => $c !== '')));
}

/**
 * True once a UK tax year such as '2026/27' has finished (after 5 April
 * 2027). Figures without a tax year never expire this way.
 */
function taxYearHasEnded(?string $taxYear): bool
{
    if (!$taxYear || !preg_match('#^(\d{4})/(\d{2})$#', $taxYear, $m)) {
        return false;
    }
    $endYear = (int) substr($m[1], 0, 2) . $m[2];
    return date('Y-m-d') > sprintf('%04d-04-05', $endYear);
}

/** Record the outcome of a check for a fact. */
function recordCheck(PDO $pdo, array $fact, string $result, ?string $observed): void
{
    $pdo->prepare(
        'INSERT INTO fact_checks (fact_id, source_id, result, observed_value) VALUES (:fid, :sid, :result, :obs)'
    )->execute([
        'fid'    => $fact['id'],
        'sid'    => $fact['source_id'],
        'result' => $result,
        'obs'    => $observed,
    ]);
}
