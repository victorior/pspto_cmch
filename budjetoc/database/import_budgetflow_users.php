<?php

declare(strict_types=1);

/**
 * Importa usuarios desde cna14865_budgetflow.sql
 * Uso: php database/import_budgetflow_users.php
 *      BUD_IMPORT_TENANT=cmch|iae|all
 */

$root = dirname(__DIR__);
require $root . '/src/bootstrap.php';
require_once $root . '/src/LegacyUserImportService.php';

$onlySlug = getenv('BUD_IMPORT_TENANT') ?: 'all';
$dumpPath = (string) ($CONFIG['legacy_sql_dump'] ?? $root . '/cna14865_budgetflow.sql');
$password = (string) ($CONFIG['legacy_user_import_password'] ?? '');

$onlyLegacyTenant = null;
if ($onlySlug !== 'all') {
    $onlyLegacyTenant = LegacyUserImportService::legacyTenantIdForSlug($onlySlug);
    if ($onlyLegacyTenant === null) {
        fwrite(STDERR, "BUD_IMPORT_TENANT debe ser cmch, iae o all\n");
        exit(1);
    }
}

$pdo = Db::pdo($CONFIG);
try {
    $stats = LegacyUserImportService::importFromDump($pdo, $dumpPath, $password, $onlyLegacyTenant);
    echo "Usuarios creados: {$stats['users_created']}\n";
    echo "Usuarios existentes (email): {$stats['users_updated']}\n";
    echo "Roles presupuesto: {$stats['roles']}\n";
    echo "Permisos division/coord: {$stats['depts']}\n";
    echo "Superadmins: {$stats['superadmins']}\n";
    echo "Omitidos: {$stats['skipped']}\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    exit(1);
}
