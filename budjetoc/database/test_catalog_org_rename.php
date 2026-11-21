<?php

declare(strict_types=1);

/**
 * CLI — rename multi-tenant de catálogo org (historia 2 / CAP-1 edit).
 * Uso: php database/test_catalog_org_rename.php
 *
 * Cubre matriz I/O: OK, conflicto, vacío, noop, desalineación,
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
 * Ejecuta una página de catálogo org en subproceso con sesión/POST controlados.
 *
 * @param array{
 *   script: string,
 *   action: string,
 *   user_id: int,
 *   tenant_id: int,
 *   entity_id: int,
 *   new_name: string,
 *   csrf_post: string,
 *   csrf_sess: string,
 *   superadmin?: bool,
 *   tenant_admin?: bool
 * } $opts
 * @return array{out: string, code: int, http: int}
 */
function runCatalogRenameHandler(array $opts): array
{
    $probe = dirname(__DIR__) . '/database/_tmp_catalog_rename_handler.php';
    $payloadFile = dirname(__DIR__) . '/database/_tmp_catalog_rename_payload.json';
    file_put_contents($payloadFile, json_encode($opts, JSON_THROW_ON_ERROR));

    $probeSrc = <<<'PHP'
<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';
$opts = json_decode((string) file_get_contents(__DIR__ . '/_tmp_catalog_rename_payload.json'), true, 512, JSON_THROW_ON_ERROR);
@unlink(__DIR__ . '/_tmp_catalog_rename_payload.json');

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
    'new_name' => (string) $opts['new_name'],
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

echo "=== Rename catálogo org multi-tenant (CAP-1 edit) ===\n\n";

$tenantIds = CatalogService::tenantIds($pdo, true);
if (count($tenantIds) < 2) {
    fail('Se requieren al menos 2 tenants activos');
    echo "\nResultado: {$ok} ok, {$fail} fail\n";
    exit(1);
}
pass('tenants activos=' . count($tenantIds) . ' ids=' . implode(',', $tenantIds));

$suffix = bin2hex(random_bytes(3));
$ccOld = 'ZZ Ren CC ' . $suffix;
$ccNew = 'ZZ Ren CC New ' . $suffix;
$ccHttp = 'ZZ Ren CC Http ' . $suffix;
$ccConflict = 'ZZ Ren CC Conflict ' . $suffix;
$divOld = 'ZZ Ren Div ' . $suffix;
$divNew = 'ZZ Ren Div New ' . $suffix;
$divHttp = 'ZZ Ren Div Http ' . $suffix;
$divConflict = 'ZZ Ren Div Conflict ' . $suffix;
$coordOld = 'ZZ Ren Coord ' . $suffix;
$coordNew = 'ZZ Ren Coord New ' . $suffix;
$coordHttp = 'ZZ Ren Coord Http ' . $suffix;
$misalignName = 'ZZ Ren Misalign ' . $suffix;
$misalignDiv = 'ZZ Ren Misalign Div ' . $suffix;

$createdCcIds = [];
$createdDeptIds = [];
$createdUserIds = [];

$cleanup = static function () use (
    $pdo,
    $tenantIds,
    &$createdCcIds,
    &$createdDeptIds,
    &$createdUserIds,
    $ccOld,
    $ccNew,
    $ccHttp,
    $ccConflict,
    $divOld,
    $divNew,
    $divHttp,
    $divConflict,
    $coordOld,
    $coordNew,
    $coordHttp,
    $misalignName,
    $misalignDiv
): void {
    $namesCc = [$ccOld, $ccNew, $ccHttp, $ccConflict, $misalignName];
    $namesDiv = [$divOld, $divNew, $divHttp, $divConflict, $misalignDiv];
    $namesCoord = [$coordOld, $coordNew, $coordHttp];
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

// Seed aligned catalog rows
$idsByTenantCc = [];
$idsByTenantDiv = [];
$idsByTenantCoord = [];
foreach ($tenantIds as $tid) {
    $pdo->prepare('INSERT INTO cost_centers (tenant_id, name) VALUES (?, ?)')->execute([$tid, $ccOld]);
    $idsByTenantCc[$tid] = (int) $pdo->lastInsertId();
    $createdCcIds[] = $idsByTenantCc[$tid];

    $pdo->prepare('INSERT INTO cost_centers (tenant_id, name) VALUES (?, ?)')->execute([$tid, $ccConflict]);
    $createdCcIds[] = (int) $pdo->lastInsertId();

    $pdo->prepare("INSERT INTO departments (tenant_id, name, type) VALUES (?, ?, 'estandar')")
        ->execute([$tid, $divOld]);
    $idsByTenantDiv[$tid] = (int) $pdo->lastInsertId();
    $createdDeptIds[] = $idsByTenantDiv[$tid];

    $pdo->prepare("INSERT INTO departments (tenant_id, name, type) VALUES (?, ?, 'estandar')")
        ->execute([$tid, $divConflict]);
    $createdDeptIds[] = (int) $pdo->lastInsertId();

    $pdo->prepare("INSERT INTO departments (tenant_id, name, type) VALUES (?, ?, 'coordinacion')")
        ->execute([$tid, $coordOld]);
    $idsByTenantCoord[$tid] = (int) $pdo->lastInsertId();
    $createdDeptIds[] = $idsByTenantCoord[$tid];
}
pass('seed CC/div/coord alineado en todos los tenants activos');

// --- CC: Nuevo vacío ---
$r = CatalogService::renameCostCenterAcrossTenants($pdo, $ccOld, '   ');
assertTrue('CC vacío: ok=false', $r['ok'] === false);
assertTrue('CC vacío: menciona requerido', isset($r['error']) && str_contains($r['error'], 'requerido'));
assertTrue('CC vacío: sin mutación', findCostCenterByName($pdo, $tenantIds[0], $ccOld) !== null);

// --- CC: Noop ---
$r = CatalogService::renameCostCenterAcrossTenants($pdo, $ccOld, '  ' . $ccOld . '  ');
assertTrue('CC noop: ok=true', $r['ok'] === true);
assertTrue('CC noop: changed=false', ($r['changed'] ?? true) === false);
assertTrue('CC noop: id intacto', (findCostCenterByName($pdo, $tenantIds[0], $ccOld)['id'] ?? 0) === $idsByTenantCc[$tenantIds[0]]);

// --- CC: Conflicto ---
$r = CatalogService::renameCostCenterAcrossTenants($pdo, $ccOld, $ccConflict);
assertTrue('CC conflicto: ok=false', $r['ok'] === false);
assertTrue('CC conflicto: menciona conflicto', isset($r['error']) && stripos($r['error'], 'conflicto') !== false);
assertTrue('CC conflicto: sin mutación', findCostCenterByName($pdo, $tenantIds[0], $ccOld) !== null);

// --- CC: Desalineación ---
$pdo->prepare('INSERT INTO cost_centers (tenant_id, name) VALUES (?, ?)')->execute([$tenantIds[0], $misalignName]);
$createdCcIds[] = (int) $pdo->lastInsertId();
$r = CatalogService::renameCostCenterAcrossTenants($pdo, $misalignName, 'ZZ Ren Misalign New ' . $suffix);
assertTrue('CC desalineación: ok=false', $r['ok'] === false);
assertTrue(
    'CC desalineación: menciona desalineación',
    isset($r['error']) && (stripos($r['error'], 'desaline') !== false || stripos($r['error'], 'sync') !== false)
);
assertTrue('CC desalineación: sin mutación en origen', findCostCenterByName($pdo, $tenantIds[0], $misalignName) !== null);
assertTrue(
    'CC desalineación: no insertó en otros',
    findCostCenterByName($pdo, $tenantIds[1], 'ZZ Ren Misalign New ' . $suffix) === null
);

// --- Div (estandar): vacío / noop / conflicto / desalineación ---
$r = CatalogService::renameDepartmentAcrossTenants($pdo, $divOld, '   ', 'estandar');
assertTrue('Div vacío: ok=false', $r['ok'] === false);
assertTrue('Div vacío: menciona requerido', isset($r['error']) && str_contains($r['error'], 'requerido'));
assertTrue('Div vacío: sin mutación', findDepartmentByName($pdo, $tenantIds[0], $divOld, 'estandar') !== null);

$r = CatalogService::renameDepartmentAcrossTenants($pdo, $divOld, '  ' . $divOld . '  ', 'estandar');
assertTrue('Div noop: ok=true', $r['ok'] === true);
assertTrue('Div noop: changed=false', ($r['changed'] ?? true) === false);
assertTrue(
    'Div noop: id intacto',
    (findDepartmentByName($pdo, $tenantIds[0], $divOld, 'estandar')['id'] ?? 0) === $idsByTenantDiv[$tenantIds[0]]
);

$r = CatalogService::renameDepartmentAcrossTenants($pdo, $divOld, $divConflict, 'estandar');
assertTrue('Div conflicto: ok=false', $r['ok'] === false);
assertTrue('Div conflicto: menciona conflicto', isset($r['error']) && stripos($r['error'], 'conflicto') !== false);
assertTrue('Div conflicto: sin mutación', findDepartmentByName($pdo, $tenantIds[0], $divOld, 'estandar') !== null);

$pdo->prepare("INSERT INTO departments (tenant_id, name, type) VALUES (?, ?, 'estandar')")
    ->execute([$tenantIds[0], $misalignDiv]);
$createdDeptIds[] = (int) $pdo->lastInsertId();
$r = CatalogService::renameDepartmentAcrossTenants($pdo, $misalignDiv, 'ZZ Ren Misalign Div New ' . $suffix, 'estandar');
assertTrue('Div desalineación: ok=false', $r['ok'] === false);
assertTrue(
    'Div desalineación: menciona desalineación',
    isset($r['error']) && (stripos($r['error'], 'desaline') !== false || stripos($r['error'], 'sync') !== false)
);
assertTrue(
    'Div desalineación: sin mutación en origen',
    findDepartmentByName($pdo, $tenantIds[0], $misalignDiv, 'estandar') !== null
);

// --- Rename OK CC ---
$r = CatalogService::renameCostCenterAcrossTenants($pdo, $ccOld, $ccNew);
assertTrue('CC OK: ok=true', $r['ok'] === true && ($r['changed'] ?? false) === true);
foreach ($tenantIds as $tid) {
    $row = findCostCenterByName($pdo, $tid, $ccNew);
    assertTrue("CC OK: tenant {$tid} tiene nuevo nombre", $row !== null);
    assertTrue("CC OK: tenant {$tid} id intacto", $row !== null && $row['id'] === $idsByTenantCc[$tid]);
    assertTrue("CC OK: tenant {$tid} sin nombre viejo", findCostCenterByName($pdo, $tid, $ccOld) === null);
}

// --- Rename OK división ---
$r = CatalogService::renameDepartmentAcrossTenants($pdo, $divOld, $divNew, 'estandar');
assertTrue('Div OK: ok=true', $r['ok'] === true && ($r['changed'] ?? false) === true);
foreach ($tenantIds as $tid) {
    $row = findDepartmentByName($pdo, $tid, $divNew, 'estandar');
    assertTrue("Div OK: tenant {$tid} nuevo nombre", $row !== null);
    assertTrue("Div OK: tenant {$tid} id intacto", $row !== null && $row['id'] === $idsByTenantDiv[$tid]);
}

// --- Rename OK coordinación ---
$r = CatalogService::renameDepartmentAcrossTenants($pdo, $coordOld, $coordNew, 'coordinacion');
assertTrue('Coord OK: ok=true', $r['ok'] === true && ($r['changed'] ?? false) === true);
foreach ($tenantIds as $tid) {
    $row = findDepartmentByName($pdo, $tid, $coordNew, 'coordinacion');
    assertTrue("Coord OK: tenant {$tid} nuevo nombre", $row !== null);
    assertTrue("Coord OK: tenant {$tid} id intacto", $row !== null && $row['id'] === $idsByTenantCoord[$tid]);
}

// --- CSRF inválido / no–super-admin / HTTP success (handlers) ---
$workTenant = $tenantIds[0];
$ccRow = findCostCenterByName($pdo, $workTenant, $ccNew);
$divRow = findDepartmentByName($pdo, $workTenant, $divNew, 'estandar');
$coordRow = findDepartmentByName($pdo, $workTenant, $coordNew, 'coordinacion');
if ($ccRow === null || $divRow === null || $coordRow === null) {
    fail('fixture CC/div/coord para handler HTTP');
} else {
    $sa = createTestUser($pdo, 'sa_rename');
    $createdUserIds[] = $sa['id'];
    $pdo->prepare('INSERT IGNORE INTO super_admins (user_id) VALUES (?)')->execute([$sa['id']]);

    $ta = createTestUser($pdo, 'ta_rename');
    $createdUserIds[] = $ta['id'];
    $pdo->prepare(
        "INSERT INTO user_tenant_roles (user_id, tenant_id, app, role) VALUES (?, ?, 'budget', 'tenant_admin')"
    )->execute([$ta['id'], $workTenant]);

    $csrfBad = runCatalogRenameHandler([
        'script' => 'tenant_catalog_cost_centers.php',
        'action' => 'rename_cc',
        'user_id' => $sa['id'],
        'tenant_id' => $workTenant,
        'entity_id' => $ccRow['id'],
        'new_name' => 'ZZ CSRF Should Fail ' . $suffix,
        'csrf_post' => 'bad-token',
        'csrf_sess' => 'good-token',
        'superadmin' => true,
    ]);
    assertTrue('CSRF CC: HTTP 403', $csrfBad['http'] === 403 || str_contains($csrfBad['out'], 'CSRF'));
    assertTrue(
        'CSRF CC: sin mutación',
        findCostCenterByName($pdo, $workTenant, 'ZZ CSRF Should Fail ' . $suffix) === null
        && findCostCenterByName($pdo, $workTenant, $ccNew) !== null
    );

    $nsa = runCatalogRenameHandler([
        'script' => 'tenant_catalog_cost_centers.php',
        'action' => 'rename_cc',
        'user_id' => $ta['id'],
        'tenant_id' => $workTenant,
        'entity_id' => $ccRow['id'],
        'new_name' => 'ZZ NSA Should Fail ' . $suffix,
        'csrf_post' => 'tok',
        'csrf_sess' => 'tok',
        'superadmin' => false,
        'tenant_admin' => true,
    ]);
    $nsaBlocked = $nsa['http'] === 302
        || stripos($nsa['out'], 'login') !== false
        || stripos($nsa['out'], 'Location:') !== false
        || !str_contains($nsa['out'], 'ZZ NSA Should Fail');
    assertTrue('no–SA: no alcanza mutación exitosa', $nsaBlocked);
    assertTrue(
        'no–SA: sin mutación',
        findCostCenterByName($pdo, $workTenant, 'ZZ NSA Should Fail ' . $suffix) === null
        && findCostCenterByName($pdo, $workTenant, $ccNew) !== null
    );

    // SA + CSRF válido: rename CC vía HTTP
    $ccIdBefore = $ccRow['id'];
    $httpCc = runCatalogRenameHandler([
        'script' => 'tenant_catalog_cost_centers.php',
        'action' => 'rename_cc',
        'user_id' => $sa['id'],
        'tenant_id' => $workTenant,
        'entity_id' => $ccIdBefore,
        'new_name' => $ccHttp,
        'csrf_post' => 'ok-csrf',
        'csrf_sess' => 'ok-csrf',
        'superadmin' => true,
    ]);
    $ccAfter = findCostCenterByName($pdo, $workTenant, $ccHttp);
    assertTrue(
        'HTTP CC OK: mutación',
        $ccAfter !== null
        && $ccAfter['id'] === $ccIdBefore
        && findCostCenterByName($pdo, $workTenant, $ccNew) === null
    );
    assertTrue(
        'HTTP CC OK: paridad otro tenant',
        findCostCenterByName($pdo, $tenantIds[1], $ccHttp) !== null
        && findCostCenterByName($pdo, $tenantIds[1], $ccNew) === null
    );

    // CSRF en divisions (barato)
    $csrfDiv = runCatalogRenameHandler([
        'script' => 'tenant_catalog_divisions.php',
        'action' => 'rename_dept',
        'user_id' => $sa['id'],
        'tenant_id' => $workTenant,
        'entity_id' => $divRow['id'],
        'new_name' => 'ZZ Div CSRF Fail ' . $suffix,
        'csrf_post' => 'bad',
        'csrf_sess' => 'good',
        'superadmin' => true,
    ]);
    assertTrue('CSRF Div: HTTP 403', $csrfDiv['http'] === 403 || str_contains($csrfDiv['out'], 'CSRF'));
    assertTrue(
        'CSRF Div: sin mutación',
        findDepartmentByName($pdo, $workTenant, 'ZZ Div CSRF Fail ' . $suffix, 'estandar') === null
        && findDepartmentByName($pdo, $workTenant, $divNew, 'estandar') !== null
    );

    // SA + CSRF: divisions success
    $divIdBefore = $divRow['id'];
    runCatalogRenameHandler([
        'script' => 'tenant_catalog_divisions.php',
        'action' => 'rename_dept',
        'user_id' => $sa['id'],
        'tenant_id' => $workTenant,
        'entity_id' => $divIdBefore,
        'new_name' => $divHttp,
        'csrf_post' => 'ok-csrf-div',
        'csrf_sess' => 'ok-csrf-div',
        'superadmin' => true,
    ]);
    $divAfter = findDepartmentByName($pdo, $workTenant, $divHttp, 'estandar');
    assertTrue(
        'HTTP Div OK: mutación',
        $divAfter !== null
        && $divAfter['id'] === $divIdBefore
        && findDepartmentByName($pdo, $workTenant, $divNew, 'estandar') === null
    );

    // SA + CSRF: coordinations success
    $coordIdBefore = $coordRow['id'];
    runCatalogRenameHandler([
        'script' => 'tenant_catalog_coordinations.php',
        'action' => 'rename_dept',
        'user_id' => $sa['id'],
        'tenant_id' => $workTenant,
        'entity_id' => $coordIdBefore,
        'new_name' => $coordHttp,
        'csrf_post' => 'ok-csrf-coord',
        'csrf_sess' => 'ok-csrf-coord',
        'superadmin' => true,
    ]);
    $coordAfter = findDepartmentByName($pdo, $workTenant, $coordHttp, 'coordinacion');
    assertTrue(
        'HTTP Coord OK: mutación',
        $coordAfter !== null
        && $coordAfter['id'] === $coordIdBefore
        && findDepartmentByName($pdo, $workTenant, $coordNew, 'coordinacion') === null
    );
}

echo "\nResultado: {$ok} ok, {$fail} fail\n";
exit($fail > 0 ? 1 : 0);
