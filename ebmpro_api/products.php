<?php
require_once __DIR__ . '/common.php';

setCorsHeaders();
header('Content-Type: application/json; charset=utf-8');

$auth   = requireAuth();
$method = $_SERVER['REQUEST_METHOD'];
$pdo    = getDb();

// ── Helper: require admin role ────────────────────────────────────────────────
function requireAdmin(array $auth): void
{
    if ($auth['role'] !== 'admin') {
        jsonResponse(['success' => false, 'error' => 'Admin access required'], 403);
    }
}

try {
    // ════════════════════════════════════════════════════════════════════════
    // GET
    // ════════════════════════════════════════════════════════════════════════
    if ($method === 'GET') {
        // Single product
        if (!empty($_GET['id'])) {
            $stmt = $pdo->prepare('SELECT * FROM products WHERE id = ? AND is_active = 1');
            $stmt->execute([(int)$_GET['id']]);
            $product = $stmt->fetch();
            if (!$product) {
                jsonResponse(['success' => false, 'error' => 'Product not found'], 404);
            }
            jsonResponse(['success' => true, 'data' => $product]);
        }

        // Full-text / LIKE search
        if (!empty($_GET['q'])) {
            $q   = trim($_GET['q']);
            $res = [];

            // Try MATCH AGAINST first (requires FULLTEXT index on products)
            try {
                $stmt = $pdo->prepare(
                    'SELECT *, MATCH(name, description, sku) AGAINST(? IN BOOLEAN MODE) AS score
                     FROM products
                     WHERE is_active = 1
                       AND MATCH(name, description, sku) AGAINST(? IN BOOLEAN MODE)
                     ORDER BY score DESC
                     LIMIT 20'
                );
                $stmt->execute([$q, $q]);
                $res = $stmt->fetchAll();
            } catch (PDOException $e) {
                $res = [];
            }

            // Fallback to LIKE if full-text returned nothing
            if (empty($res)) {
                $like = '%' . $q . '%';
                $stmt = $pdo->prepare(
                    'SELECT * FROM products
                     WHERE is_active = 1
                       AND (name LIKE ? OR sku LIKE ? OR description LIKE ?)
                     ORDER BY name ASC
                     LIMIT 20'
                );
                $stmt->execute([$like, $like, $like]);
                $res = $stmt->fetchAll();
            }

            jsonResponse(['success' => true, 'data' => $res]);
        }

        // Paginated list
        $page    = max(1, (int)($_GET['page']     ?? 1));
        $perPage = min(100, max(1, (int)($_GET['per_page'] ?? 50)));
        $offset  = ($page - 1) * $perPage;

        $stmt = $pdo->prepare(
            'SELECT * FROM products WHERE is_active = 1
             ORDER BY name ASC LIMIT ? OFFSET ?'
        );
        $stmt->execute([$perPage, $offset]);
        $products = $stmt->fetchAll();

        $total = (int)$pdo->query('SELECT COUNT(*) FROM products WHERE is_active = 1')->fetchColumn();

        jsonResponse(['success' => true, 'data' => $products, 'meta' => [
            'total'    => $total,
            'page'     => $page,
            'per_page' => $perPage,
            'pages'    => (int)ceil($total / $perPage),
        ]]);
    }

    // ════════════════════════════════════════════════════════════════════════
    // POST — create (admin only)
    // ════════════════════════════════════════════════════════════════════════
    if ($method === 'POST') {
        requireAdmin($auth);
        $body = json_decode(file_get_contents('php://input'), true) ?? [];

        if (empty($body['name'])) {
            jsonResponse(['success' => false, 'error' => 'name is required'], 422);
        }

        $stmt = $pdo->prepare(
            'INSERT INTO products
             (sku, name, description, unit_price, vat_rate, unit, stock_qty, is_active, created_at, updated_at)
             VALUES (?,?,?,?,?,?,?,1,NOW(),NOW())'
        );
        $stmt->execute([
            $body['sku']         ?? null,
            trim($body['name']),
            $body['description'] ?? null,
            round((float)($body['unit_price'] ?? 0), 4),
            round((float)($body['vat_rate']   ?? 0), 4),
            $body['unit']        ?? 'each',
            (int)($body['stock_qty'] ?? 0),
        ]);
        $newId = (int)$pdo->lastInsertId();

        $stmt = $pdo->prepare('SELECT * FROM products WHERE id = ?');
        $stmt->execute([$newId]);
        $product = $stmt->fetch();

        auditLog($pdo, (int)$auth['user_id'], $auth['username'], null,
            'create', 'product', $newId, null, $product);

        jsonResponse(['success' => true, 'data' => $product], 201);
    }

    // ════════════════════════════════════════════════════════════════════════
    // PUT — update (admin only)
    // ════════════════════════════════════════════════════════════════════════
    if ($method === 'PUT') {
        requireAdmin($auth);
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) {
            jsonResponse(['success' => false, 'error' => 'id parameter required'], 422);
        }

        $stmt = $pdo->prepare('SELECT * FROM products WHERE id = ?');
        $stmt->execute([$id]);
        $old = $stmt->fetch();
        if (!$old) {
            jsonResponse(['success' => false, 'error' => 'Product not found'], 404);
        }

        $body = json_decode(file_get_contents('php://input'), true) ?? [];

        $pdo->prepare(
            'UPDATE products
             SET sku         = COALESCE(?, sku),
                 name        = COALESCE(?, name),
                 description = COALESCE(?, description),
                 unit_price  = COALESCE(?, unit_price),
                 vat_rate    = COALESCE(?, vat_rate),
                 unit        = COALESCE(?, unit),
                 stock_qty   = COALESCE(?, stock_qty),
                 updated_at  = NOW()
             WHERE id = ?'
        )->execute([
            $body['sku']         ?? null,
            $body['name']        ?? null,
            $body['description'] ?? null,
            isset($body['unit_price']) ? round((float)$body['unit_price'], 4) : null,
            isset($body['vat_rate'])   ? round((float)$body['vat_rate'],   4) : null,
            $body['unit']        ?? null,
            isset($body['stock_qty'])  ? (int)$body['stock_qty']             : null,
            $id,
        ]);

        $stmt = $pdo->prepare('SELECT * FROM products WHERE id = ?');
        $stmt->execute([$id]);
        $new = $stmt->fetch();

        auditLog($pdo, (int)$auth['user_id'], $auth['username'], null,
            'update', 'product', $id, $old, $new);

        jsonResponse(['success' => true, 'data' => $new]);
    }

    // ════════════════════════════════════════════════════════════════════════
    // DELETE — soft-delete (admin only)
    // ════════════════════════════════════════════════════════════════════════
    if ($method === 'DELETE') {
        requireAdmin($auth);
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) {
            jsonResponse(['success' => false, 'error' => 'id parameter required'], 422);
        }

        $stmt = $pdo->prepare('SELECT * FROM products WHERE id = ?');
        $stmt->execute([$id]);
        $old = $stmt->fetch();
        if (!$old) {
            jsonResponse(['success' => false, 'error' => 'Product not found'], 404);
        }

        $pdo->prepare('UPDATE products SET is_active = 0, updated_at = NOW() WHERE id = ?')
            ->execute([$id]);

        auditLog($pdo, (int)$auth['user_id'], $auth['username'], null,
            'delete', 'product', $id, $old, null);

        jsonResponse(['success' => true, 'message' => 'Product deactivated']);
    }

    jsonResponse(['success' => false, 'error' => 'Method not allowed'], 405);

} catch (PDOException $e) {
    error_log('products.php DB error: ' . $e->getMessage());
    jsonResponse(['success' => false, 'error' => 'Service unavailable'], 503);
} catch (Throwable $e) {
    error_log('products.php error: ' . $e->getMessage());
    jsonResponse(['success' => false, 'error' => 'Internal server error'], 500);
}
