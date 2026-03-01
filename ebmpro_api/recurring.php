<?php
require_once __DIR__ . '/common.php';

setCorsHeaders();
header('Content-Type: application/json; charset=utf-8');

$method = $_SERVER['REQUEST_METHOD'];

// ── run_due is protected by CRON_SECRET, not Bearer token ────────────────────
$action = $_GET['action'] ?? '';
if ($action === 'run_due') {
    $cronSecret = $_SERVER['HTTP_X_CRON_SECRET'] ?? '';
    if (!defined('CRON_SECRET') || !hash_equals(CRON_SECRET, $cronSecret)) {
        jsonResponse(['success' => false, 'error' => 'Forbidden'], 403);
    }
} else {
    $auth = requireAuth();
    checkRateLimit('api');
}

$pdo = getDb();

// ── Helper: resolve store code ────────────────────────────────────────────────
function recurringStoreCode(PDO $pdo, int $storeId): string
{
    $stmt = $pdo->prepare('SELECT code FROM stores WHERE id = ? LIMIT 1');
    $stmt->execute([$storeId]);
    $row = $stmt->fetch();
    return $row ? $row['code'] : (string)$storeId;
}

// ── Helper: generate next invoice number for recurring ────────────────────────
function recurringNextInvoiceNumber(PDO $pdo, string $storeCode): string
{
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            'SELECT next_invoice_num FROM invoice_sequences WHERE store_code = ? FOR UPDATE'
        );
        $stmt->execute([$storeCode]);
        $seq = $stmt->fetch();

        if (!$seq) {
            $pdo->prepare(
                'INSERT INTO invoice_sequences (store_code, next_invoice_num, next_quote_num)
                 VALUES (?,1001,1001)'
            )->execute([$storeCode]);
            $current = 1001;
        } else {
            $current = (int)$seq['next_invoice_num'];
        }

        $pdo->prepare('UPDATE invoice_sequences SET next_invoice_num = ? WHERE store_code = ?')
            ->execute([$current + 1, $storeCode]);

        $pdo->commit();

        $storeRow = $pdo->prepare('SELECT invoice_prefix FROM stores WHERE code = ? LIMIT 1');
        $storeRow->execute([$storeCode]);
        $pr     = $storeRow->fetch();
        $prefix = $pr && !empty($pr['invoice_prefix']) ? $pr['invoice_prefix'] : strtoupper(substr($storeCode, 0, 3));

        return $prefix . '-' . str_pad($current, 4, '0', STR_PAD_LEFT);
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

// ── Helper: advance next_run_date by frequency ────────────────────────────────
function advanceDate(string $date, string $frequency): string
{
    $dt = new DateTimeImmutable($date);
    switch ($frequency) {
        case 'weekly':     $dt = $dt->modify('+1 week');    break;
        case 'monthly':    $dt = $dt->modify('+1 month');   break;
        case 'quarterly':  $dt = $dt->modify('+3 months');  break;
        case 'yearly':     $dt = $dt->modify('+1 year');    break;
    }
    return $dt->format('Y-m-d');
}

// ── Helper: fetch template with items ─────────────────────────────────────────
function fetchTemplateFull(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare(
        'SELECT r.*, c.company_name AS customer_name
         FROM recurring_invoices r
         LEFT JOIN customers c ON c.id = r.customer_id
         WHERE r.id = ?'
    );
    $stmt->execute([$id]);
    $tpl = $stmt->fetch();
    if (!$tpl) {
        return null;
    }
    $stmt = $pdo->prepare(
        'SELECT * FROM recurring_invoice_items WHERE recurring_id = ? ORDER BY id ASC'
    );
    $stmt->execute([$id]);
    $tpl['items'] = $stmt->fetchAll();
    return $tpl;
}

try {
    // ════════════════════════════════════════════════════════════════════════
    // POST ?action=run_due — generate invoices for all due templates
    // ════════════════════════════════════════════════════════════════════════
    if ($method === 'POST' && $action === 'run_due') {
        $today = date('Y-m-d');
        $stmt  = $pdo->prepare(
            'SELECT * FROM recurring_invoices WHERE next_run_date <= ? AND active = 1'
        );
        $stmt->execute([$today]);
        $templates   = $stmt->fetchAll();
        $createdIds  = [];
        $errors      = [];

        foreach ($templates as $tpl) {
            try {
                $sc        = recurringStoreCode($pdo, (int)$tpl['store_id']);
                $invNumber = recurringNextInvoiceNumber($pdo, $sc);

                $insStmt = $pdo->prepare(
                    'INSERT INTO invoices
                     (invoice_number, store_code, invoice_type, status, customer_id,
                      invoice_date, due_date, notes, created_by, created_at, updated_at)
                     VALUES (?,?,?,?,?,?,?,?,?,NOW(),NOW())'
                );
                $insStmt->execute([
                    $invNumber, $sc, 'invoice', 'draft',
                    (int)$tpl['customer_id'],
                    $today, null,
                    $tpl['notes'],
                    $tpl['created_by'],
                ]);
                $newInvId = (int)$pdo->lastInsertId();

                // Copy items
                $itemStmt = $pdo->prepare(
                    'SELECT * FROM recurring_invoice_items WHERE recurring_id = ?'
                );
                $itemStmt->execute([$tpl['id']]);
                $items = $itemStmt->fetchAll();

                $lineInsert = $pdo->prepare(
                    'INSERT INTO invoice_items
                     (invoice_id, line_order, description, quantity, unit_price,
                      discount_pct, vat_rate, vat_amount, line_total)
                     VALUES (?,?,?,?,?,0,?,?,?)'
                );
                $subtotal = 0.0;
                $vatTotal = 0.0;
                foreach ($items as $i => $item) {
                    $qty       = round((float)$item['quantity'], 3);
                    $price     = round((float)$item['unit_price'], 2);
                    $vatRate   = round((float)$item['vat_rate'], 2);
                    $lineNet   = round($qty * $price, 2);
                    $lineVat   = round($lineNet * $vatRate / 100, 2);
                    $lineTotal = round($lineNet + $lineVat, 2);
                    $lineInsert->execute([
                        $newInvId, $i, $item['description'],
                        $qty, $price, $vatRate, $lineVat, $lineTotal,
                    ]);
                    $subtotal += $lineNet;
                    $vatTotal += $lineVat;
                }
                $total = round($subtotal + $vatTotal, 2);
                $pdo->prepare(
                    'UPDATE invoices
                     SET subtotal = ?, vat_total = ?, total = ?, amount_paid = 0, balance = ?
                     WHERE id = ?'
                )->execute([round($subtotal, 2), $vatTotal, $total, $total, $newInvId]);

                // Advance next_run_date
                $nextRun = advanceDate($tpl['next_run_date'], $tpl['frequency']);
                $pdo->prepare(
                    'UPDATE recurring_invoices
                     SET last_run_date = ?, next_run_date = ? WHERE id = ?'
                )->execute([$today, $nextRun, $tpl['id']]);

                $createdIds[] = $newInvId;
            } catch (Throwable $e) {
                error_log('recurring run_due template ' . $tpl['id'] . ': ' . $e->getMessage());
                $errors[] = 'Template ' . $tpl['id'] . ': ' . $e->getMessage();
            }
        }

        jsonResponse([
            'success'     => true,
            'created'     => $createdIds,
            'errors'      => $errors,
            'message'     => count($createdIds) . ' invoice(s) created',
        ]);
    }

    // All remaining routes require auth (already checked above)
    // ════════════════════════════════════════════════════════════════════════
    // GET — list recurring templates
    // ════════════════════════════════════════════════════════════════════════
    if ($method === 'GET') {
        if (!empty($_GET['id'])) {
            $tpl = fetchTemplateFull($pdo, (int)$_GET['id']);
            if (!$tpl) {
                jsonResponse(['success' => false, 'error' => 'Template not found'], 404);
            }
            jsonResponse(['success' => true, 'data' => $tpl]);
        }

        $where  = ['1=1'];
        $params = [];
        if (!empty($_GET['store_id']))    { $where[] = 'r.store_id = ?';    $params[] = (int)$_GET['store_id']; }
        if (!empty($_GET['customer_id'])) { $where[] = 'r.customer_id = ?'; $params[] = (int)$_GET['customer_id']; }

        $sql = 'SELECT r.*, c.company_name AS customer_name
                FROM recurring_invoices r
                LEFT JOIN customers c ON c.id = r.customer_id
                WHERE ' . implode(' AND ', $where) . '
                ORDER BY r.next_run_date ASC, r.id DESC
                LIMIT 500';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        jsonResponse(['success' => true, 'data' => $stmt->fetchAll()]);
    }

    // ════════════════════════════════════════════════════════════════════════
    // POST — create template or toggle
    // ════════════════════════════════════════════════════════════════════════
    if ($method === 'POST') {
        $id = (int)($_GET['id'] ?? 0);

        // toggle active/paused
        if ($action === 'toggle') {
            if (!$id) {
                jsonResponse(['success' => false, 'error' => 'id parameter required'], 422);
            }
            $stmt = $pdo->prepare('SELECT id, active FROM recurring_invoices WHERE id = ?');
            $stmt->execute([$id]);
            $tpl = $stmt->fetch();
            if (!$tpl) {
                jsonResponse(['success' => false, 'error' => 'Template not found'], 404);
            }
            $newActive = $tpl['active'] ? 0 : 1;
            $pdo->prepare(
                'UPDATE recurring_invoices SET active = ? WHERE id = ?'
            )->execute([$newActive, $id]);
            jsonResponse(['success' => true, 'active' => (bool)$newActive]);
        }

        // Create template
        $body = json_decode(file_get_contents('php://input'), true) ?? [];

        foreach (['customer_id', 'store_id', 'frequency', 'next_run_date', 'items'] as $f) {
            if (empty($body[$f])) {
                jsonResponse(['success' => false, 'error' => "Field '$f' is required"], 422);
            }
        }
        $validFreqs = ['weekly', 'monthly', 'quarterly', 'yearly'];
        if (!in_array($body['frequency'], $validFreqs, true)) {
            jsonResponse(['success' => false, 'error' => 'Invalid frequency'], 422);
        }
        if (!is_array($body['items']) || count($body['items']) === 0) {
            jsonResponse(['success' => false, 'error' => 'At least one item is required'], 422);
        }

        $stmt = $pdo->prepare(
            'INSERT INTO recurring_invoices
             (customer_id, store_id, frequency, next_run_date, active, notes, created_by, created_at)
             VALUES (?,?,?,?,1,?,?,NOW())'
        );
        $stmt->execute([
            (int)$body['customer_id'],
            (int)$body['store_id'],
            $body['frequency'],
            $body['next_run_date'],
            $body['notes']       ?? null,
            (int)$auth['user_id'],
        ]);
        $newId = (int)$pdo->lastInsertId();

        $itemStmt = $pdo->prepare(
            'INSERT INTO recurring_invoice_items
             (recurring_id, product_id, description, quantity, unit_price, vat_rate)
             VALUES (?,?,?,?,?,?)'
        );
        foreach ($body['items'] as $item) {
            $itemStmt->execute([
                $newId,
                !empty($item['product_id']) ? (int)$item['product_id'] : null,
                $item['description'] ?? '',
                round((float)($item['qty'] ?? $item['quantity'] ?? 1), 3),
                round((float)($item['unit_price'] ?? 0), 2),
                round((float)($item['vat_rate'] ?? 23), 2),
            ]);
        }

        jsonResponse(['success' => true, 'data' => fetchTemplateFull($pdo, $newId)], 201);
    }

    // ════════════════════════════════════════════════════════════════════════
    // PUT — update template
    // ════════════════════════════════════════════════════════════════════════
    if ($method === 'PUT') {
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) {
            jsonResponse(['success' => false, 'error' => 'id parameter required'], 422);
        }

        $stmt = $pdo->prepare('SELECT * FROM recurring_invoices WHERE id = ?');
        $stmt->execute([$id]);
        $old = $stmt->fetch();
        if (!$old) {
            jsonResponse(['success' => false, 'error' => 'Template not found'], 404);
        }

        $body = json_decode(file_get_contents('php://input'), true) ?? [];

        $pdo->prepare(
            'UPDATE recurring_invoices
             SET customer_id    = COALESCE(?, customer_id),
                 frequency      = COALESCE(?, frequency),
                 next_run_date  = COALESCE(?, next_run_date),
                 notes          = ?,
                 active         = COALESCE(?, active)
             WHERE id = ?'
        )->execute([
            !empty($body['customer_id']) ? (int)$body['customer_id'] : null,
            $body['frequency']      ?? null,
            $body['next_run_date']  ?? null,
            $body['notes']          ?? $old['notes'],
            isset($body['active'])  ? (int)(bool)$body['active'] : null,
            $id,
        ]);

        if (!empty($body['items']) && is_array($body['items'])) {
            $pdo->prepare('DELETE FROM recurring_invoice_items WHERE recurring_id = ?')->execute([$id]);
            $itemStmt = $pdo->prepare(
                'INSERT INTO recurring_invoice_items
                 (recurring_id, product_id, description, quantity, unit_price, vat_rate)
                 VALUES (?,?,?,?,?,?)'
            );
            foreach ($body['items'] as $item) {
                $itemStmt->execute([
                    $id,
                    !empty($item['product_id']) ? (int)$item['product_id'] : null,
                    $item['description'] ?? '',
                    round((float)($item['qty'] ?? $item['quantity'] ?? 1), 3),
                    round((float)($item['unit_price'] ?? 0), 2),
                    round((float)($item['vat_rate'] ?? 23), 2),
                ]);
            }
        }

        jsonResponse(['success' => true, 'data' => fetchTemplateFull($pdo, $id)]);
    }

    // ════════════════════════════════════════════════════════════════════════
    // DELETE — delete template
    // ════════════════════════════════════════════════════════════════════════
    if ($method === 'DELETE') {
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) {
            jsonResponse(['success' => false, 'error' => 'id parameter required'], 422);
        }

        $stmt = $pdo->prepare('SELECT id FROM recurring_invoices WHERE id = ?');
        $stmt->execute([$id]);
        if (!$stmt->fetch()) {
            jsonResponse(['success' => false, 'error' => 'Template not found'], 404);
        }

        $pdo->prepare('DELETE FROM recurring_invoices WHERE id = ?')->execute([$id]);
        jsonResponse(['success' => true, 'message' => 'Template deleted']);
    }

    jsonResponse(['success' => false, 'error' => 'Method not allowed'], 405);

} catch (PDOException $e) {
    error_log('recurring.php DB error: ' . $e->getMessage());
    jsonResponse(['success' => false, 'error' => 'Service unavailable'], 503);
} catch (Throwable $e) {
    error_log('recurring.php error: ' . $e->getMessage());
    jsonResponse(['success' => false, 'error' => 'Internal server error'], 500);
}
