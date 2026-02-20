<?php
require_once __DIR__ . '/common.php';

setCorsHeaders();
header('Content-Type: application/json; charset=utf-8');

$auth   = requireAuth();
$method = $_SERVER['REQUEST_METHOD'];
$pdo    = getDb();

// ── Helper: generate account number ──────────────────────────────────────────
function generateAccountNo(PDO $pdo): string
{
    $attempts = 0;
    do {
        $candidate = 'CUS-' . str_pad(random_int(1000, 9999), 4, '0', STR_PAD_LEFT);
        $stmt = $pdo->prepare('SELECT id FROM customers WHERE account_no = ? LIMIT 1');
        $stmt->execute([$candidate]);
        $exists = $stmt->fetchColumn();
        $attempts++;
    } while ($exists && $attempts < 20);
    return $candidate;
}

try {
    // ════════════════════════════════════════════════════════════════════════
    // GET
    // ════════════════════════════════════════════════════════════════════════
    if ($method === 'GET') {
        // Single customer
        if (!empty($_GET['id'])) {
            $stmt = $pdo->prepare('SELECT * FROM customers WHERE id = ?');
            $stmt->execute([(int)$_GET['id']]);
            $customer = $stmt->fetch();
            if (!$customer) {
                jsonResponse(['success' => false, 'error' => 'Customer not found'], 404);
            }
            jsonResponse(['success' => true, 'data' => $customer]);
        }

        // List / search
        $where  = ['deleted_at IS NULL'];
        $params = [];

        if (!empty($_GET['q'])) {
            $like     = '%' . $_GET['q'] . '%';
            $where[]  = '(name LIKE ? OR account_no LIKE ? OR email LIKE ? OR telephone LIKE ?)';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        if (!empty($_GET['store_id'])) {
            $where[]  = 'store_id = ?';
            $params[] = (int)$_GET['store_id'];
        }

        $sql  = 'SELECT * FROM customers WHERE ' . implode(' AND ', $where)
              . ' ORDER BY name ASC LIMIT 500';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        jsonResponse(['success' => true, 'data' => $stmt->fetchAll()]);
    }

    // ════════════════════════════════════════════════════════════════════════
    // POST — create
    // ════════════════════════════════════════════════════════════════════════
    if ($method === 'POST') {
        $body = json_decode(file_get_contents('php://input'), true) ?? [];

        if (empty($body['name'])) {
            jsonResponse(['success' => false, 'error' => 'name is required'], 422);
        }

        $accountNo = !empty($body['account_no'])
            ? trim($body['account_no'])
            : generateAccountNo($pdo);

        // Check uniqueness
        $stmt = $pdo->prepare('SELECT id FROM customers WHERE account_no = ? LIMIT 1');
        $stmt->execute([$accountNo]);
        if ($stmt->fetchColumn()) {
            jsonResponse(['success' => false, 'error' => 'account_no already exists'], 409);
        }

        $stmt = $pdo->prepare(
            'INSERT INTO customers
             (store_id, account_no, name, email, telephone, address, vat_number,
              credit_limit, is_cash_sale, created_at, updated_at)
             VALUES (?,?,?,?,?,?,?,?,?,NOW(),NOW())'
        );
        $stmt->execute([
            !empty($body['store_id']) ? (int)$body['store_id'] : null,
            $accountNo,
            trim($body['name']),
            $body['email']        ?? null,
            $body['telephone']    ?? null,
            $body['address']      ?? null,
            $body['vat_number']   ?? null,
            !empty($body['credit_limit']) ? (float)$body['credit_limit'] : 0,
            isset($body['is_cash_sale']) ? (int)(bool)$body['is_cash_sale'] : 0,
        ]);
        $newId = (int)$pdo->lastInsertId();

        $stmt = $pdo->prepare('SELECT * FROM customers WHERE id = ?');
        $stmt->execute([$newId]);
        $customer = $stmt->fetch();

        auditLog($pdo, (int)$auth['user_id'], $auth['username'],
            $customer['store_id'] ?? null,
            'create', 'customer', $newId, null, $customer);

        jsonResponse(['success' => true, 'data' => $customer], 201);
    }

    // ════════════════════════════════════════════════════════════════════════
    // PUT — update
    // ════════════════════════════════════════════════════════════════════════
    if ($method === 'PUT') {
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) {
            jsonResponse(['success' => false, 'error' => 'id parameter required'], 422);
        }

        $stmt = $pdo->prepare('SELECT * FROM customers WHERE id = ? AND deleted_at IS NULL');
        $stmt->execute([$id]);
        $old = $stmt->fetch();
        if (!$old) {
            jsonResponse(['success' => false, 'error' => 'Customer not found'], 404);
        }

        $body = json_decode(file_get_contents('php://input'), true) ?? [];

        $pdo->prepare(
            'UPDATE customers
             SET name         = COALESCE(?, name),
                 email        = COALESCE(?, email),
                 telephone    = COALESCE(?, telephone),
                 address      = COALESCE(?, address),
                 vat_number   = COALESCE(?, vat_number),
                 credit_limit = COALESCE(?, credit_limit),
                 is_cash_sale = COALESCE(?, is_cash_sale),
                 updated_at   = NOW()
             WHERE id = ?'
        )->execute([
            $body['name']         ?? null,
            $body['email']        ?? null,
            $body['telephone']    ?? null,
            $body['address']      ?? null,
            $body['vat_number']   ?? null,
            isset($body['credit_limit']) ? (float)$body['credit_limit'] : null,
            isset($body['is_cash_sale']) ? (int)(bool)$body['is_cash_sale'] : null,
            $id,
        ]);

        $stmt = $pdo->prepare('SELECT * FROM customers WHERE id = ?');
        $stmt->execute([$id]);
        $new = $stmt->fetch();

        auditLog($pdo, (int)$auth['user_id'], $auth['username'],
            $old['store_id'] ?? null,
            'update', 'customer', $id, $old, $new);

        jsonResponse(['success' => true, 'data' => $new]);
    }

    // ════════════════════════════════════════════════════════════════════════
    // DELETE — prevent if has invoices, otherwise soft-delete
    // ════════════════════════════════════════════════════════════════════════
    if ($method === 'DELETE') {
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) {
            jsonResponse(['success' => false, 'error' => 'id parameter required'], 422);
        }

        $stmt = $pdo->prepare('SELECT * FROM customers WHERE id = ? AND deleted_at IS NULL');
        $stmt->execute([$id]);
        $old = $stmt->fetch();
        if (!$old) {
            jsonResponse(['success' => false, 'error' => 'Customer not found'], 404);
        }

        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM invoices WHERE customer_id = ? AND status != 'cancelled'"
        );
        $stmt->execute([$id]);
        if ((int)$stmt->fetchColumn() > 0) {
            jsonResponse([
                'success' => false,
                'error'   => 'Cannot delete customer with existing invoices',
            ], 409);
        }

        $pdo->prepare('UPDATE customers SET deleted_at = NOW() WHERE id = ?')->execute([$id]);

        auditLog($pdo, (int)$auth['user_id'], $auth['username'],
            $old['store_id'] ?? null,
            'delete', 'customer', $id, $old, null);

        jsonResponse(['success' => true, 'message' => 'Customer deleted']);
    }

    jsonResponse(['success' => false, 'error' => 'Method not allowed'], 405);

} catch (PDOException $e) {
    error_log('customers.php DB error: ' . $e->getMessage());
    jsonResponse(['success' => false, 'error' => 'Service unavailable'], 503);
} catch (Throwable $e) {
    error_log('customers.php error: ' . $e->getMessage());
    jsonResponse(['success' => false, 'error' => 'Internal server error'], 500);
}
