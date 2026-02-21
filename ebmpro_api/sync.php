<?php
require_once __DIR__ . '/common.php';

setCorsHeaders();
header('Content-Type: application/json; charset=utf-8');

$auth   = requireAuth();
$method = $_SERVER['REQUEST_METHOD'];
$pdo    = getDb();

// ── Helper: apply a single queued action ─────────────────────────────────────
function applySyncAction(PDO $pdo, array $auth, array $item): array
{
    $action     = $item['action']      ?? '';
    $entityType = $item['entity_type'] ?? '';
    $entityId   = !empty($item['entity_id']) ? (int)$item['entity_id'] : null;
    $payload    = $item['payload']     ?? [];

    switch ("{$action}:{$entityType}") {
        case 'create:invoice':  return syncCreateInvoice($pdo, $auth, $payload);
        case 'update:invoice':
            if (!$entityId) {
                return ['ok' => false, 'error' => 'entity_id required for update:invoice'];
            }
            return syncUpdateInvoice($pdo, $auth, $entityId, $payload);
        case 'create:customer': return syncCreateCustomer($pdo, $auth, $payload);
        case 'update:customer':
            if (!$entityId) {
                return ['ok' => false, 'error' => 'entity_id required for update:customer'];
            }
            return syncUpdateCustomer($pdo, $auth, $entityId, $payload);
        case 'create:payment':  return syncCreatePayment($pdo, $auth, $payload);
        default:
            return ['ok' => false, 'error' => "Unsupported action/entity: {$action}:{$entityType}"];
    }
}

// ── Sync: create invoice ──────────────────────────────────────────────────────
// invoice_sequences: store_code PK, next_invoice_num, next_quote_num
function syncCreateInvoice(PDO $pdo, array $auth, array $b): array
{
    if ((empty($b['store_id']) && empty($b['store_code'])) || empty($b['customer_id']) || empty($b['invoice_date'])
        || empty($b['items'])) {
        return ['ok' => false, 'error' => 'Missing required invoice fields'];
    }

    // Accept store_code directly or resolve from numeric store_id
    if (!empty($b['store_code'])) {
        $storeCode = strtoupper(trim($b['store_code']));
    } else {
        $storeId = (int)$b['store_id'];
        $sStmt = $pdo->prepare('SELECT code FROM stores WHERE id = ? LIMIT 1');
        $sStmt->execute([$storeId]);
        $storeRow  = $sStmt->fetch();
        $storeCode = $storeRow ? $storeRow['code'] : 'store';
    }

    $type = in_array($b['type'] ?? $b['invoice_type'] ?? '', ['invoice','quote','credit_note'], true)
            ? ($b['type'] ?? $b['invoice_type']) : 'invoice';
    $seqCol    = ($type === 'quote') ? 'next_quote_num' : 'next_invoice_num';
    $prefixCol = ($type === 'quote') ? 'quote_prefix'   : 'invoice_prefix';

    $pdo->beginTransaction();
    try {
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

        // Fetch prefix from stores table
        $storeRow2 = $pdo->prepare('SELECT ' . $prefixCol . ' FROM stores WHERE code = ? LIMIT 1');
        $storeRow2->execute([$storeCode]);
        $pr        = $storeRow2->fetch();
        $prefix    = $pr ? ($pr[$prefixCol] ?? strtoupper(substr($storeCode, 0, 3))) : strtoupper(substr($storeCode, 0, 3));

        $invNumber = $prefix . '-' . str_pad($current, 4, '0', STR_PAD_LEFT);

        $pdo->prepare(
            'INSERT INTO invoices
             (invoice_number, store_code, invoice_type, status, customer_id,
              invoice_date, due_date, notes, created_by,
              created_at, updated_at)
             VALUES (?,?,?,?,?,?,?,?,?,NOW(),NOW())'
        )->execute([
            $invNumber, $storeCode, $type, 'draft', (int)$b['customer_id'],
            $b['invoice_date'], $b['due_date'] ?? null,
            $b['notes'] ?? null, (int)$auth['user_id'],
        ]);
        $newId = (int)$pdo->lastInsertId();

        // Insert items
        $itemStmt = $pdo->prepare(
            'INSERT INTO invoice_items
             (invoice_id, line_order, product_code, description,
              quantity, unit_price, discount_pct, vat_rate, vat_amount, line_total)
             VALUES (?,?,?,?,?,?,?,?,?,?)'
        );
        foreach ($b['items'] as $i => $item) {
            $qty       = round((float)($item['quantity'] ?? $item['qty'] ?? 1), 3);
            $price     = round((float)($item['unit_price'] ?? 0), 2);
            $discPct   = round((float)($item['discount_pct'] ?? 0), 2);
            $vatRate   = round((float)($item['vat_rate'] ?? 23), 2);
            $lineNet   = round($qty * $price * (1 - $discPct / 100), 2);
            $vatAmount = round($lineNet * $vatRate / 100, 2);
            $itemStmt->execute([
                $newId, (int)($item['line_order'] ?? $item['sort_order'] ?? $i),
                $item['product_code'] ?? $item['code'] ?? null,
                $item['description'] ?? '',
                $qty, $price, $discPct, $vatRate, $vatAmount,
                round($lineNet + $vatAmount, 2),
            ]);
        }

        // Recalc totals
        $tots = $pdo->prepare(
            'SELECT SUM(line_total - vat_amount) AS sub, SUM(vat_amount) AS vat
             FROM invoice_items WHERE invoice_id = ?'
        );
        $tots->execute([$newId]);
        $t        = $tots->fetch();
        $subtotal = round((float)$t['sub'], 2);
        $vatTotal = round((float)$t['vat'], 2);
        $total    = round($subtotal + $vatTotal, 2);
        $pdo->prepare(
            'UPDATE invoices SET subtotal=?, vat_total=?, total=?, balance=? WHERE id=?'
        )->execute([$subtotal, $vatTotal, $total, $total, $newId]);

        $pdo->commit();
        return ['ok' => true, 'entity_id' => $newId];
    } catch (Throwable $e) {
        $pdo->rollBack();
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

function syncUpdateInvoice(PDO $pdo, array $auth, int $id, array $b): array
{
    $stmt = $pdo->prepare('SELECT id FROM invoices WHERE id = ?');
    $stmt->execute([$id]);
    if (!$stmt->fetch()) {
        return ['ok' => false, 'error' => 'Invoice not found'];
    }
    $pdo->prepare(
        'UPDATE invoices SET
         customer_id  = COALESCE(?, customer_id),
         invoice_date = COALESCE(?, invoice_date),
         due_date     = COALESCE(?, due_date),
         status       = COALESCE(?, status),
         notes        = COALESCE(?, notes),
         updated_at   = NOW()
         WHERE id = ?'
    )->execute([
        !empty($b['customer_id']) ? (int)$b['customer_id'] : null,
        $b['invoice_date'] ?? null,
        $b['due_date']     ?? null,
        $b['status']       ?? null,
        $b['notes']        ?? null,
        $id,
    ]);
    return ['ok' => true, 'entity_id' => $id];
}

function syncCreateCustomer(PDO $pdo, array $auth, array $b): array
{
    if (empty($b['company_name'] ?? $b['name'])) {
        return ['ok' => false, 'error' => 'Customer company_name required'];
    }
    $companyName = trim($b['company_name'] ?? $b['name']);
    $acct = !empty($b['account_no']) ? $b['account_no']
        : ('CUS-' . str_pad(random_int(1000, 9999), 4, '0', STR_PAD_LEFT));
    $pdo->prepare(
        'INSERT INTO customers
         (account_no, company_name, email_address, inv_telephone, address_1, inv_town, created_at, updated_at)
         VALUES (?,?,?,?,?,?,NOW(),NOW())'
    )->execute([
        $acct, $companyName,
        $b['email_address'] ?? $b['email']     ?? null,
        $b['inv_telephone'] ?? $b['telephone'] ?? null,
        $b['address_1']     ?? $b['address']   ?? null,
        $b['inv_town']      ?? $b['town']       ?? null,
    ]);
    return ['ok' => true, 'entity_id' => (int)$pdo->lastInsertId()];
}

function syncUpdateCustomer(PDO $pdo, array $auth, int $id, array $b): array
{
    $stmt = $pdo->prepare('SELECT id FROM customers WHERE id = ?');
    $stmt->execute([$id]);
    if (!$stmt->fetch()) {
        return ['ok' => false, 'error' => 'Customer not found'];
    }
    $pdo->prepare(
        'UPDATE customers SET
         company_name  = COALESCE(?, company_name),
         email_address = COALESCE(?, email_address),
         inv_telephone = COALESCE(?, inv_telephone),
         address_1     = COALESCE(?, address_1),
         inv_town      = COALESCE(?, inv_town),
         updated_at    = NOW()
         WHERE id = ?'
    )->execute([
        $b['company_name'] ?? $b['name']      ?? null,
        $b['email_address'] ?? $b['email']    ?? null,
        $b['inv_telephone'] ?? $b['telephone'] ?? null,
        $b['address_1']     ?? $b['address']   ?? null,
        $b['inv_town']      ?? $b['town']       ?? null,
        $id,
    ]);
    return ['ok' => true, 'entity_id' => $id];
}

function syncCreatePayment(PDO $pdo, array $auth, array $b): array
{
    if (empty($b['invoice_id']) || empty($b['amount']) || empty($b['payment_date'])) {
        return ['ok' => false, 'error' => 'invoice_id, amount, payment_date required'];
    }
    $invoiceId = (int)$b['invoice_id'];
    $amount    = round((float)$b['amount'], 2);
    if ($amount <= 0) {
        return ['ok' => false, 'error' => 'Amount must be positive'];
    }
    $pdo->prepare(
        'INSERT INTO payments (invoice_id, amount, payment_date, method, reference, created_by, created_at)
         VALUES (?,?,?,?,?,?,NOW())'
    )->execute([
        $invoiceId, $amount, $b['payment_date'],
        $b['method']    ?? 'cash',
        $b['reference'] ?? null,
        (int)$auth['user_id'],
    ]);
    $newId = (int)$pdo->lastInsertId();

    // Sync invoice balance
    $row = $pdo->prepare('SELECT total FROM invoices WHERE id = ?');
    $row->execute([$invoiceId]);
    $inv = $row->fetch();
    if ($inv) {
        $total = round((float)$inv['total'], 2);
        $ps    = $pdo->prepare('SELECT COALESCE(SUM(amount),0) FROM payments WHERE invoice_id = ?');
        $ps->execute([$invoiceId]);
        $paid    = round((float)$ps->fetchColumn(), 2);
        $balance = round($total - $paid, 2);
        $status  = $balance <= 0 ? 'paid' : ($paid > 0 ? 'part_paid' : 'sent');
        $pdo->prepare(
            'UPDATE invoices SET amount_paid=?, balance=?, status=?, updated_at=NOW() WHERE id=?'
        )->execute([$paid, $balance, $status, $invoiceId]);
    }
    return ['ok' => true, 'entity_id' => $newId];
}

// ════════════════════════════════════════════════════════════════════════════
try {
    // ── POST: receive and process sync queue ─────────────────────────────────
    if ($method === 'POST') {
        $body     = json_decode(file_get_contents('php://input'), true) ?? [];
        $deviceId = $body['device_id'] ?? '';
        $queue    = $body['queue']     ?? [];

        if (!is_array($queue)) {
            jsonResponse(['success' => false, 'error' => 'queue must be an array'], 422);
        }

        $processed = 0;
        $errors    = [];

        foreach ($queue as $idx => $item) {
            $result = applySyncAction($pdo, $auth, $item);

            // Persist to sync_queue
            try {
                $pdo->prepare(
                    'INSERT INTO sync_queue
                     (client_id, action, table_name, record_id, payload, queued_at, processed_at, status)
                     VALUES (?,?,?,?,?,NOW(),?,?)'
                )->execute([
                    $body['device_id'] ?? $body['client_id'] ?? '',
                    $item['action']      ?? '',
                    $item['entity_type'] ?? $item['table_name'] ?? '',
                    !empty($item['entity_id']) ? (string)$item['entity_id'] : null,
                    json_encode($item['payload'] ?? []),
                    $result['ok'] ? date('Y-m-d H:i:s') : null,
                    $result['ok'] ? 'done' : 'error',
                ]);
            } catch (PDOException $e) {
                error_log('sync_queue insert error: ' . $e->getMessage());
            }

            if ($result['ok']) {
                $processed++;
            } else {
                $errors[] = ['index' => $idx, 'error' => $result['error'] ?? 'unknown'];
            }
        }

        jsonResponse([
            'success'   => true,
            'processed' => $processed,
            'errors'    => $errors,
        ]);
    }

    // ── GET: changes since a given timestamp ──────────────────────────────────
    if ($method === 'GET') {
        $since = $_GET['since'] ?? null;
        if (!$since) {
            jsonResponse(['success' => false, 'error' => 'since parameter required'], 422);
        }

        $sinceTs = strtotime($since);
        if ($sinceTs === false) {
            jsonResponse(['success' => false, 'error' => 'Invalid since timestamp'], 422);
        }
        $sinceFormatted = date('Y-m-d H:i:s', $sinceTs);

        $stmt = $pdo->prepare(
            'SELECT id, invoice_number, customer_id, status, total, balance, updated_at
             FROM invoices WHERE updated_at > ? ORDER BY updated_at ASC LIMIT 1000'
        );
        $stmt->execute([$sinceFormatted]);
        $invoices = $stmt->fetchAll();

        $stmt = $pdo->prepare(
            'SELECT id, account_no, company_name, email_address, inv_telephone, updated_at
             FROM customers WHERE updated_at > ?
             ORDER BY updated_at ASC LIMIT 1000'
        );
        $stmt->execute([$sinceFormatted]);
        $customers = $stmt->fetchAll();

        $stmt = $pdo->prepare(
            'SELECT id, invoice_id, amount, payment_date, method, created_at
             FROM payments WHERE created_at > ? ORDER BY created_at ASC LIMIT 1000'
        );
        $stmt->execute([$sinceFormatted]);
        $payments = $stmt->fetchAll();

        jsonResponse([
            'success'   => true,
            'since'     => $sinceFormatted,
            'data'      => [
                'invoices'  => $invoices,
                'customers' => $customers,
                'payments'  => $payments,
            ],
        ]);
    }

    jsonResponse(['success' => false, 'error' => 'Method not allowed'], 405);

} catch (PDOException $e) {
    error_log('sync.php DB error: ' . $e->getMessage());
    jsonResponse(['success' => false, 'error' => 'Service unavailable'], 503);
} catch (Throwable $e) {
    error_log('sync.php error: ' . $e->getMessage());
    jsonResponse(['success' => false, 'error' => 'Internal server error'], 500);
}