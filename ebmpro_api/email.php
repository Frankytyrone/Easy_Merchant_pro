<?php
require_once __DIR__ . '/common.php';

setCorsHeaders();
header('Content-Type: application/json; charset=utf-8');

$auth = requireAuth();

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

    // ── Load SMTP settings from DB ─────────────────────────────────────────
    $stmt = $pdo->query("SELECT name, value FROM settings WHERE name LIKE 'smtp_%'");
    $rows = $stmt->fetchAll();
    $smtp = [];
    foreach ($rows as $row) {
        $smtp[$row['name']] = $row['value'];
    }

    // ── Generate tracking token ────────────────────────────────────────────
    $trackingToken = bin2hex(random_bytes(16));
    $domain        = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $scheme        = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $trackingPixel = '<img src="' . $scheme . '://' . htmlspecialchars($domain, ENT_QUOTES, 'UTF-8')
        . '/track/open.php?t=' . $trackingToken . '" width="1" height="1" alt="" />';

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
        $mail->Host       = $smtp['smtp_host']     ?? 'localhost';
        $mail->Port       = (int)($smtp['smtp_port'] ?? 587);
        $mail->SMTPAuth   = !empty($smtp['smtp_user']);
        $mail->Username   = $smtp['smtp_user']     ?? '';
        $mail->Password   = $smtp['smtp_pass']     ?? '';
        $mail->SMTPSecure = $smtp['smtp_secure']   ?? 'tls';

        $fromEmail = $smtp['smtp_from_email'] ?? ('noreply@' . $domain);
        $fromName  = $smtp['smtp_from_name']  ?? 'Easy Merchant Pro';

        $mail->setFrom($fromEmail, $fromName);
        $mail->addAddress($toEmail);
        $mail->Subject  = $subject;
        $mail->isHTML(true);
        $mail->Body     = $htmlBody;
        $mail->AltBody  = strip_tags($message);
        $mail->CharSet  = 'UTF-8';

        $mail->send();
        $messageId = $mail->getLastMessageID();

        // ── Log to email_log ───────────────────────────────────────────────
        $logStmt = $pdo->prepare(
            'INSERT INTO email_log
             (invoice_id, to_email, subject, type, tracking_token, message_id, sent_at)
             VALUES (?,?,?,?,?,?,NOW())'
        );
        $logStmt->execute([$invoiceId, $toEmail, $subject, $type, $trackingToken, $messageId]);

        // ── Update invoice record ──────────────────────────────────────────
        if ($invoiceId) {
            $pdo->prepare(
                'UPDATE invoices SET email_sent_at = NOW(), email_tracking_token = ? WHERE id = ?'
            )->execute([$trackingToken, $invoiceId]);
        }

        return ['success' => true, 'message_id' => $messageId, 'tracking_token' => $trackingToken];

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
