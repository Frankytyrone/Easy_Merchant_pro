<?php
// cron/send_reminders.php — sends payment reminder emails for overdue invoices.
//
// cPanel cron command:
// php /home/USERNAME/public_html/cron/send_reminders.php
// Replace USERNAME with your actual FastComet cPanel username
// App URL: https://shanemcgee.biz/ebmpro/

require_once __DIR__ . '/../ebmpro_api/config.php';

try {
    $stmt = $pdo->query(
        "SELECT i.*, c.email, c.name AS customer_name
         FROM invoices i
         JOIN customers c ON c.id = i.customer_id
         WHERE i.status = 'pending'
           AND i.due_date < CURDATE()
           AND (i.last_reminder_at IS NULL OR i.last_reminder_at < DATE_SUB(NOW(), INTERVAL 7 DAY))"
    );

    $invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($invoices as $invoice) {
        $to      = $invoice['email'];
        $subject = 'Payment Reminder — Invoice #' . $invoice['id'];
        $body    = "Dear {$invoice['customer_name']},\n\n"
                 . "This is a reminder that invoice #{$invoice['id']} is overdue.\n\n"
                 . "Please log in to view your account:\n"
                 . SITE_URL . APP_PATH . "\n\n"
                 . "Thank you,\nEasy Builders Merchant Pro";

        mail($to, $subject, $body, 'From: noreply@shanemcgee.biz');

        $upd = $pdo->prepare("UPDATE invoices SET last_reminder_at = NOW() WHERE id = ?");
        $upd->execute([$invoice['id']]);
    }

    echo count($invoices) . " reminder(s) sent.\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
