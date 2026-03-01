<?php
/**
 * reports.php — Dashboard statistics and reporting API
 *
 * GET ?store_code=FAL&date_from=...&date_to=...
 *   No action param → legacy dashboard stats (backward compat)
 *
 * Action-based:
 *   ?action=sales_summary&store=FAL&from=YYYY-MM-DD&to=YYYY-MM-DD
 *   ?action=vat_summary&from=YYYY-MM-DD&to=YYYY-MM-DD
 *   ?action=sales_by_product&store=FAL&from=YYYY-MM-DD&to=YYYY-MM-DD
 *   ?action=sales_by_customer&store=FAL&from=YYYY-MM-DD&to=YYYY-MM-DD
 *   ?action=overdue
 *   ?action=email_activity&from=YYYY-MM-DD&to=YYYY-MM-DD
 */
require_once __DIR__ . '/common.php';

setCorsHeaders();
header('Content-Type: application/json; charset=utf-8');

$auth = requireAuth();
checkRateLimit('api');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(['success' => false, 'error' => 'GET required'], 405);
}

$pdo    = getDb();
$action = $_GET['action'] ?? null;

if ($action !== null) {
    switch ($action) {
        case 'sales_summary':    handleSalesSummary($pdo, $auth);    break;
        case 'vat_summary':      handleVatSummary($pdo, $auth);      break;
        case 'sales_by_product': handleSalesByProduct($pdo, $auth);  break;
        case 'sales_by_customer':handleSalesByCustomer($pdo, $auth); break;
        case 'overdue':          handleOverdue($pdo, $auth);         break;
        case 'email_activity':   handleEmailActivity($pdo, $auth);   break;
        default:
            jsonResponse(['success' => false, 'error' => 'Unknown action'], 400);
    }
    exit;
}

// ── Legacy dashboard endpoint (backward compat) ───────────────────────────────
handleDashboard($pdo, $auth);

// ─────────────────────────────────────────────────────────────────────────────
// Helper: resolve store filter respecting role
// ─────────────────────────────────────────────────────────────────────────────
function resolveStore(array $auth, ?string $requested): ?string
{
    if ($auth['role'] === 'counter') {
        return $auth['store_code'] ?? $requested;
    }
    return $requested ?: null;
}

function buildStoreClause(?string $storeCode, string $alias = 'i'): array
{
    if ($storeCode) {
        return ["{$alias}.store_code = ?", [$storeCode]];
    }
    return ['1=1', []];
}

function parseDateRange(): array
{
    $from = $_GET['from'] ?? $_GET['date_from'] ?? null;
    $to   = $_GET['to']   ?? $_GET['date_to']   ?? null;
    return [$from, $to];
}

// ─────────────────────────────────────────────────────────────────────────────
// action=sales_summary
// ─────────────────────────────────────────────────────────────────────────────
function handleSalesSummary(PDO $pdo, array $auth): void
{
    $store = resolveStore($auth, strtoupper(trim($_GET['store'] ?? '')));
    [$from, $to] = parseDateRange();

    [$storeWhere, $storeArgs] = buildStoreClause($store);

    $rangeWhere = '';
    $rangeArgs  = $storeArgs;
    if ($from) { $rangeWhere .= ' AND i.invoice_date >= ?'; $rangeArgs[] = $from; }
    if ($to)   { $rangeWhere .= ' AND i.invoice_date <= ?'; $rangeArgs[] = $to;   }

    $stmt = $pdo->prepare(
        "SELECT
           COUNT(*)                    AS invoice_count,
           COALESCE(SUM(i.total),0)    AS total_sales,
           COALESCE(SUM(i.vat_total),0) AS total_vat,
           COALESCE(SUM(i.amount_paid),0) AS total_paid,
           COALESCE(SUM(i.balance),0)  AS total_outstanding,
           COALESCE(AVG(i.total),0)    AS avg_invoice_value
         FROM invoices i
         WHERE $storeWhere $rangeWhere
           AND i.status NOT IN ('cancelled','draft')"
    );
    $stmt->execute($rangeArgs);
    $row = $stmt->fetch();

    jsonResponse([
        'success'         => true,
        'data'            => [
            'invoice_count'      => (int)$row['invoice_count'],
            'total_sales'        => round((float)$row['total_sales'], 2),
            'total_vat'          => round((float)$row['total_vat'], 2),
            'total_paid'         => round((float)$row['total_paid'], 2),
            'total_outstanding'  => round((float)$row['total_outstanding'], 2),
            'avg_invoice_value'  => round((float)$row['avg_invoice_value'], 2),
        ],
        'store'           => $store,
        'from'            => $from,
        'to'              => $to,
    ]);
}

// ─────────────────────────────────────────────────────────────────────────────
// action=vat_summary
// ─────────────────────────────────────────────────────────────────────────────
function handleVatSummary(PDO $pdo, array $auth): void
{
    $store = resolveStore($auth, strtoupper(trim($_GET['store'] ?? '')));
    [$from, $to] = parseDateRange();

    [$storeWhere, $storeArgs] = buildStoreClause($store);

    $rangeWhere = '';
    $rangeArgs  = $storeArgs;
    if ($from) { $rangeWhere .= ' AND i.invoice_date >= ?'; $rangeArgs[] = $from; }
    if ($to)   { $rangeWhere .= ' AND i.invoice_date <= ?'; $rangeArgs[] = $to;   }

    $stmt = $pdo->prepare(
        "SELECT
           ii.vat_rate,
           COALESCE(SUM(ii.line_total),0)  AS net_total,
           COALESCE(SUM(ii.vat_amount),0)  AS vat_total,
           COALESCE(SUM(ii.line_total + ii.vat_amount),0) AS gross_total,
           COUNT(DISTINCT i.id)            AS invoice_count
         FROM invoice_items ii
         JOIN invoices i ON i.id = ii.invoice_id
         WHERE $storeWhere $rangeWhere
           AND i.status NOT IN ('cancelled','draft')
         GROUP BY ii.vat_rate
         ORDER BY ii.vat_rate ASC"
    );
    $stmt->execute($rangeArgs);
    $rows = $stmt->fetchAll();

    $breakdown = array_map(static function ($r) {
        return [
            'vat_rate'      => (float)$r['vat_rate'],
            'net_total'     => round((float)$r['net_total'], 2),
            'vat_total'     => round((float)$r['vat_total'], 2),
            'gross_total'   => round((float)$r['gross_total'], 2),
            'invoice_count' => (int)$r['invoice_count'],
        ];
    }, $rows);

    jsonResponse(['success' => true, 'data' => $breakdown, 'from' => $from, 'to' => $to]);
}

// ─────────────────────────────────────────────────────────────────────────────
// action=sales_by_product
// ─────────────────────────────────────────────────────────────────────────────
function handleSalesByProduct(PDO $pdo, array $auth): void
{
    $store = resolveStore($auth, strtoupper(trim($_GET['store'] ?? '')));
    [$from, $to] = parseDateRange();

    [$storeWhere, $storeArgs] = buildStoreClause($store);

    $rangeWhere = '';
    $rangeArgs  = $storeArgs;
    if ($from) { $rangeWhere .= ' AND i.invoice_date >= ?'; $rangeArgs[] = $from; }
    if ($to)   { $rangeWhere .= ' AND i.invoice_date <= ?'; $rangeArgs[] = $to;   }

    $limit = max(1, min(100, (int)($_GET['limit'] ?? 25)));

    $stmt = $pdo->prepare(
        "SELECT
           ii.product_code,
           ii.description,
           SUM(ii.quantity)   AS total_qty,
           SUM(ii.line_total) AS total_revenue,
           COUNT(DISTINCT i.id) AS invoice_count
         FROM invoice_items ii
         JOIN invoices i ON i.id = ii.invoice_id
         WHERE $storeWhere $rangeWhere
           AND i.status NOT IN ('cancelled','draft')
         GROUP BY ii.product_code, ii.description
         ORDER BY total_revenue DESC
         LIMIT ?"
    );
    $rangeArgs[] = $limit;
    $stmt->execute($rangeArgs);
    $rows = $stmt->fetchAll();

    $products = array_map(static function ($r) {
        return [
            'product_code'  => $r['product_code'],
            'description'   => $r['description'],
            'total_qty'     => round((float)$r['total_qty'], 3),
            'total_revenue' => round((float)$r['total_revenue'], 2),
            'invoice_count' => (int)$r['invoice_count'],
        ];
    }, $rows);

    jsonResponse(['success' => true, 'data' => $products, 'from' => $from, 'to' => $to]);
}

// ─────────────────────────────────────────────────────────────────────────────
// action=sales_by_customer
// ─────────────────────────────────────────────────────────────────────────────
function handleSalesByCustomer(PDO $pdo, array $auth): void
{
    $store = resolveStore($auth, strtoupper(trim($_GET['store'] ?? '')));
    [$from, $to] = parseDateRange();

    [$storeWhere, $storeArgs] = buildStoreClause($store);

    $rangeWhere = '';
    $rangeArgs  = $storeArgs;
    if ($from) { $rangeWhere .= ' AND i.invoice_date >= ?'; $rangeArgs[] = $from; }
    if ($to)   { $rangeWhere .= ' AND i.invoice_date <= ?'; $rangeArgs[] = $to;   }

    $limit = max(1, min(100, (int)($_GET['limit'] ?? 25)));

    $stmt = $pdo->prepare(
        "SELECT
           c.id AS customer_id,
           COALESCE(c.company_name, c.contact_name, i.inv_town, 'Unknown') AS customer_name,
           c.email_address,
           COUNT(i.id)           AS invoice_count,
           SUM(i.total)          AS total_revenue,
           SUM(i.balance)        AS total_outstanding
         FROM invoices i
         LEFT JOIN customers c ON c.id = i.customer_id
         WHERE $storeWhere $rangeWhere
           AND i.status NOT IN ('cancelled','draft')
         GROUP BY c.id, customer_name, c.email_address
         ORDER BY total_revenue DESC
         LIMIT ?"
    );
    $rangeArgs[] = $limit;
    $stmt->execute($rangeArgs);
    $rows = $stmt->fetchAll();

    $customers = array_map(static function ($r) {
        return [
            'customer_id'       => $r['customer_id'],
            'customer_name'     => $r['customer_name'],
            'email_address'     => $r['email_address'],
            'invoice_count'     => (int)$r['invoice_count'],
            'total_revenue'     => round((float)$r['total_revenue'], 2),
            'total_outstanding' => round((float)$r['total_outstanding'], 2),
        ];
    }, $rows);

    jsonResponse(['success' => true, 'data' => $customers, 'from' => $from, 'to' => $to]);
}

// ─────────────────────────────────────────────────────────────────────────────
// action=overdue
// ─────────────────────────────────────────────────────────────────────────────
function handleOverdue(PDO $pdo, array $auth): void
{
    $store = resolveStore($auth, strtoupper(trim($_GET['store'] ?? '')));
    [$storeWhere, $storeArgs] = buildStoreClause($store);

    $stmt = $pdo->prepare(
        "SELECT
           i.id,
           i.invoice_number,
           i.invoice_date,
           i.due_date,
           i.total,
           i.balance,
           i.status,
           COALESCE(c.company_name, c.contact_name, i.inv_town, 'Unknown') AS customer_name,
           c.email_address AS customer_email,
           DATEDIFF(CURDATE(), i.due_date) AS days_overdue
         FROM invoices i
         LEFT JOIN customers c ON c.id = i.customer_id
         WHERE $storeWhere
           AND i.status != 'paid'
           AND i.status != 'cancelled'
           AND i.due_date < CURDATE()
           AND i.balance > 0
         ORDER BY days_overdue DESC"
    );
    $stmt->execute($storeArgs);
    $rows = $stmt->fetchAll();

    $invoices = array_map(static function ($r) {
        return [
            'id'             => (int)$r['id'],
            'invoice_number' => $r['invoice_number'],
            'invoice_date'   => $r['invoice_date'],
            'due_date'       => $r['due_date'],
            'total'          => round((float)$r['total'], 2),
            'balance'        => round((float)$r['balance'], 2),
            'status'         => $r['status'],
            'customer_name'  => $r['customer_name'],
            'customer_email' => $r['customer_email'],
            'days_overdue'   => (int)$r['days_overdue'],
        ];
    }, $rows);

    jsonResponse(['success' => true, 'data' => $invoices, 'count' => count($invoices)]);
}

// ─────────────────────────────────────────────────────────────────────────────
// action=email_activity
// ─────────────────────────────────────────────────────────────────────────────
function handleEmailActivity(PDO $pdo, array $auth): void
{
    [$from, $to] = parseDateRange();

    $where  = ['1=1'];
    $params = [];
    if ($from) { $where[] = 'el.sent_at >= ?'; $params[] = $from . ' 00:00:00'; }
    if ($to)   { $where[] = 'el.sent_at <= ?'; $params[] = $to   . ' 23:59:59'; }
    $whereSQL = implode(' AND ', $where);

    $stmt = $pdo->prepare(
        "SELECT
           el.id,
           el.invoice_id,
           el.customer_id,
           el.to_email,
           el.subject,
           el.sent_at,
           el.status,
           el.tracking_token,
           COALESCE(c.company_name, c.contact_name, '') AS customer_name
         FROM email_log el
         LEFT JOIN customers c ON c.id = el.customer_id
         WHERE $whereSQL
         ORDER BY el.sent_at DESC
         LIMIT 200"
    );
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    // Summary counts
    $total  = count($rows);
    $opened = count(array_filter($rows, static fn($r) => ($r['status'] ?? '') === 'opened'));

    jsonResponse([
        'success' => true,
        'data'    => $rows,
        'summary' => [
            'total_sent'   => $total,
            'total_opened' => $opened,
            'open_rate'    => $total > 0 ? round($opened / $total * 100, 1) : 0,
        ],
        'from' => $from,
        'to'   => $to,
    ]);
}

// ─────────────────────────────────────────────────────────────────────────────
// Legacy dashboard handler (no action param)
// ─────────────────────────────────────────────────────────────────────────────
function handleDashboard(PDO $pdo, array $auth): void
{
    $requestedStore = strtoupper(trim($_GET['store_code'] ?? ''));
    $dateFrom       = $_GET['date_from'] ?? null;
    $dateTo         = $_GET['date_to']   ?? null;

    $storeCode = resolveStore($auth, $requestedStore);

    $storeWhere = $storeCode ? 'AND i.store_code = ?' : '';
    $storeArgs  = $storeCode ? [$storeCode] : [];

    $today      = date('Y-m-d');
    $monthStart = date('Y-m-01');

    // Today's invoices
    $s = $pdo->prepare("SELECT COUNT(*) AS cnt, COALESCE(SUM(i.total),0) AS tot FROM invoices i WHERE i.invoice_date = ? $storeWhere");
    $s->execute(array_merge([$today], $storeArgs));
    $todayRow = $s->fetch();

    // Outstanding
    $s = $pdo->prepare("SELECT COUNT(*) AS cnt, COALESCE(SUM(i.balance),0) AS tot FROM invoices i WHERE i.status IN ('pending','part_paid','overdue','draft') $storeWhere");
    $s->execute($storeArgs);
    $outstandingRow = $s->fetch();

    // Month total
    $s = $pdo->prepare("SELECT COALESCE(SUM(i.total),0) AS tot FROM invoices i WHERE i.invoice_date >= ? $storeWhere");
    $s->execute(array_merge([$monthStart], $storeArgs));
    $monthRow = $s->fetch();

    // Date-range totals
    $rangeWhere = '';
    $rangeArgs  = $storeArgs;
    if ($dateFrom) { $rangeWhere .= ' AND i.invoice_date >= ?'; $rangeArgs[] = $dateFrom; }
    if ($dateTo)   { $rangeWhere .= ' AND i.invoice_date <= ?'; $rangeArgs[] = $dateTo;   }

    $s = $pdo->prepare("SELECT COALESCE(SUM(i.total),0) AS total_invoiced, COALESCE(SUM(i.amount_paid),0) AS total_paid, COALESCE(SUM(i.balance),0) AS total_outstanding, COUNT(CASE WHEN i.status='overdue' THEN 1 END) AS overdue_count FROM invoices i WHERE 1=1 $storeWhere $rangeWhere");
    $s->execute($rangeArgs);
    $rangeRow = $s->fetch();

    // Recent invoices
    $s = $pdo->prepare("SELECT i.id, i.invoice_number, i.invoice_date, i.total, i.balance, i.status, COALESCE(c.company_name, i.inv_town,'') AS customer_name FROM invoices i LEFT JOIN customers c ON c.id=i.customer_id WHERE 1=1 $storeWhere ORDER BY i.created_at DESC LIMIT 10");
    $s->execute($storeArgs);
    $recentInvoices = $s->fetchAll();

    // Top products this month
    $s = $pdo->prepare("SELECT ii.product_code, ii.description, SUM(ii.quantity) AS total_qty, SUM(ii.line_total) AS total_value FROM invoice_items ii JOIN invoices i ON i.id=ii.invoice_id WHERE i.invoice_date >= ? $storeWhere GROUP BY ii.product_code, ii.description ORDER BY total_qty DESC LIMIT 5");
    $s->execute(array_merge([$monthStart], $storeArgs));
    $topProducts = $s->fetchAll();

    // Invoice list for date range
    $s = $pdo->prepare("SELECT i.id, i.invoice_number, i.invoice_date, i.total, i.balance, i.status, COALESCE(c.company_name,'') AS customer_name FROM invoices i LEFT JOIN customers c ON c.id=i.customer_id WHERE 1=1 $storeWhere $rangeWhere ORDER BY i.invoice_date DESC LIMIT 100");
    $s->execute($rangeArgs);
    $invoiceList = $s->fetchAll();

    jsonResponse([
        'success'           => true,
        'today_invoices'    => (int)$todayRow['cnt'],
        'today_total'       => (float)$todayRow['tot'],
        'outstanding_count' => (int)$outstandingRow['cnt'],
        'outstanding_total' => (float)$outstandingRow['tot'],
        'month_total'       => (float)$monthRow['tot'],
        'total_invoiced'    => (float)$rangeRow['total_invoiced'],
        'total_paid'        => (float)$rangeRow['total_paid'],
        'total_outstanding' => (float)$rangeRow['total_outstanding'],
        'overdue_count'     => (int)$rangeRow['overdue_count'],
        'recent_invoices'   => $recentInvoices,
        'top_products'      => $topProducts,
        'invoices'          => $invoiceList,
    ]);
}
