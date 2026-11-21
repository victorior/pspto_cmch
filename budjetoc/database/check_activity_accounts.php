<?php

declare(strict_types=1);

/**
 * Verifica cuenta asociada por linea de presupuesto (CLI).
 * Uso: php database/check_activity_accounts.php [budget_header_id]
 */
require dirname(__DIR__) . '/src/bootstrap.php';

$hid = isset($argv[1]) ? (int) $argv[1] : 7;
if ($hid <= 0) {
    fwrite(STDERR, "Uso: php database/check_activity_accounts.php <budget_header_id>\n");
    exit(1);
}

$pdo = Db::pdo($CONFIG);

$stH = $pdo->prepare(
    'SELECT id, tenant_id, activity_number, month FROM budget_headers WHERE id = ? LIMIT 1'
);
$stH->execute([$hid]);
$header = $stH->fetch(PDO::FETCH_ASSOC);
if (!$header) {
    echo "No existe budget_headers.id={$hid}\n";
    exit(1);
}

$tid = (int) $header['tenant_id'];
echo "=== Actividad id={$hid} tenant={$tid} numero=" . $header['activity_number'] . " mes=" . $header['month'] . " ===\n\n";

$stL = $pdo->prepare(
    'SELECT bd.id AS linea, bd.account_code_id AS fk_interno,
            ac.id AS catalogo_pk, ac.account_id AS plan_account_id, ac.name
     FROM budget_details bd
     INNER JOIN account_codes ac ON ac.id = bd.account_code_id AND ac.tenant_id = ?
     WHERE bd.budget_header_id = ?
     ORDER BY bd.id'
);
$stL->execute([$tid, $hid]);
$lines = $stL->fetchAll(PDO::FETCH_ASSOC);

if ($lines === []) {
    echo "Sin lineas en budget_details.\n";
    exit(0);
}

echo str_pad('Linea', 6) . str_pad('FK', 6) . str_pad('PK', 6)
    . str_pad('plan_id', 12) . str_pad('Nombre catálogo', 36)
    . "Etiqueta pantalla (nuevo)\n";
echo str_repeat('-', 100) . "\n";

foreach ($lines as $ln) {
    $label = budget_account_label($pdo, [
        'id' => (int) $ln['catalogo_pk'],
        'account_id' => (int) $ln['plan_account_id'],
        'name' => (string) $ln['name'],
    ]);
    $oldLabel = account_code_label([
        'account_id' => (int) $ln['plan_account_id'],
        'name' => (string) $ln['name'],
    ]);
    echo str_pad((string) $ln['linea'], 6)
        . str_pad((string) $ln['fk_interno'], 6)
        . str_pad((string) $ln['catalogo_pk'], 6)
        . str_pad((string) $ln['plan_account_id'], 12)
        . str_pad(mb_substr((string) $ln['name'], 0, 34), 36)
        . $label . "\n";
    if ($oldLabel !== $label) {
        echo "      ^ Antes (solo account_id crudo): {$oldLabel}\n";
    }
}

echo "\n=== Catálogo: filas con id 33-36 (tenant {$tid}) ===\n";
$stCat = $pdo->prepare(
    'SELECT id, account_id, name FROM account_codes WHERE tenant_id = ? AND id BETWEEN 33 AND 36 ORDER BY id'
);
$stCat->execute([$tid]);
while ($row = $stCat->fetch(PDO::FETCH_ASSOC)) {
    echo "  id={$row['id']} account_id={$row['account_id']} name={$row['name']}\n";
}

echo "\n=== PASAJES AEREOS en tenant {$tid} ===\n";
$stP = $pdo->prepare(
    "SELECT id, account_id, name FROM account_codes WHERE tenant_id = ? AND name LIKE '%PASAJES%AEREO%'"
);
$stP->execute([$tid]);
while ($row = $stP->fetch(PDO::FETCH_ASSOC)) {
    echo "  id={$row['id']} account_id={$row['account_id']} name={$row['name']}\n";
}

echo "\nListo.\n";
