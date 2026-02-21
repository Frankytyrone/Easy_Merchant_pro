<?php
/**
 * Easy Builders Merchant Pro — Installer
 * 5-step browser wizard
 */

declare(strict_types=1);

// ─── Lock check ──────────────────────────────────────────────────────────────
$lockFile = __DIR__ . '/install.lock';
if (file_exists($lockFile)) {
    http_response_code(403);
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">
    <title>Already Installed — Easy Builders Merchant Pro</title>' . inlineStyles() . '</head><body>
    <div class="wrap">
      <div class="logo">Easy Builders Merchant Pro</div>
      <div class="card">
        <div class="alert alert-error">
          <strong>&#128274; Already Installed</strong><br>
          This application has already been installed. The installer is locked.<br><br>
          If you need to re-install, remove <code>install/install.lock</code> from the server first.
        </div>
      </div>
    </div></body></html>';
    exit;
}

// ─── Session & helpers ────────────────────────────────────────────────────────
session_start();

if (!isset($_SESSION['install'])) {
    $_SESSION['install'] = [];
}

$step = max(1, min(5, (int)($_GET['step'] ?? $_SESSION['install']['step'] ?? 1)));
$_SESSION['install']['step'] = $step;

$error   = '';
$success = '';

// ─── AJAX: test DB connection ─────────────────────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'test_db') {
    header('Content-Type: application/json');
    $host = trim($_POST['db_host'] ?? '');
    $name = trim($_POST['db_name'] ?? '');
    $user = trim($_POST['db_user'] ?? '');
    $pass = $_POST['db_pass'] ?? '';
    try {
        $pdo = new PDO(
            "mysql:host={$host};dbname={$name};charset=utf8mb4",
            $user,
            $pass,
            [PDO::ATTR_TIMEOUT => 5]
        );
        echo json_encode(['ok' => true, 'msg' => 'Connection successful!']);
    } catch (PDOException $e) {
        echo json_encode(['ok' => false, 'msg' => 'Connection failed: ' . htmlspecialchars($e->getMessage())]);
    }
    exit;
}

// ─── AJAX: test SMTP ──────────────────────────────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'test_smtp') {
    header('Content-Type: application/json');
    $to   = trim($_POST['test_email'] ?? '');
    $from = trim($_POST['smtp_from_name'] ?? 'Easy Merchant Pro');
    $fromAddr = trim($_POST['smtp_user'] ?? '');
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['ok' => false, 'msg' => 'Please enter a valid recipient email address.']);
        exit;
    }
    $subject = 'Easy Builders Merchant Pro — SMTP Test';
    $message = "This is a test email from the Easy Builders Merchant Pro installer.\r\n\r\nIf you received this, your SMTP settings are working correctly.";
    $headers = "From: {$from} <{$fromAddr}>\r\nContent-Type: text/plain; charset=UTF-8";
    $sent = @mail($to, $subject, $message, $headers);
    if ($sent) {
        echo json_encode(['ok' => true, 'msg' => "Test email sent to {$to}. Please check your inbox."]);
    } else {
        echo json_encode(['ok' => false, 'msg' => 'mail() returned false. Check your server mail configuration.']);
    }
    exit;
}

// ─── POST handling ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Step 2: save DB credentials
    if ($step === 2 && isset($_POST['db_host'])) {
        $_SESSION['install']['db_host'] = trim($_POST['db_host']);
        $_SESSION['install']['db_name'] = trim($_POST['db_name']);
        $_SESSION['install']['db_user'] = trim($_POST['db_user']);
        $_SESSION['install']['db_pass'] = $_POST['db_pass'];

        // Validate by attempting connection
        try {
            new PDO(
                "mysql:host={$_SESSION['install']['db_host']};dbname={$_SESSION['install']['db_name']};charset=utf8mb4",
                $_SESSION['install']['db_user'],
                $_SESSION['install']['db_pass'],
                [PDO::ATTR_TIMEOUT => 5]
            );
            $step = 3;
            $_SESSION['install']['step'] = $step;
        } catch (PDOException $e) {
            $error = 'Database connection failed: ' . htmlspecialchars($e->getMessage());
        }
    }

    // Step 3: save shop details
    elseif ($step === 3 && isset($_POST['shop_name'])) {
        $fields = ['shop_name','address_1','address_2','town','county','eircode',
                   'phone','email','vat_no','invoice_prefix_falcarragh','invoice_prefix_gweedore'];
        foreach ($fields as $f) {
            $_SESSION['install'][$f] = trim($_POST[$f] ?? '');
        }
        if (empty($_SESSION['install']['shop_name'])) {
            $error = 'Shop name is required.';
        } else {
            $step = 4;
            $_SESSION['install']['step'] = $step;
        }
    }

    // Step 4: save SMTP settings
    elseif ($step === 4 && isset($_POST['smtp_host'])) {
        $_SESSION['install']['smtp_host']      = trim($_POST['smtp_host']);
        $_SESSION['install']['smtp_port']      = (int)($_POST['smtp_port'] ?? 587);
        $_SESSION['install']['smtp_user']      = trim($_POST['smtp_user']);
        $_SESSION['install']['smtp_pass']      = $_POST['smtp_pass'];
        $_SESSION['install']['smtp_from_name'] = trim($_POST['smtp_from_name']);
        $step = 5;
        $_SESSION['install']['step'] = $step;
    }

    // Step 5: run installation
    elseif ($step === 5 && isset($_POST['run_install'])) {
        $result = runInstall($_SESSION['install']);
        if ($result['ok']) {
            $success = $result['msg'];
        } else {
            $error = $result['msg'];
        }
    }
}

// ─── Install runner ───────────────────────────────────────────────────────────
function runInstall(array $d): array
{
    // 1. Connect
    try {
        $pdo = new PDO(
            "mysql:host={$d['db_host']};dbname={$d['db_name']};charset=utf8mb4",
            $d['db_user'],
            $d['db_pass'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 10]
        );
    } catch (PDOException $e) {
        return ['ok' => false, 'msg' => 'DB connection failed: ' . htmlspecialchars($e->getMessage())];
    }

    // 2. Run schema
    $schemaFile = __DIR__ . '/schema.sql';
    if (!file_exists($schemaFile)) {
        return ['ok' => false, 'msg' => 'schema.sql not found in install directory.'];
    }
    $sql = file_get_contents($schemaFile);
    // Split on semicolons (skip empty statements)
    $statements = array_filter(
        array_map('trim', explode(';', $sql)),
        fn($s) => $s !== '' && !preg_match('/^--/', $s)
    );
    foreach ($statements as $stmt) {
        if (trim($stmt) === '') {
            continue;
        }
        try {
            $pdo->exec($stmt);
        } catch (PDOException $e) {
            return ['ok' => false, 'msg' => 'Schema error: ' . htmlspecialchars($e->getMessage()) . '<br><pre>' . htmlspecialchars($stmt) . '</pre>'];
        }
    }

    // 3. Insert/update settings row
    $prefixFal = strtoupper(substr(preg_replace('/[^A-Z0-9]/i', '', $d['invoice_prefix_falcarragh'] ?? 'FAL'), 0, 10)) ?: 'FAL';
    $prefixGwe = strtoupper(substr(preg_replace('/[^A-Z0-9]/i', '', $d['invoice_prefix_gweedore']   ?? 'GWE'), 0, 10)) ?: 'GWE';

    $stmtSettings = $pdo->prepare("
        INSERT INTO settings
            (shop_name, address_1, address_2, town, county, eircode, phone, email, vat_no,
             smtp_host, smtp_port, smtp_user, smtp_pass, smtp_from_name,
             invoice_prefix_falcarragh, invoice_prefix_gweedore)
        VALUES
            (:shop_name, :address_1, :address_2, :town, :county, :eircode, :phone, :email, :vat_no,
             :smtp_host, :smtp_port, :smtp_user, :smtp_pass, :smtp_from_name,
             :prefix_fal, :prefix_gwe)
        ON DUPLICATE KEY UPDATE
            shop_name = VALUES(shop_name),
            address_1 = VALUES(address_1),
            address_2 = VALUES(address_2),
            town = VALUES(town),
            county = VALUES(county),
            eircode = VALUES(eircode),
            phone = VALUES(phone),
            email = VALUES(email),
            vat_no = VALUES(vat_no),
            smtp_host = VALUES(smtp_host),
            smtp_port = VALUES(smtp_port),
            smtp_user = VALUES(smtp_user),
            smtp_pass = VALUES(smtp_pass),
            smtp_from_name = VALUES(smtp_from_name),
            invoice_prefix_falcarragh = VALUES(invoice_prefix_falcarragh),
            invoice_prefix_gweedore = VALUES(invoice_prefix_gweedore)
    ");
    $stmtSettings->execute([
        ':shop_name'   => $d['shop_name']   ?? '',
        ':address_1'   => $d['address_1']   ?? '',
        ':address_2'   => $d['address_2']   ?? '',
        ':town'        => $d['town']        ?? '',
        ':county'      => $d['county']      ?? '',
        ':eircode'     => $d['eircode']     ?? '',
        ':phone'       => $d['phone']       ?? '',
        ':email'       => $d['email']       ?? '',
        ':vat_no'      => $d['vat_no']      ?? '',
        ':smtp_host'   => $d['smtp_host']   ?? '',
        ':smtp_port'   => $d['smtp_port']   ?? 587,
        ':smtp_user'   => $d['smtp_user']   ?? '',
        ':smtp_pass'   => $d['smtp_pass']   ?? '',
        ':smtp_from_name' => $d['smtp_from_name'] ?? '',
        ':prefix_fal'  => $prefixFal,
        ':prefix_gwe'  => $prefixGwe,
    ]);

    // 4. Create admin user (skip if exists)
    $hash = password_hash('Easy2026!', PASSWORD_BCRYPT);
    $stmtUser = $pdo->prepare("
        INSERT IGNORE INTO users (username, password_hash, full_name, role, active)
        VALUES ('admin', :hash, 'Administrator', 'admin', 1)
    ");
    $stmtUser->execute([':hash' => $hash]);

    // 5. Write ebmpro_api/config.php
    $secret    = bin2hex(random_bytes(16)); // 32 hex chars
    $configDir = dirname(__DIR__) . '/ebmpro_api';
    if (!is_dir($configDir)) {
        if (!mkdir($configDir, 0755, true)) {
            return ['ok' => false, 'msg' => 'Could not create ebmpro_api directory. Check file permissions.'];
        }
    }
    $configPath = $configDir . '/config.php';
    // Use var_export() so any special characters (quotes, backslashes, etc.) are safely escaped for PHP syntax.
    $eHost   = var_export($d['db_host'], true);
    $eName   = var_export($d['db_name'], true);
    $eUser   = var_export($d['db_user'], true);
    $ePass   = var_export($d['db_pass'], true);
    $eSecret = var_export($secret, true);
    $configContent = "<?php\n"
        . "define('SITE_URL', 'https://shanemcgee.biz');\n"
        . "define('APP_PATH', '/ebmpro/');\n"
        . "define('API_PATH', '/ebmpro_api/');\n"
        . "define('TRACK_URL', 'https://shanemcgee.biz/track/open.php');\n"
        . "define('JWT_SECRET', {$eSecret});\n"
        . "define('DB_HOST', {$eHost});\n"
        . "define('DB_NAME', {$eName});\n"
        . "define('DB_USER', {$eUser});\n"
        . "define('DB_PASS', {$ePass});\n"
        . "try {\n"
        . "    \$pdo = new PDO('mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4', DB_USER, DB_PASS, [\n"
        . "        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,\n"
        . "        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC\n"
        . "    ]);\n"
        . "} catch (PDOException \$e) {\n"
        . "    http_response_code(503);\n"
        . "    die(json_encode(['error' => 'DB unavailable']));\n"
        . "}\n";
    if (file_put_contents($configPath, $configContent) === false) {
        return ['ok' => false, 'msg' => 'Could not write ebmpro_api/config.php. Check file permissions.'];
    }
    if (!chmod($configPath, 0640)) {
        // Non-fatal: log a warning but continue — some hosts (e.g. shared) restrict chmod.
        error_log('EBM installer: could not chmod ebmpro_api/config.php to 0640');
    }

    // 6. Write install.lock
    if (file_put_contents(__DIR__ . '/install.lock', date('Y-m-d H:i:s') . "\n") === false) {
        return ['ok' => false, 'msg' => 'Installation completed but could not write install.lock. Please create it manually.'];
    }

    return ['ok' => true, 'msg' => 'Installation completed successfully.'];
}

// ─── Inline CSS helper ────────────────────────────────────────────────────────
function inlineStyles(): string
{
    return <<<CSS
<style>
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
  body {
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
    background: #f0f4f8;
    color: #1a202c;
    min-height: 100vh;
    display: flex;
    align-items: flex-start;
    justify-content: center;
    padding: 40px 16px;
  }
  .wrap { width: 100%; max-width: 680px; }
  .logo {
    font-size: 1.1rem;
    font-weight: 700;
    color: #2b6cb0;
    text-align: center;
    margin-bottom: 8px;
    letter-spacing: .5px;
  }
  .logo span { color: #744210; }
  .title {
    text-align: center;
    font-size: 1.5rem;
    font-weight: 700;
    margin-bottom: 24px;
    color: #2d3748;
  }
  /* Steps indicator */
  .steps {
    display: flex;
    justify-content: space-between;
    margin-bottom: 28px;
    position: relative;
  }
  .steps::before {
    content: '';
    position: absolute;
    top: 18px;
    left: 10%;
    right: 10%;
    height: 2px;
    background: #cbd5e0;
    z-index: 0;
  }
  .step-item {
    display: flex;
    flex-direction: column;
    align-items: center;
    flex: 1;
    position: relative;
    z-index: 1;
  }
  .step-num {
    width: 36px; height: 36px;
    border-radius: 50%;
    background: #e2e8f0;
    color: #718096;
    font-weight: 700;
    font-size: .85rem;
    display: flex; align-items: center; justify-content: center;
    border: 2px solid #cbd5e0;
    transition: all .2s;
  }
  .step-item.active .step-num {
    background: #2b6cb0;
    color: #fff;
    border-color: #2b6cb0;
  }
  .step-item.done .step-num {
    background: #38a169;
    color: #fff;
    border-color: #38a169;
  }
  .step-label {
    margin-top: 6px;
    font-size: .72rem;
    color: #718096;
    text-align: center;
    font-weight: 500;
  }
  .step-item.active .step-label { color: #2b6cb0; font-weight: 600; }
  .step-item.done .step-label   { color: #38a169; }
  /* Card */
  .card {
    background: #fff;
    border-radius: 10px;
    box-shadow: 0 4px 16px rgba(0,0,0,.08);
    padding: 36px 40px;
  }
  .card h2 {
    font-size: 1.15rem;
    font-weight: 700;
    margin-bottom: 20px;
    color: #2d3748;
    border-bottom: 2px solid #ebf4ff;
    padding-bottom: 10px;
  }
  .card h2 .icon { margin-right: 8px; }
  /* Form */
  .form-row { margin-bottom: 18px; }
  .form-row label {
    display: block;
    font-size: .82rem;
    font-weight: 600;
    color: #4a5568;
    margin-bottom: 5px;
  }
  .form-row label .req { color: #e53e3e; margin-left: 2px; }
  .form-row input[type=text],
  .form-row input[type=email],
  .form-row input[type=password],
  .form-row input[type=number] {
    width: 100%;
    padding: 9px 12px;
    border: 1px solid #cbd5e0;
    border-radius: 6px;
    font-size: .9rem;
    color: #2d3748;
    transition: border-color .15s, box-shadow .15s;
    background: #f7fafc;
  }
  .form-row input:focus {
    outline: none;
    border-color: #4299e1;
    box-shadow: 0 0 0 3px rgba(66,153,225,.2);
    background: #fff;
  }
  .form-hint {
    font-size: .75rem;
    color: #718096;
    margin-top: 4px;
  }
  .form-cols { display: grid; grid-template-columns: 1fr 1fr; gap: 0 20px; }
  /* Buttons */
  .btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 10px 22px;
    border-radius: 6px;
    border: none;
    font-size: .9rem;
    font-weight: 600;
    cursor: pointer;
    transition: background .15s, transform .1s;
    text-decoration: none;
  }
  .btn:active { transform: scale(.97); }
  .btn-primary  { background: #2b6cb0; color: #fff; }
  .btn-primary:hover  { background: #2c5282; }
  .btn-success  { background: #38a169; color: #fff; }
  .btn-success:hover  { background: #276749; }
  .btn-secondary { background: #edf2f7; color: #4a5568; }
  .btn-secondary:hover { background: #e2e8f0; }
  .btn-outline {
    background: transparent;
    border: 1px solid #cbd5e0;
    color: #4a5568;
  }
  .btn-outline:hover { background: #f7fafc; }
  .btn-sm { padding: 6px 14px; font-size: .8rem; }
  .btn-actions {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-top: 28px;
    padding-top: 20px;
    border-top: 1px solid #e2e8f0;
    gap: 12px;
  }
  /* Alerts */
  .alert {
    padding: 12px 16px;
    border-radius: 7px;
    font-size: .88rem;
    margin-bottom: 20px;
    border-left: 4px solid;
  }
  .alert-error   { background: #fff5f5; border-color: #fc8181; color: #742a2a; }
  .alert-success { background: #f0fff4; border-color: #68d391; color: #22543d; }
  .alert-info    { background: #ebf8ff; border-color: #63b3ed; color: #2c5282; }
  .alert-warning { background: #fffaf0; border-color: #f6ad55; color: #7b341e; }
  /* Check list */
  .check-list { list-style: none; margin: 4px 0 0 0; }
  .check-list li {
    padding: 10px 14px;
    border-radius: 6px;
    margin-bottom: 8px;
    font-size: .88rem;
    display: flex;
    align-items: center;
    gap: 10px;
    font-weight: 500;
  }
  .check-list li.pass { background: #f0fff4; color: #22543d; }
  .check-list li.fail { background: #fff5f5; color: #742a2a; }
  .check-list li.warn { background: #fffaf0; color: #7b341e; }
  .check-icon { font-size: 1rem; }
  /* Test row */
  .test-row {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    margin-top: 4px;
  }
  .test-row input { flex: 1; }
  #db-test-result, #smtp-test-result {
    font-size: .82rem;
    margin-top: 8px;
    padding: 8px 12px;
    border-radius: 5px;
    display: none;
  }
  /* Success box */
  .success-box {
    text-align: center;
    padding: 20px 0 10px;
  }
  .success-box .big-icon { font-size: 4rem; margin-bottom: 16px; }
  .success-box h3 { font-size: 1.4rem; color: #22543d; margin-bottom: 10px; }
  .success-box p { color: #4a5568; font-size: .92rem; line-height: 1.6; }
  .creds-box {
    background: #f7fafc;
    border: 1px solid #e2e8f0;
    border-radius: 7px;
    padding: 16px 20px;
    margin: 20px 0;
    text-align: left;
  }
  .creds-box table { width: 100%; border-collapse: collapse; }
  .creds-box td { padding: 5px 8px; font-size: .87rem; }
  .creds-box td:first-child { font-weight: 600; color: #4a5568; width: 40%; }
  .creds-box code {
    background: #edf2f7;
    padding: 2px 7px;
    border-radius: 4px;
    font-family: 'Courier New', monospace;
    font-size: .85rem;
  }
  .section-label {
    font-size: .78rem;
    font-weight: 700;
    color: #718096;
    text-transform: uppercase;
    letter-spacing: .8px;
    margin: 22px 0 12px;
  }
  @media (max-width: 500px) {
    .card { padding: 24px 18px; }
    .form-cols { grid-template-columns: 1fr; }
    .step-label { display: none; }
  }
</style>
CSS;
}

// ─── HTML helpers ─────────────────────────────────────────────────────────────
function stepNav(int $current): string
{
    $labels = ['Welcome', 'Database', 'Shop Details', 'SMTP', 'Install'];
    $html   = '<nav class="steps">';
    for ($i = 1; $i <= 5; $i++) {
        $cls = ($i < $current) ? 'done' : (($i === $current) ? 'active' : '');
        $num = ($i < $current) ? '&#10003;' : $i;
        $html .= "<div class=\"step-item {$cls}\">
            <div class=\"step-num\">{$num}</div>
            <div class=\"step-label\">{$labels[$i-1]}</div>
        </div>";
    }
    $html .= '</nav>';
    return $html;
}

function val(string $key, string $default = ''): string
{
    return htmlspecialchars($_SESSION['install'][$key] ?? $default, ENT_QUOTES);
}

// ─── Server checks ────────────────────────────────────────────────────────────
function serverChecks(): array
{
    $checks = [];

    // PHP version
    $phpOk = version_compare(PHP_VERSION, '7.4.0', '>=');
    $checks[] = [
        'label' => 'PHP Version (' . PHP_VERSION . ')',
        'ok'    => $phpOk,
        'warn'  => false,
        'note'  => $phpOk ? 'PHP 7.4 or higher detected.' : 'PHP 7.4 or higher is required.',
    ];

    // PDO MySQL
    $pdoOk = extension_loaded('pdo_mysql');
    $checks[] = [
        'label' => 'PDO MySQL Extension',
        'ok'    => $pdoOk,
        'warn'  => false,
        'note'  => $pdoOk ? 'PDO MySQL is available.' : 'The pdo_mysql extension is required but not loaded.',
    ];

    // mail()
    $mailOk = function_exists('mail');
    $checks[] = [
        'label' => 'PHP mail() Function',
        'ok'    => $mailOk,
        'warn'  => !$mailOk,
        'note'  => $mailOk ? 'mail() function is available.' : 'mail() is not available. Email sending will not work.',
    ];

    // Write permissions
    $apiDir      = dirname(__DIR__) . '/ebmpro_api';
    $writeApiDir = is_writable(dirname(__DIR__));
    $writeInstall = is_writable(__DIR__);
    $checks[] = [
        'label' => 'Install directory writable',
        'ok'    => $writeInstall,
        'warn'  => false,
        'note'  => $writeInstall ? 'install/ is writable.' : 'install/ directory is not writable.',
    ];
    $checks[] = [
        'label' => 'Application root writable (for config)',
        'ok'    => $writeApiDir,
        'warn'  => false,
        'note'  => $writeApiDir ? 'Root directory is writable (ebmpro_api/config.php can be created).' : 'Root directory is not writable. Cannot write config.php.',
    ];

    $allPass = !in_array(false, array_column($checks, 'ok'), true)
            || !array_filter($checks, fn($c) => !$c['ok'] && !$c['warn']);

    return ['checks' => $checks, 'canProceed' => $phpOk && $pdoOk && $writeInstall && $writeApiDir];
}

// ─── Page layout ──────────────────────────────────────────────────────────────
function pageHead(string $title): void
{
    echo '<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>' . htmlspecialchars($title) . ' — Easy Builders Merchant Pro Installer</title>'
  . inlineStyles() . '
</head>
<body>
<div class="wrap">
  <div class="logo">Easy Builders <span>Merchant Pro</span></div>
  <div class="title">Installation Wizard</div>';
}

function pageFoot(): void
{
    echo '</div></body></html>';
}

// ═══════════════════════════════════════════════════════════════════════════════
// RENDER STEPS
// ═══════════════════════════════════════════════════════════════════════════════
pageHead('Installer');
echo stepNav($step);
echo '<div class="card">';

if ($error) {
    echo '<div class="alert alert-error"><strong>&#9888; Error:</strong> ' . $error . '</div>';
}
if ($success && $step !== 5) {
    echo '<div class="alert alert-success">' . htmlspecialchars($success) . '</div>';
}

// ─── STEP 1: Welcome & server checks ─────────────────────────────────────────
if ($step === 1):
    $chk = serverChecks();
?>
<h2><span class="icon">&#128640;</span> Welcome to the Installer</h2>

<p style="color:#4a5568;font-size:.9rem;line-height:1.6;margin-bottom:20px;">
  This wizard will guide you through installing <strong>Easy Builders Merchant Pro</strong>.
  The process takes around 5 minutes. Please review the server requirements below before continuing.
</p>

<div class="section-label">Server Requirements</div>
<ul class="check-list">
<?php foreach ($chk['checks'] as $c):
    $cls  = $c['ok'] ? 'pass' : ($c['warn'] ? 'warn' : 'fail');
    $icon = $c['ok'] ? '&#9989;' : ($c['warn'] ? '&#9888;&#65039;' : '&#10060;');
?>
  <li class="<?= $cls ?>">
    <span class="check-icon"><?= $icon ?></span>
    <span><strong><?= htmlspecialchars($c['label']) ?></strong> — <?= htmlspecialchars($c['note']) ?></span>
  </li>
<?php endforeach; ?>
</ul>

<?php if (!$chk['canProceed']): ?>
<div class="alert alert-error" style="margin-top:20px;">
  <strong>&#10060; Cannot Continue</strong><br>
  One or more required checks failed. Please resolve the issues above before proceeding.
</div>
<?php else: ?>
<div class="alert alert-info" style="margin-top:20px;">
  <strong>&#9432; Ready to install.</strong> All critical requirements are met. Click <em>Next</em> to continue.
</div>
<div class="btn-actions" style="justify-content:flex-end;">
  <a href="?step=2" class="btn btn-primary">Next: Database &rarr;</a>
</div>
<?php endif; ?>

<?php
// ─── STEP 2: Database credentials ────────────────────────────────────────────
elseif ($step === 2):
?>
<h2><span class="icon">&#128451;</span> Database Connection</h2>
<p style="color:#4a5568;font-size:.88rem;margin-bottom:20px;">
  Enter your MySQL database credentials. The database must already exist.
</p>

<form method="post" action="?step=2">
  <div class="form-row">
    <label>Database Host <span class="req">*</span></label>
    <input type="text" name="db_host" value="<?= val('db_host', 'localhost') ?>" required placeholder="localhost">
    <div class="form-hint">Usually <code>localhost</code> or a hostname provided by your host.</div>
  </div>
  <div class="form-row">
    <label>Database Name <span class="req">*</span></label>
    <input type="text" name="db_name" value="<?= val('db_name') ?>" required placeholder="ebmpro">
  </div>
  <div class="form-cols">
    <div class="form-row">
      <label>Username <span class="req">*</span></label>
      <input type="text" name="db_user" value="<?= val('db_user') ?>" required placeholder="db_username" autocomplete="username">
    </div>
    <div class="form-row">
      <label>Password</label>
      <input type="password" name="db_pass" value="<?= val('db_pass') ?>" placeholder="••••••••" autocomplete="current-password">
    </div>
  </div>

  <div class="section-label">Test Connection (optional)</div>
  <div id="db-test-area" style="margin-bottom:12px;">
    <button type="button" class="btn btn-outline btn-sm" onclick="testDb()">&#128268; Test Connection</button>
    <div id="db-test-result"></div>
  </div>

  <div class="btn-actions">
    <a href="?step=1" class="btn btn-secondary">&larr; Back</a>
    <button type="submit" class="btn btn-primary">Next: Shop Details &rarr;</button>
  </div>
</form>

<script>
function testDb() {
  var form = document.querySelector('form');
  var fd   = new FormData(form);
  var res  = document.getElementById('db-test-result');
  res.style.display = 'block';
  res.style.background = '#ebf8ff';
  res.style.color = '#2c5282';
  res.textContent = 'Testing connection\u2026';
  fetch('?action=test_db', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(d => {
      res.style.background = d.ok ? '#f0fff4' : '#fff5f5';
      res.style.color      = d.ok ? '#22543d' : '#742a2a';
      res.innerHTML = (d.ok ? '&#9989; ' : '&#10060; ') + d.msg;
    })
    .catch(() => {
      res.style.background = '#fff5f5';
      res.style.color = '#742a2a';
      res.textContent = 'Request failed. Please try manually.';
    });
}
</script>

<?php
// ─── STEP 3: Shop details ─────────────────────────────────────────────────────
elseif ($step === 3):
?>
<h2><span class="icon">&#127978;</span> Shop Details</h2>
<p style="color:#4a5568;font-size:.88rem;margin-bottom:20px;">
  These details appear on invoices and customer communications.
</p>

<form method="post" action="?step=3">
  <div class="form-row">
    <label>Shop / Business Name <span class="req">*</span></label>
    <input type="text" name="shop_name" value="<?= val('shop_name') ?>" required placeholder="Easy Builders Ltd">
  </div>
  <div class="form-row">
    <label>Address Line 1</label>
    <input type="text" name="address_1" value="<?= val('address_1') ?>" placeholder="123 Main Street">
  </div>
  <div class="form-row">
    <label>Address Line 2</label>
    <input type="text" name="address_2" value="<?= val('address_2') ?>" placeholder="Industrial Estate">
  </div>
  <div class="form-cols">
    <div class="form-row">
      <label>Town / City</label>
      <input type="text" name="town" value="<?= val('town') ?>" placeholder="Letterkenny">
    </div>
    <div class="form-row">
      <label>County</label>
      <input type="text" name="county" value="<?= val('county') ?>" placeholder="Donegal">
    </div>
  </div>
  <div class="form-cols">
    <div class="form-row">
      <label>Eircode</label>
      <input type="text" name="eircode" value="<?= val('eircode') ?>" placeholder="F92 XXXX">
    </div>
    <div class="form-row">
      <label>VAT Number</label>
      <input type="text" name="vat_no" value="<?= val('vat_no') ?>" placeholder="IE1234567X">
    </div>
  </div>
  <div class="form-cols">
    <div class="form-row">
      <label>Phone</label>
      <input type="text" name="phone" value="<?= val('phone') ?>" placeholder="+353 74 000 0000">
    </div>
    <div class="form-row">
      <label>Email</label>
      <input type="email" name="email" value="<?= val('email') ?>" placeholder="info@example.ie">
    </div>
  </div>

  <div class="section-label">Invoice Prefixes</div>
  <div class="form-cols">
    <div class="form-row">
      <label>Falcarragh Store Prefix</label>
      <input type="text" name="invoice_prefix_falcarragh" value="<?= val('invoice_prefix_falcarragh', 'FAL') ?>" maxlength="10" placeholder="FAL">
      <div class="form-hint">e.g. FAL-1001, FAL-1002&hellip;</div>
    </div>
    <div class="form-row">
      <label>Gweedore Store Prefix</label>
      <input type="text" name="invoice_prefix_gweedore" value="<?= val('invoice_prefix_gweedore', 'GWE') ?>" maxlength="10" placeholder="GWE">
      <div class="form-hint">e.g. GWE-1001, GWE-1002&hellip;</div>
    </div>
  </div>

  <div class="btn-actions">
    <a href="?step=2" class="btn btn-secondary">&larr; Back</a>
    <button type="submit" class="btn btn-primary">Next: SMTP Settings &rarr;</button>
  </div>
</form>

<?php
// ─── STEP 4: SMTP settings ────────────────────────────────────────────────────
elseif ($step === 4):
?>
<h2><span class="icon">&#128231;</span> SMTP / Email Settings</h2>
<p style="color:#4a5568;font-size:.88rem;margin-bottom:20px;">
  Configure outgoing email. These settings can be changed later in the admin panel.
  SMTP credentials are stored in the database.
</p>

<form method="post" action="?step=4">
  <div class="form-cols">
    <div class="form-row">
      <label>SMTP Host</label>
      <input type="text" name="smtp_host" value="<?= val('smtp_host', 'smtp.example.com') ?>" placeholder="smtp.gmail.com">
    </div>
    <div class="form-row">
      <label>SMTP Port</label>
      <input type="number" name="smtp_port" value="<?= val('smtp_port', '587') ?>" placeholder="587" min="1" max="65535">
      <div class="form-hint">Common: 587 (STARTTLS), 465 (SSL), 25.</div>
    </div>
  </div>
  <div class="form-cols">
    <div class="form-row">
      <label>SMTP Username</label>
      <input type="text" name="smtp_user" value="<?= val('smtp_user') ?>" placeholder="user@example.com" autocomplete="username">
    </div>
    <div class="form-row">
      <label>SMTP Password</label>
      <input type="password" name="smtp_pass" value="<?= val('smtp_pass') ?>" placeholder="••••••••" autocomplete="current-password">
    </div>
  </div>
  <div class="form-row">
    <label>From Name</label>
    <input type="text" name="smtp_from_name" value="<?= val('smtp_from_name', 'Easy Builders Merchant Pro') ?>" placeholder="Easy Builders Merchant Pro">
  </div>

  <div class="section-label">Test Email (optional)</div>
  <div class="form-row">
    <label>Send test email to</label>
    <div class="test-row">
      <input type="email" id="test_email_addr" placeholder="you@example.com">
      <button type="button" class="btn btn-outline btn-sm" onclick="testSmtp()">&#9993;&#65039; Send Test</button>
    </div>
    <div id="smtp-test-result"></div>
    <div class="form-hint">Uses PHP mail(). SMTP library integration is handled by the application after installation.</div>
  </div>

  <div class="btn-actions">
    <a href="?step=3" class="btn btn-secondary">&larr; Back</a>
    <button type="submit" class="btn btn-primary">Next: Run Installer &rarr;</button>
  </div>
</form>

<script>
function testSmtp() {
  var formEl  = document.querySelector('form');
  var fd      = new FormData(formEl);
  var testTo  = document.getElementById('test_email_addr').value;
  fd.append('test_email', testTo);
  var res = document.getElementById('smtp-test-result');
  res.style.display = 'block';
  res.style.background = '#ebf8ff';
  res.style.color = '#2c5282';
  res.textContent = 'Sending test email\u2026';
  fetch('?action=test_smtp', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(d => {
      res.style.background = d.ok ? '#f0fff4' : '#fff5f5';
      res.style.color      = d.ok ? '#22543d' : '#742a2a';
      res.innerHTML = (d.ok ? '&#9989; ' : '&#10060; ') + d.msg;
    })
    .catch(() => {
      res.style.background = '#fff5f5';
      res.style.color = '#742a2a';
      res.textContent = 'Request failed.';
    });
}
</script>

<?php
// ─── STEP 5: Run installer ────────────────────────────────────────────────────
elseif ($step === 5):

    if ($success):
?>
<div class="success-box">
  <div class="big-icon">&#127881;</div>
  <h3>Installation Complete!</h3>
  <p>Easy Builders Merchant Pro has been installed successfully.<br>
  The installer is now locked and cannot be run again.</p>
</div>

<div class="creds-box">
  <div class="section-label" style="margin-top:0">Default Admin Credentials</div>
  <table>
    <tr><td>Username</td><td><code>admin</code></td></tr>
    <tr><td>Password</td><td><code>Easy2026!</code></td></tr>
  </table>
</div>

<div class="alert alert-warning">
  <strong>&#9888; Security Reminder:</strong> Change the admin password immediately after your first login.
  The installer directory (<code>install/</code>) is now locked. You may optionally restrict web access to it via your server configuration.
</div>

<div class="btn-actions" style="justify-content:center;border:none;padding:0;margin-top:20px;">
  <a href="../ebmpro/" class="btn btn-success" style="font-size:1rem;padding:12px 32px;">
    &#128274; Go to Application &rarr;
  </a>
</div>

<?php
    else:
        // Pre-install summary
        $dbHost    = val('db_host');
        $dbName    = val('db_name');
        $shopName  = val('shop_name');
        $smtpHost  = val('smtp_host');
?>
<h2><span class="icon">&#9881;&#65039;</span> Review &amp; Install</h2>
<p style="color:#4a5568;font-size:.88rem;margin-bottom:16px;">
  Please review your settings below. Click <strong>Run Installer</strong> to create the database tables,
  write the config file, and lock the installer.
</p>

<div class="creds-box">
  <div class="section-label" style="margin-top:0">Database</div>
  <table>
    <tr><td>Host</td><td><code><?= $dbHost ?></code></td></tr>
    <tr><td>Database</td><td><code><?= $dbName ?></code></td></tr>
    <tr><td>Username</td><td><code><?= val('db_user') ?></code></td></tr>
  </table>

  <div class="section-label">Shop</div>
  <table>
    <tr><td>Name</td><td><?= $shopName ?></td></tr>
    <tr><td>Eircode</td><td><?= val('eircode') ?></td></tr>
    <tr><td>VAT No.</td><td><?= val('vat_no') ?></td></tr>
    <tr><td>Invoice Prefixes</td><td><?= val('invoice_prefix_falcarragh', 'FAL') ?> / <?= val('invoice_prefix_gweedore', 'GWE') ?></td></tr>
  </table>

  <div class="section-label">SMTP</div>
  <table>
    <tr><td>Host</td><td><code><?= $smtpHost ?: '(not set)' ?></code></td></tr>
    <tr><td>Port</td><td><?= val('smtp_port', '587') ?></td></tr>
    <tr><td>From Name</td><td><?= val('smtp_from_name') ?></td></tr>
  </table>
</div>

<div class="alert alert-info">
  <strong>&#9432; What will happen:</strong>
  <ul style="margin-top:8px;margin-left:18px;font-size:.85rem;line-height:1.8;">
    <li>All database tables will be created (from schema.sql)</li>
    <li>Default settings row will be inserted</li>
    <li>Admin user will be created (<code>admin</code> / <code>Easy2026!</code>)</li>
    <li><code>ebmpro_api/config.php</code> will be written with your DB credentials</li>
    <li><code>install/install.lock</code> will be created to prevent re-running</li>
  </ul>
</div>

<form method="post" action="?step=5">
  <div class="btn-actions">
    <a href="?step=4" class="btn btn-secondary">&larr; Back</a>
    <button type="submit" name="run_install" value="1" class="btn btn-success"
            onclick="this.disabled=true;this.textContent='Installing\u2026';this.form.submit();">
      &#9881;&#65039; Run Installer
    </button>
  </div>
</form>

<?php
    endif; // success
endif; // step 5

echo '</div>'; // .card
pageFoot();