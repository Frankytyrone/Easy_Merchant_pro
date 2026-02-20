<?php
require_once __DIR__ . '/common.php';

setCorsHeaders();
header('Content-Type: application/json; charset=utf-8');

$method = $_SERVER['REQUEST_METHOD'];

// ── DELETE: client-side logout (token is stateless; client discards it) ─────
if ($method === 'DELETE') {
    jsonResponse(['success' => true, 'message' => 'Logged out']);
}

// ── GET: validate an existing token ─────────────────────────────────────────
if ($method === 'GET') {
    $payload = requireAuth();
    jsonResponse(['success' => true, 'data' => [
        'user_id'   => $payload['user_id'],
        'username'  => $payload['username'],
        'role'      => $payload['role'],
        'store_id'  => $payload['store_id'],
        'exp'       => $payload['exp'],
    ]]);
}

// ── POST: login ──────────────────────────────────────────────────────────────
if ($method !== 'POST') {
    jsonResponse(['success' => false, 'error' => 'Method not allowed'], 405);
}

$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body) || empty($body['username']) || empty($body['password'])) {
    jsonResponse(['success' => false, 'error' => 'username and password are required'], 422);
}

$username  = trim($body['username']);
$password  = $body['password'];
$ipAddress = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

try {
    $pdo = getDb();

    // ── Rate limiting: max 5 attempts per IP per 15-minute window ─────────────
    $window = date('Y-m-d H:i:s', strtotime('-15 minutes'));
    $stmt   = $pdo->prepare(
        'SELECT COUNT(*) FROM login_attempts
         WHERE ip_address = ? AND attempted_at > ?'
    );
    $stmt->execute([$ipAddress, $window]);
    $attempts = (int) $stmt->fetchColumn();

    if ($attempts >= 5) {
        jsonResponse([
            'success' => false,
            'error'   => 'Too many failed login attempts. Please try again later.',
        ], 429);
    }

    // ── Fetch user ────────────────────────────────────────────────────────────
    $stmt = $pdo->prepare(
        'SELECT id, username, password_hash, full_name, role, store_id, active
         FROM users WHERE username = ? LIMIT 1'
    );
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    $success = $user && (int) $user['active'] === 1
        && password_verify($password, $user['password_hash']);

    // ── Log attempt (schema only stores ip_address + timestamp) ───────────────
    if (!$success) {
        $logStmt = $pdo->prepare(
            'INSERT INTO login_attempts (ip_address, attempted_at) VALUES (?, NOW())'
        );
        $logStmt->execute([$ipAddress]);
    }

    if (!$success) {
        jsonResponse(['success' => false, 'error' => 'Invalid credentials'], 401);
    }

    // ── Build token ───────────────────────────────────────────────────────────
    $payload = [
        'user_id'  => (int) $user['id'],
        'username' => $user['username'],
        'role'     => $user['role'],
        'store_id' => $user['store_id'] !== null ? (int) $user['store_id'] : null,
        'exp'      => time() + 86400,
    ];
    $token = generateToken($payload);

    jsonResponse([
        'success' => true,
        'token'   => $token,
        'user'    => [
            'id'        => (int) $user['id'],
            'username'  => $user['username'],
            'full_name' => $user['full_name'],
            'role'      => $user['role'],
            'store_id'  => $user['store_id'] !== null ? (int) $user['store_id'] : null,
        ],
    ]);
} catch (PDOException $e) {
    error_log('auth.php DB error: ' . $e->getMessage());
    jsonResponse(['success' => false, 'error' => 'Service unavailable'], 503);
}
