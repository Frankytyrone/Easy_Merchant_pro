<?php
/**
 * reports.php — Dashboard statistics and reporting
 *
 * GET ?store_code=FAL  — returns dashboard stats:
 *   today_invoices, today_total, outstanding_count, outstanding_total,
 *   month_total, total_invoiced, total_paid, total_outstanding,
 *   overdue_count, recent_invoices, top_products, invoices
 */
require_once __DIR__ . '/common.php';

setCorsHeaders();
header('Content-Type: application/json; charset=utf-8');

$auth = requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(['success' => false, 'error' => 'GET required'], 405);
}

$pdo = getDb();

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
