<?php

declare(strict_types=1);

/**
 * CLI — delete multi-tenant de catálogo org (historia 3 / CAP-1 delete + CAP-2).
 * Uso: php database/test_catalog_org_delete.php
 *
 * Cubre matriz I/O: OK, con headers, desalineación,
 * CSRF inválido, no–super-admin, HTTP success SA+CSRF (CC/div/coord).
 */

require dirname(__DIR__) . '/src/bootstrap.php';

$pdo = Db::pdo($CONFIG);
$fail = 0;
$ok = 0;

function pass(string $msg): void
{
    global $ok;
    $ok++;
    echo "  OK   {$msg}\n";
}

function fail(string $msg): void
{
    global $fail;
    $fail++;
    echo "  FAIL {$msg}\n";
}

function assertTrue(string $label, bool $cond): void
{
    if ($cond) {
        pass($label);
    } else {
        fail($label);
    }
}

/**
 * @return array{id: int, email: string}
 */
function createTestUser(PDO $pdo, string $prefix): array
{
    $email = strtolower($prefix . '.' . bin2hex(random_bytes(4)) . '@test.local');
    $pdo->prepare(
        'INSERT INTO users (email, full_name, password_hash, active) VALUES (?, ?, ?, 1)'
    )->execute([$email, $prefix, password_hash('x', PASSWORD_DEFAULT)]);

    return ['id' => (int) $pdo->lastInsertId(), 'email' => $email];
}

/**
 * @return array{id: int, name: string}|null
 */
function findCostCenterByName(PDO $pdo, int $tenantId, string $name): ?array
{
    $st = $pdo->prepare(
        'SELECT id, name FROM cost_centers WHERE tenant_id = ? AND LOWER(TRIM(name)) = LOWER(TRIM(?)) LIMIT 1'
    );
    $st->execute([$tenantId, $name]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    return $row ? ['id' => (int) $row['id'], 'name' => (string) $row['name']] : null;
}

/**
 * @return array{id: int, name: string}|null
 */
function findDepartmentByName(PDO $pdo, int $tenantId, string $name, string $type): ?array
{
    $st = $pdo->prepare(
        'SELECT id, name FROM departments WHERE tenant_id = ? AND type = ? AND LOWER(TRIM(name)) = LOWER(TRIM(?)) LIMIT 1'
    );
    $st->execute([$tenantId, $type, $name]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    return $row ? ['id' => (int) $row['id'], 'name' => (string) $row['name']] : null;
}

/**
 * @param array{
 *   script: string,
 *   action: string,
 *   user_id: int,
 *   tenant_id: int,
 *   entity_id: int,
 *   csrf_post: string,
 *   csrf_sess: string,
 *   superadmin?: bool,
 *   tenant_admin?: bool
 * } $opts
 * @return array{out: string, code: int, http: int}
 */
function runCatalogDeleteHandler(array $opts): array
{
    $probe = dirname(__DIR__) . '/database/_tmp_catalog_delete_handler.php';
    $payloadFile = dirname(__DIR__) . '/database/_tmp_catalog_delete_payload.json';
    file_put_contents($payloadFile, json_encode($opts, JSON_THROW_ON_ERROR));

    $probeSrc = <<<'PHP'
<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';
$opts = json_decode((string) file_get_contents(__DIR__ . '/_tmp_catalog_delete_payload.json'), true, 512, JSON_THROW_ON_ERROR);
@unlink(__DIR__ . '/_tmp_catalog_delete_payload.json');

$httpCode = 200;
register_shutdown_function(static function () use (&$httpCode): void {
    $fromPhp = (int) http_response_code();
    if ($fromPhp > 0) {
        $httpCode = $fromPhp;
    }
    echo "\n__HTTP__" . (string) $httpCode . "\n";
});

Auth::startRealm(Auth::REALM_ADMIN);
Auth::loginUser(
    (int) $opts['user_id'],
    Auth::REALM_ADMIN,
    (int) $opts['tenant_id'],
    !empty($opts['superadmin'])
);
if (!empty($opts['tenant_admin'])) {
    $_SESSION['is_tenant_admin'] = true;
}
$_SESSION['_csrf'] = (string) $opts['csrf_sess'];
$_SERVER['REQUEST_METHOD'] = 'POST';
$_GET = ['id' => (int) $opts['tenant_id']];
$_POST = [
    'tenant_id' => (int) $opts['tenant_id'],
    'action' => (string) $opts['action'],
    'id' => (int) $opts['entity_id'],
    '_csrf' => (string) $opts['csrf_post'],
];

$script = basename((string) $opts['script']);
$allowed = [
    'tenant_catalog_cost_centers.php',
    'tenant_catalog_divisions.php',
    'tenant_catalog_coordinations.php',
];
if (!in_array($script, $allowed, true)) {
    fwrite(STDERR, "script no permitido\n");
    http_response_code(500);
    exit(1);
}

ob_start();
try {
    require dirname(__DIR__) . '/public/admin/' . $script;
} catch (Throwable $e) {
    $httpCode = 500;
    echo $e->getMessage();
}
$buf = ob_get_clean();
$fromPhp = (int) http_response_code();
if ($fromPhp > 0) {
    $httpCode = $fromPhp;
} elseif ($httpCode === 0) {
    $httpCode = 200;
}
echo $buf;
PHP;
    file_put_contents($probe, $probeSrc);

    $phpBin = PHP_BINARY !== '' ? PHP_BINARY : 'php';
    $out = [];
    $code = 0;
    exec(escapeshellarg($phpBin) . ' ' . escapeshellarg($probe) . ' 2>&1', $out, $code);
    @unlink($probe);
    @unlink($payloadFile);

    $joined = implode("\n", $out);
    $http = 200;
    if (preg_match('/__HTTP__(\d+)/', $joined, $m)) {
        $http = (int) $m[1];
    }

    return ['out' => $joined, 'code' => $code, 'http' => $http];
}

echo "=== Delete catálogo org multi-tenant (CAP-1 delete + CAP-2) ===\n\n";

$tenantIds = CatalogService::tenantIds($pdo, true);
if (count($tenantIds) < 2) {
    fail('Se requieren al menos 2 tenants activos');
    echo "\nResultado: {$ok} ok, {$fail} fail\n";
    exit(1);
}
pass('tenants activos=' . count($tenantIds) . ' ids=' . implode(',', $tenantIds));

$workTenant = $tenantIds[0];
$fxSt = $pdo->prepare(
    'SELECT
        (SELECT id FROM activities a WHERE a.tenant_id = ? ORDER BY a.id ASC LIMIT 1) AS activity_id,
        (SELECT id FROM account_codes ac WHERE ac.tenant_id = ? ORDER BY ac.id ASC LIMIT 1) AS account_code_id,
        (SELECT id FROM departments d WHERE d.tenant_id = ? AND d.type = \'estandar\' ORDER BY d.id ASC LIMIT 1) AS seed_div_id'
);
$fxSt->execute([$workTenant, $workTenant, $workTenant]);
$fx = $fxSt->fetch(PDO::FETCH_ASSOC);
$activityId = (int) ($fx['activity_id'] ?? 0);
$accountCodeId = (int) ($fx['account_code_id'] ?? 0);
$seedDivId = (int) ($fx['seed_div_id'] ?? 0);
if ($activityId <= 0 || $accountCodeId <= 0 || $seedDivId <= 0) {
    fail('Sin fixtures activity/account/div en tenant de trabajo');
    echo "\nResultado: {$ok} ok, {$fail} fail\n";
    exit(1);
}
pass("fixtures activity={$activityId} account={$accountCodeId} seed_div={$seedDivId}");

$suffix = bin2hex(random_bytes(3));
$ccName = 'ZZ Del CC ' . $suffix;
$ccBlocked = 'ZZ Del CC Block ' . $suffix;
$ccHttp = 'ZZ Del CC Http ' . $suffix;
$ccRemote = 'ZZ Del CC Remote ' . $suffix;
$divName = 'ZZ Del Div ' . $suffix;
$divBlocked = 'ZZ Del Div Block ' . $suffix;
$divHttp = 'ZZ Del Div Http ' . $suffix;
$divCreatedBy = 'ZZ Del Div CB ' . $suffix;
$coordName = 'ZZ Del Coord ' . $suffix;
$coordBlocked = 'ZZ Del Coord Block ' . $suffix;
$coordHttp = 'ZZ Del Coord Http ' . $suffix;
$coordCreatedBy = 'ZZ Del Coord CB ' . $suffix;
$misalignCc = 'ZZ Del Misalign CC ' . $suffix;
$misalignDiv = 'ZZ Del Misalign Div ' . $suffix;

$createdCcIds = [];
$createdDeptIds = [];
$createdHeaderIds = [];
$createdUserIds = [];

$cleanup = static function () use (
    $pdo,
    $tenantIds,
    &$createdCcIds,
    &$createdDeptIds,
    &$createdHeaderIds,
    &$createdUserIds,
    $ccName,
    $ccBlocked,
    $ccHttp,
    $ccRemote,
    $divName,
    $divBlocked,
    $divHttp,
    $divCreatedBy,
    $coordName,
    $coordBlocked,
    $coordHttp,
    $coordCreatedBy,
    $misalignCc,
    $misalignDiv
): void {
    foreach ($createdHeaderIds as $hid) {
        $pdo->prepare('DELETE FROM budget_details WHERE budget_header_id = ?')->execute([$hid]);
        $pdo->prepare('DELETE FROM budget_headers WHERE id = ?')->execute([$hid]);
    }
    $namesCc = [$ccName, $ccBlocked, $ccHttp, $ccRemote, $misalignCc];
    $namesDiv = [$divName, $divBlocked, $divHttp, $divCreatedBy, $misalignDiv];
    $namesCoord = [$coordName, $coordBlocked, $coordHttp, $coordCreatedBy];
    foreach ($tenantIds as $tid) {
        foreach ($namesCc as $n) {
            $pdo->prepare(
                'DELETE FROM cost_centers WHERE tenant_id = ? AND LOWER(TRIM(name)) = LOWER(TRIM(?))'
            )->execute([$tid, $n]);
        }
        foreach ($namesDiv as $n) {
            $pdo->prepare(
                "DELETE FROM departments WHERE tenant_id = ? AND type = 'estandar' AND LOWER(TRIM(name)) = LOWER(TRIM(?))"
            )->execute([$tid, $n]);
        }
        foreach ($namesCoord as $n) {
            $pdo->prepare(
                "DELETE FROM departments WHERE tenant_id = ? AND type = 'coordinacion' AND LOWER(TRIM(name)) = LOWER(TRIM(?))"
            )->execute([$tid, $n]);
        }
    }
    foreach ($createdCcIds as $id) {
        $pdo->prepare('DELETE FROM cost_centers WHERE id = ?')->execute([$id]);
    }
    foreach ($createdDeptIds as $id) {
        $pdo->prepare('DELETE FROM departments WHERE id = ?')->execute([$id]);
    }
    foreach ($createdUserIds as $id) {
        $pdo->prepare('DELETE FROM user_tenant_roles WHERE user_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM super_admins WHERE user_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
    }
};

register_shutdown_function($cleanup);

$idsByTenantCc = [];
$idsByTenantCcBlocked = [];
$idsByTenantCcRemote = [];
$idsByTenantDiv = [];
$idsByTenantDivBlocked = [];
$idsByTenantDivCreatedBy = [];
$idsByTenantCoord = [];
$idsByTenantCoordBlocked = [];
$idsByTenantCoordCreatedBy = [];
$idsByTenantCcHttp = [];
$idsByTenantDivHttp = [];
$idsByTenantCoordHttp = [];

foreach ($tenantIds as $tid) {
    $pdo->prepare('INSERT INTO cost_centers (tenant_id, name) VALUES (?, ?)')->execute([$tid, $ccName]);
    $idsByTenantCc[$tid] = (int) $pdo->lastInsertId();
    $createdCcIds[] = $idsByTenantCc[$tid];

    $pdo->prepare('INSERT INTO cost_centers (tenant_id, name) VALUES (?, ?)')->execute([$tid, $ccBlocked]);
    $idsByTenantCcBlocked[$tid] = (int) $pdo->lastInsertId();
    $createdCcIds[] = $idsByTenantCcBlocked[$tid];

    $pdo->prepare('INSERT INTO cost_centers (tenant_id, name) VALUES (?, ?)')->execute([$tid, $ccHttp]);
    $idsByTenantCcHttp[$tid] = (int) $pdo->lastInsertId();
    $createdCcIds[] = $idsByTenantCcHttp[$tid];

    $pdo->prepare('INSERT INTO cost_centers (tenant_id, name) VALUES (?, ?)')->execute([$tid, $ccRemote]);
    $idsByTenantCcRemote[$tid] = (int) $pdo->lastInsertId();
    $createdCcIds[] = $idsByTenantCcRemote[$tid];

    $pdo->prepare("INSERT INTO departments (tenant_id, name, type) VALUES (?, ?, 'estandar')")
        ->execute([$tid, $divName]);
    $idsByTenantDiv[$tid] = (int) $pdo->lastInsertId();
    $createdDeptIds[] = $idsByTenantDiv[$tid];

    $pdo->prepare("INSERT INTO departments (tenant_id, name, type) VALUES (?, ?, 'estandar')")
        ->execute([$tid, $divBlocked]);
    $idsByTenantDivBlocked[$tid] = (int) $pdo->lastInsertId();
    $createdDeptIds[] = $idsByTenantDivBlocked[$tid];

    $pdo->prepare("INSERT INTO departments (tenant_id, name, type) VALUES (?, ?, 'estandar')")
        ->execute([$tid, $divHttp]);
    $idsByTenantDivHttp[$tid] = (int) $pdo->lastInsertId();
    $createdDeptIds[] = $idsByTenantDivHttp[$tid];

    $pdo->prepare("INSERT INTO departments (tenant_id, name, type) VALUES (?, ?, 'estandar')")
        ->execute([$tid, $divCreatedBy]);
    $idsByTenantDivCreatedBy[$tid] = (int) $pdo->lastInsertId();
    $createdDeptIds[] = $idsByTenantDivCreatedBy[$tid];

    $pdo->prepare("INSERT INTO departments (tenant_id, name, type) VALUES (?, ?, 'coordinacion')")
        ->execute([$tid, $coordName]);
    $idsByTenantCoord[$tid] = (int) $pdo->lastInsertId();
    $createdDeptIds[] = $idsByTenantCoord[$tid];

    $pdo->prepare("INSERT INTO departments (tenant_id, name, type) VALUES (?, ?, 'coordinacion')")
        ->execute([$tid, $coordBlocked]);
    $idsByTenantCoordBlocked[$tid] = (int) $pdo->lastInsertId();
    $createdDeptIds[] = $idsByTenantCoordBlocked[$tid];

    $pdo->prepare("INSERT INTO departments (tenant_id, name, type) VALUES (?, ?, 'coordinacion')")
        ->execute([$tid, $coordHttp]);
    $idsByTenantCoordHttp[$tid] = (int) $pdo->lastInsertId();
    $createdDeptIds[] = $idsByTenantCoordHttp[$tid];

    $pdo->prepare("INSERT INTO departments (tenant_id, name, type) VALUES (?, ?, 'coordinacion')")
        ->execute([$tid, $coordCreatedBy]);
    $idsByTenantCoordCreatedBy[$tid] = (int) $pdo->lastInsertId();
    $createdDeptIds[] = $idsByTenantCoordCreatedBy[$tid];
}
pass('seed CC/div/coord alineado en todos los tenants activos');

// --- Vacío ---
$r = CatalogService::deleteCostCenterAcrossTenants($pdo, '   ');
assertTrue('CC vacío: ok=false', $r['ok'] === false);
assertTrue('CC vacío: menciona requerido', isset($r['error']) && str_contains($r['error'], 'requerido'));

$r = CatalogService::deleteDepartmentAcrossTenants($pdo, '   ', 'estandar');
assertTrue('Div vacío: ok=false', $r['ok'] === false);
assertTrue('Div vacío: menciona requerido', isset($r['error']) && str_contains($r['error'], 'requerido'));

// --- Desalineación ---
$pdo->prepare('INSERT INTO cost_centers (tenant_id, name) VALUES (?, ?)')->execute([$workTenant, $misalignCc]);
$createdCcIds[] = (int) $pdo->lastInsertId();
$r = CatalogService::deleteCostCenterAcrossTenants($pdo, $misalignCc);
assertTrue('CC desalineación: ok=false', $r['ok'] === false);
assertTrue(
    'CC desalineación: menciona desalineación/sync',
    isset($r['error']) && (stripos($r['error'], 'desaline') !== false || stripos($r['error'], 'sync') !== false)
);
assertTrue('CC desalineación: sin mutación', findCostCenterByName($pdo, $workTenant, $misalignCc) !== null);

$pdo->prepare("INSERT INTO departments (tenant_id, name, type) VALUES (?, ?, 'estandar')")
    ->execute([$workTenant, $misalignDiv]);
$createdDeptIds[] = (int) $pdo->lastInsertId();
$r = CatalogService::deleteDepartmentAcrossTenants($pdo, $misalignDiv, 'estandar');
assertTrue('Div desalineación: ok=false', $r['ok'] === false);
assertTrue(
    'Div desalineación: menciona desalineación/sync',
    isset($r['error']) && (stripos($r['error'], 'desaline') !== false || stripos($r['error'], 'sync') !== false)
);
assertTrue(
    'Div desalineación: sin mutación',
    findDepartmentByName($pdo, $workTenant, $misalignDiv, 'estandar') !== null
);

// --- CAP-2: headers asociados ---
$year = (int) date('Y');
$ccBlockedId = $idsByTenantCcBlocked[$workTenant];
$headerCc = [
    'month' => 4,
    'cost_center_id' => $ccBlockedId,
    'activity_id' => $activityId,
    'division_id' => $seedDivId,
    'department_id' => null,
    'observations' => 'test catalog org delete cc guard',
];
$hidCc = BudgetService::createActivity(
    $pdo,
    $workTenant,
    $headerCc,
    [['account_code_id' => $accountCodeId, 'quantity' => 1.0, 'unit_price' => 10.0, 'observation' => 'x']],
    $year,
    $seedDivId
);
$createdHeaderIds[] = $hidCc;

$r = CatalogService::deleteCostCenterAcrossTenants($pdo, $ccBlocked);
assertTrue('CC con actividades: ok=false', $r['ok'] === false);
assertTrue(
    'CC con actividades: menciona actividades',
    isset($r['error']) && stripos($r['error'], 'actividades') !== false
);
assertTrue('CC con actividades: sin mutación', findCostCenterByName($pdo, $workTenant, $ccBlocked) !== null);
assertTrue(
    'CC con actividades: paridad intacta otro tenant',
    findCostCenterByName($pdo, $tenantIds[1], $ccBlocked) !== null
);

$divBlockedId = $idsByTenantDivBlocked[$workTenant];
$headerDiv = [
    'month' => 4,
    'cost_center_id' => $ccBlockedId,
    'activity_id' => $activityId,
    'division_id' => $divBlockedId,
    'department_id' => null,
    'observations' => 'test catalog org delete div guard',
];
$hidDiv = BudgetService::createActivity(
    $pdo,
    $workTenant,
    $headerDiv,
    [['account_code_id' => $accountCodeId, 'quantity' => 1.0, 'unit_price' => 10.0, 'observation' => 'x']],
    $year,
    $divBlockedId
);
$createdHeaderIds[] = $hidDiv;

$r = CatalogService::deleteDepartmentAcrossTenants($pdo, $divBlocked, 'estandar');
assertTrue('Div con actividades: ok=false', $r['ok'] === false);
assertTrue(
    'Div con actividades: menciona actividades',
    isset($r['error']) && stripos($r['error'], 'actividades') !== false
);
assertTrue(
    'Div con actividades: sin mutación',
    findDepartmentByName($pdo, $workTenant, $divBlocked, 'estandar') !== null
);

$coordBlockedId = $idsByTenantCoordBlocked[$workTenant];
$headerCoord = [
    'month' => 4,
    'cost_center_id' => null,
    'activity_id' => $activityId,
    'division_id' => $seedDivId,
    'department_id' => $coordBlockedId,
    'observations' => 'test catalog org delete coord guard',
];
$hidCoord = BudgetService::createActivity(
    $pdo,
    $workTenant,
    $headerCoord,
    [['account_code_id' => $accountCodeId, 'quantity' => 1.0, 'unit_price' => 10.0, 'observation' => 'x']],
    $year,
    $coordBlockedId
);
$createdHeaderIds[] = $hidCoord;

$r = CatalogService::deleteDepartmentAcrossTenants($pdo, $coordBlocked, 'coordinacion');
assertTrue('Coord con actividades: ok=false', $r['ok'] === false);
assertTrue(
    'Coord con actividades: menciona actividades',
    isset($r['error']) && stripos($r['error'], 'actividades') !== false
);
assertTrue(
    'Coord con actividades: sin mutación',
    findDepartmentByName($pdo, $workTenant, $coordBlocked, 'coordinacion') !== null
);

// --- CAP-2: solo created_by (division_id/department_id null) ---
$divCbId = $idsByTenantDivCreatedBy[$workTenant];
$hidDivCb = BudgetService::createActivity(
    $pdo,
    $workTenant,
    [
        'month' => 4,
        'cost_center_id' => $ccBlockedId,
        'activity_id' => $activityId,
        'division_id' => $seedDivId,
        'department_id' => null,
        'observations' => 'test catalog org delete div created_by only',
    ],
    [['account_code_id' => $accountCodeId, 'quantity' => 1.0, 'unit_price' => 10.0, 'observation' => 'x']],
    $year,
    $seedDivId
);
$createdHeaderIds[] = $hidDivCb;
$pdo->prepare(
    'UPDATE budget_headers SET created_by = ?, division_id = NULL, department_id = NULL WHERE id = ? AND tenant_id = ?'
)->execute([$divCbId, $hidDivCb, $workTenant]);

$r = CatalogService::deleteDepartmentAcrossTenants($pdo, $divCreatedBy, 'estandar');
assertTrue('Div solo created_by: ok=false', $r['ok'] === false);
assertTrue(
    'Div solo created_by: menciona actividades',
    isset($r['error']) && stripos($r['error'], 'actividades') !== false
);
assertTrue(
    'Div solo created_by: sin mutación',
    findDepartmentByName($pdo, $workTenant, $divCreatedBy, 'estandar') !== null
);

$coordCbId = $idsByTenantCoordCreatedBy[$workTenant];
$hidCoordCb = BudgetService::createActivity(
    $pdo,
    $workTenant,
    [
        'month' => 4,
        'cost_center_id' => null,
        'activity_id' => $activityId,
        'division_id' => $seedDivId,
        'department_id' => $coordCbId,
        'observations' => 'test catalog org delete coord created_by only',
    ],
    [['account_code_id' => $accountCodeId, 'quantity' => 1.0, 'unit_price' => 10.0, 'observation' => 'x']],
    $year,
    $coordCbId
);
$createdHeaderIds[] = $hidCoordCb;
$pdo->prepare(
    'UPDATE budget_headers SET created_by = ?, department_id = NULL, division_id = NULL WHERE id = ? AND tenant_id = ?'
)->execute([$coordCbId, $hidCoordCb, $workTenant]);

$r = CatalogService::deleteDepartmentAcrossTenants($pdo, $coordCreatedBy, 'coordinacion');
assertTrue('Coord solo created_by: ok=false', $r['ok'] === false);
assertTrue(
    'Coord solo created_by: menciona actividades',
    isset($r['error']) && stripos($r['error'], 'actividades') !== false
);
assertTrue(
    'Coord solo created_by: sin mutación',
    findDepartmentByName($pdo, $workTenant, $coordCreatedBy, 'coordinacion') !== null
);

// --- CAP-2: asociación solo en tenant no-work ---
$otherTenant = $tenantIds[1];
$fxOtherSt = $pdo->prepare(
    'SELECT
        (SELECT id FROM activities a WHERE a.tenant_id = ? ORDER BY a.id ASC LIMIT 1) AS activity_id,
        (SELECT id FROM account_codes ac WHERE ac.tenant_id = ? ORDER BY ac.id ASC LIMIT 1) AS account_code_id,
        (SELECT id FROM departments d WHERE d.tenant_id = ? AND d.type = \'estandar\' ORDER BY d.id ASC LIMIT 1) AS seed_div_id'
);
$fxOtherSt->execute([$otherTenant, $otherTenant, $otherTenant]);
$fxOther = $fxOtherSt->fetch(PDO::FETCH_ASSOC);
$otherActivityId = (int) ($fxOther['activity_id'] ?? 0);
$otherAccountCodeId = (int) ($fxOther['account_code_id'] ?? 0);
$otherSeedDivId = (int) ($fxOther['seed_div_id'] ?? 0);
if ($otherActivityId <= 0 || $otherAccountCodeId <= 0 || $otherSeedDivId <= 0) {
    fail('Sin fixtures activity/account/div en tenant remoto');
} else {
    $ccRemoteLocalId = $idsByTenantCcRemote[$otherTenant];
    $hidRemote = BudgetService::createActivity(
        $pdo,
        $otherTenant,
        [
            'month' => 5,
            'cost_center_id' => $ccRemoteLocalId,
            'activity_id' => $otherActivityId,
            'division_id' => $otherSeedDivId,
            'department_id' => null,
            'observations' => 'test catalog org delete remote tenant guard',
        ],
        [['account_code_id' => $otherAccountCodeId, 'quantity' => 1.0, 'unit_price' => 10.0, 'observation' => 'x']],
        $year,
        $otherSeedDivId
    );
    $createdHeaderIds[] = $hidRemote;

    $r = CatalogService::deleteCostCenterAcrossTenants($pdo, $ccRemote);
    assertTrue('CC remoto CAP-2: ok=false', $r['ok'] === false);
    assertTrue(
        'CC remoto CAP-2: menciona actividades',
        isset($r['error']) && stripos($r['error'], 'actividades') !== false
    );
    foreach ($tenantIds as $tid) {
        assertTrue(
            "CC remoto CAP-2: nombre permanece tenant {$tid}",
            findCostCenterByName($pdo, $tid, $ccRemote) !== null
        );
    }
}

// --- Delete OK ---
$r = CatalogService::deleteCostCenterAcrossTenants($pdo, $ccName);
assertTrue('CC OK: ok=true', $r['ok'] === true && ($r['changed'] ?? false) === true);
foreach ($tenantIds as $tid) {
    assertTrue("CC OK: tenant {$tid} sin nombre", findCostCenterByName($pdo, $tid, $ccName) === null);
}

$r = CatalogService::deleteDepartmentAcrossTenants($pdo, $divName, 'estandar');
assertTrue('Div OK: ok=true', $r['ok'] === true && ($r['changed'] ?? false) === true);
foreach ($tenantIds as $tid) {
    assertTrue(
        "Div OK: tenant {$tid} sin nombre",
        findDepartmentByName($pdo, $tid, $divName, 'estandar') === null
    );
}

$r = CatalogService::deleteDepartmentAcrossTenants($pdo, $coordName, 'coordinacion');
assertTrue('Coord OK: ok=true', $r['ok'] === true && ($r['changed'] ?? false) === true);
foreach ($tenantIds as $tid) {
    assertTrue(
        "Coord OK: tenant {$tid} sin nombre",
        findDepartmentByName($pdo, $tid, $coordName, 'coordinacion') === null
    );
}

// --- CSRF / no–SA / HTTP success ---
$ccRow = findCostCenterByName($pdo, $workTenant, $ccHttp);
$divRow = findDepartmentByName($pdo, $workTenant, $divHttp, 'estandar');
$coordRow = findDepartmentByName($pdo, $workTenant, $coordHttp, 'coordinacion');
if ($ccRow === null || $divRow === null || $coordRow === null) {
    fail('fixture CC/div/coord para handler HTTP');
} else {
    $sa = createTestUser($pdo, 'sa_del_org');
    $createdUserIds[] = $sa['id'];
    $pdo->prepare('INSERT IGNORE INTO super_admins (user_id) VALUES (?)')->execute([$sa['id']]);

    $ta = createTestUser($pdo, 'ta_del_org');
    $createdUserIds[] = $ta['id'];
    $pdo->prepare(
        "INSERT INTO user_tenant_roles (user_id, tenant_id, app, role) VALUES (?, ?, 'budget', 'tenant_admin')"
    )->execute([$ta['id'], $workTenant]);

    $csrfBad = runCatalogDeleteHandler([
        'script' => 'tenant_catalog_cost_centers.php',
        'action' => 'delete_cc',
        'user_id' => $sa['id'],
        'tenant_id' => $workTenant,
        'entity_id' => $ccRow['id'],
        'csrf_post' => 'bad-token',
        'csrf_sess' => 'good-token',
        'superadmin' => true,
    ]);
    assertTrue('CSRF CC: HTTP 403', $csrfBad['http'] === 403 || str_contains($csrfBad['out'], 'CSRF'));
    assertTrue('CSRF CC: sin mutación', findCostCenterByName($pdo, $workTenant, $ccHttp) !== null);

    $nsa = runCatalogDeleteHandler([
        'script' => 'tenant_catalog_cost_centers.php',
        'action' => 'delete_cc',
        'user_id' => $ta['id'],
        'tenant_id' => $workTenant,
        'entity_id' => $ccRow['id'],
        'csrf_post' => 'tok',
        'csrf_sess' => 'tok',
        'superadmin' => false,
        'tenant_admin' => true,
    ]);
    $nsaBlocked = $nsa['http'] === 302
        || stripos($nsa['out'], 'login') !== false
        || stripos($nsa['out'], 'Location:') !== false
        || findCostCenterByName($pdo, $workTenant, $ccHttp) !== null;
    assertTrue('no–SA: no alcanza mutación exitosa', $nsaBlocked);
    assertTrue('no–SA: sin mutación', findCostCenterByName($pdo, $workTenant, $ccHttp) !== null);

    runCatalogDeleteHandler([
        'script' => 'tenant_catalog_cost_centers.php',
        'action' => 'delete_cc',
        'user_id' => $sa['id'],
        'tenant_id' => $workTenant,
        'entity_id' => $ccRow['id'],
        'csrf_post' => 'ok-csrf',
        'csrf_sess' => 'ok-csrf',
        'superadmin' => true,
    ]);
    assertTrue('HTTP CC OK: eliminado en tenant trabajo', findCostCenterByName($pdo, $workTenant, $ccHttp) === null);
    assertTrue(
        'HTTP CC OK: paridad otro tenant',
        findCostCenterByName($pdo, $tenantIds[1], $ccHttp) === null
    );

    $csrfDiv = runCatalogDeleteHandler([
        'script' => 'tenant_catalog_divisions.php',
        'action' => 'delete_dept',
        'user_id' => $sa['id'],
        'tenant_id' => $workTenant,
        'entity_id' => $divRow['id'],
        'csrf_post' => 'bad',
        'csrf_sess' => 'good',
        'superadmin' => true,
    ]);
    assertTrue('CSRF Div: HTTP 403', $csrfDiv['http'] === 403 || str_contains($csrfDiv['out'], 'CSRF'));
    assertTrue(
        'CSRF Div: sin mutación',
        findDepartmentByName($pdo, $workTenant, $divHttp, 'estandar') !== null
    );

    runCatalogDeleteHandler([
        'script' => 'tenant_catalog_divisions.php',
        'action' => 'delete_dept',
        'user_id' => $sa['id'],
        'tenant_id' => $workTenant,
        'entity_id' => $divRow['id'],
        'csrf_post' => 'ok-csrf-div',
        'csrf_sess' => 'ok-csrf-div',
        'superadmin' => true,
    ]);
    assertTrue(
        'HTTP Div OK: eliminado',
        findDepartmentByName($pdo, $workTenant, $divHttp, 'estandar') === null
        && findDepartmentByName($pdo, $tenantIds[1], $divHttp, 'estandar') === null
    );

    runCatalogDeleteHandler([
        'script' => 'tenant_catalog_coordinations.php',
        'action' => 'delete_dept',
        'user_id' => $sa['id'],
        'tenant_id' => $workTenant,
        'entity_id' => $coordRow['id'],
        'csrf_post' => 'ok-csrf-coord',
        'csrf_sess' => 'ok-csrf-coord',
        'superadmin' => true,
    ]);
    assertTrue(
        'HTTP Coord OK: eliminado',
        findDepartmentByName($pdo, $workTenant, $coordHttp, 'coordinacion') === null
        && findDepartmentByName($pdo, $tenantIds[1], $coordHttp, 'coordinacion') === null
    );
}

echo "\nResultado: {$ok} ok, {$fail} fail\n";
exit($fail > 0 ? 1 : 0);
