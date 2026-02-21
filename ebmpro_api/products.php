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
            $stmt = $pdo->prepare('SELECT * FROM products WHERE id = ? AND active = 1');
            $stmt->execute([(int)$_GET['id']]);
            $product = $stmt->fetch();
            if (!$product) {
                jsonResponse(['success' => false, 'error' => 'Product not found'], 404);
            }
            jsonResponse(['success' => true, 'data' => $product]);
        }

        // Full-text / LIKE search on description and code
        if (!empty($_GET['q'])) {
            $q   = trim($_GET['q']);
            $res = [];

            // Try MATCH AGAINST first (FULLTEXT index is on description, product_code)
            try {
                $stmt = $pdo->prepare(
                    'SELECT *, MATCH(description, product_code) AGAINST(? IN BOOLEAN MODE) AS score
                     FROM products
                     WHERE active = 1
                       AND MATCH(description, product_code) AGAINST(? IN BOOLEAN MODE)
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
                     WHERE active = 1
                       AND (description LIKE ? OR product_code LIKE ?)
                     ORDER BY description ASC
                     LIMIT 20'
                );
                $stmt->execute([$like, $like]);
                $res = $stmt->fetchAll();
            }

            jsonResponse(['success' => true, 'data' => $res]);
        }

        // Paginated list
        $page    = max(1, (int)($_GET['page']     ?? 1));
        $perPage = min(100, max(1, (int)($_GET['per_page'] ?? 50)));
        $offset  = ($page - 1) * $perPage;

        $stmt = $pdo->prepare(
            'SELECT * FROM products WHERE active = 1
             ORDER BY description ASC LIMIT ? OFFSET ?'
        );
        $stmt->execute([$perPage, $offset]);
        $products = $stmt->fetchAll();

        $total = (int)$pdo->query('SELECT COUNT(*) FROM products WHERE active = 1')->fetchColumn();

        jsonResponse(['success' => true, 'data' => $products, 'meta' => [
            'total'    => $total,
            'page'     => $page,
            'per_page' => $perPage,
            'pages'    => (int)ceil($total / $perPage),
        ]]);
    }

    // ════════════════════════════════════════════════════════════════════════
    // POST — create (admin only)
    // Schema columns: id, product_code, description, category, price, vat_rate, unit, active, store_id
    // ════════════════════════════════════════════════════════════════════════
    if ($method === 'POST') {
        requireAdmin($auth);
        $body = json_decode(file_get_contents('php://input'), true) ?? [];

        if (empty($body['description'])) {
            jsonResponse(['success' => false, 'error' => 'description is required'], 422);
        }

        $stmt = $pdo->prepare(
            'INSERT INTO products (product_code, description, category, price, vat_rate, unit, store_id, active)
             VALUES (?,?,?,?,?,?,?,1)'
        );
        $stmt->execute([
            $body['product_code'] ?? $body['code'] ?? null,
            trim($body['description']),
            $body['category']  ?? null,
            round((float)($body['price'] ?? $body['unit_price'] ?? 0), 2),
            round((float)($body['vat_rate'] ?? 23), 2),
            $body['unit']      ?? 'each',
            !empty($body['store_id']) ? (int)$body['store_id'] : null,
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
             SET product_code = COALESCE(?, product_code),
                 description  = COALESCE(?, description),
                 category     = COALESCE(?, category),
                 price        = COALESCE(?, price),
                 vat_rate     = COALESCE(?, vat_rate),
                 unit         = COALESCE(?, unit)
             WHERE id = ?'
        )->execute([
            $body['product_code'] ?? $body['code'] ?? null,
            $body['description']  ?? null,
            $body['category']     ?? null,
            isset($body['price'])      ? round((float)$body['price'],      2) :
              (isset($body['unit_price']) ? round((float)$body['unit_price'], 2) : null),
            isset($body['vat_rate'])   ? round((float)$body['vat_rate'],   2) : null,
            $body['unit']         ?? null,
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
    // DELETE — soft-delete via active=0 (admin only)
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

        $pdo->prepare('UPDATE products SET active = 0 WHERE id = ?')->execute([$id]);

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