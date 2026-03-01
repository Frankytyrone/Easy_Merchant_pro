<?php
/**
 * backup.php — Database backup management
 *
 * POST ?action=create   — Create SQL dump, save to backups/ dir
 * GET  ?action=list     — List backup files
 * GET  ?action=download&file=filename.sql — Stream a backup file
 * POST ?action=delete&file=filename.sql   — Delete a backup file
 */
require_once __DIR__ . '/common.php';

setCorsHeaders();
header('Content-Type: application/json; charset=utf-8');

$auth = requireAuth();
if ($auth['role'] !== 'admin') {
    jsonResponse(['success' => false, 'error' => 'Admin access required'], 403);
}

define('BACKUP_DIR', __DIR__ . '/backups/');

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? null;

// ─────────────────────────────────────────────────────────────────────────────
// Validate a backup filename (prevent path traversal)
// ─────────────────────────────────────────────────────────────────────────────
function validateBackupFilename(string $filename): bool
{
    // Allow only alphanumeric, underscores, hyphens, single dots; no path traversal
    return (bool) preg_match('/^[a-zA-Z0-9_\-]+(\.[a-zA-Z0-9_\-]+)*\.(sql|zip)$/', $filename)
        && strpos($filename, '..') === false;
}

// ─────────────────────────────────────────────────────────────────────────────
// POST ?action=create
// ─────────────────────────────────────────────────────────────────────────────
if ($method === 'POST' && $action === 'create') {
    try {
        $pdo       = getDb();
        $timestamp = date('Ymd_His');
        $filename  = "backup_{$timestamp}.sql";
        $filepath  = BACKUP_DIR . $filename;

        if (!is_dir(BACKUP_DIR)) {
            mkdir(BACKUP_DIR, 0750, true);
        }

        $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);

        $sql  = "-- Easy Merchant Pro SQL Backup\n";
        $sql .= "-- Generated: " . date('Y-m-d H:i:s') . "\n";
        $sql .= "SET FOREIGN_KEY_CHECKS=0;\n\n";

        foreach ($tables as $table) {
            $createRow = $pdo->query("SHOW CREATE TABLE `{$table}`")->fetch(PDO::FETCH_NUM);
            $sql .= "-- Table: {$table}\n";
            $sql .= "DROP TABLE IF EXISTS `{$table}`;\n";
            $sql .= $createRow[1] . ";\n\n";

            $rows = $pdo->query("SELECT * FROM `{$table}`")->fetchAll(PDO::FETCH_ASSOC);
            if (!empty($rows)) {
                $columns = '`' . implode('`, `', array_keys($rows[0])) . '`';
                foreach (array_chunk($rows, 500) as $chunk) {
                    $values = [];
                    foreach ($chunk as $row) {
                        $escaped = array_map(static function ($v) use ($pdo) {
                            return $v === null ? 'NULL' : $pdo->quote((string)$v);
                        }, $row);
                        $values[] = '(' . implode(', ', $escaped) . ')';
                    }
                    $sql .= "INSERT INTO `{$table}` ({$columns}) VALUES\n"
                        . implode(",\n", $values) . ";\n";
                }
                $sql .= "\n";
            }
        }
        $sql .= "SET FOREIGN_KEY_CHECKS=1;\n";

        file_put_contents($filepath, $sql);

        jsonResponse([
            'success'  => true,
            'filename' => $filename,
            'size'     => filesize($filepath),
            'created'  => date('Y-m-d H:i:s'),
        ]);

    } catch (PDOException $e) {
        error_log('backup.php create DB error: ' . $e->getMessage());
        jsonResponse(['success' => false, 'error' => 'Service unavailable'], 503);
    } catch (Throwable $e) {
        error_log('backup.php create error: ' . $e->getMessage());
        jsonResponse(['success' => false, 'error' => 'Internal server error'], 500);
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// GET ?action=list
// ─────────────────────────────────────────────────────────────────────────────
if ($method === 'GET' && $action === 'list') {
    if (!is_dir(BACKUP_DIR)) {
        jsonResponse(['success' => true, 'data' => []]);
    }

    $files = [];
    foreach (glob(BACKUP_DIR . '*.{sql,zip}', GLOB_BRACE) as $path) {
        $files[] = [
            'filename' => basename($path),
            'size'     => filesize($path),
            'created'  => date('Y-m-d H:i:s', filemtime($path)),
        ];
    }

    // Sort newest first
    usort($files, static fn ($a, $b) => strcmp($b['created'], $a['created']));

    jsonResponse(['success' => true, 'data' => $files]);
}

// ─────────────────────────────────────────────────────────────────────────────
// GET ?action=download&file=filename.sql
// ─────────────────────────────────────────────────────────────────────────────
if ($method === 'GET' && $action === 'download') {
    $filename = $_GET['file'] ?? '';

    if (!validateBackupFilename($filename)) {
        jsonResponse(['success' => false, 'error' => 'Invalid filename'], 400);
    }

    $filepath = BACKUP_DIR . $filename;
    if (!file_exists($filepath)) {
        jsonResponse(['success' => false, 'error' => 'File not found'], 404);
    }

    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    $contentType = $ext === 'zip' ? 'application/zip' : 'application/sql';

    ob_end_clean();
    header('Content-Type: ' . $contentType);
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($filepath));
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    readfile($filepath);
    exit;
}

// ─────────────────────────────────────────────────────────────────────────────
// POST ?action=delete&file=filename.sql
// ─────────────────────────────────────────────────────────────────────────────
if ($method === 'POST' && $action === 'delete') {
    $filename = $_GET['file'] ?? (json_decode(file_get_contents('php://input'), true)['file'] ?? '');

    if (!validateBackupFilename($filename)) {
        jsonResponse(['success' => false, 'error' => 'Invalid filename'], 400);
    }

    $filepath = BACKUP_DIR . $filename;
    if (!file_exists($filepath)) {
        jsonResponse(['success' => false, 'error' => 'File not found'], 404);
    }

    if (!unlink($filepath)) {
        jsonResponse(['success' => false, 'error' => 'Could not delete file'], 500);
    }

    jsonResponse(['success' => true, 'message' => "Backup '{$filename}' deleted."]);
}

jsonResponse(['success' => false, 'error' => 'Unknown action or method'], 400);
