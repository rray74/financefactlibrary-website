<?php
/**
 * The polish loop: take a draft through the AI check and a rewrite of what
 * the check and the program checks found, up to POLISH_MAX_CHECKS times, so
 * the text that reaches you has already been through the same checks you
 * would otherwise run by hand.
 *
 *   draft (ai-drafts.php: coverage list, writer, its automatic rewrite)
 *     -> AI check (aiTextCheck, text-checks.php) + the program checks
 *     -> STOP if the stop rule is met (below)
 *     -> rewrite the findings, from the source pages (it may delete)
 *     -> check again ... up to POLISH_MAX_CHECKS checks
 *     -> the best version is kept, not the last
 *
 * The stop rule (polishStopRuleMet()): the AI check has NO substance
 * findings (contradicts a source or figure, probably wrong, regional
 * difference or exception missing, heading mismatch), at most
 * POLISH_STOP_MAX_STYLE style findings (said twice, section not needed, house
 * style, too close to a source, advice), and the program checks are clean
 * (no near-copies, length within limits, valid placeholders, no house-style
 * notes such as dating words, advice phrasing or meta lengths). "Zero
 * findings" is a moving target, because the check is not repeatable and
 * is not capped (it reports up to 12); two minor style findings are the
 * tolerance that makes the rule reachable.
 *
 * Why "best, not last": a rewrite can fix one thing and break another. Every
 * version is checked once. If none meets the stop rule, the one with the
 * lowest score wins (a substance finding weighs 3, a style finding 1, a
 * program-check problem 1; a tie keeps the earlier version). A rewrite is
 * also refused outright, before it costs another check, if it breaks the
 * program checks (length, placeholders, near-copies, typed figures) or throws
 * away the text it was not asked to touch.
 *
 * The rewrite may DELETE: a section the check calls a duplicate or not needed
 * can be removed or merged, down to POLISH_MIN_WORDS words. Before this it
 * could only add and reword, so duplicates survived every round.
 *
 * What it does NOT do: judge what is missing beyond what the check sees. The
 * check only sees problems in what was written, so a result that meets the
 * stop rule means "the checker found nothing serious", not "nothing is
 * wrong". You still read every page before saving.
 *
 * It takes about fifteen to twenty minutes a page (up to five checks of up to
 * two minutes, four rewrites of about one or two, the coverage pass and the
 * draft), too long for a web request, so it runs in the
 * background: overnight from scripts/draft-pages.php, or on demand through
 * the polish_jobs queue (migration 020) that scripts/run-polish-jobs.php
 * works through, one job per run, started every minute by cron. The editor's
 * "Draft and polish in the background" button adds a job.
 *
 * Nothing here changes a live page. The result is stored as the page's AI
 * draft (subject_drafts), with the last check kept beside it
 * (subject_text_checks) so the editor can show it.
 */

require_once __DIR__ . '/ai-drafts.php'; // draftChecks(), reviseDraft(), requestSourceBlock(), the coverage list

/** Most AI checks per page. That is up to four rewrites: there is no rewrite after the last check. */
const POLISH_MAX_CHECKS = 5;

/** The stop rule allows this many style findings (and no substance findings, and clean program checks). */
const POLISH_STOP_MAX_STYLE = 2;

/**
 * When a rewrite is allowed to delete (the findings include a duplicate or an
 * unneeded section), the explanation may shrink as far as this many words, but
 * no further. Otherwise a rewrite that cuts the explanation below
 * DRAFT_REVISION_MIN_LENGTH_SHARE of its length is refused.
 */
const POLISH_MIN_WORDS = 300;

/** The finding kinds that justify deleting or merging a section. */
const POLISH_DELETION_KINDS = ['duplicated', 'unneeded_section'];

/** How much a finding counts when choosing the best version. */
const POLISH_WEIGHT_SUBSTANCE = 3;
const POLISH_WEIGHT_STYLE = 1;

/** A job still "running" after this many minutes is taken to have died. A full run (coverage, draft, five checks, four rewrites) can take twenty to thirty. */
const POLISH_STALE_MINUTES = 60;

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

/** [substance findings, style findings] in a list of findings. */
function polishCounts(array $findings): array
{
    $style = 0;
    foreach ($findings as $finding) {
        if (in_array($finding['kind'], TEXT_CHECK_STYLE_KINDS, true)) {
            $style++;
        }
    }
    return [count($findings) - $style, $style];
}

/**
 * The stop rule: no substance findings, at most POLISH_STOP_MAX_STYLE style
 * findings, and the program checks clean. "Clean" is draftScore() = 0: no
 * near-copies, explanation within the length limit, no bad placeholders, no
 * house-style notes (dating words, advice phrasing, meta lengths, typed
 * figures in the meta fields). Typed numbers that "aren't among the page's
 * figures" are not counted: they are a prompt to look, and a fixed figure
 * typed on purpose (frozen until a known date) would otherwise block the rule.
 */
function polishStopRuleMet(array $findings, array $programChecks): bool
{
    [$substance, $style] = polishCounts($findings);
    return $substance === 0 && $style <= POLISH_STOP_MAX_STYLE && draftScore($programChecks) === 0;
}

/**
 * The model for the polish REWRITE: AI_POLISH_MODEL from config/database.local.php
 * if set (an experiment: a stronger model for the one step that has to fix
 * findings without breaking the rest), else the drafting model. The AI check and
 * the coverage pass are not affected.
 */
function polishModel(): ?string
{
    return defined('AI_POLISH_MODEL') && AI_POLISH_MODEL !== '' ? AI_POLISH_MODEL : draftModel();
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
    $min = POLISH_MIN_WORDS;
    $job = <<<TXT
You polish a draft of the four text fields of a page on Finance Fact Library, a personal finance reference site: intro, meta_title, meta_description and explanation.

A checker has read the draft against the official source pages and the house style, and listed problems. Some come from the AI checker and some from program checks (near-copies, lengths, dating words and so on). Fix those problems. Leave everything else as it is: do not rewrite sentences that are not listed, do not shorten other text for its own sake, keep every placeholder, and keep the order of the sections unless a fix needs it. The one exception is removing a duplicate or an unneeded section, below.

How to fix each kind of problem:
- Missing exception or condition: add the missing point in one or two plain sentences, using ONLY what the source pages in the request say, in your own words. Work it into the paragraph it belongs to. Add a new section only if no section fits.
- Contradicts a source, probably wrong, overstated, or an unsupported claim: say what the source says, in the strength the source uses ("could" stays "could"; "usually" stays "usually"). If the source does not support a claim at all, remove it. EXCEPTION: if the sentence is about how or when a figure changed (rose, fell, "from X to Y", "older guidance", the year it changed), DELETE the whole sentence. Do not correct it: the figure cards keep each figure's history, and the text must not date itself. A corrected version of such a sentence only brings back a typed figure or year that the program checks then refuse.
- Heading that doesn't match its text: change the heading or move the point so they agree. A heading states a point; it is not a label.
- Reads as advice: state the rule instead of telling the reader what to do ("DWP has to be told", not "tell DWP").
- Too close to a source: restate the point in your own words AND a different sentence structure.
- Duplicated, or a section that is not needed: the finding quotes one of the places the point is made. Keep the fuller or better-placed statement and DELETE the other, or merge what is worth keeping into the one section where the point belongs. A sentence that only points to another section ("this is covered in the next section") is deleted, not reworded. You may delete whole sections and paragraphs for this, and you need not keep their text. A point is made once. A section that only restates what the figure cards show can go. Do not delete the only place where a point marked MUST in the coverage list is handled.
- Meta title or meta description of the wrong length: the problem gives the count with the figures filled in and what to cut or add. Count again after you change it.
- Never state a count, an age, a date or a rule that the source pages do not give.

Every sentence you add or change must be in your own words and your own sentence structure. A program compares the result with the source pages sentence by sentence, and a sentence that follows a source closely is rejected, so restate the source's point, never its sentence.

The explanation may grow to make room for what was missing, but must stay within {$limit} words in all. If it would not fit, tighten the wording of unlisted text only where the meaning is unchanged. Deleting may take it down, but not below {$min} words.

If the request has a coverage list, it is what the sources say the page has to handle, read before the draft was written. Use it for two things only: to see which source says a missing point and where in the text it belongs, and to avoid deleting the only place a MUST point is handled. It is not a list of problems: do not add a point to the draft just because it is on the list.

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
 * $programFixes  the program checks' problems as instructions (draftFixList())
 * $coverageBlock the coverage list as text (coverageText()), or ''
 */
function polishRewriteRequest(array $subject, array $facts, array $fields, array $findings, string $sourceBlock, array $programFixes = [], string $coverageBlock = ''): string
{
    $placeholders = ['- {tax_year}: the tax year of the figures'];
    foreach ($facts as $fact) {
        $placeholders[] = '- {fact:' . $fact['fact_key'] . '}: ' . $fact['label'] . ' (' . formatFactValue($fact) . ')';
    }

    $numbered = [];
    foreach (array_values($findings) as $finding) {
        $numbered[] = (count($numbered) + 1) . '. ' . polishFindingLine($finding);
    }
    foreach ($programFixes as $fix) {
        $numbered[] = (count($numbered) + 1) . '. Program check: ' . $fix;
    }

    return "Page: {$subject['name']}\n"
        . "Country: {$subject['country_name']}\n\n"
        . "The draft:\n"
        . '<intro>' . (string) ($fields['intro'] ?? '') . "</intro>\n"
        . '<meta_title>' . (string) ($fields['meta_title'] ?? '') . "</meta_title>\n"
        . '<meta_description>' . (string) ($fields['meta_description'] ?? '') . "</meta_description>\n"
        . "<explanation>\n" . (string) ($fields['explanation'] ?? '') . "\n</explanation>\n\n"
        . "Problems the checks found (fix these and nothing else, except that a duplicate or unneeded section may be deleted):\n" . implode("\n", $numbered) . "\n\n"
        . "Placeholders you may use:\n" . implode("\n", $placeholders) . "\n\n"
        . ($coverageBlock !== '' ? "Coverage list (what the page has to handle; see your instructions for how to use it):\n" . $coverageBlock . "\n" : '')
        . "Source pages (the only source of anything you add):\n" . $sourceBlock;
}

/**
 * Why a rewrite should not be used, or NULL if it is fine. Cheap program
 * checks, run before the rewrite is spent on another AI check.
 */
function polishRejects(PDO $pdo, array $subject, array $published, array $read, array $before, array $after, bool $deletionAllowed = false): ?string
{
    $wordsBefore = preg_match_all('/\S+/u', (string) $before['explanation']);
    $wordsAfter = preg_match_all('/\S+/u', (string) $after['explanation']);

    if (trim((string) $after['explanation']) === '') {
        return 'it came back with no explanation';
    }
    if ($wordsAfter > EXPLANATION_MAX_WORDS) {
        return "the explanation grew to $wordsAfter words, over the limit of " . EXPLANATION_MAX_WORDS;
    }
    if ($deletionAllowed) {
        // The findings include a duplicate or an unneeded section, so cutting is
        // expected. The floor is POLISH_MIN_WORDS: a page may shrink down to it,
        // and a page already under it may not shrink at all.
        if ($wordsAfter < POLISH_MIN_WORDS && $wordsAfter < $wordsBefore) {
            return "it cut the explanation from $wordsBefore to $wordsAfter words, below the floor of " . POLISH_MIN_WORDS;
        }
    } elseif ($wordsBefore <= EXPLANATION_MAX_WORDS && $wordsAfter < $wordsBefore * DRAFT_REVISION_MIN_LENGTH_SHARE) {
        return "it cut the explanation from $wordsBefore to $wordsAfter words";
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
 * The fields a rewrite may change: the explanation always, and the intro, meta
 * title and meta description only if something was listed against them (an AI
 * finding in that field, a program problem that names it, or for the intro a
 * near-copy sentence that is in it). Anything else is put back as it was.
 *
 * $findings       the AI check's findings
 * $programFixes   draftFixList() of the program checks (notes begin "Intro:",
 *                 "Meta title:" or "Meta description:" when they are about those)
 * $programChecks  draftChecks() of the same text, for the near-copies
 * $current        the text being rewritten
 */
function polishFieldsToChange(array $findings, array $programFixes, array $programChecks, array $current): array
{
    $allowed = ['explanation'];
    $labels = ['intro' => 'Intro', 'meta_title' => 'Meta title', 'meta_description' => 'Meta description'];

    foreach ($labels as $field => $label) {
        $listed = (bool) array_filter($findings, fn($f) => $f['field'] === $field);
        foreach ($programFixes as $fix) {
            if (preg_match('/^' . preg_quote($label, '/') . '\b/', $fix)) {
                $listed = true;
            }
        }
        if ($field === 'intro') {
            foreach ($programChecks['copies'] as $copy) {
                if (stripos((string) $current['intro'], trim((string) $copy['quote'], " .\"")) !== false) {
                    $listed = true;
                }
            }
        }
        if ($listed) {
            $allowed[] = $field;
        }
    }
    return $allowed;
}

/** The program-check categories that rose, e.g. "near-copies 0 to 5, house-style notes 1 to 2". */
function polishWhatGotWorse(array $before, array $after): string
{
    $pairs = [
        'near-copies of a source'   => [count($before['copies']), count($after['copies'])],
        'explanation over the limit' => [$before['words'] > EXPLANATION_MAX_WORDS ? 1 : 0, $after['words'] > EXPLANATION_MAX_WORDS ? 1 : 0],
        'bad placeholders'          => [count($before['placeholder_errors']), count($after['placeholder_errors'])],
        'house-style notes'         => [count($before['notes']), count($after['notes'])],
    ];
    $rose = [];
    foreach ($pairs as $name => [$was, $now]) {
        if ($now > $was) {
            $rose[] = "$name $was to $now";
        }
    }
    $what = $rose ? implode(', ', $rose) : draftScore($before) . ' problems before, ' . draftScore($after) . ' after';

    // Say WHICH new problem it is: a count alone gave nothing to act on (four
    // rewrites in a row were refused for "house-style notes 0 to 1").
    $newNotes = array_slice(array_values(array_diff($after['notes'], $before['notes'])), 0, 2);
    $newCopies = array_slice(array_values(array_diff(array_column($after['copies'], 'quote'), array_column($before['copies'], 'quote'))), 0, 1);
    foreach ($newNotes as $note) {
        $what .= '; new note: "' . quoteWords($note, 35) . '"';
    }
    foreach ($newCopies as $quote) {
        $what .= '; new near-copy: "' . quoteWords((string) $quote, 20) . '"';
    }
    return $what;
}

/**
 * Whether a refused rewrite is worth repairing: the problems the program
 * checks name (near-copies, length, typed figures, bad placeholders) are the
 * ones the automatic rewrite fixes. A rewrite that threw the text away or came
 * back empty can't be repaired.
 */
function polishRejectIsRepairable(string $why): bool
{
    return strpos($why, 'it made the program checks worse') === 0
        || strpos($why, 'the explanation grew') === 0
        || strpos($why, 'it used a placeholder') === 0;
}

// ------------------------------------------------------------
// The loop
// ------------------------------------------------------------

/**
 * Take a draft through up to $maxChecks AI checks, rewriting what each
 * finds, and return the best version. Stops early at the first version that
 * meets the stop rule (polishStopRuleMet()).
 *
 * $subject, $facts   as for draftSubjectPage()
 * $draft             the draft's four fields (placeholders unfilled)
 * $retryTokens       a larger token ceiling for the rewrite if all its room
 *                    went on reasoning (0: don't retry)
 * $coverage          the coverage list from the drafting step (ai-coverage.php),
 *                    or [] for none; given to the rewrite, never to the check
 *
 * Returns:
 *   fields    the four fields of the best version
 *   result    that version's aiTextCheck() result (null if no check ran)
 *   findings  the findings left in it
 *   counts    findings found by each check, in order, e.g. [7, 3, 1]
 *   splits    the same with substance and style, e.g. ["7 (3 substance, 4 style)", ...]
 *   log       what each check found, one line per check (shown in the draft's details)
 *   best      which check's version was kept (1 = the draft as it came)
 *   stop_met  true if the kept version met the stop rule
 *   clean     true if the kept version had no findings at all
 *   left      [substance, style, program-check problems] still in the kept version
 *   model     the model that made the rewrites ('' = the site's usual one)
 *   notes     things that stopped or limited the loop, in sentences
 *   tokens    tokens written by each rewrite
 *   problems  plain lines for the draft's red box: the program-check problems
 *             in the kept text, then the findings left
 */
function polishSubjectPage(PDO $pdo, array $subject, array $facts, array $draft, int $maxChecks = POLISH_MAX_CHECKS, int $retryTokens = 0, array $coverage = []): array
{
    $published = array_values(array_filter($facts, fn($f) => $f['status'] === 'published'));
    $read = readSourcePagesForFacts($published, DRAFT_SOURCE_CHARACTERS_EACH);
    $sourceBlock = requestSourceBlock($read['sources'], $read['texts']);
    $coverageBlock = coverageText($coverage);
    $model = polishModel();

    $current = [];
    foreach (DRAFT_FIELDS as $field) {
        $current[$field] = (string) ($draft[$field] ?? '');
    }

    $versions = [];   // each checked version: ['fields', 'result', 'checks', 'score', 'met']
    $counts = [];
    $splits = [];
    $log = [];        // what each check found, in words, for the draft's details line
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

        // The program checks on the same text: the stop rule needs them clean, and
        // the rewrite is told what they found (the AI check does not see most of it).
        $programChecks = draftChecks($pdo, $current, $published, $read, $subject);
        $programProblems = draftScore($programChecks);
        $met = polishStopRuleMet($result['findings'], $programChecks);
        $score = polishScore($result['findings']) + $programProblems;
        [$substance, $style] = polishCounts($result['findings']);

        $versions[] = ['fields' => $current, 'result' => $result, 'checks' => $programChecks, 'score' => $score, 'met' => $met];
        $counts[] = count($result['findings']);
        // What this check found: each finding's kind and its first words, and the
        // program checks' problems, so a finding that survives rewrites can be seen.
        $logLine = "Check $check: ";
        foreach ($result['findings'] as $f) {
            $logLine .= (in_array($f['kind'], TEXT_CHECK_STYLE_KINDS, true) ? 'style' : 'SUBSTANCE') . ' '
                . (TEXT_CHECK_KINDS[$f['kind']] ?? $f['kind']) . ' "' . quoteWords($f['quote'], 10) . '"; ';
        }
        $log[] = rtrim($logLine . ($programProblems ? "$programProblems program problem(s)" : ($result['findings'] ? '' : 'nothing found')), '; ') . '.';
        $splits[] = count($result['findings']) . " ($substance substance, $style style"
            . ($programProblems ? ", $programProblems program" : '') . ')';

        // The first version that meets the stop rule is the one kept, and the
        // loop ends. Otherwise a tie keeps the earlier version: only a strictly
        // lower score replaces it.
        if ($met) {
            $best = count($versions) - 1;
            break;
        }
        if ($best === null || $score < $versions[$best]['score']) {
            $best = count($versions) - 1;
        }

        // Out of checks: no rewrite after the last check.
        if ($check === $maxChecks) {
            break;
        }

        // What to fix: the AI check's findings, and the program checks' problems.
        // A near-copy is in both (the AI check adds them itself); the program
        // list is the fuller one, with the source sentence to avoid, so when it
        // has near-copies the AI check's copies are left out of the request.
        $programFixes = draftFixList($programChecks);
        $findingsForRewrite = $programChecks['copies']
            ? array_values(array_filter($result['findings'], fn($f) => $f['kind'] !== 'copied_from_source'))
            : $result['findings'];
        $deletionAllowed = (bool) array_filter($result['findings'], fn($f) => in_array($f['kind'], POLISH_DELETION_KINDS, true));

        try {
            $reply = callClaude(
                polishRewriteInstructions((string) $subject['country_prefix'], (string) $subject['country_code']),
                polishRewriteRequest($subject, $published, $current, $findingsForRewrite, $sourceBlock, $programFixes, $coverageBlock),
                DRAFT_MAX_TOKENS,
                null,
                $model,
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

        // The rewrite is asked to touch only what was listed, but it rewrites all four
        // fields every time and the meta fields drift: on Pension Tax Relief two
        // rewrites in a row came back with a meta description of 172 and 176
        // characters (limit 155), and each was refused. A field nothing was listed
        // against keeps its text.
        $mayChange = polishFieldsToChange($result['findings'], $programFixes, $programChecks, $current);
        foreach (['intro', 'meta_title', 'meta_description'] as $field) {
            if (!in_array($field, $mayChange, true)) {
                $revised[$field] = $current[$field];
            }
        }

        $why = polishRejects($pdo, $subject, $published, $read, $current, $revised, $deletionAllowed);

        // A refused rewrite is usually good text with a mechanical fault, most often
        // sentences that follow a source too closely, because it was told to add
        // what the source says. The automatic rewrite fixes those, so try it once
        // before giving up on the whole round.
        if ($why !== null && polishRejectIsRepairable($why)) {
            try {
                $repair = reviseDraft(
                    $pdo, $subject, $published, $read, $revised,
                    draftChecks($pdo, $revised, $published, $read, $subject), 2, false, $retryTokens
                );
            } catch (Throwable $e) {
                $repair = ['accepted' => false, 'draft' => $revised, 'tokens' => []];
            }
            $tokens = array_merge($tokens, $repair['tokens']);
            if ($repair['accepted']) {
                $repaired = array_intersect_key($repair['draft'], array_flip(DRAFT_FIELDS));
                $whyAfterRepair = polishRejects($pdo, $subject, $published, $read, $current, $repaired, $deletionAllowed);
                if ($whyAfterRepair === null) {
                    $notes[] = "The rewrite after check $check was first refused ($why) and was repaired.";
                    $revised = $repaired;
                    $why = null;
                } else {
                    $why .= "; after a repair attempt: $whyAfterRepair";
                }
            } else {
                $why .= '; the repair attempt did not help';
            }
        }

        if ($why !== null) {
            $notes[] = "The rewrite after check $check was not used: $why.";
            break;
        }
        $current = $revised;
    }

    if ($best === null) {
        // No check ran at all: the draft goes back as it was.
        return [
            'fields' => $current, 'result' => null, 'findings' => [], 'counts' => [], 'splits' => [], 'log' => $log, 'best' => 0,
            'stop_met' => false, 'clean' => false, 'left' => [0, 0, 0], 'model' => (string) $model,
            'notes' => $notes, 'tokens' => $tokens, 'problems' => [],
        ];
    }

    $kept = $versions[$best];
    [$leftSubstance, $leftStyle] = polishCounts($kept['result']['findings']);
    $problems = array_merge(
        draftProblemLines($kept['checks'], false),
        array_map(fn($f) => 'AI check: ' . polishFindingLine($f), $kept['result']['findings'])
    );

    return [
        'fields'   => $kept['fields'],
        'result'   => $kept['result'],
        'findings' => $kept['result']['findings'],
        'counts'   => $counts,
        'splits'   => $splits,
        'log'      => $log,
        'best'     => $best + 1,
        'stop_met' => $kept['met'],
        'clean'    => !$kept['result']['findings'],
        'left'     => [$leftSubstance, $leftStyle, draftScore($kept['checks'])],
        'model'    => (string) $model,
        'notes'    => $notes,
        'tokens'   => $tokens,
        'problems' => $problems,
    ];
}

/**
 * What the polish did, as a sentence for the draft panel, e.g.
 * "Polished: 3 checks found 7 (3 substance, 4 style), 3 (0 substance, 3 style) and
 * 1 (0 substance, 1 style). Stop rule met after check 3 (...)."
 */
function polishNote(array $polish): string
{
    if (!$polish['counts']) {
        return implode(' ', $polish['notes']) ?: 'Not polished.';
    }
    $splits = $polish['splits'];
    $last = array_pop($splits);
    $found = $splits ? implode(', ', $splits) . ' and ' . $last : (string) $last;
    $checks = count($polish['counts']);

    $line = 'Polished: ' . $checks . ' check' . ($checks === 1 ? '' : 's') . ' found ' . $found . '.';
    if ($polish['stop_met']) {
        $line .= $polish['best'] === 1
            ? ' The first draft already met the stop rule (no substance findings, at most ' . POLISH_STOP_MAX_STYLE . ' style findings, program checks clean).'
            : " Stop rule met after check {$polish['best']} (no substance findings, at most " . POLISH_STOP_MAX_STYLE . ' style findings, program checks clean).';
    } else {
        [$substance, $style, $program] = $polish['left'];
        $line .= " Stop rule NOT met. The best version (after check {$polish['best']}) is kept, with $substance substance and $style style finding" . ($style === 1 ? '' : 's')
            . " left and $program program-check problem" . ($program === 1 ? '' : 's') . '.';
    }
    if (($polish['model'] ?? '') !== '') {
        $line .= ' Rewrites by ' . $polish['model'] . '.';
    }
    return trim($line . ' ' . implode(' ', $polish['notes']));
}

// ------------------------------------------------------------
// Draft, polish and store: what the nightly job and the queue both run
// ------------------------------------------------------------

/**
 * Draft a page, polish it, and store the result as the page's AI draft.
 * Coverage list, the draft with two automatic rewrite rounds, then the polish loop.
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

    // Both background paths read the sources first for a coverage list. It
    // goes to the polish rewrite and is taken out of the draft before storing.
    $draft = draftSubjectPage($pdo, $subject, $facts, DRAFT_REVISIONS_NIGHTLY, DRAFT_REVISION_TIME_BUDGET_NIGHTLY, $retryTokens, true);
    $coverage = $draft['coverage'] ?? [];
    unset($draft['coverage']);
    $polish = null;

    if (polishEnabled()) {
        $polish = polishSubjectPage($pdo, $subject, $facts, $draft, POLISH_MAX_CHECKS, $retryTokens, $coverage);
        if ($polish['result'] !== null) {
            foreach (DRAFT_FIELDS as $field) {
                $draft[$field] = $polish['fields'][$field];
            }
            $draft['problems'] = $polish['problems'];
        }
        // The note keeps the draft's own summary and details, with the polish between them.
        [$summary, $details] = array_pad(explode(' | Details: ', (string) $draft['rewrite_note'], 2), 2, '');
        $details .= $polish['tokens'] ? ' Polish rewrites: ' . implode(', ', array_map('number_format', $polish['tokens'])) . ' tokens written.' : '';
        $details .= $polish['log'] ? ' ' . implode(' ', $polish['log']) : '';
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

/** True if migration 020 has been applied, so the polish_jobs table exists. */
function polishQueueReady(PDO $pdo): bool
{
    static $ready = null;
    if ($ready === null) {
        try {
            $pdo->query('SELECT 1 FROM polish_jobs LIMIT 1');
            $ready = true;
        } catch (PDOException $e) {
            $ready = false;
        }
    }
    return $ready;
}

/**
 * Add a page to the queue. Returns the job id, or NULL if the page already
 * has a job waiting or running (so a second click does nothing).
 *
 * Throws a plain-English error if migration 020 has not been applied, rather
 * than a database error.
 */
function queuePolishJob(PDO $pdo, int $subjectId): ?int
{
    if (!polishQueueReady($pdo)) {
        throw new RuntimeException('The polish queue is not set up yet: migration 020 (polish_jobs) has not been applied on this server.');
    }
    $open = $pdo->prepare("SELECT id FROM polish_jobs WHERE subject_id = :sid AND status IN ('queued', 'running') LIMIT 1");
    $open->execute(['sid' => $subjectId]);
    if ($open->fetch()) {
        return null;
    }
    $pdo->prepare("INSERT INTO polish_jobs (subject_id, status) VALUES (:sid, 'queued')")->execute(['sid' => $subjectId]);
    return (int) $pdo->lastInsertId();
}

/**
 * The page's most recent job, or NULL. Also NULL if migration 020 has not been
 * applied yet, so the editor still opens (it asks for this on every load).
 */
function getPolishJob(int $subjectId): ?array
{
    $pdo = getDbConnection();
    if (!polishQueueReady($pdo)) {
        return null;
    }
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
