<?php
/**
 * "Propose fix": a fix for ONE finding of the editor's AI check.
 *
 * A proposal is one to three changes, each in one of the four fields. The
 * finding names where the problem was seen, but the right fix is sometimes
 * elsewhere: on VAT Rates, "the intro promises all three rates but the
 * explanation never covers them" was best fixed in the intro and meta
 * description, not by adding a section to the explanation, and a fix limited to
 * the explanation could only try to write the section and failed the near-copy
 * check.
 *
 * Why it exists: the polish loop rewrites the whole text, so each round can fix
 * one thing and disturb another, and on Pension Tax Relief it never reached a
 * clean check by itself. This works the other way round. After "Check with AI"
 * lists its findings, each has a "Propose fix" button. The AI is given just that
 * finding, the field it is in and the source pages, and proposes the smallest
 * change: replace or delete a passage (or one in each of up to three places), or none (when the checker is wrong, or
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

You are given the problem (the words quoted are as the checker saw them, with figures filled in), the four fields as typed (with {placeholders} where figures are), the placeholders you may use, and the official source pages. Rules:
- Say only what the source pages say, in your own words AND your own sentence structure. Never follow a source sentence, even with a few words changed. Keep the strength of the source's hedges ("could", "usually", "may"): never state something more strongly or more broadly than the source does.
- Never type a figure that changes: use a placeholder from the list. Never say how or when a figure changed, or give an earlier amount.
- Exception or condition missing: add it where it belongs, in one or two sentences, and only if the source pages state it. If it needs a figure the page does not have, answer "none" and say which figure is needed.
- Contradicts a source, probably wrong, or overstated: correct it to what the source says. If the sentence is about how or when a figure changed, or no source supports it, DELETE it. Do not correct it.
- Said twice: delete the weaker statement (or the pointer sentence, such as "this is covered in the next section"), keeping the one where the point belongs.
- Heading that does not match its text, or an intro or meta description that promises what the explanation does not cover: either change the heading so it states a point true of ALL the text under it, or remove the unkept promise from the intro or meta description. The figure cards already show the figures, so the explanation does not need a section that walks through them: prefer cutting the promise to adding a section.
- Breaks the house style, or too close to a source: reword just that sentence.
- Never copy a source sentence's structure. If a sentence lists examples from a source (goods, services, groups, forms), do not reproduce the list: use at most one or two examples in a different construction, or leave the examples out and say what the category is. A program check refuses any sentence that follows a source's wording.
- If the checker is wrong (the text is right, or the problem asks for something the house style or a lesson forbids), answer "none" and say why in a sentence.

Reply with JSON only, no other text:
{"action": "replace" | "none", "changes": [{"field": "explanation", "old": "...", "new": "..."}], "reason": "one sentence for the person reading the proposal"}
- "changes" has one to three entries. Each names the "field" (intro, explanation, meta_title or meta_description) it is in. Use more than one only when the fix needs it, for example an intro and a meta description that make the same unkept promise.
- "old" is copied EXACTLY, character for character, from that field as typed (placeholders and all): the smallest passage that holds the problem, usually one sentence or one heading line. It must occur once in that field. To add a point, make "old" the sentence it follows and "new" that sentence plus the addition.
- "new" is what replaces it. An empty "new" deletes the passage. Keep to the editor's format from the house style. Do not touch anything outside "old".
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

    // All four fields, as typed: the finding names where the problem was seen, but the
    // fix may belong in another field (see the header).
    $fieldText = '';
    foreach (DRAFT_FIELDS as $field) {
        $fieldText .= "<$field>" . ($field === 'explanation' ? "\n" : '') . (string) ($fields[$field] ?? '')
            . ($field === 'explanation' ? "\n" : '') . "</$field>\n";
    }

    return "Page: {$subject['name']}\n"
        . "Country: {$subject['country_name']}\n\n"
        . "The problem the checker found:\n" . polishFindingLine($finding) . "\n\n"
        . ($retryNote !== '' ? "Your last try was not used: $retryNote\n\n" : '')
        . "The four fields as typed (\"old\" must be copied exactly from the field it is in):\n" . $fieldText . "\n"
        . "Placeholders you may use:\n" . implode("\n", $placeholders) . "\n\n"
        . "Source pages (the only source of anything you add):\n" . $sourceBlock;
}

/**
 * Read the AI's reply: ['action' => replace|none, 'changes' => [['field', 'old', 'new'], ...],
 * 'reason']. A reply in the older single-change shape ("old" and "new" at the top
 * level, optionally with "field") is read as one change in that field. Throws if the
 * reply is not JSON in either shape.
 *
 * $defaultField  the field of the finding, for a change that names none
 */
function parseFixReply(string $reply, string $defaultField = 'explanation'): array
{
    $start = strpos($reply, '{');
    $end = strrpos($reply, '}');
    $data = ($start !== false && $end > $start) ? json_decode(substr($reply, $start, $end - $start + 1), true) : null;
    $action = is_array($data) ? (string) ($data['action'] ?? '') : '';
    // The first version of this reply had "delete" as an action: that is a change with an empty "new".
    if (!in_array($action, ['replace', 'delete', 'none'], true)) {
        throw new RuntimeException('the reply was not in the expected format. It began: "' . aiReplyStart($reply) . '"');
    }

    $rows = [];
    if (isset($data['changes']) && is_array($data['changes'])) {
        $rows = $data['changes'];
    } elseif (isset($data['old'])) {
        $rows = [['field' => $data['field'] ?? $defaultField, 'old' => $data['old'], 'new' => $action === 'delete' ? '' : ($data['new'] ?? '')]];
    }

    $changes = [];
    foreach (array_slice($rows, 0, 3) as $row) {
        if (!is_array($row)) {
            continue;
        }
        $changes[] = [
            'field' => (string) ($row['field'] ?? $defaultField),
            'old'   => str_replace("\r\n", "\n", (string) ($row['old'] ?? '')),
            'new'   => str_replace("\r\n", "\n", (string) ($row['new'] ?? '')),
        ];
    }
    return [
        'action'  => $action === 'none' ? 'none' : 'replace',
        'changes' => $changes,
        'reason'  => trim((string) ($data['reason'] ?? '')),
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
        $why = 'it made the program checks worse (' . polishWhatGotWorse($checksBefore, $checksAfter) . ')';
        // For a near-copy, show the source text it followed, so the next try can say it differently.
        $seen = array_column($checksBefore['copies'], 'quote');
        foreach ($checksAfter['copies'] as $copy) {
            if (!in_array($copy['quote'], $seen, true) && !empty($copy['matched'])) {
                $why .= '. The source says: "' . quoteWords((string) $copy['matched'], 30) . '". Use a different structure, or leave the examples out';
                break;
            }
        }
        return $why;
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
 *   action   'replace' or 'none' (none: no change proposed)
 *   field    the field the finding was in
 *   changes  one to three ['field', 'old', 'new'] (an empty 'new' is a deletion); [] for none
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
            $fix = parseFixReply($reply, $field);
        } catch (RuntimeException $e) {
            $lastWhy = $e->getMessage();
            $retryNote = 'it ' . $e->getMessage();
            continue;
        }

        if ($fix['action'] === 'none' || !$fix['changes']) {
            return ['ok' => true, 'action' => 'none', 'field' => $field, 'changes' => [],
                'reason' => $fix['reason'] !== '' ? $fix['reason'] : 'The AI proposed no change.'];
        }

        // Each passage must be in its field exactly once, so the program can apply the change.
        // Changes are applied in order to a working copy, so two in one field are checked in turn.
        $after = $fields;
        $problem = '';
        foreach ($fix['changes'] as $change) {
            $where = $change['field'];
            if (!in_array($where, DRAFT_FIELDS, true)) {
                $problem = '"' . $where . '" is not one of the fields (intro, explanation, meta_title, meta_description)';
                break;
            }
            $count = $change['old'] === '' ? 0 : substr_count($after[$where], $change['old']);
            if ($count !== 1) {
                $problem = $count === 0
                    ? 'the "old" text for the ' . $where . ' was not found, character for character. Copy it exactly from that field'
                    : 'the "old" text for the ' . $where . " occurs $count times. Use a longer passage that occurs once";
                break;
            }
            if ($change['new'] === $change['old']) {
                $problem = 'a "new" text was the same as its "old" text. Make the change, or answer "none"';
                break;
            }
            $after[$where] = fixApply($after[$where], $change['old'], $change['new']);
        }
        if ($problem !== '') {
            $lastWhy = $problem;
            $retryNote = $problem . '.';
            continue;
        }

        $why = fixRejects($pdo, $subject, $published, $read, $fields, $after);
        if ($why !== null) {
            $lastWhy = $why;
            $retryNote = "it was refused because $why. Try again, or answer \"none\" if it cannot be fixed that way.";
            continue;
        }

        return ['ok' => true, 'action' => 'replace', 'field' => $field, 'changes' => $fix['changes'], 'reason' => $fix['reason']];
    }

    return ['ok' => false, 'action' => 'none', 'field' => $field, 'changes' => [],
        'reason' => "No safe fix was found (last try: $lastWhy). You can fix this one by hand."];
}
