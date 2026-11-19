<?php
declare(strict_types=1);

/*
 * FABRIC TOUCH - MySQL database setup
 *
 * Required environment variables:
 *   DB_HOST
 *   DB_NAME
 *   DB_USER
 *   DB_PASSWORD
 *
 * Optional:
 *   DB_PORT (defaults to 3306)
 *
 * This file connects to an EXISTING MySQL database and creates the
 * store tables if they do not already exist. It does not create the
 * MySQL database itself.
 */

header('Content-Type: text/plain; charset=utf-8');

$dbHost = getenv('DB_HOST') ?: '';
$dbName = getenv('DB_NAME') ?: 'fabric_touch';
$dbUser = getenv('DB_USER') ?: '';
$dbPass = getenv('DB_PASSWORD') ?: '';
$dbPort = getenv('DB_PORT') ?: '3306';

if ($dbHost === '' || $dbUser === '') {
    http_response_code(500);
    exit(
        "Database configuration is incomplete.\n" .
        "Set DB_HOST, DB_USER, DB_PASSWORD and DB_NAME in your hosting environment.\n"
    );
}

try {
    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        $dbHost,
        $dbPort,
        $dbName
    );

    $pdo = new PDO($dsn, $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS products (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            name_ar VARCHAR(255) NOT NULL,
            name_fr VARCHAR(255) DEFAULT NULL,
            description_ar TEXT DEFAULT NULL,
            description_fr TEXT DEFAULT NULL,
            price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            stock INT NOT NULL DEFAULT 0,
            fabric_type VARCHAR(120) DEFAULT NULL,
            image_url TEXT DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS orders (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            customer_name VARCHAR(255) NOT NULL,
            phone VARCHAR(50) NOT NULL,
            address TEXT NOT NULL,
            total DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            payment_method ENUM('cod','online') NOT NULL DEFAULT 'cod',
            payment_status VARCHAR(40) NOT NULL DEFAULT 'unpaid',
            order_status VARCHAR(40) NOT NULL DEFAULT 'new',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS order_items (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            order_id BIGINT UNSIGNED NOT NULL,
            product_id INT UNSIGNED DEFAULT NULL,
            product_name VARCHAR(255) NOT NULL,
            quantity INT NOT NULL DEFAULT 1,
            unit_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            PRIMARY KEY (id),
            KEY idx_order_items_order_id (order_id),
            CONSTRAINT fk_order_items_order
                FOREIGN KEY (order_id) REFERENCES orders(id)
                ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    echo "SUCCESS: Connected to MySQL and checked/created FABRIC TOUCH tables.\n";
    echo "Database: " . $dbName . "\n";
} catch (Throwable $e) {
    http_response_code(500);
    error_log('FABRIC TOUCH database setup error: ' . $e->getMessage());
    echo "DATABASE ERROR: " . $e->getMessage() . "\n";
}
