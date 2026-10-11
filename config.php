<?php
// Local XAMPP defaults. Change these values if your MySQL account is different.
$fishLureDbHost = getenv('FISHLURE_DB_HOST') ?: '127.0.0.1';
$fishLureDbName = getenv('FISHLURE_DB_NAME') ?: 'fishlure_advisor';
$fishLureDbUser = getenv('FISHLURE_DB_USER') ?: 'root';
$fishLureDbPass = getenv('FISHLURE_DB_PASS') ?: '';

try {
    $pdo = new PDO(
        "mysql:host={$fishLureDbHost};dbname={$fishLureDbName};charset=utf8mb4",
        $fishLureDbUser,
        $fishLureDbPass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
} catch (PDOException $error) {
    http_response_code(503);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'MySQL is not available. Start MySQL in XAMPP and import database.sql.']);
    exit;
}
