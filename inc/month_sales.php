<?php
// A month's sales for the attendance app on this site (/attendance): the incentive is a share of it.
// Same figure as the Bills sales report: final invoices, before GST and without shipping.
// Used with include from PHP only (no login, no output); the inc/ folder is closed to the web.
declare(strict_types=1);

/** Sales for a month (YYYY-MM) in rupees, or null if the orders database can't be read. */
function looma_orders_month_sales(string $month): ?float
{
    static $pdo = false;
    if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
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
        $st = $pdo->prepare("SELECT IFNULL(SUM(taxable), 0) FROM bills WHERE status = 'final' AND converted_to IS NULL AND bill_date >= ? AND bill_date <= LAST_DAY(?)");
        $st->execute([$month . '-01', $month . '-01']);
        return round((float)$st->fetchColumn(), 2);
    } catch (Throwable $e) {
        error_log('Looma month sales: ' . $e->getMessage());
        return null;
    }
}
