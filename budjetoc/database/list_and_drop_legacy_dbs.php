<?php

declare(strict_types=1);

/**
 * Lista bases MySQL duplicadas (budgetflow, pspto_unified, etc.) y las elimina.
 * La unica base de la app es budjetoc. Uso local: --execute
 * Uso: php database/list_and_drop_legacy_dbs.php [--execute]
 */

$execute = in_array('--execute', $argv ?? [], true);
$keep = 'budjetoc';

$pdo = new PDO('mysql:host=127.0.0.1;charset=utf8mb4', 'root', '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

$patterns = ['%budget%', '%pspto%', '%budjet%', '%cna14865%', 'oc_legacy', 'oc_multitenant'];
$found = [];
foreach ($patterns as $pat) {
    $st = $pdo->query("SHOW DATABASES LIKE " . $pdo->quote($pat));
    while ($row = $st->fetch(PDO::FETCH_NUM)) {
        $found[$row[0]] = true;
    }
}

echo "Bases encontradas (patrones budget/pspto/budjet/cna14865):\n";
foreach (array_keys($found) as $db) {
    echo "  - {$db}\n";
}

$toDrop = [];
foreach (array_keys($found) as $db) {
    if ($db === $keep) {
        continue;
    }
    if (in_array($db, ['information_schema', 'mysql', 'performance_schema', 'phpmyadmin'], true)) {
        continue;
    }
    $toDrop[] = $db;
}

if ($toDrop === []) {
    echo "\nNada que eliminar (solo queda o no hay duplicadas).\n";
    exit(0);
}

echo "\nSe eliminaran (DROP DATABASE):\n";
foreach ($toDrop as $db) {
    echo "  - {$db}\n";
}

if (!$execute) {
    echo "\nDry-run. Ejecute con --execute para aplicar.\n";
    exit(0);
}

foreach ($toDrop as $db) {
    $pdo->exec('DROP DATABASE IF EXISTS `' . str_replace('`', '``', $db) . '`');
    echo "Eliminada: {$db}\n";
}

echo "\nListo. Base activa: {$keep}\n";
