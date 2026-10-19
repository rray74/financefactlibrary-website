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
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_ENCODING       => '',
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) '
                . 'Chrome/124.0 Safari/537.36 (financefactlibrary.com data check)',
        ]);
        $html = curl_exec($curl);
        $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error = curl_error($curl);
        curl_close($curl);

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
