<?php
/**
 * Google Search Console, read-only (Phase 2).
 *
 * Logs in as the Google Cloud service account and reads search
 * performance (clicks, impressions, average position, search queries)
 * for the site. Used by scripts/fetch-search-console.php, which stores
 * the results for the admin dashboard and editor.
 *
 * Setup (done once, outside the code):
 *   - a service account with the Search Console API enabled in its
 *     Google Cloud project, added as a Restricted user in Search Console
 *   - its JSON key saved as config/search-console-key.json on each
 *     machine (gitignored, like database.local.php)
 *
 * Since the October 2026 rename, the service account is
 * finance-fact-library-dashboard@finance-fact-library.iam.gserviceaccount.com,
 * in the finance-fact-library Google Cloud project.
 *
 * Both settings below have defaults that match that setup, and either
 * can be overridden in config/database.local.php.
 */

/**
 * The Search Console property. Domain properties are written sc-domain:...
 *
 * Switched from sc-domain:financial-facts.com to the new domain after the
 * October 2026 rename. Data from before the move stays in the old
 * property; the new one only has data from its verification onwards.
 */
if (!defined('GSC_SITE_URL')) {
    define('GSC_SITE_URL', 'sc-domain:financefactlibrary.com');
}

/** Where the service account's JSON key is kept. */
if (!defined('GSC_KEY_FILE')) {
    define('GSC_KEY_FILE', __DIR__ . '/../config/search-console-key.json');
}

/** True if the key file is there and PHP can sign the login request. */
function searchConsoleAvailable(): bool
{
    return is_readable(GSC_KEY_FILE) && function_exists('openssl_sign') && function_exists('curl_init');
}

/**
 * Get a one-hour access token for the service account.
 *
 * Google's service-account login: build a small signed statement (a
 * "JWT") saying who we are and that we want read-only Search Console
 * access, sign it with the private key from the JSON file, and swap it
 * for an access token. No Google library is needed for this.
 */
function searchConsoleAccessToken(): string
{
    $key = json_decode((string) file_get_contents(GSC_KEY_FILE), true);
    if (!is_array($key) || empty($key['client_email']) || empty($key['private_key'])) {
        throw new RuntimeException('The Search Console key file is missing or not a service account JSON key.');
    }

    $tokenUrl = $key['token_uri'] ?? 'https://oauth2.googleapis.com/token';
    $now = time();

    // Base64 in the URL-safe form JWTs use, without = padding.
    $encode = fn(string $data): string => rtrim(strtr(base64_encode($data), '+/', '-_'), '=');

    $header = $encode(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
    $claims = $encode(json_encode([
        'iss'   => $key['client_email'],
        'scope' => 'https://www.googleapis.com/auth/webmasters.readonly', // read-only
        'aud'   => $tokenUrl,
        'iat'   => $now,
        'exp'   => $now + 3600,
    ]));

    $signature = '';
    if (!openssl_sign("$header.$claims", $signature, $key['private_key'], OPENSSL_ALGO_SHA256)) {
        throw new RuntimeException('Could not sign the Search Console login with the key file.');
    }
    $jwt = "$header.$claims." . $encode($signature);

    $response = searchConsoleHttp($tokenUrl, http_build_query([
        'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
        'assertion'  => $jwt,
    ]), ['content-type: application/x-www-form-urlencoded']);

    if (empty($response['access_token'])) {
        throw new RuntimeException('Google did not return an access token.');
    }
    return $response['access_token'];
}

/**
 * Run one Search Analytics query and return its rows. Each row has
 * 'keys' (the dimension values, in the order asked for), 'clicks',
 * 'impressions', 'ctr' and 'position'.
 *
 * $request is the query body, e.g. ['startDate' => '2026-09-01',
 * 'endDate' => '2026-09-28', 'dimensions' => ['page'], 'rowLimit' => 1000].
 */
function searchConsoleQuery(string $accessToken, array $request): array
{
    $url = 'https://www.googleapis.com/webmasters/v3/sites/' . rawurlencode(GSC_SITE_URL) . '/searchAnalytics/query';
    $response = searchConsoleHttp($url, json_encode($request), [
        'content-type: application/json',
        'authorization: Bearer ' . $accessToken,
    ]);
    return $response['rows'] ?? [];
}

/**
 * POST to a Google endpoint and return the decoded JSON. Throws with
 * Google's own explanation on any error (e.g. "User does not have
 * sufficient permission", which means the service account hasn't been
 * added in Search Console).
 */
function searchConsoleHttp(string $url, string $body, array $headers): array
{
    $curl = curl_init($url);
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 60,
    ]);
    $raw = curl_exec($curl);
    $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $curlError = curl_error($curl);
    curl_close($curl);

    if ($raw === false) {
        throw new RuntimeException("Request to Google failed: $curlError");
    }
    $data = json_decode($raw, true);
    if ($status !== 200) {
        $message = $data['error']['message'] ?? $data['error_description'] ?? $data['error'] ?? substr((string) $raw, 0, 200);
        throw new RuntimeException("Google returned HTTP $status: " . (is_string($message) ? $message : json_encode($message)));
    }
    return is_array($data) ? $data : [];
}

/**
 * Turn a full address from Search Console into the site path used
 * everywhere else, e.g. https://financefactlibrary.com/uk/tax/ -> /uk/tax/
 */
function searchConsolePath(string $url): string
{
    $path = parse_url($url, PHP_URL_PATH);
    return ($path === null || $path === '') ? '/' : $path;
}
