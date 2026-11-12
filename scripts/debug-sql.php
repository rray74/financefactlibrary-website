<?php
/**
 * Run another script, and if one of its database statements FAILS, print that statement.
 *
 * WHY IT EXISTS. A database error such as
 *     SQLSTATE[HY000]: General error: 1267 Illegal mix of collations ...
 * says what went wrong but not WHICH statement did it, and the scripts catch the error and
 * print only its message. When the first Australian CSV failed on the server on 12 November
 * 2026, the message alone could not say where to look. This wrapper adds the missing piece.
 * The script runs exactly as it normally would. The only difference is that a failing
 * statement is printed (to the error output) just before the same error is raised.
 *
 * Usage, from the project root, naming the script and then its own arguments as usual:
 *   php scripts/debug-sql.php scripts/import-facts.php data/imports/au-01-tax-free-threshold.csv
 *   php scripts/debug-sql.php scripts/import-page-text.php --dry-run data/imports/page-text-AU-2026-11-11.csv
 * Add --all before the script name to print EVERY statement it runs, not only the failing one:
 *   php scripts/debug-sql.php --all scripts/import-facts.php data/imports/x.csv
 *
 * HOW IT SEES FAILURES. A statement can fail at two moments: when the database parses it
 * (prepare), which is where a collation conflict shows up, or when it runs (execute), which is
 * where "data too long" and duplicate keys show up. PHP raises both as exceptions, and the
 * statement text is not in the exception. So this wrapper switches the connection to report
 * database errors as warnings, catches each warning, looks up the statement that caused it,
 * prints it, and raises the same exception the script would have got.
 *
 * Statements run directly with exec() or query() (not prepare) are not logged by --all, though
 * a failure in one is still reported. Do not paste its output anywhere public: it shows the
 * values being written.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script can only be run from the command line.\n");
}

ini_set('display_errors', 'stderr');
error_reporting(E_ALL);

require_once __DIR__ . '/../config/database.php';

/** Set from --all: print every prepared statement as it runs. */
$GLOBALS['debugSqlLogAll'] = false;

/**
 * The statement object the connection hands out. It behaves exactly like a normal PDOStatement.
 * It only adds the --all logging; failures are reported by the error handler below.
 */
class SqlLoggingStatement extends PDOStatement
{
    protected function __construct()
    {
        // PDO builds these itself; nothing to set up.
    }

    // #[ReturnTypeWillChange] keeps this valid on PHP 8.1 and later. On older PHP it is only a comment.
    #[\ReturnTypeWillChange]
    public function execute($params = null)
    {
        if (!empty($GLOBALS['debugSqlLogAll'])) {
            fwrite(STDERR, 'SQL: ' . preg_replace('/\s+/', ' ', trim($this->queryString)) . "\n     values: "
                . json_encode($params, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR) . "\n");
        }
        return parent::execute($params);
    }
}

// ---------------------------------------------------------------
// Arguments: [--all] script [the script's own arguments]
// ---------------------------------------------------------------

$args = array_slice($argv, 1);
if ($args && $args[0] === '--all') {
    $GLOBALS['debugSqlLogAll'] = true;
    array_shift($args);
}
if (!$args) {
    fwrite(STDERR, "Usage: php scripts/debug-sql.php [--all] scripts/the-script.php [its arguments]\n");
    exit(1);
}
$script = array_shift($args);
if (!is_file($script)) {
    fwrite(STDERR, "No such script: $script\n");
    exit(1);
}

// ---------------------------------------------------------------
// Report database errors with the statement that caused them
// ---------------------------------------------------------------

// The connection is created once and shared (getDbConnection() keeps it), so these settings apply to
// the target script's own queries as well.
$pdo = getDbConnection();
$pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, ['SqlLoggingStatement']);
// In warning mode a failed statement raises a PHP warning instead of an exception. The handler below
// prints the statement and then throws the exception the script expects, so its own error handling,
// and what it prints, are the same as in a normal run.
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_WARNING);

set_error_handler(function (int $errno, string $errstr) {
    // Only database warnings, which read like "PDO::prepare(): SQLSTATE[HY000]: ...".
    if (!preg_match('/^PDO(?:Statement)?::\w+\(\): (SQLSTATE\[.*)$/s', $errstr, $m)) {
        return false; // anything else is left to PHP as usual
    }

    // Find the statement: the argument of prepare()/query()/exec(), or the statement object that was executing.
    $sql = null;
    $values = null;
    foreach (debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT, 10) as $frame) {
        if (isset($frame['object']) && $frame['object'] instanceof PDOStatement) {
            $sql = $frame['object']->queryString;
            $values = $frame['args'][0] ?? null;
            break;
        }
        if (in_array($frame['function'] ?? '', ['prepare', 'query', 'exec'], true) && isset($frame['args'][0]) && is_string($frame['args'][0])) {
            $sql = $frame['args'][0];
            break;
        }
    }
    $when = ($sql !== null && isset($frame['function']) && $frame['function'] === 'prepare') ? 'when the statement was prepared (parsed)' : 'when the statement ran';
    fwrite(STDERR, "\n--- SQL that failed ($when) ---\n"
        . ($sql !== null ? preg_replace('/\s+/', ' ', trim($sql)) : '(could not find the statement)')
        . ($values !== null ? "\nvalues: " . json_encode($values, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR) : '')
        . "\nerror: " . $m[1] . "\n-----------------------\n");

    throw new PDOException($m[1]);
});

fwrite(STDERR, 'debug-sql: running ' . $script . ($GLOBALS['debugSqlLogAll'] ? ' (every statement will be shown)' : ' (a failing statement will be shown)') . "\n");

// Hand the target script its own arguments, as if it had been started directly.
$GLOBALS['argv'] = array_merge([$script], $args);
$GLOBALS['argc'] = count($GLOBALS['argv']);
require $script;
