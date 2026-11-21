<?php

declare(strict_types=1);

/**
 * CLI: vac�a OC + actividades de presupuesto. Cat�logo y usuarios se conservan.
 *
 *   php database/reset_operational_data.php
 *   php database/reset_operational_data.php --tenant=4
 *   php database/reset_operational_data.php --yes
 */

require dirname(__DIR__) . '/src/bootstrap.php';

$tenantId = null;
$confirmed = in_array('--yes', $argv ?? [], true);
foreach ($argv ?? [] as $arg) {
    if (str_starts_with($arg, '--tenant=')) {
        $tenantId = (int) substr($arg, 9);
        if ($tenantId <= 0) {
            fwrite(STDERR, "tenant_id invalido\n");
            exit(1);
        }
    }
}

$pdo = Db::pdo($CONFIG);
$scope = $tenantId === null ? 'TODOS los tenants' : "tenant_id={$tenantId}";

if (!$confirmed) {
    echo "ATENCION: se borraran OC, imputaciones, actividades y correlativos ({$scope}).\n";
    echo "Cat�logo (CC, divisiones, cuentas, proveedores) y usuarios NO se tocan.\n";
    echo "Ejecute de nuevo con --yes para confirmar.\n";
    exit(0);
}

$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');

if ($tenantId === null) {
    $pdo->exec('DELETE FROM po_budget_allocations');
    $pdo->exec('DELETE FROM po_lines');
    $pdo->exec('DELETE FROM purchase_orders');
} else {
    $pdo->prepare('DELETE FROM po_budget_allocations WHERE tenant_id = ?')->execute([$tenantId]);
    $pdo->prepare('DELETE FROM po_lines WHERE tenant_id = ?')->execute([$tenantId]);
    $pdo->prepare('DELETE FROM purchase_orders WHERE tenant_id = ?')->execute([$tenantId]);
}

if ($tenantId === null) {
    $pdo->exec(
        'DELETE bd FROM budget_details bd
         INNER JOIN budget_headers bh ON bh.id = bd.budget_header_id'
    );
    $pdo->exec('DELETE FROM budget_headers');
    $pdo->exec('DELETE FROM activity_sequences');
} else {
    $st = $pdo->prepare(
        'DELETE bd FROM budget_details bd
         INNER JOIN budget_headers bh ON bh.id = bd.budget_header_id
         WHERE bh.tenant_id = ?'
    );
    $st->execute([$tenantId]);
    $pdo->prepare('DELETE FROM budget_headers WHERE tenant_id = ?')->execute([$tenantId]);
    $pdo->prepare('DELETE FROM activity_sequences WHERE tenant_id = ?')->execute([$tenantId]);
}

foreach (['po_budget_allocations', 'po_lines', 'purchase_orders', 'budget_details', 'budget_headers'] as $t) {
    $pdo->exec("ALTER TABLE `{$t}` AUTO_INCREMENT = 1");
}

$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

echo "Listo: datos operativos vaciados ({$scope}).\n";
