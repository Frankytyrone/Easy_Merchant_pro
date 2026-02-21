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
        $where  = ['1=1'];
        $params = [];

        if (!empty($_GET['q'])) {
            $like     = '%' . $_GET['q'] . '%';
            $where[]  = '(company_name LIKE ? OR account_no LIKE ? OR email_address LIKE ? OR inv_telephone LIKE ?)';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        $sql  = 'SELECT * FROM customers WHERE ' . implode(' AND ', $where)
              . ' ORDER BY company_name ASC LIMIT 500';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        jsonResponse(['success' => true, 'data' => $stmt->fetchAll()]);
    }

    // ════════════════════════════════════════════════════════════════════════
    // POST — create
    // ════════════════════════════════════════════════════════════════════════
    if ($method === 'POST') {
        $body = json_decode(file_get_contents('php://input'), true) ?? [];

        if (empty($body['company_name'] ?? $body['name'])) {
            jsonResponse(['success' => false, 'error' => 'company_name is required'], 422);
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

        $companyName = trim($body['company_name'] ?? $body['name']);
        $stmt = $pdo->prepare(
            'INSERT INTO customers
             (account_no, customer_code, company_name, contact_name, email_address, inv_telephone,
              address_1, address_2, address_3, inv_town, inv_region, inv_postcode,
              vat_registered, payment_terms, notes, created_at, updated_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())'
        );
        $stmt->execute([
            $accountNo,
            $body['customer_code'] ?? $accountNo,
            $companyName,
            $body['contact_name']   ?? null,
            $body['email_address']  ?? $body['email']     ?? null,
            $body['inv_telephone']  ?? $body['telephone'] ?? null,
            $body['address_1']      ?? null,
            $body['address_2']      ?? null,
            $body['address_3']      ?? null,
            $body['inv_town']       ?? $body['town']      ?? null,
            $body['inv_region']     ?? $body['region']    ?? null,
            $body['inv_postcode']   ?? $body['eircode']   ?? null,
            isset($body['vat_registered']) ? (int)(bool)$body['vat_registered'] : 0,
            $body['payment_terms']  ?? null,
            $body['notes']          ?? null,
        ]);
        $newId = (int)$pdo->lastInsertId();

        $stmt = $pdo->prepare('SELECT * FROM customers WHERE id = ?');
        $stmt->execute([$newId]);
        $customer = $stmt->fetch();

        auditLog($pdo, (int)$auth['user_id'], $auth['username'], null,
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

        $stmt = $pdo->prepare('SELECT * FROM customers WHERE id = ?');
        $stmt->execute([$id]);
        $old = $stmt->fetch();
        if (!$old) {
            jsonResponse(['success' => false, 'error' => 'Customer not found'], 404);
        }

        $body = json_decode(file_get_contents('php://input'), true) ?? [];

        $pdo->prepare(
            'UPDATE customers
             SET company_name    = COALESCE(?, company_name),
                 contact_name    = COALESCE(?, contact_name),
                 email_address   = COALESCE(?, email_address),
                 inv_telephone   = COALESCE(?, inv_telephone),
                 address_1       = COALESCE(?, address_1),
                 address_2       = COALESCE(?, address_2),
                 address_3       = COALESCE(?, address_3),
                 inv_town        = COALESCE(?, inv_town),
                 inv_region      = COALESCE(?, inv_region),
                 inv_postcode    = COALESCE(?, inv_postcode),
                 vat_registered  = COALESCE(?, vat_registered),
                 payment_terms   = COALESCE(?, payment_terms),
                 notes           = COALESCE(?, notes),
                 updated_at      = NOW()
             WHERE id = ?'
        )->execute([
            $body['company_name']   ?? $body['name']      ?? null,
            $body['contact_name']   ?? null,
            $body['email_address']  ?? $body['email']     ?? null,
            $body['inv_telephone']  ?? $body['telephone'] ?? null,
            $body['address_1']      ?? null,
            $body['address_2']      ?? null,
            $body['address_3']      ?? null,
            $body['inv_town']       ?? $body['town']      ?? null,
            $body['inv_region']     ?? $body['region']    ?? null,
            $body['inv_postcode']   ?? $body['eircode']   ?? null,
            isset($body['vat_registered']) ? (int)(bool)$body['vat_registered'] : null,
            $body['payment_terms']  ?? null,
            $body['notes']          ?? null,
            $id,
        ]);

        $stmt = $pdo->prepare('SELECT * FROM customers WHERE id = ?');
        $stmt->execute([$id]);
        $new = $stmt->fetch();

        auditLog($pdo, (int)$auth['user_id'], $auth['username'], null,
            'update', 'customer', $id, $old, $new);

        jsonResponse(['success' => true, 'data' => $new]);
    }

    // ════════════════════════════════════════════════════════════════════════
    // DELETE — prevent if has non-cancelled invoices, otherwise hard delete
    // (schema has no deleted_at column on customers)
    // ════════════════════════════════════════════════════════════════════════
    if ($method === 'DELETE') {
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) {
            jsonResponse(['success' => false, 'error' => 'id parameter required'], 422);
        }

        $stmt = $pdo->prepare('SELECT * FROM customers WHERE id = ?');
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

        $pdo->prepare('DELETE FROM customers WHERE id = ?')->execute([$id]);

        auditLog($pdo, (int)$auth['user_id'], $auth['username'], null,
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