<?php
require_once __DIR__ . '/common.php';

setCorsHeaders();
header('Content-Type: application/json; charset=utf-8');

$auth   = requireAuth();
$method = $_SERVER['REQUEST_METHOD'];
$pdo    = getDb();

$validCategories = ['Materials','Fuel','Tools','Subcontractors','Office','Telephone','Insurance','Other'];

try {
    if ($method === 'GET') {
        $where  = ['1=1'];
        $params = [];
        if (!empty($_GET['store_id']))  { $where[] = 'store_id = ?';     $params[] = (int)$_GET['store_id']; }
        if (!empty($_GET['from']))      { $where[] = 'expense_date >= ?'; $params[] = $_GET['from']; }
        if (!empty($_GET['to']))        { $where[] = 'expense_date <= ?'; $params[] = $_GET['to']; }
        if (!empty($_GET['category']))  { $where[] = 'category = ?';     $params[] = $_GET['category']; }

        $sql = 'SELECT * FROM expenses WHERE ' . implode(' AND ', $where) . ' ORDER BY expense_date DESC, id DESC LIMIT 500';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        jsonResponse(['success' => true, 'data' => $stmt->fetchAll()]);
    }

    if ($method === 'POST') {
        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        foreach (['store_id', 'expense_date', 'category', 'description', 'amount'] as $f) {
            if (empty($body[$f]) && $body[$f] !== '0') {
                jsonResponse(['success' => false, 'error' => "Field '$f' is required"], 422);
            }
        }
        if (!in_array($body['category'], $validCategories, true)) {
            jsonResponse(['success' => false, 'error' => 'Invalid category'], 422);
        }

        $amount    = round((float)$body['amount'], 2);
        $vatRate   = round((float)($body['vat_rate'] ?? 0), 2);
        $vatAmount = round((float)($body['vat_amount'] ?? ($amount * $vatRate / 100)), 2);

        $stmt = $pdo->prepare(
            'INSERT INTO expenses (store_id, expense_date, category, description, amount, vat_rate, vat_amount,
             supplier, receipt_ref, created_by, created_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,NOW())'
        );
        $stmt->execute([
            (int)$body['store_id'],
            $body['expense_date'],
            $body['category'],
            $body['description'],
            $amount,
            $vatRate,
            $vatAmount,
            $body['supplier']    ?? null,
            $body['receipt_ref'] ?? null,
            (int)$auth['user_id'],
        ]);
        $newId = (int)$pdo->lastInsertId();

        $row = $pdo->prepare('SELECT * FROM expenses WHERE id = ?');
        $row->execute([$newId]);
        jsonResponse(['success' => true, 'data' => $row->fetch()], 201);
    }

    if ($method === 'PUT') {
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) jsonResponse(['success' => false, 'error' => 'id required'], 422);
        $stmt = $pdo->prepare('SELECT id FROM expenses WHERE id = ?');
        $stmt->execute([$id]);
        if (!$stmt->fetch()) jsonResponse(['success' => false, 'error' => 'Not found'], 404);

        $body      = json_decode(file_get_contents('php://input'), true) ?? [];
        $amount    = isset($body['amount'])     ? round((float)$body['amount'], 2) : null;
        $vatRate   = isset($body['vat_rate'])   ? round((float)$body['vat_rate'], 2) : null;
        $vatAmount = isset($body['vat_amount']) ? round((float)$body['vat_amount'], 2) : null;

        if (!empty($body['category']) && !in_array($body['category'], $validCategories, true)) {
            jsonResponse(['success' => false, 'error' => 'Invalid category'], 422);
        }

        $pdo->prepare(
            'UPDATE expenses SET
             expense_date = COALESCE(?, expense_date),
             category     = COALESCE(?, category),
             description  = COALESCE(?, description),
             amount       = COALESCE(?, amount),
             vat_rate     = COALESCE(?, vat_rate),
             vat_amount   = COALESCE(?, vat_amount),
             supplier     = COALESCE(?, supplier),
             receipt_ref  = COALESCE(?, receipt_ref)
             WHERE id = ?'
        )->execute([
            $body['expense_date'] ?? null,
            $body['category']     ?? null,
            $body['description']  ?? null,
            $amount,
            $vatRate,
            $vatAmount,
            $body['supplier']    ?? null,
            $body['receipt_ref'] ?? null,
            $id,
        ]);

        $row = $pdo->prepare('SELECT * FROM expenses WHERE id = ?');
        $row->execute([$id]);
        jsonResponse(['success' => true, 'data' => $row->fetch()]);
    }

    if ($method === 'DELETE') {
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) jsonResponse(['success' => false, 'error' => 'id required'], 422);
        $pdo->prepare('DELETE FROM expenses WHERE id = ?')->execute([$id]);
        jsonResponse(['success' => true, 'message' => 'Deleted']);
    }

    jsonResponse(['success' => false, 'error' => 'Method not allowed'], 405);

} catch (PDOException $e) {
    error_log('expenses.php DB error: ' . $e->getMessage());
    jsonResponse(['success' => false, 'error' => 'Service unavailable'], 503);
} catch (Throwable $e) {
    error_log('expenses.php error: ' . $e->getMessage());
    jsonResponse(['success' => false, 'error' => 'Internal server error'], 500);
}
