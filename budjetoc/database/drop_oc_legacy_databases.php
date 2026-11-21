<?php

declare(strict_types=1);

/**
 * Elimina bases MySQL oc_legacy / oc_multitenant (solo local).
 * Uso: php database/drop_oc_legacy_databases.php [--execute]
 */

$execute = in_array('--execute', $argv ?? [], true);
$toDrop = ['oc_legacy', 'oc_multitenant'];

$pdo = new PDO('mysql:host=127.0.0.1;charset=utf8mb4', 'root', '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

$found = [];
foreach ($toDrop as $db) {
    $st = $pdo->query('SHOW DATABASES LIKE ' . $pdo->quote($db));
    if ($st->fetch()) {
        $found[] = $db;
    }
}

if ($found === []) {
    echo "No existen oc_legacy ni oc_multitenant en MySQL.\n";
    exit(0);
}

echo "Bases a eliminar:\n";
foreach ($found as $db) {
    echo "  - {$db}\n";
}

if (!$execute) {
    echo "\nDry-run. Ejecute con --execute.\n";
    exit(0);
}

foreach ($found as $db) {
    $pdo->exec('DROP DATABASE IF EXISTS `' . str_replace('`', '``', $db) . '`');
    echo "Eliminada: {$db}\n";
}

echo "Listo.\n";
