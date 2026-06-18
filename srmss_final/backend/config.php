<?php
declare(strict_types=1);

const DB_HOST = '127.0.0.1';
const DB_NAME = 'srmss_db';
const DB_USER = 'root';
const DB_PASS = '';
const DB_CHARSET = 'utf8mb4';

function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', DB_HOST, DB_NAME, DB_CHARSET);
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    return $pdo;
}

function json_response(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function request_data(): array
{
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    if (stripos($contentType, 'application/json') !== false) {
        $decoded = json_decode(file_get_contents('php://input') ?: '{}', true);
        return is_array($decoded) ? $decoded : [];
    }

    if ($_POST) {
        return $_POST;
    }

    parse_str(file_get_contents('php://input') ?: '', $parsed);
    return is_array($parsed) ? $parsed : [];
}

function clean_data(array $data): array
{
    foreach ($data as $key => $value) {
        if (is_string($value)) {
            $value = trim($value);
        }
        $data[$key] = $value === '' ? null : $value;
    }

    return $data;
}

function require_fields(array $data, array $fields): void
{
    $missing = [];
    foreach ($fields as $field) {
        if (!isset($data[$field]) || trim((string) $data[$field]) === '') {
            $missing[] = $field;
        }
    }

    if ($missing) {
        json_response([
            'success' => false,
            'message' => 'Missing required fields.',
            'missing' => $missing,
        ], 422);
    }
}

function first_id(string $table, string $idColumn): int
{
    $stmt = db()->query("SELECT {$idColumn} FROM {$table} ORDER BY {$idColumn} LIMIT 1");
    return (int) ($stmt->fetchColumn() ?: 1);
}
