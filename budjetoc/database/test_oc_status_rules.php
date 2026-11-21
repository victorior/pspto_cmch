<?php

declare(strict_types=1);

/**
 * Reglas de estado OC: no reabrir; cerrar exige N factura/boleta.
 * Uso: php database/test_oc_status_rules.php
 */

require dirname(__DIR__) . '/src/bootstrap.php';

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

echo "=== Reglas estado OC (assertStatusTransition) ===\n\n";

$abierta = ['status' => OcService::STATUS_ABIERTA, 'invoice_number_in' => 0];
$abiertaConFactura = ['status' => OcService::STATUS_ABIERTA, 'invoice_number_in' => 123];
$cerrada = ['status' => OcService::STATUS_CERRADA, 'invoice_number_in' => 123];
$anulada = ['status' => OcService::STATUS_ANULADA, 'invoice_number_in' => 123];

try {
    OcService::assertStatusTransition($abierta, OcService::STATUS_ABIERTA);
    pass('misma abierta: ok');
} catch (Throwable $e) {
    fail('misma abierta: ' . $e->getMessage());
}

try {
    OcService::assertStatusTransition($abierta, OcService::STATUS_CERRADA);
    fail('cerrar sin factura debia fallar');
} catch (RuntimeException $e) {
    pass('cerrar sin factura bloqueado');
}

try {
    OcService::assertStatusTransition($abiertaConFactura, OcService::STATUS_CERRADA);
    pass('cerrar con factura ok');
} catch (Throwable $e) {
    fail('cerrar con factura: ' . $e->getMessage());
}

try {
    OcService::assertStatusTransition($cerrada, OcService::STATUS_ABIERTA);
    fail('reabrir cerrada debia fallar');
} catch (RuntimeException $e) {
    pass('reabrir cerrada bloqueado');
}

try {
    OcService::assertStatusTransition($anulada, OcService::STATUS_ABIERTA);
    fail('reabrir anulada debia fallar');
} catch (RuntimeException $e) {
    pass('reabrir anulada bloqueado');
}

try {
    OcService::assertStatusTransition($cerrada, OcService::STATUS_ANULADA);
    pass('cerrada→anulada ok');
} catch (Throwable $e) {
    fail('cerrada→anulada: ' . $e->getMessage());
}

echo "\n";
if ($fail > 0) {
    echo "FALLARON {$fail} (OK {$ok})\n";
    exit(1);
}
echo "Todas las pruebas pasaron ({$ok}).\n";
exit(0);
