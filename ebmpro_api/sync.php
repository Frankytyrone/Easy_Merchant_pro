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
        // ── Invoices ──────────────────────────────────────────────────────
        case 'create:invoice':
            require_once __DIR__ . '/invoices.php';
            // invoices.php is a script file; call helper functions directly
            // We re-implement a minimal inline path here to avoid double-output.
            return syncCreateInvoice($pdo, $auth, $payload);

        case 'update:invoice':
            if (!$entityId) {
                return ['ok' => false, 'error' => 'entity_id required for update:invoice'];
            }
            return syncUpdateInvoice($pdo, $auth, $entityId, $payload);

        // ── Customers ─────────────────────────────────────────────────────
        case 'create:customer':
            return syncCreateCustomer($pdo, $auth, $payload);

        case 'update:customer':
            if (!$entityId) {
                return ['ok' => false, 'error' => 'entity_id required for update:customer'];
            }
            return syncUpdateCustomer($pdo, $auth, $entityId, $payload);

        // ── Payments ──────────────────────────────────────────────────────
        case 'create:payment':
            return syncCreatePayment($pdo, $auth, $payload);

        default:
            return ['ok' => false, 'error' => "Unsupported action/entity: {$action}:{$entityType}"];
    }
}

// ── Inline sync helpers (minimal; avoid re-including full script files) ───────
function syncCreateInvoice(PDO $pdo, array $auth, array $b): array
{
    if (empty($b['store_id']) || empty($b['customer_id']) || empty($b['invoice_date'])
        || empty($b['due_date']) || empty($b['items'])) {
        return ['ok' => false, 'error' => 'Missing required invoice fields'];
    }
    $type      = in_array($b['type'] ?? '', ['invoice','quote','credit_note'], true) ? $b['type'] : 'invoice';
    $storeId   = (int)$b['store_id'];

    // nextInvoiceNumber is defined in invoices.php — inline it here
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            'SELECT id, prefix, last_number FROM invoice_sequences WHERE store_id = ? AND type = ? FOR UPDATE'
        );
        $stmt->execute([$storeId, $type]);
        $seq = $stmt->fetch();
        if (!$seq) {
            $pfx = strtoupper(substr($type, 0, 3));
            $pdo->prepare('INSERT INTO invoice_sequences (store_id, type, prefix, last_number) VALUES (?,?,?,0)')
                ->execute([$storeId, $type, $pfx]);
            $seqId = (int)$pdo->lastInsertId();
            $next  = 1001;
        } else {
            $seqId = (int)$seq['id'];
            $next  = (int)$seq['last_number'] + 1;
            $pfx   = $seq['prefix'];
        }
        $pdo->prepare('UPDATE invoice_sequences SET last_number = ? WHERE id = ?')->execute([$next, $seqId]);
        $invNumber = $pfx . '-' . str_pad($next, 4, '0', STR_PAD_LEFT);

        $pdo->prepare(
            'INSERT INTO invoices
             (store_id, customer_id, invoice_number, type, status, invoice_date, due_date,
              notes, terms, currency, created_by, created_at, updated_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())'
        )->execute([
            $storeId, (int)$b['customer_id'], $invNumber, $type, 'pending',
            $b['invoice_date'], $b['due_date'],
            $b['notes'] ?? null, $b['terms'] ?? null, $b['currency'] ?? 'ZAR',
            (int)$auth['user_id'],
        ]);
        $newId = (int)$pdo->lastInsertId();

        $itemStmt = $pdo->prepare(
            'INSERT INTO invoice_items
             (invoice_id, product_id, description, quantity, unit_price, vat_rate, vat_amount, line_total)
             VALUES (?,?,?,?,?,?,?,?)'
        );
        foreach ($b['items'] as $item) {
            $qty   = round((float)($item['quantity']   ?? 1), 4);
            $price = round((float)($item['unit_price'] ?? 0), 4);
            $vr    = round((float)($item['vat_rate']   ?? 0), 4);
            $net   = round($qty * $price, 2);
            $vat   = round($net * $vr / 100, 2);
            $itemStmt->execute([
                $newId,
                !empty($item['product_id']) ? (int)$item['product_id'] : null,
                $item['description'] ?? '',
                $qty, $price, $vr, $vat, round($net + $vat, 2),
            ]);
        }

        // Recalc totals
        $totRow = $pdo->prepare(
            'SELECT SUM(quantity*unit_price) AS sub, SUM(vat_amount) AS vat FROM invoice_items WHERE invoice_id = ?'
        );
        $totRow->execute([$newId]);
        $tots     = $totRow->fetch();
        $subtotal = round((float)$tots['sub'], 2);
        $vatTotal = round((float)$tots['vat'], 2);
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
        !empty($b['customer_id'])  ? (int)$b['customer_id']  : null,
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
    if (empty($b['name'])) {
        return ['ok' => false, 'error' => 'Customer name required'];
    }
    $acct = !empty($b['account_no']) ? $b['account_no']
        : ('CUS-' . str_pad(random_int(1000, 9999), 4, '0', STR_PAD_LEFT));
    $pdo->prepare(
        'INSERT INTO customers
         (store_id, account_no, name, email, telephone, address, created_at, updated_at)
         VALUES (?,?,?,?,?,?,NOW(),NOW())'
    )->execute([
        !empty($b['store_id']) ? (int)$b['store_id'] : null,
        $acct, trim($b['name']),
        $b['email']     ?? null,
        $b['telephone'] ?? null,
        $b['address']   ?? null,
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
         name      = COALESCE(?, name),
         email     = COALESCE(?, email),
         telephone = COALESCE(?, telephone),
         address   = COALESCE(?, address),
         updated_at = NOW()
         WHERE id = ?'
    )->execute([
        $b['name']      ?? null,
        $b['email']     ?? null,
        $b['telephone'] ?? null,
        $b['address']   ?? null,
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
        'INSERT INTO payments (invoice_id, amount, payment_date, method, reference, recorded_by, created_at)
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
        $status  = $balance <= 0 ? 'paid' : ($paid > 0 ? 'part_paid' : 'pending');
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

            // Persist to sync_queue regardless of outcome
            try {
                $pdo->prepare(
                    'INSERT INTO sync_queue
                     (device_id, action, entity_type, entity_id, payload, processed, error, created_at)
                     VALUES (?,?,?,?,?,?,?,NOW())'
                )->execute([
                    $deviceId,
                    $item['action']      ?? '',
                    $item['entity_type'] ?? '',
                    !empty($item['entity_id']) ? (int)$item['entity_id'] : null,
                    json_encode($item['payload'] ?? []),
                    $result['ok'] ? 1 : 0,
                    $result['ok'] ? null : ($result['error'] ?? 'unknown'),
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

        // Validate timestamp format
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
            'SELECT id, account_no, name, email, telephone, updated_at
             FROM customers WHERE updated_at > ? AND deleted_at IS NULL
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
