<?php

declare(strict_types=1);

/**
 * Revierte la correccion de cost_centers (fix_duplicate_cost_centers.php).
 * Restaura Docencia (id 5) y Direccioncc duplicado (id 10) en tenant IAE (4).
 *
 * Uso: php database/revert_cost_centers_fix.php --execute
 */

$root = dirname(__DIR__);
require $root . '/src/bootstrap.php';

$execute = in_array('--execute', $argv ?? [], true);
$pdo = Db::pdo($CONFIG);

$tenantIae = 4;
$csvPath = $root . '/records2026.csv';

/** @return list<string> activity_number con Ccosto=5 en CSV */
function docencia_activity_numbers_from_csv(string $csvPath): array
{
    if (!is_file($csvPath)) {
        throw new RuntimeException('No se encuentra records2026.csv');
    }
    $fh = fopen($csvPath, 'rb');
    if ($fh === false) {
        throw new RuntimeException('No se pudo abrir records2026.csv');
    }
    $headerLine = fgets($fh);
    if ($headerLine === false) {
        fclose($fh);
        return [];
    }
    $header = str_getcsv(trim($headerLine), ';');
    $cols = array_flip($header);
    $nums = [];
    while (($row = fgetcsv($fh, 0, ';')) !== false) {
        $ccosto = trim((string) ($row[$cols['Ccosto']] ?? ''));
        if ($ccosto !== '5') {
            continue;
        }
        $num = trim((string) ($row[$cols['Num']] ?? ''));
        if ($num !== '') {
            $nums[] = $num . '-2026';
        }
    }
    fclose($fh);

    return array_values(array_unique($nums));
}

$docenciaActivities = docencia_activity_numbers_from_csv($csvPath);
echo 'Actividades Ccosto=5 (Docencia): ' . count($docenciaActivities) . "\n";

$st = $pdo->prepare('SELECT COUNT(*) FROM budget_headers WHERE tenant_id = ? AND cost_center_id = 7');
$st->execute([$tenantIae]);
echo 'Cabeceras con Direccioncc id 7: ' . $st->fetchColumn() . "\n";

$st = $pdo->prepare(
    'SELECT COUNT(*) FROM budget_headers WHERE tenant_id = ? AND cost_center_id = 6 AND activity_number = ?'
);
$onAdmin = 0;
foreach ($docenciaActivities as $an) {
    $st->execute([$tenantIae, $an]);
    $onAdmin += (int) $st->fetchColumn();
}
echo "De esas, actualmente en Administracion (id 6): {$onAdmin}\n";

if (!$execute) {
    echo "\nEjecute con --execute para aplicar.\n";
    exit(0);
}

$pdo->beginTransaction();
try {
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');

    $pdo->prepare(
        'INSERT INTO cost_centers (id, tenant_id, name) VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE tenant_id = VALUES(tenant_id), name = VALUES(name)'
    )->execute([5, $tenantIae, 'Docencia']);

    $pdo->prepare(
        'INSERT INTO cost_centers (id, tenant_id, name) VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE tenant_id = VALUES(tenant_id), name = VALUES(name)'
    )->execute([10, $tenantIae, 'Direccioncc']);

    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

    if ($docenciaActivities !== []) {
        $placeholders = implode(',', array_fill(0, count($docenciaActivities), '?'));
        $params = array_merge([5, $tenantIae], $docenciaActivities);
        $pdo->prepare(
            "UPDATE budget_headers SET cost_center_id = ?
             WHERE tenant_id = ? AND activity_number IN ({$placeholders})"
        )->execute($params);
    }

    $pdo->prepare(
        'UPDATE budget_headers SET cost_center_id = 10 WHERE tenant_id = ? AND cost_center_id = 7'
    )->execute([$tenantIae]);

    $pdo->commit();

    echo "\nRevertido.\n";
    foreach ($pdo->query(
        "SELECT id, tenant_id, name FROM cost_centers WHERE tenant_id = {$tenantIae} ORDER BY id"
    ) as $row) {
        echo "  cc {$row['id']}: {$row['name']}\n";
    }
    $st = $pdo->prepare(
        'SELECT cost_center_id, COUNT(*) AS n FROM budget_headers WHERE tenant_id = ? GROUP BY cost_center_id'
    );
    $st->execute([$tenantIae]);
    echo "Cabeceras IAE por cost_center_id:\n";
    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
        $cc = $row['cost_center_id'] === null ? 'NULL' : (string) $row['cost_center_id'];
        echo "  {$cc}: {$row['n']}\n";
    }
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    exit(1);
}
