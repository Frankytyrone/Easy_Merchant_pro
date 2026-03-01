<?php
/**
 * reports.php — Dashboard statistics and advanced reporting
 *
 * GET ?store_code=FAL  — returns dashboard stats (default, no action param)
 * GET ?action=sales_summary&store=FAL&from=YYYY-MM-DD&to=YYYY-MM-DD
 * GET ?action=vat_summary&from=YYYY-MM-DD&to=YYYY-MM-DD
 * GET ?action=sales_by_product&store=FAL&from=YYYY-MM-DD&to=YYYY-MM-DD
 * GET ?action=sales_by_customer&store=FAL&from=YYYY-MM-DD&to=YYYY-MM-DD
 * GET ?action=overdue
 * GET ?action=email_activity&from=YYYY-MM-DD&to=YYYY-MM-DD
 */
require_once __DIR__ . '/common.php';

setCorsHeaders();
header('Content-Type: application/json; charset=utf-8');

$auth = requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(['success' => false, 'error' => 'GET required'], 405);
}

$pdo    = getDb();
$action = $_GET['action'] ?? null;

// ── Route to action handlers ──────────────────────────────────────────────────
if ($action !== null) {
    $from  = $_GET['from'] ?? null;
    $to    = $_GET['to']   ?? null;
    $store = !empty($_GET['store']) ? strtoupper(trim($_GET['store'])) : null;

    // Counter users may only see their own store
    if ($auth['role'] === 'counter') {
        $store = $auth['store_code'] ?? $store;
    }

    switch ($action) {

        // ── Sales Summary ─────────────────────────────────────────────────────
        case 'sales_summary':
            $where  = ['status != ?'];
            $params = ['cancelled'];
            if ($store && $store !== 'BOTH') {
                $where[] = 'store_code = ?';
                $params[] = $store;
            }
            if ($from) { $where[] = 'invoice_date >= ?'; $params[] = $from; }
            if ($to)   { $where[] = 'invoice_date <= ?'; $params[] = $to;   }

            $sql  = 'SELECT COUNT(*) AS invoice_count,
                            COALESCE(SUM(total), 0)       AS total_sales,
                            COALESCE(AVG(total), 0)       AS avg_invoice_value,
                            COALESCE(SUM(vat_total), 0)   AS total_vat
                     FROM invoices WHERE ' . implode(' AND ', $where);
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $row  = $stmt->fetch();
            jsonResponse(['success' => true, 'data' => [
                'invoice_count'     => (int)$row['invoice_count'],
                'total_sales'       => (float)$row['total_sales'],
                'avg_invoice_value' => round((float)$row['avg_invoice_value'], 2),
                'total_vat'         => (float)$row['total_vat'],
                'from'              => $from,
                'to'                => $to,
                'store'             => $store,
            ]]);
            break;

        // ── VAT Summary ───────────────────────────────────────────────────────
        case 'vat_summary':
            $where  = ['i.status != ?'];
            $params = ['cancelled'];
            if ($store && $store !== 'BOTH') {
                $where[] = 'i.store_code = ?';
                $params[] = $store;
            }
            if ($from) { $where[] = 'i.invoice_date >= ?'; $params[] = $from; }
            if ($to)   { $where[] = 'i.invoice_date <= ?'; $params[] = $to;   }

            $sql  = 'SELECT ii.vat_rate,
                            COALESCE(SUM(ii.line_total), 0)  AS net_total,
                            COALESCE(SUM(ii.vat_amount), 0)  AS vat_total,
                            COALESCE(SUM(ii.line_total + ii.vat_amount), 0) AS gross_total
                     FROM invoice_items ii
                     JOIN invoices i ON i.id = ii.invoice_id
                     WHERE ' . implode(' AND ', $where) . '
                     GROUP BY ii.vat_rate
                     ORDER BY ii.vat_rate';
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            jsonResponse(['success' => true, 'data' => $stmt->fetchAll()]);
            break;

        // ── Sales by Product ──────────────────────────────────────────────────
        case 'sales_by_product':
            $where  = ['i.status != ?'];
            $params = ['cancelled'];
            if ($store && $store !== 'BOTH') {
                $where[] = 'i.store_code = ?';
                $params[] = $store;
            }
            if ($from) { $where[] = 'i.invoice_date >= ?'; $params[] = $from; }
            if ($to)   { $where[] = 'i.invoice_date <= ?'; $params[] = $to;   }

            $sql  = 'SELECT ii.product_code,
                            COALESCE(p.description, ii.description) AS description,
                            SUM(ii.quantity)   AS total_qty,
                            SUM(ii.line_total) AS total_revenue
                     FROM invoice_items ii
                     JOIN invoices i ON i.id = ii.invoice_id
                     LEFT JOIN products p ON p.product_code = ii.product_code
                     WHERE ' . implode(' AND ', $where) . '
                     GROUP BY ii.product_code, description
                     ORDER BY total_revenue DESC
                     LIMIT 50';
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            jsonResponse(['success' => true, 'data' => $stmt->fetchAll()]);
            break;

        // ── Sales by Customer ─────────────────────────────────────────────────
        case 'sales_by_customer':
            $where  = ['i.status != ?'];
            $params = ['cancelled'];
            if ($store && $store !== 'BOTH') {
                $where[] = 'i.store_code = ?';
                $params[] = $store;
            }
            if ($from) { $where[] = 'i.invoice_date >= ?'; $params[] = $from; }
            if ($to)   { $where[] = 'i.invoice_date <= ?'; $params[] = $to;   }

            $sql  = 'SELECT i.customer_id,
                            COALESCE(c.company_name, i.inv_town, \'(walk-in)\') AS customer_name,
                            COUNT(i.id)           AS invoice_count,
                            SUM(i.total)          AS total_revenue,
                            SUM(i.balance)        AS outstanding
                     FROM invoices i
                     LEFT JOIN customers c ON c.id = i.customer_id
                     WHERE ' . implode(' AND ', $where) . '
                     GROUP BY i.customer_id, customer_name
                     ORDER BY total_revenue DESC
                     LIMIT 50';
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            jsonResponse(['success' => true, 'data' => $stmt->fetchAll()]);
            break;

        // ── Overdue Invoices ──────────────────────────────────────────────────
        case 'overdue':
            $where  = ["i.status NOT IN ('paid','cancelled')", 'i.due_date < CURDATE()'];
            $params = [];
            if ($store && $store !== 'BOTH') {
                $where[] = 'i.store_code = ?';
                $params[] = $store;
            }

            $sql  = 'SELECT i.id, i.invoice_number, i.invoice_date, i.due_date,
                            i.total, i.balance, i.status, i.store_code,
                            COALESCE(c.company_name, i.inv_town, \'(walk-in)\') AS customer_name,
                            c.email_address AS customer_email,
                            DATEDIFF(CURDATE(), i.due_date) AS days_overdue
                     FROM invoices i
                     LEFT JOIN customers c ON c.id = i.customer_id
                     WHERE ' . implode(' AND ', $where) . '
                     ORDER BY days_overdue DESC';
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            jsonResponse(['success' => true, 'data' => $stmt->fetchAll()]);
            break;

        // ── Email Activity ────────────────────────────────────────────────────
        case 'email_activity':
            $where  = ['1=1'];
            $params = [];
            if ($from) { $where[] = 'el.sent_at >= ?'; $params[] = $from . ' 00:00:00'; }
            if ($to)   { $where[] = 'el.sent_at <= ?'; $params[] = $to   . ' 23:59:59'; }

            $stmt = $pdo->prepare(
                'SELECT COUNT(*) AS total_sent,
                        SUM(CASE WHEN el.status = \'opened\' THEN 1 ELSE 0 END) AS total_opened
                 FROM email_log el
                 WHERE ' . implode(' AND ', $where)
            );
            $stmt->execute($params);
            $row = $stmt->fetch();

            $total  = (int)$row['total_sent'];
            $opened = (int)$row['total_opened'];
            jsonResponse(['success' => true, 'data' => [
                'total_sent'   => $total,
                'total_opened' => $opened,
                'open_rate'    => $total > 0 ? round($opened / $total * 100, 1) : 0,
            ]]);
            break;

        default:
            jsonResponse(['success' => false, 'error' => 'Unknown action'], 400);
    }

    // All action handlers exit via jsonResponse — this is unreachable
    exit;
}

// ── Default: Dashboard stats (no action param) ────────────────────────────────
$requestedStore = strtoupper(trim($_GET['store_code'] ?? ''));
$dateFrom       = $_GET['date_from'] ?? null;
$dateTo         = $_GET['date_to']   ?? null;

// Admin and manager can see any store; counter only sees their own
if ($auth['role'] === 'counter') {
    $storeCode = $auth['store_code'] ?? $requestedStore;
} else {
    $storeCode = $requestedStore ?: null;
}

// Build optional WHERE clauses
$storeWhere = $storeCode ? 'AND i.store_code = ?' : '';
$storeArgs  = $storeCode ? [$storeCode] : [];

$today      = date('Y-m-d');
$monthStart = date('Y-m-01');

// ── Today's invoices ──────────────────────────────────────────────────────────
$todayStmt = $pdo->prepare(
    "SELECT COUNT(*) AS cnt, COALESCE(SUM(i.total), 0) AS tot
     FROM invoices i
     WHERE i.invoice_date = ? $storeWhere"
);
$todayStmt->execute(array_merge([$today], $storeArgs));
$todayRow = $todayStmt->fetch();

// ── Outstanding (unpaid / part-paid) ─────────────────────────────────────────
$outstandingStmt = $pdo->prepare(
    "SELECT COUNT(*) AS cnt, COALESCE(SUM(i.balance), 0) AS tot
     FROM invoices i
     WHERE i.status IN ('pending','part_paid','overdue','draft') $storeWhere"
);
$outstandingStmt->execute($storeArgs);
$outstandingRow = $outstandingStmt->fetch();

// ── This month's total ────────────────────────────────────────────────────────
$monthStmt = $pdo->prepare(
    "SELECT COALESCE(SUM(i.total), 0) AS tot
     FROM invoices i
     WHERE i.invoice_date >= ? $storeWhere"
);
$monthStmt->execute(array_merge([$monthStart], $storeArgs));
$monthRow = $monthStmt->fetch();

// ── Date-range totals (for reports screen) ────────────────────────────────────
$rangeWhere = '';
$rangeArgs  = $storeArgs;
if ($dateFrom) { $rangeWhere .= ' AND i.invoice_date >= ?'; $rangeArgs[] = $dateFrom; }
if ($dateTo)   { $rangeWhere .= ' AND i.invoice_date <= ?'; $rangeArgs[] = $dateTo;   }

$rangeStmt = $pdo->prepare(
    "SELECT
       COALESCE(SUM(i.total),       0) AS total_invoiced,
       COALESCE(SUM(i.amount_paid), 0) AS total_paid,
       COALESCE(SUM(i.balance),     0) AS total_outstanding,
       COUNT(CASE WHEN i.status = 'overdue' THEN 1 END) AS overdue_count
     FROM invoices i
     WHERE 1=1 $storeWhere $rangeWhere"
);
$rangeStmt->execute($rangeArgs);
$rangeRow = $rangeStmt->fetch();

// ── Recent invoices (last 10) ─────────────────────────────────────────────────
$recentStmt = $pdo->prepare(
    "SELECT i.id, i.invoice_number, i.invoice_date, i.total, i.balance, i.status,
            COALESCE(c.company_name, i.inv_town, '') AS customer_name
     FROM invoices i
     LEFT JOIN customers c ON c.id = i.customer_id
     WHERE 1=1 $storeWhere
     ORDER BY i.created_at DESC
     LIMIT 10"
);
$recentStmt->execute($storeArgs);
$recentInvoices = $recentStmt->fetchAll();

// ── Top 5 products this month ─────────────────────────────────────────────────
$topProductsArgs = array_merge([$monthStart], $storeArgs);
$topStmt = $pdo->prepare(
    "SELECT ii.product_code, ii.description,
            SUM(ii.quantity) AS total_qty,
            SUM(ii.line_total) AS total_value
     FROM invoice_items ii
     JOIN invoices i ON i.id = ii.invoice_id
     WHERE i.invoice_date >= ? $storeWhere
     GROUP BY ii.product_code, ii.description
     ORDER BY total_qty DESC
     LIMIT 5"
);
$topStmt->execute($topProductsArgs);
$topProducts = $topStmt->fetchAll();

// ── Date-range invoice list (for reports screen) ──────────────────────────────
$listStmt = $pdo->prepare(
    "SELECT i.id, i.invoice_number, i.invoice_date, i.total, i.balance, i.status,
            COALESCE(c.company_name, '') AS customer_name
     FROM invoices i
     LEFT JOIN customers c ON c.id = i.customer_id
     WHERE 1=1 $storeWhere $rangeWhere
     ORDER BY i.invoice_date DESC
     LIMIT 100"
);
$listStmt->execute($rangeArgs);
$invoiceList = $listStmt->fetchAll();

jsonResponse([
    'success'           => true,
    // Dashboard stats
    'today_invoices'    => (int)$todayRow['cnt'],
    'today_total'       => (float)$todayRow['tot'],
    'outstanding_count' => (int)$outstandingRow['cnt'],
    'outstanding_total' => (float)$outstandingRow['tot'],
    'month_total'       => (float)$monthRow['tot'],
    // Reports screen compatibility
    'total_invoiced'    => (float)$rangeRow['total_invoiced'],
    'total_paid'        => (float)$rangeRow['total_paid'],
    'total_outstanding' => (float)$rangeRow['total_outstanding'],
    'overdue_count'     => (int)$rangeRow['overdue_count'],
    // Lists
    'recent_invoices'   => $recentInvoices,
    'top_products'      => $topProducts,
    'invoices'          => $invoiceList,
]);
