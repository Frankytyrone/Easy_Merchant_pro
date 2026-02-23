<?php
/**
 * stripe_webhook.php — Handle Stripe webhook events
 *
 * Stripe sends a POST with a JSON payload and a Stripe-Signature header.
 * We verify the signature, then handle checkout.session.completed and
 * payment_intent.succeeded events to mark invoices as paid.
 *
 * Configure in Stripe Dashboard → Developers → Webhooks → Add endpoint:
 *   URL: https://yourdomain/ebmpro_api/stripe_webhook.php
 *   Events: checkout.session.completed, payment_intent.succeeded,
 *           payment_link.completed
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/common.php';

// Webhooks must NOT require auth — Stripe signs them differently
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

$pdo     = getDb();
$payload = file_get_contents('php://input');
if (!$payload) {
    http_response_code(400);
    echo json_encode(['error' => 'Empty payload']);
    exit;
}

// ── Verify Stripe signature ───────────────────────────────────────────────────
$settings      = getSettings($pdo);
$webhookSecret = $settings['stripe_webhook_secret'] ?? '';

if (!empty($webhookSecret)) {
    $sigHeader = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';
    if (!verifyStripeSignature($payload, $sigHeader, $webhookSecret)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid signature']);
        exit;
    }
}

$event = json_decode($payload, true);
if (!is_array($event) || empty($event['type'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid event']);
    exit;
}

// ── Handle events ─────────────────────────────────────────────────────────────
$eventType = $event['type'];
$object    = $event['data']['object'] ?? [];

if (in_array($eventType, ['checkout.session.completed', 'payment_link.completed'], true)) {
    // payment_link metadata is on the session object
    $invoiceId     = $object['metadata']['invoice_id']     ?? null;
    $invoiceNumber = $object['metadata']['invoice_number'] ?? null;
    $amountTotal   = isset($object['amount_total']) ? ((int)$object['amount_total'] / 100) : 0;

    if ($invoiceId) {
        markInvoicePaid($pdo, (int)$invoiceId, $amountTotal, 'stripe', $event['id']);
    } elseif ($invoiceNumber) {
        $stmt = $pdo->prepare('SELECT id FROM invoices WHERE invoice_number = ? LIMIT 1');
        $stmt->execute([$invoiceNumber]);
        $row = $stmt->fetch();
        if ($row) {
            markInvoicePaid($pdo, (int)$row['id'], $amountTotal, 'stripe', $event['id']);
        }
    }
}

http_response_code(200);
echo json_encode(['received' => true]);
exit;

// ── Helpers ───────────────────────────────────────────────────────────────────
function markInvoicePaid(PDO $pdo, int $invoiceId, float $amount, string $gateway, string $reference): void
{
    try {
        // Insert payment record
        $pdo->prepare(
            "INSERT INTO payments (invoice_id, payment_date, amount, method, reference, notes, created_at)
             VALUES (?, CURDATE(), ?, 'other', ?, ?, NOW())"
        )->execute([$invoiceId, $amount, $reference, "Paid via {$gateway} (webhook)"]);

        // Recalculate balance
        $stmt = $pdo->prepare('SELECT total, amount_paid FROM invoices WHERE id = ?');
        $stmt->execute([$invoiceId]);
        $inv = $stmt->fetch();
        if (!$inv) {
            return;
        }

        $newPaid    = round((float)$inv['amount_paid'] + $amount, 2);
        $newBalance = round((float)$inv['total'] - $newPaid, 2);
        $status     = $newBalance <= 0 ? 'paid' : ($newPaid > 0 ? 'part_paid' : 'pending');

        $pdo->prepare(
            'UPDATE invoices SET amount_paid = ?, balance = ?, status = ? WHERE id = ?'
        )->execute([$newPaid, max(0, $newBalance), $status, $invoiceId]);

        error_log("stripe_webhook: invoice {$invoiceId} marked {$status} (paid {$amount})");
    } catch (PDOException $e) {
        error_log('stripe_webhook markInvoicePaid error: ' . $e->getMessage());
    }
}

function verifyStripeSignature(string $payload, string $sigHeader, string $secret): bool
{
    // Parse timestamp and signatures from header
    $parts = explode(',', $sigHeader);
    $timestamp = null;
    $signatures = [];
    foreach ($parts as $part) {
        [$k, $v] = array_pad(explode('=', $part, 2), 2, '');
        if ($k === 't') {
            $timestamp = $v;
        } elseif ($k === 'v1') {
            $signatures[] = $v;
        }
    }
    if (!$timestamp || empty($signatures)) {
        return false;
    }
    // Reject timestamps older than 5 minutes
    if (abs(time() - (int)$timestamp) > 300) {
        return false;
    }
    $signed   = $timestamp . '.' . $payload;
    $expected = hash_hmac('sha256', $signed, $secret);
    foreach ($signatures as $sig) {
        if (hash_equals($expected, $sig)) {
            return true;
        }
    }
    return false;
}
