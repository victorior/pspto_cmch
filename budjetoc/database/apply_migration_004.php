<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

$pdo = Db::pdo($CONFIG);
$sql = file_get_contents(__DIR__ . '/migrations/004_po_budget_allocations_indexes.sql');
if ($sql === false) {
    fwrite(STDERR, "No se pudo leer migration 004\n");
    exit(1);
}
try {
    $pdo->exec($sql);
    echo "Migration 004 aplicada.\n";
} catch (PDOException $e) {
    if (str_contains($e->getMessage(), 'Duplicate key name')) {
        echo "Indice idx_tenant_detail ya existe.\n";
        exit(0);
    }
    throw $e;
}
