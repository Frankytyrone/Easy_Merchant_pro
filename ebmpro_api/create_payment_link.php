<?php
/**
 * create_payment_link.php — Generate a Stripe or Revolut payment link for an invoice
 *
 * POST JSON { invoice_id: N }
 * Returns { success, url, gateway }
 */
require_once __DIR__ . '/common.php';

setCorsHeaders();
header('Content-Type: application/json; charset=utf-8');

$auth = requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'error' => 'POST required'], 405);
}

$pdo  = getDb();
$body = json_decode(file_get_contents('php://input'), true) ?? [];

$invoiceId = (int)($body['invoice_id'] ?? 0);
if (!$invoiceId) {
    jsonResponse(['success' => false, 'error' => 'invoice_id required'], 422);
}

// Load invoice
$stmt = $pdo->prepare('SELECT * FROM invoices WHERE id = ?');
$stmt->execute([$invoiceId]);
$invoice = $stmt->fetch();
if (!$invoice) {
    jsonResponse(['success' => false, 'error' => 'Invoice not found'], 404);
}

$settings = getSettings($pdo);
$gateway  = $settings['payment_gateway'] ?? 'stripe';

// Amount in cents (Stripe) / minor units
$amountCents = (int)round((float)$invoice['balance'] * 100);
if ($amountCents <= 0) {
    jsonResponse(['success' => false, 'error' => 'Invoice balance is zero — nothing to pay'], 422);
}

$description = 'Invoice ' . htmlspecialchars($invoice['invoice_number'], ENT_QUOTES, 'UTF-8');

if ($gateway === 'revolut') {
    // ── Revolut Business payment link ─────────────────────────────────────────
    $apiKey = $settings['revolut_api_key'] ?? '';
    if (empty($apiKey)) {
        jsonResponse(['success' => false, 'error' => 'Revolut API key not configured'], 503);
    }

    $payload = json_encode([
        'amount'      => $amountCents,
        'currency'    => 'EUR',
        'description' => $description,
        'reference'   => $invoice['invoice_number'],
    ]);

    $ch = curl_init('https://merchant.revolut.com/api/1.0/orders');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ],
        CURLOPT_TIMEOUT        => 15,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 201) {
        error_log('Revolut error ' . $httpCode . ': ' . $response);
        jsonResponse(['success' => false, 'error' => 'Revolut order creation failed'], 502);
    }

    $data = json_decode($response, true);
    $url  = $data['checkout_url'] ?? (isset($data['public_id']) ? 'https://checkout.revolut.com/pay/' . $data['public_id'] : null);
    if (!$url) {
        jsonResponse(['success' => false, 'error' => 'Revolut did not return a checkout URL'], 502);
    }

    // Save link to invoice notes via parameterized query
    $pdo->prepare("UPDATE invoices SET notes = CONCAT(COALESCE(notes,''), ?) WHERE id = ?")
        ->execute(["\n[Revolut link: {$url}]", $invoiceId]);

    jsonResponse(['success' => true, 'url' => $url, 'gateway' => 'revolut']);
}

// ── Default: Stripe payment link ─────────────────────────────────────────────
$secretKey = $settings['stripe_secret_key'] ?? '';
if (empty($secretKey)) {
    jsonResponse(['success' => false, 'error' => 'Stripe secret key not configured in Settings'], 503);
}

// Create a Stripe Price (one-time) and Payment Link
// Step 1: Create price
$priceResponse = stripeRequest($secretKey, 'POST', 'https://api.stripe.com/v1/prices', [
    'unit_amount' => $amountCents,
    'currency'    => 'eur',
    'product_data[name]' => $description,
]);

if (empty($priceResponse['id'])) {
    error_log('Stripe price creation failed: ' . json_encode($priceResponse));
    jsonResponse(['success' => false, 'error' => 'Stripe price creation failed'], 502);
}

// Step 2: Create payment link
$linkResponse = stripeRequest($secretKey, 'POST', 'https://api.stripe.com/v1/payment_links', [
    'line_items[0][price]'    => $priceResponse['id'],
    'line_items[0][quantity]' => 1,
    'metadata[invoice_id]'    => $invoiceId,
    'metadata[invoice_number]'=> $invoice['invoice_number'],
    'after_completion[type]'  => 'redirect',
    'after_completion[redirect][url]' => (defined('SITE_URL') ? SITE_URL : 'https://localhost') . '/ebmpro/',
]);

if (empty($linkResponse['url'])) {
    error_log('Stripe payment link creation failed: ' . json_encode($linkResponse));
    jsonResponse(['success' => false, 'error' => 'Stripe payment link creation failed'], 502);
}

$url = $linkResponse['url'];

// Save link reference
$pdo->prepare("UPDATE invoices SET notes = CONCAT(COALESCE(notes,''), ?) WHERE id = ?")
    ->execute(["\n[Stripe link: {$url}]", $invoiceId]);

auditLog($pdo, (int)$auth['user_id'], $auth['username'], $invoice['store_code'],
    'payment_link', 'invoice', $invoiceId, null, ['url' => $url, 'gateway' => 'stripe']);

jsonResponse(['success' => true, 'url' => $url, 'gateway' => 'stripe']);

// ── Stripe HTTP helper ────────────────────────────────────────────────────────
function stripeRequest(string $secretKey, string $method, string $url, array $params = []): array
{
    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD        => $secretKey . ':',
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
    ];
    if ($method === 'POST') {
        $opts[CURLOPT_POST]       = true;
        $opts[CURLOPT_POSTFIELDS] = http_build_query($params);
    }
    curl_setopt_array($ch, $opts);
    $response = curl_exec($ch);
    curl_close($ch);
    return json_decode($response ?: '{}', true) ?: [];
}
