<?php

declare(strict_types=1);

/**
 * Importa todo el catalogo legacy (sin actividades ni OC).
 * Uso: php database/import_legacy_catalog.php
 */

$root = dirname(__DIR__);
require $root . '/src/bootstrap.php';
require_once __DIR__ . '/sql_import_helpers.php';

$onlySlug = getenv('BUD_IMPORT_TENANT') ?: 'all';
$skipUsers = getenv('BUD_SKIP_USERS') === '1';

echo "=== Import catalogo legacy a budjetoc ===\n";
$phpBin = defined('PHP_BINARY') && PHP_BINARY !== '' ? PHP_BINARY : 'php';
passthru('"' . $phpBin . '" "' . __DIR__ . '/import_budgetflow_catalog.php"', $code1);
if ($code1 !== 0) {
    exit($code1);
}

echo "\n=== Politica edicion por ano ===\n";
$pdo = Db::pdo($CONFIG);
$dumpPath = (string) ($CONFIG['legacy_sql_dump'] ?? $root . '/cna14865_budgetflow.sql');
if (!is_file($dumpPath)) {
    $dumpPath = $root . '/cna14865_budgetflow.sql';
}
$dump = file_get_contents($dumpPath);
if ($dump === false) {
    fwrite(STDERR, "No dump budgetflow\n");
    exit(1);
}

$slugByOld = [1 => 'cmch', 2 => 'iae'];
if ($onlySlug !== 'all') {
    $slugByOld = array_filter($slugByOld, static fn (string $s): bool => $s === $onlySlug);
}
$tenantMap = [];
foreach ($slugByOld as $old => $slug) {
    $st = $pdo->prepare('SELECT id FROM tenants WHERE slug = ?');
    $st->execute([$slug]);
    $tid = $st->fetchColumn();
    if ($tid !== false) {
        $tenantMap[$old] = (int) $tid;
    }
}

/** @var array<int, array<int, string>> $oldDeptName */
$oldDeptName = [];
foreach (bud_sql_extract_insert_tuples($dump, 'departments') as $tuple) {
    $f = bud_sql_parse_tuple($tuple);
    if (count($f) < 3) {
        continue;
    }
    $oldDeptName[(int) $f[1]][(int) $f[0]] = (string) $f[2];
}

$pdo->exec('DELETE FROM tenant_department_edit_policy');
$insPol = $pdo->prepare(
    'INSERT INTO tenant_department_edit_policy (tenant_id, department_id, year, allow_edit) VALUES (?, ?, ?, ?)'
);
$polCount = 0;
foreach (bud_sql_extract_insert_tuples($dump, 'tenant_department_edit_policy') as $tuple) {
    $f = bud_sql_parse_tuple($tuple);
    if (count($f) < 4) {
        continue;
    }
    $oldTid = (int) $f[0];
    $legacyDeptId = (int) $f[1];
    if (!isset($tenantMap[$oldTid])) {
        continue;
    }
    $name = $oldDeptName[$oldTid][$legacyDeptId] ?? '';
    if ($name === '') {
        continue;
    }
    $stDn = $pdo->prepare('SELECT id FROM departments WHERE tenant_id = ? AND name = ? LIMIT 1');
    $stDn->execute([$tenantMap[$oldTid], $name]);
    $deptId = $stDn->fetchColumn();
    if ($deptId === false) {
        continue;
    }
    $insPol->execute([
        $tenantMap[$oldTid],
        (int) $deptId,
        (int) $f[2],
        (int) ($f[3] ?? 1) === 1 ? 1 : 0,
    ]);
    $polCount++;
}
echo "tenant_department_edit_policy: {$polCount}\n";

echo "\n=== Import catalogo OC ===\n";
passthru('"' . $phpBin . '" "' . __DIR__ . '/import_oc_catalog.php"', $code2);
if ($code2 !== 0) {
    exit($code2);
}

if (!$skipUsers) {
    echo "\n=== Usuarios budgetflow ===\n";
    passthru('"' . $phpBin . '" "' . __DIR__ . '/import_budgetflow_users.php"', $code3);
    if ($code3 !== 0) {
        exit($code3);
    }
}

echo "\nCatalogo legacy importado (sin actividades ni OC).\n";
