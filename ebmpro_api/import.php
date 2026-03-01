<?php
/**
 * import.php — Unified CSV + XML importer for products and customers.
 *
 * POST multipart/form-data:
 *   file      — CSV or XML file
 *   type      — 'products' | 'customers'
 *   format    — 'csv' | 'xml'
 *   store_id  — optional, for products
 *
 * Query params may also supply ?type= and ?format= as alternatives.
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

$type    = strtolower(trim($_POST['type']   ?? $_GET['type']   ?? ''));
$format  = strtolower(trim($_POST['format'] ?? $_GET['format'] ?? ''));
$storeId = !empty($_POST['store_id']) ? (int)$_POST['store_id'] : null;

if (!in_array($type, ['products', 'customers'], true)) {
    jsonResponse(['success' => false, 'error' => "Invalid type. Use 'products' or 'customers'"], 422);
}
if (!in_array($format, ['csv', 'xml'], true)) {
    jsonResponse(['success' => false, 'error' => "Invalid format. Use 'csv' or 'xml'"], 422);
}

if (empty($_FILES['file']['tmp_name'])) {
    jsonResponse(['success' => false, 'error' => 'No file uploaded'], 422);
}
if ($_FILES['file']['size'] > 67108864) {
    jsonResponse(['success' => false, 'error' => 'File exceeds 64 MB limit'], 422);
}

$ext = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION));
if ($ext !== $format) {
    jsonResponse(['success' => false, 'error' => "File extension does not match format '{$format}'"], 422);
}

$tmpPath = sys_get_temp_dir() . '/ebm_import_' . bin2hex(random_bytes(8)) . '.' . $ext;
if (!move_uploaded_file($_FILES['file']['tmp_name'], $tmpPath)) {
    jsonResponse(['success' => false, 'error' => 'Failed to save uploaded file'], 500);
}

try {
    $pdo = getDb();

    // ── Parse rows from CSV or XML ────────────────────────────────────────────
    $rows = [];

    if ($format === 'csv') {
        $fh = fopen($tmpPath, 'r');
        if ($fh) {
            $headers = null;
            while (($line = fgetcsv($fh)) !== false) {
                if ($headers === null) {
                    $headers = array_map('trim', $line);
                    continue;
                }
                $rows[] = array_combine($headers, array_pad(array_slice($line, 0, count($headers)), count($headers), ''));
            }
            fclose($fh);
        }
    } else {
        // XML
        libxml_use_internal_errors(true);
        $xml = simplexml_load_file($tmpPath);
        if ($xml === false) {
            @unlink($tmpPath);
            jsonResponse(['success' => false, 'error' => 'Invalid XML file'], 422);
        }
        $rootTag = ($type === 'products') ? 'product' : 'customer';
        $children = $xml->{$rootTag} ?? $xml->children();
        foreach ($children as $child) {
            $rows[] = json_decode(json_encode($child), true);
        }
    }

    if (empty($rows)) {
        @unlink($tmpPath);
        jsonResponse(['success' => false, 'imported' => 0, 'skipped' => 0, 'errors' => ['No data rows found in file']]);
    }

    $imported = 0;
    $skipped  = 0;
    $errors   = [];

    if ($type === 'products') {
        $checkStmt = $pdo->prepare(
            'SELECT id FROM products WHERE product_code = ? AND product_code IS NOT NULL LIMIT 1'
        );
        $insertStmt = $pdo->prepare(
            'INSERT INTO products
             (product_code, description, price, vat_rate, stock_qty, store_id, active)
             VALUES (?,?,?,?,?,?,1)'
        );

        foreach ($rows as $idx => $row) {
            $r           = array_change_key_case($row, CASE_LOWER);
            $code        = trim((string)($r['sku'] ?? $r['product_code'] ?? $r['code'] ?? ''));
            $description = trim((string)($r['name'] ?? $r['description'] ?? $r['desc'] ?? ''));
            $price       = round((float)($r['price'] ?? $r['unit_price'] ?? 0), 2);
            $vatRate     = (float)($r['vat_rate'] ?? 23);
            $stockQty    = (float)($r['stock_quantity'] ?? $r['stock_qty'] ?? 0);

            if ($description === '') {
                $skipped++;
                continue;
            }

            // Duplicate check
            if ($code !== '') {
                $checkStmt->execute([$code]);
                if ($checkStmt->fetchColumn()) {
                    $skipped++;
                    continue;
                }
            }

            try {
                $insertStmt->execute([
                    $code ?: null,
                    $description,
                    $price,
                    $vatRate,
                    $stockQty,
                    $storeId,
                ]);
                $imported++;
            } catch (PDOException $e) {
                $errors[] = "Row " . ($idx + 2) . ": " . $e->getMessage();
            }
        }
    } else {
        // customers
        $checkStmt = $pdo->prepare(
            'SELECT id FROM customers WHERE email_address = ? AND email_address IS NOT NULL LIMIT 1'
        );
        $insertStmt = $pdo->prepare(
            'INSERT INTO customers
             (company_name, email_address, inv_telephone, inv_address1, inv_address2,
              inv_town, inv_county, eircode)
             VALUES (?,?,?,?,?,?,?,?)'
        );

        foreach ($rows as $idx => $row) {
            $r    = array_change_key_case($row, CASE_LOWER);
            $name = trim((string)($r['name'] ?? $r['company_name'] ?? $r['customer_name'] ?? ''));

            if ($name === '') {
                $skipped++;
                continue;
            }

            $email = trim((string)($r['email'] ?? $r['email_address'] ?? ''));

            // Duplicate check by email
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $checkStmt->execute([$email]);
                if ($checkStmt->fetchColumn()) {
                    $skipped++;
                    continue;
                }
            }

            try {
                $insertStmt->execute([
                    $name,
                    $email ?: null,
                    trim((string)($r['phone'] ?? $r['telephone'] ?? '')),
                    trim((string)($r['address'] ?? $r['address1'] ?? $r['inv_address1'] ?? '')),
                    trim((string)($r['address2'] ?? $r['inv_address2'] ?? '')),
                    trim((string)($r['town'] ?? $r['inv_town'] ?? '')),
                    trim((string)($r['county'] ?? $r['inv_county'] ?? '')),
                    trim((string)($r['eircode'] ?? '')),
                ]);
                $imported++;
            } catch (PDOException $e) {
                $errors[] = "Row " . ($idx + 2) . ": " . $e->getMessage();
            }
        }
    }

    @unlink($tmpPath);

    jsonResponse([
        'success'  => true,
        'imported' => $imported,
        'skipped'  => $skipped,
        'errors'   => $errors,
        'message'  => "{$imported} imported, {$skipped} skipped, " . count($errors) . " errors",
    ]);

} catch (PDOException $e) {
    @unlink($tmpPath);
    error_log('import.php DB error: ' . $e->getMessage());
    jsonResponse(['success' => false, 'error' => 'Service unavailable'], 503);
} catch (Throwable $e) {
    @unlink($tmpPath);
    error_log('import.php error: ' . $e->getMessage());
    jsonResponse(['success' => false, 'error' => 'Internal server error'], 500);
}
