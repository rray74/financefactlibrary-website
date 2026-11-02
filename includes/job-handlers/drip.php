<?php
/**
 * Drip publisher: releases a country's staged pages at a steady pace, and
 * only once each page is actually written.
 *
 * JOB TYPE   drip_country
 * PAYLOAD    {"country": "GB", "per_week": 3}
 *              country   the jurisdiction code (GB, AU, NZ, CA, US)
 *              per_week  the most pages to publish in a week (1 to 14, default 3)
 *
 * HOW IT WORKS
 *   One job per country, which never finishes. Each time it runs it decides
 *   whether to publish ONE page, then puts itself back in the queue for the
 *   next decision. The job row's result_note always shows the last decision,
 *   so `php scripts/run-jobs.php --status` tells you what it is waiting for.
 *
 *   Each run, in order:
 *     1. Country not live yet (is_active = 0)?  Wait. Pages stay hidden drafts.
 *        This is the "build a country hidden" stage; setting is_active = 1 is
 *        the launch, and the drip picks up from there.
 *     2. Published something here less than one slot ago?  Wait out the rest
 *        of the slot. A slot is 7 days divided by per_week, so 3 a week means
 *        one page about every 56 hours, not three in a row on Monday.
 *     3. Find the next READY draft page (rules below). None?  Check again in
 *        6 hours. Nothing is published and nothing is wasted.
 *     4. Publish it, then wait one slot.
 *
 *   Why one job per country rather than one per page: if pages each had their
 *   own date, pages that were not written when their date came round would
 *   all fall due together once written and be published in a burst, breaking
 *   the pace. A single job with a slot clock cannot do that.
 *
 * WHAT "READY" MEANS (all must be true, on a page with status 'draft')
 *   - intro, explanation, meta title and meta description each contain real
 *     text, not just spaces or line breaks (the four fields core.md says every
 *     page has)
 *   - at least one of its figures is published, so the page is not an empty
 *     shell
 *   The explanation lives in subjects.explanation, which is only filled when
 *   a person saves text in the editor. AI drafts sit in subject_drafts and are
 *   never counted, so nothing unreviewed can be published by this job.
 *
 * ORDER   Lowest subject id first, i.e. the order pages were imported, so the
 *         content map order is the publishing order.
 *
 * SAFE TO RUN TWICE   Publishing is a single UPDATE that only changes a page
 *   still marked 'draft', so a repeat run cannot publish the same page twice
 *   or touch one you have since retired.
 *
 * START IT    php scripts/run-jobs.php --enqueue=drip_country \
 *               --payload='{"country":"GB","per_week":3}' --unique=drip_country:GB
 * CHECK IT    php scripts/run-jobs.php --status    (the note says what it is waiting for)
 * PREVIEW IT  php scripts/run-jobs.php --dry-run   (says what it would publish; changes nothing)
 * PAUSE IT    php scripts/run-jobs.php --cancel=drip_country:GB
 *             (removes the waiting job; start it again with the line above)
 */

/** How often to look again when nothing is ready (or the country is not live). */
const DRIP_IDLE_RECHECK_SECONDS = 21600; // 6 hours

const DRIP_DEFAULT_PER_WEEK = 3;
const DRIP_MAX_PER_WEEK = 14;

registerJobHandler('drip_country', 'handleDripCountry');

function handleDripCountry(PDO $pdo, array $payload, array $job, array $context): ?string
{
    $dryRun = !empty($context['dry_run']);

    // --- Read and check the payload. A bad payload can never work, so fail at once. ---
    $code = strtoupper(trim((string) ($payload['country'] ?? '')));
    if ($code === '') {
        throw new JobFailedPermanently('The payload needs a "country", for example {"country":"GB"}.');
    }
    $perWeek = (int) ($payload['per_week'] ?? DRIP_DEFAULT_PER_WEEK);
    if ($perWeek < 1 || $perWeek > DRIP_MAX_PER_WEEK) {
        throw new JobFailedPermanently('"per_week" must be between 1 and ' . DRIP_MAX_PER_WEEK . ", got $perWeek.");
    }

    // Top-level jurisdictions only: a country has no parent (sub-regions such as
    // GB-SCT do). Categories belong to the country, so this is the one that matters.
    $stmt = $pdo->prepare('SELECT id, code, name, is_active FROM jurisdictions WHERE code = :code AND parent_id IS NULL');
    $stmt->execute(['code' => $code]);
    $country = $stmt->fetch();
    if (!$country) {
        throw new JobFailedPermanently("No country with code '$code' in the jurisdictions table.");
    }

    // 1. Staging: the country is built but not launched.
    if (!(int) $country['is_active']) {
        return dripWait($dryRun, DRIP_IDLE_RECHECK_SECONDS, $country['name'] . ' is not live yet (is_active = 0). Pages stay as drafts until launch.');
    }

    // 2. Pace: one slot is a week divided by the weekly limit.
    $slotSeconds = (int) floor(7 * 86400 / $perWeek);

    $stmt = $pdo->prepare(
        "SELECT TIMESTAMPDIFF(SECOND, MAX(s.published_at), NOW())
         FROM subjects s
         JOIN subcategories sc ON sc.id = s.subcategory_id
         JOIN categories c ON c.id = sc.category_id
         WHERE c.jurisdiction_id = :jid AND s.status = 'published'"
    );
    $stmt->execute(['jid' => $country['id']]);
    $sinceLast = $stmt->fetchColumn();

    if ($sinceLast !== null && $sinceLast !== false && (int) $sinceLast < $slotSeconds) {
        $wait = $slotSeconds - (int) $sinceLast;
        return dripWait($dryRun, $wait, 'Next slot in ' . dripDuration($wait) . " ($perWeek a week for " . $country['name'] . ').');
    }

    // 3. Find the next ready page.
    //
    // "Filled in" means "contains at least one character that is not whitespace".
    // The pattern '[^[:space:]]' is used rather than TRIM(...) <> '' because TRIM
    // only strips spaces, so a field holding just a line break or a tab would
    // count as written and the page would go live with no text. (A NULL field
    // makes the test NULL, which is treated as not ready.)
    $stmt = $pdo->prepare(
        "SELECT s.id, s.name
         FROM subjects s
         JOIN subcategories sc ON sc.id = s.subcategory_id
         JOIN categories c ON c.id = sc.category_id
         WHERE c.jurisdiction_id = :jid
           AND s.status = 'draft'
           AND s.intro            REGEXP '[^[:space:]]'
           AND s.explanation      REGEXP '[^[:space:]]'
           AND s.meta_title       REGEXP '[^[:space:]]'
           AND s.meta_description REGEXP '[^[:space:]]'
           AND EXISTS (
               SELECT 1 FROM subject_facts sf
               JOIN facts f ON f.id = sf.fact_id
               WHERE sf.subject_id = s.id AND f.status = 'published'
           )
         ORDER BY s.id ASC
         LIMIT 1"
    );
    $stmt->execute(['jid' => $country['id']]);
    $next = $stmt->fetch();

    if (!$next) {
        return dripWait(
            $dryRun,
            DRIP_IDLE_RECHECK_SECONDS,
            'No draft page is ready (needs intro, explanation, meta title, meta description and a published figure).'
        );
    }

    // 4. Publish it.
    if ($dryRun) {
        return 'WOULD publish "' . $next['name'] . '" (subject ' . $next['id'] . ') and then wait ' . dripDuration($slotSeconds) . '.';
    }

    // published_at is set to NOW() every time, not "keep the first date" as the
    // importer does. For a drip, this IS the moment the page is released, and
    // the pace above is measured from it. (A staged page can carry an older
    // published_at stamped when the importer created it.)
    $update = $pdo->prepare(
        "UPDATE subjects SET status = 'published', published_at = NOW() WHERE id = :id AND status = 'draft'"
    );
    $update->execute(['id' => $next['id']]);
    if ($update->rowCount() !== 1) {
        // Someone changed the page between our check and the update. Look again soon.
        throw new JobDeferred(60, 'Page ' . $next['id'] . ' changed while publishing; will look again.');
    }

    throw new JobDeferred(
        $slotSeconds,
        'Published "' . $next['name'] . '" (subject ' . $next['id'] . '). Next slot in ' . dripDuration($slotSeconds) . '.'
    );
}

/**
 * "Wait" is how a recurring job ends a run: it defers itself. A dry run must
 * not defer (that would move the real job), so it just reports instead.
 */
function dripWait(bool $dryRun, int $seconds, string $reason): string
{
    if ($dryRun) {
        return 'WOULD wait: ' . $reason;
    }
    throw new JobDeferred($seconds, $reason);
}

/** 200000 seconds -> "2d 7h"; 5400 -> "1h 30m". For notes people read. */
function dripDuration(int $seconds): string
{
    $days = intdiv($seconds, 86400);
    $hours = intdiv($seconds % 86400, 3600);
    $minutes = intdiv($seconds % 3600, 60);

    if ($days > 0) {
        return $days . 'd ' . $hours . 'h';
    }
    if ($hours > 0) {
        return $hours . 'h ' . $minutes . 'm';
    }
    return max(1, $minutes) . 'm';
}
