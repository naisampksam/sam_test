<?php
// Database tables. Safe to run more than once (CREATE TABLE IF NOT EXISTS).
declare(strict_types=1);

function schema_sql(): array
{
    return [
        "CREATE TABLE IF NOT EXISTS users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(60) NOT NULL UNIQUE,
            name VARCHAR(120) NOT NULL DEFAULT '',
            password_hash VARCHAR(255) NOT NULL,
            role VARCHAR(10) NOT NULL DEFAULT 'staff',
            active TINYINT(1) NOT NULL DEFAULT 1,
            perms TEXT NULL,
            caps TEXT NULL,
            session_version INT NOT NULL DEFAULT 0,
            last_login DATETIME NULL,
            created_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS settings (
            k VARCHAR(60) PRIMARY KEY,
            v TEXT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS gsm_options (
            id INT AUTO_INCREMENT PRIMARY KEY,
            label VARCHAR(40) NOT NULL,
            sort INT NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS products (
            id INT AUTO_INCREMENT PRIMARY KEY,
            gsm_id INT NOT NULL,
            name VARCHAR(150) NOT NULL,
            sizes VARCHAR(255) NOT NULL DEFAULT 'S,M,L,XL,XXL',
            sort INT NOT NULL DEFAULT 0,
            active TINYINT(1) NOT NULL DEFAULT 1,
            INDEX (gsm_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS product_colors (
            id INT AUTO_INCREMENT PRIMARY KEY,
            product_id INT NOT NULL,
            name VARCHAR(80) NOT NULL,
            hex VARCHAR(7) NOT NULL DEFAULT '',
            sort INT NOT NULL DEFAULT 0,
            active TINYINT(1) NOT NULL DEFAULT 1,
            INDEX (product_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS couriers (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(80) NOT NULL,
            sort INT NOT NULL DEFAULT 0,
            active TINYINT(1) NOT NULL DEFAULT 1
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS custom_fields (
            id INT AUTO_INCREMENT PRIMARY KEY,
            label VARCHAR(80) NOT NULL,
            type VARCHAR(20) NOT NULL DEFAULT 'text',
            options TEXT NULL,
            scope VARCHAR(10) NOT NULL DEFAULT 'item',
            sort INT NOT NULL DEFAULT 0,
            active TINYINT(1) NOT NULL DEFAULT 1
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS orders (
            id INT AUTO_INCREMENT PRIMARY KEY,
            customer_id VARCHAR(80) NOT NULL DEFAULT '',
            customer_name VARCHAR(150) NOT NULL DEFAULT '',
            cust_seq INT NULL,
            ship_name VARCHAR(150) NOT NULL DEFAULT '',
            ship_phone VARCHAR(40) NOT NULL DEFAULT '',
            ship_address TEXT NULL,
            ship_pincode VARCHAR(12) NOT NULL DEFAULT '',
            slip_brand VARCHAR(100) NOT NULL DEFAULT '',
            print_hold TINYINT(1) NOT NULL DEFAULT 0,
            order_ref VARCHAR(80) NOT NULL DEFAULT '',
            ret_phone VARCHAR(40) NOT NULL DEFAULT '',
            ret_address TEXT NULL,
            ret_pincode VARCHAR(12) NOT NULL DEFAULT '',
            notes TEXT NULL,
            due_date DATE NULL,
            printed TINYINT(1) NOT NULL DEFAULT 0,
            printed_at DATETIME NULL,
            printed_by INT NULL,
            packed TINYINT(1) NOT NULL DEFAULT 0,
            packed_at DATETIME NULL,
            packed_by INT NULL,
            shipped TINYINT(1) NOT NULL DEFAULT 0,
            shipped_at DATETIME NULL,
            shipped_by INT NULL,
            courier VARCHAR(80) NOT NULL DEFAULT '',
            tracking_no VARCHAR(120) NOT NULL DEFAULT '',
            extra TEXT NULL,
            images_cleared_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            created_by INT NULL,
            updated_at DATETIME NULL,
            updated_by INT NULL,
            deleted_at DATETIME NULL,
            INDEX (customer_id),
            INDEX (created_at),
            INDEX (printed_at),
            INDEX (packed_at),
            INDEX (shipped_at),
            INDEX (due_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS order_items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            order_id INT NOT NULL,
            sort INT NOT NULL DEFAULT 0,
            gsm VARCHAR(40) NOT NULL DEFAULT '',
            product VARCHAR(150) NOT NULL DEFAULT '',
            color VARCHAR(80) NOT NULL DEFAULT '',
            size VARCHAR(40) NOT NULL DEFAULT '',
            sub_order_id VARCHAR(80) NOT NULL DEFAULT '',
            item_type VARCHAR(12) NOT NULL DEFAULT 'print',
            quantity INT NOT NULL DEFAULT 1,
            length_m DECIMAL(8,2) NULL,
            plain TINYINT(1) NOT NULL DEFAULT 0,
            design_id INT NULL,
            design_name VARCHAR(200) NOT NULL DEFAULT '',
            front_print TEXT NULL,
            back_print TEXT NULL,
            chest_print TEXT NULL,
            neck_label_on TINYINT(1) NOT NULL DEFAULT 0,
            neck_label TEXT NULL,
            custom_print TEXT NULL,
            front_size VARCHAR(40) NOT NULL DEFAULT '',
            back_size VARCHAR(40) NOT NULL DEFAULT '',
            chest_size VARCHAR(40) NOT NULL DEFAULT '',
            custom_size VARCHAR(40) NOT NULL DEFAULT '',
            extra TEXT NULL,
            printed TINYINT(1) NOT NULL DEFAULT 0,
            printed_at DATETIME NULL,
            printed_by INT NULL,
            created_at DATETIME NOT NULL,
            INDEX (order_id),
            INDEX (printed_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS order_images (
            id INT AUTO_INCREMENT PRIMARY KEY,
            order_id INT NOT NULL,
            item_id INT NULL,
            filename VARCHAR(100) NOT NULL,
            original_name VARCHAR(255) NOT NULL DEFAULT '',
            uploaded_by INT NULL,
            created_at DATETIME NOT NULL,
            INDEX (order_id),
            INDEX (item_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS designs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(150) NOT NULL,
            code VARCHAR(60) NOT NULL DEFAULT '',
            gsm VARCHAR(40) NOT NULL DEFAULT '',
            product VARCHAR(150) NOT NULL DEFAULT '',
            color VARCHAR(80) NOT NULL DEFAULT '',
            size VARCHAR(40) NOT NULL DEFAULT '',
            front_print TEXT NULL,
            back_print TEXT NULL,
            chest_print TEXT NULL,
            neck_label_on TINYINT(1) NOT NULL DEFAULT 0,
            neck_label TEXT NULL,
            custom_print TEXT NULL,
            front_size VARCHAR(40) NOT NULL DEFAULT '',
            back_size VARCHAR(40) NOT NULL DEFAULT '',
            chest_size VARCHAR(40) NOT NULL DEFAULT '',
            custom_size VARCHAR(40) NOT NULL DEFAULT '',
            extra TEXT NULL,
            active TINYINT(1) NOT NULL DEFAULT 1,
            created_by INT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NULL,
            INDEX (name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS design_images (
            id INT AUTO_INCREMENT PRIMARY KEY,
            design_id INT NOT NULL,
            filename VARCHAR(100) NOT NULL,
            original_name VARCHAR(255) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL,
            INDEX (design_id),
            INDEX (filename)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS customers (
            id INT AUTO_INCREMENT PRIMARY KEY,
            code VARCHAR(80) NOT NULL,
            name VARCHAR(150) NOT NULL DEFAULT '',
            ship_name VARCHAR(150) NOT NULL DEFAULT '',
            phone VARCHAR(40) NOT NULL DEFAULT '',
            address TEXT NULL,
            pincode VARCHAR(12) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL,
            updated_at DATETIME NULL,
            UNIQUE KEY (code),
            INDEX (phone),
            INDEX (name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS order_log (
            id INT AUTO_INCREMENT PRIMARY KEY,
            order_id INT NOT NULL,
            item_id INT NULL,
            user_id INT NULL,
            field VARCHAR(60) NOT NULL,
            old_value TEXT NULL,
            new_value TEXT NULL,
            created_at DATETIME NOT NULL,
            INDEX (order_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS login_attempts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            ip VARCHAR(45) NOT NULL,
            created_at DATETIME NOT NULL,
            INDEX (ip, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ];
}

function column_exists(PDO $pdo, string $table, string $col): bool
{
    $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $st->execute([$table, $col]);
    return (int)$st->fetchColumn() > 0;
}

/** Upgrades databases created by the first version (one product per order) to orders with items. */
function migrate(PDO $pdo): void
{
    if (!column_exists($pdo, 'custom_fields', 'scope')) {
        $pdo->exec("ALTER TABLE custom_fields ADD scope VARCHAR(10) NOT NULL DEFAULT 'item' AFTER options");
    }
    if (!column_exists($pdo, 'order_images', 'item_id')) {
        $pdo->exec('ALTER TABLE order_images ADD item_id INT NULL AFTER order_id, ADD INDEX (item_id)');
    }
    if (!column_exists($pdo, 'order_log', 'item_id')) {
        $pdo->exec('ALTER TABLE order_log ADD item_id INT NULL AFTER order_id');
    }
    if (!column_exists($pdo, 'orders', 'ship_address')) {
        $pdo->exec("ALTER TABLE orders ADD ship_name VARCHAR(150) NOT NULL DEFAULT '' AFTER customer_id,
            ADD ship_phone VARCHAR(40) NOT NULL DEFAULT '' AFTER ship_name, ADD ship_address TEXT NULL AFTER ship_phone,
            ADD ship_pincode VARCHAR(12) NOT NULL DEFAULT '' AFTER ship_address");
    }
    if (!column_exists($pdo, 'order_items', 'plain')) {
        $pdo->exec('ALTER TABLE order_items ADD plain TINYINT(1) NOT NULL DEFAULT 0 AFTER quantity');
    }
    if (!column_exists($pdo, 'order_items', 'neck_label_on')) {
        $pdo->exec('ALTER TABLE order_items ADD neck_label_on TINYINT(1) NOT NULL DEFAULT 0 AFTER chest_print');
        $pdo->exec("UPDATE order_items SET neck_label_on = 1 WHERE neck_label IS NOT NULL AND neck_label <> ''");
    }
    if (!column_exists($pdo, 'order_items', 'design_id')) {
        $pdo->exec('ALTER TABLE order_items ADD design_id INT NULL AFTER plain');
    }
    // Fill the customer book from existing orders (latest address wins).
    if ((int)$pdo->query('SELECT COUNT(*) FROM customers')->fetchColumn() === 0 && column_exists($pdo, 'orders', 'ship_address')) {
        $pdo->exec("INSERT INTO customers (code, name, phone, address, pincode, created_at)
                    SELECT o.customer_id, o.ship_name, o.ship_phone, o.ship_address, o.ship_pincode, o.created_at FROM orders o
                    WHERE o.customer_id <> '' AND o.deleted_at IS NULL
                      AND o.id = (SELECT MAX(o2.id) FROM orders o2 WHERE o2.customer_id = o.customer_id AND o2.deleted_at IS NULL)");
    }
    if (!column_exists($pdo, 'orders', 'slip_brand')) {
        $pdo->exec("ALTER TABLE orders ADD slip_brand VARCHAR(100) NOT NULL DEFAULT '' AFTER ship_pincode");
    }
    foreach ([
        ['order_items', 'sub_order_id', "VARCHAR(80) NOT NULL DEFAULT '' AFTER sort"],
        ['order_items', 'item_type', "VARCHAR(12) NOT NULL DEFAULT 'print' AFTER sub_order_id"],
        ['order_items', 'length_m', 'DECIMAL(8,2) NULL AFTER quantity'],
        ['orders', 'order_ref', "VARCHAR(80) NOT NULL DEFAULT '' AFTER slip_brand"],
        ['orders', 'ret_phone', "VARCHAR(40) NOT NULL DEFAULT '' AFTER order_ref"],
        ['orders', 'ret_address', 'TEXT NULL AFTER ret_phone'],
        ['orders', 'ret_pincode', "VARCHAR(12) NOT NULL DEFAULT '' AFTER ret_address"],
        ['designs', 'size', "VARCHAR(40) NOT NULL DEFAULT '' AFTER color"],
        ['orders', 'print_hold', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER slip_brand'],
        ['order_items', 'front_size', "VARCHAR(40) NOT NULL DEFAULT '' AFTER custom_print"],
        ['order_items', 'back_size', "VARCHAR(40) NOT NULL DEFAULT '' AFTER front_size"],
        ['order_items', 'chest_size', "VARCHAR(40) NOT NULL DEFAULT '' AFTER back_size"],
        ['order_items', 'custom_size', "VARCHAR(40) NOT NULL DEFAULT '' AFTER chest_size"],
        ['designs', 'front_size', "VARCHAR(40) NOT NULL DEFAULT '' AFTER custom_print"],
        ['designs', 'back_size', "VARCHAR(40) NOT NULL DEFAULT '' AFTER front_size"],
        ['designs', 'chest_size', "VARCHAR(40) NOT NULL DEFAULT '' AFTER back_size"],
        ['designs', 'custom_size', "VARCHAR(40) NOT NULL DEFAULT '' AFTER chest_size"],
        ['orders', 'customer_name', "VARCHAR(150) NOT NULL DEFAULT '' AFTER customer_id"],
        ['order_items', 'design_name', "VARCHAR(200) NOT NULL DEFAULT '' AFTER design_id"],
        ['orders', 'cust_seq', 'INT NULL AFTER customer_name'],
        ['customers', 'ship_name', "VARCHAR(150) NOT NULL DEFAULT '' AFTER name"],
    ] as [$t, $c, $def]) {
        if (!column_exists($pdo, $t, $c)) {
            $pdo->exec("ALTER TABLE $t ADD $c $def");
            if ($c === 'item_type') {
                $pdo->exec("UPDATE order_items SET item_type = 'plain' WHERE plain = 1");
            }
            if ($t === 'order_items' && $c === 'front_size') {
                // Print size now sits next to each print place; retire the old single "Print size" field (data is kept).
                $pdo->exec("UPDATE custom_fields SET active = 0 WHERE label = 'Print size' AND type = 'select'");
            }
            if ($t === 'customers' && $c === 'ship_name') {
                // The customer book's name was the ship-to name. Move it there: "Customer name" is now the customer's own
                // (brand) name, printed under "Return to" on labels, and starts empty until someone enters it.
                $pdo->exec("UPDATE customers SET ship_name = name, name = ''");
            }
            if ($c === 'design_name') {
                // Name the saved design on items that used one before this column existed.
                $pdo->exec("UPDATE order_items it JOIN designs d ON d.id = it.design_id
                            SET it.design_name = IF(d.code <> '', CONCAT(d.name, ' · ', d.code), d.name)");
            }
            if ($c === 'cust_seq') {
                // Number existing orders per customer per day: C101-1, C101-2 … in the order they were created.
                $seen = [];
                $st = $pdo->prepare('UPDATE orders SET cust_seq = ? WHERE id = ?');
                foreach ($pdo->query("SELECT id, customer_id, DATE(created_at) d FROM orders WHERE customer_id <> '' AND deleted_at IS NULL ORDER BY id") as $r) {
                    $k = mb_strtolower($r['customer_id']) . '|' . $r['d'];
                    $seen[$k] = ($seen[$k] ?? 0) + 1;
                    $st->execute([$seen[$k], $r['id']]);
                }
            }
        }
    }
    if (!column_exists($pdo, 'orders', 'images_cleared_at')) {
        $pdo->exec('ALTER TABLE orders ADD images_cleared_at DATETIME NULL AFTER extra');
    }
    if (column_exists($pdo, 'orders', 'product')) {
        $pdo->beginTransaction();
        foreach ($pdo->query('SELECT * FROM orders WHERE id NOT IN (SELECT order_id FROM order_items)')->fetchAll(PDO::FETCH_ASSOC) as $o) {
            $pdo->prepare('INSERT INTO order_items (order_id, gsm, product, color, size, quantity, front_print, back_print, chest_print, neck_label,
                           custom_print, extra, printed, printed_at, printed_by, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
                ->execute([$o['id'], $o['gsm'], $o['product'], $o['color'], $o['size'], $o['quantity'], $o['front_print'], $o['back_print'],
                    $o['chest_print'], $o['neck_label'], $o['custom_print'], $o['extra'], $o['printed'], $o['printed_at'], $o['printed_by'], $o['created_at']]);
            $pdo->prepare('UPDATE order_images SET item_id = ? WHERE order_id = ?')->execute([$pdo->lastInsertId(), $o['id']]);
        }
        $pdo->commit();
        // ALTER commits implicitly in MySQL, so it runs after the data copy is committed.
        foreach (['gsm', 'product', 'color', 'size', 'quantity', 'front_print', 'back_print', 'chest_print', 'neck_label', 'custom_print'] as $c) {
            $pdo->exec("ALTER TABLE orders DROP COLUMN $c");
        }
    }
}

/** Starter data. Replace the sample catalog from Admin -> Catalog (bulk import supported). */
function seed_data(PDO $pdo): void
{
    $settings = [
        'company_name' => 'Looma Apparels', 'dispatch_days' => '2', 'skip_sundays' => '1',
        'slip_brand' => 'Looma Apparels',
        'print_sizes' => 'A2 (16×22), A3 (11×16), A4 (8×11), Logo (2.5×2.5), Custom',
        'slip_ret_phone' => '8089963691',
        'slip_ret_address' => 'Watani Complex, Kizhisseri, Malappuram',
        'slip_ret_pincode' => '673641',
        'wa_confirm' => "Hi {name}, thank you for your order with {brand}! 🙏\nOrder {order}: {items}.\nWe will dispatch it by {dispatch}.",
        'wa_shipped' => "Hi {name}, your {brand} order {order} has been shipped via {courier} 🚚\nTracking number: {tracking}\nThank you for shopping with us!",
    ];
    $st = $pdo->prepare('INSERT IGNORE INTO settings (k, v) VALUES (?, ?)');
    foreach ($settings as $k => $v) {
        $st->execute([$k, $v]);
    }

    if ((int)$pdo->query('SELECT COUNT(*) FROM couriers')->fetchColumn() === 0) {
        $st = $pdo->prepare('INSERT INTO couriers (name, sort) VALUES (?, ?)');
        foreach (['Delhivery', 'India Post', 'Ekart', 'Nova Travels', 'Gokulam', 'Professional',
                     'DTDC', 'Blue Dart', 'Ecom Express', 'Speed & Safe', 'Hand delivery / Pickup'] as $i => $c) {
            $st->execute([$c, $i]);
        }
    }

    if ((int)$pdo->query('SELECT COUNT(*) FROM custom_fields')->fetchColumn() === 0) {
        $st = $pdo->prepare("INSERT INTO custom_fields (label, type, options, scope, sort) VALUES (?, ?, ?, 'item', ?)");
        $st->execute(['Print method', 'select', 'DTF, Puff Print, HD / High Density, Embroidery, Screen Print', 1]);
    }

    if ((int)$pdo->query('SELECT COUNT(*) FROM gsm_options')->fetchColumn() === 0) {
        require_once __DIR__ . '/catalog_import.php';
        import_catalog_text($pdo, sample_catalog_text(), false);
    }
}

function sample_catalog_text(): string
{
    // Looma Apparels Catalog 2026 (ready stock).
    // Format: GSM | Product | Colors (comma separated, optional #hex) | Sizes (comma separated)
    return <<<TXT
250 GSM | Oversized Fit - French Terry | Black #131313, Royal Blue #083D8A, Lavender #684BA2, Red #C52E2E, Green #24572A, White #F2EAEA, Beige #EBE6D4, Brown #79472D, Navy Blue #1F273A | XS, S, M, L, XL, XXL
250 GSM | Oversized Fit - Acid Wash | Black #313131, Green #24572A, Royal Blue #083D8A | XS, S, M, L, XL, XXL
250 GSM | Fullsleeve Oversized Fit | Black #131313 | XS, S, M, L, XL, XXL
230 GSM | Oversized Fit | Black #131313, White #F2EAEA | XS, S, M, L, XL, XXL
190 GSM | Oversized Fit | Black #131313, White #F2EAEA | XS, S, M, L, XL, XXL
190 GSM | Regular Fit - Single Jersey | Black #000000, White #FFFFFF, Red #DB0C0C | XS, S, M, L, XL, XXL
TXT;
}
