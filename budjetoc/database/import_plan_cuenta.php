<?php

declare(strict_types=1);

/**
 * Importa plan_cuenta.sql (tabla plan_cuenta: id, name) a account_codes.account_id por tenant.
 *
 * Uso: php database/import_plan_cuenta.php [--tenant-id=4] [--all-tenants] [--dry-run]
 */
require dirname(__DIR__) . '/src/bootstrap.php';

$opts = getopt('', ['tenant-id:', 'all-tenants', 'dry-run']);
$dryRun = isset($opts['dry-run']);
$allTenants = isset($opts['all-tenants']);
$tenantIdArg = isset($opts['tenant-id']) ? (int) $opts['tenant-id'] : 0;

$sqlFile = dirname(__DIR__) . '/plan_cuenta.sql';
if (!is_file($sqlFile)) {
    fwrite(STDERR, "No se encuentra plan_cuenta.sql en la raiz del proyecto.\n");
    exit(1);
}

/** @return list<array{account_id: int, name: string}> */
function parse_plan_cuenta_file(string $path): array
{
    $content = utf8_file_contents($path);

    if (!preg_match_all('/\((\d+)\s*,\s*\'((?:[^\'\\\\]|\\\\.|\'\')*)\'\)/s', $content, $matches, PREG_SET_ORDER)) {
        throw new RuntimeException('No se encontraron filas INSERT en plan_cuenta.sql');
    }

    $rows = [];
    foreach ($matches as $m) {
        $name = str_replace(["\\'", "\\\\"], ["'", "\\"], $m[2]);
        $rows[] = [
            'account_id' => (int) $m[1],
            'name' => trim($name),
        ];
    }

    return $rows;
}

$pdo = Db::pdo($CONFIG);
$planRows = parse_plan_cuenta_file($sqlFile);
echo 'Cuentas en plan_cuenta.sql: ' . count($planRows) . "\n";

$tenantIds = [];
if ($allTenants) {
    $tenantIds = array_map('intval', $pdo->query('SELECT id FROM tenants WHERE active = 1 ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));
} elseif ($tenantIdArg > 0) {
    $tenantIds = [$tenantIdArg];
} else {
    $tenantIds = [CatalogService::resolveMasterTenantId($pdo)];
}

if ($tenantIds === []) {
    fwrite(STDERR, "Sin tenants.\n");
    exit(1);
}

$hasAccountId = (bool) $pdo->query(
    "SELECT 1 FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'account_codes' AND COLUMN_NAME = 'account_id'"
)->fetchColumn();

$hasCode = (bool) $pdo->query(
    "SELECT 1 FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'account_codes' AND COLUMN_NAME = 'code'"
)->fetchColumn();

if (!$hasAccountId && !$dryRun) {
    echo "Aplicando migracion 003 (agregar account_id)...\n";
    $pdo->exec(
        'ALTER TABLE account_codes ADD COLUMN account_id BIGINT UNSIGNED NULL AFTER tenant_id'
    );
    $hasAccountId = true;
}

if (!$hasAccountId) {
    fwrite(STDERR, "La tabla account_codes no tiene columna account_id. Ejecute la migracion 003.\n");
    exit(1);
}

$ins = $pdo->prepare(
    'INSERT INTO account_codes (tenant_id, account_id, name) VALUES (?, ?, ?)
     ON DUPLICATE KEY UPDATE name = VALUES(name)'
);

$matchByName = $pdo->prepare(
    'SELECT id FROM account_codes WHERE tenant_id = ? AND account_id IS NULL AND LOWER(TRIM(name)) = LOWER(TRIM(?)) LIMIT 1'
);
$upd = $pdo->prepare('UPDATE account_codes SET account_id = ? WHERE id = ? AND tenant_id = ?');

foreach ($tenantIds as $tid) {
    $stName = $pdo->prepare('SELECT name FROM tenants WHERE id = ?');
    $stName->execute([$tid]);
    $tName = (string) $stName->fetchColumn();
    echo "\nTenant {$tid} ({$tName}):\n";

    $inserted = 0;
    $updated = 0;
    if (!$dryRun) {
        $pdo->beginTransaction();
    }
    try {
        foreach ($planRows as $row) {
            $aid = $row['account_id'];
            $name = $row['name'];
            if ($dryRun) {
                $inserted++;
                continue;
            }
            $ins->execute([$tid, $aid, $name]);
            if ($ins->rowCount() === 1) {
                $inserted++;
            } elseif ($ins->rowCount() === 2) {
                $updated++;
            }
        }

        if (!$dryRun) {
            foreach ($planRows as $row) {
                $matchByName->execute([$tid, $row['name']]);
                $legacyPk = $matchByName->fetchColumn();
                if ($legacyPk !== false) {
                    $upd->execute([$row['account_id'], (int) $legacyPk, $tid]);
                }
            }
            $pdo->commit();
        }
    } catch (Throwable $e) {
        if (!$dryRun && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    $stCnt = $pdo->prepare('SELECT COUNT(*) FROM account_codes WHERE tenant_id = ?');
    $stCnt->execute([$tid]);
    $total = (int) $stCnt->fetchColumn();
    echo "  Insertadas/actualizadas: {$inserted} nuevas, {$updated} nombres actualizados. Total en tenant: {$total}\n";
}

if (!$dryRun) {
    echo "\nFinalizando account_id y esquema...\n";
    if ($hasCode) {
        $pdo->exec(
            "UPDATE account_codes SET account_id = CAST(code AS UNSIGNED)
             WHERE account_id IS NULL AND TRIM(code) REGEXP '^[0-9]+$'"
        );
    }
    $updPk = $pdo->prepare('UPDATE account_codes SET account_id = ? WHERE id = ?');
    $orphans = $pdo->query('SELECT id, name FROM account_codes WHERE account_id IS NULL')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($orphans as $orphan) {
        $nameKey = mb_strtolower(trim((string) $orphan['name']));
        foreach ($planRows as $row) {
            if (mb_strtolower(trim($row['name'])) === $nameKey) {
                $updPk->execute([$row['account_id'], (int) $orphan['id']]);
                break;
            }
        }
    }
    $deleted = $pdo->exec(
        'DELETE ac FROM account_codes ac
         LEFT JOIN budget_details bd ON bd.account_code_id = ac.id
         WHERE ac.account_id IS NULL AND bd.id IS NULL'
    );
    echo "  Cuentas huerfanas eliminadas (sin uso en presupuesto): {$deleted}\n";
    $nulls = (int) $pdo->query('SELECT COUNT(*) FROM account_codes WHERE account_id IS NULL')->fetchColumn();
    if ($nulls > 0) {
        echo "  Aviso: quedan {$nulls} cuentas sin account_id pero usadas en presupuesto; no se borran.\n";
    }
    $idxCode = $pdo->query(
        "SELECT 1 FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'account_codes' AND INDEX_NAME = 'uq_tenant_code'"
    )->fetchColumn();
    if ($idxCode) {
        try {
            $pdo->exec('ALTER TABLE account_codes DROP INDEX uq_tenant_code');
        } catch (Throwable) {
            // indice ya eliminado o columna ausente
        }
    }
    $hasCodeNow = (bool) $pdo->query(
        "SELECT 1 FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'account_codes' AND COLUMN_NAME = 'code'"
    )->fetchColumn();
    if ($hasCodeNow) {
        $pdo->exec('ALTER TABLE account_codes DROP COLUMN code');
    }
    if ($nulls === 0) {
        $pdo->exec('ALTER TABLE account_codes MODIFY account_id BIGINT UNSIGNED NOT NULL');
    } else {
        echo "  La columna code se elimino; account_id permite NULL en cuentas legacy con presupuesto.\n";
    }
    $idxExists = $pdo->query(
        "SELECT 1 FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'account_codes' AND INDEX_NAME = 'uq_tenant_account_id'"
    )->fetchColumn();
    if (!$idxExists) {
        $pdo->exec('ALTER TABLE account_codes ADD UNIQUE KEY uq_tenant_account_id (tenant_id, account_id)');
    }
}

echo $dryRun ? "\n(dry-run, sin cambios en BD)\n" : "\nListo.\n";
