<?php
require_once __DIR__ . '/common.php';

setCorsHeaders();
header('Content-Type: application/json; charset=utf-8');

$auth   = requireAuth();
$method = $_SERVER['REQUEST_METHOD'];
$pdo    = getDb();

function nextQuoteNumber(PDO $pdo, int $storeId): string
{
    // Get store code
    $s = $pdo->prepare('SELECT code, quote_prefix FROM stores WHERE id = ? LIMIT 1');
    $s->execute([$storeId]);
    $store = $s->fetch();
    $storeCode = $store ? $store['code'] : (string)$storeId;
    $prefix    = $store ? ($store['quote_prefix'] ?? strtoupper(substr($storeCode, 0, 3))) : strtoupper(substr($storeCode, 0, 3));

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT next_quote_num FROM invoice_sequences WHERE store_code = ? FOR UPDATE');
        $stmt->execute([$storeCode]);
        $seq = $stmt->fetch();
        if (!$seq) {
            $pdo->prepare('INSERT INTO invoice_sequences (store_code, next_invoice_num, next_quote_num) VALUES (?,1001,1001)')->execute([$storeCode]);
            $current = 1001;
        } else {
            $current = (int)$seq['next_quote_num'];
        }
        $pdo->prepare('UPDATE invoice_sequences SET next_quote_num = ? WHERE store_code = ?')->execute([$current + 1, $storeCode]);
        $pdo->commit();
        return $prefix . '-Q-' . str_pad($current, 4, '0', STR_PAD_LEFT);
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function calcQuoteTotals(array $items): array
{
    $subtotal = 0;
    $vatAmt   = 0;
    foreach ($items as $item) {
        $qty      = (float)($item['qty'] ?? $item['quantity'] ?? 1);
        $price    = (float)($item['unit_price'] ?? 0);
        $vatRate  = (float)($item['vat_rate'] ?? 23);
        $lineNet  = round($qty * $price, 2);
        $lineVat  = round($lineNet * $vatRate / 100, 2);
        $subtotal += $lineNet;
        $vatAmt   += $lineVat;
    }
    return [
        'subtotal'   => round($subtotal, 2),
        'vat_amount' => round($vatAmt, 2),
        'total'      => round($subtotal + $vatAmt, 2),
    ];
}

function insertQuoteItems(PDO $pdo, int $quoteId, array $items): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO quote_items (quote_id, product_id, description, quantity, unit_price, vat_rate, line_total)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    foreach ($items as $item) {
        $qty      = round((float)($item['qty'] ?? $item['quantity'] ?? 1), 3);
        $price    = round((float)($item['unit_price'] ?? 0), 2);
        $vatRate  = round((float)($item['vat_rate'] ?? 23), 2);
        $lineNet  = round($qty * $price, 2);
        $lineVat  = round($lineNet * $vatRate / 100, 2);
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
    }
}

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
    if (!$quote) return null;
    $stmt = $pdo->prepare('SELECT * FROM quote_items WHERE quote_id = ? ORDER BY id ASC');
    $stmt->execute([$id]);
    $quote['items'] = $stmt->fetchAll();
    return $quote;
}

try {
    // ── POST actions ─────────────────────────────────────────────────────────
    if ($method === 'POST' && isset($_GET['action'])) {
        $id = (int)($_GET['id'] ?? 0);

        // Send quote via email
        if ($_GET['action'] === 'send') {
            if (!$id) jsonResponse(['success' => false, 'error' => 'id required'], 422);
            $quote = fetchQuoteFull($pdo, $id);
            if (!$quote) jsonResponse(['success' => false, 'error' => 'Quote not found'], 404);

            $toEmail = $quote['customer_email'] ?? '';
            if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
                jsonResponse(['success' => false, 'error' => 'Customer has no valid email address'], 422);
            }

            $allSettings = getSettings($pdo);
            $mailerDir = __DIR__ . '/PHPMailer/';
            if (!file_exists($mailerDir . 'PHPMailer.php')) {
                jsonResponse(['success' => false, 'error' => 'PHPMailer not found'], 500);
            }
            require_once $mailerDir . 'Exception.php';
            require_once $mailerDir . 'PHPMailer.php';
            require_once $mailerDir . 'SMTP.php';

            $token   = bin2hex(random_bytes(16));
            $subject = 'Quote ' . $quote['quote_number'] . ' from Easy Builders Merchant';
            $body    = '<p>Dear ' . htmlspecialchars($quote['customer_name'] ?? '') . ',</p>'
                     . '<p>Please find attached your quote <strong>' . htmlspecialchars($quote['quote_number']) . '</strong>.</p>'
                     . '<p>Total: €' . number_format((float)$quote['total'], 2) . '</p>'
                     . ($quote['expiry_date'] ? '<p>Valid until: ' . htmlspecialchars($quote['expiry_date']) . '</p>' : '')
                     . '<p>Thank you for your business.</p>'
                     . '<img src="' . (defined('TRACK_URL') ? TRACK_URL : '') . '?t=' . $token . '" width="1" height="1" alt="">';

            try {
                $mail = new PHPMailer\PHPMailer\PHPMailer(true);
                $mail->isSMTP();
                $mail->Host       = $allSettings['smtp_host']      ?? 'localhost';
                $mail->Port       = (int)($allSettings['smtp_port'] ?? 587);
                $mail->SMTPAuth   = !empty($allSettings['smtp_user']);
                $mail->Username   = $allSettings['smtp_user']      ?? '';
                $mail->Password   = $allSettings['smtp_pass']      ?? '';
                $mail->SMTPSecure = 'tls';
                $fromEmail = $allSettings['smtp_user']      ?? '';
                $fromName  = $allSettings['smtp_from_name'] ?? 'Easy Builders Merchant Pro';
                $mail->setFrom($fromEmail, $fromName);
                $mail->addAddress($toEmail, $quote['customer_name'] ?? '');
                $mail->isHTML(true);
                $mail->Subject = $subject;
                $mail->Body    = $body;
                $mail->AltBody = 'Quote ' . $quote['quote_number'] . ' — Total: €' . number_format((float)$quote['total'], 2);
                $mail->send();

                $pdo->prepare("UPDATE quotes SET status = 'sent', updated_at = NOW() WHERE id = ?")
                    ->execute([$id]);
                $pdo->prepare('INSERT INTO email_log (customer_id, to_email, subject, type, tracking_token, sent_at, status) VALUES (?,?,?,?,?,NOW(),\'sent\')')
                    ->execute([$quote['customer_id'], $toEmail, $subject, 'invoice', $token]);

                jsonResponse(['success' => true, 'message' => 'Quote sent to ' . $toEmail]);
            } catch (Throwable $e) {
                error_log('quotes send error: ' . $e->getMessage());
                jsonResponse(['success' => false, 'error' => 'Failed to send email: ' . $e->getMessage()], 500);
            }
        }

        // Convert quote to invoice
        if ($_GET['action'] === 'convert') {
            if (!$id) jsonResponse(['success' => false, 'error' => 'id required'], 422);
            $quote = fetchQuoteFull($pdo, $id);
            if (!$quote) jsonResponse(['success' => false, 'error' => 'Quote not found'], 404);
            if (!in_array($quote['status'], ['sent', 'accepted'], true)) {
                jsonResponse(['success' => false, 'error' => 'Only sent or accepted quotes can be converted to invoices'], 422);
            }

            // Get store code from store_id
            $storeRow = $pdo->prepare('SELECT code FROM stores WHERE id = ? LIMIT 1');
            $storeRow->execute([$quote['store_id']]);
            $sr = $storeRow->fetch();
            $storeCode = $sr ? $sr['code'] : (string)$quote['store_id'];

            // Generate invoice number using same sequence logic
            $seqStmt = $pdo->prepare('SELECT next_invoice_num FROM invoice_sequences WHERE store_code = ? FOR UPDATE');
            $pdo->beginTransaction();
            try {
                $seqStmt->execute([$storeCode]);
                $seq = $seqStmt->fetch();
                if (!$seq) {
                    $pdo->prepare('INSERT INTO invoice_sequences (store_code, next_invoice_num, next_quote_num) VALUES (?,1001,1001)')->execute([$storeCode]);
                    $invNum = 1001;
                } else {
                    $invNum = (int)$seq['next_invoice_num'];
                }
                $pdo->prepare('UPDATE invoice_sequences SET next_invoice_num = ? WHERE store_code = ?')->execute([$invNum + 1, $storeCode]);

                // Get invoice prefix
                $prStmt = $pdo->prepare('SELECT invoice_prefix FROM stores WHERE code = ? LIMIT 1');
                $prStmt->execute([$storeCode]);
                $pr = $prStmt->fetch();
                $invPrefix = $pr ? ($pr['invoice_prefix'] ?? strtoupper(substr($storeCode, 0, 3))) : strtoupper(substr($storeCode, 0, 3));
                $invNumber = $invPrefix . '-' . str_pad($invNum, 4, '0', STR_PAD_LEFT);

                // Create invoice
                $insertInv = $pdo->prepare(
                    'INSERT INTO invoices (invoice_number, store_code, invoice_type, status, customer_id,
                     invoice_date, notes, created_by, created_at, updated_at)
                     VALUES (?,?,?,?,?,?,?,?,NOW(),NOW())'
                );
                $insertInv->execute([
                    $invNumber,
                    $storeCode,
                    'invoice',
                    'draft',
                    $quote['customer_id'],
                    date('Y-m-d'),
                    $quote['notes'],
                    (int)$auth['user_id'],
                ]);
                $newInvId = (int)$pdo->lastInsertId();

                // Copy quote items to invoice_items
                $insertItem = $pdo->prepare(
                    'INSERT INTO invoice_items (invoice_id, line_order, description, quantity, unit_price, vat_rate, vat_amount, line_total)
                     VALUES (?,?,?,?,?,?,?,?)'
                );
                foreach ($quote['items'] as $i => $item) {
                    $qty      = (float)$item['quantity'];
                    $price    = (float)$item['unit_price'];
                    $vatRate  = (float)$item['vat_rate'];
                    $lineNet  = round($qty * $price, 2);
                    $vatAmt   = round($lineNet * $vatRate / 100, 2);
                    $lineTotal = round($lineNet + $vatAmt, 2);
                    $insertItem->execute([$newInvId, $i, $item['description'], $qty, $price, $vatRate, $vatAmt, $lineTotal]);
                }

                // Recalc invoice totals
                $totStmt = $pdo->prepare('SELECT SUM(line_total - vat_amount) AS subtotal, SUM(vat_amount) AS vat_total FROM invoice_items WHERE invoice_id = ?');
                $totStmt->execute([$newInvId]);
                $tots = $totStmt->fetch();
                $sub  = round((float)($tots['subtotal']  ?? 0), 2);
                $vat  = round((float)($tots['vat_total'] ?? 0), 2);
                $tot  = round($sub + $vat, 2);
                $pdo->prepare('UPDATE invoices SET subtotal=?, vat_total=?, total=?, balance=? WHERE id=?')
                    ->execute([$sub, $vat, $tot, $tot, $newInvId]);

                // Mark quote as accepted
                $pdo->prepare("UPDATE quotes SET status = 'accepted', updated_at = NOW() WHERE id = ?")
                    ->execute([$id]);

                $pdo->commit();

                auditLog($pdo, (int)$auth['user_id'], $auth['username'], $storeCode,
                    'convert_quote', 'quote', $id, ['quote_number' => $quote['quote_number']],
                    ['invoice_number' => $invNumber, 'invoice_id' => $newInvId]);

                jsonResponse(['success' => true, 'invoice_id' => $newInvId, 'invoice_number' => $invNumber]);
            } catch (Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }
        }
    }

    // ── GET ──────────────────────────────────────────────────────────────────
    if ($method === 'GET') {
        if (!empty($_GET['id'])) {
            $quote = fetchQuoteFull($pdo, (int)$_GET['id']);
            if (!$quote) jsonResponse(['success' => false, 'error' => 'Quote not found'], 404);
            jsonResponse(['success' => true, 'data' => $quote]);
        }

        $where  = ['1=1'];
        $params = [];
        if (!empty($_GET['store_id']))     { $where[] = 'q.store_id = ?';     $params[] = (int)$_GET['store_id']; }
        if (!empty($_GET['status']))       { $where[] = 'q.status = ?';       $params[] = $_GET['status']; }
        if (!empty($_GET['customer_id'])) { $where[] = 'q.customer_id = ?';  $params[] = (int)$_GET['customer_id']; }

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

    // ── POST (create) ────────────────────────────────────────────────────────
    if ($method === 'POST' && !isset($_GET['action'])) {
        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        foreach (['customer_id', 'store_id', 'quote_date', 'items'] as $f) {
            if (empty($body[$f])) jsonResponse(['success' => false, 'error' => "Field '$f' is required"], 422);
        }
        if (!is_array($body['items']) || count($body['items']) === 0) {
            jsonResponse(['success' => false, 'error' => 'At least one line item is required'], 422);
        }

        $storeId     = (int)$body['store_id'];
        $quoteNumber = nextQuoteNumber($pdo, $storeId);
        $tots        = calcQuoteTotals($body['items']);

        $stmt = $pdo->prepare(
            'INSERT INTO quotes (quote_number, customer_id, store_id, status, quote_date, expiry_date,
             subtotal, vat_amount, total, notes, created_by, created_at, updated_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())'
        );
        $stmt->execute([
            $quoteNumber,
            (int)$body['customer_id'],
            $storeId,
            'draft',
            $body['quote_date'],
            $body['expiry_date'] ?? null,
            $tots['subtotal'],
            $tots['vat_amount'],
            $tots['total'],
            $body['notes'] ?? null,
            (int)$auth['user_id'],
        ]);
        $newId = (int)$pdo->lastInsertId();
        insertQuoteItems($pdo, $newId, $body['items']);

        // Get store code for audit
        $sc = $pdo->prepare('SELECT code FROM stores WHERE id = ? LIMIT 1');
        $sc->execute([$storeId]);
        $scRow = $sc->fetch();
        $storeCode = $scRow ? $scRow['code'] : (string)$storeId;
        auditLog($pdo, (int)$auth['user_id'], $auth['username'], $storeCode,
            'create', 'quote', $newId, null, ['quote_number' => $quoteNumber]);

        jsonResponse(['success' => true, 'data' => fetchQuoteFull($pdo, $newId)], 201);
    }

    // ── PUT (update) ─────────────────────────────────────────────────────────
    if ($method === 'PUT') {
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) jsonResponse(['success' => false, 'error' => 'id parameter required'], 422);

        $stmt = $pdo->prepare('SELECT * FROM quotes WHERE id = ?');
        $stmt->execute([$id]);
        $old = $stmt->fetch();
        if (!$old) jsonResponse(['success' => false, 'error' => 'Quote not found'], 404);
        if ($old['status'] !== 'draft') jsonResponse(['success' => false, 'error' => 'Only draft quotes can be edited'], 422);

        $body = json_decode(file_get_contents('php://input'), true) ?? [];

        if (!empty($body['items']) && is_array($body['items'])) {
            $tots = calcQuoteTotals($body['items']);
        } else {
            $tots = ['subtotal' => $old['subtotal'], 'vat_amount' => $old['vat_amount'], 'total' => $old['total']];
        }

        $pdo->prepare(
            'UPDATE quotes SET customer_id = COALESCE(?,customer_id),
             quote_date = COALESCE(?,quote_date), expiry_date = COALESCE(?,expiry_date),
             notes = ?, subtotal = ?, vat_amount = ?, total = ?, updated_at = NOW()
             WHERE id = ?'
        )->execute([
            !empty($body['customer_id']) ? (int)$body['customer_id'] : null,
            $body['quote_date']  ?? null,
            $body['expiry_date'] ?? null,
            $body['notes']       ?? $old['notes'],
            $tots['subtotal'],
            $tots['vat_amount'],
            $tots['total'],
            $id,
        ]);

        if (!empty($body['items']) && is_array($body['items'])) {
            $pdo->prepare('DELETE FROM quote_items WHERE quote_id = ?')->execute([$id]);
            insertQuoteItems($pdo, $id, $body['items']);
        }

        jsonResponse(['success' => true, 'data' => fetchQuoteFull($pdo, $id)]);
    }

    // ── DELETE ───────────────────────────────────────────────────────────────
    if ($method === 'DELETE') {
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) jsonResponse(['success' => false, 'error' => 'id parameter required'], 422);
        $stmt = $pdo->prepare('SELECT * FROM quotes WHERE id = ?');
        $stmt->execute([$id]);
        $old = $stmt->fetch();
        if (!$old) jsonResponse(['success' => false, 'error' => 'Quote not found'], 404);
        if ($old['status'] !== 'draft') jsonResponse(['success' => false, 'error' => 'Only draft quotes can be deleted'], 422);

        $pdo->prepare('DELETE FROM quotes WHERE id = ?')->execute([$id]);
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
