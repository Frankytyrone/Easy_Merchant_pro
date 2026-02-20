<?php
require_once __DIR__ . '/common.php';
require_once __DIR__ . '/email.php';

setCorsHeaders();
header('Content-Type: application/json; charset=utf-8');

// Allow both authenticated users and internal cron calls with a shared secret.
$cronSecret  = $_SERVER['HTTP_X_CRON_SECRET'] ?? '';
$settingsRow = null;
$auth        = null;

try {
    $pdo = getDb();

    // Check for cron secret from settings table
    $stmt = $pdo->prepare("SELECT value FROM settings WHERE name = 'cron_secret' LIMIT 1");
    $stmt->execute();
    $row = $stmt->fetch();
    if ($row && !empty($row['value']) && hash_equals($row['value'], $cronSecret)) {
        // Cron-authenticated — create a minimal pseudo-auth array
        $auth = ['user_id' => 0, 'username' => 'cron', 'role' => 'cron', 'store_id' => null];
    }
} catch (Throwable $e) {
    // DB might not be set up yet; fall through to token auth below.
}

if ($auth === null) {
    $auth = requireAuth();
}

$method = $_SERVER['REQUEST_METHOD'];

try {
    // ════════════════════════════════════════════════════════════════════════
    // GET — list invoices eligible for reminders
    // ════════════════════════════════════════════════════════════════════════
    if ($method === 'GET') {
        $today = date('Y-m-d');

        $stmt = $pdo->prepare(
            "SELECT i.id, i.invoice_number, i.invoice_date, i.due_date,
                    i.total, i.balance, i.currency, i.last_reminder_sent_at,
                    c.id AS customer_id, c.name AS customer_name, c.email AS customer_email
             FROM invoices i
             JOIN customers c ON c.id = i.customer_id
             WHERE i.status IN ('pending','part_paid')
               AND i.balance > 0
               AND i.due_date < ?
             ORDER BY i.due_date ASC"
        );
        $stmt->execute([$today]);
        $overdue = $stmt->fetchAll();

        jsonResponse(['success' => true, 'data' => $overdue]);
    }

    // ════════════════════════════════════════════════════════════════════════
    // POST — trigger reminder for a specific invoice
    // ════════════════════════════════════════════════════════════════════════
    if ($method === 'POST') {
        $body      = json_decode(file_get_contents('php://input'), true) ?? [];
        $invoiceId = (int)($body['invoice_id'] ?? 0);

        if (!$invoiceId) {
            jsonResponse(['success' => false, 'error' => 'invoice_id required'], 422);
        }

        $stmt = $pdo->prepare(
            'SELECT i.*, c.name AS customer_name, c.email AS customer_email
             FROM invoices i
             JOIN customers c ON c.id = i.customer_id
             WHERE i.id = ?'
        );
        $stmt->execute([$invoiceId]);
        $invoice = $stmt->fetch();

        if (!$invoice) {
            jsonResponse(['success' => false, 'error' => 'Invoice not found'], 404);
        }
        if (empty($invoice['customer_email'])) {
            jsonResponse(['success' => false, 'error' => 'Customer has no email address'], 422);
        }

        $subject = 'Payment Reminder: Invoice ' . $invoice['invoice_number'];
        $message = 'Dear ' . htmlspecialchars($invoice['customer_name'], ENT_QUOTES, 'UTF-8') . ",\n\n"
            . "This is a friendly reminder that invoice " . $invoice['invoice_number']
            . " dated " . $invoice['invoice_date']
            . " has an outstanding balance of " . $invoice['currency']
            . ' ' . number_format((float)$invoice['balance'], 2)
            . ".\n\nPlease arrange payment at your earliest convenience.\n\nThank you.";

        $result = sendEmail($pdo, [
            'invoice_id' => $invoiceId,
            'to_email'   => $invoice['customer_email'],
            'subject'    => $subject,
            'message'    => nl2br(htmlspecialchars($message, ENT_QUOTES, 'UTF-8')),
            'type'       => 'reminder',
        ]);

        if ($result['success']) {
            $pdo->prepare(
                'UPDATE invoices SET last_reminder_sent_at = NOW() WHERE id = ?'
            )->execute([$invoiceId]);

            auditLog($pdo, (int)$auth['user_id'], $auth['username'],
                (int)($invoice['store_id'] ?? 0),
                'reminder_sent', 'invoice', $invoiceId,
                null, ['to_email' => $invoice['customer_email']]);
        }

        jsonResponse($result);
    }

    jsonResponse(['success' => false, 'error' => 'Method not allowed'], 405);

} catch (PDOException $e) {
    error_log('reminders.php DB error: ' . $e->getMessage());
    jsonResponse(['success' => false, 'error' => 'Service unavailable'], 503);
} catch (Throwable $e) {
    error_log('reminders.php error: ' . $e->getMessage());
    jsonResponse(['success' => false, 'error' => 'Internal server error'], 500);
}
