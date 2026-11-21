<?php

declare(strict_types=1);

/**
 * Importa actividades 2026 desde records2026.csv (export Excel).
 *
 * Uso:
 *   php database/import_records2026.php              (simulacion)
 *   php database/import_records2026.php --execute    (graba en BD)
 *   php database/import_records2026.php --revert      (borra importacion YYYY)
 *
 * Variables opcionales:
 *   BUD_IMPORT_YEAR=2026
 *   BUD_IMPORT_TENANT=iae   (slug; si no, se detecta por columna id)
 *   BUD_RECORDS_CSV=ruta/al/archivo.csv
 */

$root = dirname(__DIR__);
require $root . '/src/bootstrap.php';
require_once $root . '/src/Records2026ImportService.php';

$year = (int) (getenv('BUD_IMPORT_YEAR') ?: '2026');
$execute = in_array('--execute', $argv ?? [], true);
$revert = in_array('--revert', $argv ?? [], true);
$csvPath = getenv('BUD_RECORDS_CSV') ?: $root . '/records2026.csv';

$tenantId = null;
$slug = getenv('BUD_IMPORT_TENANT') ?: '';
if ($slug !== '') {
    $pdo = Db::pdo($CONFIG);
    $st = $pdo->prepare('SELECT id FROM tenants WHERE slug = ? LIMIT 1');
    $st->execute([$slug]);
    $tid = $st->fetchColumn();
    if ($tid === false) {
        fwrite(STDERR, "Tenant no encontrado: {$slug}\n");
        exit(1);
    }
    $tenantId = (int) $tid;
}

$pdo = Db::pdo($CONFIG);

if ($revert) {
    if ($tenantId === null) {
        $tenantId = Records2026ImportService::detectTenantFromCsv($pdo, $csvPath);
    }
    if ($tenantId === null) {
        fwrite(STDERR, "Indique tenant: BUD_IMPORT_TENANT=iae\n");
        exit(1);
    }
    echo "REVERT importacion {$year} tenant_id={$tenantId}\n";
    try {
        $rev = Records2026ImportService::revertImport($pdo, $tenantId, $year);
    } catch (Throwable $e) {
        fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
        exit(1);
    }
    echo "Cabeceras eliminadas: {$rev['headers_deleted']}\n";
    echo "Lineas detalle eliminadas: {$rev['details_deleted']}\n";
    exit(0);
}

echo ($execute ? 'IMPORT' : 'SIMULACION') . " desde {$csvPath}\n";
echo "Ano actividad: {$year}\n";

try {
    $stats = Records2026ImportService::importFromCsv($pdo, $csvPath, $year, $tenantId, !$execute);
} catch (Throwable $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    exit(1);
}

echo "Grupos (Num unicos): {$stats['groups']}\n";
echo "Cabeceras " . ($execute ? 'creadas' : 'a crear') . ": {$stats['headers_created']}\n";
echo "Cabeceras omitidas (ya existian): {$stats['headers_skipped']}\n";
echo "Lineas detalle " . ($execute ? 'creadas' : 'a crear') . ": {$stats['details_created']}\n";

if ($stats['errors'] !== []) {
    echo "Errores (" . count($stats['errors']) . "):\n";
    foreach (array_slice($stats['errors'], 0, 30) as $err) {
        echo "  - {$err}\n";
    }
    if (count($stats['errors']) > 30) {
        echo '  ... y ' . (count($stats['errors']) - 30) . " mas\n";
    }
    exit($execute ? 1 : 0);
}

if (!$execute) {
    echo "\nEjecute con --execute para grabar en la base budjetoc.\n";
}
