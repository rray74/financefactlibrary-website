<?php
/**
 * Can this machine read the official source pages?
 *
 * Read-only diagnostic. It changes nothing in the database and nothing on any
 * page. Run it on your Mac AND on the Hostinger server: a site that blocks one
 * may let the other through.
 *
 * WHY IT EXISTS. The writer, the AI check and the weekly source check all
 * depend on includes/source-pages.php being able to download each cited page.
 * When a site refuses (the ATO answered HTTP 403 on 4 November 2026) nothing
 * fails loudly: the AI check just reports "couldn't be read" at the bottom of
 * its result, and the writer is told to say nothing that depends on that page.
 * This script finds those cases in one run, for every source at once, and also
 * tries to find out what would get the blocked ones through.
 *
 * Usage, from the project root:
 *   php scripts/check-source-access.php                  every allowlisted source
 *   php scripts/check-source-access.php --country=AU     only one country's sources
 *   php scripts/check-source-access.php --url=https://...   one address, whether or not it is in the table
 *
 * For each source it tries, in order, stopping at the first that works:
 *   1. THE FETCHER AS IT IS: fetchPageText() from includes/source-pages.php, so
 *      "OK" here means the writer, the AI check and the weekly check can read it.
 *   2. AS PRODUCTION, to show what the site sends back when it refuses (the
 *      Server header and the first words of the refusal page). Diagnosis only.
 *   3. BROWSER HEADERS: the same request with the full set of headers a browser
 *      sends (Accept, Accept-Language, Upgrade-Insecure-Requests, Sec-Fetch-*).
 *   4. BROWSER HEADERS over HTTP/1.1 only.
 * Variants 3 and 4 are only tried for a source the fetcher could not read.
 *
 * If a variant gets every blocked source through, the fix is to send those
 * headers from fetchPageText(). If none does, the site is refusing by something
 * a header can't change (the connection's fingerprint, or this machine's
 * address), and the options are listed at the end.
 *
 * Polite by design: one request at a time, a pause between sources, and no
 * page is kept.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script can only be run from the command line.\n");
}

ini_set('display_errors', 'stderr');
error_reporting(E_ALL);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/source-pages.php';

/** Seconds to wait between sources, to be polite to the sites we read. */
const ACCESS_CHECK_PAUSE_SECONDS = 1;

/** The headers a desktop browser sends when you open a page by typing its address. */
function browserHeaders(): array
{
    return [
        'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
        'Accept-Language: en-AU,en;q=0.9',
        'Upgrade-Insecure-Requests: 1',
        'Sec-Fetch-Dest: document',
        'Sec-Fetch-Mode: navigate',
        'Sec-Fetch-Site: none',
        'Sec-Fetch-User: ?1',
        'Cache-Control: max-age=0',
    ];
}

/**
 * One request, described by what came back.
 *
 * $kind 'production' sends only a user agent, like includes/source-pages.php;
 * 'browser' adds browserHeaders(). $httpVersion is a CURL_HTTP_VERSION_ value or
 * null to let curl choose.
 *
 * Returns status, the curl error text, the Server header, the first words of
 * the body, and how many characters of text the page has once tags are removed.
 */
function probeUrl(string $url, string $kind, ?int $httpVersion = null): array
{
    $serverHeader = '';
    $curl = curl_init($url);
    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_ENCODING       => '',
        CURLOPT_USERAGENT      => $kind === 'browser'
            ? 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36'
            : 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36 (financefactlibrary.com data check)',
        CURLOPT_HEADERFUNCTION => function ($handle, $line) use (&$serverHeader) {
            if (stripos($line, 'server:') === 0) {
                $serverHeader = trim(substr($line, 7));
            }
            return strlen($line);
        },
    ];
    if ($kind === 'browser') {
        $options[CURLOPT_HTTPHEADER] = browserHeaders();
    }
    if ($httpVersion !== null) {
        $options[CURLOPT_HTTP_VERSION] = $httpVersion;
    }
    curl_setopt_array($curl, $options);
    $body = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $error = curl_error($curl);
    curl_close($curl);

    $text = is_string($body) ? trim(preg_replace('/\s+/u', ' ', strip_tags(preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', ' ', $body)))) : '';
    return [
        'status'  => $status,
        'error'   => $error,
        'server'  => $serverHeader,
        'snippet' => substr($text, 0, 90),
        'chars'   => strlen($text),
        // Counts as readable the way fetchPageText() counts it: 200, and not almost empty.
        'readable' => $status === 200 && strlen($text) >= 200,
    ];
}

/** "403" or "200, 4,512 characters" or "no answer (the curl error)". */
function describeProbe(array $p): string
{
    if ($p['status'] === 0) {
        return 'no answer' . ($p['error'] !== '' ? " ({$p['error']})" : '');
    }
    return 'HTTP ' . $p['status'] . ($p['status'] === 200 ? ', ' . number_format($p['chars']) . ' characters of text' : '');
}

// ---------------------------------------------------------------
// Which addresses to test
// ---------------------------------------------------------------

$options = getopt('', ['country:', 'url:']);
$targets = []; // [publisher, url]

if (isset($options['url'])) {
    $targets[] = ['(address given)', (string) $options['url']];
} else {
    $pdo = getDbConnection();
    $sql = 'SELECT s.publisher, s.url FROM sources s LEFT JOIN jurisdictions j ON j.id = s.jurisdiction_id WHERE s.is_allowlisted = 1';
    $params = [];
    if (isset($options['country'])) {
        $sql .= ' AND j.code = :code';
        $params['code'] = strtoupper((string) $options['country']);
    }
    $stmt = $pdo->prepare($sql . ' ORDER BY s.publisher, s.id');
    $stmt->execute($params);
    foreach ($stmt->fetchAll() as $row) {
        if (!in_array($row['url'], SKIP_SOURCE_URLS, true)) {
            $targets[] = [$row['publisher'], $row['url']];
        }
    }
}

if (!$targets) {
    fwrite(STDERR, "No sources to test.\n");
    exit(1);
}

echo 'Testing ' . count($targets) . " source(s) from this machine (" . (gethostname() ?: 'unknown host') . ").\n\n";

// ---------------------------------------------------------------
// Run
// ---------------------------------------------------------------

$readable = 0;
$blocked = [];          // url => the probes that were tried
$variantSuccess = [];   // variant name => how many blocked sources it got through
foreach ($targets as $i => [$publisher, $url]) {
    if ($i > 0) {
        sleep(ACCESS_CHECK_PAUSE_SECONDS);
    }
    $short = strlen($url) > 78 ? substr($url, 0, 75) . '...' : $url;

    // 1. The fetcher exactly as the site uses it.
    try {
        fetchPageText($url);
        $readable++;
        echo sprintf("  OK       %-28s %s\n", substr($publisher, 0, 28), $short);
        continue;
    } catch (Throwable $e) {
        $why = $e->getMessage();
    }

    echo sprintf("  BLOCKED  %-28s %s\n           the fetcher says: %s\n", substr($publisher, 0, 28), $short, trim($why));

    // 2 to 4. What the site sends back, and what would get through.
    $tried = [];
    $variants = [
        'as production (diagnosis)' => ['production', null],
        'browser headers'           => ['browser', null],
        'browser headers, HTTP/1.1' => ['browser', CURL_HTTP_VERSION_1_1],
    ];
    foreach ($variants as $name => [$kind, $version]) {
        $probe = probeUrl($url, $kind, $version);
        $tried[$name] = $probe;
        echo sprintf("           %-27s %s%s%s\n", $name . ':', describeProbe($probe),
            $probe['server'] !== '' ? "  [Server: {$probe['server']}]" : '',
            (!$probe['readable'] && $probe['snippet'] !== '') ? '  "' . $probe['snippet'] . '"' : '');
        if ($probe['readable'] && $kind === 'browser') {
            $variantSuccess[$name] = ($variantSuccess[$name] ?? 0) + 1;
            break; // the first variant that works is enough
        }
    }
    $blocked[$url] = $tried;
}

// ---------------------------------------------------------------
// Verdict
// ---------------------------------------------------------------

$total = count($targets);
echo "\n$readable of $total source(s) can be read as things stand";
echo count($blocked) ? ', ' . count($blocked) . " cannot.\n" : ".\n";

if (!$blocked) {
    echo "Nothing to fix here. Run it on the other machine too (your Mac and the server).\n";
    exit(0);
}

echo "\n";
$worked = array_keys(array_filter($variantSuccess, fn($n) => $n === count($blocked)));
if ($worked) {
    echo "Every blocked source was readable with: {$worked[0]}.\n"
        . "The fix is to send those headers from fetchPageText() in includes/source-pages.php.\n";
} elseif ($variantSuccess) {
    echo "Some blocked sources were readable with browser headers (" . implode(', ', array_map(fn($n, $c) => "$n: $c", array_keys($variantSuccess), $variantSuccess)) . "),\n"
        . "but not all. Look at the lines above to see which still fail.\n";
} else {
    echo "No header change got a blocked source through. The site is refusing something a header can't change:\n"
        . "the fingerprint of the connection itself, or this machine's address. Options, in the order I would try them:\n"
        . "  - run this on the other machine: a server's address is often treated differently from a home connection\n"
        . "  - look for the same figure on another official page that does not refuse (the Server line above shows who refuses)\n"
        . "  - keep a saved copy of the page that a person refreshes (the weekly check then cannot spot a change on its own)\n";
}
exit(1);
