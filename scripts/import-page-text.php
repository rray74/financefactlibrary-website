<?php
/**
 * Read page text from a file made by scripts/export-page-text.php.
 *
 * Moves the intro, explanation, meta title and meta description of pages from one copy
 * of the site to another (your Mac to the server, say) without writing them again or
 * paying for another AI draft. See export-page-text.php for why this exists.
 *
 * Usage, from the project root:
 *   php scripts/import-page-text.php --dry-run data/imports/page-text-AU-2026-11-09.csv
 *   php scripts/import-page-text.php data/imports/page-text-AU-2026-11-09.csv
 *   php scripts/import-page-text.php --overwrite data/imports/page-text-AU-2026-11-09.csv
 * Always run it with --dry-run first. It says what each page would do and writes nothing.
 *
 * THE PAGES MUST ALREADY BE HERE. Import the figures first (scripts/import-facts.php).
 * That creates each page and its figures, and a page's text can only use a placeholder
 * such as {fact:au_tax_free_threshold} once that figure exists and is published here.
 * A page that doesn't exist is reported, not created.
 *
 * WHAT IT NEVER DOES
 *   - It never changes a figure, a source, a category, or a page's status. A page that is
 *     a draft stays a draft, so importing text cannot make anything go live.
 *   - It never marks a figure verified. (Re-running the figures CSV would, and
 *     would claim a check that did not happen.)
 *   - It never replaces a page that is already written here. If the page on this
 *     machine already has an explanation, and the text in the file differs from the
 *     page's text, that page is SKIPPED and said so, with nothing changed. That protects text edited on
 *     the live site. --overwrite replaces it on purpose.
 *   - It never imports text that would not save in the editor: a placeholder that names a
 *     figure which doesn't exist here or isn't published, or a meta title over 100 characters
 *     or a meta description over 200 (the limits of the database columns, counted with the
 *     placeholders still in).
 *
 * A page that is NOT yet written here (no explanation, only the intro and meta text that
 * the figures CSV supplied) is simply filled: the file's text replaces what is there.
 *
 * THE REVIEW DATE. text_reviewed_at is when a person last saved the text in the editor.
 * It is carried across as it was, never stamped with today, because nobody has reviewed
 * the text on this machine just by importing it. If you want a person to look at it here,
 * open the page in the editor and save it.
 *
 * One bad row doesn't stop the others. The exit code is 1 if any row had an error.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script can only be run from the command line.\n");
}

ini_set('display_errors', 'stderr');
error_reporting(E_ALL);

require_once __DIR__ . '/../config/database.php';
// Only the placeholder CHECKS are needed (they need a database connection and nothing else).
require_once __DIR__ . '/../includes/placeholders.php';

/** The text fields, with the names used in messages and the column limits (characters; null = no limit). */
const PAGE_TEXT_FIELDS = [
    'intro'            => ['label' => 'Intro',            'limit' => null],
    'explanation'      => ['label' => 'Explanation',      'limit' => null],
    'meta_title'       => ['label' => 'Meta title',       'limit' => 100],
    'meta_description' => ['label' => 'Meta description', 'limit' => 200],
];

// ---------------------------------------------------------------
// Arguments
// ---------------------------------------------------------------

$flags = [];
$files = [];
foreach (array_slice($argv, 1) as $arg) {
    if (in_array($arg, ['--dry-run', '--overwrite'], true)) {
        $flags[$arg] = true;
    } elseif (strpos($arg, '--') === 0) {
        fwrite(STDERR, "Unknown option: $arg\nSee the notes at the top of scripts/import-page-text.php.\n");
        exit(2);
    } else {
        $files[] = $arg;
    }
}
if (count($files) !== 1) {
    fwrite(STDERR, "Usage: php scripts/import-page-text.php [--dry-run] [--overwrite] path/to/page-text-AU-2026-11-09.csv\n");
    exit(1);
}
$dryRun = isset($flags['--dry-run']);
$overwrite = isset($flags['--overwrite']);
$path = $files[0];
if (!is_readable($path)) {
    fwrite(STDERR, "Cannot read file: $path\n");
    exit(1);
}

$pdo = getDbConnection();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// ---------------------------------------------------------------
// Read the file
// ---------------------------------------------------------------

$handle = fopen($path, 'r');
// An empty escape character (the fifth argument) keeps backslashes in the text as they are.
$header = fgetcsv($handle, 0, ',', '"', '');
if ($header === false || $header === [null]) {
    fwrite(STDERR, "The file is empty.\n");
    exit(1);
}
// A byte order mark (EF BB BF) at the start, left by some programs, would stick to the first column name.
$header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]);
$header = array_map(fn($h) => strtolower(trim((string) $h)), $header);

foreach (['jurisdiction_code', 'subject_slug'] as $needed) {
    if (!in_array($needed, $header, true)) {
        fwrite(STDERR, "The file has no '$needed' column. It should be a file made by scripts/export-page-text.php.\n");
        exit(1);
    }
}
if (!array_intersect(array_keys(PAGE_TEXT_FIELDS), $header)) {
    fwrite(STDERR, "The file has none of the text columns (intro, explanation, meta_title, meta_description).\n");
    exit(1);
}

echo ($dryRun ? "DRY RUN: nothing will be written.\n" : '')
    . ($overwrite ? "--overwrite is on: pages that already have different text here WILL be replaced.\n" : '') . "\n";

// ---------------------------------------------------------------
// Each row
// ---------------------------------------------------------------

$stats = ['filled' => 0, 'replaced' => 0, 'unchanged' => 0, 'skipped' => 0, 'errors' => 0];
$rowNumber = 1; // the header is row 1, as in the figures importer
while (($cells = fgetcsv($handle, 0, ',', '"', '')) !== false) {
    $rowNumber++;
    if ($cells === [null]) {
        continue; // a blank line
    }
    $row = [];
    foreach ($header as $i => $name) {
        $row[$name] = isset($cells[$i]) ? (string) $cells[$i] : '';
    }

    try {
        $outcome = importPageTextRow($pdo, $row, $dryRun, $overwrite);
        $stats[$outcome['result']]++;
        echo "Row $rowNumber: " . $outcome['message'] . "\n";
    } catch (Throwable $e) {
        $stats['errors']++;
        $slug = $row['subject_slug'] ?? '?';
        echo "Row $rowNumber: ERROR for '$slug': " . $e->getMessage() . "\n";
    }
}
fclose($handle);

echo "\nDone. {$stats['filled']} filled, {$stats['replaced']} replaced, {$stats['unchanged']} unchanged, "
    . "{$stats['skipped']} skipped, {$stats['errors']} errors.\n";
if ($stats['skipped'] > 0) {
    echo "Skipped pages already have different text on this machine. Nothing was changed on them. "
        . "Look at them, then use --overwrite if the file's text is the one to keep.\n";
}
if ($dryRun) {
    echo "That was a dry run. Run it again without --dry-run to write.\n";
}
exit($stats['errors'] > 0 ? 1 : 0);

// ===============================================================
// One page
// ===============================================================

/**
 * Decide what one row does, and do it unless this is a dry run.
 *
 * Returns ['result' => filled|replaced|unchanged|skipped, 'message' => ...].
 * Throws for anything wrong (an error), and nothing is written for that page.
 */
function importPageTextRow(PDO $pdo, array $row, bool $dryRun, bool $overwrite): array
{
    $slug = trim($row['subject_slug'] ?? '');
    $code = strtoupper(trim($row['jurisdiction_code'] ?? ''));
    if ($slug === '' || $code === '') {
        throw new RuntimeException('subject_slug and jurisdiction_code must both be filled in');
    }

    // --- The page, and whether it is in the country the file says ---
    $stmt = $pdo->prepare(
        'SELECT s.id, s.status, s.intro, s.explanation, s.meta_title, s.meta_description,
                sc.slug AS subcategory_slug, c.slug AS category_slug, COALESCE(j.parent_id, j.id) AS country_id, j.code AS country_code
         FROM subjects s
         JOIN subcategories sc ON sc.id = s.subcategory_id
         JOIN categories c ON c.id = sc.category_id
         JOIN jurisdictions j ON j.id = c.jurisdiction_id
         WHERE s.slug = :slug'
    );
    $stmt->execute(['slug' => $slug]);
    $page = $stmt->fetch();
    if (!$page) {
        throw new RuntimeException("there is no page '$slug' on this machine. Import its figures first (scripts/import-facts.php), which creates the page");
    }

    $stmt = $pdo->prepare('SELECT id, parent_id FROM jurisdictions WHERE code = :code');
    $stmt->execute(['code' => $code]);
    $jurisdiction = $stmt->fetch();
    if (!$jurisdiction) {
        throw new RuntimeException("unknown jurisdiction_code '$code'");
    }
    // Subject slugs are unique across ALL countries, so a slug can exist here as ANOTHER country's page.
    // Never put one country's text on another's page.
    if ((int) $page['country_id'] !== (int) ($jurisdiction['parent_id'] ?? $jurisdiction['id'])) {
        throw new RuntimeException("'$slug' on this machine belongs to {$page['country_code']}, but the file says $code. "
            . 'The text was not imported, so it cannot end up on the wrong country\'s page');
    }

    // --- The text in the file ---
    $incoming = [];
    foreach (PAGE_TEXT_FIELDS as $field => $meta) {
        $text = str_replace("\r\n", "\n", (string) ($row[$field] ?? ''));
        // A field with nothing but spaces or line breaks counts as "not in the file", so it never blanks a field.
        $incoming[$field] = preg_match('/[^\s]/u', $text) ? rtrim($text) : null;
    }
    if (count(array_filter($incoming, fn($t) => $t !== null)) === 0) {
        return ['result' => 'unchanged', 'message' => "$slug: the file has no text for this page"];
    }

    // --- Would it save in the editor? ---
    foreach (PAGE_TEXT_FIELDS as $field => $meta) {
        if ($incoming[$field] !== null && $meta['limit'] !== null) {
            $length = preg_match_all('/./su', $incoming[$field]);
            if ($length > $meta['limit']) {
                throw new RuntimeException("{$meta['label']} is $length characters, over the {$meta['limit']} the page can hold "
                    . '(counted with the placeholders as typed, before figures are filled in)');
            }
        }
    }
    // Every {fact:...} must be a published figure on this machine, and {tax_year} must be valid.
    $toCheck = [];
    foreach (PAGE_TEXT_FIELDS as $field => $meta) {
        if ($incoming[$field] !== null) {
            $toCheck[$meta['label']] = $incoming[$field];
        }
    }
    try {
        assertValidPlaceholders($pdo, $toCheck);
    } catch (RuntimeException $e) {
        $why = preg_replace('/^Not saved\.\s*/', '', $e->getMessage());
        // A figure that is missing or unpublished here is fixed by importing the figures. A typo in a
        // placeholder is not, so the advice is only added for the first kind.
        $figureProblem = strpos($why, "doesn't match any fact") !== false || strpos($why, "so it can't be shown") !== false;
        throw new RuntimeException($why . ($figureProblem ? ' Import the page\'s figures here first, so the placeholders resolve' : ''));
    }

    // --- Compare with what is here ---
    $current = [];
    foreach (PAGE_TEXT_FIELDS as $field => $meta) {
        $current[$field] = preg_match('/[^\s]/u', (string) $page[$field]) ? rtrim(str_replace("\r\n", "\n", (string) $page[$field])) : null;
    }
    $changes = [];   // fields whose text would change
    foreach (PAGE_TEXT_FIELDS as $field => $meta) {
        if ($incoming[$field] !== null && $incoming[$field] !== $current[$field]) {
            $changes[] = $field;
        }
    }
    if (!$changes) {
        return ['result' => 'unchanged', 'message' => "$slug: unchanged (the text here is already the same)"];
    }

    $written = $current['explanation'] !== null;   // already written on this machine?
    $names = implode(', ', array_map(fn($f) => strtolower(PAGE_TEXT_FIELDS[$f]['label']), $changes));

    if ($written && !$overwrite) {
        $words = fn($t) => $t === null ? 0 : preg_match_all('/\S+/u', $t);
        return ['result' => 'skipped', 'message' => "$slug: SKIPPED. This machine already has an explanation ("
            . $words($current['explanation']) . ' words) and the file differs in: ' . $names . '. Nothing was changed'];
    }

    // --- Write (or say what would be written) ---
    $verb = $written ? 'replaced' : 'filled';
    // If the file names a category and subcategory that differ from where the page sits here, say so
    // (the page is still matched by its slug, which is unique). Nothing is said when the file has none.
    $categoryNote = function (array $page, array $row): string {
        $fileCategory = trim($row['category_slug'] ?? '');
        $fileSubcategory = trim($row['subcategory_slug'] ?? '');
        if ($fileCategory === '' || $fileSubcategory === '') {
            return '';
        }
        return ($page['category_slug'] !== $fileCategory || $page['subcategory_slug'] !== $fileSubcategory)
            ? " [note: here the page is in {$page['category_slug']}/{$page['subcategory_slug']}, the file says $fileCategory/$fileSubcategory]" : '';
    };
    if (!$dryRun) {
        $sets = [];
        $params = ['id' => $page['id']];
        foreach ($changes as $field) {
            $sets[] = "`$field` = :$field";
            $params[$field] = $incoming[$field];
        }
        // The date a person last saved the text, carried over as it was. Never today's date.
        $reviewed = trim($row['text_reviewed_at'] ?? '');
        if (preg_match('/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}:\d{2})?$/', $reviewed)) {
            $sets[] = '`text_reviewed_at` = :reviewed';
            $params['reviewed'] = strlen($reviewed) === 10 ? $reviewed . ' 00:00:00' : $reviewed;
        }
        $pdo->prepare('UPDATE subjects SET ' . implode(', ', $sets) . ' WHERE id = :id')->execute($params);
    }

    return ['result' => $verb, 'message' => "$slug: " . ($dryRun ? 'WOULD have ' . $verb : $verb) . ' ' . $names
        . ($written ? ' (the page already had text; --overwrite)' : '')
        . $categoryNote($page, $row)];
}
