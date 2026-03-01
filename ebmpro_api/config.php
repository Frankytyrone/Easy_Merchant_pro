<?php
/* ============================================================
   EBM Pro — Database Configuration

   FOR XAMPP (local testing):
   DB_NAME = 'ebmpro_local'
   DB_USER = 'root'
   DB_PASS = ''

   FOR FASTCOMET (app.shanemcgee.biz):
   DB_NAME = 'youraccount_ebmpro'  (check in cPanel MySQL Databases)
   DB_USER = 'youraccount_user'    (check in cPanel MySQL Databases)
   DB_PASS = 'yourpassword'
   ============================================================ */

define('DB_HOST',    'localhost');
define('DB_NAME',    'DB_NAME_HERE');   // ← change this for live server
define('DB_USER',    'DB_USER_HERE');   // ← change this for live server
define('DB_PASS',    'DB_PASS_HERE');   // ← change this for live server
define('DB_CHARSET', 'utf8mb4');

define('APP_ENV',  'local');  // change to 'production' on live server
define('APP_URL',  'http://localhost/ebmpro');
define('API_URL',  'http://localhost/ebmpro_api');

define('SITE_URL',  'https://shanemcgee.biz');
define('APP_PATH',  '/ebmpro/');
define('API_PATH',  '/ebmpro_api/');
define('TRACK_URL', 'https://shanemcgee.biz/track/open.php');
define('JWT_SECRET', '69a09b0ef43bcb8970f16b5b915fac3be4c68caa954d3a6a7e2d9ecc71ff719a');

// Secret token required to access diagnose.php — change this to a random string on your server
define('DIAGNOSE_TOKEN', 'CHANGE_ME_TO_RANDOM_STRING');

// Secret used by the recurring invoice cron job — change this to a random string
define('CRON_SECRET', 'CHANGE_ME_TO_RANDOM_SECRET');

try {
    $pdo = new PDO('mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4', DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
} catch (PDOException $e) {
    http_response_code(503);
    die(json_encode(['error' => 'DB unavailable']));
}
