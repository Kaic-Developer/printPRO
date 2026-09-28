<?php

// Cria somente o banco local informado no .env; nunca apaga tabelas existentes.
require dirname(__DIR__).'/vendor/autoload.php';

Dotenv\Dotenv::createImmutable(dirname(__DIR__))->load();

if (($_ENV['APP_ENV'] ?? '') !== 'local') {
    fwrite(STDERR, "Este comando e exclusivo do ambiente local.\n");
    exit(1);
}

$database = $_ENV['DB_DATABASE'] ?? 'printpro';
if (! preg_match('/\A[a-zA-Z0-9_]+\z/', $database)) {
    throw new RuntimeException('Nome do banco invalido.');
}

$connection = new PDO(
    'mysql:host='.($_ENV['DB_HOST'] ?? '127.0.0.1').';port='.($_ENV['DB_PORT'] ?? '3306').';charset=utf8mb4',
    $_ENV['DB_USERNAME'] ?? 'root',
    $_ENV['DB_PASSWORD'] ?? '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
);
$connection->exec("CREATE DATABASE IF NOT EXISTS `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
echo "Banco local disponivel: {$database}\n";
