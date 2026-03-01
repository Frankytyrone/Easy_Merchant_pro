<?php
/**
 * generate_recurring.php — Cron script to trigger recurring invoice generation.
 *
 * Schedule this in cPanel (or crontab) to run daily, e.g.:
 *   0 6 * * * php /path/to/cron/generate_recurring.php
 *
 * It calls the recurring.php API endpoint with the X-Cron-Secret header.
 * Set RECURRING_API_URL to point at your live API URL if needed.
 */

// Load config to get CRON_SECRET and API_URL
$configPath = __DIR__ . '/../ebmpro_api/config.php';
if (!file_exists($configPath)) {
    fwrite(STDERR, "[generate_recurring] config.php not found at: {$configPath}\n");
    exit(1);
}

// Extract constants without executing the PDO connection
// We only need CRON_SECRET and API_URL, so include the config
require_once $configPath;

$apiUrl    = (defined('API_URL') ? API_URL : 'http://localhost/ebmpro_api')
    . '/recurring.php?action=run_due';
// NOTE: On production, ensure API_URL in config.php uses https:// to protect CRON_SECRET in transit.
$cronSecret = defined('CRON_SECRET') ? CRON_SECRET : '';

if (empty($cronSecret) || $cronSecret === 'CHANGE_ME_TO_RANDOM_SECRET') {
    fwrite(STDERR, "[generate_recurring] CRON_SECRET is not configured in config.php\n");
    exit(1);
}

$context = stream_context_create([
    'http' => [
        'method'  => 'POST',
        'header'  => implode("\r\n", [
            'Content-Type: application/json',
            'X-Cron-Secret: ' . $cronSecret,
        ]),
        'content'         => '{}',
        'timeout'         => 60,
        'ignore_errors'   => true,
    ],
]);

$response = @file_get_contents($apiUrl, false, $context);

if ($response === false) {
    fwrite(STDERR, "[generate_recurring] Failed to reach API at: {$apiUrl}\n");
    exit(1);
}

$data = json_decode($response, true);

if (!is_array($data)) {
    fwrite(STDERR, "[generate_recurring] Invalid response: {$response}\n");
    exit(1);
}

if (empty($data['success'])) {
    fwrite(STDERR, "[generate_recurring] API error: " . ($data['error'] ?? 'unknown') . "\n");
    exit(1);
}

$created = count($data['created'] ?? []);
$errors  = $data['errors'] ?? [];

echo "[generate_recurring] " . date('Y-m-d H:i:s') . " — {$created} invoice(s) created.\n";

if (!empty($errors)) {
    foreach ($errors as $err) {
        fwrite(STDERR, "[generate_recurring] Error: {$err}\n");
    }
}

exit(0);
