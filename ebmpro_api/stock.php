<?php
/**
 * stock.php — Stock level management API
 *
 * GET  /ebmpro_api/stock.php          — all stock levels joined with products
 * GET  /ebmpro_api/stock.php?low=1   — items where current_qty <= min_qty
 * POST /ebmpro_api/stock.php         — adjust stock { product_id, quantity_change, reason }
 *
 * Requires JWT auth (admin or manager role).
 */
require_once __DIR__ . '/common.php';

setCorsHeaders();
header('Content-Type: application/json; charset=utf-8');

$auth = requireAuth();
if (!in_array($auth['role'], ['admin', 'manager'], true)) {
    jsonResponse(['success' => false, 'error' => 'Admin or manager role required'], 403);
}

$pdo    = getDb();
$method = $_SERVER['REQUEST_METHOD'];

try {
    // ── GET — list stock levels ───────────────────────────────────────────────
    if ($method === 'GET') {
        $lowOnly = !empty($_GET['low']);

        $sql = 'SELECT p.id AS product_id,
                       p.product_code,
                       p.description,
                       COALESCE(p.stock_qty, 0) AS current_qty,
                       0 AS min_qty,
                       (SELECT MAX(s.created_at)
                        FROM tbl_stock s
                        WHERE s.product_id = p.id) AS last_updated
                FROM products p
                WHERE p.active = 1';

        if ($lowOnly) {
            $sql .= ' AND COALESCE(p.stock_qty, 0) <= 0';
        }

        $sql .= ' ORDER BY p.product_code ASC';

        $stmt = $pdo->query($sql);
        $rows = $stmt->fetchAll();

        jsonResponse(['success' => true, 'data' => $rows]);
    }

    // ── POST — adjust stock ───────────────────────────────────────────────────
    if ($method === 'POST') {
        $body      = json_decode(file_get_contents('php://input'), true) ?? [];
        $productId = (int)($body['product_id'] ?? 0);
        $change    = (float)($body['quantity_change'] ?? 0);
        $reason    = trim($body['reason'] ?? '');

        if (!$productId) {
            jsonResponse(['success' => false, 'error' => 'product_id is required'], 422);
        }
        if ($change == 0) {
            jsonResponse(['success' => false, 'error' => 'quantity_change must be non-zero'], 422);
        }
        if ($reason === '') {
            jsonResponse(['success' => false, 'error' => 'reason is required'], 422);
        }

        // Verify product exists
        $prodStmt = $pdo->prepare('SELECT id, product_code, description, stock_qty FROM products WHERE id = ?');
        $prodStmt->execute([$productId]);
        $product = $prodStmt->fetch();

        if (!$product) {
            jsonResponse(['success' => false, 'error' => 'Product not found'], 404);
        }

        $oldQty = (float)$product['stock_qty'];
        $newQty = round($oldQty + $change, 3);

        // Update product stock_qty
        $pdo->prepare('UPDATE products SET stock_qty = ? WHERE id = ?')
            ->execute([$newQty, $productId]);

        // Log movement to tbl_stock
        $movementType = $change > 0 ? 'in' : 'out';
        $pdo->prepare(
            'INSERT INTO tbl_stock (product_id, movement_type, quantity, reference, notes, created_by, created_at)
             VALUES (?, ?, ?, ?, ?, ?, NOW())'
        )->execute([
            $productId,
            $movementType,
            abs($change),
            $reason,
            null,
            (int)$auth['user_id'],
        ]);

        // Audit log
        auditLog($pdo, (int)$auth['user_id'], $auth['username'], null,
            'stock_adjust', 'products', $productId,
            ['stock_qty' => $oldQty],
            ['stock_qty' => $newQty, 'change' => $change, 'reason' => $reason]);

        jsonResponse([
            'success'     => true,
            'product_id'  => $productId,
            'old_qty'     => $oldQty,
            'new_qty'     => $newQty,
            'change'      => $change,
        ]);
    }

    jsonResponse(['success' => false, 'error' => 'Method not allowed'], 405);

} catch (PDOException $e) {
    error_log('stock.php DB error: ' . $e->getMessage());
    jsonResponse(['success' => false, 'error' => 'Service unavailable'], 503);
} catch (Throwable $e) {
    error_log('stock.php error: ' . $e->getMessage());
    jsonResponse(['success' => false, 'error' => 'Internal server error'], 500);
}
