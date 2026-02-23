<?php
/**
 * import_payments.php — Chunked XML/CSV payment importer
 *
 * POST multipart/form-data:
 *   file  — XML or CSV (from tbl_payments export)
 *
 * Column mapping from tbl_payments:
 *   pay_id      → legacy_id
 *   pay_link    → legacy_invoice_id
 *   pay_date    → payment_date
 *   pay_amount  → amount
 *   pay_details → notes
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
$chunkSize = 10000;

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
    $tmpDest = sys_get_temp_dir() . '/ebm_import_pay_' . session_id() . '.' . $ext;
    if (!move_uploaded_file($_FILES['file']['tmp_name'], $tmpDest)) {
        jsonResponse(['success' => false, 'error' => 'Failed to save uploaded file'], 500);
    }
    $_SESSION['import_payments_file'] = $tmpDest;
    $_SESSION['import_payments_ext']  = $ext;
} else {
    $tmpDest = $_SESSION['import_payments_file'] ?? '';
    $ext     = $_SESSION['import_payments_ext']  ?? '';
    if (!$tmpDest || !file_exists($tmpDest)) {
        jsonResponse(['success' => false, 'error' => 'Upload session expired — please re-upload'], 410);
    }
}

function parsePaymentRows(string $filePath, string $ext): array
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
        $items = $xml->payment ?? $xml->tbl_payments ?? $xml->row ?? $xml->children();
        foreach ($items as $item) {
            $rows[] = json_decode(json_encode($item), true);
        }
    }
    return $rows;
}

function mapPaymentRow(array $row): array
{
    $r = array_change_key_case($row, CASE_LOWER);

    $legacyId        = !empty($r['pay_id'])   ? (int)$r['pay_id']   : null;
    $legacyInvoiceId = !empty($r['pay_link'])  ? (int)$r['pay_link'] : null;
    $rawDate         = $r['pay_date'] ?? $r['payment_date'] ?? '';
    $payDate         = '';
    if ($rawDate) {
        $ts = strtotime((string)$rawDate);
        $payDate = $ts ? date('Y-m-d', $ts) : date('Y-m-d');
    } else {
        $payDate = date('Y-m-d');
    }
    $amount = round((float)($r['pay_amount'] ?? $r['amount'] ?? 0), 2);
    $notes  = trim((string)($r['pay_details'] ?? $r['notes'] ?? ''));

    return [
        'legacy_id'         => $legacyId,
        'legacy_invoice_id' => $legacyInvoiceId,
        'payment_date'      => $payDate,
        'amount'            => $amount,
        'notes'             => $notes ?: null,
    ];
}

$allRows = parsePaymentRows($tmpDest, $ext);
$total   = count($allRows);
$chunk   = array_slice($allRows, $offset, $chunkSize);

// Build legacy invoice_id → actual invoice.id lookup
$invMap = [];
$invRows = $pdo->query('SELECT id, legacy_id FROM invoices WHERE legacy_id IS NOT NULL')->fetchAll();
foreach ($invRows as $ir) {
    $invMap[(int)$ir['legacy_id']] = (int)$ir['id'];
}

$checkStmt = $pdo->prepare(
    'SELECT id FROM payments WHERE legacy_id = ? LIMIT 1'
);
$insertStmt = $pdo->prepare(
    'INSERT INTO payments
     (legacy_id, legacy_invoice_id, invoice_id, payment_date, amount, method, notes, created_at)
     VALUES (?,?,?,?,?,\'other\',?,NOW())'
);

$inserted = 0;
$skipped  = 0;
$errors   = 0;

foreach ($chunk as $row) {
    $m = mapPaymentRow($row);

    if ($m['amount'] <= 0) {
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

    // Resolve invoice_id from legacy map
    $invoiceId = null;
    if (!empty($m['legacy_invoice_id']) && isset($invMap[$m['legacy_invoice_id']])) {
        $invoiceId = $invMap[$m['legacy_invoice_id']];
    }

    if ($invoiceId === null) {
        // Cannot link payment to an invoice — skip
        $skipped++;
        continue;
    }

    try {
        $insertStmt->execute([
            $m['legacy_id'],
            $m['legacy_invoice_id'],
            $invoiceId,
            $m['payment_date'],
            $m['amount'],
            $m['notes'],
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
    unset($_SESSION['import_payments_file'], $_SESSION['import_payments_ext']);
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
