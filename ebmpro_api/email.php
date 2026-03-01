<?php
require_once __DIR__ . '/common.php';

setCorsHeaders();
header('Content-Type: application/json; charset=utf-8');

$auth = requireAuth();

// ── GET — return email tracking info for an invoice ───────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $invoiceId = (int)($_GET['invoice_id'] ?? 0);
    if (!$invoiceId) {
        jsonResponse(['success' => false, 'error' => 'invoice_id required'], 422);
    }
    try {
        $pdo  = getDb();
        $stmt = $pdo->prepare(
            'SELECT sent_at AS emailed_at, to_email,
                    (SELECT COUNT(*) FROM email_log el2
                     WHERE el2.invoice_id = ? AND el2.status = \'opened\') AS opened_count,
                    (SELECT MAX(el3.opened_at) FROM email_log el3
                     WHERE el3.invoice_id = ?) AS last_opened_at
             FROM email_log
             WHERE invoice_id = ?
             ORDER BY sent_at DESC
             LIMIT 1'
        );
        $stmt->execute([$invoiceId, $invoiceId, $invoiceId]);
        $row = $stmt->fetch();
        jsonResponse(['success' => true, 'data' => $row ?: null]);
    } catch (PDOException $e) {
        error_log('email.php GET error: ' . $e->getMessage());
        jsonResponse(['success' => false, 'error' => 'Service unavailable'], 503);
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'error' => 'Method not allowed'], 405);
}

/**
 * Core email-sending function, callable from other endpoints (e.g. reminders.php).
 * Returns ['success' => bool, ...].
 *
 * @param PDO   $pdo
 * @param array $params  Keys: invoice_id, to_email, subject, message, type
 */
function sendEmail(PDO $pdo, array $params): array
{
    $invoiceId = !empty($params['invoice_id']) ? (int)$params['invoice_id'] : null;
    $toEmail   = trim($params['to_email']  ?? '');
    $subject   = trim($params['subject']   ?? '');
    $message   = $params['message']        ?? '';
    $type      = $params['type']           ?? 'general';

    if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        return ['success' => false, 'error' => 'Invalid to_email address'];
    }
    if ($subject === '') {
        return ['success' => false, 'error' => 'subject is required'];
    }

    // ── Load SMTP settings from DB (key-value settings table) ─────────────────
    $allSettings = getSettings($pdo);
    $smtp = [
        'smtp_host'       => $allSettings['smtp_host']      ?? 'localhost',
        'smtp_port'       => (int)($allSettings['smtp_port'] ?? 587),
        'smtp_user'       => $allSettings['smtp_user']      ?? '',
        'smtp_pass'       => $allSettings['smtp_pass']      ?? '',
        'smtp_from_name'  => $allSettings['smtp_from_name'] ?? 'Easy Builders Merchant Pro',
        'smtp_from_email' => null,
    ];

    // ── Generate tracking token ────────────────────────────────────────────
    $trackingToken = bin2hex(random_bytes(16));
    $trackUrl      = defined('TRACK_URL') ? TRACK_URL : 'https://shanemcgee.biz/track/open.php';
    $trackingPixel = '<img src="' . $trackUrl . '?t=' . $trackingToken . '" width="1" height="1" alt="">';

    $htmlBody = $message . "\n" . $trackingPixel;

    // ── PHPMailer ─────────────────────────────────────────────────────────
    $mailerDir = __DIR__ . '/PHPMailer/';
    if (!file_exists($mailerDir . 'PHPMailer.php')) {
        return ['success' => false, 'error' => 'PHPMailer library not found'];
    }

    require_once $mailerDir . 'Exception.php';
    require_once $mailerDir . 'PHPMailer.php';
    require_once $mailerDir . 'SMTP.php';

    $mail = new PHPMailer\PHPMailer\PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host       = $smtp['smtp_host'];
        $mail->Port       = $smtp['smtp_port'];
        $mail->SMTPAuth   = !empty($smtp['smtp_user']);
        $mail->Username   = $smtp['smtp_user'];
        $mail->Password   = $smtp['smtp_pass'];
        $mail->SMTPSecure = 'tls';

        $fromEmail = $smtp['smtp_from_email'] ?? ('noreply@' . ($_SERVER['HTTP_HOST'] ?? 'localhost'));
        $fromName  = $smtp['smtp_from_name'];

        $mail->setFrom($fromEmail, $fromName);
        $mail->addAddress($toEmail);
        $mail->Subject  = $subject;
        $mail->isHTML(true);
        $mail->Body     = $htmlBody;
        $mail->AltBody  = strip_tags($message);
        $mail->CharSet  = 'UTF-8';

        $mail->send();

        // ── Log to email_log ──────────────────────────────────────────────────
        $logStmt = $pdo->prepare(
            'INSERT INTO email_log
             (invoice_id, to_email, subject, tracking_token, sent_at, status)
             VALUES (?,?,?,?,NOW(),?)'
        );
        $logStmt->execute([$invoiceId, $toEmail, $subject, $trackingToken, 'sent']);

        // ── Update invoice email tracking ──────────────────────────────────
        if ($invoiceId) {
            $pdo->prepare(
                'UPDATE invoices SET email_sent_at = NOW() WHERE id = ?'
            )->execute([$invoiceId]);
        }

        return ['success' => true, 'tracking_token' => $trackingToken];

    } catch (PHPMailer\PHPMailer\Exception $e) {
        error_log('email.php mailer error: ' . $e->getMessage());
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

// ── Handle direct POST request ────────────────────────────────────────────────
try {
    $pdo  = getDb();
    $body = json_decode(file_get_contents('php://input'), true) ?? [];

    $required = ['to_email', 'subject', 'message'];
    foreach ($required as $f) {
        if (empty($body[$f])) {
            jsonResponse(['success' => false, 'error' => "Field '$f' is required"], 422);
        }
    }

    $result = sendEmail($pdo, $body);
    $code   = $result['success'] ? 200 : 500;
    jsonResponse($result, $code);

} catch (PDOException $e) {
    error_log('email.php DB error: ' . $e->getMessage());
    jsonResponse(['success' => false, 'error' => 'Service unavailable'], 503);
} catch (Throwable $e) {
    error_log('email.php error: ' . $e->getMessage());
    jsonResponse(['success' => false, 'error' => 'Internal server error'], 500);
}