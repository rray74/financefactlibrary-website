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
 *   php scripts/check-source-access.php                      every allowlisted source
 *   php scripts/check-source-access.php --country=AU         only one country's sources
 *   php scripts/check-source-access.php --url=https://...    one address, in the table or not
 *   php scripts/check-source-access.php --url=A --url=B      several addresses (repeat --url)
 *
 * For each source it tries, in order, stopping at the first that works:
 *   1. THE FETCHER AS IT IS: fetchPageText() from includes/source-pages.php, so
 *      "OK" means the writer, the AI check and the weekly check can read it.
 * and, only for a source that failed, these variants (the first four show what
 * the site sends back, then climb from honest to less honest):
 *   a. AS PRODUCTION: only a user agent, as the fetcher sends. Diagnosis only:
 *      it shows the Server header and the first words of the refusal page.
 *   b. BROWSER HEADERS, OUR OWN USER AGENT: the full set of headers a browser
 *      sends (Accept, Accept-Language, Sec-Fetch-*), still saying who we are.
 *   c. the same over HTTP/1.1 only.
 *   d. BROWSER HEADERS, PLAIN BROWSER USER AGENT: also drops the name of this
 *      site and looks like an ordinary Chrome. A judgement call, see below.
 *   e. the same over HTTP/1.1 only.
 *
 * WHY THE ORDER MATTERS. Variants b and c keep the fetcher honest: the site is
 * told who is asking. Variants d and e pretend to be a person with a browser
 * to get past a site's bot protection. That may go against a site's terms of
 * use, so any source that only works at d or e is called out at the end for
 * you to decide, rather than being fixed quietly.
 *
 * If no variant gets a source through, the site is refusing by something a
 * header can't change (a JavaScript challenge, the fingerprint of the
 * connection, or this machine's address) and the options are listed at the end.
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

/** The user agent includes/source-pages.php sends: it says who is asking. */
const OWN_USER_AGENT = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) '
    . 'Chrome/124.0 Safari/537.36 (financefactlibrary.com data check)';

/** An ordinary desktop Chrome, naming nobody. */
const PLAIN_USER_AGENT = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) '
    . 'Chrome/126.0.0.0 Safari/537.36';

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
 * The variants, from the one that changes least to the one that changes most.
 * 'headers' adds browserHeaders(); 'agent' is 'own' or 'plain'; 'http11' forces
 * HTTP/1.1; 'diagnosis' marks the one that is only there to show the refusal;
 * 'plain' marks the ones that stop saying who we are.
 */
const PROBE_VARIANTS = [
    'as production (diagnosis)'             => ['headers' => false, 'agent' => 'own',   'http11' => false, 'diagnosis' => true,  'plain' => false],
    'browser headers, our user agent'       => ['headers' => true,  'agent' => 'own',   'http11' => false, 'diagnosis' => false, 'plain' => false],
    'browser headers, our agent, HTTP/1.1'  => ['headers' => true,  'agent' => 'own',   'http11' => true,  'diagnosis' => false, 'plain' => false],
    'browser headers, plain browser agent'  => ['headers' => true,  'agent' => 'plain', 'http11' => false, 'diagnosis' => false, 'plain' => true],
    'plain browser agent, HTTP/1.1'         => ['headers' => true,  'agent' => 'plain', 'http11' => true,  'diagnosis' => false, 'plain' => true],
];

/**
 * One request, described by what came back: status, curl's error text, the
 * Server header, the first words of the body, and how many characters of text
 * the page has once tags are removed.
 */
function probeUrl(string $url, array $variant): array
{
    $serverHeader = '';
    $curl = curl_init($url);
    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_ENCODING       => '',
        CURLOPT_USERAGENT      => $variant['agent'] === 'plain' ? PLAIN_USER_AGENT : OWN_USER_AGENT,
        CURLOPT_HEADERFUNCTION => function ($handle, $line) use (&$serverHeader) {
            if (stripos($line, 'server:') === 0) {
                $serverHeader = trim(substr($line, 7));
            }
            return strlen($line);
        },
    ];
    if ($variant['headers']) {
        $options[CURLOPT_HTTPHEADER] = browserHeaders();
    }
    if ($variant['http11']) {
        $options[CURLOPT_HTTP_VERSION] = CURL_HTTP_VERSION_1_1;
    }
    curl_setopt_array($curl, $options);
    $body = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $error = curl_error($curl);
    curl_close($curl);

    $text = is_string($body)
        ? trim(preg_replace('/\s+/u', ' ', strip_tags(preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', ' ', $body))))
        : '';
    return [
        'status'   => $status,
        'error'    => $error,
        'server'   => $serverHeader,
        'snippet'  => substr($text, 0, 90),
        'chars'    => strlen($text),
        // Counts as readable the way fetchPageText() counts it: 200, and not almost empty.
        'readable' => $status === 200 && strlen($text) >= 200,
    ];
}

/** "HTTP 403" or "HTTP 200, 4,512 characters of text" or "no answer (the curl error)". */
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
    // getopt() gives a string for one --url and an array for several.
    foreach ((array) $options['url'] as $given) {
        $targets[] = ['(address given)', (string) $given];
    }
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

echo 'Testing ' . count($targets) . ' source(s) from this machine (' . (gethostname() ?: 'unknown host') . ").\n\n";

// ---------------------------------------------------------------
// Run
// ---------------------------------------------------------------

$readable = 0;
$blocked = [];        // url => the name of the first variant that worked, or null
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

    // Then the variants, least change first. Stop at the first that works.
    $worked = null;
    foreach (PROBE_VARIANTS as $name => $variant) {
        $probe = probeUrl($url, $variant);
        echo sprintf("           %-38s %s%s%s\n", $name . ':', describeProbe($probe),
            $probe['server'] !== '' ? "  [Server: {$probe['server']}]" : '',
            (!$probe['readable'] && $probe['snippet'] !== '') ? '  "' . $probe['snippet'] . '"' : '');
        if ($probe['readable'] && !$variant['diagnosis']) {
            $worked = $name;
            break;
        }
    }
    $blocked[$url] = $worked;
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

$fixedHonestly = array_filter($blocked, fn($name) => $name !== null && !PROBE_VARIANTS[$name]['plain']);
$fixedByPretending = array_filter($blocked, fn($name) => $name !== null && PROBE_VARIANTS[$name]['plain']);
$stillBlocked = array_filter($blocked, fn($name) => $name === null);

echo "\n";
if ($fixedHonestly) {
    $names = array_count_values($fixedHonestly);
    echo count($fixedHonestly) . ' blocked source(s) are readable while still saying who we are (the fetcher need only send browser headers):' . "\n";
    foreach ($names as $name => $count) {
        echo "  $count with: $name\n";
    }
}
if ($fixedByPretending) {
    echo "\n" . count($fixedByPretending) . ' source(s) were readable ONLY when the request also looked like an ordinary browser and no longer named this site:' . "\n";
    foreach (array_keys($fixedByPretending) as $url) {
        echo "  $url\n";
    }
    echo "That is getting past the site's bot protection by not saying who is asking. Check the site's terms of use before\n"
        . "doing it, or use another page for the figure. It is your decision, so nothing is changed for these.\n";
}
if ($stillBlocked) {
    echo "\n" . count($stillBlocked) . " source(s) were not readable with any variant. The site is refusing something a header can't change:\n"
        . "a JavaScript challenge, the fingerprint of the connection itself, or this machine's address. The Server line above\n"
        . "shows who refuses. Options, in the order I would try them:\n"
        . "  - run this on the other machine: a server's address is often treated differently from a home connection\n"
        . "  - look for the same figure on another official page that does not refuse\n"
        . "  - keep a saved copy of the page that a person refreshes (the weekly check then cannot spot a change on its own)\n";
    foreach (array_keys($stillBlocked) as $url) {
        echo "  not readable: $url\n";
    }
}
exit(1);
