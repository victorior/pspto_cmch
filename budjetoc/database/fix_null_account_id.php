<?php

declare(strict_types=1);

/**
 * Asigna account_id (plan_cuenta) a filas legacy y fusiona duplicados.
 * Uso: php database/fix_null_account_id.php
 */
require dirname(__DIR__) . '/src/bootstrap.php';

$pdo = Db::pdo($CONFIG);
$sql = file_get_contents(dirname(__DIR__) . '/plan_cuenta.sql');
preg_match_all('/\((\d+)\s*,\s*\'((?:[^\']|\'\')*)\'\)/', $sql, $matches, PREG_SET_ORDER);

$norm = static function (string $s): string {
    $s = mb_strtolower(trim($s));
    $s = preg_replace('/[^a-z0-9]+/u', ' ', $s) ?? $s;

    return trim(preg_replace('/\s+/', ' ', $s) ?? $s);
};

$plan = [];
foreach ($matches as $m) {
    $name = str_replace(["\\'", "\\\\"], ["'", "\\"], $m[2]);
    $plan[$norm($name)] = (int) $m[1];
}

/** id interno account_codes => account_id plan_cuenta */
$manualByPk = [
    18 => 51131006,  // GASTOS LEGALES...
    27 => 51132003,  // MANT.E INSUMOS...
    29 => 51132005,  // MANTENCION GENERALY GASTOS VEHICULOS
    35 => 51134004,  // PASAJES AEREOS
    40 => 51114005,  // ROPA DE TRABAJO...
    49 => 51134002,  // VIATICOS -> movilizacion y viaje
    98 => 51131011,  // MANTENCION DE SOFTWARE...
    99 => 12219002,  // SOFTWARE Y LICENCIAS
];

function resolve_plan_account_id(string $name, array $plan, callable $norm, array $manualByPk, int $legacyPk): ?int
{
    if (isset($manualByPk[$legacyPk])) {
        return $manualByPk[$legacyPk];
    }
    if (preg_match('/\b(\d{8})\b/', $name, $m) === 1) {
        return (int) $m[1];
    }
    $key = $norm($name);
    if (isset($plan[$key])) {
        return $plan[$key];
    }
    $bestId = null;
    $bestScore = 0.0;
    foreach ($plan as $pName => $pId) {
        similar_text($key, $pName, $pct);
        if ($pct > $bestScore) {
            $bestScore = $pct;
            $bestId = $pId;
        }
    }

    return $bestScore >= 72.0 ? $bestId : null;
}

function merge_account_code(PDO $pdo, int $tenantId, int $legacyPk, int $planAccountId): string
{
    $findCanon = $pdo->prepare(
        'SELECT id FROM account_codes WHERE tenant_id = ? AND account_id = ? AND id <> ? LIMIT 1'
    );
    $findCanon->execute([$tenantId, $planAccountId, $legacyPk]);
    $canonPk = $findCanon->fetchColumn();
    if ($canonPk !== false) {
        $pdo->prepare('UPDATE budget_details SET account_code_id = ? WHERE account_code_id = ?')
            ->execute([(int) $canonPk, $legacyPk]);
        $pdo->prepare('DELETE FROM account_codes WHERE id = ?')->execute([$legacyPk]);

        return 'fusionada';
    }
    $pdo->prepare('UPDATE account_codes SET account_id = ? WHERE id = ?')->execute([$planAccountId, $legacyPk]);

    return 'actualizada';
}

$rows = $pdo->query(
    'SELECT id, tenant_id, name FROM account_codes WHERE account_id IS NULL ORDER BY id'
)->fetchAll(PDO::FETCH_ASSOC);

$stats = ['actualizada' => 0, 'fusionada' => 0, 'sin_match' => 0];
foreach ($rows as $row) {
    $legacyPk = (int) $row['id'];
    $tenantId = (int) $row['tenant_id'];
    $name = (string) $row['name'];
    $planAccountId = resolve_plan_account_id($name, $plan, $norm, $manualByPk, $legacyPk);
    if ($planAccountId === null) {
        $stats['sin_match']++;
        echo "  SIN MATCH id={$legacyPk} tenant={$tenantId} name={$name}\n";
        continue;
    }
    $action = merge_account_code($pdo, $tenantId, $legacyPk, $planAccountId);
    $stats[$action]++;
    echo "  {$action} id={$legacyPk} -> account_id={$planAccountId} ({$name})\n";
}

$left = (int) $pdo->query('SELECT COUNT(*) FROM account_codes WHERE account_id IS NULL')->fetchColumn();
$total = (int) $pdo->query('SELECT COUNT(*) FROM account_codes')->fetchColumn();
echo "\nResumen: actualizadas={$stats['actualizada']}, fusionadas={$stats['fusionada']}, sin_match={$stats['sin_match']}\n";
echo "Cuentas con account_id NULL: {$left} / total {$total}\n";

if ($left === 0) {
    $pdo->exec('ALTER TABLE account_codes MODIFY account_id BIGINT UNSIGNED NOT NULL');
    echo "account_id definido como NOT NULL.\n";
}

echo "Listo.\n";
