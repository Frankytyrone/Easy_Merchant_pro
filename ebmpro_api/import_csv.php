<?php
/**
 * import_csv.php — Unified CSV importer for customers, products and invoices.
 *
 * POST multipart/form-data:
 *   type — 'customers' | 'products' | 'invoices'
 *   file — CSV file (up to 128 MB / ~50 000 rows)
 *
 * Column mappings are flexible and case-insensitive.
 *
 * Returns JSON: { success, imported, skipped, errors[] }
 */
require_once __DIR__ . '/common.php';

setCorsHeaders();
header('Content-Type: application/json; charset=utf-8');

$auth = requireAuth();
if (!in_array($auth['role'], ['admin', 'manager'], true)) {
    jsonResponse(['success' => false, 'error' => 'Admin or manager access required'], 403);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'error' => 'POST required'], 405);
}

$type = strtolower(trim($_POST['type'] ?? ''));
if (!in_array($type, ['customers', 'products', 'invoices'], true)) {
    jsonResponse(['success' => false, 'error' => 'Invalid type. Must be customers, products or invoices'], 422);
}

if (empty($_FILES['file']['tmp_name'])) {
    jsonResponse(['success' => false, 'error' => 'No file uploaded'], 422);
}

$ext = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION));
if ($ext !== 'csv') {
    jsonResponse(['success' => false, 'error' => 'Only CSV files are accepted'], 422);
}

if ($_FILES['file']['size'] > 134217728) { // 128 MB
    jsonResponse(['success' => false, 'error' => 'File exceeds 128 MB limit'], 422);
}

$tmpPath = $_FILES['file']['tmp_name'];

// ── CSV parser ─────────────────────────────────────────────────────────────────
function parseCsv(string $path): array
{
    $rows = [];
    $fh = fopen($path, 'r');
    if (!$fh) {
        return $rows;
    }
    // Strip UTF-8 BOM if present
    $bom = fread($fh, 3);
    if ($bom !== "\xEF\xBB\xBF") {
        rewind($fh);
    }
    $headers = null;
    while (($line = fgetcsv($fh)) !== false) {
        if ($headers === null) {
            $headers = array_map('trim', $line);
            continue;
        }
        $rows[] = array_combine($headers, array_pad(array_slice($line, 0, count($headers)), count($headers), ''));
    }
    fclose($fh);
    return $rows;
}

// ── Flexible column lookup (case-insensitive, multiple aliases) ────────────────
function col(array $r, array $names, string $default = ''): string
{
    foreach ($names as $n) {
        if (isset($r[$n]) && $r[$n] !== '') {
            return trim((string)$r[$n]);
        }
    }
    return $default;
}

// ── Import customers ───────────────────────────────────────────────────────────
function importCustomers(array $rows, PDO $pdo): array
{
    $imported = 0;
    $skipped  = 0;
    $errors   = [];

    $checkEmail = $pdo->prepare(
        'SELECT id FROM customers WHERE email_address = ? LIMIT 1'
    );
    $insert = $pdo->prepare(
        'INSERT INTO customers
         (legacy_id, company_name, email_address, phone, address_1, address_2,
          town, county, postcode, inv_town, inv_region, inv_postcode,
          inv_telephone, account_no, created_at, updated_at)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())'
    );

    foreach ($rows as $i => $raw) {
        $r = array_change_key_case($raw, CASE_LOWER);

        $name  = col($r, ['custname','name','company','company_name','client_name']);
        $email = col($r, ['custemail','email','email_address']);

        if ($name === '' && $email === '') {
            $skipped++;
            continue;
        }

        // Skip duplicate emails
        if ($email !== '') {
            $checkEmail->execute([$email]);
            if ($checkEmail->fetchColumn()) {
                $skipped++;
                continue;
            }
        }

        $legacyId = col($r, ['custid','id','client_id']);
        $phone    = col($r, ['custphone','phone','tel','telephone','inv_telephone']);
        $addr1    = col($r, ['custaddress','address','address1','address_1']);
        $addr2    = col($r, ['address2','address_2']);
        $town     = col($r, ['town','city','inv_town']);
        $county   = col($r, ['county','region','inv_region']);
        $eircode  = col($r, ['eircode','postcode','zip','inv_postcode']);
        $vatNo    = col($r, ['vatno','vat_number','vatnumber']);

        $accountNo = 'CUS-' . str_pad((string)random_int(10000, 99999), 5, '0', STR_PAD_LEFT);

        try {
            $insert->execute([
                $legacyId !== '' ? (int)$legacyId : null,
                $name,
                $email ?: null,
                $phone ?: null,
                $addr1 ?: null,
                $addr2 ?: null,
                $town ?: null,
                $county ?: null,
                $eircode ?: null,
                $town ?: null,
                $county ?: null,
                $eircode ?: null,
                $phone ?: null,
                $accountNo,
            ]);
            $imported++;
        } catch (PDOException $e) {
            error_log('import_csv customers row ' . ($i + 2) . ': ' . $e->getMessage());
            $errors[] = 'Row ' . ($i + 2) . ': Database error (duplicate or invalid data)';
        }
    }

    return compact('imported', 'skipped', 'errors');
}

// ── Import products ────────────────────────────────────────────────────────────
function importProducts(array $rows, PDO $pdo): array
{
    $imported = 0;
    $skipped  = 0;
    $errors   = [];

    $checkCode = $pdo->prepare(
        'SELECT id FROM products WHERE product_code = ? LIMIT 1'
    );
    $insert = $pdo->prepare(
        'INSERT INTO products (product_code, description, price, vat_rate, category, unit, active, created_at, updated_at)
         VALUES (?,?,?,?,?,?,1,NOW(),NOW())'
    );

    foreach ($rows as $i => $raw) {
        $r = array_change_key_case($raw, CASE_LOWER);

        $code = col($r, ['prodcode','code','product_code','productcode','productid']);
        $desc = col($r, ['proddesc','description','desc','order_description']);

        if ($desc === '') {
            $skipped++;
            continue;
        }

        // Skip duplicate product codes
        if ($code !== '') {
            $checkCode->execute([$code]);
            if ($checkCode->fetchColumn()) {
                $skipped++;
                continue;
            }
        }

        $priceRaw  = col($r, ['price','unitprice','unit_price','order_unit_pri']);
        $vatRaw    = col($r, ['vatrate','vat_rate','vat','order_taxe']);
        $category  = col($r, ['category','order_quickref']);
        $unit      = col($r, ['unit'], 'each');

        $price = round((float)$priceRaw, 2);

        $vatRate = (float)$vatRaw;
        if ($vatRate === 0.0 && $vatRaw === '') {
            $vatRate = 23.0;
        } elseif ($vatRate > 0 && $vatRate < 1) {
            $vatRate = round($vatRate * 100, 2);
        }
        if (!in_array((int)round($vatRate), [0, 9, 13, 23], true)) {
            $vatRate = 23.0;
        }

        try {
            $insert->execute([
                $code ?: null,
                $desc,
                $price,
                $vatRate,
                $category ?: null,
                $unit ?: 'each',
            ]);
            $imported++;
        } catch (PDOException $e) {
            error_log('import_csv products row ' . ($i + 2) . ': ' . $e->getMessage());
            $errors[] = 'Row ' . ($i + 2) . ': Database error (duplicate or invalid data)';
        }
    }

    return compact('imported', 'skipped', 'errors');
}

// ── Import invoices ────────────────────────────────────────────────────────────
function importInvoices(array $rows, PDO $pdo): array
{
    $imported = 0;
    $skipped  = 0;
    $errors   = [];

    // Build legacy_id → customer id map
    $custMap  = [];
    $custName = [];
    $custRows = $pdo->query('SELECT id, legacy_id, company_name FROM customers')->fetchAll();
    foreach ($custRows as $cr) {
        if ($cr['legacy_id'] !== null) {
            $custMap[(int)$cr['legacy_id']] = (int)$cr['id'];
        }
        $custName[strtolower((string)$cr['company_name'])] = (int)$cr['id'];
    }

    $checkInv = $pdo->prepare(
        'SELECT id FROM invoices WHERE invoice_number = ? LIMIT 1'
    );
    $insert = $pdo->prepare(
        'INSERT INTO invoices
         (invoice_number, customer_id, invoice_date, subtotal, total, balance, status,
          invoice_type, created_at, updated_at)
         VALUES (?,?,?,?,?,?,?,?,NOW(),NOW())'
    );

    foreach ($rows as $i => $raw) {
        $r = array_change_key_case($raw, CASE_LOWER);

        $invNo = col($r, ['invno','invoice_number','invoicenumber','inv_nu']);
        if ($invNo === '') {
            $skipped++;
            continue;
        }

        // Skip duplicate invoice numbers
        $checkInv->execute([$invNo]);
        if ($checkInv->fetchColumn()) {
            $skipped++;
            continue;
        }

        $rawDate = col($r, ['invdate','invoice_date','invoicedate','inv_date']);
        $invDate = date('Y-m-d');
        if ($rawDate !== '') {
            $ts = strtotime($rawDate);
            if ($ts) {
                $invDate = date('Y-m-d', $ts);
            }
        }

        $totalRaw  = col($r, ['total','amount','inv_total']);
        $total     = round((float)$totalRaw, 2);
        $statusRaw = col($r, ['status'], 'paid');
        $status    = in_array($statusRaw, ['paid','pending','overdue','void'], true) ? $statusRaw : 'paid';

        // Resolve customer
        $custId = null;
        $legacyCustId = col($r, ['custid','customer_id','clientid','legacy_client_id']);
        if ($legacyCustId !== '') {
            $custId = $custMap[(int)$legacyCustId] ?? null;
        }
        if ($custId === null) {
            $custNameRaw = col($r, ['custname','customer_name','company_name','client_name','name']);
            if ($custNameRaw !== '') {
                $custId = $custName[strtolower($custNameRaw)] ?? null;
            }
        }

        try {
            $insert->execute([
                $invNo,
                $custId,
                $invDate,
                $total,
                $total,
                $total,
                $status,
                'invoice',
            ]);
            $imported++;
        } catch (PDOException $e) {
            error_log('import_csv invoices row ' . ($i + 2) . ': ' . $e->getMessage());
            $errors[] = 'Row ' . ($i + 2) . ': Database error (duplicate or invalid data)';
        }
    }

    return compact('imported', 'skipped', 'errors');
}

// ── Dispatch ───────────────────────────────────────────────────────────────────
$rows = parseCsv($tmpPath);

if (empty($rows)) {
    jsonResponse(['success' => false, 'error' => 'CSV file is empty or could not be parsed'], 422);
}

$pdo = getDb();

switch ($type) {
    case 'customers':
        $result = importCustomers($rows, $pdo);
        break;
    case 'products':
        $result = importProducts($rows, $pdo);
        break;
    case 'invoices':
        $result = importInvoices($rows, $pdo);
        break;
}

jsonResponse([
    'success'  => true,
    'imported' => $result['imported'],
    'skipped'  => $result['skipped'],
    'errors'   => $result['errors'],
]);
