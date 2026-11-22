<?php
/**
 * Free triage of every page's written text: which pages have the most problems, so the
 * page-by-page work (docs/page-checklist.md) starts where it matters. READ-ONLY, and no AI.
 *
 * WHY IT EXISTS. Every written page has four text fields (intro, explanation, meta title, meta
 * description) and the pages are reviewed one at a time, at about $0.43 of AI and 25 minutes each.
 * The program checks cost nothing and run in a fraction of a second, so run them over every page
 * first, rank the pages, and spend the AI and the time on the worst ones. It also shows which KIND
 * of problem is most common, which says where the writing rules or the checks need work.
 *
 * Usage, from the project root:
 *   php scripts/triage-page-text.php                 ranked list of every written page in a country that is switched on
 *   php scripts/triage-page-text.php --details       also print each page's findings
 *   php scripts/triage-page-text.php --limit=10      only the 10 worst pages
 *   php scripts/triage-page-text.php --country=GB    one country (GB, AU...)
 *   php scripts/triage-page-text.php --page=base-rate   one page, with its findings (a subject slug)
 *   php scripts/triage-page-text.php --include-hidden   also pages in countries not switched on yet
 *   php scripts/triage-page-text.php --csv           the ranked list as CSV, for a spreadsheet
 *   php scripts/triage-page-text.php --close-copies  also run the near-copy check on every page (see below)
 *   php scripts/triage-page-text.php --page=base-rate --no-close-copies   one page, without the near-copy check
 *
 * What it runs on each page's SAVED text, as the editor's "Checks on the saved text" box does
 * (quickTextChecks() in includes/text-checks.php): typed figures that change (including small
 * percentages and amounts), rates on rate pages, dating words, advice, typed tax years, hedge words,
 * a unit repeated after a placeholder, the country's own rules, and an over-long explanation. On top of
 * that it checks lengths with the figures filled in (meta title 42 or fewer, meta description 140 to 155)
 * and fields that are empty.
 *
 * THE NEAR-COPY CHECK (added 22 Nov 2026). Sentences that follow a source page's wording closely (60% of
 * the words in the same order, findCloseCopies() in includes/text-checks.php, the same check the AI check
 * and the drafting loop use) are found by comparing the filled-in explanation and intro with the pages the
 * page's figure cards cite plus its reference sources. That needs those pages downloaded, which takes a few
 * seconds a page, so it runs by default only with --page (the per-page routine in docs/page-checklist.md)
 * and with --close-copies for the whole list. A source page the site cannot read is reported as a finding,
 * because the check is then incomplete. With the source pages read, a hedge word is flagged only when no
 * source page says it too, as in the drafting loop; without them it is flagged in the editor's way,
 * "keep it only if a source says it". Before this was added, a page could be clean here and still copy
 * its sources (Tenancy Deposits, 22 Nov).
 *
 * What it does NOT run: the AI check. The last AI check's finding count is shown, read from the database,
 * where there is one. A page that is clean here can still have substance problems the AI check or a human
 * read would find.
 *
 * It never writes anything, calls no API and uses no key, so it costs nothing and is safe to run anywhere.
 * The near-copy check only reads the official source pages over HTTP, as check-sources.php does.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script can only be run from the command line.\n");
}

ini_set('display_errors', 'stderr');
error_reporting(E_ALL);

// No AI is used, but the check code loads the AI helpers, which want a purpose to be named.
// This one is never used to call anything.
define('AI_KEY_PURPOSE', 'SOURCES');

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/text-checks.php';

$options = getopt('', ['details', 'limit:', 'country:', 'page:', 'include-hidden', 'csv', 'close-copies', 'no-close-copies']);
$details = isset($options['details']) || isset($options['page']);
$limit = isset($options['limit']) ? max(1, (int) $options['limit']) : 0;
$onlyCountry = isset($options['country']) ? strtoupper((string) $options['country']) : null;
$onlyPage = isset($options['page']) ? (string) $options['page'] : null;
$includeHidden = isset($options['include-hidden']);
$asCsv = isset($options['csv']);
// The near-copy check downloads each page's source pages, so it is on for a single page (the routine)
// and opt-in for the whole list. --no-close-copies turns it off for a single page.
$closeCopies = (isset($options['close-copies']) || $onlyPage !== null) && !isset($options['no-close-copies']);

$pdo = getDbConnection();

// A page's country is the country of its category (the parent, when the category sits on a
// state or region), as in scripts/check-page-text.php. A page whose category has no country is
// treated as switched on.
$sql = "SELECT s.id, s.name, s.slug, s.status, s.intro, s.explanation, s.meta_title, s.meta_description,
               s.text_reviewed_at, country.code AS country_code, country.is_active AS country_active
        FROM subjects s
        LEFT JOIN subcategories sc ON sc.id = s.subcategory_id
        LEFT JOIN categories c ON c.id = sc.category_id
        LEFT JOIN jurisdictions j ON j.id = c.jurisdiction_id
        LEFT JOIN jurisdictions country ON country.id = COALESCE(j.parent_id, j.id)
        WHERE s.status IN ('published', 'draft')";
$params = [];
if ($onlyPage !== null) {
    $sql .= ' AND s.slug = :slug';
    $params['slug'] = $onlyPage;
}
$rows = $pdo->prepare($sql . ' ORDER BY s.name');
$rows->execute($params);
$subjects = $rows->fetchAll();

/** The AI check's finding count for a page, from its last saved check, or null if never checked. */
function lastAiFindingCount(PDO $pdo, int $subjectId): ?int
{
    $stmt = $pdo->prepare('SELECT findings FROM subject_text_checks WHERE subject_id = :id');
    $stmt->execute(['id' => $subjectId]);
    $json = $stmt->fetchColumn();
    if ($json === false || $json === null) {
        return null;
    }
    $decoded = json_decode((string) $json, true);
    return is_array($decoded) ? count($decoded) : null;
}

/**
 * Which kind of problem a note is, for the summary. The notes are sentences (see
 * quickTextChecks()), so this reads the wording. An unrecognised note counts as "other".
 */
function triageKind(string $note): string
{
    $kinds = [
        'rate in the text'       => '/it\'s a rate|shows a rate|state rates/',
        'typed figure (card)'    => '/is typed, and it is the same as|is typed\. Use \{fact:/',
        'typed figure (unknown)' => '/isn\'t one of|aren\'t among/',
        'typed tax year'         => '/typed\. Use \{tax_year\}/',
        'dating word'            => '/dates the text/',
        'hedge word'             => '/is a hedge/',
        'close copy of a source' => '/^(Explanation|Intro): follows the wording/',
        'source page not read'   => '/could not be read, so the near-copy check/',
        'unit repeated'          => '/lesson L38/',
        'advice'                 => '/reads as advice/',
        'too long'               => '/words, over the house style/',
        'generic closing'        => '/generic kind the house style leaves out/',
        'meta length'            => '/^Meta (title|description): \d+ characters/',
        'empty field'            => '/^(Intro|Explanation|Meta title|Meta description) is empty/',
    ];
    foreach ($kinds as $kind => $pattern) {
        if (preg_match($pattern, $note)) {
            return $kind;
        }
    }
    return 'other (country rule or not recognised)';
}

$results = [];
$unwritten = 0;
$hiddenSkipped = 0;

foreach ($subjects as $subject) {
    if (trim((string) $subject['explanation']) === '') {
        $unwritten++;
        continue;
    }
    if (!$includeHidden && $subject['country_active'] !== null && (int) $subject['country_active'] === 0) {
        $hiddenSkipped++;
        continue;
    }
    if ($onlyCountry !== null && strtoupper((string) $subject['country_code']) !== $onlyCountry) {
        continue;
    }

    $facts = getFactsForSubject((int) $subject['id']);
    $fields = [
        'intro'            => (string) $subject['intro'],
        'explanation'      => (string) $subject['explanation'],
        'meta_title'       => (string) $subject['meta_title'],
        'meta_description' => (string) $subject['meta_description'],
    ];
    $country = (string) ($subject['country_code'] ?: 'GB');

    // The source pages (card sources and reference sources), when the near-copy check is on. They are
    // also given to quickTextChecks() so a hedge word a source says itself is not flagged.
    $read = $closeCopies ? readSourcePagesForFacts($facts, 50000, (int) $subject['id']) : null;

    // The checks the editor shows on saved text, country rules included.
    $notes = quickTextChecks($fields, $facts, $country, $read['texts'] ?? null);

    // Sentences that follow a source closely, worked out on the filled-in text as the AI check does.
    if ($read !== null) {
        $names = array_map(fn($s) => $s['name'], $read['sources']);
        foreach (['explanation' => 'Explanation', 'intro' => 'Intro'] as $field => $label) {
            $filledText = trim((string) fillPlaceholders($fields[$field], $country, $facts));
            if ($filledText === '') {
                continue;
            }
            foreach (array_slice(findCloseCopies($filledText, $read['texts'], $names), 0, 6) as $copy) {
                $notes[] = "$label: follows the wording of {$copy['source']} closely (" . round($copy['score'] * 100)
                    . '% of the words match, in order): "' . quoteWords($copy['quote'], 25) . '". Restate it in your own words and order.';
            }
        }
        foreach ($read['sources'] as $source) {
            if (empty($source['read'])) {
                $notes[] = "Source page {$source['url']} could not be read, so the near-copy check is incomplete for this page"
                    . (!empty($source['error']) ? " ({$source['error']})." : '.');
            }
        }
    }

    // Fields left empty.
    foreach (TEXT_FIELD_LABELS as $field => $label) {
        if (trim($fields[$field]) === '') {
            $notes[] = "$label is empty.";
        }
    }

    // Meta lengths as search results show them, with the figures filled in.
    foreach (['meta_title' => [0, META_TITLE_MAX, 'Meta title'], 'meta_description' => [META_DESCRIPTION_MIN, META_DESCRIPTION_MAX, 'Meta description']] as $field => [$min, $max, $label]) {
        if (trim($fields[$field]) === '') {
            continue;
        }
        $filled = trim((string) fillPlaceholders($fields[$field], $country, $facts));
        $length = (int) preg_match_all('/./us', $filled);
        if ($length > $max || $length < $min) {
            $notes[] = "$label: $length characters with the figures filled in; the house style asks for "
                . ($min > 0 ? "$min to $max." : "$max or fewer.");
        }
    }

    $results[] = [
        'name'     => $subject['name'],
        'slug'     => $subject['slug'],
        'country'  => $country,
        'status'   => $subject['status'],
        'reviewed' => $subject['text_reviewed_at'] !== null,
        'words'    => (int) preg_match_all('/\S+/u', $fields['explanation']),
        'notes'    => $notes,
        'score'    => count($notes),
        'ai'       => lastAiFindingCount($pdo, (int) $subject['id']),
    ];
}

if ($onlyPage !== null && !$results) {
    fwrite(STDERR, "No written page with the slug '$onlyPage' (or its country isn't switched on: add --include-hidden).\n");
    exit(1);
}

// Worst first; ties by name so the order is stable.
usort($results, fn($a, $b) => [$b['score'], $a['name']] <=> [$a['score'], $b['name']]);

// ---- CSV ----
if ($asCsv) {
    $out = fopen('php://output', 'w');
    fputcsv($out, ['page', 'slug', 'country', 'status', 'findings', 'last_ai_findings', 'explanation_words', 'text_reviewed'], ',', '"', '\\');
    foreach ($results as $r) {
        fputcsv($out, [$r['name'], $r['slug'], $r['country'], $r['status'], $r['score'], $r['ai'] ?? '', $r['words'], $r['reviewed'] ? 'yes' : 'no'], ',', '"', '\\');
    }
    fclose($out);
    exit(0);
}

// ---- Ranked list ----
$shown = $limit > 0 ? array_slice($results, 0, $limit) : $results;
$clean = count(array_filter($results, fn($r) => $r['score'] === 0));

echo 'Free triage of page text (program checks' . ($closeCopies ? ' and near-copy check' : '') . ": no AI, nothing written).\n";
echo count($results) . ' written page(s) checked'
    . ($onlyCountry ? " in $onlyCountry" : '') . "; $clean with no findings.\n";
if ($unwritten) {
    echo "$unwritten page(s) have no explanation yet and are not included.\n";
}
if ($hiddenSkipped) {
    echo "$hiddenSkipped written page(s) in a country that is not switched on were left out (add --include-hidden).\n";
}
echo "\n";

printf("%-3s %-42s %-3s %8s %6s %6s %s\n", '#', 'Page', 'Ctry', 'Findings', 'AI', 'Words', 'Reviewed');
foreach ($shown as $i => $r) {
    printf(
        "%-3d %-42s %-3s %8d %6s %6d %s\n",
        $i + 1,
        mb_strimwidth($r['name'], 0, 42, '...'),
        $r['country'],
        $r['score'],
        $r['ai'] === null ? '-' : (string) $r['ai'],
        $r['words'],
        $r['reviewed'] ? 'yes' : 'no'
    );
}
if ($limit > 0 && count($results) > $limit) {
    echo '... and ' . (count($results) - $limit) . " more.\n";
}

// ---- Which kinds of problem are most common ----
$kindCounts = [];
$pagesWithKind = [];
foreach ($results as $r) {
    foreach ($r['notes'] as $note) {
        $kind = triageKind($note);
        $kindCounts[$kind] = ($kindCounts[$kind] ?? 0) + 1;
        $pagesWithKind[$kind][$r['slug']] = true;
    }
}
arsort($kindCounts);
if ($kindCounts) {
    echo "\nMost common kinds of finding:\n";
    foreach ($kindCounts as $kind => $count) {
        printf("  %-40s %4d findings on %d page(s)\n", $kind, $count, count($pagesWithKind[$kind]));
    }
}

// ---- Findings, page by page ----
if ($details) {
    echo "\n";
    foreach ($shown as $r) {
        if (!$r['notes']) {
            continue;
        }
        echo '== ' . $r['name'] . ' (' . $r['slug'] . ', ' . $r['country'] . ', ' . $r['score'] . " finding" . ($r['score'] === 1 ? '' : 's') . ") ==\n";
        foreach ($r['notes'] as $note) {
            echo '  - ' . $note . "\n";
        }
        echo "\n";
    }
}

echo $closeCopies
    ? "\nThe AI check is not part of this. Run it from the page's editor.\n"
    : "\nThe near-copy check was not run (add --close-copies, or use --page) and the AI check is not part of this. Run the AI check from the page's editor.\n";
exit(0);
