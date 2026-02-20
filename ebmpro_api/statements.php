<?php
require_once __DIR__ . '/common.php';

setCorsHeaders();
header('Content-Type: application/json; charset=utf-8');

$auth   = requireAuth();
$method = $_SERVER['REQUEST_METHOD'];

if ($method !== 'GET') {
    jsonResponse(['success' => false, 'error' => 'Method not allowed'], 405);
}

$customerId = (int)($_GET['customer_id'] ?? 0);
if (!$customerId) {
    jsonResponse(['success' => false, 'error' => 'customer_id parameter required'], 422);
}

$dateFrom = $_GET['date_from'] ?? null;
$dateTo   = $_GET['date_to']   ?? null;

try {
    $pdo = getDb();

    // ── Customer details ──────────────────────────────────────────────────────
    $stmt = $pdo->prepare('SELECT * FROM customers WHERE id = ?');
    $stmt->execute([$customerId]);
    $customer = $stmt->fetch();
    if (!$customer) {
        jsonResponse(['success' => false, 'error' => 'Customer not found'], 404);
    }

    // ── Opening balance: sum of invoice balances before date_from ─────────────
    $openingBalance = 0.0;
    if ($dateFrom) {
        $stmt = $pdo->prepare(
            "SELECT COALESCE(SUM(balance), 0)
             FROM invoices
             WHERE customer_id = ?
               AND status != 'cancelled'
               AND invoice_date < ?"
        );
        $stmt->execute([$customerId, $dateFrom]);
        $openingBalance = round((float)$stmt->fetchColumn(), 2);
    }

    // ── Invoices within period ────────────────────────────────────────────────
    $where  = ["customer_id = ?", "status != 'cancelled'"];
    $params = [$customerId];

    if ($dateFrom) {
        $where[]  = 'invoice_date >= ?';
        $params[] = $dateFrom;
    }
    if ($dateTo) {
        $where[]  = 'invoice_date <= ?';
        $params[] = $dateTo;
    }

    $sql = 'SELECT id, invoice_number, invoice_type, invoice_date, due_date,
                   total, amount_paid, balance, status
            FROM invoices
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY invoice_date ASC, id ASC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $invoices = $stmt->fetchAll();

    // Attach payments per invoice
    foreach ($invoices as &$inv) {
        $ps = $pdo->prepare(
            'SELECT payment_date, amount, method, reference
             FROM payments WHERE invoice_id = ? ORDER BY payment_date ASC'
        );
        $ps->execute([$inv['id']]);
        $inv['payments'] = $ps->fetchAll();
    }
    unset($inv);

    // ── Closing balance ───────────────────────────────────────────────────────
    $periodBalance  = array_sum(array_column($invoices, 'balance'));
    $closingBalance = round($openingBalance + $periodBalance, 2);

    jsonResponse([
        'success' => true,
        'data'    => [
            'customer'        => $customer,
            'date_from'       => $dateFrom,
            'date_to'         => $dateTo,
            'opening_balance' => $openingBalance,
            'invoices'        => $invoices,
            'closing_balance' => $closingBalance,
            'generated_at'    => date('Y-m-d H:i:s'),
        ],
    ]);

} catch (PDOException $e) {
    error_log('statements.php DB error: ' . $e->getMessage());
    jsonResponse(['success' => false, 'error' => 'Service unavailable'], 503);
} catch (Throwable $e) {
    error_log('statements.php error: ' . $e->getMessage());
    jsonResponse(['success' => false, 'error' => 'Internal server error'], 500);
}
