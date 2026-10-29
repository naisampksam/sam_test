<?php
// A month's sales for the attendance app on this site (/attendance): the incentive is a share of it.
// Same figure as the Bills sales report: final invoices, before GST and without shipping.
// Used with include from PHP only (no login, no output); the inc/ folder is closed to the web.
declare(strict_types=1);

/** Sales for a month (YYYY-MM) in rupees, or null if the orders database can't be read. */
function looma_orders_month_sales(string $month): ?float
{
    return preg_match('/^\d{4}-\d{2}$/', $month) ? looma_orders_sales_between($month . '-01', date('Y-m-t', strtotime($month . '-01'))) : null;
}

/** Sales from one date to another (YYYY-MM-DD, both included) — e.g. the 1st of the month till someone's last day. */
function looma_orders_sales_between(string $from, string $to): ?float
{
    static $pdo = false;
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
        return null;
    }
    try {
        if ($pdo === false) {
            $pdo = null;
            $file = dirname(__DIR__) . '/config.php';
            $c = is_file($file) ? include $file : null;
            if (is_array($c) && !empty($c['db_name'])) {
                $pdo = new PDO('mysql:host=' . ($c['db_host'] ?? 'localhost') . ';dbname=' . $c['db_name'] . ';charset=utf8mb4', (string)($c['db_user'] ?? ''), (string)($c['db_pass'] ?? ''),
                    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            }
        }
        if (!$pdo) {
            return null;
        }
        $st = $pdo->prepare("SELECT IFNULL(SUM(taxable), 0) FROM bills WHERE status = 'final' AND converted_to IS NULL AND bill_date BETWEEN ? AND ?");
        $st->execute([$from, $to]);
        return round((float)$st->fetchColumn(), 2);
    } catch (Throwable $e) {
        error_log('Looma month sales: ' . $e->getMessage());
        return null;
    }
}
