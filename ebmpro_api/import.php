<?php
/**
 * import.php — Unified importer for customers and products.
 * Supports CSV and XML formats.
 *
 * POST multipart/form-data:
 *   type   — 'customers' | 'products'
 *   format — 'csv' | 'xml'
 *   file   — CSV or XML file
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

$type   = strtolower(trim($_POST['type']   ?? ''));
$format = strtolower(trim($_POST['format'] ?? 'csv'));

if (!in_array($type, ['customers', 'products'], true)) {
    jsonResponse(['success' => false, 'error' => 'Invalid type. Must be customers or products'], 422);
}
if (!in_array($format, ['csv', 'xml'], true)) {
    jsonResponse(['success' => false, 'error' => 'Invalid format. Must be csv or xml'], 422);
}

if (empty($_FILES['file']['tmp_name'])) {
    jsonResponse(['success' => false, 'error' => 'No file uploaded'], 422);
}

$ext = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION));
if ($ext !== $format) {
    jsonResponse(['success' => false, 'error' => "File extension must match format: .$format"], 422);
}

define('MAX_IMPORT_FILE_SIZE', 134217728); // 128 MB
if ($_FILES['file']['size'] > MAX_IMPORT_FILE_SIZE) {
    jsonResponse(['success' => false, 'error' => 'File exceeds 128 MB limit'], 422);
}

$tmpPath = $_FILES['file']['tmp_name'];
function parseXmlProducts(string $path): array
{
    $xml = @simplexml_load_file($path);
    if ($xml === false) {
        return [];
    }
    $rows = [];
    foreach ($xml->product as $p) {
        $rows[] = [
            'name'             => (string)$p->name,
            'sku'              => (string)$p->sku,
            'price'            => (string)$p->price,
            'vat_rate'         => (string)$p->vat_rate,
            'stock_quantity'   => (string)$p->stock_quantity,
            'description'      => (string)$p->description,
        ];
    }
    return $rows;
}

function parseXmlCustomers(string $path): array
{
    $xml = @simplexml_load_file($path);
    if ($xml === false) {
        return [];
    }
    $rows = [];
    foreach ($xml->customer as $c) {
        $rows[] = [
            'name'    => (string)$c->name,
            'email'   => (string)$c->email,
            'phone'   => (string)$c->phone,
            'address' => (string)$c->address,
            'town'    => (string)$c->town,
            'county'  => (string)$c->county,
            'eircode' => (string)$c->eircode,
        ];
    }
    return $rows;
}

// ── Importers ──────────────────────────────────────────────────────────────────
function importProductsFromRows(array $rows, PDO $pdo, bool $isXml): array
{
    $imported = 0;
    $skipped  = 0;
    $errors   = [];

    $checkCode = $pdo->prepare('SELECT id FROM products WHERE product_code = ? LIMIT 1');
    $insert    = $pdo->prepare(
        'INSERT INTO products (product_code, description, price, vat_rate, active, created_at, updated_at)
         VALUES (?,?,?,?,1,NOW(),NOW())'
    );

    foreach ($rows as $i => $row) {
        if ($isXml) {
            $code  = trim($row['sku'] ?? '');
            $desc  = trim($row['name'] ?? ($row['description'] ?? ''));
            $price = (float)($row['price'] ?? 0);
            $vat   = (float)($row['vat_rate'] ?? 23);
        } else {
            $r     = array_change_key_case($row, CASE_LOWER);
            $code  = trim($r['product_code'] ?? ($r['sku'] ?? ($r['code'] ?? '')));
            $desc  = trim($r['name'] ?? ($r['description'] ?? ''));
            $price = (float)($r['price'] ?? 0);
            $vat   = (float)($r['vat_rate'] ?? 23);
        }

        if ($desc === '') { $skipped++; continue; }

        if ($code !== '') {
            $checkCode->execute([$code]);
            if ($checkCode->fetchColumn()) { $skipped++; continue; }
        }

        try {
            $insert->execute([$code ?: null, $desc, round($price, 2), round($vat, 2)]);
            $imported++;
        } catch (PDOException $e) {
            $errors[] = 'Row ' . ($i + 1) . ': ' . $e->getMessage();
        }
    }
    return compact('imported', 'skipped', 'errors');
}

function importCustomersFromRows(array $rows, PDO $pdo, bool $isXml): array
{
    $imported = 0;
    $skipped  = 0;
    $errors   = [];

    $checkEmail = $pdo->prepare('SELECT id FROM customers WHERE email_address = ? LIMIT 1');
    $insert     = $pdo->prepare(
        'INSERT INTO customers (company_name, email_address, inv_telephone, address_1, town, county, inv_postcode, account_no, created_at, updated_at)
         VALUES (?,?,?,?,?,?,?,?,NOW(),NOW())'
    );

    foreach ($rows as $i => $row) {
        if ($isXml) {
            $name    = trim($row['name'] ?? '');
            $email   = trim($row['email'] ?? '');
            $phone   = trim($row['phone'] ?? '');
            $address = trim($row['address'] ?? '');
            $town    = trim($row['town'] ?? '');
            $county  = trim($row['county'] ?? '');
            $eircode = trim($row['eircode'] ?? '');
        } else {
            $r       = array_change_key_case($row, CASE_LOWER);
            $name    = trim($r['name'] ?? ($r['company_name'] ?? ''));
            $email   = trim($r['email'] ?? ($r['email_address'] ?? ''));
            $phone   = trim($r['phone'] ?? ($r['telephone'] ?? ''));
            $address = trim($r['address'] ?? ($r['address_1'] ?? ''));
            $town    = trim($r['town'] ?? '');
            $county  = trim($r['county'] ?? '');
            $eircode = trim($r['eircode'] ?? ($r['postcode'] ?? ''));
        }

        if ($name === '' && $email === '') { $skipped++; continue; }

        if ($email !== '') {
            $checkEmail->execute([$email]);
            if ($checkEmail->fetchColumn()) { $skipped++; continue; }
        }

        $accountNo = 'CUS-' . str_pad((string)random_int(10000, 99999), 5, '0', STR_PAD_LEFT);
        try {
            $insert->execute([$name, $email ?: null, $phone ?: null, $address ?: null, $town ?: null, $county ?: null, $eircode ?: null, $accountNo]);
            $imported++;
        } catch (PDOException $e) {
            $errors[] = 'Row ' . ($i + 1) . ': ' . $e->getMessage();
        }
    }
    return compact('imported', 'skipped', 'errors');
}

// ── Dispatch ───────────────────────────────────────────────────────────────────
$pdo = getDb();

if ($format === 'xml') {
    if ($type === 'products') {
        $rows   = parseXmlProducts($tmpPath);
        $isXml  = true;
    } else {
        $rows   = parseXmlCustomers($tmpPath);
        $isXml  = true;
    }
    if (empty($rows)) {
        jsonResponse(['success' => false, 'error' => 'XML file is empty or could not be parsed'], 422);
    }
    if ($type === 'products') {
        $result = importProductsFromRows($rows, $pdo, $isXml);
    } else {
        $result = importCustomersFromRows($rows, $pdo, $isXml);
    }
} else {
    // CSV — re-implement CSV parsing inline
    // $ext is already validated as 'csv' at line 42
    // Parse CSV
    $rows = [];
    $fh   = fopen($tmpPath, 'r');
    if ($fh) {
        $bom = fread($fh, 3);
        if ($bom !== "\xEF\xBB\xBF") rewind($fh);
        $headers = null;
        while (($line = fgetcsv($fh)) !== false) {
            if ($headers === null) { $headers = array_map('trim', $line); continue; }
            $rows[] = array_combine($headers, array_pad(array_slice($line, 0, count($headers)), count($headers), ''));
        }
        fclose($fh);
    }
    if (empty($rows)) {
        jsonResponse(['success' => false, 'error' => 'CSV file is empty or could not be parsed'], 422);
    }
    if ($type === 'products') {
        $result = importProductsFromRows($rows, $pdo, false);
    } else {
        $result = importCustomersFromRows($rows, $pdo, false);
    }
}

jsonResponse([
    'success'  => true,
    'imported' => $result['imported'],
    'skipped'  => $result['skipped'],
    'errors'   => $result['errors'],
]);
