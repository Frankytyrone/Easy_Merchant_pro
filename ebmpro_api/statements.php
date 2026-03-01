<?php
require_once __DIR__ . '/common.php';

setCorsHeaders();
header('Content-Type: application/json; charset=utf-8');

$auth   = requireAuth();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    // ── GET — return customer statement with running balance ──────────────────
    $customerId = (int)($_GET['customer_id'] ?? 0);
    if (!$customerId) {
        jsonResponse(['success' => false, 'error' => 'customer_id parameter required'], 422);
    }

    $dateFrom = $_GET['date_from'] ?? null;
    $dateTo   = $_GET['date_to']   ?? null;

    try {
        $pdo = getDb();

        $stmt = $pdo->prepare('SELECT * FROM customers WHERE id = ?');
        $stmt->execute([$customerId]);
        $customer = $stmt->fetch();
        if (!$customer) {
            jsonResponse(['success' => false, 'error' => 'Customer not found'], 404);
        }

        // Opening balance: sum of balances before date_from
        $openingBalance = 0.0;
        if ($dateFrom) {
            $stmt = $pdo->prepare(
                "SELECT COALESCE(SUM(balance), 0)
                 FROM invoices
                 WHERE customer_id = ?
                   AND status != 'cancelled'
                   AND invoice_date < ?"
            );
            $stmt->execute([$customerId, $dateFrom]);
            $openingBalance = round((float)$stmt->fetchColumn(), 2);
        }

        // Invoices within period
        $where  = ["customer_id = ?", "status != 'cancelled'"];
        $params = [$customerId];
        if ($dateFrom) { $where[] = 'invoice_date >= ?'; $params[] = $dateFrom; }
        if ($dateTo)   { $where[] = 'invoice_date <= ?'; $params[] = $dateTo; }

        $stmt = $pdo->prepare(
            'SELECT id, invoice_number, invoice_type, invoice_date, due_date,
                    total, amount_paid, balance, status
             FROM invoices
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY invoice_date ASC, id ASC'
        );
        $stmt->execute($params);
        $invoices = $stmt->fetchAll();

        // Attach payments and running balance
        $runningBalance = $openingBalance;
        foreach ($invoices as &$inv) {
            $ps = $pdo->prepare(
                'SELECT payment_date, amount, method, reference
                 FROM payments WHERE invoice_id = ? ORDER BY payment_date ASC'
            );
            $ps->execute([$inv['id']]);
            $inv['payments']        = $ps->fetchAll();
            $runningBalance        += (float)$inv['balance'];
            $inv['running_balance'] = round($runningBalance, 2);
        }
        unset($inv);

        $closingBalance = round($openingBalance + array_sum(array_column($invoices, 'balance')), 2);

        jsonResponse([
            'success' => true,
            'data'    => [
                'customer'        => $customer,
                'date_from'       => $dateFrom,
                'date_to'         => $dateTo,
                'opening_balance' => $openingBalance,
                'invoices'        => $invoices,
                'closing_balance' => $closingBalance,
                'generated_at'    => date('Y-m-d H:i:s'),
            ],
        ]);

    } catch (PDOException $e) {
        error_log('statements.php GET DB error: ' . $e->getMessage());
        jsonResponse(['success' => false, 'error' => 'Service unavailable'], 503);
    } catch (Throwable $e) {
        error_log('statements.php GET error: ' . $e->getMessage());
        jsonResponse(['success' => false, 'error' => 'Internal server error'], 500);
    }
}

if ($method === 'POST') {
    // ── POST — generate and optionally email a customer statement ────────────
    $body       = json_decode(file_get_contents('php://input'), true) ?? [];
    $customerId = (int)($body['customer_id'] ?? 0);
    $dateFrom   = $body['from'] ?? $body['date_from'] ?? null;
    $dateTo     = $body['to']   ?? $body['date_to']   ?? null;
    $sendEmail  = !empty($body['send_email']);

    if (!$customerId) {
        jsonResponse(['success' => false, 'error' => 'customer_id required'], 422);
    }

    try {
        $pdo = getDb();

        $stmt = $pdo->prepare('SELECT * FROM customers WHERE id = ?');
        $stmt->execute([$customerId]);
        $customer = $stmt->fetch();
        if (!$customer) {
            jsonResponse(['success' => false, 'error' => 'Customer not found'], 404);
        }

        // Collect invoices for the period
        $where  = ["customer_id = ?", "status != 'cancelled'"];
        $params = [$customerId];
        if ($dateFrom) { $where[] = 'invoice_date >= ?'; $params[] = $dateFrom; }
        if ($dateTo)   { $where[] = 'invoice_date <= ?'; $params[] = $dateTo; }

        $stmt = $pdo->prepare(
            'SELECT id, invoice_number, invoice_date, due_date, total, amount_paid, balance, status
             FROM invoices WHERE ' . implode(' AND ', $where) . ' ORDER BY invoice_date ASC, id ASC'
        );
        $stmt->execute($params);
        $invoices = $stmt->fetchAll();

        // Calculate opening balance (sum of outstanding balances before date_from)
        $openingBalance = 0.0;
        if ($dateFrom) {
            $obStmt = $pdo->prepare(
                "SELECT COALESCE(SUM(balance), 0) FROM invoices
                 WHERE customer_id = ? AND status != 'cancelled' AND invoice_date < ?"
            );
            $obStmt->execute([$customerId, $dateFrom]);
            $openingBalance = round((float)$obStmt->fetchColumn(), 2);
        }

        // Calculate running balance
        $runningBalance = $openingBalance;
        foreach ($invoices as &$inv) {
            $runningBalance        += (float)$inv['balance'];
            $inv['running_balance'] = round($runningBalance, 2);
        }
        unset($inv);

        if (!$sendEmail) {
            jsonResponse(['success' => true, 'data' => compact('customer', 'invoices', 'dateFrom', 'dateTo')]);
        }

        if (empty($customer['email_address'])) {
            jsonResponse(['success' => false, 'error' => 'Customer has no email address'], 422);
        }

        $customerName = $customer['company_name'] ?: ($customer['contact_name'] ?: 'Customer');
        $period       = ($dateFrom ?: 'Start') . ' to ' . ($dateTo ?: date('Y-m-d'));
        $subject      = "Account Statement — {$customerName} — {$period}";

        // Build HTML email body
        $rows = '';
        foreach ($invoices as $inv) {
            $status = htmlspecialchars(strtoupper(str_replace('_', ' ', $inv['status'])), ENT_QUOTES, 'UTF-8');
            $rows  .= '<tr>'
                . '<td>' . htmlspecialchars($inv['invoice_date'], ENT_QUOTES, 'UTF-8') . '</td>'
                . '<td>' . htmlspecialchars($inv['invoice_number'], ENT_QUOTES, 'UTF-8') . '</td>'
                . '<td>' . $status . '</td>'
                . '<td style="text-align:right">€' . number_format((float)$inv['total'], 2) . '</td>'
                . '<td style="text-align:right">€' . number_format((float)$inv['amount_paid'], 2) . '</td>'
                . '<td style="text-align:right">€' . number_format((float)$inv['balance'], 2) . '</td>'
                . '<td style="text-align:right"><strong>€' . number_format((float)$inv['running_balance'], 2) . '</strong></td>'
                . '</tr>';
        }

        $addrParts = array_filter([
            $customer['address_1'] ?? '',
            $customer['address_2'] ?? '',
            $customer['inv_town']  ?? '',
            $customer['inv_region'] ?? '',
        ]);

        $htmlMessage = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body '
            . 'style="font-family:Arial,sans-serif;color:#222;max-width:700px;margin:0 auto">'
            . '<h2 style="color:#1a3a2a">Account Statement</h2>'
            . '<p><strong>Customer:</strong> ' . htmlspecialchars($customerName, ENT_QUOTES, 'UTF-8') . '</p>'
            . '<p><strong>Address:</strong> ' . htmlspecialchars(implode(', ', $addrParts), ENT_QUOTES, 'UTF-8') . '</p>'
            . '<p><strong>Statement Period:</strong> ' . htmlspecialchars($period, ENT_QUOTES, 'UTF-8') . '</p>'
            . '<p><strong>Generated:</strong> ' . date('d M Y H:i') . '</p><hr>'
            . '<table border="1" cellpadding="6" cellspacing="0" '
            . 'style="border-collapse:collapse;width:100%;font-size:.9rem">'
            . '<thead style="background:#1a3a2a;color:#fff">'
            . '<tr><th>Date</th><th>Invoice #</th><th>Status</th><th>Total</th>'
            . '<th>Paid</th><th>Balance</th><th>Running Balance</th></tr>'
            . '</thead><tbody>' . $rows . '</tbody></table>'
            . '<p style="margin-top:1rem">If you have any queries, please contact us.</p>'
            . '</body></html>';

        // Send via PHPMailer
        $mailerDir = __DIR__ . '/PHPMailer/';
        if (!file_exists($mailerDir . 'PHPMailer.php')) {
            jsonResponse(['success' => false, 'error' => 'PHPMailer library not found'], 500);
        }
        require_once $mailerDir . 'Exception.php';
        require_once $mailerDir . 'PHPMailer.php';
        require_once $mailerDir . 'SMTP.php';

        $allSettings = getSettings($pdo);
        $mail = new PHPMailer\PHPMailer\PHPMailer(true);

        try {
            $mail->isSMTP();
            $mail->Host       = $allSettings['smtp_host'] ?? 'localhost';
            $mail->Port       = (int)($allSettings['smtp_port'] ?? 587);
            $mail->SMTPAuth   = !empty($allSettings['smtp_user']);
            $mail->Username   = $allSettings['smtp_user'] ?? '';
            $mail->Password   = $allSettings['smtp_pass'] ?? '';
            $mail->SMTPSecure = 'tls';
            $fromEmail = $allSettings['smtp_from_email'] ?? ('noreply@' . ($_SERVER['HTTP_HOST'] ?? 'localhost'));
            $fromName  = $allSettings['smtp_from_name']  ?? 'Easy Builders Merchant Pro';
            $mail->setFrom($fromEmail, $fromName);
            $mail->addAddress($customer['email_address'], $customerName);
            $mail->Subject = $subject;
            $mail->isHTML(true);
            $mail->Body    = $htmlMessage;
            $mail->AltBody = strip_tags($htmlMessage);
            $mail->CharSet = 'UTF-8';
            $mail->send();
        } catch (PHPMailer\PHPMailer\Exception $e) {
            error_log('statements.php mailer error: ' . $e->getMessage());
            jsonResponse(['success' => false, 'error' => 'Email failed: ' . $e->getMessage()], 500);
        }

        // Log to email_log
        try {
            $pdo->prepare(
                'INSERT INTO email_log (customer_id, to_email, subject, type, sent_at, status)
                 VALUES (?, ?, ?, ?, NOW(), ?)'
            )->execute([$customerId, $customer['email_address'], $subject, 'statement', 'sent']);
        } catch (Throwable $logErr) {
            error_log('statements.php log error: ' . $logErr->getMessage());
        }

        jsonResponse(['success' => true, 'message' => 'Statement sent to ' . $customer['email_address']]);

    } catch (PDOException $e) {
        error_log('statements.php POST DB error: ' . $e->getMessage());
        jsonResponse(['success' => false, 'error' => 'Service unavailable'], 503);
    } catch (Throwable $e) {
        error_log('statements.php POST error: ' . $e->getMessage());
        jsonResponse(['success' => false, 'error' => 'Internal server error'], 500);
    }
}

jsonResponse(['success' => false, 'error' => 'Method not allowed'], 405);
