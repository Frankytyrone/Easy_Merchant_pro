<?php
require_once __DIR__ . '/common.php';

setCorsHeaders();
header('Content-Type: application/json; charset=utf-8');

$auth   = requireAuth();
$method = $_SERVER['REQUEST_METHOD'];
$pdo    = getDb();

// ── Helper: look up store code string from store_id ───────────────────────────
function storeCode(PDO $pdo, int $storeId): string
{
    $stmt = $pdo->prepare('SELECT code FROM stores WHERE id = ? LIMIT 1');
    $stmt->execute([$storeId]);
    $row = $stmt->fetch();
    return $row ? $row['code'] : (string)$storeId;
}

// ── Helper: recalculate invoice totals from stored line values ────────────────
function recalcTotals(PDO $pdo, int $invoiceId): array
{
    $stmt = $pdo->prepare(
        'SELECT SUM(line_total - vat_amount) AS subtotal, SUM(vat_amount) AS vat_total
         FROM invoice_items WHERE invoice_id = ?'
    );
    $stmt->execute([$invoiceId]);
    $row      = $stmt->fetch();
    $subtotal = round((float)($row['subtotal']  ?? 0), 2);
    $vatTotal = round((float)($row['vat_total'] ?? 0), 2);
    $total    = round($subtotal + $vatTotal, 2);

    $paidStmt = $pdo->prepare(
        'SELECT COALESCE(SUM(amount), 0) FROM payments WHERE invoice_id = ?'
    );
    $paidStmt->execute([$invoiceId]);
    $amountPaid = round((float)$paidStmt->fetchColumn(), 2);
    $balance    = round($total - $amountPaid, 2);

    $pdo->prepare(
        'UPDATE invoices
         SET subtotal = ?, vat_total = ?, total = ?, amount_paid = ?, balance = ?
         WHERE id = ?'
    )->execute([$subtotal, $vatTotal, $total, $amountPaid, $balance, $invoiceId]);

    return compact('subtotal', 'vatTotal', 'total', 'amountPaid', 'balance');
}

// ── Helper: insert line items using schema column names ───────────────────────
function insertItems(PDO $pdo, int $invoiceId, array $items): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO invoice_items
         (invoice_id, line_order, product_code, description,
          quantity, unit_price, discount_pct, vat_rate, vat_amount, line_total)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    foreach ($items as $i => $item) {
        $qty       = round((float)($item['quantity']    ?? $item['qty'] ?? 1), 3);
        $price     = round((float)($item['unit_price']  ?? 0), 2);
        $discPct   = round((float)($item['discount_pct'] ?? 0), 2);
        $vatRate   = round((float)($item['vat_rate']    ?? 23), 2);
        $lineNet   = round($qty * $price * (1 - $discPct / 100), 2);
        $vatAmount = round($lineNet * $vatRate / 100, 2);
        $lineTotal = round($lineNet + $vatAmount, 2);

        $stmt->execute([
            $invoiceId,
            (int)($item['line_order'] ?? $item['sort_order'] ?? $i),
            $item['product_code'] ?? $item['code'] ?? null,
            $item['description'] ?? '',
            $qty,
            $price,
            $discPct,
            $vatRate,
            $vatAmount,
            $lineTotal,
        ]);
    }
}

// ── Helper: generate next invoice number ─────────────────────────────────────
// invoice_sequences: store_code PK, next_invoice_num, next_quote_num
// Prefix comes from stores table (invoice_prefix / quote_prefix columns).
function nextInvoiceNumber(PDO $pdo, string $storeCode, string $type): string
{
    $seqCol    = ($type === 'quote') ? 'next_quote_num' : 'next_invoice_num';
    $prefixCol = ($type === 'quote') ? 'quote_prefix'   : 'invoice_prefix';

    $pdo->beginTransaction();
    try {
        // Lock the sequence row
        $stmt = $pdo->prepare(
            "SELECT {$seqCol} FROM invoice_sequences WHERE store_code = ? FOR UPDATE"
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
            $current = (int)$seq[$seqCol];
        }

        // Increment the stored next number
        $pdo->prepare("UPDATE invoice_sequences SET {$seqCol} = ? WHERE store_code = ?")
            ->execute([$current + 1, $storeCode]);

        $pdo->commit();

        // Fetch prefix from stores table
        $storeRow2 = $pdo->prepare('SELECT ' . $prefixCol . ' FROM stores WHERE code = ? LIMIT 1');
        $storeRow2->execute([$storeCode]);
        $pr     = $storeRow2->fetch();
        $prefix = $pr ? ($pr[$prefixCol] ?? strtoupper(substr($storeCode, 0, 3))) : strtoupper(substr($storeCode, 0, 3));

        return $prefix . '-' . str_pad($current, 4, '0', STR_PAD_LEFT);
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

// ── Helper: fetch full invoice with items + payments ─────────────────────────
function fetchInvoiceFull(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare(
        'SELECT i.*, c.company_name AS customer_name, c.email_address AS customer_email
         FROM invoices i
         LEFT JOIN customers c ON c.id = i.customer_id
         WHERE i.id = ?'
    );
    $stmt->execute([$id]);
    $inv = $stmt->fetch();
    if (!$inv) {
        return null;
    }

    $stmt = $pdo->prepare(
        'SELECT * FROM invoice_items WHERE invoice_id = ? ORDER BY line_order ASC, id ASC'
    );
    $stmt->execute([$id]);
    $inv['items'] = $stmt->fetchAll();

    $stmt = $pdo->prepare(
        'SELECT * FROM payments WHERE invoice_id = ? ORDER BY payment_date ASC, id ASC'
    );
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
        $where  = ["i.status != 'cancelled'"];
        $params = [];

        if (!empty($_GET['store_code']))   { $where[] = 'i.store_code = ?';     $params[] = $_GET['store_code']; }
        if (!empty($_GET['store_id']))     { $where[] = 'i.store_code = ?';     $params[] = $_GET['store_id']; }
        if (!empty($_GET['status']))      { $where[] = 'i.status = ?';        $params[] = $_GET['status']; }
        if (!empty($_GET['type']))        { $where[] = 'i.invoice_type = ?';  $params[] = $_GET['type']; }
        if (!empty($_GET['customer_id'])) { $where[] = 'i.customer_id = ?';   $params[] = (int)$_GET['customer_id']; }
        if (!empty($_GET['date_from']))   { $where[] = 'i.invoice_date >= ?'; $params[] = $_GET['date_from']; }
        if (!empty($_GET['date_to']))     { $where[] = 'i.invoice_date <= ?'; $params[] = $_GET['date_to']; }
        if (!empty($_GET['search'])) {
            $where[]  = '(i.invoice_number LIKE ? OR c.company_name LIKE ?)';
            $like     = '%' . $_GET['search'] . '%';
            $params[] = $like;
            $params[] = $like;
        }

        $sql = 'SELECT i.*, c.company_name AS customer_name
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
            $stmt = $pdo->prepare(
                "SELECT * FROM invoices WHERE id = ? AND invoice_type = 'quote'"
            );
            $stmt->execute([$id]);
            $quote = $stmt->fetch();
            if (!$quote) {
                jsonResponse(['success' => false, 'error' => 'Quote not found'], 404);
            }
            $newNumber = nextInvoiceNumber($pdo, $quote['store_code'], 'invoice');
            $pdo->prepare(
                "UPDATE invoices SET invoice_type = 'invoice', invoice_number = ?,
                 status = 'sent', updated_at = NOW() WHERE id = ?"
            )->execute([$newNumber, $id]);

            $sc = $quote['store_code'];
            auditLog($pdo, (int)$auth['user_id'], $auth['username'], $sc,
                'convert_quote', 'invoice', $id,
                ['invoice_type' => 'quote', 'invoice_number' => $quote['invoice_number']],
                ['invoice_type' => 'invoice', 'invoice_number' => $newNumber]);

            jsonResponse(['success' => true, 'data' => fetchInvoiceFull($pdo, $id)]);
        }

        // Create invoice / quote
        $required = ['store_id', 'customer_id', 'invoice_date', 'items'];
        foreach ($required as $f) {
            if (empty($body[$f])) {
                jsonResponse(['success' => false, 'error' => "Field '$f' is required"], 422);
            }
        }
        if (!is_array($body['items']) || count($body['items']) === 0) {
            jsonResponse(['success' => false, 'error' => 'At least one line item is required'], 422);
        }

        $type      = in_array($body['type'] ?? $body['invoice_type'] ?? '', ['invoice','quote','credit_note'], true)
                       ? ($body['type'] ?? $body['invoice_type']) : 'invoice';
        $storeId   = (int)$body['store_id'];
        $sc        = storeCode($pdo, $storeId);
        $invNumber = nextInvoiceNumber($pdo, $sc, $type);

        $stmt = $pdo->prepare(
            'INSERT INTO invoices
             (invoice_number, store_code, invoice_type, status, customer_id,
              invoice_date, due_date, notes,
              created_by, created_at, updated_at)
             VALUES (?,?,?,?,?,?,?,?,?,NOW(),NOW())'
        );
        $stmt->execute([
            $invNumber,
            $sc,
            $type,
            'draft',
            (int)$body['customer_id'],
            $body['invoice_date'],
            $body['due_date']  ?? null,
            $body['notes']     ?? null,
            (int)$auth['user_id'],
        ]);
        $newId = (int)$pdo->lastInsertId();

        insertItems($pdo, $newId, $body['items']);
        recalcTotals($pdo, $newId);

        auditLog($pdo, (int)$auth['user_id'], $auth['username'], $sc,
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

        // Detect backdating (log it in notes but don't use a missing schema column)
        $backdated = false;
        if (!empty($body['invoice_date'])) {
            $invDate   = new DateTimeImmutable($body['invoice_date']);
            $createdAt = new DateTimeImmutable($old['created_at']);
            $backdated = $invDate < $createdAt->setTime(0, 0, 0);
        }

        $pdo->prepare(
            'UPDATE invoices
             SET customer_id    = COALESCE(?, customer_id),
                 invoice_date   = COALESCE(?, invoice_date),
                 due_date       = COALESCE(?, due_date),
                 status         = COALESCE(?, status),
                 notes          = ?,
                 updated_at     = NOW()
             WHERE id = ?'
        )->execute([
            !empty($body['customer_id']) ? (int)$body['customer_id'] : null,
            $body['invoice_date']    ?? null,
            $body['due_date']        ?? null,
            $body['status']          ?? null,
            $body['notes']           ?? $old['notes'],
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

        $sc     = $old['store_code'];
        $action = 'update' . ($backdated ? '_backdated' : '');
        auditLog($pdo, (int)$auth['user_id'], $auth['username'], $sc,
            $action, 'invoice', $id, $old, $new);

        jsonResponse(['success' => true, 'data' => fetchInvoiceFull($pdo, $id)]);
    }

    // ════════════════════════════════════════════════════════════════════════
    // DELETE (soft — set status = cancelled)
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
            "UPDATE invoices SET status = 'cancelled', updated_at = NOW() WHERE id = ?"
        )->execute([$id]);

        $sc = $old['store_code'];
        auditLog($pdo, (int)$auth['user_id'], $auth['username'], $sc,
            'delete', 'invoice', $id,
            ['status' => $old['status']], ['status' => 'cancelled']);

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