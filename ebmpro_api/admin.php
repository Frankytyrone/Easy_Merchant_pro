<?php
/**
 * admin.php — Admin-only dashboard API
 *
 * GET ?action=dashboard   — today's sales, who is online, system health
 * GET ?action=audit        — last 20 audit log entries
 * GET ?action=operators    — list all users
 * POST ?action=operator    — add/edit operator  { id?, username, role, password? }
 * POST ?action=lock        — lock/unlock operator { id, active: 0|1 }
 * POST ?action=reset_password — { id, password }
 * GET ?action=backups      — list saved backups in backups/ folder
 * POST ?action=run_backup  — trigger an immediate backup save to backups/
 */
require_once __DIR__ . '/common.php';

setCorsHeaders();
header('Content-Type: application/json; charset=utf-8');

$auth = requireAuth();
if ($auth['role'] !== 'admin') {
    jsonResponse(['success' => false, 'error' => 'Admin access required'], 403);
}

$pdo    = getDb();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    // ── Dashboard ──────────────────────────────────────────────────────────────
    if ($action === 'dashboard') {
        // Today's sales per store
        $salesStmt = $pdo->query(
            "SELECT store_code, COUNT(*) AS invoice_count, COALESCE(SUM(total),0) AS total_sales
             FROM invoices
             WHERE invoice_date = CURDATE() AND status NOT IN ('cancelled','draft')
             GROUP BY store_code"
        );
        $todaySales = $salesStmt->fetchAll();

        // Who is online (sessions table may not exist — fallback to audit log)
        $onlineStmt = $pdo->query(
            "SELECT DISTINCT username, store_code, MAX(created_at) AS last_seen
             FROM audit_log
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL 5 MINUTE)
             GROUP BY username, store_code
             ORDER BY last_seen DESC"
        );
        $online = $onlineStmt->fetchAll();

        // System health
        $dbSizeStmt = $pdo->query(
            "SELECT ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) AS size_mb
             FROM information_schema.tables
             WHERE table_schema = DATABASE()"
        );
        $dbSize = (float)($dbSizeStmt->fetchColumn() ?? 0);

        $queueStmt = $pdo->query(
            "SELECT status, COUNT(*) AS cnt FROM sync_queue GROUP BY status"
        );
        $queueStatus = $queueStmt->fetchAll();

        $pendingCount = 0;
        foreach ($queueStatus as $qs) {
            if ($qs['status'] === 'pending') {
                $pendingCount = (int)$qs['cnt'];
            }
        }

        // Last backup (look in backups/ folder)
        $backupsDir  = __DIR__ . '/../backups';
        $lastBackup  = null;
        if (is_dir($backupsDir)) {
            $files = glob($backupsDir . '/*.zip');
            if ($files) {
                usort($files, fn($a, $b) => filemtime($b) - filemtime($a));
                $lastBackup = date('Y-m-d H:i:s', filemtime($files[0]));
            }
        }

        jsonResponse([
            'success'      => true,
            'today_sales'  => $todaySales,
            'online'       => $online,
            'health'       => [
                'db_size_mb'     => $dbSize,
                'last_backup'    => $lastBackup,
                'sync_pending'   => $pendingCount,
            ],
        ]);
    }

    // ── Audit Log ─────────────────────────────────────────────────────────────
    if ($action === 'audit') {
        $limit  = min(100, max(1, (int)($_GET['limit'] ?? 20)));
        $offset = max(0, (int)($_GET['offset'] ?? 0));
        $stmt   = $pdo->prepare(
            'SELECT * FROM audit_log ORDER BY created_at DESC LIMIT ? OFFSET ?'
        );
        $stmt->execute([$limit, $offset]);
        jsonResponse(['success' => true, 'data' => $stmt->fetchAll()]);
    }

    // ── Operators ─────────────────────────────────────────────────────────────
    if ($action === 'operators' && $method === 'GET') {
        $stmt = $pdo->query(
            'SELECT id, username, role, store_id, active, created_at FROM users ORDER BY username ASC'
        );
        jsonResponse(['success' => true, 'data' => $stmt->fetchAll()]);
    }

    if ($action === 'operator' && $method === 'POST') {
        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        $id   = !empty($body['id']) ? (int)$body['id'] : null;

        $username = trim($body['username'] ?? '');
        $role     = in_array($body['role'] ?? '', ['admin', 'manager', 'counter'], true)
            ? $body['role'] : 'counter';
        $storeId  = !empty($body['store_id']) ? (int)$body['store_id'] : null;

        if (empty($username)) {
            jsonResponse(['success' => false, 'error' => 'username is required'], 422);
        }

        if ($id) {
            // Update existing
            $pdo->prepare(
                'UPDATE users SET username = ?, role = ?, store_id = ? WHERE id = ?'
            )->execute([$username, $role, $storeId, $id]);

            if (!empty($body['password'])) {
                $hash = password_hash($body['password'], PASSWORD_BCRYPT);
                $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([$hash, $id]);
            }
            auditLog($pdo, (int)$auth['user_id'], $auth['username'], null,
                'update', 'user', $id, null, ['username' => $username, 'role' => $role]);
            jsonResponse(['success' => true, 'message' => 'Operator updated']);
        } else {
            // Create new
            if (empty($body['password'])) {
                jsonResponse(['success' => false, 'error' => 'password is required for new operator'], 422);
            }
            $hash = password_hash($body['password'], PASSWORD_BCRYPT);
            $pdo->prepare(
                'INSERT INTO users (username, password_hash, role, store_id, active) VALUES (?,?,?,?,1)'
            )->execute([$username, $hash, $role, $storeId]);
            $newId = (int)$pdo->lastInsertId();
            auditLog($pdo, (int)$auth['user_id'], $auth['username'], null,
                'create', 'user', $newId, null, ['username' => $username, 'role' => $role]);
            jsonResponse(['success' => true, 'id' => $newId, 'message' => 'Operator created']);
        }
    }

    if ($action === 'lock' && $method === 'POST') {
        $body   = json_decode(file_get_contents('php://input'), true) ?? [];
        $id     = (int)($body['id'] ?? 0);
        $active = isset($body['active']) ? (int)(bool)$body['active'] : 0;
        if (!$id) {
            jsonResponse(['success' => false, 'error' => 'id required'], 422);
        }
        $pdo->prepare('UPDATE users SET active = ? WHERE id = ?')->execute([$active, $id]);
        auditLog($pdo, (int)$auth['user_id'], $auth['username'], null,
            $active ? 'unlock' : 'lock', 'user', $id, null, ['active' => $active]);
        jsonResponse(['success' => true, 'message' => $active ? 'Operator unlocked' : 'Operator locked']);
    }

    if ($action === 'reset_password' && $method === 'POST') {
        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        $id   = (int)($body['id'] ?? 0);
        $pw   = $body['password'] ?? '';
        if (!$id || empty($pw)) {
            jsonResponse(['success' => false, 'error' => 'id and password required'], 422);
        }
        $hash = password_hash($pw, PASSWORD_BCRYPT);
        $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([$hash, $id]);
        auditLog($pdo, (int)$auth['user_id'], $auth['username'], null,
            'reset_password', 'user', $id, null, null);
        jsonResponse(['success' => true, 'message' => 'Password reset']);
    }

    // ── Backup management ────────────────────────────────────────────────────
    if ($action === 'backups' && $method === 'GET') {
        $backupsDir = __DIR__ . '/../backups';
        $list = [];
        if (is_dir($backupsDir)) {
            foreach (glob($backupsDir . '/*.zip') as $f) {
                $list[] = [
                    'filename' => basename($f),
                    'size_mb'  => round(filesize($f) / 1048576, 2),
                    'created'  => date('Y-m-d H:i:s', filemtime($f)),
                ];
            }
            usort($list, fn($a, $b) => strcmp($b['created'], $a['created']));
        }
        jsonResponse(['success' => true, 'data' => $list]);
    }

    if ($action === 'run_backup' && $method === 'POST') {
        $backupsDir = __DIR__ . '/../backups';
        if (!is_dir($backupsDir)) {
            mkdir($backupsDir, 0750, true);
        }

        $timestamp  = date('Ymd_His');
        $sqlFile    = "backup_{$timestamp}.sql";
        $jsonFile   = "backup_{$timestamp}.json";
        $zipPath    = $backupsDir . "/backup_{$timestamp}.zip";

        $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);

        $sqlContent  = "-- Easy Merchant Pro backup generated " . date('Y-m-d H:i:s') . "\nSET FOREIGN_KEY_CHECKS=0;\n\n";
        $jsonData    = [];

        foreach ($tables as $table) {
            $createRow   = $pdo->query("SHOW CREATE TABLE `{$table}`")->fetch(PDO::FETCH_NUM);
            $sqlContent .= "DROP TABLE IF EXISTS `{$table}`;\n" . $createRow[1] . ";\n\n";
            $rows        = $pdo->query("SELECT * FROM `{$table}`")->fetchAll(PDO::FETCH_ASSOC);
            $jsonData[$table] = $rows;
            if (!empty($rows)) {
                $columns = '`' . implode('`, `', array_keys($rows[0])) . '`';
                foreach (array_chunk($rows, 500) as $chunk) {
                    $vals = [];
                    foreach ($chunk as $row) {
                        $escaped = array_map(fn($v) => $v === null ? 'NULL' : $pdo->quote((string)$v), $row);
                        $vals[]  = '(' . implode(', ', $escaped) . ')';
                    }
                    $sqlContent .= "INSERT INTO `{$table}` ({$columns}) VALUES\n" . implode(",\n", $vals) . ";\n";
                }
                $sqlContent .= "\n";
            }
        }
        $sqlContent .= "SET FOREIGN_KEY_CHECKS=1;\n";

        $tmpFile = tempnam(sys_get_temp_dir(), 'ebm_bkp_');
        $zip     = new ZipArchive();
        $zip->open($tmpFile, ZipArchive::OVERWRITE);
        $zip->addFromString($sqlFile,  $sqlContent);
        $zip->addFromString($jsonFile, json_encode($jsonData, JSON_PRETTY_PRINT));
        $zip->close();
        rename($tmpFile, $zipPath);

        // Prune backups older than 30 days
        $allBackups = glob($backupsDir . '/*.zip');
        if (count($allBackups) > 30) {
            usort($allBackups, fn($a, $b) => filemtime($a) - filemtime($b));
            $toDelete = array_slice($allBackups, 0, count($allBackups) - 30);
            foreach ($toDelete as $old) {
                @unlink($old);
            }
        }

        auditLog($pdo, (int)$auth['user_id'], $auth['username'], null,
            'backup', 'system', null, null, ['file' => basename($zipPath)]);

        jsonResponse(['success' => true, 'filename' => basename($zipPath), 'message' => 'Backup created']);
    }

    // ── Download a specific backup ────────────────────────────────────────────
    if ($action === 'download_backup' && $method === 'GET') {
        $filename   = basename($_GET['filename'] ?? '');
        $backupsDir = __DIR__ . '/../backups';
        $fullPath   = $backupsDir . '/' . $filename;

        if (empty($filename) || !file_exists($fullPath) || pathinfo($filename, PATHINFO_EXTENSION) !== 'zip') {
            jsonResponse(['success' => false, 'error' => 'File not found'], 404);
        }

        // Ensure no path traversal
        $realBackups = realpath($backupsDir);
        $realFile    = realpath($fullPath);
        if (!$realFile || strncmp($realFile, $realBackups . DIRECTORY_SEPARATOR, strlen($realBackups) + 1) !== 0) {
            jsonResponse(['success' => false, 'error' => 'Access denied'], 403);
        }

        ob_end_clean();
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($fullPath));
        header('Cache-Control: no-cache, no-store, must-revalidate');
        readfile($fullPath);
        exit;
    }

    jsonResponse(['success' => false, 'error' => 'Unknown action'], 400);

} catch (PDOException $e) {
    error_log('admin.php DB error: ' . $e->getMessage());
    jsonResponse(['success' => false, 'error' => 'Service unavailable'], 503);
} catch (Throwable $e) {
    error_log('admin.php error: ' . $e->getMessage());
    jsonResponse(['success' => false, 'error' => 'Internal server error'], 500);
}
