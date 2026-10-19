<?php
/**
 * AI-assisted updates (Phase 2).
 *
 * When scripts/check-sources.php can't find a published figure on its
 * source page, the page has usually changed to show a new value (after a
 * Budget, or for a new tax year). This file asks Claude to read that page
 * and say what it now states for each missing figure, with the exact
 * passage it took the value from.
 *
 * Nothing the AI says is trusted on its own. Every proposal goes through
 * the same kind of gates as the Bank of England pipeline:
 *
 *   allowlist  the page is an allowlisted official source (check-sources
 *              only reads those, so this always passes here)
 *   verbatim   the quoted passage really is on the page, character for
 *              character, and contains the proposed value. This is what
 *              stops a made-up figure getting through.
 *   threshold  the new value is within AI_MAX_CHANGE_RATIO of the old one,
 *              so a misread (wrong row of a table, say) is held instead
 *              of published
 *   in effect  the value isn't for a date still in the future
 *
 * A proposal that passes every gate is published through updateFactValue()
 * (so it's in fact_history and "What's changed"), but only when
 * AI_AUTO_APPLY is true in config/database.local.php. Until then, every
 * proposal is held on /admin/review.php for Approve / Reject, so you can
 * see how reliable it is first. Proposals that fail a gate are always held.
 *
 * Needs ANTHROPIC_API_KEY in config/database.local.php. Without it, this
 * whole step is skipped and check-sources.php works exactly as before.
 */

require_once __DIR__ . '/fact-writer.php';

// ------------------------------------------------------------
// Settings. Each can be overridden in config/database.local.php by
// defining it there first, e.g. define('AI_AUTO_APPLY', true);
// ------------------------------------------------------------

/** Publish proposals that pass every gate. Off by default: everything is held for review. */
if (!defined('AI_AUTO_APPLY')) {
    define('AI_AUTO_APPLY', false);
}

/** The Claude model used to read pages. */
if (!defined('ANTHROPIC_MODEL')) {
    define('ANTHROPIC_MODEL', 'claude-sonnet-5-5');
}

/**
 * Largest change that can publish without review, as a fraction of the
 * old value. 0.25 = 25%. Real yearly changes to allowances and rates are
 * usually far smaller; a bigger jump is more likely a misread.
 */
if (!defined('AI_MAX_CHANGE_RATIO')) {
    define('AI_MAX_CHANGE_RATIO', 0.25);
}

/** Longest page text sent to the AI (characters). GOV.UK pages are far shorter. */
const AI_MAX_PAGE_CHARACTERS = 100000;

/**
 * Keep a database connection open through long AI calls.
 *
 * Hostinger's MySQL closes connections that sit idle for a short time,
 * and an AI call can take a minute or more with the connection unused.
 * The next query then fails with "MySQL server has gone away" (which is
 * what stopped the first nightly run of scripts/draft-pages.php). This
 * raises the idle limit for this one connection only, to 15 minutes. It
 * changes nothing for any other connection or the server.
 *
 * Call it before the first AI call in any script or page that uses the
 * database afterwards. Safe to call more than once.
 */
function keepDbConnectionOpenForAi(PDO $pdo, int $seconds = 900): void
{
    try {
        $pdo->exec('SET SESSION wait_timeout = ' . (int) $seconds);
    } catch (Throwable $e) {
        // Not fatal: the worst case is the old behaviour, reported as an error.
    }
}

/** True if an API key is set and cURL is available, so proposals can run. */
function aiProposalsAvailable(): bool
{
    return defined('ANTHROPIC_API_KEY') && ANTHROPIC_API_KEY !== '' && function_exists('curl_init');
}

/**
 * Send one request to the Anthropic Messages API and return the text of
 * the reply. Throws on any error. The API key is never included in an
 * error message, so it can't end up in logs.
 */
function callClaude(string $system, string $userText, int $maxTokens = 4000): string
{
    $body = json_encode([
        'model'      => ANTHROPIC_MODEL,
        'max_tokens' => $maxTokens,
        'system'     => $system,
        'messages'   => [['role' => 'user', 'content' => $userText]],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);

    $curl = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_TIMEOUT        => 180,
        CURLOPT_HTTPHEADER     => [
            'content-type: application/json',
            'x-api-key: ' . ANTHROPIC_API_KEY,
            'anthropic-version: 2023-06-01',
        ],
    ]);
    $response = curl_exec($curl);
    $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $curlError = curl_error($curl);
    curl_close($curl);

    if ($response === false) {
        throw new RuntimeException("AI request failed: $curlError");
    }

    $data = json_decode($response, true);
    if ($status !== 200) {
        // The API explains the problem (bad key, no credit, overloaded...).
        $message = $data['error']['message'] ?? substr((string) $response, 0, 200);
        throw new RuntimeException("AI request failed (HTTP $status): $message");
    }

    $text = '';
    foreach ($data['content'] ?? [] as $block) {
        if (($block['type'] ?? '') === 'text') {
            $text .= $block['text'];
        }
    }
    if ($text === '') {
        throw new RuntimeException('AI reply was empty');
    }
    return $text;
}

/**
 * Ask the AI what a source page now says for each figure that couldn't be
 * found on it, then gate, and hold or publish, each answer.
 *
 * $source    the sources row
 * $pageText  the page as plain text, exactly as check-sources.php made it
 *            (numbers without thousands separators)
 * $facts     the facts rows that weren't found on the page
 *
 * Returns one result per fact, keyed by fact id:
 *   ['outcome' => applied | held | verified | not_found | skipped,
 *    'message' => one line for the cron output]
 *
 * With $dryRun, the AI is still asked (so you can see what it would do),
 * but nothing is written.
 */
function proposeUpdatesFromPage(PDO $pdo, array $source, string $pageText, array $facts, bool $dryRun): array
{
    $reply = callClaude(aiPageReadingInstructions(), aiPageReadingRequest($source, $pageText, $facts));
    $answers = parseAiAnswers($reply);

    $results = [];
    foreach ($facts as $fact) {
        $answer = $answers[$fact['fact_key']] ?? null;
        try {
            $results[$fact['id']] = handleAiAnswer($pdo, $source, $pageText, $fact, $answer, $dryRun);
        } catch (Throwable $e) {
            $results[$fact['id']] = ['outcome' => 'skipped', 'message' => 'not handled: ' . $e->getMessage()];
        }
    }
    return $results;
}

/** The standing instructions for reading a page. */
function aiPageReadingInstructions(): string
{
    return <<<'TXT'
You check figures for a UK personal finance website against official source pages (GOV.UK, HMRC, DWP and similar).

You will be given the plain text of one page, and a list of figures the website shows from that page which could no longer be found on it. For each figure, find what the page now states for exactly the same thing: the same allowance, rate or threshold, for the same people, and for the current or latest period the page covers.

Rules:
- Only use what the page states. Never calculate, convert, estimate or use outside knowledge.
- Thousands separators have been removed from numbers in the page text (12,570 appears as 12570). Copy numbers as they appear.
- "evidence" must be copied character for character from the page text: one sentence or table row, 10 to 300 characters, containing the value.
- If the page gives the figure for more than one period, use the latest one in effect, and give its effective date or tax year when the page states it.
- If the page doesn't clearly state the figure, or you are unsure it's the same thing, set "found" to false and say why in "note". A wrong answer is far worse than no answer.

Reply with JSON only, no other text, in this shape:
{"results": [{"fact_key": "...", "found": true, "value": "12570", "evidence": "...", "effective_from": "2026-04-06", "tax_year": "2026/27", "note": "..."}]}

"value" is the bare number: digits and at most one decimal point, no £, % or commas. "effective_from" (YYYY-MM-DD) and "tax_year" (e.g. 2026/27) are null unless the page states them for this figure.
TXT;
}

/** The request for one page: the figures to look for, then the page text. */
function aiPageReadingRequest(array $source, string $pageText, array $facts): string
{
    $lines = [];
    foreach ($facts as $fact) {
        $lines[] = '- fact_key: ' . $fact['fact_key']
            . "\n  label: " . $fact['label']
            . ($fact['context'] ? "\n  context: " . $fact['context'] : '')
            . "\n  value we show now: " . $fact['value'] . ($fact['unit'] ? ' ' . $fact['unit'] : '')
            . ($fact['tax_year'] ? "\n  tax year we show: " . $fact['tax_year'] : '')
            . ($fact['effective_from'] ? "\n  in effect from: " . $fact['effective_from'] : '');
    }

    // Very long pages are cut short. The figures are usually near the top.
    if (strlen($pageText) > AI_MAX_PAGE_CHARACTERS) {
        $pageText = substr($pageText, 0, AI_MAX_PAGE_CHARACTERS);
    }

    return "Page: {$source['publisher']}, {$source['url']}\n"
        . "Today's date: " . date('Y-m-d') . "\n\n"
        . "Figures not found on the page:\n" . implode("\n", $lines) . "\n\n"
        . "PAGE TEXT:\n" . $pageText;
}

/**
 * Turn the AI's reply into answers keyed by fact_key. Tolerates the JSON
 * being wrapped in ```json fences. Throws if it can't be read at all.
 */
function parseAiAnswers(string $reply): array
{
    $json = trim(preg_replace('/^```(?:json)?|```$/m', '', trim($reply)));
    $data = json_decode($json, true);
    if (!is_array($data) || !isset($data['results']) || !is_array($data['results'])) {
        throw new RuntimeException('AI reply was not in the expected format');
    }

    $answers = [];
    foreach ($data['results'] as $row) {
        if (is_array($row) && isset($row['fact_key'])) {
            $answers[(string) $row['fact_key']] = $row;
        }
    }
    return $answers;
}

/**
 * Gate one answer and act on it: publish, hold, mark verified, or leave
 * it for "Needs a look" (not found).
 */
function handleAiAnswer(PDO $pdo, array $source, string $pageText, array $fact, ?array $answer, bool $dryRun): array
{
    if ($answer === null) {
        return ['outcome' => 'not_found', 'message' => 'AI gave no answer for this figure'];
    }
    if (empty($answer['found'])) {
        $note = trim((string) ($answer['note'] ?? ''));
        return ['outcome' => 'not_found', 'message' => 'AI: not stated on the page' . ($note ? " ($note)" : '')];
    }

    // --- Tidy and check the answer's own format. ---
    $value = trim((string) ($answer['value'] ?? ''));
    $evidence = trim(preg_replace('/\s+/u', ' ', (string) ($answer['evidence'] ?? '')));
    $effectiveFrom = $answer['effective_from'] ?? null;
    $taxYear = $answer['tax_year'] ?? null;

    if (!preg_match('/^\d+(\.\d+)?$/', $value)) {
        return ['outcome' => 'not_found', 'message' => "AI gave an unusable value '$value'"];
    }
    // Dates and tax years in the wrong format are dropped rather than trusted.
    $effectiveFrom = is_string($effectiveFrom) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $effectiveFrom)
        && strtotime($effectiveFrom) !== false ? $effectiveFrom : null;
    $taxYear = is_string($taxYear) && preg_match('#^\d{4}/\d{2}$#', $taxYear) ? $taxYear : null;

    $newNumber = (float) $value;
    $oldNumber = $fact['value_numeric'] !== null ? (float) $fact['value_numeric'] : null;

    // --- The gates. ---
    $gateAllowlist = (int) $source['is_allowlisted'] === 1;
    $gateVerbatim = evidenceIsOnPage($evidence, $pageText) && valueIsInEvidence($value, $evidence);
    $gateInEffect = $effectiveFrom === null || $effectiveFrom <= date('Y-m-d');

    if ($oldNumber !== null && abs($newNumber - $oldNumber) < 0.00001) {
        // The same figure, just written differently from what the simple
        // check looks for. If the passage is genuinely on the page, the
        // figure is confirmed.
        if (!$gateVerbatim) {
            return ['outcome' => 'not_found', 'message' => 'AI found the same value, but its quote isn\'t on the page'];
        }
        if (!$dryRun) {
            markFactVerified($pdo, (int) $fact['id']);
        }
        return ['outcome' => 'verified', 'message' => "AI: still $value (\"" . shortEvidence($evidence) . '")'];
    }

    // Change size, as a fraction of the old value. Unknown when there was
    // no old number (or it was 0), in which case it can't publish alone.
    $gateThreshold = $oldNumber !== null && $oldNumber != 0.0
        && abs($newNumber - $oldNumber) / abs($oldNumber) <= AI_MAX_CHANGE_RATIO;

    // --- Decide: publish only if every gate passed and auto-apply is on. ---
    $reasons = [];
    if (!$gateAllowlist) {
        $reasons[] = 'source isn\'t allowlisted';
    }
    if (!$gateVerbatim) {
        $reasons[] = 'quoted passage not found word for word on the page';
    }
    if (!$gateThreshold) {
        $reasons[] = $oldNumber ? 'change of ' . round(abs($newNumber - $oldNumber) / abs($oldNumber) * 100) . '% is larger than '
            . round(AI_MAX_CHANGE_RATIO * 100) . '%' : 'no previous number to compare with';
    }
    if (!$gateInEffect) {
        $reasons[] = "takes effect on $effectiveFrom, so approve it on or after that date";
    }
    if (!$reasons && !AI_AUTO_APPLY) {
        $reasons[] = 'trial mode: every AI proposal is reviewed (passed all checks)';
    }

    $publish = !$reasons;
    $summary = "AI: {$fact['value']} → $value" . ($taxYear ? " ($taxYear)" : '');

    if ($dryRun) {
        return [
            'outcome' => $publish ? 'applied' : 'held',
            'message' => $summary . ($publish ? ', would publish' : ', would hold: ' . implode('; ', $reasons))
                . ' ("' . shortEvidence($evidence) . '")',
        ];
    }

    $changeRow = [
        'fact_id'        => (int) $fact['id'],
        'value'          => $value,
        'numeric'        => $value,
        'effective_from' => $effectiveFrom,
        'tax_year'       => $taxYear,
        'source_id'      => (int) $source['id'],
        'evidence'       => substr($evidence, 0, 2000),
        'gate_allowlist' => (int) $gateAllowlist,
        'gate_verbatim'  => (int) $gateVerbatim,
        'gate_threshold' => (int) $gateThreshold,
    ];

    if ($publish) {
        withTransaction($pdo, function () use ($pdo, $changeRow) {
            $changeId = insertAiChange($pdo, $changeRow, 'applied', 'Passed all checks, published automatically');
            updateFactValue($pdo, $changeRow['fact_id'], $changeRow['value'], [
                'change_source'  => 'pipeline',
                'fact_change_id' => $changeId,
                'effective_from' => $changeRow['effective_from'],
                'tax_year'       => $changeRow['tax_year'],
                'note'           => 'Read from the source page by AI, all checks passed',
            ]);
            // The new figure is now the one on the page, so it leaves
            // "Needs a look".
            markFactVerified($pdo, $changeRow['fact_id']);
        });
        return ['outcome' => 'applied', 'message' => "$summary, published"];
    }

    $added = holdAiChange($pdo, $changeRow, 'AI proposal held: ' . implode('; ', $reasons));
    return ['outcome' => 'held', 'message' => $summary . ($added ? ', held for review: ' : ', already waiting for review: ')
        . implode('; ', $reasons)];
}

/**
 * Hold a proposal on the review page. If the same value is already
 * waiting, nothing is added (it would otherwise reappear every week).
 * An older AI proposal for the same fact with a different value is
 * marked superseded, so only the latest reading is shown.
 * Returns false if it was already waiting.
 */
function holdAiChange(PDO $pdo, array $changeRow, string $reason): bool
{
    $stmt = $pdo->prepare(
        "SELECT 1 FROM fact_changes WHERE fact_id = :fid AND status = 'held' AND proposed_value = :value"
    );
    $stmt->execute(['fid' => $changeRow['fact_id'], 'value' => $changeRow['value']]);
    if ($stmt->fetchColumn()) {
        return false;
    }

    withTransaction($pdo, function () use ($pdo, $changeRow, $reason) {
        $pdo->prepare(
            "UPDATE fact_changes SET status = 'superseded', decided_at = NOW()
             WHERE fact_id = :fid AND status = 'held' AND extraction_method = 'ai'"
        )->execute(['fid' => $changeRow['fact_id']]);

        insertAiChange($pdo, $changeRow, 'held', $reason);
    });
    return true;
}

/** Write one AI proposal to fact_changes. Returns its id. */
function insertAiChange(PDO $pdo, array $changeRow, string $status, string $reason): int
{
    $pdo->prepare(
        "INSERT INTO fact_changes
            (change_type, fact_id, proposed_value, proposed_value_numeric, effective_from, tax_year,
             source_id, evidence_snippet, extraction_method, gate_allowlist, gate_verbatim,
             gate_threshold, status, status_reason, decided_at, applied_at)
         VALUES ('update', :fid, :value, :num, :effective_from, :tax_year,
             :src, :evidence, 'ai', :g_allow, :g_verbatim,
             :g_threshold, :status, :reason, :decided_at, :applied_at)"
    )->execute([
        'fid'            => $changeRow['fact_id'],
        'value'          => $changeRow['value'],
        'num'            => $changeRow['numeric'],
        'effective_from' => $changeRow['effective_from'],
        'tax_year'       => $changeRow['tax_year'],
        'src'            => $changeRow['source_id'],
        'evidence'       => $changeRow['evidence'],
        'g_allow'        => $changeRow['gate_allowlist'],
        'g_verbatim'     => $changeRow['gate_verbatim'],
        'g_threshold'    => $changeRow['gate_threshold'],
        'status'         => $status,
        'reason'         => substr($reason, 0, 500),
        // Applied changes are decided and applied now; held ones wait.
        'decided_at'     => $status === 'applied' ? date('Y-m-d H:i:s') : null,
        'applied_at'     => $status === 'applied' ? date('Y-m-d H:i:s') : null,
    ]);
    return (int) $pdo->lastInsertId();
}

/**
 * The verbatim gate: is the AI's quoted passage really on the page?
 * Both are compared after the same tidying (lower case, single spaces,
 * curly quotes and dashes made plain), so only real differences fail.
 * Short quotes are refused, since a few characters match almost anywhere.
 */
function evidenceIsOnPage(string $evidence, string $pageText): bool
{
    $needle = normaliseForMatch($evidence);
    if (strlen($needle) < 10) {
        return false;
    }
    return strpos(normaliseForMatch($pageText), $needle) !== false;
}

/** The proposed value appears as a whole number inside the quoted passage. */
function valueIsInEvidence(string $value, string $evidence): bool
{
    $candidates = [$value];
    if (strpos($value, '.') !== false) {
        $candidates[] = rtrim(rtrim($value, '0'), '.'); // 3.50 -> 3.5
    }
    $evidence = normaliseForMatch($evidence);
    foreach ($candidates as $candidate) {
        if ($candidate !== '' && preg_match('/(?<![\d.])' . preg_quote($candidate, '/') . '(?!\d|\.\d)/', $evidence)) {
            return true;
        }
    }
    return false;
}

/**
 * Tidy text for the verbatim comparison: plain quotes and dashes, no
 * thousands separators, single spaces, lower case.
 */
function normaliseForMatch(string $text): string
{
    $text = str_replace(
        ["\u{2018}", "\u{2019}", "\u{201C}", "\u{201D}", "\u{2013}", "\u{2014}", "\u{00A0}"],
        ["'", "'", '"', '"', '-', '-', ' '],
        $text
    );
    do {
        $before = $text;
        $text = preg_replace('/(\d),(\d{3})(?!\d)/', '$1$2', $text);
    } while ($text !== $before);

    return strtolower(trim(preg_replace('/\s+/u', ' ', $text)));
}

/** The first 120 characters of a quote, for the one-line cron output. */
function shortEvidence(string $evidence): string
{
    return strlen($evidence) > 120 ? substr($evidence, 0, 117) . '...' : $evidence;
}
