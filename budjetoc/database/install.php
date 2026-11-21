<?php

declare(strict_types=1);

/**
 * Instalaci&mdash;n inicial: schema + superadmin.
 * Uso: php database/install.php
 * Variables de entorno opcionales: BUD_ADMIN_EMAIL, BUD_ADMIN_PASS, BUD_ADMIN_NAME
 */
$root = dirname(__DIR__);
$configPath = $root . '/config/config.php';
if (!is_file($configPath)) {
    fwrite(STDERR, "Copie config/config.example.php a config/config.php\n");
    exit(1);
}
$config = require $configPath;
require_once __DIR__ . '/require_budjetoc_db.php';
require_budjetoc_database($config);
$db = $config['db'];

$pdo = new PDO(
    sprintf('mysql:host=%s;charset=utf8mb4', $db['host']),
    $db['user'],
    $db['pass'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$name = $db['name'];
$pdo->exec("CREATE DATABASE IF NOT EXISTS `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$pdo->exec("USE `{$name}`");

$sql = file_get_contents(__DIR__ . '/schema.sql');
if ($sql === false) {
    throw new RuntimeException('No se pudo leer schema.sql');
}
foreach (array_filter(array_map('trim', preg_split('/;\s*\n/', $sql))) as $stmt) {
    if ($stmt === '' || str_starts_with($stmt, '--')) {
        continue;
    }
    $pdo->exec($stmt);
}

$email = getenv('BUD_ADMIN_EMAIL') ?: 'admin@local.test';
$pass = getenv('BUD_ADMIN_PASS') ?: 'Admin1234!';
$nameAdmin = getenv('BUD_ADMIN_NAME') ?: 'Super Admin';
$hash = password_hash($pass, PASSWORD_DEFAULT);

$st = $pdo->prepare('SELECT id FROM users WHERE email = ?');
$st->execute([$email]);
$uid = (int) ($st->fetchColumn() ?: 0);
if ($uid <= 0) {
    $pdo->prepare('INSERT INTO users (email, password_hash, full_name) VALUES (?, ?, ?)')
        ->execute([$email, $hash, $nameAdmin]);
    $uid = (int) $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO super_admins (user_id) VALUES (?)')->execute([$uid]);
    echo "Superadmin creado: {$email}\n";
} else {
    echo "Superadmin ya existe: {$email}\n";
}
echo "Base `{$name}` lista.\n";
echo "Admin: {$config['base_url']}/admin/login.php\n";
echo "Presupuesto: {$config['base_url']}/budget/login.php\n";
echo "OC: {$config['base_url']}/oc/login.php\n";
