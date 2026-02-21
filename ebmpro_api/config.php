<?php
define('SITE_URL', 'https://shanemcgee.biz');
define('APP_PATH', '/ebmpro/');
define('API_PATH', '/ebmpro_api/');
define('TRACK_URL', 'https://shanemcgee.biz/track/open.php');
define('JWT_SECRET', '69a09b0ef43bcb8970f16b5b915fac3be4c68caa954d3a6a7e2d9ecc71ff719a');
define('DB_HOST', 'localhost');
define('DB_NAME', 'DB_NAME_HERE');
define('DB_USER', 'DB_USER_HERE');
define('DB_PASS', 'DB_PASS_HERE');
try {
    $pdo = new PDO('mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4', DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
} catch (PDOException $e) {
    http_response_code(503);
    die(json_encode(['error' => 'DB unavailable']));
}
