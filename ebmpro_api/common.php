<?php
require_once __DIR__ . '/config.php';

/**
 * Returns a PDO connection, creating it on first call (lazy singleton).
 */
function getDb(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
    }
    return $pdo;
}

/**
 * Send a JSON response and exit.
 */
function jsonResponse(array $data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Set CORS headers for same-origin / configured origin only.
 * Handles OPTIONS preflight.
 */
function setCorsHeaders(): void
{
    $allowedOrigin = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http')
        . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');

    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin === $allowedOrigin) {
        header('Access-Control-Allow-Origin: ' . $allowedOrigin);
        header('Vary: Origin');
    }
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Authorization, Content-Type, X-Requested-With');

    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}

/**
 * Base64URL encode (no padding).
 */
function base64UrlEncode(string $data): string
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

/**
 * Base64URL decode.
 */
function base64UrlDecode(string $data): string
{
    $remainder = strlen($data) % 4;
    if ($remainder) {
        $data .= str_repeat('=', 4 - $remainder);
    }
    return base64_decode(strtr($data, '-_', '+/'));
}

/**
 * Generate a JWT-like token for the given user payload.
 */
function generateToken(array $payload): string
{
    $header  = base64UrlEncode(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
    $body    = base64UrlEncode(json_encode($payload));
    $sig     = base64UrlEncode(hash_hmac('sha256', $header . '.' . $body, APP_SECRET, true));
    return $header . '.' . $body . '.' . $sig;
}

/**
 * Validate a JWT-like token.
 * Returns the decoded payload array or null on failure.
 */
function validateToken(string $token): ?array
{
    $parts = explode('.', $token);
    if (count($parts) !== 3) {
        return null;
    }
    [$header, $body, $sig] = $parts;
    $expected = base64UrlEncode(hash_hmac('sha256', $header . '.' . $body, APP_SECRET, true));
    if (!hash_equals($expected, $sig)) {
        return null;
    }
    $payload = json_decode(base64UrlDecode($body), true);
    if (!is_array($payload) || empty($payload['exp']) || $payload['exp'] < time()) {
        return null;
    }
    return $payload;
}

/**
 * Require a valid Bearer token.
 * Dies with 401 JSON on failure; returns decoded payload array on success.
 */
function requireAuth(): array
{
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (strncasecmp($authHeader, 'Bearer ', 7) !== 0) {
        jsonResponse(['success' => false, 'error' => 'Authorization header missing or malformed'], 401);
    }
    $token   = substr($authHeader, 7);
    $payload = validateToken($token);
    if ($payload === null) {
        jsonResponse(['success' => false, 'error' => 'Invalid or expired token'], 401);
    }
    return $payload;
}

/**
 * Write an entry to the audit_log table.
 *
 * @param PDO         $pdo
 * @param int|null    $userId
 * @param string      $userName
 * @param mixed       $storeContext  store code (VARCHAR) or store_id (int); stored in store_context
 * @param string      $action        e.g. 'create', 'update', 'delete'
 * @param string      $entityType    e.g. 'invoice', 'customer'
 * @param int|null    $entityId
 * @param mixed       $oldValues     array|null
 * @param mixed       $newValues     array|null
 */
function auditLog(
    PDO $pdo,
    ?int $userId,
    string $userName,
    $storeContext,
    string $action,
    string $entityType,
    ?int $entityId,
    $oldValues,
    $newValues
): void {
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO audit_log
             (user_id, user_name, store_context, action, entity_type, entity_id,
              old_values, new_values, ip_address, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())'
        );
        $stmt->execute([
            $userId,
            $userName,
            $storeContext,   // stored in store_context column
            $action,
            $entityType,
            $entityId,
            $oldValues !== null ? json_encode($oldValues) : null,
            $newValues !== null ? json_encode($newValues) : null,
            $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    } catch (PDOException $e) {
        // Audit failures must never crash the main request.
        error_log('auditLog error: ' . $e->getMessage());
    }
}
