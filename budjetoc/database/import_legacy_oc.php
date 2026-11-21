<?php

declare(strict_types=1);

/**
 * Importa ordenes de compra desde cia35385_OC_IAE.sql (sistema legacy IAE).
 *
 * Uso:
 *   php database/import_legacy_oc.php
 *   php database/import_legacy_oc.php --execute
 *   php database/import_legacy_oc.php --execute --tenant=iae
 *   php database/import_legacy_oc.php --revert --tenant=iae
 */

$root = dirname(__DIR__);
require $root . '/src/bootstrap.php';
require_once $root . '/src/LegacyOcImportService.php';

$execute = in_array('--execute', $argv ?? [], true);
$revert = in_array('--revert', $argv ?? [], true);
$tenantSlug = 'iae';
$dumpPath = $root . '/cia35385_OC_IAE.sql';
$importYear = null;

foreach ($argv ?? [] as $arg) {
    if (str_starts_with($arg, '--tenant=')) {
        $tenantSlug = substr($arg, 9);
    }
    if (str_starts_with($arg, '--dump=')) {
        $dumpPath = substr($arg, 7);
    }
    if (str_starts_with($arg, '--year=')) {
        $importYear = substr($arg, 7);
    }
}

if (!is_file($dumpPath)) {
    fwrite(STDERR, "No se encuentra el dump: {$dumpPath}\n");
    exit(1);
}

$pdo = Db::pdo($CONFIG);
$st = $pdo->prepare('SELECT id, name FROM tenants WHERE slug = ? LIMIT 1');
$st->execute([$tenantSlug]);
$tenantRow = $st->fetch(PDO::FETCH_ASSOC);
if ($tenantRow === false) {
    fwrite(STDERR, "Tenant no encontrado: {$tenantSlug}\n");
    exit(1);
}
$tenantId = (int) $tenantRow['id'];

if ($revert) {
    echo "REVERT OC legacy tenant {$tenantSlug} (id={$tenantId})";
    if ($importYear === '2026') {
        echo ' (solo 2026)';
    }
    echo "\n";
    $rev = LegacyOcImportService::revertImport($pdo, $tenantId, $importYear === '2026' ? '2026' : null);
    echo "OC eliminadas: {$rev['orders_deleted']}\n";
    exit(0);
}

echo ($execute ? 'IMPORT' : 'SIMULACION') . " OC legacy";
if ($importYear === '2026') {
    echo ' serie 2026 (detalle_factura 2026xxxx)';
}
echo "\n";
echo "Tenant: {$tenantRow['name']} (id={$tenantId}, slug={$tenantSlug})\n";
echo "Dump: {$dumpPath}\n\n";

try {
    $stats = $importYear === '2026'
        ? LegacyOcImportService::importSeries2026FromDump($pdo, $dumpPath, $tenantId, !$execute)
        : LegacyOcImportService::importFromDump($pdo, $dumpPath, $tenantId, !$execute);
} catch (Throwable $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    exit(1);
}

echo 'OC ' . ($execute ? 'creadas' : 'a crear') . ": {$stats['orders_created']}\n";
echo "OC omitidas (ya existian): {$stats['orders_skipped']}\n";
echo 'Lineas ' . ($execute ? 'creadas' : 'a crear') . ": {$stats['lines_created']}\n";
echo 'Imputaciones ' . ($execute ? 'creadas' : 'a crear') . ": {$stats['allocations_created']}\n";
echo "Imputaciones omitidas (sin cabecera presupuesto en BD nueva): {$stats['allocations_skipped']}\n";
echo 'Proveedores nuevos: ' . ($execute ? $stats['suppliers_created'] : '(simulacion)') . "\n";

if ($stats['errors'] !== []) {
    echo "\nErrores (" . count($stats['errors']) . "):\n";
    foreach (array_slice($stats['errors'], 0, 20) as $err) {
        echo "  - {$err}\n";
    }
    if (count($stats['errors']) > 20) {
        echo '  ... y ' . (count($stats['errors']) - 20) . " mas\n";
    }
}

if (!$execute) {
    echo "\nEjecute con --execute para grabar en budjetoc.\n";
}
