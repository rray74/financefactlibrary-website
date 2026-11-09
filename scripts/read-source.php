<?php
/**
 * Read a source page the way the site reads it. Read-only.
 *
 * WHY IT EXISTS. A figure must be checked against the live official page before
 * it goes into a CSV. But a page can be read from a stale copy: on 5 November
 * 2026 a copy of the Services Australia Age Pension page showed the rates from a
 * year earlier, with no date on it. Nothing looked wrong, and the old figures were
 * the ones that would have been entered. This script reads the page from YOUR
 * machine with the same code the writer, the AI check and the weekly check use
 * (fetchPageText() in includes/source-pages.php), so what you see is exactly what
 * the site would verify a figure against. Paste its output into the chat and the
 * figure can be checked against it.
 *
 * Usage, from the project root:
 *   php scripts/read-source.php --url=https://...                    the start of the page
 *   php scripts/read-source.php --url=https://... --find="Maximum basic rate"
 *                                                  only the words around each match
 *   php scripts/read-source.php --url=... --find=one --find=two      several searches
 *   php scripts/read-source.php --url=... --chars=6000               more of the page
 *   php scripts/read-source.php --url=... --around=400               more words around a match
 *
 * Numbers are shown the way the checker sees them: thousands separators are
 * removed, so $1,237.70 reads $1237.70. The page is not saved anywhere.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script can only be run from the command line.\n");
}

ini_set('display_errors', 'stderr');
error_reporting(E_ALL);

require_once __DIR__ . '/../includes/source-pages.php';

$options = getopt('', ['url:', 'find:', 'chars:', 'around:']);
if (empty($options['url']) || is_array($options['url'])) {
    fwrite(STDERR, "Give one address: php scripts/read-source.php --url=https://...\n"
        . "Add --find=\"words\" to see only the text around those words.\n");
    exit(1);
}

$url = (string) $options['url'];
$finds = array_values(array_filter((array) ($options['find'] ?? []), fn($f) => trim((string) $f) !== ''));
$chars = isset($options['chars']) ? max(200, (int) $options['chars']) : 3000;
$around = isset($options['around']) ? max(40, (int) $options['around']) : 220;

try {
    $text = fetchPageText($url);
} catch (Throwable $e) {
    fwrite(STDERR, "Could not read the page: " . $e->getMessage() . "\n"
        . "(Run scripts/check-source-access.php to see whether the site refuses this machine.)\n");
    exit(1);
}

echo 'Read ' . number_format(strlen($text)) . ' characters from ' . $url . ' on ' . date('j F Y, H:i') . ' (' . (gethostname() ?: 'this machine') . ").\n\n";

if (!$finds) {
    echo substr($text, 0, $chars) . (strlen($text) > $chars ? "\n\n[...cut here. Use --chars=N for more, or --find=\"words\" to search.]" : '') . "\n";
    exit(0);
}

foreach ($finds as $find) {
    // Case-insensitive, and tolerant of the way the checker normalises the page: a
    // thousands separator typed in the search is removed, as it is in the text.
    $needle = preg_replace('/(\d),(\d{3})(?!\d)/', '$1$2', (string) $find);
    echo '=== "' . $find . "\" ===\n";
    $count = 0;
    $offset = 0;
    while (($pos = stripos($text, $needle, $offset)) !== false && $count < 6) {
        $start = max(0, $pos - $around);
        $slice = substr($text, $start, strlen($needle) + 2 * $around);
        echo ($start > 0 ? '...' : '') . trim($slice) . "...\n\n";
        $offset = $pos + strlen($needle) + $around; // skip past this stretch, so matches don't repeat
        $count++;
    }
    if ($count === 0) {
        echo "(not found on the page)\n\n";
    }
}
exit(0);
