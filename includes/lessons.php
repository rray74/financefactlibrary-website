<?php
/**
 * Writing guidance for the AI, and learning from your edits.
 *
 * The AI starts from nothing on every call, so it can't "remember" that a
 * mistake was found last week. What can improve is what it is told. Two
 * things are sent with every AI draft and every AI check:
 *
 *   1. the house style: docs/house-style/core.md and the country's file,
 *      which change rarely and are edited by hand;
 *   2. the lessons: short, general rules learned from real edits, kept in
 *      writing_lessons (migration 017). They grow as pages are written.
 *
 * How a lesson is made:
 *   - You edit an AI draft and save the page. recordDraftEdit() keeps the
 *     draft and the saved text together in draft_edit_records.
 *   - learnFromEditRecord() asks the AI to compare them and propose general
 *     lessons (not facts about the page's subject). They are saved as
 *     'proposed' and do nothing yet.
 *   - You approve or reject each one on /admin/lessons.php. Approved
 *     lessons are used from the next draft or check onwards.
 *   - You can also write a lesson by hand there.
 * It never changes a page.
 */

require_once __DIR__ . '/../config/database.php'; // getDbConnection()
require_once __DIR__ . '/ai-proposer.php';         // callClaude(), aiReplyStart(), keepDbConnectionOpenForAi()

/** Most approved lessons sent with a request; if there are more, the newest are used. */
const LESSONS_SENT_MAX = 40;

/** Most lessons the AI may propose from one edit. */
const LESSONS_PER_EDIT_MAX = 4;

// ------------------------------------------------------------
// What the AI is told
// ------------------------------------------------------------

/**
 * The house style for a country: docs/house-style/core.md followed by the
 * country's own file (uk.md for /uk/ pages). The country file is optional,
 * so a new country works with the core rules until its file is written.
 */
function houseStyleText(string $countryPrefix): string
{
    $folder = __DIR__ . '/../docs/house-style/';
    if (!is_readable($folder . 'core.md')) {
        throw new RuntimeException('docs/house-style/core.md is missing, so there is no house style to work from.');
    }

    $text = file_get_contents($folder . 'core.md');
    $countryFile = $folder . preg_replace('/[^a-z]/', '', strtolower($countryPrefix)) . '.md';
    if (is_readable($countryFile)) {
        $text .= "\n\n" . file_get_contents($countryFile);
    }
    return $text;
}

/**
 * The approved lessons that apply to a country (scope 'all' or its code),
 * oldest first. Returns an empty list if the table isn't there yet, so the
 * drafts and checks keep working before migration 017 is applied.
 */
function getApprovedLessons(string $countryCode = ''): array
{
    try {
        $pdo = getDbConnection();
        $stmt = $pdo->prepare(
            "SELECT id, lesson, scope FROM writing_lessons
             WHERE status = 'approved' AND (scope = 'all' OR scope = :cc)
             ORDER BY id"
        );
        $stmt->execute(['cc' => $countryCode]);
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        return [];
    }
}

/**
 * A lesson's fixed reference, "L14": its database id, which never changes when other
 * lessons are retired or added. The lessons page shows it, and the AI is sent the lessons
 * under the same references, so "L14" in an AI check finding is always the same lesson.
 *
 * Before this the AI was sent the lessons numbered by their POSITION in the list ("12."),
 * which moves whenever a lesson is retired or added, so a finding that said "lesson 12"
 * could not be matched to a lesson on the page.
 */
function lessonReference(int $lessonId): string
{
    return 'L' . $lessonId;
}

/** The approved lessons as a block of text for the AI, or '' if there are none. */
function lessonsPromptText(string $countryCode = ''): string
{
    $lessons = getApprovedLessons($countryCode);
    if (!$lessons) {
        return '';
    }
    $lessons = array_slice($lessons, -LESSONS_SENT_MAX);

    $text = "LESSONS LEARNED FROM PAST EDITS\nThese come from mistakes already found and corrected on real pages. Follow every one. "
        . "Each has a fixed reference (L3, L14 and so on): when you say a text breaks a lesson, give its reference.\n";
    foreach ($lessons as $lesson) {
        $text .= lessonReference((int) $lesson['id']) . '. ' . trim(preg_replace('/\s+/u', ' ', $lesson['lesson'])) . "\n";
    }
    return $text;
}

/** The house style followed by the lessons: everything the AI is told about how pages are written. */
function writingGuidanceText(string $countryPrefix, string $countryCode): string
{
    $lessons = lessonsPromptText($countryCode);
    return houseStyleText($countryPrefix) . ($lessons !== '' ? "\n\n" . $lessons : '');
}

// ------------------------------------------------------------
// Recording edits
// ------------------------------------------------------------

/**
 * How much of the explanation was changed, 0 (identical) to 100 (nothing
 * in common), worked out on words. NULL if there was no draft explanation
 * to compare. The longest run of words the two share is found, so moving
 * a sentence counts as a small change and rewriting it as a large one.
 */
function explanationChangePercent(?string $draft, ?string $final): ?int
{
    if ($draft === null || trim($draft) === '' || $final === null) {
        return null;
    }

    $words = fn(string $text) => array_slice(
        preg_split('/\s+/u', strtolower(trim($text)), -1, PREG_SPLIT_NO_EMPTY), 0, 1500
    );
    $a = $words($draft);
    $b = $words($final);
    $n = count($a);
    $m = count($b);
    if ($n === 0 || $m === 0) {
        return 100;
    }

    // Longest common subsequence of words, one row at a time.
    $previous = array_fill(0, $m + 1, 0);
    for ($i = 1; $i <= $n; $i++) {
        $current = [0];
        for ($j = 1; $j <= $m; $j++) {
            $current[$j] = $a[$i - 1] === $b[$j - 1]
                ? $previous[$j - 1] + 1
                : max($previous[$j], $current[$j - 1]);
        }
        $previous = $current;
    }

    return (int) round(100 * (1 - 2 * $previous[$m] / ($n + $m)));
}

/**
 * Keep a draft and the text it was saved as, so lessons can be learned
 * from the difference. Called from the editor when a page is saved with an
 * explanation while it still has a draft.
 *
 * $draft  the subject_drafts row (intro, explanation, meta_title,
 *         meta_description, created_at)
 * $final  the saved fields, with the same four keys
 *
 * Returns the new record's id.
 */
function recordDraftEdit(PDO $pdo, int $subjectId, array $draft, array $final): int
{
    $pdo->prepare(
        'INSERT INTO draft_edit_records
            (subject_id, draft_intro, draft_explanation, draft_meta_title, draft_meta_description,
             final_intro, final_explanation, final_meta_title, final_meta_description,
             draft_made_at, explanation_change_pct, saved_at)
         VALUES (:sid, :d_intro, :d_expl, :d_title, :d_desc, :f_intro, :f_expl, :f_title, :f_desc, :made, :pct, NOW())'
    )->execute([
        'sid'     => $subjectId,
        'd_intro' => $draft['intro'] ?? null,
        'd_expl'  => $draft['explanation'] ?? null,
        'd_title' => $draft['meta_title'] ?? null,
        'd_desc'  => $draft['meta_description'] ?? null,
        'f_intro' => $final['intro'] ?? null,
        'f_expl'  => $final['explanation'] ?? null,
        'f_title' => $final['meta_title'] ?? null,
        'f_desc'  => $final['meta_description'] ?? null,
        'made'    => $draft['created_at'] ?? null,
        'pct'     => explanationChangePercent($draft['explanation'] ?? null, $final['explanation'] ?? null),
    ]);
    return (int) $pdo->lastInsertId();
}

// ------------------------------------------------------------
// Learning from edits
// ------------------------------------------------------------

/** A lesson in a form that two wordings of the same rule are likely to share, to spot repeats. */
function lessonKey(string $lesson): string
{
    return trim(preg_replace('/[^a-z0-9]+/', ' ', strtolower($lesson)));
}

/**
 * Ask the AI what general lessons one edit shows, and save them as
 * 'proposed'. Marks the record as learned from either way, unless the AI
 * call itself fails (then it's retried next time).
 *
 * Returns ['proposed' => how many lessons were saved, 'note' => a sentence
 * for the page, or null].
 */
function learnFromEditRecord(PDO $pdo, int $recordId): array
{
    $stmt = $pdo->prepare(
        'SELECT r.*, s.name AS subject_name, j.code AS country_code, j.url_prefix AS country_prefix,
                j.name AS country_name
         FROM draft_edit_records r
         JOIN subjects s ON s.id = r.subject_id
         JOIN subcategories sc ON sc.id = s.subcategory_id
         JOIN categories c ON c.id = sc.category_id
         JOIN jurisdictions j ON j.id = c.jurisdiction_id
         WHERE r.id = :id'
    );
    $stmt->execute(['id' => $recordId]);
    $record = $stmt->fetch();
    if (!$record) {
        throw new RuntimeException("Edit record $recordId not found.");
    }

    $markLearned = fn() => $pdo->prepare('UPDATE draft_edit_records SET learned_at = NOW() WHERE id = :id')
        ->execute(['id' => $recordId]);

    // Compare field by field, ignoring spacing. If nothing changed there's nothing to learn.
    $fields = ['intro' => 'Intro', 'explanation' => 'Explanation', 'meta_title' => 'Meta title', 'meta_description' => 'Meta description'];
    $flat = fn($t) => trim(preg_replace('/\s+/u', ' ', (string) $t));
    $changed = [];
    foreach ($fields as $field => $label) {
        if ($flat($record['draft_' . $field]) !== $flat($record['final_' . $field])) {
            $changed[$field] = $label;
        }
    }
    if (!$changed) {
        $markLearned();
        return ['proposed' => 0, 'note' => $record['subject_name'] . ': saved as drafted, so there was nothing to learn.'];
    }

    // Everything already approved or waiting, so the AI doesn't propose it again.
    $existing = $pdo->query("SELECT lesson FROM writing_lessons WHERE status IN ('approved', 'proposed') ORDER BY id")
        ->fetchAll(PDO::FETCH_COLUMN);
    $existingBlock = $existing
        ? implode("\n", array_map(fn($l, $i) => ($i + 1) . '. ' . $flat($l), $existing, array_keys($existing)))
        : '(none yet)';

    $request = "Page: {$record['subject_name']}\nCountry: {$record['country_name']}\n";
    foreach ($fields as $field => $label) {
        $draft = (string) $record['draft_' . $field];
        $final = (string) $record['final_' . $field];
        if (!isset($changed[$field])) {
            $request .= "\n== $label: saved exactly as drafted ==\n";
            continue;
        }
        $request .= "\n== $label ==\n<draft>\n$draft\n</draft>\n<saved>\n$final\n</saved>\n";
    }
    $request .= "\nLessons already approved or waiting for a decision (don't repeat these):\n$existingBlock\n";

    $instructions = <<<'TXT'
You study how a human editor changed an AI-written draft before saving it, for a page on Finance Fact Library, a personal finance reference site. Your job is to find GENERAL lessons that would make future drafts need fewer edits.

Propose a lesson only when a change shows a general weakness in how drafts are written, such as: a type of factual mistake, a structural problem (repetition, a heading that doesn't match its text, a needless section), a habit the house style forbids, or something readers need that drafts tend to leave out.

Do NOT propose a lesson for:
- changes that are only wording, tone or taste;
- facts about this page's subject (those belong in the source pages, not in rules);
- anything the house style or an existing lesson already says.

Each lesson must be a rule a writer can follow on any page: one or two sentences, in the imperative, without naming this page's subject (a short example in brackets is fine). If it applies only to one country's systems, set scope to that country's code (for example "GB"); otherwise "all". For each lesson give "before": a short quote from the draft that shows the problem, and "after": the matching quote from the saved text, each copied exactly (at most 30 words).

At most 4 lessons. Returning none is normal and good: most edits teach nothing general.

Reply with JSON only, no other text:
{"lessons": [{"lesson": "...", "scope": "all", "before": "...", "after": "..."}]}
or {"lessons": []}

The house style follows, so you can see what is already covered.

TXT;
    $instructions .= houseStyleText((string) $record['country_prefix']);

    $reply = callClaude($instructions, $request, 2000);

    // The JSON is taken from the first { to the last }, so text around it doesn't matter.
    $start = strpos($reply, '{');
    $end = strrpos($reply, '}');
    $data = ($start !== false && $end > $start) ? json_decode(substr($reply, $start, $end - $start + 1), true) : null;
    if (!is_array($data) || !isset($data['lessons']) || !is_array($data['lessons'])) {
        throw new RuntimeException('The AI reply was not in the expected format. It began: "' . aiReplyStart($reply) . '"');
    }

    $known = array_flip(array_map('lessonKey', $existing));
    $saved = 0;
    foreach (array_slice($data['lessons'], 0, LESSONS_PER_EDIT_MAX) as $item) {
        $lesson = trim((string) ($item['lesson'] ?? ''));
        if ($lesson === '' || isset($known[lessonKey($lesson)])) {
            continue;
        }
        $scope = strtoupper(trim((string) ($item['scope'] ?? 'all')));
        $scope = ($scope === 'ALL' || !preg_match('/^[A-Z]{2,3}$/', $scope)) ? 'all' : $scope;
        addLesson($pdo, $lesson, $scope, 'proposed', 'edit', [
            'example_before' => (string) ($item['before'] ?? ''),
            'example_after'  => (string) ($item['after'] ?? ''),
            'record_id'      => $recordId,
            'subject_id'     => (int) $record['subject_id'],
        ]);
        $known[lessonKey($lesson)] = true;
        $saved++;
    }

    $markLearned();
    return ['proposed' => $saved, 'note' => $record['subject_name'] . ': ' . ($saved === 0
        ? 'no general lessons found.'
        : $saved . ' lesson' . ($saved === 1 ? '' : 's') . ' proposed.')];
}

/**
 * Learn from the oldest edit records not yet looked at, up to $limit.
 * One failing record doesn't stop the others. Returns the notes (one per
 * record), the number of lessons proposed in all, and any errors.
 */
function processPendingEditRecords(PDO $pdo, int $limit = 2): array
{
    // Each AI call leaves the connection idle; see keepDbConnectionOpenForAi().
    keepDbConnectionOpenForAi($pdo);

    $ids = $pdo->query('SELECT id FROM draft_edit_records WHERE learned_at IS NULL ORDER BY id LIMIT ' . max(1, $limit))
        ->fetchAll(PDO::FETCH_COLUMN);

    $result = ['looked_at' => 0, 'proposed' => 0, 'notes' => [], 'errors' => []];
    foreach ($ids as $id) {
        try {
            $one = learnFromEditRecord($pdo, (int) $id);
            $result['looked_at']++;
            $result['proposed'] += $one['proposed'];
            $result['notes'][] = $one['note'];
        } catch (PDOException $e) {
            // A database failure would repeat for every record.
            $result['errors'][] = 'Stopped: database error (' . $e->getMessage() . ')';
            break;
        } catch (Throwable $e) {
            $result['errors'][] = 'Record ' . $id . ': ' . $e->getMessage();
        }
    }
    return $result;
}

// ------------------------------------------------------------
// Managing lessons (the lessons page)
// ------------------------------------------------------------

const LESSON_STATUSES = ['proposed', 'approved', 'rejected', 'retired'];

/**
 * Add a lesson. $extra can hold example_before, example_after, record_id
 * and subject_id. Returns the new id.
 */
function addLesson(PDO $pdo, string $lesson, string $scope, string $status, string $origin, array $extra = []): int
{
    $lesson = trim(preg_replace('/\s+/u', ' ', $lesson));
    if ($lesson === '' || !preg_match('/^.{1,1000}$/su', $lesson)) {
        throw new RuntimeException('A lesson needs some text, and 1,000 characters at most.');
    }
    if (!in_array($status, LESSON_STATUSES, true)) {
        throw new RuntimeException('Unknown lesson status.');
    }
    $scope = ($scope === 'all' || !preg_match('/^[A-Z]{2,3}$/', $scope)) ? 'all' : $scope;

    $short = fn($t) => ($t = trim((string) $t)) === '' ? null
        : (preg_match('/^.{0,600}/su', $t, $m) ? $m[0] : substr($t, 0, 600));

    $pdo->prepare(
        'INSERT INTO writing_lessons (lesson, scope, status, origin, example_before, example_after, record_id, subject_id, decided_at)
         VALUES (:lesson, :scope, :status, :origin, :before, :after, :record, :subject, :decided)'
    )->execute([
        'lesson'  => $lesson,
        'scope'   => $scope,
        'status'  => $status,
        'origin'  => substr($origin, 0, 10),
        'before'  => $short($extra['example_before'] ?? null),
        'after'   => $short($extra['example_after'] ?? null),
        'record'  => $extra['record_id'] ?? null,
        'subject' => $extra['subject_id'] ?? null,
        'decided' => $status === 'proposed' ? null : date('Y-m-d H:i:s'),
    ]);
    return (int) $pdo->lastInsertId();
}

/**
 * Change a lesson's wording (while it's proposed or approved): so an AI
 * suggestion can be tidied before it's approved, and an approved lesson edited in
 * place from the lessons page. The lesson keeps its id, so its reference (L14) stays.
 */
function setLessonText(PDO $pdo, int $lessonId, string $lesson): void
{
    $lesson = trim(preg_replace('/\s+/u', ' ', $lesson));
    if ($lesson === '' || !preg_match('/^.{1,1000}$/su', $lesson)) {
        throw new RuntimeException('A lesson needs some text, and 1,000 characters at most.');
    }
    $pdo->prepare("UPDATE writing_lessons SET lesson = :lesson WHERE id = :id AND status IN ('proposed', 'approved')")
        ->execute(['lesson' => $lesson, 'id' => $lessonId]);
}

/** Approve, reject or retire a lesson. */
function setLessonStatus(PDO $pdo, int $lessonId, string $status): void
{
    if (!in_array($status, LESSON_STATUSES, true) || $status === 'proposed') {
        throw new RuntimeException('Unknown lesson status.');
    }
    $stmt = $pdo->prepare('UPDATE writing_lessons SET status = :status, decided_at = NOW() WHERE id = :id');
    $stmt->execute(['status' => $status, 'id' => $lessonId]);
    if ($stmt->rowCount() === 0) {
        throw new RuntimeException('Lesson not found.');
    }
}

/** The lessons with a given status, with the page each came from (if any), newest first. */
function getLessonsByStatus(PDO $pdo, string $status): array
{
    $stmt = $pdo->prepare(
        'SELECT l.*, s.name AS subject_name
         FROM writing_lessons l
         LEFT JOIN subjects s ON s.id = l.subject_id
         WHERE l.status = :status
         ORDER BY l.id DESC'
    );
    $stmt->execute(['status' => $status]);
    return $stmt->fetchAll();
}

/** How many lessons are waiting for a decision. 0 if the table isn't there yet. */
function countProposedLessons(PDO $pdo): int
{
    try {
        return (int) $pdo->query("SELECT COUNT(*) FROM writing_lessons WHERE status = 'proposed'")->fetchColumn();
    } catch (PDOException $e) {
        return 0;
    }
}

/** The most recent edit records, for the "how much you changed" table. */
function getRecentEditRecords(PDO $pdo, int $limit = 10): array
{
    return $pdo->query(
        'SELECT r.id, r.subject_id, s.name AS subject_name, r.explanation_change_pct, r.saved_at, r.learned_at
         FROM draft_edit_records r
         JOIN subjects s ON s.id = r.subject_id
         ORDER BY r.id DESC LIMIT ' . max(1, $limit)
    )->fetchAll();
}

/** How many edit records haven't been looked at for lessons yet. */
function countPendingEditRecords(PDO $pdo): int
{
    try {
        return (int) $pdo->query('SELECT COUNT(*) FROM draft_edit_records WHERE learned_at IS NULL')->fetchColumn();
    } catch (PDOException $e) {
        return 0;
    }
}
