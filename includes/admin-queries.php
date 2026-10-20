<?php
/**
 * Read-side queries used only by the admin pages.
 *
 * Kept apart from includes/functions.php because these deliberately see
 * everything, including draft and retired subjects and unpublished
 * facts, which the public pages must never show. Nothing here changes
 * data. Writes go through includes/fact-writer.php.
 */

require_once __DIR__ . '/functions.php';

/**
 * Intros shorter than this are flagged as short, on the subjects list and
 * the dashboard. Matches the house style (docs/house-style/core.md): one
 * sentence of 120 to 200 characters. Was 200, which flagged intros that
 * follow the house style.
 */
const SHORT_INTRO_CHARACTERS = 120;

/**
 * Every subject, whatever its status, with where it sits in the site and
 * a few numbers for the subjects list. Ordered the way the site's
 * navigation is: category, then subcategory, then subject.
 */
function getAdminSubjectList(): array
{
    $pdo = getDbConnection();
    return $pdo->query(
        "SELECT s.id, s.name, s.slug, s.status, s.intro, s.meta_title, s.meta_description,
                -- Explanation length in characters (column from migration 010).
                CHAR_LENGTH(COALESCE(s.explanation, '')) AS explanation_length,
                sc.name AS subcategory_name, c.name AS category_name, j.code AS country_code,
                -- Figures the public page actually shows (published facts only).
                (SELECT COUNT(*) FROM subject_facts sf
                 JOIN facts f ON f.id = sf.fact_id AND f.status = 'published'
                 WHERE sf.subject_id = s.id) AS published_facts,
                -- Facts this page owns, whatever their status.
                (SELECT COUNT(*) FROM facts f2 WHERE f2.primary_subject_id = s.id) AS owned_facts
         FROM subjects s
         JOIN subcategories sc ON sc.id = s.subcategory_id
         JOIN categories c ON c.id = sc.category_id
         JOIN jurisdictions j ON j.id = c.jurisdiction_id
         ORDER BY j.id, c.name, sc.name, s.name"
    )->fetchAll();
}

/**
 * One subject by id, whatever its status, with its category,
 * subcategory and country for the editor's heading and links.
 */
function getAdminSubject(int $subjectId): ?array
{
    $pdo = getDbConnection();
    $stmt = $pdo->prepare(
        'SELECT s.*, sc.name AS subcategory_name, sc.slug AS subcategory_slug,
                c.name AS category_name, c.slug AS category_slug,
                j.id AS country_id, j.url_prefix AS country_prefix, j.name AS country_name,
                -- For {tax_year} and the house style file of the country.
                j.code AS country_code
         FROM subjects s
         JOIN subcategories sc ON sc.id = s.subcategory_id
         JOIN categories c ON c.id = sc.category_id
         JOIN jurisdictions j ON j.id = c.jurisdiction_id
         WHERE s.id = :id
         LIMIT 1'
    );
    $stmt->execute(['id' => $subjectId]);
    return $stmt->fetch() ?: null;
}

/**
 * Every fact linked to a subject page, in the page's order, whatever the
 * fact's status, so the editor can see drafts and retired facts too.
 *
 * Unlike getFactsForSubject() (the public version), `context` here is
 * always the fact's own context, and the page-specific override is
 * returned separately as context_override, because the editor needs to
 * know which one it's looking at.
 *
 *   is_primary   1 if this page owns the fact (only then is it editable
 *                here; shared facts are edited on their owner's page)
 *   owner_id / owner_name   the owning page
 *   linked_pages            how many pages show this fact in total
 */
function getAdminFactsForSubject(int $subjectId): array
{
    $pdo = getDbConnection();
    $stmt = $pdo->prepare(
        'SELECT f.*, sf.context_override,
                (f.primary_subject_id = sf.subject_id) AS is_primary,
                owner.id AS owner_id, owner.name AS owner_name,
                src.publisher AS source_name, src.url AS source_url,
                j.code AS jurisdiction_code, j.name AS jurisdiction_name,
                j.parent_id AS jurisdiction_parent_id,
                (SELECT COUNT(*) FROM subject_facts sf2 WHERE sf2.fact_id = f.id) AS linked_pages
         FROM subject_facts sf
         JOIN facts f ON f.id = sf.fact_id
         JOIN subjects owner ON owner.id = f.primary_subject_id
         LEFT JOIN sources src ON src.id = f.source_id
         JOIN jurisdictions j ON j.id = f.jurisdiction_id
         WHERE sf.subject_id = :sid
         ORDER BY sf.sort_order ASC, f.label ASC'
    );
    $stmt->execute(['sid' => $subjectId]);
    return $stmt->fetchAll();
}

/**
 * Every subcategory, with its category and country, for the "where does
 * this page go" choice when adding a subject. Ordered like the site.
 */
function getAdminSubcategoryOptions(): array
{
    $pdo = getDbConnection();
    return $pdo->query(
        'SELECT sc.id, sc.name, c.name AS category_name, j.name AS country_name
         FROM subcategories sc
         JOIN categories c ON c.id = sc.category_id
         JOIN jurisdictions j ON j.id = c.jurisdiction_id
         ORDER BY j.id, c.name, sc.name'
    )->fetchAll();
}

/**
 * A country and its nations/regions (e.g. United Kingdom, then Scotland,
 * Wales...), for the "applies to" choice when adding a fact. The country
 * itself comes first, since most figures are country-wide.
 */
function getJurisdictionOptions(int $countryId): array
{
    $pdo = getDbConnection();
    $stmt = $pdo->prepare(
        'SELECT id, name, parent_id FROM jurisdictions
         WHERE id = :cid OR parent_id = :cid2
         ORDER BY parent_id IS NOT NULL, name'
    );
    $stmt->execute(['cid' => $countryId, 'cid2' => $countryId]);
    return $stmt->fetchAll();
}

// ------------------------------------------------------------
// Dashboard (/admin/index.php)
// ------------------------------------------------------------

/**
 * Scheduled scripts the dashboard expects to see in cron_runs, with when
 * they're meant to run. A last run more than 8 days ago means the cron
 * job has stopped (the weekly ones would be a day late by then). Add new
 * cron scripts here.
 */
const ADMIN_EXPECTED_CRON_SCRIPTS = [
    'fetch-boe-rates.php'      => 'Daily 07:00',
    'check-sources.php'        => 'Daily 07:30',
    'fetch-search-console.php' => 'Daily 06:00',
    'draft-pages.php'          => 'Daily 05:00',
    'check-page-text.php'      => 'Daily 05:30',
    'send-alerts.php'          => 'Daily 08:00',
];

/**
 * How many days after its last start a script counts as stopped. The
 * default suits a weekly job (a day late by then); daily jobs get 2, so
 * one missed run shows up on the dashboard instead of going unnoticed
 * for over a week. A script not listed here uses the default.
 */
const ADMIN_CRON_STALE_DAYS = 8;
const ADMIN_CRON_STALE_DAYS_BY_SCRIPT = [
    'fetch-boe-rates.php'      => 2,
    'check-sources.php'        => 2,
    'fetch-search-console.php' => 2,
    'draft-pages.php'          => 2,
    'check-page-text.php'      => 2,
    'send-alerts.php'          => 2,
];

/** Days after which a script's last run counts as stale (see above). */
function cronStaleDays(string $script): int
{
    return ADMIN_CRON_STALE_DAYS_BY_SCRIPT[$script] ?? ADMIN_CRON_STALE_DAYS;
}

/**
 * Describe one script's latest run: when it started, and a state of
 * ok / error / running / stuck / stale / never, with a label and whether
 * it's a problem ('bad'). Used by the dashboard's Scheduled jobs table
 * and by the email alerts (includes/alerts.php), so both agree.
 *
 * $run is the script's row from getLatestCronRuns(), or NULL if it has
 * never run; $script picks the stale limit.
 */
function describeCronRun(?array $run, string $script = ''): array
{
    if ($run === null) {
        return ['started' => null, 'state' => 'never', 'label' => 'No runs recorded', 'bad' => true];
    }

    // The start-time column's name isn't fixed across environments, so
    // use whichever exists.
    $startedRaw = $run['started_at'] ?? $run['created_at'] ?? null;
    $started = $startedRaw ? strtotime($startedRaw) : null;
    $finished = !empty($run['finished_at']);
    $ageDays = $started ? (time() - $started) / 86400 : null;

    if (!$finished) {
        // Still marked as running more than an hour after starting means
        // it died without recording an outcome.
        $stuck = $started !== null && time() - $started > 3600;
        return ['started' => $started, 'state' => $stuck ? 'stuck' : 'running',
                'label' => $stuck ? 'Didn\'t finish' : 'Running now', 'bad' => $stuck];
    }
    if ($ageDays !== null && $ageDays > cronStaleDays($script)) {
        // The schedule has stopped.
        return ['started' => $started, 'state' => 'stale',
                'label' => 'Last run ' . floor($ageDays) . ' days ago', 'bad' => true];
    }
    $ok = ($run['status'] ?? '') === 'ok';
    return ['started' => $started, 'state' => $ok ? 'ok' : 'error',
            'label' => $ok ? 'OK' : 'Problem', 'bad' => !$ok];
}

/**
 * The latest cron_runs row for each script, keyed by script name. Uses
 * SELECT * so it works whatever the start-time column is called; the
 * dashboard reads started_at or created_at, whichever exists.
 */
function getLatestCronRuns(): array
{
    $pdo = getDbConnection();
    $rows = $pdo->query(
        'SELECT cr.*
         FROM cron_runs cr
         JOIN (SELECT script, MAX(id) AS latest_id FROM cron_runs GROUP BY script) latest
              ON latest.latest_id = cr.id
         ORDER BY cr.script'
    )->fetchAll();

    $runs = [];
    foreach ($rows as $row) {
        $runs[$row['script']] = $row;
    }
    return $runs;
}

/**
 * Headline numbers for the dashboard, in one query. "Short intro" uses
 * SHORT_INTRO_CHARACTERS, the same guide as the subjects list.
 */
function getDashboardCounts(): array
{
    $pdo = getDbConnection();
    return $pdo->query(
        "SELECT
            (SELECT COUNT(*) FROM subjects WHERE status = 'published') AS published_subjects,
            (SELECT COUNT(*) FROM subjects WHERE status = 'draft') AS draft_subjects,
            (SELECT COUNT(*) FROM facts WHERE status = 'published') AS published_facts,
            (SELECT COUNT(*) FROM facts WHERE status = 'draft') AS draft_facts,
            (SELECT COUNT(DISTINCT source_id) FROM facts WHERE status = 'published') AS sources_used,
            (SELECT COUNT(*) FROM subjects
             WHERE status = 'published' AND CHAR_LENGTH(COALESCE(intro, '')) < " . SHORT_INTRO_CHARACTERS . ") AS short_intros,
            (SELECT COUNT(*) FROM subjects
             WHERE status = 'published' AND COALESCE(explanation, '') = '') AS no_explanation"
    )->fetch();
}

/**
 * The most recent real value changes across the whole site, newest
 * first, with how each was made (manual, import, pipeline).
 */
function getRecentFactChanges(int $limit = 10): array
{
    $pdo = getDbConnection();
    return $pdo->query(
        "SELECT fh.old_value, fh.old_value_numeric, fh.new_value, fh.new_value_numeric,
                fh.change_source, fh.changed_at,
                f.label, f.unit, f.value_type, f.primary_subject_id
         FROM fact_history fh
         JOIN facts f ON f.id = fh.fact_id
         WHERE fh.old_value IS NOT NULL
         ORDER BY fh.changed_at DESC, fh.id DESC
         LIMIT " . (int) $limit
    )->fetchAll();
}

// ------------------------------------------------------------
// Search Console (migration 011, filled by
// scripts/fetch-search-console.php). All for the latest 28-day period.
// ------------------------------------------------------------

/**
 * Site totals for the period, or NULL if there's no data yet. Position
 * is the average weighted by impressions, as Search Console shows it.
 */
function getSearchTotals(): ?array
{
    $pdo = getDbConnection();
    $row = $pdo->query(
        'SELECT COUNT(*) AS pages, SUM(clicks) AS clicks, SUM(impressions) AS impressions,
                SUM(position * impressions) / NULLIF(SUM(impressions), 0) AS position,
                MIN(period_start) AS period_start, MAX(period_end) AS period_end, MAX(fetched_at) AS fetched_at
         FROM search_console_pages'
    )->fetch();
    return ($row && (int) $row['pages'] > 0) ? $row : null;
}

/** The pages with the most impressions in the period. */
function getTopSearchPages(int $limit = 10): array
{
    $pdo = getDbConnection();
    return $pdo->query(
        'SELECT page_path, clicks, impressions, ctr, position
         FROM search_console_pages
         ORDER BY impressions DESC, clicks DESC
         LIMIT ' . (int) $limit
    )->fetchAll();
}

/** One page's figures for the period (by its path, e.g. /uk/tax/...), or NULL. */
function getSearchStatsForPath(string $path): ?array
{
    $pdo = getDbConnection();
    $stmt = $pdo->prepare('SELECT * FROM search_console_pages WHERE page_path = :path');
    $stmt->execute(['path' => $path]);
    return $stmt->fetch() ?: null;
}

/** The searches that showed a page most often in the period. */
function getTopQueriesForPath(string $path, int $limit = 10): array
{
    $pdo = getDbConnection();
    $stmt = $pdo->prepare(
        'SELECT query, clicks, impressions, position
         FROM search_console_queries
         WHERE page_path = :path
         ORDER BY impressions DESC
         LIMIT ' . (int) $limit
    );
    $stmt->execute(['path' => $path]);
    return $stmt->fetchAll();
}

// ------------------------------------------------------------
// Page writing (subject_drafts, migration 012)
// ------------------------------------------------------------

/** A subject's stored AI draft, or NULL if it has none. */
function getSubjectDraft(int $subjectId): ?array
{
    $pdo = getDbConnection();
    $stmt = $pdo->prepare('SELECT * FROM subject_drafts WHERE subject_id = :sid');
    $stmt->execute(['sid' => $subjectId]);
    return $stmt->fetch() ?: null;
}

/**
 * Pages still to be written: published and draft subjects with no
 * explanation, most-searched first (Google impressions in the last 28
 * days), then published before draft, then by name. Each row has
 * impressions, has_draft and draft_created_at. Used by the dashboard's
 * writing queue and by scripts/draft-pages.php, so both work in the same
 * order.
 */
function getWritingQueue(): array
{
    $pdo = getDbConnection();
    $rows = $pdo->query(
        "SELECT s.id, s.name, s.status,
                (d.subject_id IS NOT NULL) AS has_draft, d.created_at AS draft_created_at,
                -- Only pages with at least one published figure can be drafted.
                (SELECT COUNT(*) FROM subject_facts sf
                 JOIN facts f ON f.id = sf.fact_id AND f.status = 'published'
                 WHERE sf.subject_id = s.id) AS published_facts
         FROM subjects s
         LEFT JOIN subject_drafts d ON d.subject_id = s.id
         WHERE s.status IN ('published', 'draft') AND COALESCE(s.explanation, '') = ''"
    )->fetchAll();

    // Impressions by page path. Paths are built in PHP (subjectUrlById),
    // so they're matched here rather than joined in SQL.
    $impressions = $pdo->query('SELECT page_path, impressions FROM search_console_pages')
        ->fetchAll(PDO::FETCH_KEY_PAIR);

    foreach ($rows as &$row) {
        $row['path'] = subjectUrlById((int) $row['id']);
        $row['impressions'] = (int) ($impressions[$row['path']] ?? 0);
    }
    unset($row);

    usort($rows, fn($a, $b) => [$b['impressions'], $a['status'] !== 'published', $a['name']]
        <=> [$a['impressions'], $b['status'] !== 'published', $b['name']]);
    return $rows;
}

/**
 * A subject's latest AI text check (subject_text_checks, migration 013),
 * with findings and sources decoded, or NULL if it's never been checked.
 */
function getSubjectTextCheck(int $subjectId): ?array
{
    $pdo = getDbConnection();
    $stmt = $pdo->prepare('SELECT * FROM subject_text_checks WHERE subject_id = :sid');
    $stmt->execute(['sid' => $subjectId]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }
    $row['findings'] = json_decode((string) $row['findings'], true) ?: [];
    $row['sources'] = json_decode((string) $row['sources'], true) ?: [];
    return $row;
}
