<?php
require_once __DIR__ . '/env.php';
app_env_load();

$host = app_env_get('DB_HOST', '127.0.0.1');
$user = app_env_get('DB_USER', 'root');
$pass = app_env_get('DB_PASS', '');
$db   = app_env_get('DB_NAME', 'estelsgp');

$dsn = "mysql:host={$host};dbname={$db};charset=utf8mb4";

$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (PDOException $e) {
    http_response_code(500);
    die("Erro na ligação à base de dados.");
}

