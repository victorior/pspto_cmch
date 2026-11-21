<?php

declare(strict_types=1);

/**
 * Fusiona centros de costo duplicados SOLO dentro del mismo tenant (mismo nombre).
 * Conserva el id menor. No modifica otros tenants ni reasigna por IDs legacy.
 *
 * Uso:
 *   php database/merge_duplicate_cost_centers.php
 *   php database/merge_duplicate_cost_centers.php --execute
 *   php database/merge_duplicate_cost_centers.php --execute --tenant=4
 */

$root = dirname(__DIR__);
require $root . '/src/bootstrap.php';

$execute = in_array('--execute', $argv ?? [], true);
$onlyTenant = null;
foreach ($argv ?? [] as $arg) {
    if (str_starts_with($arg, '--tenant=')) {
        $onlyTenant = (int) substr($arg, 9);
    }
}

$pdo = Db::pdo($CONFIG);

/** @return list<array{tenant_id: int, keep_id: int, dup_id: int, name: string, header_count: int}>} */
function find_same_tenant_duplicates(PDO $pdo, ?int $onlyTenant): array
{
    $sql = <<<'SQL'
        SELECT c1.tenant_id, c2.id AS keep_id, c1.id AS dup_id, c1.name,
               (SELECT COUNT(*) FROM budget_headers bh
                WHERE bh.cost_center_id = c1.id AND bh.tenant_id = c1.tenant_id) AS header_count
        FROM cost_centers c1
        INNER JOIN cost_centers c2
            ON c2.tenant_id = c1.tenant_id
           AND LOWER(TRIM(c2.name)) = LOWER(TRIM(c1.name))
           AND c2.id < c1.id
        SQL;
    if ($onlyTenant !== null && $onlyTenant > 0) {
        $sql .= ' WHERE c1.tenant_id = ' . (int) $onlyTenant;
    }
    $sql .= ' ORDER BY c1.tenant_id, LOWER(TRIM(c1.name)), c1.id';

    $rows = [];
    foreach ($pdo->query($sql) as $row) {
        $rows[] = [
            'tenant_id' => (int) $row['tenant_id'],
            'keep_id' => (int) $row['keep_id'],
            'dup_id' => (int) $row['dup_id'],
            'name' => (string) $row['name'],
            'header_count' => (int) $row['header_count'],
        ];
    }

    return $rows;
}

/** @return int */
function count_foreign_headers_outside_tenant(PDO $pdo, int $dupId, int $tenantId): int
{
    $st = $pdo->prepare(
        'SELECT COUNT(*) FROM budget_headers bh
         INNER JOIN cost_centers cc ON cc.id = bh.cost_center_id
         WHERE bh.cost_center_id = ? AND bh.tenant_id <> ?'
    );
    $st->execute([$dupId, $tenantId]);

    return (int) $st->fetchColumn();
}

$dupes = find_same_tenant_duplicates($pdo, $onlyTenant);

if ($dupes === []) {
    echo "No hay duplicados por nombre dentro del mismo tenant.\n";
    exit(0);
}

echo ($execute ? 'Ejecutando' : 'Vista previa') . ":\n";
foreach ($dupes as $d) {
    $foreign = count_foreign_headers_outside_tenant($pdo, $d['dup_id'], $d['tenant_id']);
    echo sprintf(
        "  tenant %d: id %d -> %d (%s), %d cabecera(s) mismo tenant",
        $d['tenant_id'],
        $d['dup_id'],
        $d['keep_id'],
        $d['name'],
        $d['header_count']
    );
    if ($foreign > 0) {
        echo " [OMITIDO: {$foreign} cabecera(s) de otro tenant usan id {$d['dup_id']}]";
    }
    echo "\n";
}

if (!$execute) {
    echo "\nEjecute con --execute para aplicar.\n";
    exit(0);
}

$pdo->beginTransaction();
try {
    $upd = $pdo->prepare(
        'UPDATE budget_headers SET cost_center_id = ?
         WHERE cost_center_id = ? AND tenant_id = ?'
    );
    $del = $pdo->prepare('DELETE FROM cost_centers WHERE id = ? AND tenant_id = ?');

    foreach ($dupes as $d) {
        if (count_foreign_headers_outside_tenant($pdo, $d['dup_id'], $d['tenant_id']) > 0) {
            throw new RuntimeException(
                "No se puede fusionar id {$d['dup_id']}: hay cabeceras de otro tenant."
            );
        }
        $upd->execute([$d['keep_id'], $d['dup_id'], $d['tenant_id']]);
        $del->execute([$d['dup_id'], $d['tenant_id']]);
    }

    $pdo->commit();
    echo "\nFusion completada.\n";
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    exit(1);
}
