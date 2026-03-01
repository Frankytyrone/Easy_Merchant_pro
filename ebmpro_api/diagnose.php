<?php
/* ============================================================
   EBM Pro — Self-Diagnosis / System Health Endpoint
   Requires ?token= parameter matching DIAGNOSE_TOKEN from config.php.
   Does NOT expose passwords or secret keys in output.
   ============================================================ */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Token check — extract DIAGNOSE_TOKEN from config.php via regex (safe, no eval/include)
$_configSrc      = file_exists(__DIR__ . '/config.php') ? file_get_contents(__DIR__ . '/config.php') : '';
$_expectedToken  = '';
if (preg_match("/define\s*\(\s*'DIAGNOSE_TOKEN'\s*,\s*['\"]([^'\"]+)['\"]\s*\)/", $_configSrc, $_m)) {
    $_expectedToken = $_m[1];
}
$_providedToken = $_GET['token'] ?? '';
if ($_expectedToken === '' || $_providedToken === ''
    || !hash_equals($_expectedToken, $_providedToken)) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}
unset($_configSrc, $_expectedToken, $_providedToken, $_m);

$checks  = [];
$errors  = 0;
$warnings = 0;

// Placeholder strings that indicate config.php has not been updated
const PLACEHOLDER_DB_NAME = 'DB_NAME_HERE';
const PLACEHOLDER_DB_USER = 'DB_USER_HERE';

/* ── Helper: add a check result ─────────────────────────── */
function addCheck(string $category, string $name, string $status,
                  string $message, ?string $fix = null): void {
    global $checks, $errors, $warnings;
    $checks[] = [
        'category' => $category,
        'name'     => $name,
        'status'   => $status,
        'message'  => $message,
        'fix'      => $fix,
    ];
    if ($status === 'ERROR')   $errors++;
    if ($status === 'WARNING') $warnings++;
}

/* ══════════════════════════════════════════════════════════
   1. FILE / CONFIG CHECKS
   ══════════════════════════════════════════════════════════ */

$apiDir    = __DIR__;
$configFile = $apiDir . '/config.php';
$commonFile = $apiDir . '/common.php';

if (file_exists($configFile)) {
    addCheck('Files', 'config.php', 'OK', 'config.php found');
} else {
    addCheck('Files', 'config.php', 'ERROR',
        'config.php is missing from the ebmpro_api folder.',
        'Copy ebmpro_api/config.php from the repository and fill in your database details.');
}

if (file_exists($commonFile)) {
    addCheck('Files', 'common.php', 'OK', 'common.php found');
} else {
    addCheck('Files', 'common.php', 'ERROR',
        'common.php is missing from the ebmpro_api folder.',
        'Re-download the application files and place common.php in the ebmpro_api folder.');
}

/* ── Read config constants without executing the full DB connection ── */
$dbName     = null;
$dbUser     = null;
$jwtSecret  = null;

if (file_exists($configFile)) {
    $configSrc = file_get_contents($configFile);

    // Extract define() values via regex (safe — no eval)
    if (preg_match("/define\s*\(\s*'DB_NAME'\s*,\s*'([^']+)'\s*\)/", $configSrc, $m)) {
        $dbName = $m[1];
    }
    if (preg_match("/define\s*\(\s*'DB_USER'\s*,\s*'([^']+)'\s*\)/", $configSrc, $m)) {
        $dbUser = $m[1];
    }
    if (preg_match("/define\s*\(\s*'JWT_SECRET'\s*,\s*'([^']+)'\s*\)/", $configSrc, $m)) {
        $jwtSecret = $m[1];
    }

    if ($dbName === PLACEHOLDER_DB_NAME) {
        addCheck('Config', 'Database name', 'ERROR',
            "DB_NAME still says '" . PLACEHOLDER_DB_NAME . "' — the database has not been configured.",
            "Open ebmpro_api/config.php and change " . PLACEHOLDER_DB_NAME . " to your actual database name (e.g. 'ebmpro_local' for XAMPP).");
    } else {
        addCheck('Config', 'Database name', 'OK',
            "DB_NAME is set to '" . htmlspecialchars($dbName ?? '', ENT_QUOTES) . "'");
    }

    if ($dbUser === PLACEHOLDER_DB_USER) {
        addCheck('Config', 'Database user', 'ERROR',
            "DB_USER still says '" . PLACEHOLDER_DB_USER . "' — the database user has not been configured.",
            "Open ebmpro_api/config.php and change " . PLACEHOLDER_DB_USER . " to your database username (e.g. 'root' for XAMPP).");
    } else {
        addCheck('Config', 'Database user', 'OK',
            "DB_USER is configured");
    }

    if (empty($jwtSecret)) {
        addCheck('Config', 'JWT secret', 'ERROR',
            'JWT_SECRET is empty — login tokens cannot be created.',
            "Open ebmpro_api/config.php and set JWT_SECRET to a long random string.");
    } else {
        addCheck('Config', 'JWT secret', 'OK', 'JWT_SECRET is set');
    }
} else {
    // File missing — placeholders already added above
    addCheck('Config', 'Database name', 'ERROR',
        'Cannot check — config.php is missing.', null);
    addCheck('Config', 'Database user', 'ERROR',
        'Cannot check — config.php is missing.', null);
    addCheck('Config', 'JWT secret', 'ERROR',
        'Cannot check — config.php is missing.', null);
}

/* ══════════════════════════════════════════════════════════
   2. PHP CHECKS
   ══════════════════════════════════════════════════════════ */

$phpVersion = PHP_VERSION;
$phpMajor   = (int) PHP_MAJOR_VERSION;
$phpMinor   = (int) PHP_MINOR_VERSION;

if ($phpMajor < 7) {
    addCheck('PHP', 'PHP version', 'ERROR',
        "PHP {$phpVersion} is too old — the app requires PHP 7.4 or newer.",
        'Update PHP to version 7.4 or newer in XAMPP Control Panel (or ask your host).');
} elseif ($phpMajor === 7 && $phpMinor < 4) {
    addCheck('PHP', 'PHP version', 'WARNING',
        "PHP {$phpVersion} is below the recommended minimum of 7.4.",
        'Update PHP to 7.4 or newer in XAMPP Control Panel for best compatibility.');
} else {
    addCheck('PHP', 'PHP version', 'OK', "PHP {$phpVersion}");
}

if (extension_loaded('pdo')) {
    addCheck('PHP', 'PDO extension', 'OK', 'PDO is enabled');
} else {
    addCheck('PHP', 'PDO extension', 'ERROR',
        'PDO is not enabled — the app cannot talk to the database.',
        'Enable the php_pdo extension in php.ini (XAMPP: open php.ini and uncomment extension=pdo).');
}

if (extension_loaded('pdo_mysql')) {
    addCheck('PHP', 'PDO MySQL driver', 'OK', 'PDO MySQL driver is enabled');
} else {
    addCheck('PHP', 'PDO MySQL driver', 'ERROR',
        'PDO MySQL driver is not enabled — MySQL connections will fail.',
        'Enable php_pdo_mysql in php.ini (XAMPP: uncomment extension=pdo_mysql and restart Apache).');
}

if (class_exists('ZipArchive')) {
    addCheck('PHP', 'ZipArchive (backup)', 'OK', 'ZipArchive is available');
} else {
    addCheck('PHP', 'ZipArchive (backup)', 'WARNING',
        'ZipArchive is not available — the backup/export feature will not work.',
        'Enable php_zip in php.ini (XAMPP: uncomment extension=zip and restart Apache).');
}

if (function_exists('json_encode')) {
    addCheck('PHP', 'JSON support', 'OK', 'json_encode is available');
} else {
    addCheck('PHP', 'JSON support', 'ERROR',
        'JSON support is missing — the API cannot return data.',
        'This is very unusual. Try reinstalling PHP.');
}

// INI values
$uploadMax  = ini_get('upload_max_filesize');
$postMax    = ini_get('post_max_size');
$memLimit   = ini_get('memory_limit');
$maxExecTime = ini_get('max_execution_time');

function parseBytes(string $val): int {
    $val  = trim($val);
    $unit = strtolower(substr($val, -1));
    $num  = (int) $val;
    switch ($unit) {
        case 'g': return $num * 1073741824;
        case 'm': return $num * 1048576;
        case 'k': return $num * 1024;
        default:  return $num;
    }
}

$memBytes = parseBytes($memLimit);
if ($memLimit === '-1') {
    addCheck('PHP', 'memory_limit', 'OK', 'memory_limit is unlimited');
} elseif ($memBytes < 128 * 1048576) {
    addCheck('PHP', 'memory_limit', 'WARNING',
        "memory_limit is only {$memLimit} — this may cause problems with large imports.",
        'Set memory_limit = 512M in php.ini (or in ebmpro_api/.htaccess: php_value memory_limit 512M).');
} else {
    addCheck('PHP', 'memory_limit', 'OK', "memory_limit = {$memLimit}");
}

$uploadBytes = parseBytes($uploadMax);
if ($uploadBytes < 8 * 1048576) {
    addCheck('PHP', 'upload_max_filesize', 'WARNING',
        "upload_max_filesize is only {$uploadMax} — large file imports may fail.",
        'Set upload_max_filesize = 64M in php.ini or in ebmpro_api/.htaccess.');
} else {
    addCheck('PHP', 'upload_max_filesize', 'OK', "upload_max_filesize = {$uploadMax}");
}

addCheck('PHP', 'post_max_size', 'OK', "post_max_size = {$postMax}");
addCheck('PHP', 'max_execution_time', 'OK', "max_execution_time = {$maxExecTime}s");

/* ══════════════════════════════════════════════════════════
   3. AUTHORIZATION HEADER CHECK (XAMPP bug)
   ══════════════════════════════════════════════════════════ */

$testToken   = 'DiagnoseTestToken';
$authSeen    = false;
$authSource  = '';

// Simulate what happens when a real Authorization header is sent
// We can only check what the server passed through for THIS request
if (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
    $authSeen   = true;
    $authSource = '$_SERVER[HTTP_AUTHORIZATION]';
} elseif (!empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
    $authSeen   = true;
    $authSource = '$_SERVER[REDIRECT_HTTP_AUTHORIZATION]';
} elseif (function_exists('apache_request_headers')) {
    $reqHeaders = apache_request_headers();
    $reqHeaders = array_change_key_case($reqHeaders, CASE_LOWER);
    if (!empty($reqHeaders['authorization'])) {
        $authSeen   = true;
        $authSource = 'apache_request_headers()';
    }
}

// Check if the .htaccess RewriteRule fix is present
$htaccessFile = $apiDir . '/.htaccess';
$htaccessHasFix = false;
if (file_exists($htaccessFile)) {
    $htContent = file_get_contents($htaccessFile);
    // Look for the specific RewriteRule pattern that passes the Authorization header
    if (preg_match('/RewriteRule\s+\.\*\s+-\s+\[.*?HTTP_AUTHORIZATION/i', $htContent)) {
        $htaccessHasFix = true;
    }
}

if ($authSeen) {
    addCheck('Authorization', 'Login token (Authorization header)', 'OK',
        "PHP can see the Authorization header (via {$authSource}) — login should work correctly.");
} elseif ($htaccessHasFix) {
    addCheck('Authorization', 'Login token (Authorization header)', 'OK',
        'The .htaccess RewriteRule fix is present — the Authorization header should be forwarded correctly.');
} else {
    addCheck('Authorization', 'Login token (Authorization header)', 'ERROR',
        'XAMPP appears to be blocking the login token — this is the most common reason why nothing saves after login.',
        "Add these two lines to your ebmpro_api/.htaccess file:\n  RewriteEngine On\n  RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]");
}

/* ══════════════════════════════════════════════════════════
   4. DATABASE CHECKS
   ══════════════════════════════════════════════════════════ */

$requiredTables = [
    'users', 'products', 'customers', 'invoices', 'invoice_items',
    'invoice_sequences', 'payments', 'settings', 'stores', 'audit_log',
    'login_attempts', 'sync_queue', 'tbl_stock', 'email_log',
];

$pdo      = null;
$dbOk     = false;
$dbError  = '';

// Only attempt connection if config values look valid
$canConnect = ($dbName !== null && $dbName !== 'DB_NAME_HERE'
            && $dbUser !== null && $dbUser !== 'DB_USER_HERE');

if ($canConnect && file_exists($configFile)) {
    // Extract DB_PASS and DB_HOST safely
    $configSrc2 = file_get_contents($configFile);
    $dbPass = '';
    $dbHost = 'localhost';
    if (preg_match("/define\s*\(\s*'DB_PASS'\s*,\s*'([^']*)'\s*\)/", $configSrc2, $m)) {
        $dbPass = $m[1];
    }
    if (preg_match("/define\s*\(\s*'DB_HOST'\s*,\s*'([^']+)'\s*\)/", $configSrc2, $m)) {
        $dbHost = $m[1];
    }

    try {
        $pdo = new PDO(
            "mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4",
            $dbUser,
            $dbPass,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
             PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );
        $dbOk = true;
        addCheck('Database', 'Database connection', 'OK',
            "Connected successfully to '{$dbName}'");
    } catch (PDOException $e) {
        // Use only the SQLSTATE code — do not expose the full message which may contain credentials
        $sqlState = $e->getCode();
        $safeMsg  = "Connection failed (SQLSTATE {$sqlState})";
        addCheck('Database', 'Database connection', 'ERROR',
            "Cannot connect to the database '{$dbName}': {$safeMsg}",
            "Check your ebmpro_api/config.php — make sure DB_NAME, DB_USER and DB_PASS are all set correctly. For XAMPP: DB_NAME='ebmpro_local', DB_USER='root', DB_PASS=''.");
    }
} elseif (!$canConnect) {
    addCheck('Database', 'Database connection', 'ERROR',
        'Cannot try to connect — config.php has not been set up yet.',
        "Open ebmpro_api/config.php and fill in DB_NAME, DB_USER and DB_PASS with your database details.");
}

if ($dbOk && $pdo) {
    // Table presence check
    try {
        $stmt = $pdo->query("SHOW TABLES");
        $existing = array_column($stmt->fetchAll(PDO::FETCH_NUM), 0);
        $missing  = array_diff($requiredTables, $existing);

        if (empty($missing)) {
            addCheck('Database', 'Required tables', 'OK',
                count($requiredTables) . ' required tables found');
        } else {
            $missingList = implode(', ', $missing);
            addCheck('Database', 'Required tables', 'ERROR',
                "Missing tables: {$missingList}",
                "Re-import install/schema.sql in phpMyAdmin (go to your database → Import → choose install/schema.sql).");
        }
    } catch (PDOException $e) {
        addCheck('Database', 'Required tables', 'ERROR',
            'Could not list tables: ' . $e->getMessage(), null);
    }

    // Admin user check
    try {
        if (in_array('users', $existing ?? [])) {
            $row = $pdo->query("SELECT COUNT(*) as cnt FROM users WHERE role='admin'")->fetch();
            $adminCount = (int)($row['cnt'] ?? 0);
            if ($adminCount > 0) {
                addCheck('Database', 'Admin user', 'OK',
                    "{$adminCount} admin user(s) found in the users table");
            } else {
                addCheck('Database', 'Admin user', 'ERROR',
                    'No admin user found in the database — you cannot log in.',
                    "Re-import install/schema.sql in phpMyAdmin to restore the default admin user.");
            }
        }
    } catch (PDOException $e) {
        addCheck('Database', 'Admin user', 'WARNING',
            'Could not check admin users: ' . $e->getMessage(), null);
    }

    // Stores check
    try {
        if (in_array('stores', $existing ?? [])) {
            $row = $pdo->query("SELECT COUNT(*) as cnt FROM stores")->fetch();
            $storeCount = (int)($row['cnt'] ?? 0);
            if ($storeCount >= 2) {
                addCheck('Database', 'Stores (FAL & GWE)', 'OK',
                    "{$storeCount} store(s) found");
            } elseif ($storeCount > 0) {
                addCheck('Database', 'Stores (FAL & GWE)', 'WARNING',
                    "Only {$storeCount} store found — both FAL and GWE are expected.",
                    "Re-import install/schema.sql to restore the default store records.");
            } else {
                addCheck('Database', 'Stores (FAL & GWE)', 'ERROR',
                    'No stores found in the database.',
                    "Re-import install/schema.sql in phpMyAdmin to restore the store records.");
            }
        }
    } catch (PDOException $e) {
        addCheck('Database', 'Stores (FAL & GWE)', 'WARNING',
            'Could not check stores: ' . $e->getMessage(), null);
    }

    // Invoice sequences check
    try {
        if (in_array('invoice_sequences', $existing ?? [])) {
            $row = $pdo->query("SELECT COUNT(*) as cnt FROM invoice_sequences")->fetch();
            $seqCount = (int)($row['cnt'] ?? 0);
            if ($seqCount > 0) {
                addCheck('Database', 'Invoice sequences', 'OK',
                    "{$seqCount} invoice sequence row(s) found");
            } else {
                addCheck('Database', 'Invoice sequences', 'WARNING',
                    'No rows in invoice_sequences — invoice numbering may not work.',
                    "Re-import install/schema.sql in phpMyAdmin to restore sequence data.");
            }
        }
    } catch (PDOException $e) {
        addCheck('Database', 'Invoice sequences', 'WARNING',
            'Could not check invoice_sequences: ' . $e->getMessage(), null);
    }
} elseif ($canConnect && !$dbOk) {
    // DB connection failed — skip table checks but note them
    foreach (['Required tables', 'Admin user', 'Stores (FAL & GWE)', 'Invoice sequences'] as $name) {
        addCheck('Database', $name, 'ERROR',
            'Skipped — database connection failed.', null);
    }
}

/* ══════════════════════════════════════════════════════════
   5. BUILD RESPONSE
   ══════════════════════════════════════════════════════════ */

if ($errors > 0) {
    $overall = 'ERROR';
    $summary = "{$errors} problem(s) found" . ($warnings > 0 ? " and {$warnings} warning(s)" : '');
    $summary .= ' — please fix the errors above before using the app';
} elseif ($warnings > 0) {
    $overall = 'WARNING';
    $summary = "{$warnings} warning(s) found — the app should work but some features may be limited";
} else {
    $overall = 'OK';
    $summary = 'All systems healthy — everything looks good!';
}

echo json_encode([
    'overall'  => $overall,
    'summary'  => $summary,
    'checks'   => $checks,
    'note'     => 'This endpoint is public — it does not expose passwords or secret keys',
    'generated_at' => date('Y-m-d H:i:s'),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
