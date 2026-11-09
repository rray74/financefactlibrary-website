<?php
/**
 * Export the finished text of pages to a file, to carry to another copy of the site.
 *
 * WHY THIS EXISTS. Content reaches the site through files and scripts, but the text of
 * a page (its intro, explanation, meta title and meta description) is written in the
 * editor, so it lives only in the database of the machine where it was written. The
 * figures CSV (scripts/import-facts.php) carries the intro and meta text but has no
 * column for the explanation, so a page drafted and edited on your Mac had no way to
 * reach the server except by pasting it in, page by page, and an AI draft that is
 * made again on the server is paid for twice. This script and import-page-text.php
 * close that gap: write and edit where you like, then move the finished text.
 *
 * WHAT IT WRITES. One row per page, in a CSV that scripts/import-page-text.php reads:
 *   jurisdiction_code, category_slug, subcategory_slug, subject_slug   which page
 *   subject_name                                                       for people reading the file
 *   intro, explanation, meta_title, meta_description                   the four fields, exactly as saved
 *   text_reviewed_at                                                   when a person last saved it
 *   exported_at                                                        when this file was made
 * The text is written as it is stored, placeholders like {fact:au_tax_free_threshold}
 * included, never filled in. Nothing in the database changes.
 *
 * Usage, from the project root:
 *   php scripts/export-page-text.php --country=AU                     every Australian page that has an explanation
 *   php scripts/export-page-text.php --country=AU --slug=age-pension,medicare-levy
 *                                                                     only those pages
 *   php scripts/export-page-text.php --country=AU --all               pages with any text, even no explanation yet
 *   php scripts/export-page-text.php --country=AU --out=path/file.csv a different file name
 *   php scripts/export-page-text.php --country=AU --force             overwrite a file of the same name
 *
 * The file goes in data/imports/ by default, named page-text-AU-2026-11-09.csv, so it
 * can be committed and deployed with the rest of the site, then read on the server:
 *   php scripts/import-page-text.php --dry-run data/imports/page-text-AU-2026-11-09.csv
 *
 * Edit text in the admin, not in this file. A spreadsheet program can silently change
 * quotation marks and line breaks when it saves a CSV.
 *
 * NOT CARRIED: the AI check results, the AI drafts, and the lessons learned from your
 * edits (writing_lessons). Lessons belong to the machine where they were approved.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script can only be run from the command line.\n");
}

ini_set('display_errors', 'stderr');
error_reporting(E_ALL);

require_once __DIR__ . '/../config/database.php';

/** The columns of the file, in order. import-page-text.php reads them by name. */
const PAGE_TEXT_COLUMNS = [
    'jurisdiction_code', 'category_slug', 'subcategory_slug', 'subject_slug', 'subject_name',
    'intro', 'explanation', 'meta_title', 'meta_description', 'text_reviewed_at', 'exported_at',
];

$options = getopt('', ['country:', 'slug:', 'all', 'out:', 'force']);

$country = isset($options['country']) ? strtoupper(trim((string) $options['country'])) : '';
// --slug may be given once with commas, or repeated.
$slugs = [];
foreach ((array) ($options['slug'] ?? []) as $given) {
    foreach (explode(',', (string) $given) as $slug) {
        if (trim($slug) !== '') {
            $slugs[] = trim($slug);
        }
    }
}
if ($country === '' && !$slugs) {
    fwrite(STDERR, "Say which pages: --country=AU for a country, and/or --slug=page-one,page-two.\n"
        . "See the notes at the top of scripts/export-page-text.php.\n");
    exit(1);
}

$pdo = getDbConnection();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// A page's country is the country of its category (categories belong to a country,
// and a figure for one state or nation sits in its country's categories).
$sql = "SELECT s.slug, s.name, s.status, s.intro, s.explanation, s.meta_title, s.meta_description, s.text_reviewed_at,
               sc.slug AS subcategory_slug, c.slug AS category_slug, j.code AS country_code
        FROM subjects s
        JOIN subcategories sc ON sc.id = s.subcategory_id
        JOIN categories c ON c.id = sc.category_id
        JOIN jurisdictions j ON j.id = c.jurisdiction_id
        WHERE 1 = 1";
$params = [];
if ($country !== '') {
    $sql .= ' AND j.code = :country';
    $params['country'] = $country;
}
if ($slugs) {
    $marks = [];
    foreach ($slugs as $i => $slug) {
        $marks[] = ':s' . $i;
        $params['s' . $i] = $slug;
    }
    $sql .= ' AND s.slug IN (' . implode(', ', $marks) . ')';
}
if (!isset($options['all'])) {
    // Written pages only. A field holding just spaces or line breaks counts as empty:
    // the pattern needs at least one character that is not whitespace (TRIM() would
    // miss a lone line break).
    $sql .= " AND s.explanation REGEXP '[^[:space:]]'";
}
$stmt = $pdo->prepare($sql . ' ORDER BY s.id');
$stmt->execute($params);
$pages = $stmt->fetchAll();

// A slug that was asked for but is not here is worth saying, not silently skipping.
foreach ($slugs as $slug) {
    if (!in_array($slug, array_column($pages, 'slug'), true)) {
        fwrite(STDERR, "Not exported: '$slug' (no such page" . ($country !== '' ? " in $country" : '')
            . (isset($options['all']) ? '' : ', or it has no explanation yet. Add --all to include pages without one') . ").\n");
    }
}
if (!$pages) {
    fwrite(STDERR, "Nothing to export.\n");
    exit(1);
}

$path = isset($options['out']) && $options['out'] !== true
    ? (string) $options['out']
    : __DIR__ . '/../data/imports/page-text-' . ($country !== '' ? $country : 'selected') . '-' . date('Y-m-d') . '.csv';
if (is_file($path) && !isset($options['force'])) {
    fwrite(STDERR, "The file already exists: $path\nUse --force to replace it, or --out=another-name.csv.\n");
    exit(1);
}
if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0775, true)) {
    fwrite(STDERR, 'Could not create the folder ' . dirname($path) . "\n");
    exit(1);
}

$handle = fopen($path, 'w');
if ($handle === false) {
    fwrite(STDERR, "Could not write $path\n");
    exit(1);
}
// The fifth argument of fputcsv() is the escape character. An empty one keeps a
// backslash in the text as an ordinary backslash.
fputcsv($handle, PAGE_TEXT_COLUMNS, ',', '"', '');

$exportedAt = date('Y-m-d H:i:s');
foreach ($pages as $page) {
    $row = [
        'jurisdiction_code' => $page['country_code'],
        'category_slug'     => $page['category_slug'],
        'subcategory_slug'  => $page['subcategory_slug'],
        'subject_slug'      => $page['slug'],
        'subject_name'      => $page['name'],
        // Line endings are written as plain \n so the file is the same whichever machine made it.
        'intro'             => str_replace("\r\n", "\n", (string) $page['intro']),
        'explanation'       => str_replace("\r\n", "\n", (string) $page['explanation']),
        'meta_title'        => (string) $page['meta_title'],
        'meta_description'  => (string) $page['meta_description'],
        'text_reviewed_at'  => (string) $page['text_reviewed_at'],
        'exported_at'       => $exportedAt,
    ];
    fputcsv($handle, array_map(fn($column) => $row[$column], PAGE_TEXT_COLUMNS), ',', '"', '');
}
fclose($handle);

echo 'Exported ' . count($pages) . " page(s) to $path\n\n";
foreach ($pages as $page) {
    $words = preg_match_all('/\S+/u', (string) $page['explanation']);
    echo sprintf("  %-34s %-9s %4d words  %s\n", $page['slug'], $page['status'], $words,
        $page['text_reviewed_at'] ? 'reviewed ' . substr($page['text_reviewed_at'], 0, 10) : 'not yet reviewed (saved in the editor)');
}
echo "\nTo read it on the other machine, once its figures are imported there:\n"
    . '  php scripts/import-page-text.php --dry-run ' . $path . "\n";
exit(0);
