<?php
/**
 * Add (or remove) reference sources for pages, from a CSV.
 *
 * A reference source is an official page that supports what a page's TEXT says but that no
 * figure card cites (migration 030). The AI check, Propose fix and the drafting loop read it
 * in addition to the card sources, so correct text isn't flagged as unsupported, and the daily
 * source check records its fingerprint so a page is re-checked when it changes. It is not shown to
 * readers, and it changes no page and no figure.
 *
 * Usage, from the project root:
 *   php scripts/import-reference-sources.php --dry-run data/imports/reference-sources-2026-11.csv
 *   php scripts/import-reference-sources.php data/imports/reference-sources-2026-11.csv
 *   php scripts/import-reference-sources.php --no-check data/imports/reference-sources-2026-11.csv
 *   php scripts/import-reference-sources.php --list                      every page's reference sources
 *   php scripts/import-reference-sources.php --list=carers-allowance     one page's
 * Always run it with --dry-run first.
 *
 * CSV columns, matched by name:
 *   subject_slug   the page (its slug). Required
 *   source_url     the official page. Required. Its domain must be on the allowlist
 *                  (FACT_SOURCE_ALLOWLIST in includes/fact-writer.php), as for a figure's source
 *   source_name    the publisher, for a page the site has not seen before (optional)
 *   action         add (the default) or remove
 *   note           what the page is used for, for people reading the list (optional, 255 characters)
 *
 * Safe to run again: a source already linked to the page is reported and left alone.
 *
 * It reads each new page over HTTP to make sure the site can read it (the same reader the AI
 * check uses), and refuses a page it cannot read, because a source that cannot be read would
 * silently check nothing. --no-check skips that. Nothing else is read or sent anywhere: there is no AI
 * call, so it costs nothing.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script can only be run from the command line.\n");
}

ini_set('display_errors', 'stderr');
error_reporting(E_ALL);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/fact-writer.php';   // getOrCreateSource(), getAllowlistEntry()
require_once __DIR__ . '/../includes/source-pages.php';  // fetchPageText(), getReferenceSourcesForSubject()

$flags = [];
$files = [];
foreach (array_slice($argv, 1) as $arg) {
    if (strncmp($arg, '--', 2) === 0) {
        $flags[] = $arg;
    } else {
        $files[] = $arg;
    }
}
$dryRun = in_array('--dry-run', $flags, true);
$noCheck = in_array('--no-check', $flags, true);

$pdo = getDbConnection();

// ---------------------------------------------------------------
// --list: show what is linked now
// ---------------------------------------------------------------
foreach ($flags as $flag) {
    if ($flag === '--list' || strncmp($flag, '--list=', 7) === 0) {
        $slug = $flag === '--list' ? null : substr($flag, 7);
        $sql = 'SELECT s.slug, src.url, src.publisher, r.note
                FROM subject_reference_sources r
                JOIN subjects s ON s.id = r.subject_id
                JOIN sources src ON src.id = r.source_id'
            . ($slug !== null ? ' WHERE s.slug = :slug' : '') . ' ORDER BY s.slug, r.created_at, src.id';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($slug !== null ? ['slug' => $slug] : []);
        $rows = $stmt->fetchAll();
        if (!$rows) {
            echo "No reference sources" . ($slug !== null ? " for '$slug'" : '') . ".\n";
            exit(0);
        }
        $last = null;
        foreach ($rows as $row) {
            if ($row['slug'] !== $last) {
                echo ($last !== null ? "\n" : '') . $row['slug'] . "\n";
                $last = $row['slug'];
            }
            echo '  ' . $row['url'] . "\n    " . $row['publisher'] . ($row['note'] ? ' - ' . $row['note'] : '') . "\n";
        }
        exit(0);
    }
}

if (count($files) !== 1) {
    fwrite(STDERR, "Give one CSV file: php scripts/import-reference-sources.php --dry-run data/imports/<file>.csv\n");
    exit(1);
}
$handle = @fopen($files[0], 'r');
if (!$handle) {
    fwrite(STDERR, "Can't read {$files[0]}\n");
    exit(1);
}

$header = fgetcsv($handle, 0, ',', '"', '\\');
$header = $header ? array_map(fn($h) => strtolower(trim((string) $h)), $header) : [];
foreach (['subject_slug', 'source_url'] as $required) {
    if (!in_array($required, $header, true)) {
        fwrite(STDERR, "The file has no '$required' column.\n");
        exit(1);
    }
}

echo $dryRun ? "DRY RUN: nothing will be written.\n\n" : '';

$stats = ['added' => 0, 'removed' => 0, 'unchanged' => 0, 'errors' => 0];
$rowNumber = 1;
while (($cells = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
    $rowNumber++;
    if ($cells === [null] || trim(implode('', $cells)) === '') {
        continue;
    }
    $row = [];
    foreach ($header as $i => $name) {
        $row[$name] = trim((string) ($cells[$i] ?? ''));
    }

    try {
        $slug = $row['subject_slug'];
        $url = $row['source_url'];
        $action = strtolower($row['action'] ?? '') ?: 'add';
        $note = $row['note'] ?? '';
        if ($slug === '' || $url === '') {
            throw new RuntimeException('subject_slug and source_url are both required');
        }
        if (!in_array($action, ['add', 'remove'], true)) {
            throw new RuntimeException("action must be add or remove, got '$action'");
        }
        if (strlen($note) > 255) {
            throw new RuntimeException('note is longer than 255 characters');
        }
        if (!preg_match('#^https://#i', $url)) {
            throw new RuntimeException('source_url must start with https://');
        }

        // The page, and its country (the category's, or that country's parent when the category sits on a region).
        $stmt = $pdo->prepare(
            'SELECT s.id, COALESCE(j.parent_id, j.id) AS country_id
             FROM subjects s
             LEFT JOIN subcategories sc ON sc.id = s.subcategory_id
             LEFT JOIN categories c ON c.id = sc.category_id
             LEFT JOIN jurisdictions j ON j.id = c.jurisdiction_id
             WHERE s.slug = :slug'
        );
        $stmt->execute(['slug' => $slug]);
        $subjects = $stmt->fetchAll();
        if (count($subjects) !== 1) {
            throw new RuntimeException(count($subjects) === 0
                ? "no page with the slug '$slug'"
                : "the slug '$slug' matches " . count($subjects) . ' pages, so it is ambiguous');
        }
        $subjectId = (int) $subjects[0]['id'];

        if ($action === 'remove') {
            $stmt = $pdo->prepare(
                'DELETE r FROM subject_reference_sources r JOIN sources src ON src.id = r.source_id
                 WHERE r.subject_id = :sid AND src.url = :url'
            );
            if ($dryRun) {
                $check = $pdo->prepare('SELECT COUNT(*) FROM subject_reference_sources r JOIN sources src ON src.id = r.source_id WHERE r.subject_id = :sid AND src.url = :url');
                $check->execute(['sid' => $subjectId, 'url' => $url]);
                $would = (int) $check->fetchColumn();
            } else {
                $stmt->execute(['sid' => $subjectId, 'url' => $url]);
                $would = $stmt->rowCount();
            }
            if ($would > 0) {
                $stats['removed']++;
                echo "Row $rowNumber: " . ($dryRun ? 'WOULD remove' : 'removed') . " $url from $slug\n";
            } else {
                $stats['unchanged']++;
                echo "Row $rowNumber: $slug does not have $url, nothing to remove\n";
            }
            continue;
        }

        // Same rule as for a figure's source: official domains only.
        if (getAllowlistEntry($url) === null) {
            throw new RuntimeException('the domain of that page is not on the allowlist (FACT_SOURCE_ALLOWLIST in includes/fact-writer.php)');
        }

        // Already linked?
        $stmt = $pdo->prepare(
            'SELECT 1 FROM subject_reference_sources r JOIN sources src ON src.id = r.source_id
             WHERE r.subject_id = :sid AND src.url = :url'
        );
        $stmt->execute(['sid' => $subjectId, 'url' => $url]);
        if ($stmt->fetchColumn()) {
            $stats['unchanged']++;
            echo "Row $rowNumber: $slug already has $url\n";
            continue;
        }

        // The site must be able to read it, or it would check nothing.
        $readNote = '';
        if (!$noCheck) {
            try {
                $text = fetchPageText($url);
                $readNote = ' (read ' . number_format(strlen($text)) . ' characters)';
            } catch (Throwable $e) {
                throw new RuntimeException("the site could not read that page: " . $e->getMessage()
                    . ' (--no-check skips this test)');
            }
        }

        if (!$dryRun) {
            $sourceId = getOrCreateSource($pdo, $url, (($row['source_name'] ?? '') ?: null), $subjects[0]['country_id'] !== null ? (int) $subjects[0]['country_id'] : null);
            $pdo->prepare('INSERT IGNORE INTO subject_reference_sources (subject_id, source_id, note) VALUES (:sid, :src, :note)')
                ->execute(['sid' => $subjectId, 'src' => $sourceId, 'note' => $note !== '' ? $note : null]);
        }
        $stats['added']++;
        echo "Row $rowNumber: " . ($dryRun ? 'WOULD add' : 'added') . " $url to $slug$readNote\n";

    } catch (Throwable $e) {
        $stats['errors']++;
        echo "Row $rowNumber: ERROR - " . $e->getMessage() . "\n";
    }
}
fclose($handle);

echo "\nDone. {$stats['added']} " . ($dryRun ? 'would be added' : 'added') . ", {$stats['removed']} "
    . ($dryRun ? 'would be removed' : 'removed') . ", {$stats['unchanged']} unchanged, {$stats['errors']} errors.\n";
if ($dryRun) {
    echo "That was a dry run. Run it again without --dry-run to write.\n";
}
exit($stats['errors'] > 0 ? 1 : 0);
