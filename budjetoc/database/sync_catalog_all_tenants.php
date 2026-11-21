<?php

declare(strict_types=1);

/**
 * Replica el catalogo del tenant maestro a todos los demas tenants activos.
 * Uso: php database/sync_catalog_all_tenants.php
 * Opcional: BUD_CATALOG_SOURCE=cmch|3 (slug o id del tenant origen)
 */

$root = dirname(__DIR__);
require $root . '/src/bootstrap.php';

$pdo = Db::pdo($CONFIG);
$source = getenv('BUD_CATALOG_SOURCE') ?: '';
$sourceId = null;
if ($source !== '' && ctype_digit($source)) {
    $sourceId = (int) $source;
} elseif ($source !== '') {
    $st = $pdo->prepare('SELECT id FROM tenants WHERE slug = ? LIMIT 1');
    $st->execute([$source]);
    $row = $st->fetchColumn();
    $sourceId = $row !== false ? (int) $row : null;
}

$masterId = CatalogService::resolveMasterTenantId($pdo, $sourceId);
$stName = $pdo->prepare('SELECT name, slug FROM tenants WHERE id = ?');
$stName->execute([$masterId]);
$meta = $stName->fetch(PDO::FETCH_ASSOC);

echo "Origen: tenant #{$masterId} " . ($meta['name'] ?? '') . ' (' . ($meta['slug'] ?? '') . ")\n";

$result = CatalogService::replicateFromTenant($pdo, $masterId);
$a = $result['added'];

echo "Destinos: {$result['targets']} tenant(s)\n";
echo "Agregados en total:\n";
echo "  - Centros de costo: {$a['cost_centers']}\n";
echo "  - Departamentos: {$a['departments']}\n";
echo "  - Actividades: {$a['activities']}\n";
echo "  - Cuentas contables: {$a['account_codes']}\n";
echo "Listo.\n";
