<?php
/*
 * generate_recurring.php — Daily cron job to create recurring invoices.
 *
 * cPanel cron command (run daily at 06:00):
 * 0 6 * * * php /home/USERNAME/public_html/cron/generate_recurring.php
 *
 * Replace USERNAME with your actual FastComet cPanel username.
 */
require_once __DIR__ . '/../ebmpro_api/config.php';

$cronSecret = defined('CRON_SECRET') ? CRON_SECRET : '';
$apiUrl     = (defined('API_URL') ? API_URL : 'http://localhost/ebmpro_api')
            . '/recurring.php?action=run_due';

$context = stream_context_create([
    'http' => [
        'method'  => 'POST',
        'header'  => "Content-Type: application/json\r\nX-Cron-Secret: " . $cronSecret . "\r\n",
        'content' => '{}',
        'timeout' => 30,
    ],
]);

$response = @file_get_contents($apiUrl, false, $context);
if ($response === false) {
    echo "Error: Could not reach recurring.php endpoint." . PHP_EOL;
    exit(1);
}

$data = json_decode($response, true);
if (!empty($data['invoices_created'])) {
    echo "Recurring invoices created: " . implode(', ', $data['invoices_created']) . PHP_EOL;
} else {
    echo "No recurring invoices due." . PHP_EOL;
}
echo "Done." . PHP_EOL;
