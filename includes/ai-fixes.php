<?php
/**
 * "Propose fix": a fix for ONE finding of the editor's AI check.
 *
 * Why it exists: the polish loop rewrites the whole text, so each round can fix
 * one thing and disturb another, and on Pension Tax Relief it never reached a
 * clean check by itself. This works the other way round. After "Check with AI"
 * lists its findings, each has a "Propose fix" button. The AI is given just that
 * finding, the field it is in and the source pages, and proposes the smallest
 * change: replace one passage, delete it, or none (when the checker is wrong, or
 * the fix needs a figure the page does not have). Everything else in the text is
 * untouched, because the program, not the AI, applies the change.
 *
 * What is checked before a proposal is shown (so a proposal you see has already
 * passed the program checks):
 *   - the passage to change ("old") is found exactly once, character for
 *     character, in the field as typed (placeholders and all);
 *   - the text with the change applied does not make the program checks worse
 *     (near-copies of a source, dating words, advice phrasing, meta lengths,
 *     placeholders, explanation length). See fixRejects().
 * If a proposal fails either, the AI is told why and tries once more. If that
 * fails too, no proposal is shown, and the reason is.
 *
 * What the person does: the proposal is shown under the finding with the old
 * and new words. "Apply to the box" (in the page's JavaScript) makes the change
 * in the text box and nothing else. Nothing is saved until the person saves the
 * page, and the AI check can be run again on the changed text.
 *
 * It uses the polish model (polishModel(): AI_POLISH_MODEL, else the drafting
 * model) and reads the source pages, so it takes up to a minute. It is also the
 * piece that "proposed updates" for pages flagged by a changed source can be
 * built on later: the same call, with the change as the finding.
 */

require_once __DIR__ . '/ai-drafts.php';
require_once __DIR__ . '/ai-polish.php'; // polishModel(), polishFindingLine(), polishWhatGotWorse()

/** Most tokens a proposal may use. It is one passage and a sentence of reasons. */
const FIX_MAX_TOKENS = 1500;

/** Tries per proposal: the first, and one more with what was wrong with it. */
const FIX_ATTEMPTS = 2;

/** The instructions for a fix, then the house style and the lessons. */
function fixInstructions(string $countryPrefix, string $countryCode): string
{
    $job = <<<'TXT'
You fix ONE problem that a checker found in the text of a page on Finance Fact Library, a personal finance reference site. A person will read your proposed change and accept or reject it, so make the smallest change that fixes the problem.

You are given the problem (the words quoted are as the checker saw them, with figures filled in), the field it is in as typed (with {placeholders} where figures are), the placeholders you may use, and the official source pages. Rules:
- Say only what the source pages say, in your own words AND your own sentence structure. Never follow a source sentence, even with a few words changed. Keep the strength of the source's hedges ("could", "usually", "may"): never state something more strongly or more broadly than the source does.
- Never type a figure that changes: use a placeholder from the list. Never say how or when a figure changed, or give an earlier amount.
- Exception or condition missing: add it where it belongs, in one or two sentences, and only if the source pages state it. If it needs a figure the page does not have, answer "none" and say which figure is needed.
- Contradicts a source, probably wrong, or overstated: correct it to what the source says. If the sentence is about how or when a figure changed, or no source supports it, DELETE it. Do not correct it.
- Said twice: delete the weaker statement (or the pointer sentence, such as "this is covered in the next section"), keeping the one where the point belongs.
- Heading that does not match its text: change the heading so it states a point that is true of ALL the text under it.
- Breaks the house style, or too close to a source: reword just that sentence.
- If the checker is wrong (the text is right, or the problem asks for something the house style or a lesson forbids), answer "none" and say why in a sentence.

Reply with JSON only, no other text:
{"action": "replace" | "delete" | "none", "old": "...", "new": "...", "reason": "one sentence for the person reading the proposal"}
- "old" is copied EXACTLY, character for character, from the field as typed (placeholders and all): the smallest passage that holds the problem, usually one sentence or one heading line. It must occur once in the field. To add a point, make "old" the sentence it follows and "new" that sentence plus the addition.
- "new" is what replaces it (empty for "delete"). Keep to the editor's format from the house style. Do not touch anything outside "old".
TXT;
    return $job . "\n\n" . writingGuidanceText($countryPrefix, $countryCode);
}

/**
 * The request for one fix.
 *
 * $fields      the four fields as typed (keys intro, explanation, meta_title, meta_description)
 * $finding     one finding from aiTextCheck()
 * $retryNote   what was wrong with the last try, or ''
 */
function fixRequest(array $subject, array $facts, array $fields, array $finding, string $sourceBlock, string $retryNote = ''): string
{
    $placeholders = ['- {tax_year}: the tax year of the figures'];
    foreach ($facts as $fact) {
        $placeholders[] = '- {fact:' . $fact['fact_key'] . '}: ' . $fact['label'] . ' (' . formatFactValue($fact) . ')';
    }

    $target = (string) $finding['field'];
    $context = '';
    foreach (DRAFT_FIELDS as $field) {
        if ($field !== $target && trim((string) ($fields[$field] ?? '')) !== '') {
            $context .= "<$field>" . (string) $fields[$field] . "</$field>\n";
        }
    }

    return "Page: {$subject['name']}\n"
        . "Country: {$subject['country_name']}\n\n"
        . "The problem the checker found:\n" . polishFindingLine($finding) . "\n\n"
        . ($retryNote !== '' ? "Your last try was not used: $retryNote\n\n" : '')
        . "The field the problem is in, as typed (this is the text \"old\" must be copied from):\n"
        . "<field>\n" . (string) ($fields[$target] ?? '') . "\n</field>\n\n"
        . "The other fields, for context only (do not change them):\n" . ($context !== '' ? $context : "(empty)\n") . "\n"
        . "Placeholders you may use:\n" . implode("\n", $placeholders) . "\n\n"
        . "Source pages (the only source of anything you add):\n" . $sourceBlock;
}

/**
 * Read the AI's reply: ['action' => replace|delete|none, 'old', 'new', 'reason'].
 * Throws if it is not JSON in that shape.
 */
function parseFixReply(string $reply): array
{
    $start = strpos($reply, '{');
    $end = strrpos($reply, '}');
    $data = ($start !== false && $end > $start) ? json_decode(substr($reply, $start, $end - $start + 1), true) : null;
    if (!is_array($data) || !in_array($data['action'] ?? '', ['replace', 'delete', 'none'], true)) {
        throw new RuntimeException('the reply was not in the expected format. It began: "' . aiReplyStart($reply) . '"');
    }
    return [
        'action' => (string) $data['action'],
        'old'    => str_replace("\r\n", "\n", (string) ($data['old'] ?? '')),
        'new'    => str_replace("\r\n", "\n", (string) ($data['new'] ?? '')),
        'reason' => trim((string) ($data['reason'] ?? '')),
    ];
}

/**
 * The field with one passage replaced. The caller has already checked that $old
 * occurs exactly once. When the replacement is empty the blank lines it leaves
 * are tidied: three or more in a row become one blank line. The page's
 * JavaScript does the same when it applies a proposal to the box, so the text
 * that was checked here is the text that ends up in the box.
 */
function fixApply(string $field, string $old, string $new): string
{
    $position = strpos($field, $old);
    $text = substr_replace($field, $new, $position, strlen($old));
    return preg_replace("/\n{3,}/", "\n\n", $text);
}

/**
 * Why a proposed change should not be shown, or NULL if it is fine. The program
 * checks, as for a polish rewrite (polishRejects()) but without its length floor:
 * a fix may delete a sentence from a short page.
 *
 * $before, $after  the four fields, keyed by DRAFT_FIELDS
 */
function fixRejects(PDO $pdo, array $subject, array $published, array $read, array $before, array $after): ?string
{
    $wordsBefore = preg_match_all('/\S+/u', (string) $before['explanation']);
    $wordsAfter = preg_match_all('/\S+/u', (string) $after['explanation']);

    if (trim((string) $before['explanation']) !== '' && trim((string) $after['explanation']) === '') {
        return 'it would leave the explanation empty';
    }
    if ($wordsAfter > EXPLANATION_MAX_WORDS && $wordsAfter > $wordsBefore) {
        return "it would take the explanation to $wordsAfter words, over the limit of " . EXPLANATION_MAX_WORDS;
    }

    $checksAfter = draftChecks($pdo, $after, $published, $read, $subject);
    if ($checksAfter['placeholder_errors']) {
        return 'it used a placeholder that is not valid';
    }
    $checksBefore = draftChecks($pdo, $before, $published, $read, $subject);
    if (draftScore($checksAfter) > draftScore($checksBefore)) {
        return 'it made the program checks worse (' . polishWhatGotWorse($checksBefore, $checksAfter) . ')';
    }
    return null;
}

/**
 * Propose a fix for one finding.
 *
 * $fields   the four fields as typed in the boxes (placeholders unfilled)
 * $finding  one finding from the stored AI check
 *
 * Returns:
 *   ok       false only if no usable proposal could be made (see reason)
 *   action   'replace', 'delete' or 'none' (none: no change proposed)
 *   field    which field it is in
 *   old,new  the passage and what replaces it (for 'none', both '')
 *   reason   a sentence for the person: why this change, why none, or why it failed
 *
 * An AI or network failure throws, as the other AI features do.
 */
function proposeFix(PDO $pdo, array $subject, array $facts, array $fields, array $finding): array
{
    $field = (string) $finding['field'];
    if (!in_array($field, DRAFT_FIELDS, true)) {
        throw new RuntimeException('That finding is not about one of the four text boxes.');
    }
    $fields = array_intersect_key($fields, array_flip(DRAFT_FIELDS)) + array_fill_keys(DRAFT_FIELDS, '');
    foreach (DRAFT_FIELDS as $name) {
        $fields[$name] = str_replace("\r\n", "\n", (string) $fields[$name]);
    }
    if (trim($fields[$field]) === '') {
        throw new RuntimeException('That box is empty now, so there is nothing to fix. Run Check with AI again.');
    }

    $published = array_values(array_filter($facts, fn($f) => $f['status'] === 'published'));
    $read = readSourcePagesForFacts($published, DRAFT_SOURCE_CHARACTERS_EACH);
    $sourceBlock = requestSourceBlock($read['sources'], $read['texts']);
    $instructions = fixInstructions((string) $subject['country_prefix'], (string) $subject['country_code']);

    $retryNote = '';
    $lastWhy = 'no usable reply';
    for ($attempt = 1; $attempt <= FIX_ATTEMPTS; $attempt++) {
        // An API or network failure is not retried here: it throws, like the other AI features.
        $reply = callClaude(
            $instructions,
            fixRequest($subject, $published, $fields, $finding, $sourceBlock, $retryNote),
            FIX_MAX_TOKENS,
            null,
            polishModel(),
            true
        );

        try {
            $fix = parseFixReply($reply);
        } catch (RuntimeException $e) {
            $lastWhy = $e->getMessage();
            $retryNote = 'it ' . $e->getMessage();
            continue;
        }

        if ($fix['action'] === 'none') {
            return ['ok' => true, 'action' => 'none', 'field' => $field, 'old' => '', 'new' => '',
                'reason' => $fix['reason'] !== '' ? $fix['reason'] : 'The AI proposed no change.'];
        }

        // The passage must be in the field exactly once, so the program can apply the change.
        $count = $fix['old'] === '' ? 0 : substr_count($fields[$field], $fix['old']);
        if ($count !== 1) {
            $lastWhy = $count === 0
                ? 'the passage to change was not found, character for character, in the text'
                : "the passage to change occurs $count times in the text";
            $retryNote = $count === 0
                ? 'the "old" text was not found, character for character, in the field. Copy it exactly from the field text.'
                : "the \"old\" text occurs $count times in the field. Use a longer passage that occurs only once.";
            continue;
        }

        $new = $fix['action'] === 'delete' ? '' : $fix['new'];
        if ($new === $fix['old']) {
            $lastWhy = 'the proposed text was the same as the existing text';
            $retryNote = 'the "new" text was the same as the "old" text. Make the change, or answer "none".';
            continue;
        }

        $after = $fields;
        $after[$field] = fixApply($fields[$field], $fix['old'], $new);
        $why = fixRejects($pdo, $subject, $published, $read, $fields, $after);
        if ($why !== null) {
            $lastWhy = $why;
            $retryNote = "it was refused because $why. Try again, or answer \"none\" if it cannot be fixed that way.";
            continue;
        }

        return ['ok' => true, 'action' => $new === '' ? 'delete' : 'replace', 'field' => $field,
            'old' => $fix['old'], 'new' => $new, 'reason' => $fix['reason']];
    }

    return ['ok' => false, 'action' => 'none', 'field' => $field, 'old' => '', 'new' => '',
        'reason' => "No safe fix was found (last try: $lastWhy). You can fix this one by hand."];
}
