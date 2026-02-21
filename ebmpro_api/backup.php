<?php
require_once __DIR__ . '/common.php';

setCorsHeaders();
header('Content-Type: application/json; charset=utf-8');

$auth = requireAuth();
if ($auth['role'] !== 'admin') {
    jsonResponse(['success' => false, 'error' => 'Admin access required'], 403);
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(['success' => false, 'error' => 'Method not allowed'], 405);
}

try {
    $pdo = getDb();

    $datestamp = date('Ymd');
    $timestamp = date('Ymd_His');
    $sqlFile   = "backup_{$datestamp}.sql";
    $jsonFile  = "backup_{$datestamp}.json";
    $zipName   = "backup_{$timestamp}.zip";

    // ── Discover all tables ───────────────────────────────────────────────────
    $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);

    // ── Build SQL dump (pure PHP — no shell exec) ─────────────────────────────
    $sqlContent = "-- Easy Merchant Pro backup generated " . date('Y-m-d H:i:s') . "\n";
    $sqlContent .= "SET FOREIGN_KEY_CHECKS=0;\n\n";

    foreach ($tables as $table) {
        // CREATE TABLE statement
        $createRow = $pdo->query("SHOW CREATE TABLE `{$table}`")->fetch(PDO::FETCH_NUM);
        $sqlContent .= "-- Table: {$table}\n";
        $sqlContent .= "DROP TABLE IF EXISTS `{$table}`;\n";
        $sqlContent .= $createRow[1] . ";\n\n";

        // INSERT rows in batches of 500
        $rows  = $pdo->query("SELECT * FROM `{$table}`")->fetchAll(PDO::FETCH_ASSOC);
        if (!empty($rows)) {
            $columns = '`' . implode('`, `', array_keys($rows[0])) . '`';
            foreach (array_chunk($rows, 500) as $chunk) {
                $values = [];
                foreach ($chunk as $row) {
                    $escaped = array_map(static function ($v) use ($pdo) {
                        if ($v === null) {
                            return 'NULL';
                        }
                        return $pdo->quote((string)$v);
                    }, $row);
                    $values[] = '(' . implode(', ', $escaped) . ')';
                }
                $sqlContent .= "INSERT INTO `{$table}` ({$columns}) VALUES\n"
                    . implode(",\n", $values) . ";\n";
            }
            $sqlContent .= "\n";
        }
    }
    $sqlContent .= "SET FOREIGN_KEY_CHECKS=1;\n";

    // ── Build JSON dump ───────────────────────────────────────────────────────
    $jsonData = [];
    foreach ($tables as $table) {
        $jsonData[$table] = $pdo->query("SELECT * FROM `{$table}`")->fetchAll(PDO::FETCH_ASSOC);
    }
    $jsonContent = json_encode($jsonData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

    // ── Create ZIP in memory (temp file) ─────────────────────────────────────
    $tmpFile = tempnam(sys_get_temp_dir(), 'ebm_backup_');
    if ($tmpFile === false) {
        jsonResponse(['success' => false, 'error' => 'Could not create temp file'], 500);
    }

    $zip = new ZipArchive();
    if ($zip->open($tmpFile, ZipArchive::OVERWRITE) !== true) {
        unlink($tmpFile);
        jsonResponse(['success' => false, 'error' => 'Could not create ZIP archive'], 500);
    }

    $zip->addFromString($sqlFile,  $sqlContent);
    $zip->addFromString($jsonFile, $jsonContent);
    $zip->close();

    // ── Stream ZIP to browser ─────────────────────────────────────────────────
    ob_end_clean();

    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $zipName . '"');
    header('Content-Length: ' . filesize($tmpFile));
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');

    readfile($tmpFile);
    unlink($tmpFile);
    exit;

} catch (PDOException $e) {
    error_log('backup.php DB error: ' . $e->getMessage());
    jsonResponse(['success' => false, 'error' => 'Service unavailable'], 503);
} catch (Throwable $e) {
    error_log('backup.php error: ' . $e->getMessage());
    if (isset($tmpFile) && file_exists($tmpFile)) {
        unlink($tmpFile);
    }
    jsonResponse(['success' => false, 'error' => 'Internal server error'], 500);
}