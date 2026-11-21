<?php

declare(strict_types=1);

/**
 * Importa solo productos desde dump OC legacy a budjetoc (UTF-8 corregido).
 * Uso: php database/import_products.php
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
    fwrite(STDERR, "No se encuentra cna14865_oc_multitenant.sql\n");
    exit(1);
}
$sqlFile = $candidates[0];
echo "Dump: {$sqlFile}\n";

$pdo = Db::pdo($CONFIG);
$dump = bud_sql_load_dump($sqlFile);
$tenantMap = bud_resolve_tenant_map($pdo);

$pdo->beginTransaction();
try {
    foreach (array_values($tenantMap) as $tid) {
        $pdo->prepare('DELETE FROM products WHERE tenant_id = ?')->execute([$tid]);
    }

    $insProd = $pdo->prepare(
        'INSERT INTO products (tenant_id, code, name, unit_price, active) VALUES (?, ?, ?, ?, ?)'
    );
    $prodCount = 0;
    $prodSkip = 0;
    foreach (bud_sql_extract_insert_tuples($dump, 'products') as $tuple) {
        $f = bud_sql_parse_tuple($tuple);
        if (count($f) < 7) {
            continue;
        }
        $oldTid = (int) $f[1];
        if (!isset($tenantMap[$oldTid])) {
            continue;
        }
        $code = bud_sql_fix_utf8_text((string) ($f[2] ?? '')) ?? '';
        $name = bud_sql_fix_utf8_text((string) ($f[3] ?? '')) ?? '';
        $name = trim(str_replace(["\r\n", "\r"], "\n", $name));
        $active = (int) ($f[4] ?? 1) === 1 ? 1 : 0;
        $price = (float) ($f[6] ?? 0);
        if ($code === '' && $name === '') {
            continue;
        }
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

    $pdo->commit();
    echo "products: {$prodCount} importados, omitidos duplicado: {$prodSkip}\n";

    foreach ($tenantMap as $slugOld => $tid) {
        $st = $pdo->prepare('SELECT COUNT(*) FROM products WHERE tenant_id = ?');
        $st->execute([$tid]);
        $stSlug = $pdo->prepare('SELECT slug FROM tenants WHERE id = ?');
        $stSlug->execute([$tid]);
        $slug = (string) $stSlug->fetchColumn();
        echo "  tenant {$slug} (id {$tid}): " . $st->fetchColumn() . " productos\n";
    }

    $sample = $pdo->query(
        "SELECT code, name FROM products WHERE name REGEXP '[áéíóúñÁÉÍÓÚÑ°]' LIMIT 3"
    )->fetchAll(PDO::FETCH_ASSOC);
    if ($sample !== []) {
        echo "Muestra con tildes/ñ:\n";
        foreach ($sample as $row) {
            echo '  - ' . $row['code'] . ' | ' . mb_substr((string) $row['name'], 0, 60) . "\n";
        }
    }
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    exit(1);
}
