<?php
/**
 * CSV -> database import for Finance Fact Library.
 *
 * Usage:
 *   php scripts/import-facts.php path/to/your-facts.csv [--country=GB]
 *
 * Every row must say which country it is for: in a jurisdiction_code
 * column, or for the whole file with --country=XX (a row's own cell wins,
 * but must be in the same country as the flag, so a file for one country
 * can't end up in another). A row with neither is refused. Older UK files
 * that have no jurisdiction_code column just need --country=GB added.
 *
 * Two rules keep countries apart, because fact keys and subject slugs are
 * unique across ALL countries: a row can't take over another country's
 * subject page by reusing its slug, or another country's fact by reusing
 * its key. Give each country's pages and figures names of their own (for
 * example fact keys starting au_).
 *
 * One row per fact. Category/subcategory/subject are resolved by slug and
 * created automatically if they don't exist yet (using the *_name /
 * *_description / intro columns on whichever row first introduces them —
 * leaving those blank on later rows for the same slug is fine, and is
 * how data/imports/example-junior-isa.csv is laid out).
 *
 * Re-running the same file is safe: facts are matched by fact_key, and a
 * value only counts as changed (and gets a fact_history row) when it
 * really differs. An unchanged value just marks the fact as verified.
 * So this doubles as the day-to-day update mechanism: update a row in
 * your spreadsheet, re-run the import.
 *
 * Expected header row (order doesn't matter, matched by column name):
 *   category_slug, category_name, category_description,
 *   subcategory_slug, subcategory_name, subcategory_description,
 *   subject_slug, subject_name, subject_intro,
 *   fact_key, label, value, unit, context,
 *   source_name, source_url, last_updated, review_frequency_days
 *
 * Optional columns added with the new database structure:
 *   jurisdiction_code        e.g. GB, AU, or GB-SCT for a Scotland-only figure.
 *                            Required unless --country=XX is given (see Usage)
 *   link_type                'primary' (default), 'also' or 'unlink' (see below)
 *   value_display            display override, e.g. '£0 to £125,000'
 *   effective_from           YYYY-MM-DD the figure took effect (not in the future)
 *   tax_year                 e.g. 2026/27
 *   status                   draft | published | retired (new facts default
 *                            to published; existing facts keep their status
 *                            unless this is filled in)
 *   subject_meta_title       SEO title for the subject page
 *   subject_meta_description SEO description for the subject page
 *   subject_status           draft | published | retired, to publish, hide
 *                            or retire a subject page. A page this row
 *                            CREATES is published straight away unless this
 *                            says draft: use draft to stage pages for the
 *                            drip publisher to release
 *   previous_value           the value before the current one (e.g. last
 *                            tax year's rate), recorded in the fact's
 *                            history and dated by effective_from
 *
 * Placeholders: subject_intro, subject_meta_title and
 * subject_meta_description can contain {fact:fact_key} and {tax_year}
 * (see includes/placeholders.php). A row with an invalid placeholder, or
 * one naming a fact that doesn't exist or isn't published, is refused as
 * a whole. The fact must already exist when the row is read, so put rows
 * that create facts above rows whose text uses them.
 *
 * Editing existing pages without phpMyAdmin:
 *   Any filled-in name, description, intro, meta or subject_status cell is
 *   applied to the category, subcategory or subject on that row, whether
 *   it's new or already exists. Blank cells never change anything, so a
 *   one-row CSV can rename a subcategory or rewrite a subject's intro.
 *
 * link_type 'primary' (the default) creates or updates the fact, and
 * makes this row's subject the page that owns it.
 *
 * link_type 'unlink' removes an existing fact from this row's subject
 * page, without changing or deleting the fact. It can't unlink a fact
 * from the page that owns it: move ownership first with a primary row
 * for another subject, then unlink the old page.
 *
 * link_type 'also' shows an EXISTING fact on this row's subject as well,
 * without changing the fact. Only the hierarchy slugs and fact_key are
 * needed. If context is filled in, it's used as that page's own context
 * for the fact (e.g. the base rate explained in SVR terms).
 *
 * Required on primary rows: category_slug, subcategory_slug,
 * subject_slug and fact_key, plus label and value for a NEW fact.
 * For an existing fact, every other cell is optional and blank cells
 * leave that detail unchanged. So, for example, a row with just the
 * fact's hierarchy slugs, fact_key and status = retired retires it.
 * last_updated is optional: it's the date the value changed, and
 * defaults to today.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/fact-writer.php';

// One CSV file, plus an optional --country=XX. The flag says which country
// the whole file is for. It fills in rows that leave jurisdiction_code blank,
// and it is also a safety check: a row that names a different country is
// refused, so a file meant for one country can't end up in another.
$csvPath = null;
$defaultCountryCode = '';
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--country=([A-Za-z0-9-]{1,10})$/', $arg, $argMatch)) {
        $defaultCountryCode = strtoupper($argMatch[1]);
    } elseif (strpos($arg, '--') === 0) {
        fwrite(STDERR, "Unknown option: $arg\n");
        exit(1);
    } elseif ($csvPath === null) {
        $csvPath = $arg;
    } else {
        fwrite(STDERR, "Only one CSV file at a time.\n");
        exit(1);
    }
}
if ($csvPath === null) {
    fwrite(STDERR, "Usage: php scripts/import-facts.php path/to/facts.csv [--country=GB]\n");
    exit(1);
}
if (!is_readable($csvPath)) {
    fwrite(STDERR, "Cannot read file: $csvPath\n");
    exit(1);
}

$pdo = getDbConnection();

// Caches so repeated slugs within one run don't hit the DB again for a
// lookup already resolved earlier in the same file.
$categoryCache = [];
$subcategoryCache = [];
$subjectCache = [];

$stats = ['created' => 0, 'changed' => 0, 'unchanged' => 0, 'linked' => 0, 'unlinked' => 0, 'errors' => 0];

$handle = fopen($csvPath, 'r');
if ($handle === false) {
    fwrite(STDERR, "Failed to open file.\n");
    exit(1);
}

// Strip a UTF-8 BOM if Excel/Numbers added one on export.
$firstBytes = fread($handle, 3);
if ($firstBytes !== "\xEF\xBB\xBF") {
    rewind($handle);
}

$header = fgetcsv($handle, 0, ',', '"', '\\');
if ($header === false) {
    fwrite(STDERR, "CSV appears empty.\n");
    exit(1);
}
$header = array_map('trim', $header);

$rowNum = 1;
while (($row = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
    $rowNum++;

    if (count(array_filter($row, fn($v) => trim((string) $v) !== '')) === 0) {
        continue; // skip blank rows
    }

    $data = @array_combine($header, $row);
    if ($data === false) {
        echo "Row $rowNum: column count doesn't match header — skipped.\n";
        $stats['errors']++;
        continue;
    }
    $get = fn(string $key) => trim($data[$key] ?? '');

    try {
        // Each row is all-or-nothing: if anything fails, nothing from
        // that row is saved (e.g. no half-created subject).
        $message = withTransaction($pdo, function () use (
            $pdo, $get, $defaultCountryCode, &$categoryCache, &$subcategoryCache, &$subjectCache, &$stats
        ) {
            // Jurisdiction decides which country the category belongs to.
            // A Scotland-only fact still sits in the UK's categories.
            //
            // The country must be stated: in the row's jurisdiction_code, or
            // for the whole file with --country=XX. There is no silent
            // default any more. It used to be "GB", which was harmless with
            // one country, but with several a blank cell would quietly put
            // that row's category, page and figure in the UK.
            $code = strtoupper($get('jurisdiction_code'));
            if ($code === '') {
                $code = $defaultCountryCode;
            }
            if ($code === '') {
                throw new RuntimeException(
                    'jurisdiction_code is required (for example GB or AU). Fill in the column, '
                    . 'or run the import with --country=GB'
                );
            }
            $jurisdiction = getJurisdictionByCode($pdo, $code);
            $countryId = getCountryId($jurisdiction);

            // With --country, a row for a different country is a mistake.
            // (A sub-region of the flagged country, such as GB-SCT with
            // --country=GB, is the same country and is fine.)
            if ($defaultCountryCode !== ''
                && (int) $countryId !== (int) getCountryId(getJurisdictionByCode($pdo, $defaultCountryCode))) {
                throw new RuntimeException(
                    "this row is for $code but the import was started with --country=$defaultCountryCode. "
                    . 'Fix the row or the flag, so a file cannot end up in the wrong country'
                );
            }

            $categorySlug = $get('category_slug');
            if ($categorySlug === '') {
                throw new RuntimeException('category_slug is required');
            }
            $catCacheKey = $countryId . ':' . $categorySlug;
            if (!isset($categoryCache[$catCacheKey])) {
                $categoryCache[$catCacheKey] = getOrCreateCategory(
                    $pdo, $countryId, $categorySlug, $get('category_name'), $get('category_description')
                );
            }
            $categoryId = $categoryCache[$catCacheKey];

            // Filled-in cells update an existing category (blank cells are ignored).
            updateFilledColumns($pdo, 'categories', $categoryId, [
                'name'        => $get('category_name'),
                'description' => $get('category_description'),
            ]);

            $subcategorySlug = $get('subcategory_slug');
            if ($subcategorySlug === '') {
                throw new RuntimeException('subcategory_slug is required');
            }
            $subcatCacheKey = $categoryId . ':' . $subcategorySlug;
            if (!isset($subcategoryCache[$subcatCacheKey])) {
                $subcategoryCache[$subcatCacheKey] = getOrCreateSubcategory(
                    $pdo, $categoryId, $subcategorySlug, $get('subcategory_name'), $get('subcategory_description')
                );
            }
            $subcategoryId = $subcategoryCache[$subcatCacheKey];

            updateFilledColumns($pdo, 'subcategories', $subcategoryId, [
                'name'        => $get('subcategory_name'),
                'description' => $get('subcategory_description'),
            ]);

            $subjectSlug = $get('subject_slug');
            if ($subjectSlug === '') {
                throw new RuntimeException('subject_slug is required');
            }

            // Placeholders in the subject's text must be valid before
            // anything is written, the same check the editor makes.
            assertValidPlaceholders($pdo, [
                'subject_intro'            => $get('subject_intro'),
                'subject_meta_title'       => $get('subject_meta_title'),
                'subject_meta_description' => $get('subject_meta_description'),
            ]);
            if (!isset($subjectCache[$subjectSlug])) {
                // A page this row creates starts with the row's subject_status,
                // so staged pages are born as drafts (see getOrCreateSubject()).
                $subjectCache[$subjectSlug] = getOrCreateSubject(
                    $pdo, $subcategoryId, $subjectSlug, $get('subject_name'), $get('subject_intro'),
                    strtolower($get('subject_status'))
                );
            }
            $subjectId = $subjectCache[$subjectSlug];

            // Subject slugs are unique across ALL countries (the public site finds
            // a page by its slug alone), and getOrCreateSubject() finds a subject
            // by slug alone. So a row for one country that reuses another country's
            // slug would quietly attach its figures to that other country's page.
            // Refuse it; the row is rolled back, so nothing is half-created.
            $subjectCountry = subjectCountry($pdo, $subjectId);
            if ($subjectCountry && (int) $subjectCountry['country_id'] !== (int) $countryId) {
                throw new RuntimeException(
                    "subject '$subjectSlug' already exists as a {$subjectCountry['code']} page. Subject slugs are "
                    . "unique across all countries, so a $code page needs a slug of its own "
                    . '(for example one that ends with the country name)'
                );
            }

            // Name, intro and SEO fields are applied whenever they're filled
            // in, so existing subjects can be edited from the spreadsheet.
            updateFilledColumns($pdo, 'subjects', $subjectId, [
                'name'             => $get('subject_name'),
                'intro'            => $get('subject_intro'),
                'meta_title'       => $get('subject_meta_title'),
                'meta_description' => $get('subject_meta_description'),
            ]);
            updateSubjectStatus($pdo, $subjectId, $get('subject_status'));

            $factKey = $get('fact_key');
            if ($factKey === '') {
                throw new RuntimeException('fact_key is required');
            }

            $linkType = strtolower($get('link_type') ?: 'primary');

            // --- 'unlink' rows: take a fact off this page ---
            if ($linkType === 'unlink') {
                $factId = findFactIdByKey($pdo, $factKey);
                if (!$factId) {
                    throw new RuntimeException("fact '$factKey' doesn't exist, so there's nothing to unlink");
                }
                $removed = unlinkFactFromSubject($pdo, $subjectId, $factId);
                if ($removed) {
                    $stats['unlinked']++;
                }
                return ($removed ? 'unlinked' : 'already not linked:') . " fact '$factKey' from '$subjectSlug'";
            }

            // --- 'also' rows: show an existing fact on another page ---
            if ($linkType === 'also') {
                $factId = findFactIdByKey($pdo, $factKey);
                if (!$factId) {
                    throw new RuntimeException("fact '$factKey' doesn't exist yet, so it can't be linked. Add its primary row first");
                }
                // One country per page: another country's figure can't be shown here.
                $factCountry = factCountry($pdo, $factId);
                if ($factCountry && (int) $factCountry['country_id'] !== (int) $countryId) {
                    throw new RuntimeException(
                        "fact '$factKey' belongs to {$factCountry['code']}, so it can't be shown on a $code page. "
                        . "Add $code's own figure instead"
                    );
                }
                $created = linkFactToSubject($pdo, $subjectId, $factId, $get('context') ?: null);
                $stats['linked']++;
                return ($created ? 'linked' : 'already linked') . " fact '$factKey' to '$subjectSlug'";
            }

            if ($linkType !== 'primary') {
                throw new RuntimeException("link_type must be 'primary', 'also' or 'unlink', got '$linkType'");
            }

            // --- 'primary' rows: create or update the fact itself ---
            $label = $get('label');
            $value = $get('value');

            $lastUpdated = validDateOrNull($get('last_updated'), 'last_updated');
            $effectiveFrom = validDateOrNull($get('effective_from'), 'effective_from');

            $status = strtolower($get('status'));
            if ($status !== '' && !in_array($status, ['draft', 'published', 'retired'], true)) {
                throw new RuntimeException("status must be draft, published or retired, got '$status'");
            }

            $sourceId = null;
            if ($get('source_url') !== '') {
                $sourceId = getOrCreateSource($pdo, $get('source_url'), $get('source_name') ?: null, $countryId);
            }

            $factId = findFactIdByKey($pdo, $factKey);

            // Fact keys are unique across ALL countries, and a row for an
            // existing fact re-parents it to this row's page. So a row that
            // reuses another country's key would pull that country's figure
            // onto this page. Refuse it (the row is rolled back).
            if ($factId) {
                $factCountry = factCountry($pdo, $factId);
                if ($factCountry && (int) $factCountry['country_id'] !== (int) $countryId) {
                    throw new RuntimeException(
                        "fact '$factKey' already exists in {$factCountry['code']}. Fact keys are unique across all "
                        . "countries, so a $code figure needs a key of its own (for example one that starts with "
                        . strtolower($code) . '_). Nothing was changed'
                    );
                }
            }

            if (!$factId) {
                // New facts need a label and value. Status defaults to
                // published, since you're the one adding them.
                if ($label === '' || $value === '') {
                    throw new RuntimeException('label and value are required for a new fact');
                }
                $factId = createFact($pdo, [
                    'primary_subject_id'    => $subjectId,
                    'jurisdiction_id'       => (int) $jurisdiction['id'],
                    'fact_key'              => $factKey,
                    'label'                 => $label,
                    'value'                 => $value,
                    'unit'                  => $get('unit') ?: null,
                    'value_display'         => $get('value_display') ?: null,
                    'context'               => $get('context') ?: null,
                    'source_id'             => $sourceId,
                    'last_updated'          => $lastUpdated,
                    'effective_from'        => $effectiveFrom,
                    'tax_year'              => $get('tax_year') ?: null,
                    'review_frequency_days' => $get('review_frequency_days') !== '' ? (int) $get('review_frequency_days') : 90,
                    'status'                => $status ?: 'published',
                    'change_source'         => 'import',
                ]);
                $stats['created']++;
                if ($get('previous_value') !== '') {
                    recordPreviousValue($pdo, $factId, $get('previous_value'), $effectiveFrom);
                }
                return "created fact '$factKey'";
            }

            // Existing fact: same rule as categories and subjects, so a
            // blank cell never changes anything. Only the cells that are
            // filled in are written, which means a row with just the
            // fact_key and status = retired retires a fact and leaves
            // everything else about it untouched.
            //
            // Descriptive fields go first, so a changed unit is in place
            // before the value is compared.
            $fields = ['primary_subject_id' => $subjectId];
            if ($label !== '') {
                $fields['label'] = $label;
            }
            if ($get('jurisdiction_code') !== '') {
                $fields['jurisdiction_id'] = (int) $jurisdiction['id'];
            }
            foreach (['unit' => 'unit', 'value_display' => 'value_display', 'context' => 'context'] as $column => $cell) {
                if ($get($cell) !== '') {
                    $fields[$column] = $get($cell);
                }
            }
            if ($sourceId !== null) {
                $fields['source_id'] = $sourceId;
            }
            if ($get('review_frequency_days') !== '') {
                $fields['review_frequency_days'] = (int) $get('review_frequency_days');
            }
            if ($status !== '') {
                $fields['status'] = $status;
            }

            $sets = implode(', ', array_map(fn($column) => "$column = :$column", array_keys($fields)));
            $stmt = $pdo->prepare("UPDATE facts SET $sets WHERE id = :id");
            $stmt->execute($fields + ['id' => $factId]);

            // Make sure the owning page shows the fact. If ownership moved,
            // the previous page keeps it as a shared fact. Remove that link
            // with an 'unlink' row if it's no longer wanted.
            linkFactToSubject($pdo, $subjectId, $factId);

            // No value in the row means the value isn't being touched, so
            // it's neither changed nor marked as checked.
            if ($value === '') {
                return "updated details of fact '$factKey'" . ($status !== '' ? " (status: $status)" : '');
            }

            $changed = updateFactValue($pdo, $factId, $value, [
                'change_source'  => 'import',
                'changed_on'     => $lastUpdated,
                'effective_from' => $effectiveFrom,
                'tax_year'       => $get('tax_year') ?: null,
                'note'           => 'CSV import',
            ]);

            // Previous value from the spreadsheet (e.g. last year's rate).
            // Skipped automatically if that change is already in history.
            if ($get('previous_value') !== '') {
                recordPreviousValue($pdo, $factId, $get('previous_value'), $effectiveFrom);
            }

            $stats[$changed ? 'changed' : 'unchanged']++;
            return ($changed ? 'value changed for' : 'verified (unchanged)') . " fact '$factKey'";
        });

        echo "Row $rowNum: $message\n";

    } catch (Throwable $e) {
        echo "Row $rowNum: ERROR — " . $e->getMessage() . "\n";
        $stats['errors']++;

        // The row was rolled back, so anything it created (and cached)
        // no longer exists. Clear the caches so later rows look again.
        $categoryCache = $subcategoryCache = $subjectCache = [];
    }
}

fclose($handle);

echo "\nDone. {$stats['created']} created, {$stats['changed']} changed, "
    . "{$stats['unchanged']} unchanged, {$stats['linked']} linked, {$stats['unlinked']} unlinked, "
    . "{$stats['errors']} errors.\n";
exit($stats['errors'] > 0 ? 1 : 0);

// ============================================================

/**
 * Returns a Y-m-d date, or NULL for an empty cell. Anything else is an
 * error rather than a silently wrong date.
 */
function validDateOrNull(string $value, string $column): ?string
{
    if ($value === '') {
        return null;
    }
    $date = DateTime::createFromFormat('!Y-m-d', $value);
    if (!$date || $date->format('Y-m-d') !== $value) {
        throw new RuntimeException("$column must be YYYY-MM-DD, got '$value'");
    }
    return $value;
}

function findFactIdByKey(PDO $pdo, string $factKey): ?int
{
    $stmt = $pdo->prepare('SELECT id FROM facts WHERE fact_key = :key');
    $stmt->execute(['key' => $factKey]);
    $id = $stmt->fetchColumn();
    return $id ? (int) $id : null;
}

/**
 * Category slugs are unique per country (migration 001), so the lookup
 * and the insert both include the country.
 */
function getOrCreateCategory(PDO $pdo, int $countryId, string $slug, string $name, string $description): int
{
    $stmt = $pdo->prepare('SELECT id FROM categories WHERE jurisdiction_id = :jid AND slug = :slug');
    $stmt->execute(['jid' => $countryId, 'slug' => $slug]);
    $id = $stmt->fetchColumn();
    if ($id) {
        return (int) $id;
    }
    if ($name === '') {
        throw new RuntimeException("category '$slug' doesn't exist yet and no category_name was given to create it");
    }
    $stmt = $pdo->prepare(
        'INSERT INTO categories (jurisdiction_id, name, slug, description) VALUES (:jid, :name, :slug, :description)'
    );
    $stmt->execute(['jid' => $countryId, 'name' => $name, 'slug' => $slug, 'description' => $description ?: null]);
    return (int) $pdo->lastInsertId();
}

function getOrCreateSubcategory(PDO $pdo, int $categoryId, string $slug, string $name, string $description): int
{
    $stmt = $pdo->prepare('SELECT id FROM subcategories WHERE category_id = :cid AND slug = :slug');
    $stmt->execute(['cid' => $categoryId, 'slug' => $slug]);
    $id = $stmt->fetchColumn();
    if ($id) {
        return (int) $id;
    }
    if ($name === '') {
        throw new RuntimeException("subcategory '$slug' doesn't exist yet and no subcategory_name was given to create it");
    }
    $stmt = $pdo->prepare('INSERT INTO subcategories (category_id, name, slug, description) VALUES (:cid, :name, :slug, :description)');
    $stmt->execute(['cid' => $categoryId, 'name' => $name, 'slug' => $slug, 'description' => $description ?: null]);
    return (int) $pdo->lastInsertId();
}

/**
 * Subjects created by the importer are published straight away, since
 * you're the one authoring them. New subjects default to draft in the
 * database, so the pipeline's subjects won't go live unless it says so.
 *
 * The exception is a row whose subject_status says otherwise: a page created
 * with subject_status = draft is born a draft, with no published_at date.
 * That is how a country is staged for the drip publisher
 * (includes/job-handlers/drip.php), which later publishes each page once its
 * text is written. Before this, such a page was created published, with
 * published_at stamped at import, and flipped to draft a moment later.
 *
 * $initialStatus only matters when the subject is created here. A value that
 * isn't draft, published or retired is ignored (the row is then refused by
 * updateSubjectStatus(), and rolled back).
 */
function getOrCreateSubject(PDO $pdo, int $subcategoryId, string $slug, string $name, string $intro, string $initialStatus = ''): int
{
    $status = in_array($initialStatus, ['draft', 'published', 'retired'], true) ? $initialStatus : 'published';
    $stmt = $pdo->prepare('SELECT id FROM subjects WHERE slug = :slug');
    $stmt->execute(['slug' => $slug]);
    $id = $stmt->fetchColumn();
    if ($id) {
        return (int) $id;
    }
    if ($name === '') {
        throw new RuntimeException("subject '$slug' doesn't exist yet and no subject_name was given to create it");
    }
    $stmt = $pdo->prepare(
        "INSERT INTO subjects (subcategory_id, name, slug, intro, status, published_at)
         VALUES (:scid, :name, :slug, :intro, :status, CASE WHEN :status2 = 'published' THEN NOW() ELSE NULL END)"
    );
    $stmt->execute([
        'scid' => $subcategoryId, 'name' => $name, 'slug' => $slug, 'intro' => $intro ?: null,
        'status' => $status, 'status2' => $status,
    ]);
    return (int) $pdo->lastInsertId();
}

/**
 * Update only the columns whose cell was filled in, so a blank cell never
 * wipes something that was set earlier. $table must be one of the three
 * hierarchy tables, and the column names come from this script, never
 * from the CSV, so nothing user-supplied reaches the SQL itself.
 */
function updateFilledColumns(PDO $pdo, string $table, int $id, array $fields): void
{
    if (!in_array($table, ['categories', 'subcategories', 'subjects'], true)) {
        throw new InvalidArgumentException("Unexpected table '$table'");
    }

    $fields = array_filter($fields, fn($value) => $value !== '');
    if (!$fields) {
        return;
    }

    $sets = implode(', ', array_map(fn($column) => "$column = :$column", array_keys($fields)));
    $stmt = $pdo->prepare("UPDATE $table SET $sets WHERE id = :id");
    $stmt->execute($fields + ['id' => $id]);
}

/**
 * Set a subject's status from the subject_status column, if filled in.
 * Publishing also stamps published_at the first time, so the date a page
 * went live is kept even if it's later unpublished and republished.
 */
function updateSubjectStatus(PDO $pdo, int $subjectId, string $status): void
{
    if ($status === '') {
        return;
    }

    $status = strtolower($status);
    if (!in_array($status, ['draft', 'published', 'retired'], true)) {
        throw new RuntimeException("subject_status must be draft, published or retired, got '$status'");
    }

    $stmt = $pdo->prepare(
        "UPDATE subjects
         SET status = :status,
             published_at = CASE WHEN :status2 = 'published' AND published_at IS NULL THEN NOW() ELSE published_at END
         WHERE id = :id"
    );
    $stmt->execute(['status' => $status, 'status2' => $status, 'id' => $subjectId]);
}

/**
 * Remove a fact from a subject page. Refuses to remove it from its owner
 * page, because every fact must be shown on the page that owns it.
 * Returns true if a link was removed, false if there wasn't one.
 */
function unlinkFactFromSubject(PDO $pdo, int $subjectId, int $factId): bool
{
    $stmt = $pdo->prepare('SELECT primary_subject_id FROM facts WHERE id = :id');
    $stmt->execute(['id' => $factId]);
    if ((int) $stmt->fetchColumn() === $subjectId) {
        throw new RuntimeException(
            "this page owns the fact, so it can't be unlinked. Give it a new owner with a primary row first"
        );
    }

    $stmt = $pdo->prepare('DELETE FROM subject_facts WHERE subject_id = :sid AND fact_id = :fid');
    $stmt->execute(['sid' => $subjectId, 'fid' => $factId]);

    return $stmt->rowCount() > 0;
}

/**
 * The country (id and code) a subject page belongs to, found through its
 * category, which belongs to a country. Null if the subject doesn't exist.
 */
function subjectCountry(PDO $pdo, int $subjectId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT j.id AS country_id, j.code
         FROM subjects s
         JOIN subcategories sc ON sc.id = s.subcategory_id
         JOIN categories c ON c.id = sc.category_id
         JOIN jurisdictions j ON j.id = c.jurisdiction_id
         WHERE s.id = :id'
    );
    $stmt->execute(['id' => $subjectId]);
    return $stmt->fetch() ?: null;
}

/**
 * The country (id and code) a fact belongs to. A sub-region's fact, such as
 * a GB-SCT one, counts as its parent country's. Null if the fact doesn't
 * exist.
 */
function factCountry(PDO $pdo, int $factId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT root.id AS country_id, root.code
         FROM facts f
         JOIN jurisdictions j ON j.id = f.jurisdiction_id
         JOIN jurisdictions root ON root.id = COALESCE(j.parent_id, j.id)
         WHERE f.id = :id'
    );
    $stmt->execute(['id' => $factId]);
    return $stmt->fetch() ?: null;
}
