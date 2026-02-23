<?php
/**
 * import_invoices.php — Chunked XML/CSV invoice importer
 *
 * POST multipart/form-data:
 *   file     — XML or CSV (from tbl_invoice export)
 *   store_code (optional) — FAL or GWE
 *
 * Column mapping from tbl_invoice:
 *   inv_id               → legacy_id
 *   inv_nu               → invoice_number
 *   ClientID             → legacy_client_id
 *   inv_date             → invoice_date
 *   inv_due_             → due_date
 *   inv_Payment_method   → payment_method
 *   tot_tax_n            → vat_number
 *   tot_tax_v            → vat_total
 *   tot_delivery_        → delivery_charge
 *   inv_quote            → is_quote
 *   Client address fields → snapshot on invoice
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
$storeCode = strtoupper(trim($_POST['store_code'] ?? 'FAL'));

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
    $tmpDest = sys_get_temp_dir() . '/ebm_import_inv_' . session_id() . '.' . $ext;
    if (!move_uploaded_file($_FILES['file']['tmp_name'], $tmpDest)) {
        jsonResponse(['success' => false, 'error' => 'Failed to save uploaded file'], 500);
    }
    $_SESSION['import_invoices_file'] = $tmpDest;
    $_SESSION['import_invoices_ext']  = $ext;
} else {
    $tmpDest = $_SESSION['import_invoices_file'] ?? '';
    $ext     = $_SESSION['import_invoices_ext']  ?? '';
    if (!$tmpDest || !file_exists($tmpDest)) {
        jsonResponse(['success' => false, 'error' => 'Upload session expired — please re-upload'], 410);
    }
}

function parseInvoiceRows(string $filePath, string $ext): array
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
        $items = $xml->invoice ?? $xml->tbl_invoice ?? $xml->row ?? $xml->children();
        foreach ($items as $item) {
            $rows[] = json_decode(json_encode($item), true);
        }
    }
    return $rows;
}

function mapInvoiceRow(array $row, string $storeCode): array
{
    $r = array_change_key_case($row, CASE_LOWER);

    $legacyId       = !empty($r['inv_id'])   ? (int)$r['inv_id']   : null;
    $invoiceNum     = trim((string)($r['inv_nu'] ?? $r['invoice_number'] ?? ''));
    $legacyClientId = !empty($r['clientid']) ? (int)$r['clientid'] : null;

    $rawDate = $r['inv_date'] ?? $r['invoice_date'] ?? '';
    $invDate = '';
    if ($rawDate) {
        $ts = strtotime((string)$rawDate);
        $invDate = $ts ? date('Y-m-d', $ts) : date('Y-m-d');
    } else {
        $invDate = date('Y-m-d');
    }

    $rawDue  = $r['inv_due_'] ?? $r['due_date'] ?? '';
    $dueDate = '';
    if ($rawDue) {
        $ts = strtotime((string)$rawDue);
        $dueDate = $ts ? date('Y-m-d', $ts) : null;
    }

    $payMethod      = trim((string)($r['inv_payment_method'] ?? $r['payment_method'] ?? ''));
    $vatNumber      = trim((string)($r['tot_tax_n'] ?? $r['vat_number'] ?? ''));
    $vatTotal       = round((float)($r['tot_tax_v'] ?? $r['vat_total'] ?? 0), 2);
    $deliveryCharge = round((float)($r['tot_delivery_'] ?? $r['delivery_charge'] ?? 0), 2);
    $isQuote        = (int)(bool)($r['inv_quote'] ?? $r['is_quote'] ?? 0);

    // Client address snapshot
    $town     = trim((string)($r['inv_town']    ?? $r['town']    ?? ''));
    $region   = trim((string)($r['inv_region']  ?? $r['region']  ?? ''));
    $postcode = trim((string)($r['inv_postcode'] ?? $r['postcode'] ?? ''));
    $email    = trim((string)($r['email_address'] ?? $r['email'] ?? ''));
    $phone    = trim((string)($r['inv_telephone'] ?? $r['telephone'] ?? ''));

    return [
        'legacy_id'        => $legacyId,
        'invoice_number'   => $invoiceNum ?: ($storeCode . '-' . str_pad((string)abs($legacyId ?? 0), 5, '0', STR_PAD_LEFT)),
        'legacy_client_id' => $legacyClientId,
        'invoice_date'     => $invDate,
        'due_date'         => $dueDate ?: null,
        'payment_method'   => $payMethod ?: null,
        'vat_number'       => $vatNumber ?: null,
        'vat_total'        => $vatTotal,
        'delivery_charge'  => $deliveryCharge,
        'is_quote'         => $isQuote,
        'inv_town'         => $town ?: null,
        'inv_region'       => $region ?: null,
        'inv_postcode'     => $postcode ?: null,
        'email_address'    => $email ?: null,
        'inv_telephone'    => $phone ?: null,
        'store_code'       => $storeCode,
        'invoice_type'     => $isQuote ? 'quote' : 'invoice',
        'status'           => 'pending',
    ];
}

$allRows = parseInvoiceRows($tmpDest, $ext);
$total   = count($allRows);
$chunk   = array_slice($allRows, $offset, $chunkSize);

// Pre-build customer legacy_id → id lookup
$custMap = [];
$custRows = $pdo->query('SELECT id, legacy_id FROM customers WHERE legacy_id IS NOT NULL')->fetchAll();
foreach ($custRows as $cr) {
    $custMap[(int)$cr['legacy_id']] = (int)$cr['id'];
}

$checkStmt = $pdo->prepare(
    'SELECT id FROM invoices WHERE legacy_id = ? LIMIT 1'
);
$insertStmt = $pdo->prepare(
    'INSERT INTO invoices
     (legacy_id, invoice_number, invoice_type, store_code, customer_id, legacy_client_id,
      invoice_date, due_date, payment_method, vat_number, vat_total, delivery_charge,
      is_quote, inv_town, inv_region, inv_postcode, email_address, inv_telephone,
      subtotal, total, balance, status, created_at, updated_at)
     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,0,?,?,?,NOW(),NOW())'
);

$inserted = 0;
$skipped  = 0;
$errors   = 0;

foreach ($chunk as $row) {
    $m = mapInvoiceRow($row, $storeCode);

    if (empty($m['invoice_number'])) {
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

    // Resolve customer_id from legacy map
    $customerId = null;
    if (!empty($m['legacy_client_id']) && isset($custMap[$m['legacy_client_id']])) {
        $customerId = $custMap[$m['legacy_client_id']];
    }

    $total_val = round($m['vat_total'] + $m['delivery_charge'], 2);

    try {
        $insertStmt->execute([
            $m['legacy_id'],
            $m['invoice_number'],
            $m['invoice_type'],
            $m['store_code'],
            $customerId,
            $m['legacy_client_id'],
            $m['invoice_date'],
            $m['due_date'],
            $m['payment_method'],
            $m['vat_number'],
            $m['vat_total'],
            $m['delivery_charge'],
            $m['is_quote'],
            $m['inv_town'],
            $m['inv_region'],
            $m['inv_postcode'],
            $m['email_address'],
            $m['inv_telephone'],
            $total_val,
            $total_val,
            $m['status'],
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
    unset($_SESSION['import_invoices_file'], $_SESSION['import_invoices_ext']);
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
