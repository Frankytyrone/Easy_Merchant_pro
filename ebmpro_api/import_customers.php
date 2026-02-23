<?php
/**
 * import_customers.php — Chunked XML/CSV customer importer
 *
 * POST multipart/form-data:
 *   file  — XML or CSV file (from tbl_clients export)
 *
 * Column mapping from Easy Invoicing tbl_clients:
 *   client_id      → legacy_id
 *   client_name    → company_name
 *   alternative    → contact_name
 *   address_1..3   → address_1..3
 *   town           → town / inv_town
 *   region         → county / inv_region
 *   postcode       → postcode / inv_postcode
 *   telephone (x2) → phone / inv_telephone, phone2
 *   email_address  → email_address
 *   account_r      → account_ref / account_no
 *   notes          → notes
 *   del_*          → del_* delivery fields
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

$pdo = getDb();
session_start();

$offset    = max(0, (int)($_POST['offset'] ?? 0));
$chunkSize = 500;
$storeId   = !empty($_POST['store_id']) ? (int)$_POST['store_id'] : null;

if ($offset === 0) {
    if (empty($_FILES['file']['tmp_name'])) {
        jsonResponse(['success' => false, 'error' => 'No file uploaded'], 422);
    }
    $ext = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['xml', 'csv'], true)) {
        jsonResponse(['success' => false, 'error' => 'Only XML or CSV files are accepted'], 422);
    }
    if ($_FILES['file']['size'] > 67108864) {
        jsonResponse(['success' => false, 'error' => 'File exceeds 64 MB limit'], 422);
    }
    $tmpDest = sys_get_temp_dir() . '/ebm_import_cust_' . session_id() . '.' . $ext;
    if (!move_uploaded_file($_FILES['file']['tmp_name'], $tmpDest)) {
        jsonResponse(['success' => false, 'error' => 'Failed to save uploaded file'], 500);
    }
    $_SESSION['import_customers_file'] = $tmpDest;
    $_SESSION['import_customers_ext']  = $ext;
} else {
    $tmpDest = $_SESSION['import_customers_file'] ?? '';
    $ext     = $_SESSION['import_customers_ext']  ?? '';
    if (!$tmpDest || !file_exists($tmpDest)) {
        jsonResponse(['success' => false, 'error' => 'Upload session expired — please re-upload'], 410);
    }
}

function parseCustomerRows(string $filePath, string $ext): array
{
    $rows = [];
    if ($ext === 'csv') {
        $fh = fopen($filePath, 'r');
        if (!$fh) {
            return $rows;
        }
        $headers = null;
        while (($line = fgetcsv($fh)) !== false) {
            if ($headers === null) {
                $headers = array_map('trim', $line);
                continue;
            }
            $rows[] = array_combine($headers, array_pad($line, count($headers), ''));
        }
        fclose($fh);
    } else {
        libxml_use_internal_errors(true);
        $xml = simplexml_load_file($filePath);
        if ($xml === false) {
            return $rows;
        }
        $items = $xml->client ?? $xml->tbl_clients ?? $xml->row ?? $xml->customer ?? $xml->children();
        foreach ($items as $item) {
            $rows[] = json_decode(json_encode($item), true);
        }
    }
    return $rows;
}

function mapCustomerRow(array $row): array
{
    $r = array_change_key_case($row, CASE_LOWER);

    $legacyId   = !empty($r['client_id'])  ? (int)$r['client_id']  : null;
    $company    = trim((string)($r['client_name'] ?? $r['company_name'] ?? ''));
    $contact    = trim((string)($r['alternative'] ?? $r['contact_name'] ?? ''));
    $address1   = trim((string)($r['address_1'] ?? $r['address1'] ?? ''));
    $address2   = trim((string)($r['address_2'] ?? $r['address2'] ?? ''));
    $address3   = trim((string)($r['address_3'] ?? $r['address3'] ?? ''));
    $town       = trim((string)($r['town'] ?? ''));
    $county     = trim((string)($r['region'] ?? $r['county'] ?? ''));
    $postcode   = trim((string)($r['postcode'] ?? ''));
    $phone      = trim((string)($r['telephone'] ?? $r['telephone_1'] ?? $r['phone'] ?? ''));
    $phone2     = trim((string)($r['telephone_2'] ?? $r['phone2'] ?? ''));
    $email      = trim((string)($r['email_address'] ?? $r['email'] ?? ''));
    $accountRef = trim((string)($r['account_r'] ?? $r['account_ref'] ?? $r['account_no'] ?? ''));
    $notes      = trim((string)($r['notes'] ?? ''));

    // Delivery address
    $delName    = trim((string)($r['del_client_name']   ?? $r['delivery_name']    ?? ''));
    $delContact = trim((string)($r['del_alternative']   ?? $r['delivery_contact'] ?? ''));
    $delAddr1   = trim((string)($r['del_address_1']     ?? $r['del_address1']     ?? ''));
    $delAddr2   = trim((string)($r['del_address_2']     ?? $r['del_address2']     ?? ''));
    $delAddr3   = trim((string)($r['del_address_3']     ?? $r['del_address3']     ?? ''));
    $delTown    = trim((string)($r['del_town']           ?? ''));
    $delCounty  = trim((string)($r['del_region']         ?? $r['del_county']      ?? ''));
    $delPost    = trim((string)($r['del_postcode']       ?? ''));

    return [
        'legacy_id'        => $legacyId,
        'company_name'     => $company,
        'contact_name'     => $contact ?: null,
        'address_1'        => $address1 ?: null,
        'address_2'        => $address2 ?: null,
        'address_3'        => $address3 ?: null,
        'town'             => $town ?: null,
        'inv_town'         => $town ?: null,
        'county'           => $county ?: null,
        'inv_region'       => $county ?: null,
        'postcode'         => $postcode ?: null,
        'inv_postcode'     => $postcode ?: null,
        'phone'            => $phone ?: null,
        'inv_telephone'    => $phone ?: null,
        'phone2'           => $phone2 ?: null,
        'email_address'    => $email ?: null,
        'account_ref'      => $accountRef ?: null,
        'notes'            => $notes ?: null,
        'del_client_name'  => $delName ?: null,
        'del_contact_name' => $delContact ?: null,
        'del_address_1'    => $delAddr1 ?: null,
        'del_address_2'    => $delAddr2 ?: null,
        'del_address_3'    => $delAddr3 ?: null,
        'del_town'         => $delTown ?: null,
        'del_county'       => $delCounty ?: null,
        'del_postcode'     => $delPost ?: null,
    ];
}

$allRows = parseCustomerRows($tmpDest, $ext);
$total   = count($allRows);
$chunk   = array_slice($allRows, $offset, $chunkSize);

$checkStmt = $pdo->prepare(
    'SELECT id FROM customers WHERE legacy_id = ? LIMIT 1'
);
$insertStmt = $pdo->prepare(
    'INSERT INTO customers
     (legacy_id, company_name, contact_name, address_1, address_2, address_3,
      town, inv_town, county, inv_region, postcode, inv_postcode,
      phone, inv_telephone, phone2, email_address, account_ref, account_no,
      notes, del_client_name, del_contact_name, del_address_1, del_address_2, del_address_3,
      del_town, del_county, del_postcode, store_id, created_at, updated_at)
     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())'
);

$inserted = 0;
$skipped  = 0;
$errors   = 0;

foreach ($chunk as $row) {
    $m = mapCustomerRow($row);

    if (empty($m['company_name'])) {
        $skipped++;
        continue;
    }

    // Duplicate check by legacy_id
    if (!empty($m['legacy_id'])) {
        $checkStmt->execute([$m['legacy_id']]);
        if ($checkStmt->fetchColumn()) {
            $skipped++;
            continue;
        }
    }

    // Generate account_no from account_ref or sequential fallback
    $accountNo = !empty($m['account_ref'])
        ? $m['account_ref']
        : 'CUS-' . str_pad(random_int(10000, 99999), 5, '0', STR_PAD_LEFT);

    try {
        $insertStmt->execute([
            $m['legacy_id'],
            $m['company_name'],
            $m['contact_name'],
            $m['address_1'],
            $m['address_2'],
            $m['address_3'],
            $m['town'],
            $m['inv_town'],
            $m['county'],
            $m['inv_region'],
            $m['postcode'],
            $m['inv_postcode'],
            $m['phone'],
            $m['inv_telephone'],
            $m['phone2'],
            $m['email_address'],
            $m['account_ref'],
            $accountNo,
            $m['notes'],
            $m['del_client_name'],
            $m['del_contact_name'],
            $m['del_address_1'],
            $m['del_address_2'],
            $m['del_address_3'],
            $m['del_town'],
            $m['del_county'],
            $m['del_postcode'],
            $storeId,
        ]);
        $inserted++;
    } catch (PDOException $e) {
        $errors++;
    }
}

$processed  = $offset + count($chunk);
$done       = $processed >= $total;
$percentage = $total > 0 ? (int)round($processed / $total * 100) : 100;

if ($done) {
    @unlink($tmpDest);
    unset($_SESSION['import_customers_file'], $_SESSION['import_customers_ext']);
}

jsonResponse([
    'success'    => true,
    'inserted'   => $inserted,
    'skipped'    => $skipped,
    'errors'     => $errors,
    'total'      => $total,
    'processed'  => $processed,
    'percentage' => $percentage,
    'done'       => $done,
    'message'    => $done
        ? "Import complete: {$inserted} inserted, {$skipped} skipped, {$errors} errors"
        : "Processing… {$processed}/{$total} ({$percentage}%)",
]);
