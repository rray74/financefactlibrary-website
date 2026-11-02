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
// COUNTRY_WORDING (each country's adjective and official sources) lives in
// functions.php. Nothing in that file runs on load (no sessions, no output),
// so it is safe to load from the cron scripts too.
require_once __DIR__ . '/functions.php';

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

// ------------------------------------------------------------
// Which country is a figure for?
//
// The prompt names the country and its official sources, and tax years are
// written and validated the country's way, so the AI step has to know
// whose figure it is looking at. Before the site had a second country this
// was simply "the UK".
// ------------------------------------------------------------

/**
 * The COUNTRY a jurisdiction belongs to, as its jurisdictions row
 * (id, parent_id, code, name). A sub-region such as Scotland belongs to its
 * parent, the UK. Null if the id is empty or unknown.
 *
 * Cached for the run: a source check asks this for every figure.
 */
function aiCountryForJurisdictionId(PDO $pdo, ?int $jurisdictionId): ?array
{
    static $cache = [];
    if (!$jurisdictionId) {
        return null;
    }
    if (array_key_exists($jurisdictionId, $cache)) {
        return $cache[$jurisdictionId];
    }

    $stmt = $pdo->prepare('SELECT id, parent_id, code, name FROM jurisdictions WHERE id = :id');
    $row = null;
    $id = $jurisdictionId;
    // A country has no parent and a sub-region has one (the country). The
    // loop is capped so a broken hierarchy can never run away.
    for ($depth = 0; $depth < 5 && $id; $depth++) {
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch() ?: null;
        if ($row === null || $row['parent_id'] === null) {
            break;
        }
        $id = (int) $row['parent_id'];
    }
    if ($row !== null && $row['parent_id'] !== null) {
        $row = null; // never reached a top-level country
    }

    return $cache[$jurisdictionId] = $row;
}

/** The country code ('GB' for Scotland's figures too) for a jurisdiction id, or null. */
function aiCountryCodeForJurisdictionId(PDO $pdo, ?int $jurisdictionId): ?string
{
    $country = aiCountryForJurisdictionId($pdo, $jurisdictionId);
    return $country ? $country['code'] : null;
}

/**
 * Turn a tax year the AI read off a page into the form the database stores.
 *
 *   countries whose tax year doesn't start on 1 January (UK, Australia,
 *   New Zealand): 2026/27, the same for all of them
 *   countries whose tax year is the calendar year (US, Canada): 2026
 *
 * It accepts the ways pages write the year: 2026/27, 2026-27, 2026–27
 * (en dash), 2026/2027. Anything where the second year isn't the year after
 * the first (2026/29) is rejected, as is a bare "2026" for a country whose
 * year spans two calendar years, because the page could mean either end.
 * Returns null when the value can't be trusted: no tax year is safer than
 * a wrong one.
 *
 * How a country WRITES the year (Australia's en dash, as in 2026–27) is
 * applied when a page is shown, by formatTaxYear() in placeholders.php, so
 * the stored form is the same for every two-year country.
 *
 * With no country code the UK rule applies, which is what every figure got
 * before there was a second country.
 *
 * @param mixed $raw whatever the AI put in "tax_year"
 */
function aiNormaliseTaxYear($raw, ?string $countryCode): ?string
{
    if (!is_string($raw)) {
        return null;
    }
    $raw = trim($raw);
    $style = $countryCode === null ? 'slash' : (COUNTRY_TAX_YEARS[$countryCode]['style'] ?? 'single');

    if ($style === 'single') {
        return preg_match('/^\d{4}$/', $raw) ? $raw : null;
    }

    // Start year, a slash / hyphen / en dash / em dash, then the end year as
    // two or four digits.
    if (!preg_match('/^(\d{4})\s*[\/\-\x{2013}\x{2014}]\s*(\d{2}|\d{4})$/u', $raw, $m)) {
        return null;
    }
    $start = (int) $m[1];
    $end = (int) $m[2];
    $endMatches = strlen($m[2]) === 4 ? $end === $start + 1 : $end === ($start + 1) % 100;
    if (!$endMatches) {
        return null;
    }
    return sprintf('%d/%02d', $start, ($start + 1) % 100);
}

/**
 * The first 150 characters of an AI reply, on one line, for error
 * messages, so a reply that can't be read shows what came back. Used by
 * the AI drafts and the AI text check.
 */
function aiReplyStart(string $reply): string
{
    $flat = trim(preg_replace('/\s+/u', ' ', $reply));
    return preg_match('/^.{0,150}/su', $flat, $m) ? $m[0] : substr($flat, 0, 150);
}

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
 * Optional request settings the API may refuse for some models. Each starts
 * as accepted; one the API rejects (an HTTP 400 that names it) is dropped, the
 * request is sent again without it, and the refusal is remembered for the rest
 * of the request. Names used: 'thinking' ("thinking: disabled"), 'effort'
 * (output_config effort "low").
 */
function claudeOptionAccepted(string $name, ?bool $set = null): bool
{
    static $accepted = [];
    if ($set !== null) {
        $accepted[$name] = $set;
    }
    return $accepted[$name] ?? true;
}

/**
 * Whether the "thinking: disabled" setting is still being sent. The model
 * reasons by default, and those reasoning tokens count against max_tokens: a
 * page with long sources made it reason for thousands of tokens, leaving too
 * few to write with (drafts cut off after ~700 words), and with a ceiling of
 * 6,000 it spent every token reasoning and wrote nothing. These jobs are
 * structured writing from supplied text, which doesn't need it. False means
 * the API refused the setting for this model.
 */
function claudeThinkingParamAccepted(?bool $set = null): bool
{
    return claudeOptionAccepted('thinking', $set);
}

/**
 * How the most recent successful callClaude() ended: ['stop_reason' =>
 * 'end_turn' (finished normally) or 'max_tokens' (cut off at the limit),
 * 'input_tokens' => ..., 'output_tokens' => ..., 'blocks' => the kinds of
 * content in the reply, e.g. "thinking,text", 'model' => the model that
 * answered, 'thinking_switch' => 'accepted' or 'refused']. Lets a caller tell
 * a reply that was cut off from one that was merely badly formed. Pass an
 * array to set it (callClaude() does).
 */
function claudeLastCallInfo(?array $set = null): array
{
    static $info = [];
    if ($set !== null) {
        $info = $set;
    }
    return $info;
}

/**
 * The tokens every callClaude() has used since the ledger was last reset, per
 * model: ['model' => ['calls', 'input', 'output', 'cache_read', 'cache_write']].
 * 'input' is what the API reports as input tokens (not counting cached ones).
 * The background jobs reset it at the start of a page and print it at the end
 * (claudeUsageText()), so the cost of a page can be seen in its Details line
 * rather than guessed from the Anthropic usage page. Every successful (HTTP 200)
 * reply is counted, including empty ones, which are billed too.
 *
 * $add    one call's usage: ['model', 'input', 'output', 'cache_read', 'cache_write']
 * $reset  empty the ledger first
 */
function claudeUsageLedger(?array $add = null, bool $reset = false): array
{
    static $ledger = [];
    if ($reset) {
        $ledger = [];
    }
    if ($add !== null) {
        $row = $ledger[$add['model']] ?? ['calls' => 0, 'input' => 0, 'output' => 0, 'cache_read' => 0, 'cache_write' => 0];
        $row['calls']++;
        foreach (['input', 'output', 'cache_read', 'cache_write'] as $key) {
            $row[$key] += (int) ($add[$key] ?? 0);
        }
        $ledger[$add['model']] = $row;
    }
    return $ledger;
}

/**
 * Prices per million tokens for a model, [input, output], from AI_PRICES in
 * config/database.local.php, or NULL if none is set for it. AI_PRICES maps the
 * start of a model name to its prices, for example:
 *   define('AI_PRICES', ['claude-sonnet-4-6' => [3.00, 15.00], 'claude-sonnet-5-5' => [3.00, 15.00]]);
 * The prices are NOT built in, because they change and are yours to check on
 * Anthropic's pricing page. Without them the usage line shows tokens only.
 */
function claudeModelPrice(string $model): ?array
{
    if (!defined('AI_PRICES') || !is_array(AI_PRICES)) {
        return null;
    }
    foreach (AI_PRICES as $prefix => $prices) {
        if (strpos($model, (string) $prefix) === 0 && is_array($prices) && count($prices) >= 2) {
            return [(float) $prices[0], (float) $prices[1]];
        }
    }
    return null;
}

/**
 * The ledger as one sentence for a Details line or a job note, e.g.
 * "AI use: claude-sonnet-4-6: 6 calls, 148,200 tokens read, 7,100 written;
 * claude-sonnet-5-5: 3 calls, 121,000 read, 2,400 written." With AI_PRICES set
 * for every model used, it ends with an estimated cost. Returns '' if no
 * calls were made.
 */
function claudeUsageText(array $ledger): string
{
    if (!$ledger) {
        return '';
    }
    $parts = [];
    $cost = 0.0;
    $allPriced = true;
    foreach ($ledger as $model => $row) {
        $read = $row['input'] + $row['cache_read'] + $row['cache_write'];
        $parts[] = "$model: {$row['calls']} call" . ($row['calls'] === 1 ? '' : 's') . ', '
            . number_format($read) . ' tokens read, ' . number_format($row['output']) . ' written';
        $price = claudeModelPrice((string) $model);
        if ($price === null) {
            $allPriced = false;
            continue;
        }
        // Cached input is read at a tenth of the price and written at a quarter more. The
        // site does not ask for caching, so these are normally zero.
        $cost += ($row['input'] + $row['cache_write'] * 1.25 + $row['cache_read'] * 0.1) / 1000000 * $price[0]
            + $row['output'] / 1000000 * $price[1];
    }
    $text = 'AI use: ' . implode('; ', $parts) . '.';
    if ($allPriced && $cost > 0) {
        $text .= ' Estimated cost about $' . number_format($cost, 2) . ' at the prices in AI_PRICES.';
    }
    return $text;
}

/**
 * Send one request to the API. Returns [HTTP status, response body, curl
 * error]. Kept apart from callClaude() so that function's handling of
 * replies (and its retries) can be tested without calling the API.
 */
function claudeTransport(string $body): array
{
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
    return [$status, $response, $curlError];
}

/**
 * Ask the AI one question and return its text reply.
 *
 * $model           the model to use (default: ANTHROPIC_MODEL). Drafting passes
 *                  AI_DRAFT_MODEL when that is set.
 * $lowEffort       ask for low effort (output_config), which for models that
 *                  reason makes them reason and write less. Drafting uses it;
 *                  the checks, where thoroughness matters, don't.
 * $retryMaxTokens  if the reply is EMPTY because the model spent the whole
 *                  ceiling reasoning, ask once more with this larger ceiling.
 *                  0 (the default) means don't: the editor has limited time.
 *
 * Every request also says "no reasoning" (see claudeThinkingParamAccepted()).
 * If the API refuses that or the effort setting, the request is sent again
 * without it, and the refusal is remembered.
 *
 * An EMPTY reply (the API answers normally but sends no text) is tried once
 * more, since it is usually a one-off. It is not retried when the AI declined
 * (stop reason "refusal") or ran out of room ("max_tokens"), since asking
 * again wouldn't change that, nor when the first try was slow, since a second
 * could outlast the time a page allows. If it is still empty, the error says
 * why: the stop reason, how many tokens went in and came out, what the reply
 * held, the model, and whether the "no reasoning" setting was accepted.
 *
 * $transport sends the request body (default: claudeTransport()); tests
 * replace it.
 */
function callClaude(
    string $system,
    string $userText,
    int $maxTokens = 4000,
    ?callable $transport = null,
    ?string $model = null,
    bool $lowEffort = false,
    int $retryMaxTokens = 0
): string {
    $transport ??= 'claudeTransport';
    $build = function () use (&$maxTokens, $system, $userText, $model, $lowEffort): string {
        $request = ['model' => $model ?? ANTHROPIC_MODEL, 'max_tokens' => $maxTokens];
        if (claudeOptionAccepted('thinking')) {
            $request['thinking'] = ['type' => 'disabled'];
        }
        if ($lowEffort && claudeOptionAccepted('effort')) {
            $request['output_config'] = ['effort' => 'low'];
        }
        $request['system'] = $system;
        $request['messages'] = [['role' => 'user', 'content' => $userText]];
        return json_encode($request, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    };
    $body = $build();

    $started = microtime(true);
    $retriedWithMoreRoom = false;
    for ($attempt = 1; ; $attempt++) {
        [$status, $response, $curlError] = $transport($body);

        if ($response === false) {
            throw new RuntimeException("AI request failed: $curlError");
        }

        $data = json_decode($response, true);
        if ($status !== 200) {
            // The API explains the problem (bad key, no credit, overloaded...).
            $message = $data['error']['message'] ?? substr((string) $response, 0, 200);
            // A setting this model doesn't accept: drop it, remember, send again.
            if ($status === 400) {
                if (claudeOptionAccepted('thinking') && stripos((string) $message, 'thinking') !== false) {
                    claudeOptionAccepted('thinking', false);
                    $body = $build();
                    $attempt--; // not a retry of an empty reply
                    continue;
                }
                if (claudeOptionAccepted('effort') && $lowEffort
                    && (stripos((string) $message, 'effort') !== false || stripos((string) $message, 'output_config') !== false)) {
                    claudeOptionAccepted('effort', false);
                    $body = $build();
                    $attempt--;
                    continue;
                }
            }
            throw new RuntimeException("AI request failed (HTTP $status): $message");
        }

        // Billed whether or not the reply has any text in it.
        claudeUsageLedger([
            'model'       => (string) ($data['model'] ?? ($model ?? ANTHROPIC_MODEL)),
            'input'       => (int) ($data['usage']['input_tokens'] ?? 0),
            'output'      => (int) ($data['usage']['output_tokens'] ?? 0),
            'cache_read'  => (int) ($data['usage']['cache_read_input_tokens'] ?? 0),
            'cache_write' => (int) ($data['usage']['cache_creation_input_tokens'] ?? 0),
        ]);

        $text = '';
        $kinds = [];
        foreach ($data['content'] ?? [] as $block) {
            $kinds[] = (string) ($block['type'] ?? '?');
            if (($block['type'] ?? '') === 'text') {
                $text .= $block['text'];
            }
        }
        $blocks = implode(',', array_unique($kinds));
        $answeredBy = (string) ($data['model'] ?? ($model ?? ANTHROPIC_MODEL));
        $thinkingSwitch = claudeOptionAccepted('thinking') ? 'accepted' : 'refused';

        if ($text !== '') {
            claudeLastCallInfo([
                'stop_reason'     => (string) ($data['stop_reason'] ?? ''),
                'input_tokens'    => (int) ($data['usage']['input_tokens'] ?? 0),
                'output_tokens'   => (int) ($data['usage']['output_tokens'] ?? 0),
                'blocks'          => $blocks,
                'model'           => $answeredBy,
                'thinking_switch' => $thinkingSwitch,
            ]);
            return $text;
        }

        // Empty. Say why: the stop reason and the token counts.
        $stop = (string) ($data['stop_reason'] ?? 'unknown');
        $usage = $data['usage'] ?? [];
        $detail = "stop reason: $stop, " . number_format((int) ($usage['input_tokens'] ?? 0)) . ' tokens in, '
            . number_format((int) ($usage['output_tokens'] ?? 0)) . ' tokens out'
            . ($blocks !== '' ? ", reply held: $blocks" : ', reply held nothing')
            . ", model: $answeredBy, no-reasoning setting: $thinkingSwitch";

        $allRoomSpentReasoning = $stop === 'max_tokens' && stripos($blocks, 'thinking') !== false;

        // All the room went on reasoning: ask once more with more room, if allowed.
        if ($allRoomSpentReasoning && $retryMaxTokens > $maxTokens && !$retriedWithMoreRoom) {
            $retriedWithMoreRoom = true;
            $maxTokens = $retryMaxTokens;
            $body = $build();
            continue;
        }

        if ($attempt === 1 && !in_array($stop, ['refusal', 'max_tokens'], true) && microtime(true) - $started < 60) {
            sleep(2);
            continue;
        }

        throw new RuntimeException('AI reply was empty (' . $detail . ')' . ($attempt > 1 ? ', even after trying again.' : '.')
            . ($stop === 'refusal' ? ' The AI declined to answer.' : '')
            . ($stop === 'max_tokens'
                ? ($allRoomSpentReasoning
                    ? ' All its room went on reasoning before it wrote anything.'
                    : ' It ran out of room before writing anything.')
                : ''));
    }
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
    // Whose rules is this page about? The country of the figures that cite
    // it; failing that, the source's own jurisdiction. With neither, the
    // prompt is worded without a country rather than guessing the UK.
    $firstFact = $facts[0] ?? [];
    $country = aiCountryForJurisdictionId(
        $pdo,
        !empty($firstFact['jurisdiction_id']) ? (int) $firstFact['jurisdiction_id']
            : (!empty($source['jurisdiction_id']) ? (int) $source['jurisdiction_id'] : null)
    );

    $reply = callClaude(aiPageReadingInstructions($country), aiPageReadingRequest($source, $pageText, $facts));
    $answers = parseAiAnswers($reply);

    $results = [];
    foreach ($facts as $fact) {
        $answer = $answers[$fact['fact_key']] ?? null;
        try {
            // Each figure's own country decides how its tax year is read.
            $factCountryCode = aiCountryCodeForJurisdictionId($pdo, (int) ($fact['jurisdiction_id'] ?? 0));
            $results[$fact['id']] = handleAiAnswer($pdo, $source, $pageText, $fact, $answer, $dryRun, $factCountryCode);
        } catch (Throwable $e) {
            $results[$fact['id']] = ['outcome' => 'skipped', 'message' => 'not handled: ' . $e->getMessage()];
        }
    }
    return $results;
}

/**
 * The standing instructions for reading a page.
 *
 * $country is the jurisdictions row of the country the figures belong to
 * (see aiCountryForJurisdictionId()). It sets three things: how the website
 * is described ("an Australian personal finance website"), which official
 * sources are named, and the example date and tax year, which are written
 * the way that country writes them. With null the instructions name no
 * country at all.
 *
 * For the UK the text matches the original wording, except that the sources
 * now come from COUNTRY_WORDING (GOV.UK, HMRC, the Scottish and Welsh
 * governments and NS&I) rather than a hard-coded "GOV.UK, HMRC, DWP".
 */
function aiPageReadingInstructions(?array $country = null): string
{
    $code = $country['code'] ?? null;
    $adjective = $country ? countryAdjective($country) : '';
    $sources = $country ? countrySourceExamples($country) : '';

    $site = trim($adjective . ' personal finance website');
    $official = $sources !== '' ? "official source pages ($sources and similar)" : 'official source pages';

    // The example date is the start of that country's tax year in 2026, and
    // the example tax year is written the country's way. Both show the AI
    // the shape of a good answer. (No country: the UK's, as before.)
    $rule = COUNTRY_TAX_YEARS[$code ?? 'GB'] ?? ['month' => 1, 'day' => 1];
    $exampleDate = sprintf('2026-%02d-%02d', $rule['month'], $rule['day']);
    $exampleTaxYear = formatTaxYear(2026, $code ?? 'GB');

    $template = <<<'TXT'
You check figures for a [[SITE]] against [[OFFICIAL]].

You will be given the plain text of one page, and a list of figures the website shows from that page which could no longer be found on it. For each figure, find what the page now states for exactly the same thing: the same allowance, rate or threshold, for the same people, and for the current or latest period the page covers.

Rules:
- Only use what the page states. Never calculate, convert, estimate or use outside knowledge.
- Thousands separators have been removed from numbers in the page text (12,570 appears as 12570). Copy numbers as they appear.
- "evidence" must be copied character for character from the page text: one sentence or table row, 10 to 300 characters, containing the value.
- If the page gives the figure for more than one period, use the latest one in effect, and give its effective date or tax year when the page states it.
- If the page doesn't clearly state the figure, or you are unsure it's the same thing, set "found" to false and say why in "note". A wrong answer is far worse than no answer.

Reply with JSON only, no other text, in this shape:
{"results": [{"fact_key": "...", "found": true, "value": "12570", "evidence": "...", "effective_from": "[[DATE]]", "tax_year": "[[TAXYEAR]]", "note": "..."}]}

"value" is the bare number: digits and at most one decimal point, no currency symbol, % or commas. "effective_from" (YYYY-MM-DD) and "tax_year" (e.g. [[TAXYEAR]]) are null unless the page states them for this figure.
TXT;

    return strtr($template, [
        '[[SITE]]'     => $site,
        '[[OFFICIAL]]' => $official,
        '[[DATE]]'     => $exampleDate,
        '[[TAXYEAR]]'  => $exampleTaxYear,
    ]);
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
function handleAiAnswer(PDO $pdo, array $source, string $pageText, array $fact, ?array $answer, bool $dryRun, ?string $countryCode = null): array
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
    // The tax year is read the way the figure's country writes it and stored
    // in one form (see aiNormaliseTaxYear()). Before this, only the UK form
    // 2026/27 survived, so an Australian 2026–27 was silently dropped.
    $taxYear = aiNormaliseTaxYear($taxYear, $countryCode);

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
