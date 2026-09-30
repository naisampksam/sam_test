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
            sort INT NOT NULL DEFAULT 0,
            active TINYINT(1) NOT NULL DEFAULT 1
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS orders (
            id INT AUTO_INCREMENT PRIMARY KEY,
            customer_id VARCHAR(80) NOT NULL DEFAULT '',
            gsm VARCHAR(40) NOT NULL DEFAULT '',
            product VARCHAR(150) NOT NULL DEFAULT '',
            color VARCHAR(80) NOT NULL DEFAULT '',
            size VARCHAR(40) NOT NULL DEFAULT '',
            quantity INT NOT NULL DEFAULT 1,
            front_print TEXT NULL,
            back_print TEXT NULL,
            chest_print TEXT NULL,
            neck_label TEXT NULL,
            custom_print TEXT NULL,
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

        "CREATE TABLE IF NOT EXISTS order_images (
            id INT AUTO_INCREMENT PRIMARY KEY,
            order_id INT NOT NULL,
            filename VARCHAR(100) NOT NULL,
            original_name VARCHAR(255) NOT NULL DEFAULT '',
            uploaded_by INT NULL,
            created_at DATETIME NOT NULL,
            INDEX (order_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS order_log (
            id INT AUTO_INCREMENT PRIMARY KEY,
            order_id INT NOT NULL,
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

/** Starter data. Replace the sample catalog from Admin -> Catalog (bulk import supported). */
function seed_data(PDO $pdo): void
{
    $settings = ['company_name' => 'Looma Apparels', 'dispatch_days' => '2', 'skip_sundays' => '1'];
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
        $st = $pdo->prepare('INSERT INTO custom_fields (label, type, options, sort) VALUES (?, ?, ?, ?)');
        $st->execute(['Print method', 'select', 'DTF, Puff Print, HD / High Density, Embroidery, Screen Print', 1]);
        $st->execute(['Print size', 'select', 'A2 (16x22), A3 (11x16), A4 (8x11), Logo (2.5x2.5), Custom', 2]);
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
