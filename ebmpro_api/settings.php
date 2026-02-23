<?php
/**
 * settings.php — Application settings (key/value store)
 *
 * GET  — returns all settings as key-value pairs
 * POST/PUT — updates settings (admin only)
 *   Single:  { "key": "company_name", "value": "Easy Builders Merchant" }
 *   Batch:   { "settings": { "company_name": "...", "company_phone": "..." } }
 *   Flat:    { "company_name": "...", "company_phone": "..." }
 */
require_once __DIR__ . '/common.php';

setCorsHeaders();
header('Content-Type: application/json; charset=utf-8');

$auth = requireAuth();

$pdo = getDb();

$defaults = [
    'company_name'      => 'Easy Builders Merchant',
    'company_address_1' => '',
    'company_town'      => 'Falcarragh',
    'company_county'    => 'Donegal',
    'company_eircode'   => '',
    'company_phone'     => '',
    'company_email'     => '',
    'company_vat_no'    => '',
    'invoice_terms'     => 'Payment due within 30 days',
    'invoice_footer'    => 'Thank you for your business',
    'stripe_enabled'    => '0',
    'stripe_pk'         => '',
    'revolut_enabled'   => '0',
    'default_vat_rate'  => '23',
    'currency_symbol'   => '€',
];

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $settings = getSettings($pdo);

    // Seed defaults on first run if table is empty
    if (empty($settings)) {
        $insertStmt = $pdo->prepare(
            'INSERT IGNORE INTO settings (`key`, value) VALUES (?, ?)'
        );
        foreach ($defaults as $k => $v) {
            $insertStmt->execute([$k, $v]);
        }
        $settings = $defaults;
    }

    jsonResponse(['success' => true, 'settings' => $settings]);
}

if ($method === 'POST' || $method === 'PUT') {
    if ($auth['role'] !== 'admin') {
        jsonResponse(['success' => false, 'error' => 'Admin access required'], 403);
    }

    $body = json_decode(file_get_contents('php://input'), true) ?? [];

    $toSave = [];

    if (isset($body['settings']) && is_array($body['settings'])) {
        // Batch update via { "settings": { "key": "value", ... } }
        $toSave = $body['settings'];
    } elseif (isset($body['key']) && array_key_exists('value', $body)) {
        // Single update via { "key": "company_name", "value": "..." }
        $toSave[$body['key']] = $body['value'];
    } else {
        // Flat object — every key/value pair is a setting
        $reserved = ['success', 'error'];
        foreach ($body as $k => $v) {
            if (!in_array($k, $reserved, true)) {
                $toSave[$k] = $v;
            }
        }
    }

    if (empty($toSave)) {
        jsonResponse(['success' => false, 'error' => 'No settings provided'], 422);
    }

    $upsertStmt = $pdo->prepare(
        'INSERT INTO settings (`key`, value) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE value = VALUES(value)'
    );

    foreach ($toSave as $k => $v) {
        $upsertStmt->execute([(string)$k, (string)$v]);
    }

    jsonResponse(['success' => true, 'message' => 'Settings saved', 'updated' => count($toSave)]);
}

jsonResponse(['success' => false, 'error' => 'Method not allowed'], 405);
