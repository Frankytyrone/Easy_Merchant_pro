<?php
/**
 * import_products.php — Chunked XML/CSV product importer
 *
 * POST multipart/form-data:
 *   file  — XML or CSV file
 *   store_id (optional)
 *
 * Returns JSON:
 *   { success, inserted, skipped, errors, total, message }
 *
 * Supports chunked uploads: send ?offset=N&total=M to process in batches.
 * The file is uploaded once; the server reads it from a temp location stored
 * in the session between chunk calls.
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

// ── Receive the uploaded file (first request) or re-use cached path ───────────
session_start();

$offset    = max(0, (int)($_POST['offset'] ?? 0));
$chunkSize = 10000;
$storeId   = !empty($_POST['store_id']) ? (int)$_POST['store_id'] : null;

if ($offset === 0) {
    // Fresh upload
    if (empty($_FILES['file']['tmp_name'])) {
        jsonResponse(['success' => false, 'error' => 'No file uploaded'], 422);
    }
    $ext = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['xml', 'csv'], true)) {
        jsonResponse(['success' => false, 'error' => 'Only XML or CSV files are accepted'], 422);
    }
    if ($_FILES['file']['size'] > 67108864) { // 64 MB
        jsonResponse(['success' => false, 'error' => 'File exceeds 64 MB limit'], 422);
    }

    $tmpDest = sys_get_temp_dir() . '/ebm_import_' . session_id() . '.' . $ext;
    if (!move_uploaded_file($_FILES['file']['tmp_name'], $tmpDest)) {
        jsonResponse(['success' => false, 'error' => 'Failed to save uploaded file'], 500);
    }
    $_SESSION['import_products_file'] = $tmpDest;
    $_SESSION['import_products_ext']  = $ext;
} else {
    // Subsequent chunk — re-use cached file
    $tmpDest = $_SESSION['import_products_file'] ?? '';
    $ext     = $_SESSION['import_products_ext']  ?? '';
    if (!$tmpDest || !file_exists($tmpDest)) {
        jsonResponse(['success' => false, 'error' => 'Upload session expired — please re-upload'], 410);
    }
}

// ── Parse all rows into a flat array ─────────────────────────────────────────
function parseProductRows(string $filePath, string $ext): array
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
        // XML
        libxml_use_internal_errors(true);
        $xml = simplexml_load_file($filePath);
        if ($xml === false) {
            return $rows;
        }
        // Support both <products><product>…</product></products>
        // and <dataroot><tbl_products>…</tbl_products></dataroot> (Access export)
        $items = $xml->product ?? $xml->tbl_products ?? $xml->row ?? $xml->item ?? $xml->children();
        foreach ($items as $item) {
            $rows[] = json_decode(json_encode($item), true);
        }
    }
    return $rows;
}

$allRows = parseProductRows($tmpDest, $ext);
$total   = count($allRows);
$chunk   = array_slice($allRows, $offset, $chunkSize);

// ── Map columns from Easy Invoicing → EBM Pro ────────────────────────────────
function mapProductRow(array $row): array
{
    // Normalise keys to lower-case
    $r = array_change_key_case($row, CASE_LOWER);

    $productCode = trim((string)($r['productid']  ?? $r['product_code'] ?? $r['product id'] ?? ''));
    $barcode     = trim((string)($r['productscod'] ?? $r['barcode'] ?? ''));
    $description = trim((string)($r['order_description'] ?? $r['description'] ?? $r['desc'] ?? ''));
    $category    = trim((string)($r['order_quickref']    ?? $r['category'] ?? ''));
    $vatRate     = (float)($r['order_taxe'] ?? $r['vat_rate'] ?? 23);
    $stockQty    = (float)($r['order_quant'] ?? $r['stock_qty'] ?? 0);
    $price       = round((float)($r['order_unit_pri'] ?? $r['price'] ?? $r['unit_price'] ?? 0), 2);

    // Normalise VAT: only 0 or 23 are valid for Irish context
    if ($vatRate !== 0.0 && $vatRate < 1) {
        // Stored as decimal fraction (e.g. 0.23) — convert
        $vatRate = round($vatRate * 100, 2);
    }
    if (!in_array((int)$vatRate, [0, 9, 13, 23], true)) {
        $vatRate = 23.0;
    }

    return [
        'product_code' => $productCode ?: null,
        'barcode'      => $barcode ?: null,
        'description'  => $description,
        'category'     => $category ?: null,
        'price'        => $price,
        'vat_rate'     => $vatRate,
        'stock_qty'    => $stockQty,
    ];
}

// ── Prepare insert / duplicate-check statements ───────────────────────────────
$checkStmt = $pdo->prepare(
    'SELECT id FROM products WHERE product_code = ? AND product_code IS NOT NULL LIMIT 1'
);
$insertStmt = $pdo->prepare(
    'INSERT INTO products (product_code, barcode, description, category, price, vat_rate, stock_qty, store_id, active)
     VALUES (?,?,?,?,?,?,?,?,1)'
);

$inserted = 0;
$skipped  = 0;
$errors   = 0;

foreach ($chunk as $row) {
    $mapped = mapProductRow($row);

    if (empty($mapped['description'])) {
        $skipped++;
        continue;
    }

    // Duplicate check by product_code
    if (!empty($mapped['product_code'])) {
        $checkStmt->execute([$mapped['product_code']]);
        if ($checkStmt->fetchColumn()) {
            $skipped++;
            continue;
        }
    }

    try {
        $insertStmt->execute([
            $mapped['product_code'],
            $mapped['barcode'],
            $mapped['description'],
            $mapped['category'],
            $mapped['price'],
            $mapped['vat_rate'],
            $mapped['stock_qty'],
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
    // Clean up temp file
    @unlink($tmpDest);
    unset($_SESSION['import_products_file'], $_SESSION['import_products_ext']);
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
