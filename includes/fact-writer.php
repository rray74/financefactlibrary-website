<?php
/**
 * Write-side fact functions.
 *
 * Every change to a fact's value goes through updateFactValue(), and every
 * new fact through createFact(), so the history, verification and
 * page-link rules live in one place. Used by scripts/import-facts.php and
 * public/admin/review.php now, and by the cron pipeline later.
 *
 * The admin editor's other writes live here too (updateSubjectDetails,
 * updateFactDetails, at the end of the file), so every content change
 * has one home, whichever page or script makes it.
 *
 * The public site never includes this file. It only reads, through
 * includes/functions.php.
 */

require_once __DIR__ . '/../config/database.php';
// Placeholder checks for subject text (assertValidPlaceholders()).
require_once __DIR__ . '/placeholders.php';

/**
 * Domains whose facts can be trusted without review, with the licence
 * their content is published under (NULL if not stated). Subdomains are
 * included, so 'gov.uk' also covers 'www.gov.uk' and 'ons.gov.uk'.
 *
 * Migration 002 applied the UK-wide entries to existing sources. This
 * list is what decides for every new source from now on, and it's the
 * first of the automated gates the pipeline will use.
 */
const FACT_SOURCE_ALLOWLIST = [
    'gov.uk'              => 'Open Government Licence v3.0',
    'bankofengland.co.uk' => null,
    'fca.org.uk'          => null,
    // Devolved governments and tax authorities, for nation-specific facts
    // such as LBTT (Revenue Scotland) and LTT (Welsh Revenue Authority).
    'gov.scot'            => 'Open Government Licence v3.0',
    'revenue.scot'        => null,
    'gov.wales'           => 'Open Government Licence v3.0',
    // National Savings and Investments, backed by HM Treasury, the
    // official source for Premium Bonds rates and odds.
    'nsandi.com'          => null,
];

/**
 * Run $fn inside a transaction, or inside the caller's transaction if
 * one is already open. This lets a function like updateFactValue() be
 * safe on its own, and also be part of a bigger unit of work such as a
 * whole import row.
 */
function withTransaction(PDO $pdo, callable $fn)
{
    if ($pdo->inTransaction()) {
        return $fn();
    }

    $pdo->beginTransaction();
    try {
        $result = $fn();
        $pdo->commit();
        return $result;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * Work out a value's type and numeric form, using the same rules as
 * migration 003. Plain numbers ('4.25', '125,000') get a numeric value,
 * and anything else is treated as text.
 *
 * Returns [value_type, value_numeric].
 */
function parseFactValue(string $value, ?string $unit): array
{
    $plain = str_replace(',', '', trim($value));

    if (!preg_match('/^-?\d+(\.\d+)?$/', $plain)) {
        // ISO dates are stored as type 'date' so they can be formatted.
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($value))) {
            return ['date', null];
        }
        return ['text', null];
    }

    if ($unit === '%') {
        $type = 'percent';
    } elseif (in_array($unit, ['£', '$', '€'], true)) {
        $type = 'currency';
    } elseif (strpos($plain, '.') === false) {
        $type = 'integer';
    } else {
        $type = 'decimal';
    }

    return [$type, $plain];
}

/**
 * Remove the fact's own unit if it was typed into the value, so '4.5%',
 * '£125,000' and '5 years' are stored as '4.5', '125,000' and '5'. The
 * unit is added back when the value is displayed.
 */
function stripUnitFromValue(string $value, ?string $unit): string
{
    $value = trim($value);
    $unit = trim((string) $unit);

    if ($unit === '') {
        return $value;
    }
    if (str_starts_with($value, $unit)) {
        $value = substr($value, strlen($unit));
    }
    if (str_ends_with($value, $unit)) {
        $value = substr($value, 0, -strlen($unit));
    }

    return trim($value);
}

/**
 * Values go live the moment they're written, so an effective date in the
 * future would show a figure before it applies. Announced future changes
 * (e.g. Budget measures) will be queued in fact_changes by the pipeline
 * and applied on the day. Until then, enter them once they're in effect.
 */
function assertEffectiveDateNotFuture(?string $effectiveFrom): void
{
    if ($effectiveFrom !== null && $effectiveFrom > date('Y-m-d')) {
        throw new RuntimeException(
            "Effective date $effectiveFrom is in the future. Enter the change once it's in effect"
        );
    }
}

/**
 * Look up a jurisdiction by its ISO code ('GB', 'GB-SCT'). Cached, since
 * an import can hit the same code hundreds of times.
 */
function getJurisdictionByCode(PDO $pdo, string $code): array
{
    static $cache = [];
    $code = strtoupper(trim($code));

    if (!isset($cache[$code])) {
        $stmt = $pdo->prepare('SELECT * FROM jurisdictions WHERE code = :code');
        $stmt->execute(['code' => $code]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new RuntimeException("Unknown jurisdiction code '$code'");
        }
        $cache[$code] = $row;
    }

    return $cache[$code];
}

/**
 * The country a jurisdiction belongs to: itself for a country, or its
 * parent for a sub-region such as Scotland.
 */
function getCountryId(array $jurisdiction): int
{
    return (int) ($jurisdiction['parent_id'] ?? $jurisdiction['id']);
}

/**
 * If a URL's domain is on the allowlist, returns ['licence' => ...],
 * otherwise NULL.
 */
function getAllowlistEntry(string $url): ?array
{
    $host = strtolower((string) parse_url($url, PHP_URL_HOST));

    foreach (FACT_SOURCE_ALLOWLIST as $domain => $licence) {
        if ($host === $domain || str_ends_with($host, '.' . $domain)) {
            return ['licence' => $licence];
        }
    }

    return null;
}

/**
 * Find a source by URL, or create it. New sources are marked primary and
 * allowlisted only if their domain is on FACT_SOURCE_ALLOWLIST.
 */
function getOrCreateSource(PDO $pdo, string $url, ?string $publisher, ?int $jurisdictionId): int
{
    $stmt = $pdo->prepare('SELECT id FROM sources WHERE url = :url');
    $stmt->execute(['url' => $url]);
    $id = $stmt->fetchColumn();
    if ($id) {
        return (int) $id;
    }

    $allow = getAllowlistEntry($url);

    $stmt = $pdo->prepare(
        'INSERT INTO sources (jurisdiction_id, publisher, url, source_type, is_allowlisted, licence)
         VALUES (:jid, :publisher, :url, :type, :allow, :licence)'
    );
    $stmt->execute([
        'jid'       => $jurisdictionId,
        'publisher' => $publisher ?: (parse_url($url, PHP_URL_HOST) ?: 'Unknown'),
        'url'       => $url,
        'type'      => $allow ? 'primary' : 'secondary',
        'allow'     => $allow ? 1 : 0,
        'licence'   => $allow['licence'] ?? null,
    ]);

    return (int) $pdo->lastInsertId();
}

/**
 * Show a fact on a subject page. New links go to the end of the page
 * (sort_order steps of 10). Returns true if a link was created, false if
 * it already existed, in which case only the context override is updated,
 * and only when one is given.
 */
function linkFactToSubject(PDO $pdo, int $subjectId, int $factId, ?string $contextOverride = null): bool
{
    $stmt = $pdo->prepare('SELECT 1 FROM subject_facts WHERE subject_id = :sid AND fact_id = :fid');
    $stmt->execute(['sid' => $subjectId, 'fid' => $factId]);

    if ($stmt->fetchColumn()) {
        if ($contextOverride !== null) {
            $stmt = $pdo->prepare(
                'UPDATE subject_facts SET context_override = :ctx WHERE subject_id = :sid AND fact_id = :fid'
            );
            $stmt->execute(['ctx' => $contextOverride, 'sid' => $subjectId, 'fid' => $factId]);
        }
        return false;
    }

    $stmt = $pdo->prepare(
        'INSERT INTO subject_facts (subject_id, fact_id, sort_order, context_override)
         SELECT :sid, :fid, COALESCE(MAX(sort_order), 0) + 10, :ctx
         FROM subject_facts WHERE subject_id = :sid2'
    );
    $stmt->execute(['sid' => $subjectId, 'fid' => $factId, 'ctx' => $contextOverride, 'sid2' => $subjectId]);

    return true;
}

/**
 * Create a fact, link it to its owner page, and record its first value in
 * fact_history, all in one transaction. Returns the new fact's id.
 *
 * Required keys: primary_subject_id, jurisdiction_id, fact_key, label, value.
 * Optional: unit, value_display, context, source_id, last_updated
 * (Y-m-d, defaults to today), effective_from, tax_year,
 * review_frequency_days (default 90), status (default 'draft'),
 * change_source (default 'manual').
 */
function createFact(PDO $pdo, array $f): int
{
    return withTransaction($pdo, function () use ($pdo, $f) {
        assertEffectiveDateNotFuture($f['effective_from'] ?? null);
        $f['value'] = stripUnitFromValue($f['value'], $f['unit'] ?? null);
        [$type, $numeric] = parseFactValue($f['value'], $f['unit'] ?? null);
        $lastUpdated = $f['last_updated'] ?? date('Y-m-d');

        $stmt = $pdo->prepare(
            'INSERT INTO facts
                (primary_subject_id, jurisdiction_id, fact_key, label, value, value_type, value_numeric,
                 value_display, unit, context, source_id, last_updated, effective_from, tax_year,
                 last_verified_at, status, review_frequency_days)
             VALUES
                (:subject_id, :jid, :fact_key, :label, :value, :type, :numeric,
                 :display, :unit, :context, :source_id, :last_updated, :effective_from, :tax_year,
                 :verified, :status, :review)'
        );
        $stmt->execute([
            'subject_id'     => $f['primary_subject_id'],
            'jid'            => $f['jurisdiction_id'],
            'fact_key'       => $f['fact_key'],
            'label'          => $f['label'],
            'value'          => $f['value'],
            'type'           => $type,
            'numeric'        => $numeric,
            'display'        => $f['value_display'] ?? null,
            'unit'           => $f['unit'] ?? null,
            'context'        => $f['context'] ?? null,
            'source_id'      => $f['source_id'] ?? null,
            'last_updated'   => $lastUpdated,
            'effective_from' => $f['effective_from'] ?? null,
            'tax_year'       => $f['tax_year'] ?? null,
            // A new fact has just been checked by whoever added it.
            'verified'       => $lastUpdated,
            'status'         => $f['status'] ?? 'draft',
            'review'         => $f['review_frequency_days'] ?? 90,
        ]);
        $factId = (int) $pdo->lastInsertId();

        linkFactToSubject($pdo, (int) $f['primary_subject_id'], $factId);

        $stmt = $pdo->prepare(
            'INSERT INTO fact_history
                (fact_id, new_value, new_value_numeric, effective_from, change_source, note)
             VALUES (:fid, :value, :numeric, :effective_from, :source, :note)'
        );
        $stmt->execute([
            'fid'            => $factId,
            'value'          => $f['value'],
            'numeric'        => $numeric,
            'effective_from' => $f['effective_from'] ?? null,
            'source'         => $f['change_source'] ?? 'manual',
            'note'           => 'Fact created',
        ]);

        return $factId;
    });
}

/**
 * Set a fact's value. This is the only function that should ever change
 * facts.value.
 *
 * If the value really changed, the fact is updated and a fact_history row
 * records old and new. If it didn't, the fact is just marked verified.
 * A formatting-only difference ('125000' vs '125,000') counts as
 * unchanged: the new text is stored, but no history row is written.
 *
 * Options:
 *   change_source   'manual' | 'import' | 'pipeline' (default 'manual')
 *   effective_from  Y-m-d the new value took effect, if known
 *   tax_year        e.g. '2026/27', if relevant
 *   changed_on      Y-m-d to store as last_updated (default today)
 *   fact_change_id  the pipeline proposal that caused this, if any
 *   note            free text for the history row
 *
 * Returns true if the value changed, false if it was only verified.
 */
function updateFactValue(PDO $pdo, int $factId, string $newValue, array $opts = []): bool
{
    return withTransaction($pdo, function () use ($pdo, $factId, $newValue, $opts) {
        assertEffectiveDateNotFuture($opts['effective_from'] ?? null);

        // Lock the row so two writers can't both record the same change.
        $stmt = $pdo->prepare('SELECT id, value, value_numeric, unit FROM facts WHERE id = :id FOR UPDATE');
        $stmt->execute(['id' => $factId]);
        $fact = $stmt->fetch();
        if (!$fact) {
            throw new RuntimeException("Fact $factId not found");
        }

        $newValue = stripUnitFromValue($newValue, $fact['unit']);
        [$type, $numeric] = parseFactValue($newValue, $fact['unit']);

        $sameText = $newValue === $fact['value'];
        $sameNumber = $numeric !== null && $fact['value_numeric'] !== null
            && (float) $numeric === (float) $fact['value_numeric'];

        if ($sameText || $sameNumber) {
            if (!$sameText) {
                // Formatting-only change, so store the new text but no history.
                $stmt = $pdo->prepare('UPDATE facts SET value = :value WHERE id = :id');
                $stmt->execute(['value' => $newValue, 'id' => $factId]);
            }
            markFactVerified($pdo, $factId);
            return false;
        }

        // A new value makes the old effective date and tax year wrong, so
        // they are replaced (or cleared, if not given) rather than kept.
        $stmt = $pdo->prepare(
            'UPDATE facts SET
                value = :value, value_type = :type, value_numeric = :numeric,
                last_updated = :changed_on, effective_from = :effective_from, tax_year = :tax_year,
                last_verified_at = NOW()
             WHERE id = :id'
        );
        $stmt->execute([
            'value'          => $newValue,
            'type'           => $type,
            'numeric'        => $numeric,
            'changed_on'     => $opts['changed_on'] ?? date('Y-m-d'),
            'effective_from' => $opts['effective_from'] ?? null,
            'tax_year'       => $opts['tax_year'] ?? null,
            'id'             => $factId,
        ]);

        $stmt = $pdo->prepare(
            'INSERT INTO fact_history
                (fact_id, old_value, old_value_numeric, new_value, new_value_numeric,
                 effective_from, change_source, fact_change_id, note)
             VALUES (:fid, :old, :old_num, :new, :new_num, :effective_from, :source, :change_id, :note)'
        );
        $stmt->execute([
            'fid'            => $factId,
            'old'            => $fact['value'],
            'old_num'        => $fact['value_numeric'],
            'new'            => $newValue,
            'new_num'        => $numeric,
            'effective_from' => $opts['effective_from'] ?? null,
            'source'         => $opts['change_source'] ?? 'manual',
            'change_id'      => $opts['fact_change_id'] ?? null,
            'note'           => $opts['note'] ?? null,
        ]);

        return true;
    });
}

/**
 * Record that a fact was checked against its source and is still right.
 * Resets its review window and logs the check in fact_checks.
 */
function markFactVerified(PDO $pdo, int $factId): void
{
    $stmt = $pdo->prepare('UPDATE facts SET last_verified_at = NOW() WHERE id = :id');
    $stmt->execute(['id' => $factId]);

    $stmt = $pdo->prepare(
        "INSERT INTO fact_checks (fact_id, source_id, result, observed_value)
         SELECT id, source_id, 'unchanged', value FROM facts WHERE id = :id"
    );
    $stmt->execute(['id' => $factId]);
}

/**
 * Record the value a fact had before its current one, e.g. the 2025/26
 * rate when importing the 2026/27 rate for the first time. This gives
 * the page's "What's changed" section real history from day one,
 * instead of only from the first change the site itself sees.
 *
 * Writes one fact_history row: previous value -> current value, dated
 * by $effectiveFrom (when the current value took effect). Safe to call
 * on every import: if that exact change is already recorded (for
 * example because updateFactValue() just wrote it), nothing is added.
 *
 * Returns true if a row was written.
 */
function recordPreviousValue(PDO $pdo, int $factId, string $previousValue, ?string $effectiveFrom): bool
{
    $stmt = $pdo->prepare('SELECT value, value_numeric, unit FROM facts WHERE id = :id');
    $stmt->execute(['id' => $factId]);
    $fact = $stmt->fetch();
    if (!$fact) {
        throw new RuntimeException("Fact $factId not found");
    }

    $previousValue = stripUnitFromValue($previousValue, $fact['unit']);
    [, $previousNumeric] = parseFactValue($previousValue, $fact['unit']);

    // Already recorded? Compare numerically where possible, so '10' and
    // '10.00' count as the same value.
    $stmt = $pdo->prepare(
        'SELECT old_value, old_value_numeric FROM fact_history
         WHERE fact_id = :fid AND old_value IS NOT NULL AND new_value = :new'
    );
    $stmt->execute(['fid' => $factId, 'new' => $fact['value']]);
    foreach ($stmt->fetchAll() as $row) {
        $sameNumber = $previousNumeric !== null && $row['old_value_numeric'] !== null
            && (float) $previousNumeric === (float) $row['old_value_numeric'];
        if ($sameNumber || $row['old_value'] === $previousValue) {
            return false;
        }
    }

    $stmt = $pdo->prepare(
        'INSERT INTO fact_history
            (fact_id, old_value, old_value_numeric, new_value, new_value_numeric,
             effective_from, change_source, note, changed_at)
         VALUES (:fid, :old, :old_num, :new, :new_num, :effective_from, \'import\',
                 \'Previous value recorded at import\', :changed_at)'
    );
    $stmt->execute([
        'fid'            => $factId,
        'old'            => $previousValue,
        'old_num'        => $previousNumeric,
        'new'            => $fact['value'],
        'new_num'        => $fact['value_numeric'],
        'effective_from' => $effectiveFrom,
        // Date the row by when the change happened, not when it was imported.
        'changed_at'     => ($effectiveFrom ?? date('Y-m-d')) . ' 00:00:00',
    ]);

    return true;
}

// ------------------------------------------------------------
// Admin editor writes (Phase 1).
//
// These change wording and settings, not values, so they don't write
// fact_history: "What's changed" on the public pages is about figures,
// and a reworded label isn't a change in the figure.
// ------------------------------------------------------------

/** The statuses a subject can have (see fact.php for what each means publicly). */
const SUBJECT_STATUSES = ['draft', 'published', 'retired'];

/**
 * Tidy text typed into an admin form. Trims it, turns Windows line
 * endings into plain ones, and returns NULL for an empty field, so
 * "nothing entered" is always stored the same way (the public pages
 * treat NULL as "not set" and fall back, e.g. meta title to name).
 *
 * $singleLine collapses all runs of whitespace, including line breaks,
 * to one space: right for titles, labels and meta descriptions.
 */
function cleanEditorText(?string $text, bool $singleLine = false): ?string
{
    $text = str_replace(["\r\n", "\r"], "\n", (string) $text);
    if ($singleLine) {
        $text = preg_replace('/\s+/u', ' ', $text);
    }
    $text = trim($text);
    return $text === '' ? null : $text;
}

/**
 * Length in characters rather than bytes, so £ counts as one. Uses a
 * regex instead of mb_strlen so it doesn't depend on mbstring (same
 * approach as the meta description trim in header.php).
 */
function textLength(?string $text): int
{
    return $text === null ? 0 : (int) preg_match_all('/./su', $text);
}

/**
 * Save a subject's editable fields from the admin editor: intro,
 * explanation, meta title, meta description and status. Name and slug
 * aren't editable here, because changing a slug changes the page's
 * address.
 *
 * $fields keys: intro, explanation, meta_title, meta_description, status.
 * The explanation column comes from migration 010.
 */
function updateSubjectDetails(PDO $pdo, int $subjectId, array $fields): void
{
    $intro = cleanEditorText($fields['intro'] ?? null);
    $explanation = cleanEditorText($fields['explanation'] ?? null);
    $metaTitle = cleanEditorText($fields['meta_title'] ?? null, true);
    $metaDescription = cleanEditorText($fields['meta_description'] ?? null, true);
    $status = (string) ($fields['status'] ?? '');

    if (!in_array($status, SUBJECT_STATUSES, true)) {
        throw new RuntimeException('Choose a status: draft, published or retired.');
    }
    // 255 is a hard stop to fit the database column. The editor's
    // counters show the much shorter lengths search results actually use.
    if (textLength($metaTitle) > 255) {
        throw new RuntimeException('Meta title is too long (255 characters at most).');
    }
    if (textLength($metaDescription) > 255) {
        throw new RuntimeException('Meta description is too long (255 characters at most).');
    }
    // Far more than a page needs (about 3,000 words), but stops a stray
    // paste of a whole document.
    if (textLength($explanation) > 20000) {
        throw new RuntimeException('Explanation is too long (20,000 characters at most).');
    }

    // Placeholders ({fact:key}, {tax_year}) must all be valid, or readers
    // would see them as typed. Field names match the editor's labels.
    assertValidPlaceholders($pdo, [
        'Intro'            => $intro,
        'Explanation'      => $explanation,
        'Meta title'       => $metaTitle,
        'Meta description' => $metaDescription,
    ]);

    $stmt = $pdo->prepare('SELECT id FROM subjects WHERE id = :id');
    $stmt->execute(['id' => $subjectId]);
    if (!$stmt->fetchColumn()) {
        throw new RuntimeException("Subject $subjectId not found");
    }

    $stmt = $pdo->prepare(
        'UPDATE subjects
         SET intro = :intro, explanation = :explanation, meta_title = :meta_title,
             meta_description = :meta_description, status = :status
         WHERE id = :id'
    );
    $stmt->execute([
        'intro'            => $intro,
        'explanation'      => $explanation,
        'meta_title'       => $metaTitle,
        'meta_description' => $metaDescription,
        'status'           => $status,
        'id'               => $subjectId,
    ]);
}

/**
 * Store an AI draft of a subject's four text fields (subject_drafts,
 * migration 012), replacing any earlier draft for that subject. Drafts
 * never touch the live page.
 *
 * $draft keys: intro, explanation, meta_title, meta_description, and
 * problems (a list of sentences). $madeBy is 'nightly' or 'editor'.
 */
function saveSubjectDraft(PDO $pdo, int $subjectId, array $draft, string $madeBy): void
{
    $pdo->prepare(
        'REPLACE INTO subject_drafts
            (subject_id, intro, explanation, meta_title, meta_description, problems, made_by, model, created_at)
         VALUES (:sid, :intro, :explanation, :meta_title, :meta_description, :problems, :made_by, :model, NOW())'
    )->execute([
        'sid'              => $subjectId,
        'intro'            => $draft['intro'] ?? null,
        'explanation'      => $draft['explanation'] ?? null,
        'meta_title'       => $draft['meta_title'] ?? null,
        'meta_description' => $draft['meta_description'] ?? null,
        'problems'         => !empty($draft['problems']) ? implode("\n", $draft['problems']) : null,
        'made_by'          => $madeBy,
        'model'            => defined('ANTHROPIC_MODEL') ? ANTHROPIC_MODEL : null,
    ]);
}

/** Remove a subject's draft, once the page has been written. */
function deleteSubjectDraft(PDO $pdo, int $subjectId): void
{
    $pdo->prepare('DELETE FROM subject_drafts WHERE subject_id = :sid')->execute(['sid' => $subjectId]);
}

/**
 * Save a fact's wording and review cadence from the admin editor: label,
 * context and review_frequency_days. The value itself is never changed
 * here. That always goes through updateFactValue(), so it's recorded in
 * fact_history.
 *
 * $fields keys: label, context, review_frequency_days.
 */
function updateFactDetails(PDO $pdo, int $factId, array $fields): void
{
    $label = cleanEditorText($fields['label'] ?? null, true);
    $context = cleanEditorText($fields['context'] ?? null, true);
    $reviewDays = filter_var($fields['review_frequency_days'] ?? null, FILTER_VALIDATE_INT);

    if ($label === null) {
        throw new RuntimeException('A fact needs a label.');
    }
    if (textLength($label) > 255) {
        throw new RuntimeException('Label is too long (255 characters at most).');
    }
    // Between a day and ten years. Annual figures usually use 365, and
    // rates that can move any month 30.
    if ($reviewDays === false || $reviewDays < 1 || $reviewDays > 3650) {
        throw new RuntimeException('Review every must be a whole number of days, from 1 to 3650.');
    }

    $stmt = $pdo->prepare('SELECT id FROM facts WHERE id = :id');
    $stmt->execute(['id' => $factId]);
    if (!$stmt->fetchColumn()) {
        throw new RuntimeException("Fact $factId not found");
    }

    $stmt = $pdo->prepare(
        'UPDATE facts SET label = :label, context = :context, review_frequency_days = :review WHERE id = :id'
    );
    $stmt->execute([
        'label'   => $label,
        'context' => $context,
        'review'  => $reviewDays,
        'id'      => $factId,
    ]);
}

// ------------------------------------------------------------
// Adding subjects and facts from the admin editor (Phase 1, step 2).
//
// These check everything typed into the forms, then hand over to the
// same code the importer uses (createFact, getOrCreateSource), so a fact
// added in the browser ends up exactly like an imported one, with its
// first fact_history row and its page link.
// ------------------------------------------------------------

/** Statuses a fact can be given when it's added. Retiring comes later. */
const NEW_FACT_STATUSES = ['draft', 'published'];

/**
 * Create a subject page. Returns the new subject's id.
 *
 * $s keys: subcategory_id, name, slug, intro (optional), status
 * (default 'draft', so a new page stays hidden until it has facts and
 * an intro worth showing).
 */
function createSubject(PDO $pdo, array $s): int
{
    $name = cleanEditorText($s['name'] ?? null, true);
    $slug = strtolower((string) cleanEditorText($s['slug'] ?? null, true));
    $intro = cleanEditorText($s['intro'] ?? null);
    $status = (string) ($s['status'] ?? 'draft');
    $subcategoryId = (int) ($s['subcategory_id'] ?? 0);

    if ($name === null) {
        throw new RuntimeException('Give the subject a name.');
    }
    if (textLength($name) > 255) {
        throw new RuntimeException('Name is too long (255 characters at most).');
    }
    // Slugs become the last part of the page address, so only lowercase
    // letters, numbers and single hyphens, e.g. 2-year-fixed-mortgage-rates.
    if (!preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $slug) || strlen($slug) > 120) {
        throw new RuntimeException('The address (slug) can only use lowercase letters, numbers and hyphens, e.g. junior-isa-allowance.');
    }
    if (!in_array($status, SUBJECT_STATUSES, true)) {
        throw new RuntimeException('Choose a status: draft, published or retired.');
    }
    // Same placeholder check as every other save of page text.
    assertValidPlaceholders($pdo, ['Intro' => $intro]);

    $stmt = $pdo->prepare('SELECT id FROM subcategories WHERE id = :id');
    $stmt->execute(['id' => $subcategoryId]);
    if (!$stmt->fetchColumn()) {
        throw new RuntimeException('Choose where the page goes (category and subcategory).');
    }

    // Subject slugs are unique across the whole site (fact.php finds a
    // page by its slug alone), so check before inserting to give a clear
    // message rather than a database error.
    $stmt = $pdo->prepare('SELECT name FROM subjects WHERE slug = :slug');
    $stmt->execute(['slug' => $slug]);
    $existing = $stmt->fetchColumn();
    if ($existing !== false) {
        throw new RuntimeException("The address '$slug' is already used by \"$existing\". Choose another.");
    }

    $stmt = $pdo->prepare(
        'INSERT INTO subjects (subcategory_id, name, slug, intro, status)
         VALUES (:subcategory_id, :name, :slug, :intro, :status)'
    );
    $stmt->execute([
        'subcategory_id' => $subcategoryId,
        'name'           => $name,
        'slug'           => $slug,
        'intro'          => $intro,
        'status'         => $status,
    ]);

    return (int) $pdo->lastInsertId();
}

/**
 * Add a new fact, owned by $subjectId, from the editor's "Add a fact"
 * form. Returns ['id' => new fact id, 'status' => status it was saved
 * with, 'note' => why the status differs from the one asked for, or
 * NULL].
 *
 * $in keys: fact_key, label, value, unit, context, jurisdiction_id,
 * source_url, source_publisher, effective_from, tax_year,
 * review_frequency_days, status.
 *
 * A fact can only be published straight away if its source is on the
 * allowlist (official sources). Anything else is saved as a draft, the
 * same rule the automated pipeline will follow.
 */
function addFactToSubject(PDO $pdo, int $subjectId, array $in): array
{
    $factKey = strtolower((string) cleanEditorText($in['fact_key'] ?? null, true));
    $label = cleanEditorText($in['label'] ?? null, true);
    $value = cleanEditorText($in['value'] ?? null, true);
    $unit = cleanEditorText($in['unit'] ?? null, true);
    $context = cleanEditorText($in['context'] ?? null, true);
    $sourceUrl = cleanEditorText($in['source_url'] ?? null, true);
    $publisher = cleanEditorText($in['source_publisher'] ?? null, true);
    $effectiveFrom = cleanEditorText($in['effective_from'] ?? null, true);
    $taxYear = cleanEditorText($in['tax_year'] ?? null, true);
    $jurisdictionId = (int) ($in['jurisdiction_id'] ?? 0);
    $reviewDays = filter_var($in['review_frequency_days'] ?? null, FILTER_VALIDATE_INT);
    $status = (string) ($in['status'] ?? 'draft');

    // --- Check each field, with a message saying what to fix. ---

    // Keys are what {{fact:key}} placeholders and worked examples use, so
    // they follow the same pattern as those (letters, numbers, _).
    if (!preg_match('/^[a-z0-9]+(_[a-z0-9]+)*$/', $factKey) || strlen($factKey) > 100) {
        throw new RuntimeException('The key can only use lowercase letters, numbers and underscores, e.g. junior_isa_allowance.');
    }
    if ($label === null) {
        throw new RuntimeException('A fact needs a label.');
    }
    if (textLength($label) > 255) {
        throw new RuntimeException('Label is too long (255 characters at most).');
    }
    if ($value === null) {
        throw new RuntimeException('Enter the value.');
    }
    if ($unit !== null && textLength($unit) > 20) {
        throw new RuntimeException('Unit is too long. Use a short unit such as £, %, years or weeks.');
    }
    if ($effectiveFrom !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $effectiveFrom)) {
        throw new RuntimeException('Effective date must be a valid date.');
    }
    // Same format as the importer and the "tax year ended" check use.
    if ($taxYear !== null && !preg_match('/^\d{4}\/\d{2}$/', $taxYear)) {
        throw new RuntimeException('Tax year must look like 2026/27.');
    }
    if ($reviewDays === false || $reviewDays < 1 || $reviewDays > 3650) {
        throw new RuntimeException('Review every must be a whole number of days, from 1 to 3650.');
    }
    if (!in_array($status, NEW_FACT_STATUSES, true)) {
        throw new RuntimeException('Choose a status: draft or published.');
    }
    if ($sourceUrl !== null) {
        $scheme = strtolower((string) parse_url($sourceUrl, PHP_URL_SCHEME));
        if (!filter_var($sourceUrl, FILTER_VALIDATE_URL) || !in_array($scheme, ['http', 'https'], true)) {
            throw new RuntimeException('Source must be a full web address, starting https://');
        }
    }

    // The key must be new. Facts are found by key across the whole site.
    $stmt = $pdo->prepare('SELECT label FROM facts WHERE fact_key = :key');
    $stmt->execute(['key' => $factKey]);
    $existing = $stmt->fetchColumn();
    if ($existing !== false) {
        throw new RuntimeException("The key '$factKey' is already used by \"$existing\". Choose another.");
    }

    // The fact's jurisdiction must be the page's country or one of its
    // nations/regions, so a UK page can't get (say) a US figure by mistake.
    $stmt = $pdo->prepare(
        'SELECT j.id
         FROM subjects s
         JOIN subcategories sc ON sc.id = s.subcategory_id
         JOIN categories c ON c.id = sc.category_id
         JOIN jurisdictions j ON j.id = c.jurisdiction_id OR j.parent_id = c.jurisdiction_id
         WHERE s.id = :sid AND j.id = :jid'
    );
    $stmt->execute(['sid' => $subjectId, 'jid' => $jurisdictionId]);
    if (!$stmt->fetchColumn()) {
        throw new RuntimeException('Choose where the figure applies (the UK, or one of its nations).');
    }

    // --- Decide the status: only allowlisted sources publish directly. ---
    $note = null;
    if ($status === 'published' && ($sourceUrl === null || getAllowlistEntry($sourceUrl) === null)) {
        $status = 'draft';
        $note = $sourceUrl === null
            ? 'Saved as a draft: a fact needs a source before it can be published.'
            : 'Saved as a draft: ' . parse_url($sourceUrl, PHP_URL_HOST) . ' isn\'t on the source allowlist, so it can\'t publish directly.';
    }

    // --- Write, in one transaction: the source (if new) and the fact. ---
    $factId = withTransaction($pdo, function () use (
        $pdo, $subjectId, $factKey, $label, $value, $unit, $context, $sourceUrl, $publisher,
        $effectiveFrom, $taxYear, $jurisdictionId, $reviewDays, $status
    ) {
        $sourceId = $sourceUrl !== null ? getOrCreateSource($pdo, $sourceUrl, $publisher, $jurisdictionId) : null;

        return createFact($pdo, [
            'primary_subject_id'    => $subjectId,
            'jurisdiction_id'       => $jurisdictionId,
            'fact_key'              => $factKey,
            'label'                 => $label,
            'value'                 => $value,
            'unit'                  => $unit,
            'context'               => $context,
            'source_id'             => $sourceId,
            'effective_from'        => $effectiveFrom,
            'tax_year'              => $taxYear,
            'review_frequency_days' => $reviewDays,
            'status'                => $status,
            'change_source'         => 'manual',
        ]);
    });

    return ['id' => $factId, 'status' => $status, 'note' => $note];
}
