<?php
/**
 * The coverage pass: before a page is drafted, read the official source pages
 * and list what the page has to handle, so the writer drafts against a list
 * instead of choosing from memory of what matters.
 *
 * Why it exists: on the Council Tax Bands test the points that cost the most
 * polish rounds were ones the sources state and the first draft left out or
 * got wrong (how a band is removed, the HMO form, the appeal route). The AI
 * check finds them AFTER the draft; this lists them BEFORE it.
 *
 * What it produces: a list of items, each with an id (C1, C2...), a priority
 * ("must" or "if_room"), a kind, one sentence saying the point, and the
 * source it comes from. The list is used in two places:
 *   - the writer (ai-drafts.php) is told to account for every "must" item in
 *     its plan: a section that covers it, or a stated reason for leaving it out
 *     (usually "the figure card shows it");
 *   - the polish rewrite (ai-polish.php) uses it to decide where a missing
 *     point belongs, and not to delete the only place a "must" item is handled.
 *
 * What it does NOT do: the AI check never sees this list. The check stays an
 * independent judge of the text against the sources, rather than marking the
 * writer's own homework.
 *
 * It runs only in the background paths (the nightly job and the polish queue),
 * not in the editor's "Draft this page" button, which has a web request's time
 * limit. A failure here is never fatal: the page is drafted without a list.
 *
 * Nothing here is stored. The list lives for the length of one job.
 */

require_once __DIR__ . '/ai-proposer.php'; // callClaude()

/** Most tokens the coverage reply may use. The list is short; this leaves room. */
const COVERAGE_MAX_TOKENS = 4000;

/** Most items kept from the reply, and most of them that can be "must". */
const COVERAGE_MAX_ITEMS = 25;
const COVERAGE_MAX_MUST = 12;

/** The kinds of item, as the AI is asked to label them. */
const COVERAGE_KINDS = ['rule', 'exception', 'removal_route', 'appeal_route', 'regional', 'worked_example'];

/** The standing instructions for the coverage pass. */
function coverageInstructions(): string
{
    return <<<'TXT'
You read the official source pages behind a page on Finance Fact Library, a personal finance reference site, and list what the page has to handle. You do NOT write the page. Someone else writes it from your list, so the list must be complete on the points that matter and short on everything else.

The page shows its figures as cards (the amounts, rates and thresholds), so leave those out. List the points a reader gets wrong or cannot see from the figures:
- rules and conditions that decide who or what is covered, and what does not count
- exceptions, special cases and limits
- how something is added, removed, claimed, reported, reviewed or ended (the route: who, in what circumstances)
- routes of appeal, review or challenge
- differences for Scotland, Wales, Northern Ireland, or a state, province or region, where the sources give them
- the sources' own worked examples (name the example in one line; the writer will use placeholders for figures)

Leave out: background and history, step-by-step how to apply, contact details, forms to download unless a form is itself the rule (for example a form that must be used to claim a reduction), and anything the figure cards already state.

Each item is ONE short sentence in your own words saying the point. Never copy a source sentence. Name the source it comes from, using the source's name as given. State nothing the sources do not say. If a source could not be read, list nothing that depends on it. In the source text, thousands separators have been removed (12570 means 12,570).

Priority:
- "must": a reader would be misled, miss out, or be charged or paid wrongly if the page left it out.
- "if_room": useful, but the page can be right without it.
Use "must" sparingly: at most 12 items, and at most 25 items in all. Put the "must" items first.

Reply with JSON only, no other text. The kind is one of: rule, exception, removal_route, appeal_route, regional, worked_example.
{"items": [{"priority": "must", "kind": "exception", "point": "...", "source": "..."}]}
TXT;
}

/**
 * The request for the coverage pass: the page, its figures (so the AI knows
 * what the cards already show), and the source pages.
 *
 * $sourceBlock  the sources as the writer is shown them (requestSourceBlock())
 */
function coverageRequest(array $subject, array $facts, string $sourceBlock): string
{
    $figures = [];
    foreach ($facts as $fact) {
        $line = '- ' . $fact['label'] . ': ' . formatFactValue($fact);
        if (isRegionalFact($fact)) {
            $line .= ' (applies in ' . $fact['jurisdiction_name'] . ')';
        }
        $figures[] = $line;
    }

    return "Page: {$subject['name']}\n"
        . "Country: {$subject['country_name']}\n"
        . "Section: {$subject['category_name']} > {$subject['subcategory_name']}\n"
        . "\nFigures shown as cards on the page (do not list these):\n" . implode("\n", $figures) . "\n"
        . "\nSource pages:\n" . ($sourceBlock !== '' ? $sourceBlock : "(none: the figures cite no readable source page)\n");
}

/**
 * Read the AI's reply into a clean list. Items with no point, an unknown
 * priority or an unknown kind are dropped or repaired; ids are given here
 * (C1, C2...) in the order kept, "must" items first.
 *
 * Returns a list of ['id', 'priority', 'kind', 'point', 'source'].
 */
function parseCoverage(string $reply): array
{
    $start = strpos($reply, '{');
    $end = strrpos($reply, '}');
    $data = ($start !== false && $end > $start) ? json_decode(substr($reply, $start, $end - $start + 1), true) : null;
    if (!is_array($data) || !isset($data['items']) || !is_array($data['items'])) {
        throw new RuntimeException('The coverage reply was not in the expected format. It began: "' . aiReplyStart($reply) . '"');
    }

    $must = [];
    $other = [];
    foreach ($data['items'] as $item) {
        $point = trim(preg_replace('/\s+/u', ' ', (string) ($item['point'] ?? '')));
        if ($point === '') {
            continue;
        }
        $kind = (string) ($item['kind'] ?? 'rule');
        $row = [
            'priority' => (($item['priority'] ?? '') === 'must') ? 'must' : 'if_room',
            'kind'     => in_array($kind, COVERAGE_KINDS, true) ? $kind : 'rule',
            'point'    => $point,
            'source'   => trim((string) ($item['source'] ?? '')),
        ];
        if ($row['priority'] === 'must' && count($must) < COVERAGE_MAX_MUST) {
            $must[] = $row;
        } else {
            $row['priority'] = 'if_room'; // past the cap, a "must" is demoted
            $other[] = $row;
        }
    }

    $items = [];
    foreach (array_slice(array_merge($must, $other), 0, COVERAGE_MAX_ITEMS) as $i => $row) {
        $items[] = ['id' => 'C' . ($i + 1)] + $row;
    }
    return $items;
}

/**
 * Run the coverage pass. Never throws: if it fails, the page is drafted
 * without a list and the note says why.
 *
 * Returns:
 *   items   the list from parseCoverage() (empty if it failed or found nothing)
 *   tokens  tokens the reply used
 *   note    one sentence for the draft's details line
 */
function buildCoverage(array $subject, array $published, string $sourceBlock): array
{
    if (trim($sourceBlock) === '') {
        return ['items' => [], 'tokens' => 0, 'note' => 'No coverage list: the figures cite no readable source page.'];
    }
    try {
        // The model is the site's usual one (the same as the AI check): this is
        // reading and judging, not drafting, so it does not use AI_DRAFT_MODEL.
        $reply = callClaude(coverageInstructions(), coverageRequest($subject, $published, $sourceBlock), COVERAGE_MAX_TOKENS);
        $tokens = (int) (claudeLastCallInfo()['output_tokens'] ?? 0);
        $items = parseCoverage($reply);
    } catch (Throwable $e) {
        return ['items' => [], 'tokens' => 0, 'note' => 'No coverage list (' . $e->getMessage() . '), so the page was drafted without one.'];
    }

    $must = count(array_filter($items, fn($item) => $item['priority'] === 'must'));
    return [
        'items'  => $items,
        'tokens' => $tokens,
        'note'   => 'Coverage list: ' . count($items) . ' points (' . $must . ' must), ' . number_format($tokens) . ' tokens written.',
    ];
}

/**
 * The list as a block of text for a prompt, or '' if there is none.
 * One line per item: "C3 [MUST, exception, source name] the point".
 */
function coverageText(array $items): string
{
    if (!$items) {
        return '';
    }
    $lines = [];
    foreach ($items as $item) {
        $lines[] = $item['id'] . ' [' . ($item['priority'] === 'must' ? 'MUST' : 'if room') . ', '
            . str_replace('_', ' ', $item['kind'])
            . ($item['source'] !== '' ? ', ' . $item['source'] : '') . '] ' . $item['point'];
    }
    return implode("\n", $lines) . "\n";
}

/**
 * The "must" items a writer's plan does not mention by id, either as covered
 * or as left out. The writer is told to name every one of them, so a missing
 * id means the plan did not account for it.
 *
 * $reply  the writer's whole reply (the plan is between <plan> tags)
 * Returns the ids, e.g. ['C3', 'C7'].
 */
function coveragePlanGaps(array $items, string $reply): array
{
    if (!$items) {
        return [];
    }
    $plan = preg_match('#<plan>(.*?)</plan>#s', $reply, $m) ? $m[1] : '';
    $gaps = [];
    foreach ($items as $item) {
        if ($item['priority'] === 'must' && !preg_match('/\b' . preg_quote($item['id'], '/') . '\b/', $plan)) {
            $gaps[] = $item['id'];
        }
    }
    return $gaps;
}
