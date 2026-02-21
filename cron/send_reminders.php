<?php
/*
 * cPanel cron command:
 * php /home/USERNAME/public_html/cron/send_reminders.php
 * Replace USERNAME with your actual FastComet cPanel username
 * App URL: https://shanemcgee.biz/ebmpro/
 */
require_once '../ebmpro_api/config.php';
require_once '../ebmpro_api/PHPMailer/PHPMailer.php';
require_once '../ebmpro_api/PHPMailer/SMTP.php';
require_once '../ebmpro_api/PHPMailer/Exception.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

$sent   = 0;
$errors = 0;

try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8',
        DB_USER,
        DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (Exception $e) {
    echo "DB connection failed: " . $e->getMessage() . PHP_EOL;
    exit(1);
}

// Load SMTP settings from key-value settings table
$settings = [];
try {
    $rows = $pdo->query('SELECT `key`, value FROM settings')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) { $settings[$r['key']] = $r['value']; }
} catch (Exception $e) {
    echo "Could not load settings: " . $e->getMessage() . PHP_EOL;
}

// Find overdue invoices that have not yet received a reminder today
$sql = "
    SELECT i.*, c.company_name AS customer_name, c.email_address AS customer_email
    FROM invoices i
    LEFT JOIN customers c ON i.customer_id = c.id
    WHERE i.status IN ('sent', 'part_paid', 'overdue')
      AND i.due_date < CURDATE()
      AND i.balance > 0
      AND (i.email_address IS NOT NULL OR c.email_address IS NOT NULL)
      AND NOT EXISTS (
          SELECT 1 FROM email_log el
          WHERE el.invoice_id = i.id
            AND DATE(el.sent_at) = CURDATE()
      )
";

try {
    $invoices = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    echo "Query failed: " . $e->getMessage() . PHP_EOL;
    exit(1);
}

foreach ($invoices as $invoice) {
    $recipientEmail = !empty($invoice['email_address'])
        ? $invoice['email_address']
        : $invoice['customer_email'];    $recipientName = $invoice['customer_name'] ?? '';
    $invoiceNumber = $invoice['invoice_number'] ?? $invoice['id'];
    $balance       = number_format((float)$invoice['balance'], 2);
    $dueDate       = $invoice['due_date'];
    $daysOverdue   = (int)floor((time() - strtotime($dueDate)) / 86400);
    $token         = bin2hex(random_bytes(16));

    $subject = "Payment Reminder - Invoice {$invoiceNumber} - €{$balance} due";

    $body = "<!DOCTYPE html>
<html>
<head><meta charset='UTF-8'><style>
  body{font-family:Arial,sans-serif;color:#333;font-size:15px;}
  .box{max-width:600px;margin:30px auto;border:1px solid #ddd;border-radius:6px;padding:32px;}
  h2{color:#c0392b;}
  table{width:100%;border-collapse:collapse;margin-top:16px;}
  th,td{padding:10px 14px;border:1px solid #eee;text-align:left;}
  th{background:#f5f5f5;}
  .footer{margin-top:28px;font-size:12px;color:#888;}
</style></head>
<body>
<div class='box'>
  <h2>Payment Reminder</h2>
  <p>Dear " . htmlspecialchars($recipientName) . ",</p>
  <p>This is a reminder that the following invoice is now <strong>{$daysOverdue} day(s) overdue</strong>:</p>
  <table>
    <tr><th>Invoice Number</th><td>" . htmlspecialchars($invoiceNumber) . "</td></tr>
    <tr><th>Due Date</th><td>" . htmlspecialchars($dueDate) . "</td></tr>
    <tr><th>Amount Due</th><td>€{$balance}</td></tr>
    <tr><th>Days Overdue</th><td>{$daysOverdue}</td></tr>
  </table>
  <p style='margin-top:20px;'>Please arrange payment at your earliest convenience. If you have already made payment, please disregard this notice.</p>
  <p>If you have any queries regarding this invoice, please do not hesitate to contact us.</p>
  <p>Thank you for your business.</p>
  <div class='footer'>This is an automated reminder from Easy Builders Merchant Pro.</div>
  <img src='" . (defined('TRACK_URL') ? TRACK_URL : 'https://shanemcgee.biz/track/open.php') . "?t={$token}' width='1' height='1' alt='' style='display:none;' />
</div>
</body>
</html>";

    try {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host       = $settings['smtp_host']       ?? 'localhost';
        $mail->SMTPAuth   = true;
        $mail->Username   = $settings['smtp_user']       ?? '';
        $mail->Password   = $settings['smtp_pass']       ?? '';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = (int)($settings['smtp_port'] ?? 587);

        $fromEmail = $settings['smtp_user']      ?? '';
        $fromName  = $settings['smtp_from_name'] ?? 'Easy Builders Merchant Pro';
        $mail->setFrom($fromEmail, $fromName);
        $mail->addAddress($recipientEmail, $recipientName);

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $body;
        $mail->AltBody = "Payment Reminder - Invoice {$invoiceNumber} for €{$balance} is {$daysOverdue} day(s) overdue. Please arrange payment as soon as possible.";

        $mail->send();

        // Log in email_log
        $pdo->prepare("
            INSERT INTO email_log (invoice_id, to_email, subject, tracking_token, sent_at, status)
            VALUES (?, ?, ?, ?, NOW(), 'sent')
        ")->execute([$invoice['id'], $recipientEmail, $subject, $token]);

        // Mark invoice as overdue
        $pdo->prepare("UPDATE invoices SET status = 'overdue' WHERE id = ?")
            ->execute([$invoice['id']]);

        echo "Sent reminder for invoice {$invoiceNumber} to {$recipientEmail}" . PHP_EOL;
        $sent++;
    } catch (Exception $e) {
        echo "Error sending reminder for invoice {$invoiceNumber}: " . $e->getMessage() . PHP_EOL;
        $errors++;
    }
}

echo PHP_EOL . "Reminders sent: {$sent}, Errors: {$errors}" . PHP_EOL;