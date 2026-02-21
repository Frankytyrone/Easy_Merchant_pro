<?php
error_reporting(0);
@ini_set('display_errors', 0);

require_once '../ebmpro_api/config.php';

$gif = "\x47\x49\x46\x38\x39\x61\x01\x00\x01\x00\x80\x00\x00\x00\x00\x00"
     . "\xFF\xFF\xFF\x21\xF9\x04\x00\x00\x00\x00\x00\x2C\x00\x00\x00\x00"
     . "\x01\x00\x01\x00\x00\x02\x02\x44\x01\x00\x3B";

header('Content-Type: image/gif');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

$token = isset($_GET['t']) ? trim($_GET['t']) : '';

if ($token !== '') {
    try {
        $pdo = new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8',
            DB_USER,
            DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );

        $stmt = $pdo->prepare(
            'SELECT id, invoice_id, opened_at FROM email_log WHERE tracking_token = ? LIMIT 1'
        );
        $stmt->execute([$token]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row && $row['opened_at'] === null) {
            $pdo->prepare(
                "UPDATE email_log SET opened_at = NOW(), status = 'opened' WHERE tracking_token = ?"
            )->execute([$token]);

            if (!empty($row['invoice_id'])) {
                $pdo->prepare(
                    'UPDATE invoices SET email_opened_at = NOW() WHERE id = ?'
                )->execute([$row['invoice_id']]);
            }
        }
    } catch (Exception $e) {
        // Silently ignore — pixel must always return
    }
}

echo $gif;