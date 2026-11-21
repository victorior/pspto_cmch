<?php

declare(strict_types=1);

/**
 * Importa catalogo desde cna14865_budgetflow.sql a budjetoc.
 * Tablas: account_codes, activities, cost_centers, departments.
 *
 * Uso: php database/import_budgetflow_catalog.php
 * Opcional: BUD_IMPORT_TENANT=cmch|iae|all (default all)
 */

$root = dirname(__DIR__);
require $root . '/src/bootstrap.php';
$config = $CONFIG;
$sqlFile = $root . '/cna14865_budgetflow.sql';

if (!is_file($sqlFile)) {
    fwrite(STDERR, "No se encuentra cna14865_budgetflow.sql en {$root}\n");
    exit(1);
}

$onlySlug = getenv('BUD_IMPORT_TENANT') ?: 'all';
$sourceTenants = [
    1 => ['name' => 'Colegio CMCH', 'slug' => 'cmch'],
    2 => ['name' => 'IAE', 'slug' => 'iae'],
];

if ($onlySlug !== 'all') {
    $found = false;
    foreach ($sourceTenants as $oldId => $meta) {
        if ($meta['slug'] === $onlySlug) {
            $sourceTenants = [$oldId => $meta];
            $found = true;
            break;
        }
    }
    if (!$found) {
        fwrite(STDERR, "BUD_IMPORT_TENANT debe ser cmch, iae o all\n");
        exit(1);
    }
}

$pdo = new PDO(
    sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $config['db']['host'], $config['db']['name']),
    $config['db']['user'],
    $config['db']['pass'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$dump = file_get_contents($sqlFile);
if ($dump === false) {
    fwrite(STDERR, "No se pudo leer el dump.\n");
    exit(1);
}

/** @return list<string> */
function extract_insert_tuples(string $dump, string $table): array
{
    $pattern = '/INSERT INTO `' . preg_quote($table, '/') . '`\s*\([^)]+\)\s*VALUES\s*(.*?);/s';
    if (!preg_match($pattern, $dump, $m)) {
        return [];
    }
    $body = $m[1];
    $rows = [];
    $len = strlen($body);
    $i = 0;
    while ($i < $len) {
        while ($i < $len && in_array($body[$i], [' ', "\n", "\r", "\t", ','], true)) {
            $i++;
        }
        if ($i >= $len || $body[$i] !== '(') {
            break;
        }
        $depth = 0;
        $start = $i;
        $inString = false;
        $escape = false;
        for (; $i < $len; $i++) {
            $c = $body[$i];
            if ($inString) {
                if ($escape) {
                    $escape = false;
                } elseif ($c === '\\') {
                    $escape = true;
                } elseif ($c === "'") {
                    $inString = false;
                }
                continue;
            }
            if ($c === "'") {
                $inString = true;
                continue;
            }
            if ($c === '(') {
                $depth++;
            } elseif ($c === ')') {
                $depth--;
                if ($depth === 0) {
                    $rows[] = substr($body, $start, $i - $start + 1);
                    $i++;
                    break;
                }
            }
        }
    }

    return $rows;
}

/** @return list<string|null> */
function parse_sql_tuple(string $tuple): array
{
    $tuple = trim($tuple);
    if (str_starts_with($tuple, '(')) {
        $tuple = substr($tuple, 1, -1);
    }
    $fields = [];
    $buf = '';
    $len = strlen($tuple);
    $inString = false;
    $escape = false;
    for ($i = 0; $i < $len; $i++) {
        $c = $tuple[$i];
        if ($inString) {
            if ($escape) {
                $buf .= $c;
                $escape = false;
            } elseif ($c === '\\') {
                $escape = true;
            } elseif ($c === "'") {
                $inString = false;
            } else {
                $buf .= $c;
            }
            continue;
        }
        if ($c === "'") {
            $inString = true;
            continue;
        }
        if ($c === ',') {
            $fields[] = trim_value($buf);
            $buf = '';
            continue;
        }
        $buf .= $c;
    }
    $fields[] = trim_value($buf);

    return $fields;
}

function trim_value(string $v): ?string
{
    $v = trim($v);
    if (strtoupper($v) === 'NULL') {
        return null;
    }

    return $v;
}

/** Id numerico de plan_cuenta desde dump legacy (columna code o id de fila). */
function legacy_account_id(?string $code, int $legacyId): int
{
    $code = trim((string) $code);
    if ($code !== '' && preg_match('/^\d+$/', $code) === 1) {
        return (int) $code;
    }

    return $legacyId > 0 ? $legacyId : 0;
}

/** @var array<int, int> $tenantMap old source tenant_id => budjetoc tenant_id */
$tenantMap = [];

$pdo->beginTransaction();
try {
    foreach ($sourceTenants as $oldTid => $meta) {
        $st = $pdo->prepare('SELECT id FROM tenants WHERE slug = ? LIMIT 1');
        $st->execute([$meta['slug']]);
        $newId = $st->fetchColumn();
        if ($newId === false) {
            $pdo->prepare(
                'INSERT INTO tenants (name, slug, budget_enabled, oc_enabled, active) VALUES (?, ?, 1, 1, 1)'
            )->execute([$meta['name'], $meta['slug']]);
            $newId = (int) $pdo->lastInsertId();
            $pdo->prepare('INSERT INTO tenant_settings (tenant_id, company_name) VALUES (?, ?)')
                ->execute([(int) $newId, $meta['name']]);
            echo "Tenant creado: {$meta['name']} (id {$newId}, slug {$meta['slug']})\n";
        } else {
            $newId = (int) $newId;
            echo "Tenant existente: {$meta['name']} (id {$newId})\n";
        }
        $tenantMap[$oldTid] = $newId;

        foreach (['account_codes', 'activities', 'cost_centers', 'departments'] as $tbl) {
            $pdo->prepare("DELETE FROM {$tbl} WHERE tenant_id = ?")->execute([$newId]);
        }
    }

    $insCc = $pdo->prepare('INSERT INTO cost_centers (tenant_id, name) VALUES (?, ?)');
    $ccInserted = 0;
    $ccSkipped = 0;
    foreach (extract_insert_tuples($dump, 'cost_centers') as $tuple) {
        $f = parse_sql_tuple($tuple);
        if (count($f) < 3) {
            continue;
        }
        $oldTenant = (int) $f[1];
        if (!isset($tenantMap[$oldTenant])) {
            continue;
        }
        $tenantId = $tenantMap[$oldTenant];
        $name = (string) $f[2];
        if (CatalogService::costCenterExists($pdo, $tenantId, $name)) {
            $ccSkipped++;
            continue;
        }
        $insCc->execute([$tenantId, $name]);
        $ccInserted++;
    }
    echo "cost_centers: {$ccInserted} insertados, {$ccSkipped} omitidos (nombre duplicado en tenant)\n";

    $insDept = $pdo->prepare(
        'INSERT INTO departments (tenant_id, name, type, parent_id) VALUES (?, ?, ?, ?)'
    );
    $deptCount = 0;
    foreach (extract_insert_tuples($dump, 'departments') as $tuple) {
        $f = parse_sql_tuple($tuple);
        if (count($f) < 4) {
            continue;
        }
        $oldTenant = (int) $f[1];
        if (!isset($tenantMap[$oldTenant])) {
            continue;
        }
        $type = $f[3] === 'coordinacion' ? 'coordinacion' : 'estandar';
        $insDept->execute([$tenantMap[$oldTenant], $f[2], $type, null]);
        $deptCount++;
    }
    echo "departments: {$deptCount} filas insertadas\n";

    $insAct = $pdo->prepare('INSERT INTO activities (tenant_id, name) VALUES (?, ?)');
    $actCount = 0;
    foreach (extract_insert_tuples($dump, 'activities') as $tuple) {
        $f = parse_sql_tuple($tuple);
        if (count($f) < 3) {
            continue;
        }
        $oldTenant = (int) $f[1];
        if (!isset($tenantMap[$oldTenant])) {
            continue;
        }
        $insAct->execute([$tenantMap[$oldTenant], $f[2]]);
        $actCount++;
    }
    echo "activities: {$actCount} filas insertadas\n";

    $insAc = $pdo->prepare('INSERT INTO account_codes (tenant_id, account_id, name) VALUES (?, ?, ?)');
    $acCount = 0;
    $acSkip = 0;
    foreach (extract_insert_tuples($dump, 'account_codes') as $tuple) {
        $f = parse_sql_tuple($tuple);
        if (count($f) < 4) {
            continue;
        }
        $legacyId = (int) $f[0];
        $oldTenant = (int) $f[1];
        if (!isset($tenantMap[$oldTenant])) {
            continue;
        }
        $name = (string) $f[3];
        $accountId = legacy_account_id($f[2] ?? null, $legacyId);
        if ($accountId <= 0) {
            $acSkip++;
            continue;
        }
        try {
            $insAc->execute([$tenantMap[$oldTenant], $accountId, $name]);
            $acCount++;
        } catch (PDOException $e) {
            if (str_contains($e->getMessage(), 'Duplicate')) {
                $acSkip++;
            } else {
                $acSkip++;
            }
        }
    }
    echo "account_codes: {$acCount} insertadas, {$acSkip} omitidas por error\n";

    $pdo->commit();
    echo "Importacion completada.\n";
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    exit(1);
}
