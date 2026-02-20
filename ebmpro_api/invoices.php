<?php
require_once __DIR__ . '/common.php';

setCorsHeaders();
header('Content-Type: application/json; charset=utf-8');

$auth   = requireAuth();
$method = $_SERVER['REQUEST_METHOD'];
$pdo    = getDb();

// ── Helper: recalculate and persist invoice totals ────────────────────────────
function recalcTotals(PDO $pdo, int $invoiceId): array
{
    $stmt = $pdo->prepare(
        'SELECT SUM(quantity * unit_price) AS subtotal,
                SUM(vat_amount)            AS vat_total
         FROM invoice_items WHERE invoice_id = ?'
    );
    $stmt->execute([$invoiceId]);
    $row      = $stmt->fetch();
    $subtotal = round((float)($row['subtotal'] ?? 0), 2);
    $vatTotal = round((float)($row['vat_total'] ?? 0), 2);
    $total    = round($subtotal + $vatTotal, 2);

    $paidStmt = $pdo->prepare(
        'SELECT COALESCE(SUM(amount), 0) FROM payments WHERE invoice_id = ?'
    );
    $paidStmt->execute([$invoiceId]);
    $amountPaid = round((float) $paidStmt->fetchColumn(), 2);
    $balance    = round($total - $amountPaid, 2);

    $pdo->prepare(
        'UPDATE invoices
         SET subtotal = ?, vat_total = ?, total = ?, amount_paid = ?, balance = ?
         WHERE id = ?'
    )->execute([$subtotal, $vatTotal, $total, $amountPaid, $balance, $invoiceId]);

    return compact('subtotal', 'vatTotal', 'total', 'amountPaid', 'balance');
}

// ── Helper: insert line items ────────────────────────────────────────────────
function insertItems(PDO $pdo, int $invoiceId, array $items): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO invoice_items
         (invoice_id, product_id, description, quantity, unit_price, vat_rate, vat_amount, line_total)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );
    foreach ($items as $item) {
        $qty      = round((float)($item['quantity']   ?? 1), 4);
        $price    = round((float)($item['unit_price'] ?? 0), 4);
        $vatRate  = round((float)($item['vat_rate']   ?? 0), 4);
        $lineNet  = round($qty * $price, 2);
        $vatAmt   = round($lineNet * $vatRate / 100, 2);
        $lineTotal = round($lineNet + $vatAmt, 2);

        $stmt->execute([
            $invoiceId,
            !empty($item['product_id']) ? (int)$item['product_id'] : null,
            $item['description'] ?? '',
            $qty,
            $price,
            $vatRate,
            $vatAmt,
            $lineTotal,
        ]);
    }
}

// ── Helper: generate next invoice number ─────────────────────────────────────
function nextInvoiceNumber(PDO $pdo, int $storeId, string $type): string
{
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            'SELECT id, prefix, last_number FROM invoice_sequences
             WHERE store_id = ? AND type = ? FOR UPDATE'
        );
        $stmt->execute([$storeId, $type]);
        $seq = $stmt->fetch();

        if (!$seq) {
            $prefix = strtoupper(substr($type, 0, 3));
            $pdo->prepare(
                'INSERT INTO invoice_sequences (store_id, type, prefix, last_number) VALUES (?,?,?,0)'
            )->execute([$storeId, $type, $prefix]);
            $seqId  = (int)$pdo->lastInsertId();
            $next   = 1001;
            $prefix = $prefix;
        } else {
            $seqId  = (int)$seq['id'];
            $next   = (int)$seq['last_number'] + 1;
            $prefix = $seq['prefix'];
        }

        $pdo->prepare('UPDATE invoice_sequences SET last_number = ? WHERE id = ?')
            ->execute([$next, $seqId]);

        $pdo->commit();
        return $prefix . '-' . str_pad($next, 4, '0', STR_PAD_LEFT);
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

// ── Helper: fetch full invoice with items + payments ─────────────────────────
function fetchInvoiceFull(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare(
        'SELECT i.*, c.name AS customer_name, c.email AS customer_email
         FROM invoices i
         LEFT JOIN customers c ON c.id = i.customer_id
         WHERE i.id = ?'
    );
    $stmt->execute([$id]);
    $inv = $stmt->fetch();
    if (!$inv) {
        return null;
    }

    $stmt = $pdo->prepare('SELECT * FROM invoice_items WHERE invoice_id = ? ORDER BY id');
    $stmt->execute([$id]);
    $inv['items'] = $stmt->fetchAll();

    $stmt = $pdo->prepare('SELECT * FROM payments WHERE invoice_id = ? ORDER BY payment_date, id');
    $stmt->execute([$id]);
    $inv['payments'] = $stmt->fetchAll();

    return $inv;
}

try {
    // ════════════════════════════════════════════════════════════════════════
    // GET
    // ════════════════════════════════════════════════════════════════════════
    if ($method === 'GET') {
        // Single invoice
        if (!empty($_GET['id'])) {
            $inv = fetchInvoiceFull($pdo, (int)$_GET['id']);
            if (!$inv) {
                jsonResponse(['success' => false, 'error' => 'Invoice not found'], 404);
            }
            jsonResponse(['success' => true, 'data' => $inv]);
        }

        // List invoices
        $where  = ['i.status != ?'];
        $params = ['deleted'];

        if (!empty($_GET['store_id']))   { $where[] = 'i.store_id = ?';     $params[] = (int)$_GET['store_id']; }
        if (!empty($_GET['status']))     { $where[] = 'i.status = ?';       $params[] = $_GET['status']; }
        if (!empty($_GET['type']))       { $where[] = 'i.type = ?';         $params[] = $_GET['type']; }
        if (!empty($_GET['customer_id'])){ $where[] = 'i.customer_id = ?';  $params[] = (int)$_GET['customer_id']; }
        if (!empty($_GET['date_from']))  { $where[] = 'i.invoice_date >= ?'; $params[] = $_GET['date_from']; }
        if (!empty($_GET['date_to']))    { $where[] = 'i.invoice_date <= ?'; $params[] = $_GET['date_to']; }
        if (!empty($_GET['search'])) {
            $where[]  = '(i.invoice_number LIKE ? OR c.name LIKE ?)';
            $like     = '%' . $_GET['search'] . '%';
            $params[] = $like;
            $params[] = $like;
        }

        $sql = 'SELECT i.*, c.name AS customer_name
                FROM invoices i
                LEFT JOIN customers c ON c.id = i.customer_id
                WHERE ' . implode(' AND ', $where) . '
                ORDER BY i.invoice_date DESC, i.id DESC
                LIMIT 500';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        jsonResponse(['success' => true, 'data' => $stmt->fetchAll()]);
    }

    // ════════════════════════════════════════════════════════════════════════
    // POST
    // ════════════════════════════════════════════════════════════════════════
    if ($method === 'POST') {
        $body = json_decode(file_get_contents('php://input'), true) ?? [];

        // Convert quote to invoice
        if (isset($_GET['action']) && $_GET['action'] === 'convert_quote') {
            $id = (int)($body['invoice_id'] ?? 0);
            if (!$id) {
                jsonResponse(['success' => false, 'error' => 'invoice_id required'], 422);
            }
            $stmt = $pdo->prepare('SELECT * FROM invoices WHERE id = ? AND type = ?');
            $stmt->execute([$id, 'quote']);
            $quote = $stmt->fetch();
            if (!$quote) {
                jsonResponse(['success' => false, 'error' => 'Quote not found'], 404);
            }
            $newNumber = nextInvoiceNumber($pdo, (int)$quote['store_id'], 'invoice');
            $pdo->prepare(
                'UPDATE invoices SET type = ?, invoice_number = ?, status = ?, updated_at = NOW()
                 WHERE id = ?'
            )->execute(['invoice', $newNumber, 'pending', $id]);

            auditLog($pdo, (int)$auth['user_id'], $auth['username'], (int)$quote['store_id'],
                'convert_quote', 'invoice', $id,
                ['type' => 'quote', 'invoice_number' => $quote['invoice_number']],
                ['type' => 'invoice', 'invoice_number' => $newNumber]);

            jsonResponse(['success' => true, 'data' => fetchInvoiceFull($pdo, $id)]);
        }

        // Create invoice / quote
        $required = ['store_id', 'customer_id', 'invoice_date', 'due_date', 'items'];
        foreach ($required as $f) {
            if (empty($body[$f])) {
                jsonResponse(['success' => false, 'error' => "Field '$f' is required"], 422);
            }
        }
        if (!is_array($body['items']) || count($body['items']) === 0) {
            jsonResponse(['success' => false, 'error' => 'At least one line item is required'], 422);
        }

        $type       = in_array($body['type'] ?? '', ['invoice','quote','credit_note'], true)
                        ? $body['type'] : 'invoice';
        $storeId    = (int)$body['store_id'];
        $invNumber  = nextInvoiceNumber($pdo, $storeId, $type);

        $stmt = $pdo->prepare(
            'INSERT INTO invoices
             (store_id, customer_id, invoice_number, type, status, invoice_date, due_date,
              notes, terms, currency, created_by, created_at, updated_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())'
        );
        $stmt->execute([
            $storeId,
            (int)$body['customer_id'],
            $invNumber,
            $type,
            'pending',
            $body['invoice_date'],
            $body['due_date'],
            $body['notes']    ?? null,
            $body['terms']    ?? null,
            $body['currency'] ?? 'ZAR',
            (int)$auth['user_id'],
        ]);
        $newId = (int)$pdo->lastInsertId();

        insertItems($pdo, $newId, $body['items']);
        recalcTotals($pdo, $newId);

        auditLog($pdo, (int)$auth['user_id'], $auth['username'], $storeId,
            'create', 'invoice', $newId, null, ['invoice_number' => $invNumber]);

        jsonResponse(['success' => true, 'data' => fetchInvoiceFull($pdo, $newId)], 201);
    }

    // ════════════════════════════════════════════════════════════════════════
    // PUT
    // ════════════════════════════════════════════════════════════════════════
    if ($method === 'PUT') {
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) {
            jsonResponse(['success' => false, 'error' => 'id parameter required'], 422);
        }

        $stmt = $pdo->prepare('SELECT * FROM invoices WHERE id = ?');
        $stmt->execute([$id]);
        $old = $stmt->fetch();
        if (!$old) {
            jsonResponse(['success' => false, 'error' => 'Invoice not found'], 404);
        }

        $body = json_decode(file_get_contents('php://input'), true) ?? [];

        $backdated = false;
        if (!empty($body['invoice_date'])) {
            $invDate   = new DateTimeImmutable($body['invoice_date']);
            $createdAt = new DateTimeImmutable($old['created_at']);
            $backdated = $invDate < $createdAt->setTime(0, 0, 0);
        }

        $pdo->prepare(
            'UPDATE invoices
             SET customer_id  = COALESCE(?, customer_id),
                 invoice_date = COALESCE(?, invoice_date),
                 due_date     = COALESCE(?, due_date),
                 status       = COALESCE(?, status),
                 notes        = ?,
                 terms        = ?,
                 currency     = COALESCE(?, currency),
                 is_backdated = ?,
                 updated_at   = NOW()
             WHERE id = ?'
        )->execute([
            !empty($body['customer_id']) ? (int)$body['customer_id'] : null,
            $body['invoice_date'] ?? null,
            $body['due_date']     ?? null,
            $body['status']       ?? null,
            $body['notes']        ?? $old['notes'],
            $body['terms']        ?? $old['terms'],
            $body['currency']     ?? null,
            $backdated ? 1 : 0,
            $id,
        ]);

        if (!empty($body['items']) && is_array($body['items'])) {
            $pdo->prepare('DELETE FROM invoice_items WHERE invoice_id = ?')->execute([$id]);
            insertItems($pdo, $id, $body['items']);
        }
        recalcTotals($pdo, $id);

        $stmt = $pdo->prepare('SELECT * FROM invoices WHERE id = ?');
        $stmt->execute([$id]);
        $new = $stmt->fetch();

        auditLog($pdo, (int)$auth['user_id'], $auth['username'], (int)$old['store_id'],
            'update' . ($backdated ? '_backdated' : ''), 'invoice', $id, $old, $new);

        jsonResponse(['success' => true, 'data' => fetchInvoiceFull($pdo, $id)]);
    }

    // ════════════════════════════════════════════════════════════════════════
    // DELETE (soft)
    // ════════════════════════════════════════════════════════════════════════
    if ($method === 'DELETE') {
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) {
            jsonResponse(['success' => false, 'error' => 'id parameter required'], 422);
        }
        $stmt = $pdo->prepare('SELECT * FROM invoices WHERE id = ?');
        $stmt->execute([$id]);
        $old = $stmt->fetch();
        if (!$old) {
            jsonResponse(['success' => false, 'error' => 'Invoice not found'], 404);
        }
        $pdo->prepare(
            'UPDATE invoices SET status = ?, updated_at = NOW() WHERE id = ?'
        )->execute(['cancelled', $id]);

        auditLog($pdo, (int)$auth['user_id'], $auth['username'], (int)$old['store_id'],
            'delete', 'invoice', $id, ['status' => $old['status']], ['status' => 'cancelled']);

        jsonResponse(['success' => true, 'message' => 'Invoice cancelled']);
    }

    jsonResponse(['success' => false, 'error' => 'Method not allowed'], 405);

} catch (PDOException $e) {
    error_log('invoices.php DB error: ' . $e->getMessage());
    jsonResponse(['success' => false, 'error' => 'Service unavailable'], 503);
} catch (Throwable $e) {
    error_log('invoices.php error: ' . $e->getMessage());
    jsonResponse(['success' => false, 'error' => 'Internal server error'], 500);
}
