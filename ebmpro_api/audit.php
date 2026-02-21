<?php
require_once __DIR__ . '/common.php';

setCorsHeaders();
header('Content-Type: application/json; charset=utf-8');

$auth = requireAuth();
if (!in_array($auth['role'], ['admin', 'manager'], true)) {
    jsonResponse(['success' => false, 'error' => 'Admin or manager access required'], 403);
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(['success' => false, 'error' => 'Method not allowed'], 405);
}

try {
    $pdo = getDb();

    $page    = max(1, (int)($_GET['page']     ?? 1));
    $perPage = min(200, max(1, (int)($_GET['per_page'] ?? 50)));
    $offset  = ($page - 1) * $perPage;

    $where  = ['1=1'];
    $params = [];

    if (!empty($_GET['table_name']) || !empty($_GET['entity_type'])) {
        $where[]  = 'table_name = ?';
        $params[] = $_GET['table_name'] ?? $_GET['entity_type'];
    }
    if (!empty($_GET['record_id']) || !empty($_GET['entity_id'])) {
        $where[]  = 'record_id = ?';
        $params[] = (int)($_GET['record_id'] ?? $_GET['entity_id']);
    }
    if (!empty($_GET['user_id'])) {
        $where[]  = 'user_id = ?';
        $params[] = (int)$_GET['user_id'];
    }
    if (!empty($_GET['action'])) {
        $where[]  = 'action = ?';
        $params[] = $_GET['action'];
    }
    if (!empty($_GET['date_from'])) {
        $where[]  = 'created_at >= ?';
        $params[] = $_GET['date_from'] . ' 00:00:00';
    }
    if (!empty($_GET['date_to'])) {
        $where[]  = 'created_at <= ?';
        $params[] = $_GET['date_to'] . ' 23:59:59';
    }

    $whereClause = implode(' AND ', $where);

    // Count total matching rows
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM audit_log WHERE {$whereClause}");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    // Fetch page
    $dataStmt = $pdo->prepare(
        "SELECT * FROM audit_log WHERE {$whereClause}
         ORDER BY created_at DESC
         LIMIT ? OFFSET ?"
    );
    $dataStmt->execute(array_merge($params, [$perPage, $offset]));
    $rows = $dataStmt->fetchAll();

    jsonResponse([
        'success' => true,
        'data'    => $rows,
        'meta'    => [
            'total'    => $total,
            'page'     => $page,
            'per_page' => $perPage,
            'pages'    => (int)ceil($total / $perPage),
        ],
    ]);

} catch (PDOException $e) {
    error_log('audit.php DB error: ' . $e->getMessage());
    jsonResponse(['success' => false, 'error' => 'Service unavailable'], 503);
} catch (Throwable $e) {
    error_log('audit.php error: ' . $e->getMessage());
    jsonResponse(['success' => false, 'error' => 'Internal server error'], 500);
}