<?php

declare(strict_types=1);

/**
 * Prueba CLI de reglas Presupuesto/OC (sin sesion web).
 * Uso: php database/test_budget_oc_rules.php
 */

require dirname(__DIR__) . '/src/bootstrap.php';

$pdo = Db::pdo($CONFIG);
$fail = 0;
$ok = 0;

function pass(string $msg): void
{
    global $ok;
    $ok++;
    echo "  OK   {$msg}\n";
}

function fail(string $msg): void
{
    global $fail;
    $fail++;
    echo "  FAIL {$msg}\n";
}

echo "=== Pruebas PHP (BudgetService / OcPrintView) ===\n\n";

$st = $pdo->query('SELECT tenant_id FROM budget_headers LIMIT 1');
$tid = (int) ($st->fetchColumn() ?: 0);
if ($tid <= 0) {
    $st = $pdo->query('SELECT id FROM tenants LIMIT 1');
    $tid = (int) ($st->fetchColumn() ?: 1);
}

pass('tenant_id=' . $tid);

if (!BudgetService::hasBudgetYearColumn($pdo)) {
    fail('hasBudgetYearColumn');
} else {
    pass('hasBudgetYearColumn');
}

$st = $pdo->prepare('SELECT id FROM budget_headers WHERE tenant_id = ? LIMIT 1');
$st->execute([$tid]);
$hid = (int) ($st->fetchColumn() ?: 0);
if ($hid <= 0) {
    fail('Sin actividades para probar');
} else {
    pass("header_id={$hid}");

    $voided = BudgetService::allocationsForHeaderVoided($pdo, $tid, $hid);
    $active = BudgetService::allocationsForHeader($pdo, $tid, $hid);
    pass('allocationsForHeader vigentes=' . count($active) . ' voided=' . count($voided));

    $util = BudgetService::utilizedSumsByHeaderIds($pdo, [$hid])[$hid] ?? 0.0;
    $sumActive = 0.0;
    foreach ($active as $a) {
        $sumActive += (float) ($a['amount'] ?? 0);
    }
    if (abs($util - $sumActive) < 0.02 || count($active) === 0) {
        pass("utilizedSumsByHeaderIds={$util} alineado con allocations vigentes");
    } else {
        fail("utilized={$util} vs sum allocations={$sumActive}");
    }

    $hdr = BudgetService::getHeader($pdo, $tid, $hid);
    if ($hdr !== null) {
        pass('getHeader activity_number=' . ($hdr['activity_number'] ?? ''));
    } else {
        fail('getHeader');
    }
}

$st = $pdo->prepare('SELECT id FROM purchase_orders WHERE tenant_id = ? LIMIT 1');
$st->execute([$tid]);
$poId = (int) ($st->fetchColumn() ?: 0);
if ($poId > 0) {
    $view = OcPrintView::build($pdo, $tid, $poId);
    if ($view !== null && isset($view['folioLabel'], $view['lines'])) {
        pass('OcPrintView::build folio=' . $view['folioLabel'] . ' lines=' . count($view['lines']));
    } else {
        fail('OcPrintView::build');
    }
} else {
    pass('OcPrintView omitido (sin OC en BD)');
}

if (PurchaseOrderStatus::ANULADA === OcService::STATUS_ANULADA) {
    pass('PurchaseOrderStatus === OcService::STATUS_ANULADA');
} else {
    fail('Constantes status desalineadas');
}

$rows = BudgetService::searchLines($pdo, $tid, '', 2026, 5);
pass('searchLines (sin N+1 fatal) count=' . count($rows));

echo "\n=== Resumen PHP: OK={$ok} FAIL={$fail} ===\n";
exit($fail > 0 ? 1 : 0);
