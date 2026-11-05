<?php
/**
 * Reading official source pages as plain text.
 *
 * Used by scripts/check-sources.php (is each figure still on its page?)
 * and by the editor's AI text check in includes/text-checks.php (does the
 * page's text agree with its sources?). Moved here unchanged from
 * check-sources.php so both read pages the same way.
 */

/** Sources handled by another job, or not readable as a page (a database). */
const SKIP_SOURCE_URLS = ['https://www.bankofengland.co.uk/boeapps/database/'];

/**
 * The headers a desktop browser sends when you open a page by typing its address.
 * Some sites refuse a request without them: on 4 November 2026 the ATO answered 403
 * (Akamai), and Services Australia and the Fair Work Ombudsman reset the connection,
 * to the plain request, while all three answered normally once these were added. They
 * are sent only after a plain request has failed (see fetchPageText()). The
 * diagnostic scripts/check-source-access.php shows which sources need them.
 */
const SOURCE_BROWSER_HEADERS = [
    'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
    'Accept-Language: en-AU,en;q=0.9',
    'Upgrade-Insecure-Requests: 1',
    'Sec-Fetch-Dest: document',
    'Sec-Fetch-Mode: navigate',
    'Sec-Fetch-Site: none',
    'Sec-Fetch-User: ?1',
    'Cache-Control: max-age=0',
];

/**
 * One download. Returns [body or false, HTTP status (0 if none), curl's error text].
 * The user agent names this site; $browserHeaders adds SOURCE_BROWSER_HEADERS.
 */
function fetchOverHttp(string $url, bool $browserHeaders): array
{
    $curl = curl_init($url);
    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_ENCODING       => '',
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) '
            . 'Chrome/124.0 Safari/537.36 (financefactlibrary.com data check)',
    ];
    if ($browserHeaders) {
        $options[CURLOPT_HTTPHEADER] = SOURCE_BROWSER_HEADERS;
    }
    curl_setopt_array($curl, $options);
    $body = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $error = curl_error($curl);
    curl_close($curl);

    return [$body, $status, $error];
}

/**
 * Download a page and reduce it to plain text: scripts, styles and tags
 * removed, entities decoded, whitespace collapsed, and thousands
 * separators taken out of numbers so "£12,570" reads as "£12570".
 *
 * For testing without internet access, set FF_TEST_FETCH_DIR to a folder
 * of saved pages named md5(url).html.
 */
function fetchPageText(string $url): string
{
    $testDir = getenv('FF_TEST_FETCH_DIR');
    if ($testDir) {
        $html = @file_get_contents(rtrim($testDir, '/') . '/' . md5($url) . '.html');
        if ($html === false) {
            throw new RuntimeException('no saved test page');
        }
    } else {
        // Hosts that refused the plain request and then answered once browser headers
        // were added, remembered for the rest of this run so that a source check which
        // reads seven pages from one site asks for the first page twice and the other
        // six once. (A static variable lasts as long as the PHP process.)
        static $needsBrowserHeaders = [];
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        [$html, $status, $error] = fetchOverHttp($url, isset($needsBrowserHeaders[$host]));

        // Refused outright (403), or no proper answer at all (status 0: for example the
        // HTTP/2 "stream reset" that the Services Australia and Fair Work Ombudsman sites
        // answer a bare request with). Try once more the way a browser asks. The user
        // agent stays the one above, so the site is still told who is asking. If the
        // retry fails too (the Fair Work Commission sits behind a JavaScript challenge),
        // the first attempt's answer is the one reported.
        if (($html === false || $status !== 200) && ($status === 403 || $status === 0) && !isset($needsBrowserHeaders[$host])) {
            [$retryHtml, $retryStatus, $retryError] = fetchOverHttp($url, true);
            if ($retryHtml !== false && $retryStatus === 200) {
                $needsBrowserHeaders[$host] = true;
                [$html, $status, $error] = [$retryHtml, $retryStatus, $retryError];
            }
        }

        if ($html === false || $status !== 200) {
            throw new RuntimeException("HTTP $status $error");
        }
    }

    $html = preg_replace('#<(script|style|noscript)\b[^>]*>.*?</\1>#is', ' ', $html);
    // Keep table cells and blocks apart, so numbers in neighbouring cells don't run together.
    $html = preg_replace('#<(/?)(td|th|tr|p|li|div|br|h\d)\b[^>]*>#i', ' ', $html);
    $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/[\s\x{00A0}]+/u', ' ', $text);

    // 12,570 -> 12570 (repeat for numbers with several separators, like 1,073,100).
    do {
        $before = $text;
        $text = preg_replace('/(\d),(\d{3})(?!\d)/', '$1$2', $text);
    } while ($text !== $before);

    if (strlen(trim($text)) < 200) {
        throw new RuntimeException('page came back almost empty');
    }

    return $text;
}

/**
 * Read the source pages that a page's figures cite, once each.
 *
 * Used by the AI drafts (so the writer works from the sources, not from
 * memory) and by the AI check (so it can compare the text with them).
 * Pages handled by other jobs (SKIP_SOURCE_URLS) are left out. A page that
 * can't be read is reported, not fatal.
 *
 * $facts  facts with source_url and source_name (as getAdminFactsForSubject()
 *         returns them); only published ones should be passed in
 * $maxCharsEach  each page is cut to this many characters
 *
 * Returns:
 *   sources  url => ['name', 'url', 'read' => bool, 'error', 'hash'], where
 *            hash is the fingerprint check-sources.php also stores in
 *            sources.content_hash, so a later change to the page shows
 *   texts    url => the page's text, for the pages that were read
 */
function readSourcePagesForFacts(array $facts, int $maxCharsEach = 50000): array
{
    $sources = [];
    $texts = [];
    foreach ($facts as $fact) {
        $url = $fact['source_url'] ?? null;
        if (!$url || isset($sources[$url]) || in_array($url, SKIP_SOURCE_URLS, true)) {
            continue;
        }
        $sources[$url] = ['name' => $fact['source_name'] ?? $url, 'url' => $url, 'read' => false, 'error' => null, 'hash' => null];
        try {
            $text = fetchPageText($url);
            $sources[$url]['hash'] = hash('sha256', $text);
            $texts[$url] = preg_match('/^.{0,' . $maxCharsEach . '}/su', $text, $m) ? $m[0] : $text;
            $sources[$url]['read'] = true;
        } catch (Throwable $e) {
            $sources[$url]['error'] = $e->getMessage();
        }
    }
    return ['sources' => $sources, 'texts' => $texts];
}
