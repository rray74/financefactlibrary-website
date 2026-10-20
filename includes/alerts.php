<?php
/**
 * Email alerts to the site owner.
 *
 * Without this, the only way to learn that a figure changed on a source
 * page, or that an AI proposal is waiting, or that a scheduled job has
 * stopped, is to open the dashboard. scripts/send-alerts.php runs daily
 * after the other jobs and emails what's NEW since the last email.
 *
 * What it watches (the same lists the dashboard shows):
 *   - proposed figure changes held for a decision (getHeldChanges())
 *   - figures that need a look: not found on their source page, or the
 *     page couldn't be read (getFactsNeedingAttention())
 *   - scheduled jobs with a problem or that have stopped (describeCronRun())
 *
 * "New" means not emailed about before: each item has a key, kept in
 * admin_alerts_sent (migration 016) once it has been emailed. An item that
 * is still waiting isn't emailed again; one that clears and then comes
 * back is. The first email after switching alerts on lists everything
 * currently waiting.
 *
 * Settings, in config/database.local.php:
 *   define('ALERT_EMAIL_TO', 'you@example.com');          required; alerts are off without it
 *   define('ALERT_EMAIL_FROM', 'alerts@yourdomain.com');   optional; should be a real mailbox on the domain
 *   define('SITE_URL', 'https://financefactlibrary.com');  optional here; used for the links in the email
 *
 * Sent with PHP's mail(), which Hostinger supports. Not listed above:
 * facts "due for review", since the weekly and daily checks keep those
 * fresh, and a job that has stopped is reported through the jobs list.
 */

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/admin-queries.php'; // getLatestCronRuns(), describeCronRun()

/** Most items listed per section in one email (the rest are counted). */
const ALERT_MAX_ITEMS_PER_SECTION = 15;

/** The sections of an alert email, in the order they appear. */
const ALERT_SECTIONS = [
    'held' => 'PROPOSED CHANGES HELD FOR YOUR DECISION',
    'look' => 'FIGURES THAT NEED A LOOK',
    'cron' => 'SCHEDULED JOBS WITH A PROBLEM',
];

/** The address(es) alerts go to, validated, or an empty list if alerts are off. */
function alertRecipients(): array
{
    if (!defined('ALERT_EMAIL_TO') || trim((string) ALERT_EMAIL_TO) === '') {
        return [];
    }
    $recipients = [];
    foreach (explode(',', (string) ALERT_EMAIL_TO) as $address) {
        $address = trim($address);
        if ($address !== '' && filter_var($address, FILTER_VALIDATE_EMAIL)) {
            $recipients[] = $address;
        }
    }
    return $recipients;
}

/** True if alerts are switched on and at least one valid address is set. */
function alertEmailConfigured(): bool
{
    return alertRecipients() !== [];
}

/** The site's address, for links in the email (SITE_URL, or the live domain). */
function alertSiteUrl(): string
{
    return rtrim(defined('SITE_URL') && SITE_URL ? (string) SITE_URL : 'https://financefactlibrary.com', '/');
}

/** One header value with any line breaks removed, so nothing can inject extra headers. */
function alertHeaderValue(string $value): string
{
    return trim(preg_replace('/[\r\n]+/', ' ', $value));
}

/**
 * Send one email to the configured address(es) with mail(). Returns true
 * if PHP handed it to the mail system, which doesn't guarantee delivery:
 * check the spam folder the first time (scripts/send-alerts.php --test).
 */
function sendAdminAlertEmail(string $subject, string $body): bool
{
    $recipients = alertRecipients();
    if (!$recipients) {
        return false;
    }

    $from = defined('ALERT_EMAIL_FROM') && filter_var((string) ALERT_EMAIL_FROM, FILTER_VALIDATE_EMAIL)
        ? (string) ALERT_EMAIL_FROM
        : 'noreply@financefactlibrary.com';
    $name = defined('SITE_NAME') ? (string) SITE_NAME : 'Finance Fact Library';

    $headers = implode("\r\n", [
        'From: ' . alertHeaderValue($name) . ' <' . $from . '>',
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
    ]);

    // Plain ASCII subject, so no mbstring or header encoding is needed.
    return (bool) @mail(implode(', ', $recipients), alertHeaderValue($subject), str_replace("\n", "\r\n", $body), $headers);
}

/** A fact's value or a proposed value, written like the figure cards write it. */
function alertValue(array $row, ?string $value = null, $numeric = null): string
{
    return formatFactValue([
        'value'         => $value ?? $row['value'],
        'value_numeric' => $value !== null ? $numeric : $row['value_numeric'],
        'value_type'    => $row['value_type'],
        'unit'          => $row['unit'],
        'value_display' => null,
    ]);
}

/** Cut text to $max characters on one line, with "..." if shortened. */
function alertShorten(string $text, int $max): string
{
    $flat = trim(preg_replace('/\s+/u', ' ', $text));
    return preg_match('/^.{0,' . $max . '}$/su', $flat) ? $flat
        : (preg_match('/^.{0,' . $max . '}/su', $flat, $m) ? $m[0] : substr($flat, 0, $max)) . '...';
}

/**
 * Everything that currently needs attention, as key => ['section', 'text'].
 * Keys are stable while an item waits (see the top of this file).
 */
function collectAdminAlertItems(): array
{
    $items = [];

    // --- Held changes: a decision is waiting. ---
    foreach (getHeldChanges() as $change) {
        $line = $change['label'] . ': ' . alertValue($change) . ' to '
            . alertValue($change, (string) $change['proposed_value'], $change['proposed_value_numeric']);
        if (!empty($change['status_reason'])) {
            $line .= "\n    Why held: " . alertShorten((string) $change['status_reason'], 200);
        }
        if (!empty($change['evidence_snippet'])) {
            $line .= "\n    Source says: \"" . alertShorten((string) $change['evidence_snippet'], 200) . '"';
        }
        if (!empty($change['source_url'])) {
            $line .= "\n    " . $change['source_url'];
        }
        $items['held:' . (int) $change['id']] = ['section' => 'held', 'text' => $line];
    }

    // --- Figures whose last check failed. ---
    foreach (getFactsNeedingAttention() as $fact) {
        if ($fact['check_result'] === 'source_error') {
            $why = "its source page couldn't be read";
        } elseif ($fact['check_note'] === 'tax_year_ended') {
            $why = 'its tax year has ended';
        } else {
            $why = 'not found on its source page, which may now show a new value';
        }
        $line = $fact['label'] . ': we show ' . alertValue($fact) . '; ' . $why . '.';
        if (!empty($fact['source_url'])) {
            $line .= "\n    " . $fact['source_url'];
        }
        $items['look:' . (int) $fact['id']] = ['section' => 'look', 'text' => $line];
    }

    // --- Scheduled jobs with a problem. One alert per script and kind of
    // problem, so a job that stays broken isn't emailed every day. ---
    $latestRuns = getLatestCronRuns();
    $scripts = array_unique(array_merge(array_keys(ADMIN_EXPECTED_CRON_SCRIPTS), array_keys($latestRuns)));
    foreach ($scripts as $script) {
        $run = $latestRuns[$script] ?? null;
        $state = describeCronRun($run, $script);
        if (!$state['bad']) {
            continue;
        }
        $when = ADMIN_EXPECTED_CRON_SCRIPTS[$script] ?? null;
        $line = $script . ($when ? " ($when)" : '') . ': ' . $state['label'];
        if ($state['started']) {
            $line .= '. Last started ' . date('D j M, H:i', $state['started']);
        }
        // The run's note explains a failure. For a job that has simply
        // stopped, it's the old success summary, which would only confuse.
        if (!empty($run['error']) && in_array($state['state'], ['error', 'stuck'], true)) {
            $line .= ".\n    " . alertShorten((string) $run['error'], 300);
        }
        $items['cron:' . $script . ':' . $state['state']] = ['section' => 'cron', 'text' => $line];
    }

    return $items;
}

/**
 * The email for a set of new items: ['subject' => ..., 'body' => ...].
 * $totalWaiting is how many things need attention in all, new or not.
 */
function buildAdminAlertEmail(array $newItems, int $totalWaiting): array
{
    $count = count($newItems);
    $subject = '[Finance Fact Library] ' . $count . ' new thing' . ($count === 1 ? '' : 's') . ' need' . ($count === 1 ? 's' : '') . ' attention';

    $body = "New since the last alert:\n";
    foreach (ALERT_SECTIONS as $section => $heading) {
        $lines = [];
        foreach ($newItems as $item) {
            if ($item['section'] === $section) {
                $lines[] = $item['text'];
            }
        }
        if (!$lines) {
            continue;
        }
        $body .= "\n" . $heading . "\n";
        foreach (array_slice($lines, 0, ALERT_MAX_ITEMS_PER_SECTION) as $line) {
            $body .= '- ' . $line . "\n";
        }
        if (count($lines) > ALERT_MAX_ITEMS_PER_SECTION) {
            $body .= '- ...and ' . (count($lines) - ALERT_MAX_ITEMS_PER_SECTION) . " more, on the review page.\n";
        }
    }

    $site = alertSiteUrl();
    $body .= "\nIn all, $totalWaiting " . ($totalWaiting === 1 ? 'thing needs' : 'things need') . " attention.\n"
        . "Review page: $site/admin/review.php\n"
        . "Dashboard:   $site/admin/\n\n"
        . "You won't be emailed again about these unless they clear and come back.\n";

    return ['subject' => $subject, 'body' => $body];
}

/**
 * Work out what's new since the last email, email it, and remember it.
 *
 * $send is the function that sends (subject, body) and returns true on
 * success; the default is sendAdminAlertEmail(), and it can be replaced
 * for testing. With $dryRun, nothing is sent or written.
 *
 * Items are only recorded as emailed if the email was handed to the mail
 * system, so a failure is retried on the next run. If alerts aren't
 * configured, nothing is recorded, so the first email after switching
 * them on lists everything waiting.
 *
 * Returns: waiting, new, cleared (counts), configured, emailed (bool),
 * email (subject and body, when there is something new) and error.
 */
function processAdminAlerts(PDO $pdo, bool $dryRun = false, ?callable $send = null): array
{
    $send ??= 'sendAdminAlertEmail';

    $current = collectAdminAlertItems();
    $sentKeys = $pdo->query('SELECT alert_key FROM admin_alerts_sent')->fetchAll(PDO::FETCH_COLUMN);

    $new = array_diff_key($current, array_flip($sentKeys));
    $cleared = array_values(array_diff($sentKeys, array_keys($current)));
    $configured = alertEmailConfigured();

    $result = ['waiting' => count($current), 'new' => count($new), 'cleared' => count($cleared),
               'configured' => $configured, 'emailed' => false, 'email' => null, 'error' => null];

    if ($new) {
        $result['email'] = buildAdminAlertEmail($new, count($current));
        if (!$configured) {
            $result['error'] = 'Alerts are off: ALERT_EMAIL_TO isn\'t set in config/database.local.php.';
        } elseif (!$dryRun) {
            if ($send($result['email']['subject'], $result['email']['body'])) {
                $insert = $pdo->prepare('INSERT INTO admin_alerts_sent (alert_key) VALUES (:k)');
                foreach (array_keys($new) as $key) {
                    $insert->execute(['k' => $key]);
                }
                $result['emailed'] = true;
            } else {
                $error = error_get_last()['message'] ?? '';
                $result['error'] = 'The email could not be sent (mail() failed). Will try again next run.'
                    . ($error !== '' ? ' ' . $error : '');
            }
        }
    }

    // Items that have cleared are forgotten, so they're emailed about
    // again if they come back.
    if ($cleared && $configured && !$dryRun) {
        $delete = $pdo->prepare('DELETE FROM admin_alerts_sent WHERE alert_key = :k');
        foreach ($cleared as $key) {
            $delete->execute(['k' => $key]);
        }
    }

    return $result;
}
