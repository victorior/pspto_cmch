<?php

declare(strict_types=1);

/**
 * Importa cat�logo OC desde cna14865_oc_multitenant.sql (sin OC ni budget_links).
 * Tablas: tenant_settings, suppliers, products, plan_cuenta.
 *
 * Uso: php database/import_oc_catalog.php
 */

$root = dirname(__DIR__);
require $root . '/src/bootstrap.php';
require_once __DIR__ . '/sql_import_helpers.php';

$candidates = bud_legacy_oc_dump_paths($root);
$configPath = (string) ($CONFIG['legacy_oc_sql_dump'] ?? '');
if ($configPath !== '' && is_file($configPath)) {
    array_unshift($candidates, $configPath);
}
$candidates = array_values(array_unique($candidates));
if ($candidates === []) {
    fwrite(STDERR, "No se encuentra dump OC legacy (cna14865_oc_multitenant.sql)\n");
    exit(1);
}
$sqlFile = $candidates[0];

$pdo = Db::pdo($CONFIG);
$dump = bud_sql_load_dump($sqlFile);
$tenantMap = bud_resolve_tenant_map($pdo);

$pdo->beginTransaction();
try {
    $pdo->exec('DELETE FROM plan_cuenta');
    $insPlan = $pdo->prepare('INSERT INTO plan_cuenta (id, name) VALUES (?, ?)');
    $planCount = 0;
    foreach (bud_sql_extract_insert_tuples($dump, 'plan_cuenta') as $tuple) {
        $f = bud_sql_parse_tuple($tuple);
        if (count($f) < 2) {
            continue;
        }
        $insPlan->execute([(int) $f[0], bud_sql_fix_utf8_text((string) $f[1]) ?? '']);
        $planCount++;
    }
    echo "plan_cuenta: {$planCount}\n";

    $updTs = $pdo->prepare(
        'UPDATE tenant_settings SET company_name = ?, address = ?, tax_id = ?, phone = ?,
         business_line = ?, print_contact_name = ?, print_contact_role = ?, print_contact_email = ?,
         print_delivery_hours = ?, print_delivery_address = ?, print_invoice_to = ?,
         print_footer_text = ?, print_logo_path = ?, tax_percent = ?
         WHERE tenant_id = ?'
    );
    $tsCount = 0;
    foreach (bud_sql_extract_insert_tuples($dump, 'tenant_settings') as $tuple) {
        $f = bud_sql_parse_tuple($tuple);
        if (count($f) < 7) {
            continue;
        }
        $oldTid = (int) $f[0];
        if (!isset($tenantMap[$oldTid])) {
            continue;
        }
        $updTs->execute([
            bud_sql_fix_utf8_text((string) ($f[1] ?? '')) ?? '',
            bud_sql_fix_utf8_text((string) ($f[2] ?? '')) ?? '',
            bud_sql_fix_utf8_text((string) ($f[3] ?? '')) ?? '',
            bud_sql_fix_utf8_text((string) ($f[4] ?? '')) ?? '',
            bud_sql_fix_utf8_text((string) ($f[5] ?? '')) ?? '',
            bud_sql_fix_utf8_text((string) ($f[6] ?? '')) ?? '',
            bud_sql_fix_utf8_text((string) ($f[7] ?? '')) ?? '',
            bud_sql_fix_utf8_text((string) ($f[8] ?? '')) ?? '',
            bud_sql_fix_utf8_text((string) ($f[9] ?? '')) ?? '',
            bud_sql_fix_utf8_text((string) ($f[10] ?? '')) ?? '',
            bud_sql_fix_utf8_text((string) ($f[11] ?? '')) ?? '',
            bud_sql_fix_utf8_text((string) ($f[12] ?? '')) ?? '',
            bud_sql_fix_utf8_text((string) ($f[13] ?? '')) ?? '',
            (float) ($f[14] ?? 19),
            $tenantMap[$oldTid],
        ]);
        // bodega_user_id (f[15]) se omite hasta mapear usuarios OC
        $tsCount++;
    }
    echo "tenant_settings: {$tsCount} actualizados\n";

    foreach (array_values($tenantMap) as $tid) {
        $pdo->prepare('DELETE FROM suppliers WHERE tenant_id = ?')->execute([$tid]);
        $pdo->prepare('DELETE FROM products WHERE tenant_id = ?')->execute([$tid]);
    }

    $insSup = $pdo->prepare(
        'INSERT INTO suppliers (tenant_id, legal_name, trade_name, phone, email) VALUES (?, ?, ?, ?, ?)'
    );
    $supCount = 0;
    foreach (bud_sql_extract_insert_tuples($dump, 'suppliers') as $tuple) {
        $f = bud_sql_parse_tuple($tuple);
        if (count($f) < 6) {
            continue;
        }
        $oldTid = (int) $f[1];
        if (!isset($tenantMap[$oldTid])) {
            continue;
        }
        $insSup->execute([
            $tenantMap[$oldTid],
            bud_sql_fix_utf8_text((string) ($f[3] ?? '')) ?? '',
            bud_sql_fix_utf8_text((string) ($f[4] ?? '')) ?? '',
            bud_sql_fix_utf8_text((string) ($f[5] ?? '')) ?? '',
            bud_sql_fix_utf8_text((string) ($f[6] ?? '')) ?? '',
        ]);
        $supCount++;
    }
    echo "suppliers: {$supCount}\n";

    $insProd = $pdo->prepare(
        'INSERT INTO products (tenant_id, code, name, unit_price, active) VALUES (?, ?, ?, ?, ?)'
    );
    $prodCount = 0;
    $prodSkip = 0;
    foreach (bud_sql_extract_insert_tuples($dump, 'products') as $tuple) {
        $f = bud_sql_parse_tuple($tuple);
        if (count($f) < 6) {
            continue;
        }
        $oldTid = (int) $f[1];
        if (!isset($tenantMap[$oldTid])) {
            continue;
        }
        $code = bud_sql_fix_utf8_text((string) ($f[2] ?? '')) ?? '';
        $name = trim(str_replace(["\r\n", "\r"], "\n", bud_sql_fix_utf8_text((string) ($f[3] ?? '')) ?? ''));
        $active = (int) ($f[4] ?? 1) === 1 ? 1 : 0;
        $price = (float) ($f[6] ?? 0);
        try {
            $insProd->execute([$tenantMap[$oldTid], $code, $name, $price, $active]);
            $prodCount++;
        } catch (PDOException $e) {
            if (str_contains($e->getMessage(), 'Duplicate')) {
                $prodSkip++;
            } else {
                throw $e;
            }
        }
    }
    echo "products: {$prodCount}, omitidos duplicado: {$prodSkip}\n";

    $pdo->commit();
    echo "Import OC catalogo OK.\n";
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    exit(1);
}
