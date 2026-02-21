<?php
// Tracking pixel — records email open event and returns a 1×1 transparent GIF.

header('Content-Type: image/gif');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

$token = isset($_GET['t']) ? trim($_GET['t']) : '';

if ($token !== '') {
    // Log the open event (requires DB connection)
    require_once __DIR__ . '/../ebmpro_api/config.php';
    try {
        $stmt = $pdo->prepare("UPDATE email_log SET opened_at = NOW() WHERE token = ? AND opened_at IS NULL");
        $stmt->execute([$token]);
    } catch (Exception $e) {
        // Silently ignore DB errors so the pixel always returns
    }
}

// 1×1 transparent GIF
echo base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
