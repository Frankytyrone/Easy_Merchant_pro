<?php
require_once __DIR__ . '/common.php';

setCorsHeaders();
header('Content-Type: application/json; charset=utf-8');

$auth   = requireAuth();
$method = $_SERVER['REQUEST_METHOD'];
$pdo    = getDb();

// ── Helper: recalculate invoice balance and update status ─────────────────────
function syncInvoiceBalance(PDO $pdo, int $invoiceId): void
{
    $stmt = $pdo->prepare('SELECT total FROM invoices WHERE id = ?');
    $stmt->execute([$invoiceId]);
    $row = $stmt->fetch();
    if (!$row) {
        return;
    }
    $total = round((float)$row['total'], 2);

    $stmt = $pdo->prepare('SELECT COALESCE(SUM(amount), 0) FROM payments WHERE invoice_id = ?');
    $stmt->execute([$invoiceId]);
    $paid    = round((float)$stmt->fetchColumn(), 2);
    $balance = round($total - $paid, 2);

    $status = 'pending';
    if ($balance <= 0) {
        $status = 'paid';
    } elseif ($paid > 0) {
        $status = 'part_paid';
    }

    $pdo->prepare(
        'UPDATE invoices SET amount_paid = ?, balance = ?, status = ?, updated_at = NOW()
         WHERE id = ?'
    )->execute([$paid, $balance, $status, $invoiceId]);
}

try {
    // ════════════════════════════════════════════════════════════════════════
    // GET — list payments for invoice
    // ════════════════════════════════════════════════════════════════════════
    if ($method === 'GET') {
        $invoiceId = (int)($_GET['invoice_id'] ?? 0);
        if (!$invoiceId) {
            jsonResponse(['success' => false, 'error' => 'invoice_id parameter required'], 422);
        }
        $stmt = $pdo->prepare(
            'SELECT * FROM payments WHERE invoice_id = ? ORDER BY payment_date ASC, id ASC'
        );
        $stmt->execute([$invoiceId]);
        jsonResponse(['success' => true, 'data' => $stmt->fetchAll()]);
    }

    // ════════════════════════════════════════════════════════════════════════
    // POST — record a payment
    // ════════════════════════════════════════════════════════════════════════
    if ($method === 'POST') {
        $body = json_decode(file_get_contents('php://input'), true) ?? [];

        $required = ['invoice_id', 'amount', 'payment_date'];
        foreach ($required as $f) {
            if (empty($body[$f])) {
                jsonResponse(['success' => false, 'error' => "Field '$f' is required"], 422);
            }
        }

        $invoiceId = (int)$body['invoice_id'];
        $amount    = round((float)$body['amount'], 2);

        if ($amount <= 0) {
            jsonResponse(['success' => false, 'error' => 'Amount must be greater than zero'], 422);
        }

        $stmt = $pdo->prepare('SELECT id, store_id, balance FROM invoices WHERE id = ?');
        $stmt->execute([$invoiceId]);
        $invoice = $stmt->fetch();
        if (!$invoice) {
            jsonResponse(['success' => false, 'error' => 'Invoice not found'], 404);
        }

        $stmt = $pdo->prepare(
            'INSERT INTO payments
             (invoice_id, amount, payment_date, method, reference, notes, recorded_by, created_at)
             VALUES (?,?,?,?,?,?,?,NOW())'
        );
        $stmt->execute([
            $invoiceId,
            $amount,
            $body['payment_date'],
            $body['method']    ?? 'cash',
            $body['reference'] ?? null,
            $body['notes']     ?? null,
            (int)$auth['user_id'],
        ]);
        $newId = (int)$pdo->lastInsertId();

        syncInvoiceBalance($pdo, $invoiceId);

        $stmt = $pdo->prepare('SELECT * FROM payments WHERE id = ?');
        $stmt->execute([$newId]);
        $payment = $stmt->fetch();

        auditLog($pdo, (int)$auth['user_id'], $auth['username'], (int)$invoice['store_id'],
            'create', 'payment', $newId, null, $payment);

        jsonResponse(['success' => true, 'data' => $payment], 201);
    }

    // ════════════════════════════════════════════════════════════════════════
    // DELETE — remove payment and recalculate balance
    // ════════════════════════════════════════════════════════════════════════
    if ($method === 'DELETE') {
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) {
            jsonResponse(['success' => false, 'error' => 'id parameter required'], 422);
        }

        $stmt = $pdo->prepare('SELECT * FROM payments WHERE id = ?');
        $stmt->execute([$id]);
        $payment = $stmt->fetch();
        if (!$payment) {
            jsonResponse(['success' => false, 'error' => 'Payment not found'], 404);
        }

        $pdo->prepare('DELETE FROM payments WHERE id = ?')->execute([$id]);
        syncInvoiceBalance($pdo, (int)$payment['invoice_id']);

        $stmt = $pdo->prepare('SELECT store_id FROM invoices WHERE id = ?');
        $stmt->execute([(int)$payment['invoice_id']]);
        $inv = $stmt->fetch();

        auditLog($pdo, (int)$auth['user_id'], $auth['username'],
            $inv ? (int)$inv['store_id'] : null,
            'delete', 'payment', $id, $payment, null);

        jsonResponse(['success' => true, 'message' => 'Payment deleted']);
    }

    jsonResponse(['success' => false, 'error' => 'Method not allowed'], 405);

} catch (PDOException $e) {
    error_log('payments.php DB error: ' . $e->getMessage());
    jsonResponse(['success' => false, 'error' => 'Service unavailable'], 503);
} catch (Throwable $e) {
    error_log('payments.php error: ' . $e->getMessage());
    jsonResponse(['success' => false, 'error' => 'Internal server error'], 500);
}
