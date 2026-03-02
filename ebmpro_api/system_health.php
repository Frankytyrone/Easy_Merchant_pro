<?php
/**
 * system_health.php — System Health Check
 *
 * GET — returns comprehensive health status (admin only)
 */
require_once __DIR__ . '/common.php';

setCorsHeaders();
header('Content-Type: application/json; charset=utf-8');

$auth = requireAuth();
if ($auth['role'] !== 'admin') {
    jsonResponse(['success' => false, 'error' => 'Admin access required'], 403);
}

// ── Required tables ───────────────────────────────────────────
$requiredTables = [
    'users', 'products', 'customers', 'invoices', 'invoice_items',
    'invoice_sequences', 'payments', 'settings', 'stores', 'audit_log',
    'login_attempts', 'sync_queue', 'tbl_stock', 'email_log', 'rate_limit',
    'quotes', 'quote_items', 'recurring_invoices', 'recurring_invoice_items',
    'expenses',
];

// ── Required columns per table ────────────────────────────────
$requiredColumns = [
    'stores'       => ['id','code','name','address_1','address_2','town','county','eircode','phone','invoice_prefix','quote_prefix','next_invoice_num','next_quote_num'],
    'settings'     => ['id','key','value'],
    'users'        => ['id','username','password_hash','role','store_id','active','created_at'],
    'login_attempts' => ['id','ip_address','attempted_at'],
    'customers'    => ['id','customer_code','company_name','contact_name','address_1','address_2','address_3',
                       'inv_town','inv_region','inv_postcode','email_address','inv_telephone','account_no',
                       'vat_registered','payment_terms','notes','store_id','created_at','updated_at',
                       'legacy_id','phone','phone2','town','county','postcode','account_ref',
                       'del_client_name','del_contact_name','del_address_1','del_address_2','del_address_3',
                       'del_town','del_county','del_postcode'],
    'products'     => ['id','product_code','description','category','price','vat_rate','unit','active',
                       'store_id','created_at','barcode','stock_qty','legacy_id'],
    'invoice_sequences' => ['store_code','next_invoice_num','next_quote_num'],
    'invoices'     => ['id','invoice_number','invoice_type','store_code','customer_id','invoice_date','due_date',
                       'inv_town','inv_region','inv_postcode','email_address','inv_telephone',
                       'inv_del_client_name','inv_del_alternative_name','inv_del_address_1','inv_del_address_2',
                       'inv_del_address_3','inv_del_town','inv_del_region','subtotal','vat_total','total',
                       'amount_paid','balance','status','payment_terms','notes','is_backdated',
                       'email_sent_at','email_opened_at','reminder_sent_at','created_by','updated_by',
                       'created_at','updated_at','legacy_id','payment_method','vat_number',
                       'delivery_charge','is_quote','legacy_client_id'],
    'invoice_items' => ['id','invoice_id','line_order','product_code','description','vat_rate',
                        'quantity','unit_price','discount_pct','line_total','vat_amount'],
    'payments'     => ['id','invoice_id','payment_date','amount','method','reference','notes',
                       'created_by','created_at','legacy_id','legacy_invoice_id'],
    'email_log'    => ['id','invoice_id','customer_id','to_email','subject','sent_at',
                       'opened_at','status','tracking_token'],
    'audit_log'    => ['id','user_id','action','ip_address','created_at'],
    'sync_queue'   => ['id'],
    'tbl_stock'    => ['id','product_id','quantity_change','reason','adjusted_by','adjusted_at'],
    'rate_limit'   => ['id','ip_address','action','window_start'],
    'quotes'       => ['id','quote_number','customer_id','store_id','status','quote_date',
                       'expiry_date','subtotal','vat_amount','total','notes','created_by','created_at','updated_at'],
    'quote_items'  => ['id','quote_id','product_id','description','quantity','unit_price','vat_rate','line_total'],
    'recurring_invoices' => ['id','customer_id','store_id','frequency','next_run_date','last_run_date',
                             'active','notes','created_by','created_at'],
    'recurring_invoice_items' => ['id','recurring_id','product_id','description','quantity','unit_price','vat_rate'],
    'expenses'     => ['id','store_id','expense_date','category','description','amount',
                       'vat_rate','vat_amount','supplier','receipt_ref','created_by','created_at'],
];

// ── Required API files ────────────────────────────────────────
$requiredApiFiles = [
    'auth.php','products.php','customers.php','invoices.php','payments.php',
    'settings.php','quotes.php','expenses.php','recurring.php','reports.php',
    'import.php','backup.php','audit.php','admin.php','stock.php',
    'system_health.php','system_fix.php',
];

// ── Required JS files ─────────────────────────────────────────
$requiredJsFiles = [
    'app.js','auth.js','config.js','customer.js','db.js',
    'import.js','invoice.js','pdf.js','product.js','sync.js','system_health.js',
];

// ── Required config constants ─────────────────────────────────
$requiredConstants = ['DB_HOST','DB_NAME','DB_USER','DB_PASS','JWT_SECRET'];

// ── Required PHP extensions ───────────────────────────────────
$requiredExtensions = ['pdo','pdo_mysql','json','zip'];

$results = [];

// 1. Database tables
$tableChecks = [];
try {
    $pdo = getDb();
    $existing = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($requiredTables as $tbl) {
        $tableChecks[$tbl] = in_array($tbl, $existing) ? 'ok' : 'missing';
    }
} catch (Throwable $e) {
    foreach ($requiredTables as $tbl) {
        $tableChecks[$tbl] = 'error';
    }
}
$results['tables'] = $tableChecks;

// 2. Database columns
$columnChecks = [];
try {
    $pdo = getDb();
    $existing = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($requiredColumns as $tbl => $cols) {
        if (!in_array($tbl, $existing)) {
            foreach ($cols as $col) {
                $columnChecks[$tbl][$col] = 'table_missing';
            }
            continue;
        }
        $colRows = $pdo->query("SHOW COLUMNS FROM `$tbl`")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($cols as $col) {
            $columnChecks[$tbl][$col] = in_array($col, $colRows) ? 'ok' : 'missing';
        }
    }
} catch (Throwable $e) {
    // leave partially populated
}
$results['columns'] = $columnChecks;

// 3. Required API files
$apiFileChecks = [];
foreach ($requiredApiFiles as $f) {
    $apiFileChecks[$f] = file_exists(__DIR__ . '/' . $f) ? 'ok' : 'missing';
}
$results['api_files'] = $apiFileChecks;

// 4. Required JS files
$jsFileChecks = [];
$jsDir = dirname(__DIR__) . '/ebmpro/js';
foreach ($requiredJsFiles as $f) {
    $jsFileChecks[$f] = file_exists($jsDir . '/' . $f) ? 'ok' : 'missing';
}
$results['js_files'] = $jsFileChecks;

// 5. PHP config constants
$constChecks = [];
foreach ($requiredConstants as $c) {
    $constChecks[$c] = defined($c) ? 'ok' : 'missing';
}
$results['config'] = $constChecks;

// 6. PHP extensions
$extChecks = [];
foreach ($requiredExtensions as $ext) {
    $extChecks[$ext] = extension_loaded($ext) ? 'ok' : 'missing';
}
$results['extensions'] = $extChecks;

// 7. PHP settings
$results['php_settings'] = [
    'memory_limit'       => ini_get('memory_limit'),
    'upload_max_filesize'=> ini_get('upload_max_filesize'),
    'post_max_size'      => ini_get('post_max_size'),
    'max_execution_time' => ini_get('max_execution_time'),
];

// 8. Data integrity
$integrity = [];
try {
    $pdo = getDb();
    $adminCount = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role='admin' AND active=1")->fetchColumn();
    $integrity['admin_user_exists'] = $adminCount > 0 ? 'ok' : 'missing';

    $storeCount = (int) $pdo->query("SELECT COUNT(*) FROM stores")->fetchColumn();
    $integrity['stores_exist'] = $storeCount > 0 ? 'ok' : 'missing';

    $seqCount = (int) $pdo->query("SELECT COUNT(*) FROM invoice_sequences")->fetchColumn();
    $integrity['invoice_sequences_exist'] = $seqCount > 0 ? 'ok' : 'missing';
} catch (Throwable $e) {
    $integrity['error'] = $e->getMessage();
}
$results['integrity'] = $integrity;

// 9. Error log (last 20 lines)
$errorLog = [];
$logPath = ini_get('error_log');
if ($logPath && is_readable($logPath)) {
    $lines = file($logPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    $errorLog = array_slice($lines, -20);
} else {
    $errorLog = ['(error_log not accessible)'];
}
$results['error_log'] = $errorLog;

jsonResponse(['success' => true, 'health' => $results]);

