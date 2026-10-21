<?php
/**
 * Review flags (page-writing procedure, step 5): which written pages need
 * another look, and why.
 *
 * Only pages with an explanation are flagged; pages without one are in
 * the writing queue instead. Worked out live from the database each time,
 * so a flag disappears as soon as its cause is dealt with. The reasons:
 *
 *   figure_changed  a figure on the page changed after the text was last
 *                   reviewed (subjects.text_reviewed_at, migration 014).
 *                   Placeholders update themselves, but the sentences
 *                   around them may not still be right. Rates (checked
 *                   every 42 days or less) are left out: they change all
 *                   the time and the house style keeps them out of the text.
 *   ai_findings     the latest AI check of the saved text found problems
 *                   (only when that check was of the text as saved)
 *   placeholder     a placeholder in the saved text no longer works, e.g.
 *                   its fact was retired, so readers see nothing there
 *   search_listing  shown in search often, near the top, but hardly ever
 *                   clicked: the meta title and description need work
 *   yearly          a year since the text was last reviewed
 *
 * Saving the page details in the editor marks the text as reviewed, which
 * clears figure_changed and yearly, and holds off search_listing for
 * SEARCH_FLAG_QUIET_DAYS so a new title has time to show results. The
 * other two clear when the problem is fixed and checked again.
 */

require_once __DIR__ . '/admin-queries.php'; // getSubjectTextCheck(), search data
require_once __DIR__ . '/text-checks.php';   // textCheckHash(), RATE_REVIEW_DAYS

/** search_listing: at least this many impressions in the 28-day period... */
const SEARCH_FLAG_MIN_IMPRESSIONS = 100;
/** ...fewer clicks than this share of them... */
const SEARCH_FLAG_MAX_CTR = 0.01;
/** ...and an average position this good or better. Lower in the results,
 *  few clicks is a ranking problem, not a title problem. */
const SEARCH_FLAG_MAX_POSITION = 20;
/** Days after a save before search_listing can flag the page again. */
const SEARCH_FLAG_QUIET_DAYS = 28;

/** Days after the last review before the yearly review is due. */
const YEARLY_REVIEW_DAYS = 365;

/** Labels for each reason, in order of importance. */
const REVIEW_FLAG_LABELS = [
    'ai_findings'    => 'AI check found problems',
    'placeholder'    => 'Placeholder not working',
    'figure_changed' => 'Figure changed',
    'search_listing' => 'Search listing not clicked',
    'yearly'         => 'Yearly review due',
];

/**
 * Every written page that needs a look, most important first: pages with
 * more serious reasons first, then the most-searched. Each item:
 *   id, name, status, impressions, text_reviewed_at
 *   reasons  list of ['kind' => key of REVIEW_FLAG_LABELS, 'detail' => sentence]
 */
function getReviewFlags(): array
{
    $pdo = getDbConnection();
    $pages = $pdo->query(
        "SELECT s.id, s.name, s.status, s.intro, s.explanation, s.meta_title, s.meta_description,
                s.text_reviewed_at
         FROM subjects s
         WHERE s.status IN ('published', 'draft') AND COALESCE(s.explanation, '') <> ''"
    )->fetchAll();

    $flagged = [];
    foreach ($pages as $page) {
        $reasons = reviewFlagReasons($pdo, $page);
        if ($reasons) {
            $flagged[] = $page + ['reasons' => $reasons,
                'impressions' => (int) (getSearchStatsForPath(subjectUrlById((int) $page['id']))['impressions'] ?? 0)];
        }
    }

    // Most important reason first, then the most-searched page.
    $rank = array_flip(array_keys(REVIEW_FLAG_LABELS));
    usort($flagged, fn($a, $b) =>
        [$rank[$a['reasons'][0]['kind']], $b['impressions'], $a['name']]
        <=> [$rank[$b['reasons'][0]['kind']], $a['impressions'], $b['name']]);
    return $flagged;
}

/** The reasons one page is flagged, most important first (empty if none). */
function getReviewFlagsForSubject(int $subjectId): array
{
    $pdo = getDbConnection();
    $stmt = $pdo->prepare(
        'SELECT id, name, status, intro, explanation, meta_title, meta_description, text_reviewed_at
         FROM subjects WHERE id = :id'
    );
    $stmt->execute(['id' => $subjectId]);
    $page = $stmt->fetch();
    return ($page && trim((string) $page['explanation']) !== '') ? reviewFlagReasons($pdo, $page) : [];
}

/** Work out the reasons for one page row (see the top of this file). */
function reviewFlagReasons(PDO $pdo, array $page): array
{
    $subjectId = (int) $page['id'];
    $reviewedAt = $page['text_reviewed_at'] ? strtotime($page['text_reviewed_at']) : null;
    $fields = [
        'intro'            => $page['intro'],
        'explanation'      => $page['explanation'],
        'meta_title'       => $page['meta_title'],
        'meta_description' => $page['meta_description'],
    ];
    $reasons = [];

    // --- ai_findings: only a check of exactly the saved text counts. ---
    // Only findings about whether the text is right count. Style findings
    // (said twice, section not needed...) are shown in the editor, but a
    // finding can't be dismissed, so one you've decided to accept would flag
    // the page for good.
    $check = getSubjectTextCheck($subjectId);
    if ($check && $check['findings'] && $check['text_hash'] === textCheckHash($fields)) {
        $accuracy = array_filter($check['findings'], fn($f) => !in_array($f['kind'], TEXT_CHECK_STYLE_KINDS, true));
        $count = count($accuracy);
        if ($count > 0) {
            $reasons[] = ['kind' => 'ai_findings', 'detail' => "$count problem" . ($count === 1 ? '' : 's')
                . ' found on ' . date('j M', strtotime($check['checked_at'])) . '.'];
        }
    }

    // --- placeholder: errors in the saved text. ---
    $placeholderErrors = checkPlaceholders($pdo, [
        'Intro' => $page['intro'], 'Explanation' => $page['explanation'],
        'Meta title' => $page['meta_title'], 'Meta description' => $page['meta_description'],
    ])['errors'];
    foreach ($placeholderErrors as $error) {
        $reasons[] = ['kind' => 'placeholder', 'detail' => $error];
    }

    // --- figure_changed: real changes since the last review, rates left out. ---
    $stmt = $pdo->prepare(
        "SELECT fh.old_value, fh.old_value_numeric, fh.new_value, fh.new_value_numeric, fh.changed_at,
                f.label, f.unit, f.value_type
         FROM fact_history fh
         JOIN facts f ON f.id = fh.fact_id AND f.status = 'published'
         JOIN subject_facts sf ON sf.fact_id = f.id AND sf.subject_id = :sid
         WHERE fh.old_value IS NOT NULL
           AND fh.changed_at > :reviewed
           AND f.review_frequency_days > :rate_days
           -- Same filter as the page's 'What's changed': rows where the value
           -- didn't actually change aren't changes.
           AND fh.old_value <> fh.new_value
           AND NOT (fh.old_value_numeric IS NOT NULL AND fh.new_value_numeric IS NOT NULL
                    AND fh.old_value_numeric = fh.new_value_numeric)
         ORDER BY fh.changed_at"
    );
    $stmt->execute([
        'sid'       => $subjectId,
        'reviewed'  => $reviewedAt ? date('Y-m-d H:i:s', $reviewedAt) : '1970-01-01',
        'rate_days' => RATE_REVIEW_DAYS,
    ]);
    foreach ($stmt->fetchAll() as $change) {
        $reasons[] = ['kind' => 'figure_changed', 'detail' => $change['label'] . ' changed from '
            . formatHistoryValue($change, 'old') . ' to ' . formatHistoryValue($change, 'new')
            . ' on ' . date('j M Y', strtotime($change['changed_at'])) . '. Check the sentences around it.'];
    }

    // --- search_listing: seen near the top but not clicked. ---
    $quiet = $reviewedAt && $reviewedAt > time() - SEARCH_FLAG_QUIET_DAYS * 86400;
    $search = getSearchStatsForPath(subjectUrlById($subjectId));
    if (!$quiet && $search
        && (int) $search['impressions'] >= SEARCH_FLAG_MIN_IMPRESSIONS
        && (float) $search['ctr'] < SEARCH_FLAG_MAX_CTR
        && (float) $search['position'] <= SEARCH_FLAG_MAX_POSITION) {
        $reasons[] = ['kind' => 'search_listing', 'detail' => number_format((int) $search['impressions'])
            . ' impressions but ' . (int) $search['clicks'] . ((int) $search['clicks'] === 1 ? ' click' : ' clicks')
            . ', at average position '
            . number_format((float) $search['position'], 1)
            . '. Rework the meta title and description around the top searches.'];
    }

    // --- yearly: a year since the last review. ---
    if (!$reviewedAt || $reviewedAt < time() - YEARLY_REVIEW_DAYS * 86400) {
        $reasons[] = ['kind' => 'yearly', 'detail' => $reviewedAt
            ? 'Last reviewed ' . date('j M Y', $reviewedAt) . '.'
            : 'Not reviewed since the review date was introduced.'];
    }

    // Most important first, as REVIEW_FLAG_LABELS orders them.
    $rank = array_flip(array_keys(REVIEW_FLAG_LABELS));
    usort($reasons, fn($a, $b) => $rank[$a['kind']] <=> $rank[$b['kind']]);
    return $reasons;
}
