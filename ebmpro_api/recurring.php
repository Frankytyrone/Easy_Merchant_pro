<?php
require_once __DIR__ . '/common.php';

setCorsHeaders();
header('Content-Type: application/json; charset=utf-8');

$method = $_SERVER['REQUEST_METHOD'];

// run_due action uses CRON_SECRET, not auth token
if ($method === 'POST' && ($_GET['action'] ?? '') === 'run_due') {
    if (!defined('CRON_SECRET') || ($_GET['cron_secret'] ?? '') !== CRON_SECRET) {
        jsonResponse(['success' => false, 'error' => 'Unauthorized'], 401);
    }
    $pdo = getDb();
    // Find all active templates due today or earlier
    $stmt = $pdo->prepare(
        "SELECT r.*, c.company_name AS customer_name
         FROM recurring_invoices r
         LEFT JOIN customers c ON c.id = r.customer_id
         WHERE r.active = 1 AND r.next_run_date <= CURDATE()"
    );
    $stmt->execute();
    $templates = $stmt->fetchAll();

    $created = [];
    foreach ($templates as $tpl) {
        // Get store code
        $scRow = $pdo->prepare('SELECT code, invoice_prefix FROM stores WHERE id = ? LIMIT 1');
        $scRow->execute([$tpl['store_id']]);
        $sr = $scRow->fetch();
        $storeCode = $sr ? $sr['code'] : (string)$tpl['store_id'];
        $prefix    = $sr ? ($sr['invoice_prefix'] ?? strtoupper(substr($storeCode, 0, 3))) : strtoupper(substr($storeCode, 0, 3));

        // Get next invoice number
        $pdo->beginTransaction();
        try {
            $seqStmt = $pdo->prepare('SELECT next_invoice_num FROM invoice_sequences WHERE store_code = ? FOR UPDATE');
            $seqStmt->execute([$storeCode]);
            $seq = $seqStmt->fetch();
            if (!$seq) {
                $pdo->prepare('INSERT INTO invoice_sequences (store_code, next_invoice_num, next_quote_num) VALUES (?,1001,1001)')->execute([$storeCode]);
                $invNum = 1001;
            } else {
                $invNum = (int)$seq['next_invoice_num'];
            }
            $pdo->prepare('UPDATE invoice_sequences SET next_invoice_num = ? WHERE store_code = ?')->execute([$invNum + 1, $storeCode]);
            $invNumber = $prefix . '-' . str_pad($invNum, 4, '0', STR_PAD_LEFT);

            // Create invoice
            $pdo->prepare(
                'INSERT INTO invoices (invoice_number, store_code, invoice_type, status, customer_id,
                 invoice_date, notes, created_by, created_at, updated_at)
                 VALUES (?,?,?,?,?,?,?,?,NOW(),NOW())'
            )->execute([
                $invNumber, $storeCode, 'invoice', 'draft',
                $tpl['customer_id'], date('Y-m-d'), $tpl['notes'], $tpl['created_by'],
            ]);
            $newInvId = (int)$pdo->lastInsertId();

            // Copy recurring items to invoice_items
            $itemsStmt = $pdo->prepare('SELECT * FROM recurring_invoice_items WHERE recurring_id = ?');
            $itemsStmt->execute([$tpl['id']]);
            $items = $itemsStmt->fetchAll();

            $insertItem = $pdo->prepare(
                'INSERT INTO invoice_items (invoice_id, line_order, description, quantity, unit_price, vat_rate, vat_amount, line_total)
                 VALUES (?,?,?,?,?,?,?,?)'
            );
            $subtotal = 0; $vatTotal = 0;
            foreach ($items as $i => $item) {
                $qty      = (float)$item['quantity'];
                $price    = (float)$item['unit_price'];
                $vatRate  = (float)$item['vat_rate'];
                $lineNet  = round($qty * $price, 2);
                $vatAmt   = round($lineNet * $vatRate / 100, 2);
                $lineTotal = round($lineNet + $vatAmt, 2);
                $insertItem->execute([$newInvId, $i, $item['description'], $qty, $price, $vatRate, $vatAmt, $lineTotal]);
                $subtotal += $lineNet;
                $vatTotal += $vatAmt;
            }
            $subtotal = round($subtotal, 2);
            $vatTotal = round($vatTotal, 2);
            $total    = round($subtotal + $vatTotal, 2);
            $pdo->prepare('UPDATE invoices SET subtotal=?, vat_total=?, total=?, balance=? WHERE id=?')
                ->execute([$subtotal, $vatTotal, $total, $total, $newInvId]);

            // Update next_run_date
            $next = new DateTime($tpl['next_run_date']);
            switch ($tpl['frequency']) {
                case 'weekly':    $next->modify('+1 week');    break;
                case 'monthly':   $next->modify('+1 month');   break;
                case 'quarterly': $next->modify('+3 months');  break;
                case 'yearly':    $next->modify('+1 year');    break;
            }
            $pdo->prepare('UPDATE recurring_invoices SET next_run_date = ?, last_run_date = CURDATE() WHERE id = ?')
                ->execute([$next->format('Y-m-d'), $tpl['id']]);

            $pdo->commit();
            $created[] = $newInvId;
        } catch (Throwable $e) {
            $pdo->rollBack();
            error_log('recurring run_due error for template ' . $tpl['id'] . ': ' . $e->getMessage());
        }
    }
    jsonResponse(['success' => true, 'invoices_created' => $created]);
}

// All other routes require auth
$auth = requireAuth();
$pdo  = getDb();

try {
    if ($method === 'GET') {
        $where  = ['1=1'];
        $params = [];
        if (!empty($_GET['store_id'])) { $where[] = 'r.store_id = ?'; $params[] = (int)$_GET['store_id']; }

        $sql = 'SELECT r.*, c.company_name AS customer_name
                FROM recurring_invoices r
                LEFT JOIN customers c ON c.id = r.customer_id
                WHERE ' . implode(' AND ', $where) . '
                ORDER BY r.next_run_date ASC';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        // Attach items
        foreach ($rows as &$row) {
            $iStmt = $pdo->prepare('SELECT * FROM recurring_invoice_items WHERE recurring_id = ?');
            $iStmt->execute([$row['id']]);
            $row['items'] = $iStmt->fetchAll();
        }
        jsonResponse(['success' => true, 'data' => $rows]);
    }

    if ($method === 'POST') {
        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        foreach (['customer_id', 'store_id', 'frequency', 'next_run_date', 'items'] as $f) {
            if (empty($body[$f])) jsonResponse(['success' => false, 'error' => "Field '$f' is required"], 422);
        }
        if (!in_array($body['frequency'], ['weekly','monthly','quarterly','yearly'], true)) {
            jsonResponse(['success' => false, 'error' => 'Invalid frequency'], 422);
        }

        $pdo->prepare(
            'INSERT INTO recurring_invoices (customer_id, store_id, frequency, next_run_date, active, notes, created_by, created_at)
             VALUES (?,?,?,?,1,?,?,NOW())'
        )->execute([
            (int)$body['customer_id'],
            (int)$body['store_id'],
            $body['frequency'],
            $body['next_run_date'],
            $body['notes']   ?? null,
            (int)$auth['user_id'],
        ]);
        $newId = (int)$pdo->lastInsertId();

        $iStmt = $pdo->prepare(
            'INSERT INTO recurring_invoice_items (recurring_id, product_id, description, quantity, unit_price, vat_rate)
             VALUES (?,?,?,?,?,?)'
        );
        foreach ($body['items'] as $item) {
            $iStmt->execute([
                $newId,
                !empty($item['product_id']) ? (int)$item['product_id'] : null,
                $item['description'] ?? '',
                round((float)($item['qty'] ?? $item['quantity'] ?? 1), 3),
                round((float)($item['unit_price'] ?? 0), 2),
                round((float)($item['vat_rate'] ?? 23), 2),
            ]);
        }
        jsonResponse(['success' => true, 'id' => $newId], 201);
    }

    if ($method === 'PUT') {
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) jsonResponse(['success' => false, 'error' => 'id required'], 422);
        $stmt = $pdo->prepare('SELECT id FROM recurring_invoices WHERE id = ?');
        $stmt->execute([$id]);
        if (!$stmt->fetch()) jsonResponse(['success' => false, 'error' => 'Not found'], 404);

        $body = json_decode(file_get_contents('php://input'), true) ?? [];

        // Toggle active if provided
        if (isset($body['active'])) {
            $pdo->prepare('UPDATE recurring_invoices SET active = ? WHERE id = ?')
                ->execute([(int)(bool)$body['active'], $id]);
        }
        // Update other fields
        $pdo->prepare(
            'UPDATE recurring_invoices SET
             customer_id    = COALESCE(?, customer_id),
             frequency      = COALESCE(?, frequency),
             next_run_date  = COALESCE(?, next_run_date),
             notes          = ?
             WHERE id = ?'
        )->execute([
            !empty($body['customer_id']) ? (int)$body['customer_id'] : null,
            $body['frequency']      ?? null,
            $body['next_run_date']  ?? null,
            $body['notes']          ?? null,
            $id,
        ]);

        if (!empty($body['items']) && is_array($body['items'])) {
            $pdo->prepare('DELETE FROM recurring_invoice_items WHERE recurring_id = ?')->execute([$id]);
            $iStmt = $pdo->prepare(
                'INSERT INTO recurring_invoice_items (recurring_id, product_id, description, quantity, unit_price, vat_rate)
                 VALUES (?,?,?,?,?,?)'
            );
            foreach ($body['items'] as $item) {
                $iStmt->execute([
                    $id,
                    !empty($item['product_id']) ? (int)$item['product_id'] : null,
                    $item['description'] ?? '',
                    round((float)($item['qty'] ?? $item['quantity'] ?? 1), 3),
                    round((float)($item['unit_price'] ?? 0), 2),
                    round((float)($item['vat_rate'] ?? 23), 2),
                ]);
            }
        }
        jsonResponse(['success' => true, 'message' => 'Updated']);
    }

    if ($method === 'DELETE') {
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) jsonResponse(['success' => false, 'error' => 'id required'], 422);
        $pdo->prepare('DELETE FROM recurring_invoices WHERE id = ?')->execute([$id]);
        jsonResponse(['success' => true, 'message' => 'Deleted']);
    }

    jsonResponse(['success' => false, 'error' => 'Method not allowed'], 405);

} catch (PDOException $e) {
    error_log('recurring.php DB error: ' . $e->getMessage());
    jsonResponse(['success' => false, 'error' => 'Service unavailable'], 503);
} catch (Throwable $e) {
    error_log('recurring.php error: ' . $e->getMessage());
    jsonResponse(['success' => false, 'error' => 'Internal server error'], 500);
}
