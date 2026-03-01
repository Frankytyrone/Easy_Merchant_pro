<?php
/**
 * backup.php — Database backup management
 *
 * POST ?action=create  — Create a new backup (SQL dump)
 * GET  ?action=list    — List existing backups
 * GET  ?action=download&file=filename.sql — Download a backup file
 * POST ?action=delete&file=filename.sql  — Delete a backup file
 */
require_once __DIR__ . '/common.php';

setCorsHeaders();

$auth = requireAuth();
if ($auth['role'] !== 'admin') {
    jsonResponse(['success' => false, 'error' => 'Admin access required'], 403);
}

$action  = $_GET['action'] ?? $_POST['action'] ?? 'create';
$method  = $_SERVER['REQUEST_METHOD'];
$backDir = __DIR__ . '/backups/';

if (!is_dir($backDir)) {
    @mkdir($backDir, 0700, true);
    // Protect the directory
    file_put_contents($backDir . '.htaccess', "Deny from all\n");
}

// ── Validate filename helper ──────────────────────────────────────────────────
function validateBackupFilename(string $filename): bool
{
    return (bool) preg_match('/^ebmpro_backup_\d{8}_\d{6}\.sql$/', $filename);
}

switch ($action) {

    // ─────────────────────────────────────────────────────────────────────────
    case 'create':
        if ($method !== 'POST') {
            jsonResponse(['success' => false, 'error' => 'POST required'], 405);
        }

        header('Content-Type: application/json; charset=utf-8');

        $timestamp = date('Ymd_His');
        $filename  = "ebmpro_backup_{$timestamp}.sql";
        $filepath  = $backDir . $filename;

        try {
            $pdo = getDb();

            // Try mysqldump first
            $dumpCmd   = null;

            if (function_exists('exec') || function_exists('shell_exec')) {
                // Locate mysqldump binary (only from known paths, not arbitrary input)
                $candidates = ['/usr/bin/mysqldump', '/usr/local/bin/mysqldump'];
                foreach ($candidates as $bin) {
                    if (file_exists($bin) && is_executable($bin)) {
                        $dumpCmd = $bin;
                        break;
                    }
                }
            }

            if ($dumpCmd) {
                $pwArg = defined('DB_PASS') && DB_PASS !== ''
                    ? '--password=' . escapeshellarg(DB_PASS)
                    : '';
                $cmd = sprintf(
                    '%s -h %s -u %s %s --single-transaction --routines --triggers %s > %s 2>&1',
                    escapeshellarg($dumpCmd),
                    escapeshellarg(defined('DB_HOST') ? DB_HOST : 'localhost'),
                    escapeshellarg(defined('DB_USER') ? DB_USER : 'root'),
                    $pwArg,
                    escapeshellarg(defined('DB_NAME') ? DB_NAME : ''),
                    escapeshellarg($filepath)
                );
                exec($cmd, $output, $exitCode);
                if ($exitCode !== 0) {
                    // Fall back to PHP export
                    $dumpCmd = null;
                }
            }

            if (!$dumpCmd) {
                // Pure PHP table export
                $tables  = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
                $content = "-- Easy Merchant Pro backup generated " . date('Y-m-d H:i:s') . "\n";
                $content .= "SET FOREIGN_KEY_CHECKS=0;\n\n";

                foreach ($tables as $table) {
                    $createRow = $pdo->query("SHOW CREATE TABLE `{$table}`")->fetch(PDO::FETCH_NUM);
                    $content  .= "-- Table: {$table}\n";
                    $content  .= "DROP TABLE IF EXISTS `{$table}`;\n";
                    $content  .= $createRow[1] . ";\n\n";

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
                            $content .= "INSERT INTO `{$table}` ({$columns}) VALUES\n"
                                . implode(",\n", $values) . ";\n";
                        }
                        $content .= "\n";
                    }
                }
                $content .= "SET FOREIGN_KEY_CHECKS=1;\n";
                file_put_contents($filepath, $content);
            }

            $size = file_exists($filepath) ? filesize($filepath) : 0;
            jsonResponse([
                'success'  => true,
                'filename' => $filename,
                'size'     => $size,
                'size_mb'  => round($size / 1048576, 2),
            ]);
        } catch (Throwable $e) {
            error_log('backup.php create error: ' . $e->getMessage());
            jsonResponse(['success' => false, 'error' => 'Backup failed: ' . $e->getMessage()], 500);
        }
        break;

    // ─────────────────────────────────────────────────────────────────────────
    case 'list':
        header('Content-Type: application/json; charset=utf-8');
        $files = [];
        if (is_dir($backDir)) {
            foreach (glob($backDir . 'ebmpro_backup_*.sql') as $f) {
                $files[] = [
                    'filename' => basename($f),
                    'size'     => filesize($f),
                    'size_mb'  => round(filesize($f) / 1048576, 2),
                    'created'  => date('Y-m-d H:i:s', filemtime($f)),
                ];
            }
        }
        // Sort newest first
        usort($files, fn($a, $b) => strcmp($b['created'], $a['created']));
        jsonResponse(['success' => true, 'data' => $files]);
        break;

    // ─────────────────────────────────────────────────────────────────────────
    case 'download':
        $filename = basename($_GET['file'] ?? '');
        if (!validateBackupFilename($filename)) {
            jsonResponse(['success' => false, 'error' => 'Invalid filename'], 400);
        }
        $filepath = $backDir . $filename;
        if (!file_exists($filepath)) {
            jsonResponse(['success' => false, 'error' => 'File not found'], 404);
        }
        ob_end_clean();
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($filepath));
        header('Cache-Control: no-cache, no-store, must-revalidate');
        readfile($filepath);
        exit;

    // ─────────────────────────────────────────────────────────────────────────
    case 'delete':
        if ($method !== 'POST') {
            header('Content-Type: application/json; charset=utf-8');
            jsonResponse(['success' => false, 'error' => 'POST required'], 405);
        }
        header('Content-Type: application/json; charset=utf-8');
        $filename = basename($_GET['file'] ?? '');
        if (!validateBackupFilename($filename)) {
            jsonResponse(['success' => false, 'error' => 'Invalid filename'], 400);
        }
        $filepath = $backDir . $filename;
        if (!file_exists($filepath)) {
            jsonResponse(['success' => false, 'error' => 'File not found'], 404);
        }
        if (!unlink($filepath)) {
            jsonResponse(['success' => false, 'error' => 'Could not delete file'], 500);
        }
        jsonResponse(['success' => true, 'message' => 'Backup deleted']);
        break;

    default:
        header('Content-Type: application/json; charset=utf-8');
        jsonResponse(['success' => false, 'error' => 'Unknown action'], 400);
}
