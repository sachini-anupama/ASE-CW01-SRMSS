<?php
declare(strict_types=1);

require __DIR__ . '/config.php';

header('Content-Type: text/plain; charset=utf-8');

try {
    $dsn = sprintf('mysql:host=%s;charset=%s', DB_HOST, DB_CHARSET);
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    $sql = file_get_contents(__DIR__ . '/schema.sql');
    if ($sql === false) {
        throw new RuntimeException('Could not read schema.sql');
    }

    $quotedDbName = '`' . str_replace('`', '``', DB_NAME) . '`';
    $pdo->exec("DROP DATABASE IF EXISTS {$quotedDbName}");

    foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
        $pdo->exec($statement);
    }

    echo "SRMSS database installed successfully.\n";
    echo "Database: " . DB_NAME . "\n";
} catch (Throwable $e) {
    http_response_code(500);
    echo "Install failed: " . $e->getMessage() . "\n";
}
