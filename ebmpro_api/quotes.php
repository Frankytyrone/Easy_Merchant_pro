<?php
require_once __DIR__ . '/common.php';

setCorsHeaders();
header('Content-Type: application/json; charset=utf-8');

$auth   = requireAuth();
checkRateLimit('api');
$method = $_SERVER['REQUEST_METHOD'];
$pdo    = getDb();

// ── Helper: resolve store code from store_id ──────────────────────────────────
function quoteStoreCode(PDO $pdo, int $storeId): string
{
    $stmt = $pdo->prepare('SELECT code FROM stores WHERE id = ? LIMIT 1');
    $stmt->execute([$storeId]);
    $row = $stmt->fetch();
    return $row ? $row['code'] : (string)$storeId;
}

// ── Helper: generate next quote number ────────────────────────────────────────
function nextQuoteNumber(PDO $pdo, string $storeCode): string
{
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            'SELECT next_quote_num FROM invoice_sequences WHERE store_code = ? FOR UPDATE'
        );
        $stmt->execute([$storeCode]);
        $seq = $stmt->fetch();

        if (!$seq) {
            $pdo->prepare(
                'INSERT INTO invoice_sequences (store_code, next_invoice_num, next_quote_num)
                 VALUES (?,1001,1001)'
            )->execute([$storeCode]);
            $current = 1001;
        } else {
            $current = (int)$seq['next_quote_num'];
        }

        $pdo->prepare('UPDATE invoice_sequences SET next_quote_num = ? WHERE store_code = ?')
            ->execute([$current + 1, $storeCode]);

        $pdo->commit();

        $storeRow = $pdo->prepare('SELECT quote_prefix FROM stores WHERE code = ? LIMIT 1');
        $storeRow->execute([$storeCode]);
        $pr     = $storeRow->fetch();
        $prefix = $pr && !empty($pr['quote_prefix'])
            ? $pr['quote_prefix']
            : strtoupper(substr($storeCode, 0, 3)) . '-Q';

        return $prefix . '-' . date('Y') . '-' . str_pad($current - 1000, 3, '0', STR_PAD_LEFT);
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

// ── Helper: insert quote line items and update quote totals ───────────────────
function insertQuoteItems(PDO $pdo, int $quoteId, array $items): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO quote_items
         (quote_id, product_id, description, quantity, unit_price, vat_rate, line_total)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $subtotal  = 0.0;
    $vatAmount = 0.0;
    foreach ($items as $item) {
        $qty       = round((float)($item['qty'] ?? $item['quantity'] ?? 1), 3);
        $price     = round((float)($item['unit_price'] ?? 0), 2);
        $vatRate   = round((float)($item['vat_rate'] ?? 23), 2);
        $lineNet   = round($qty * $price, 2);
        $lineVat   = round($lineNet * $vatRate / 100, 2);
        $lineTotal = round($lineNet + $lineVat, 2);

        $stmt->execute([
            $quoteId,
            !empty($item['product_id']) ? (int)$item['product_id'] : null,
            $item['description'] ?? '',
            $qty,
            $price,
            $vatRate,
            $lineTotal,
        ]);
        $subtotal  += $lineNet;
        $vatAmount += $lineVat;
    }
    $total = round($subtotal + $vatAmount, 2);
    $pdo->prepare(
        'UPDATE quotes SET subtotal = ?, vat_amount = ?, total = ? WHERE id = ?'
    )->execute([round($subtotal, 2), round($vatAmount, 2), $total, $quoteId]);
}

// ── Helper: fetch full quote with line items ──────────────────────────────────
function fetchQuoteFull(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare(
        'SELECT q.*, c.company_name AS customer_name, c.email_address AS customer_email
         FROM quotes q
         LEFT JOIN customers c ON c.id = q.customer_id
         WHERE q.id = ?'
    );
    $stmt->execute([$id]);
    $quote = $stmt->fetch();
    if (!$quote) {
        return null;
    }
    $stmt = $pdo->prepare('SELECT * FROM quote_items WHERE quote_id = ? ORDER BY id ASC');
    $stmt->execute([$id]);
    $quote['items'] = $stmt->fetchAll();
    return $quote;
}

// ── Helper: send quote by email ────────────────────────────────────────────────
function sendQuoteEmail(PDO $pdo, array $quote): array
{
    $toEmail = trim($quote['customer_email'] ?? '');
    if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        return ['success' => false, 'error' => 'Customer has no valid email address'];
    }

    $allSettings = getSettings($pdo);
    $smtpHost    = $allSettings['smtp_host']      ?? 'localhost';
    $smtpPort    = (int)($allSettings['smtp_port']  ?? 587);
    $smtpUser    = $allSettings['smtp_user']      ?? '';
    $smtpPass    = $allSettings['smtp_pass']      ?? '';
    $fromName    = $allSettings['smtp_from_name'] ?? 'Easy Builders Merchant Pro';
    $fromEmail   = $allSettings['smtp_from_email'] ?? ('noreply@' . ($_SERVER['HTTP_HOST'] ?? 'localhost'));
    $shopName    = $allSettings['shop_name'] ?? 'Easy Builders Merchant';

    $subject = "Quote {$quote['quote_number']} from {$shopName}";
    $message = "<p>Dear {$quote['customer_name']},</p>"
        . "<p>Please find your quote <strong>{$quote['quote_number']}</strong> attached.</p>"
        . "<p>Total: &euro;" . number_format((float)$quote['total'], 2) . "</p>"
        . "<p>Valid until: " . ($quote['expiry_date'] ?? 'N/A') . "</p>"
        . "<p>Thank you for your business.</p>"
        . "<p>{$shopName}</p>";

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
        $mail->Host       = $smtpHost;
        $mail->Port       = $smtpPort;
        $mail->SMTPAuth   = !empty($smtpUser);
        $mail->Username   = $smtpUser;
        $mail->Password   = $smtpPass;
        $mail->SMTPSecure = 'tls';
        $mail->setFrom($fromEmail, $fromName);
        $mail->addAddress($toEmail);
        $mail->Subject  = $subject;
        $mail->isHTML(true);
        $mail->Body     = $message;
        $mail->AltBody  = strip_tags($message);
        $mail->CharSet  = 'UTF-8';
        $mail->send();

        // Log to email_log
        $pdo->prepare(
            'INSERT INTO email_log (customer_id, to_email, subject, type, sent_at, status)
             VALUES (?,?,?,?,NOW(),?)'
        )->execute([$quote['customer_id'], $toEmail, $subject, 'invoice', 'sent']);

        return ['success' => true];
    } catch (PHPMailer\PHPMailer\Exception $e) {
        error_log('quotes.php mailer error: ' . $e->getMessage());
        return ['success' => false, 'error' => 'Email sending failed'];
    }
}

try {
    // ════════════════════════════════════════════════════════════════════════
    // GET
    // ════════════════════════════════════════════════════════════════════════
    if ($method === 'GET') {
        // Single quote
        if (!empty($_GET['id'])) {
            $quote = fetchQuoteFull($pdo, (int)$_GET['id']);
            if (!$quote) {
                jsonResponse(['success' => false, 'error' => 'Quote not found'], 404);
            }
            jsonResponse(['success' => true, 'data' => $quote]);
        }

        // List quotes
        $where  = ['1=1'];
        $params = [];

        if (!empty($_GET['store_id']))    { $where[] = 'q.store_id = ?';    $params[] = (int)$_GET['store_id']; }
        if (!empty($_GET['status']))      { $where[] = 'q.status = ?';      $params[] = $_GET['status']; }
        if (!empty($_GET['customer_id'])) { $where[] = 'q.customer_id = ?'; $params[] = (int)$_GET['customer_id']; }

        $sql = 'SELECT q.*, c.company_name AS customer_name
                FROM quotes q
                LEFT JOIN customers c ON c.id = q.customer_id
                WHERE ' . implode(' AND ', $where) . '
                ORDER BY q.quote_date DESC, q.id DESC
                LIMIT 500';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        jsonResponse(['success' => true, 'data' => $stmt->fetchAll()]);
    }

    // ════════════════════════════════════════════════════════════════════════
    // POST
    // ════════════════════════════════════════════════════════════════════════
    if ($method === 'POST') {
        $action = $_GET['action'] ?? '';
        $id     = (int)($_GET['id'] ?? 0);

        // ── Send quote by email ───────────────────────────────────────────
        if ($action === 'send') {
            if (!$id) {
                jsonResponse(['success' => false, 'error' => 'id parameter required'], 422);
            }
            $quote = fetchQuoteFull($pdo, $id);
            if (!$quote) {
                jsonResponse(['success' => false, 'error' => 'Quote not found'], 404);
            }
            $result = sendQuoteEmail($pdo, $quote);
            if (!$result['success']) {
                jsonResponse($result, 500);
            }
            $pdo->prepare(
                "UPDATE quotes SET status = 'sent', updated_at = NOW() WHERE id = ?"
            )->execute([$id]);
            auditLog($pdo, (int)$auth['user_id'], $auth['username'], (string)$quote['store_id'],
                'send', 'quote', $id, ['status' => $quote['status']], ['status' => 'sent']);
            jsonResponse(['success' => true, 'message' => 'Quote sent']);
        }

        // ── Convert quote to invoice ──────────────────────────────────────
        if ($action === 'convert') {
            if (!$id) {
                jsonResponse(['success' => false, 'error' => 'id parameter required'], 422);
            }
            $quote = fetchQuoteFull($pdo, $id);
            if (!$quote) {
                jsonResponse(['success' => false, 'error' => 'Quote not found'], 404);
            }

            $sc        = quoteStoreCode($pdo, (int)$quote['store_id']);
            $invNumber = nextQuoteNumber($pdo, $sc); // reuse number generation for invoice prefix
            // Actually use invoice sequence for invoices
            $seqStmt = $pdo->prepare(
                'SELECT next_invoice_num FROM invoice_sequences WHERE store_code = ? FOR UPDATE'
            );
            $pdo->beginTransaction();
            $seqStmt->execute([$sc]);
            $seqRow = $seqStmt->fetch();
            if (!$seqRow) {
                $pdo->prepare(
                    'INSERT INTO invoice_sequences (store_code, next_invoice_num, next_quote_num)
                     VALUES (?,1001,1001)'
                )->execute([$sc]);
                $seqCurrent = 1001;
            } else {
                $seqCurrent = (int)$seqRow['next_invoice_num'];
            }
            $pdo->prepare(
                'UPDATE invoice_sequences SET next_invoice_num = ? WHERE store_code = ?'
            )->execute([$seqCurrent + 1, $sc]);
            $pdo->commit();

            $storeRow = $pdo->prepare('SELECT invoice_prefix FROM stores WHERE code = ? LIMIT 1');
            $storeRow->execute([$sc]);
            $pr         = $storeRow->fetch();
            $invPrefix  = $pr && !empty($pr['invoice_prefix']) ? $pr['invoice_prefix'] : strtoupper(substr($sc, 0, 3));
            $invNumber  = $invPrefix . '-' . str_pad($seqCurrent, 4, '0', STR_PAD_LEFT);

            $insStmt = $pdo->prepare(
                'INSERT INTO invoices
                 (invoice_number, store_code, invoice_type, status, customer_id,
                  invoice_date, due_date, notes, created_by, created_at, updated_at)
                 VALUES (?,?,?,?,?,?,?,?,?,NOW(),NOW())'
            );
            $insStmt->execute([
                $invNumber,
                $sc,
                'invoice',
                'draft',
                (int)$quote['customer_id'],
                $quote['quote_date'],
                null,
                $quote['notes'],
                (int)$auth['user_id'],
            ]);
            $newInvoiceId = (int)$pdo->lastInsertId();

            // Copy items from quote_items to invoice_items
            $itemsStmt = $pdo->prepare(
                'INSERT INTO invoice_items
                 (invoice_id, line_order, description, quantity, unit_price, discount_pct, vat_rate, vat_amount, line_total)
                 VALUES (?, ?, ?, ?, ?, 0, ?, ?, ?)'
            );
            foreach ($quote['items'] as $i => $item) {
                $qty       = round((float)$item['quantity'], 3);
                $price     = round((float)$item['unit_price'], 2);
                $vatRate   = round((float)$item['vat_rate'], 2);
                $lineNet   = round($qty * $price, 2);
                $lineVat   = round($lineNet * $vatRate / 100, 2);
                $lineTotal = round($lineNet + $lineVat, 2);
                $itemsStmt->execute([
                    $newInvoiceId, $i,
                    $item['description'],
                    $qty, $price, $vatRate, $lineVat, $lineTotal,
                ]);
            }

            // Recalc invoice totals
            $totStmt = $pdo->prepare(
                'SELECT SUM(line_total - vat_amount) AS subtotal, SUM(vat_amount) AS vat_total
                 FROM invoice_items WHERE invoice_id = ?'
            );
            $totStmt->execute([$newInvoiceId]);
            $totRow   = $totStmt->fetch();
            $subtotal = round((float)($totRow['subtotal'] ?? 0), 2);
            $vatTotal = round((float)($totRow['vat_total'] ?? 0), 2);
            $total    = round($subtotal + $vatTotal, 2);
            $pdo->prepare(
                'UPDATE invoices
                 SET subtotal = ?, vat_total = ?, total = ?, amount_paid = 0, balance = ?
                 WHERE id = ?'
            )->execute([$subtotal, $vatTotal, $total, $total, $newInvoiceId]);

            // Mark quote as accepted
            $pdo->prepare(
                "UPDATE quotes SET status = 'accepted', updated_at = NOW() WHERE id = ?"
            )->execute([$id]);

            auditLog($pdo, (int)$auth['user_id'], $auth['username'], $sc,
                'convert', 'quote', $id,
                ['quote_number' => $quote['quote_number']],
                ['invoice_number' => $invNumber, 'invoice_id' => $newInvoiceId]);

            jsonResponse(['success' => true, 'invoice_id' => $newInvoiceId, 'invoice_number' => $invNumber]);
        }

        // ── Create quote ──────────────────────────────────────────────────
        $body = json_decode(file_get_contents('php://input'), true) ?? [];

        foreach (['customer_id', 'store_id', 'quote_date', 'items'] as $f) {
            if (empty($body[$f])) {
                jsonResponse(['success' => false, 'error' => "Field '$f' is required"], 422);
            }
        }
        if (!is_array($body['items']) || count($body['items']) === 0) {
            jsonResponse(['success' => false, 'error' => 'At least one line item is required'], 422);
        }

        $sc          = quoteStoreCode($pdo, (int)$body['store_id']);
        $quoteNumber = nextQuoteNumber($pdo, $sc);

        $stmt = $pdo->prepare(
            'INSERT INTO quotes
             (quote_number, customer_id, store_id, status, quote_date, expiry_date, notes, created_by, created_at, updated_at)
             VALUES (?,?,?,?,?,?,?,?,NOW(),NOW())'
        );
        $stmt->execute([
            $quoteNumber,
            (int)$body['customer_id'],
            (int)$body['store_id'],
            'draft',
            $body['quote_date'],
            $body['expiry_date'] ?? null,
            $body['notes']       ?? null,
            (int)$auth['user_id'],
        ]);
        $newId = (int)$pdo->lastInsertId();

        insertQuoteItems($pdo, $newId, $body['items']);

        auditLog($pdo, (int)$auth['user_id'], $auth['username'], $sc,
            'create', 'quote', $newId, null, ['quote_number' => $quoteNumber]);

        jsonResponse(['success' => true, 'data' => fetchQuoteFull($pdo, $newId)], 201);
    }

    // ════════════════════════════════════════════════════════════════════════
    // PUT — update quote (draft only)
    // ════════════════════════════════════════════════════════════════════════
    if ($method === 'PUT') {
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) {
            jsonResponse(['success' => false, 'error' => 'id parameter required'], 422);
        }

        $stmt = $pdo->prepare('SELECT * FROM quotes WHERE id = ?');
        $stmt->execute([$id]);
        $old = $stmt->fetch();
        if (!$old) {
            jsonResponse(['success' => false, 'error' => 'Quote not found'], 404);
        }
        if ($old['status'] !== 'draft') {
            jsonResponse(['success' => false, 'error' => 'Only draft quotes can be edited'], 422);
        }

        $body = json_decode(file_get_contents('php://input'), true) ?? [];

        $pdo->prepare(
            'UPDATE quotes
             SET customer_id  = COALESCE(?, customer_id),
                 quote_date   = COALESCE(?, quote_date),
                 expiry_date  = COALESCE(?, expiry_date),
                 status       = COALESCE(?, status),
                 notes        = ?,
                 updated_at   = NOW()
             WHERE id = ?'
        )->execute([
            !empty($body['customer_id']) ? (int)$body['customer_id'] : null,
            $body['quote_date']   ?? null,
            $body['expiry_date']  ?? null,
            $body['status']       ?? null,
            $body['notes']        ?? $old['notes'],
            $id,
        ]);

        if (!empty($body['items']) && is_array($body['items'])) {
            $pdo->prepare('DELETE FROM quote_items WHERE quote_id = ?')->execute([$id]);
            insertQuoteItems($pdo, $id, $body['items']);
        }

        $sc = quoteStoreCode($pdo, (int)$old['store_id']);
        auditLog($pdo, (int)$auth['user_id'], $auth['username'], $sc,
            'update', 'quote', $id, $old, $body);

        jsonResponse(['success' => true, 'data' => fetchQuoteFull($pdo, $id)]);
    }

    // ════════════════════════════════════════════════════════════════════════
    // DELETE — delete quote (draft only)
    // ════════════════════════════════════════════════════════════════════════
    if ($method === 'DELETE') {
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) {
            jsonResponse(['success' => false, 'error' => 'id parameter required'], 422);
        }

        $stmt = $pdo->prepare('SELECT * FROM quotes WHERE id = ?');
        $stmt->execute([$id]);
        $old = $stmt->fetch();
        if (!$old) {
            jsonResponse(['success' => false, 'error' => 'Quote not found'], 404);
        }
        if ($old['status'] !== 'draft') {
            jsonResponse(['success' => false, 'error' => 'Only draft quotes can be deleted'], 422);
        }

        $pdo->prepare('DELETE FROM quotes WHERE id = ?')->execute([$id]);

        $sc = quoteStoreCode($pdo, (int)$old['store_id']);
        auditLog($pdo, (int)$auth['user_id'], $auth['username'], $sc,
            'delete', 'quote', $id, $old, null);

        jsonResponse(['success' => true, 'message' => 'Quote deleted']);
    }

    jsonResponse(['success' => false, 'error' => 'Method not allowed'], 405);

} catch (PDOException $e) {
    error_log('quotes.php DB error: ' . $e->getMessage());
    jsonResponse(['success' => false, 'error' => 'Service unavailable'], 503);
} catch (Throwable $e) {
    error_log('quotes.php error: ' . $e->getMessage());
    jsonResponse(['success' => false, 'error' => 'Internal server error'], 500);
}
