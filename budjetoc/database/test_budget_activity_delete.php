<?php

declare(strict_types=1);

/**
 * CLI — eliminar actividades de Presupuesto (historia 1 / CAP-3 + CAP-4).
 * Uso: php database/test_budget_activity_delete.php
 *
 * Cubre matriz I/O: encargado OK/fail (año, ajena), SA/tenant_admin OK, OC block,
 * OC anulada con allocation, legacy created_by, handler HTTP, canDeleteFlagsForHeaders.
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

function headerExists(PDO $pdo, int $tenantId, int $headerId): bool
{
    $st = $pdo->prepare('SELECT 1 FROM budget_headers WHERE id = ? AND tenant_id = ? LIMIT 1');
    $st->execute([$headerId, $tenantId]);

    return (bool) $st->fetchColumn();
}

function detailCount(PDO $pdo, int $headerId): int
{
    $st = $pdo->prepare('SELECT COUNT(*) FROM budget_details WHERE budget_header_id = ?');
    $st->execute([$headerId]);

    return (int) $st->fetchColumn();
}

/**
 * Ejecuta activity_delete.php en subproceso con sesion/POST controlados.
 *
 * @param array{user_id: int, tenant_id: int, header_id: int, csrf_post: string, csrf_sess: string, superadmin?: bool} $opts
 * @return array{out: string, code: int}
 */
function runActivityDeleteHandler(array $opts): array
{
    $probe = dirname(__DIR__) . '/database/_tmp_activity_delete_handler.php';
    $payload = json_encode($opts, JSON_THROW_ON_ERROR);
    $payloadFile = dirname(__DIR__) . '/database/_tmp_activity_delete_payload.json';
    file_put_contents($payloadFile, $payload);

    $probeSrc = <<<'PHP'
<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';
$opts = json_decode((string) file_get_contents(__DIR__ . '/_tmp_activity_delete_payload.json'), true, 512, JSON_THROW_ON_ERROR);
@unlink(__DIR__ . '/_tmp_activity_delete_payload.json');

register_shutdown_function(static function (): void {
    echo "\n__HTTP__" . (string) http_response_code() . "\n";
});

Auth::startRealm(Auth::REALM_BUDGET);
Auth::loginUser(
    (int) $opts['user_id'],
    Auth::REALM_BUDGET,
    (int) $opts['tenant_id'],
    !empty($opts['superadmin'])
);
$_SESSION['_csrf'] = (string) $opts['csrf_sess'];
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = [
    'id' => (int) $opts['header_id'],
    '_csrf' => (string) $opts['csrf_post'],
    'return' => 'index.php',
];

require dirname(__DIR__) . '/public/budget/activity_delete.php';
PHP;
    file_put_contents($probe, $probeSrc);

    $phpBin = PHP_BINARY !== '' ? PHP_BINARY : 'php';
    $out = [];
    $code = 0;
    exec(escapeshellarg($phpBin) . ' ' . escapeshellarg($probe) . ' 2>&1', $out, $code);
    @unlink($probe);
    @unlink($payloadFile);

    return ['out' => implode("\n", $out), 'code' => $code];
}

echo "=== Eliminar actividades Presupuesto (CAP-3 / CAP-4) ===\n\n";

$st = $pdo->query(
    "SELECT t.id AS tenant_id,
            (SELECT id FROM departments d WHERE d.tenant_id = t.id AND d.type = 'estandar' ORDER BY d.id ASC LIMIT 1) AS div_id,
            (SELECT id FROM departments d2 WHERE d2.tenant_id = t.id AND d2.type = 'estandar' AND d2.id <> (
                SELECT id FROM departments d3 WHERE d3.tenant_id = t.id AND d3.type = 'estandar' ORDER BY d3.id ASC LIMIT 1
            ) ORDER BY d2.id ASC LIMIT 1) AS other_div_id,
            (SELECT id FROM cost_centers cc WHERE cc.tenant_id = t.id ORDER BY cc.id ASC LIMIT 1) AS cc_id,
            (SELECT id FROM activities a WHERE a.tenant_id = t.id ORDER BY a.id ASC LIMIT 1) AS activity_id,
            (SELECT id FROM account_codes ac WHERE ac.tenant_id = t.id ORDER BY ac.id ASC LIMIT 1) AS account_code_id,
            (SELECT id FROM suppliers s WHERE s.tenant_id = t.id ORDER BY s.id ASC LIMIT 1) AS supplier_id
     FROM tenants t
     HAVING div_id IS NOT NULL AND cc_id IS NOT NULL AND activity_id IS NOT NULL AND account_code_id IS NOT NULL
     ORDER BY t.id ASC
     LIMIT 1"
);
$fx = $st->fetch(PDO::FETCH_ASSOC);
if ($fx === false) {
    fail('Sin fixtures (tenant/division/CC/actividad/cuenta)');
    echo "\nResultado: {$ok} ok, {$fail} fail\n";
    exit(1);
}

$tenantId = (int) $fx['tenant_id'];
$divId = (int) $fx['div_id'];
$otherDivId = (int) ($fx['other_div_id'] ?? 0);
$ccId = (int) $fx['cc_id'];
$activityId = (int) $fx['activity_id'];
$accountCodeId = (int) $fx['account_code_id'];
$supplierId = (int) ($fx['supplier_id'] ?? 0);
$year = (int) date('Y');

pass("tenant_id={$tenantId} div={$divId} other_div={$otherDivId}");

$createdDepartmentIds = [];
$createdSupplierId = 0;
$createdHeaderIds = [];
$createdUserIds = [];
$createdPoIds = [];
$policyRowsToRestore = [];

if ($otherDivId <= 0) {
    $pdo->prepare(
        "INSERT INTO departments (tenant_id, name, type, parent_id) VALUES (?, ?, 'estandar', NULL)"
    )->execute([$tenantId, 'Test Div Delete ' . bin2hex(random_bytes(2))]);
    $otherDivId = (int) $pdo->lastInsertId();
    $createdDepartmentIds[] = $otherDivId;
    pass("other_div creada id={$otherDivId}");
}

if ($supplierId <= 0) {
    $createdSupplierId = SupplierService::create(
        $pdo,
        $tenantId,
        'Proveedor Test Delete Activity',
        '',
        '',
        ''
    );
    $supplierId = $createdSupplierId;
    pass("supplier creado id={$supplierId}");
}

$makeActivity = static function (
    PDO $pdo,
    int $tenantId,
    int $year,
    int $activityId,
    int $ccId,
    int $accountCodeId,
    int $createdBy,
) use (&$createdHeaderIds): int {
    $header = [
        'month' => 3,
        'cost_center_id' => $ccId,
        'activity_id' => $activityId,
        'division_id' => $createdBy,
        'department_id' => null,
        'observations' => 'test delete activity',
    ];
    $details = [[
        'account_code_id' => $accountCodeId,
        'quantity' => 1.0,
        'unit_price' => 1000.0,
        'observation' => 'linea test',
    ]];
    $hid = BudgetService::createActivity($pdo, $tenantId, $header, $details, $year, $createdBy);
    $createdHeaderIds[] = $hid;

    return $hid;
};

$setPolicy = static function (PDO $pdo, int $tenantId, int $deptId, int $year, int $allow) use (&$policyRowsToRestore): void {
    $st = $pdo->prepare(
        'SELECT allow_edit FROM tenant_department_edit_policy WHERE tenant_id = ? AND year = ? AND department_id = ?'
    );
    $st->execute([$tenantId, $year, $deptId]);
    $prev = $st->fetchColumn();
    $policyRowsToRestore[] = [
        'tenant_id' => $tenantId,
        'year' => $year,
        'department_id' => $deptId,
        'had_row' => $prev !== false,
        'allow_edit' => $prev !== false ? (int) $prev : null,
    ];
    if ($prev === false) {
        $pdo->prepare(
            'INSERT INTO tenant_department_edit_policy (tenant_id, year, department_id, allow_edit) VALUES (?, ?, ?, ?)'
        )->execute([$tenantId, $year, $deptId, $allow]);
    } else {
        $pdo->prepare(
            'UPDATE tenant_department_edit_policy SET allow_edit = ? WHERE tenant_id = ? AND year = ? AND department_id = ?'
        )->execute([$allow, $tenantId, $year, $deptId]);
    }
};

try {
    // --- Usuarios ---
    $encargado = createTestUser($pdo, 'enc-del');
    $encargadoOther = createTestUser($pdo, 'enc-other');
    $tenantAdmin = createTestUser($pdo, 'ta-del');
    $saUser = createTestUser($pdo, 'sa-del');
    $createdUserIds = [$encargado['id'], $encargadoOther['id'], $tenantAdmin['id'], $saUser['id']];

    $pdo->prepare(
        "INSERT INTO user_tenant_roles (user_id, tenant_id, app, role) VALUES (?, ?, 'budget', 'encargado')"
    )->execute([$encargado['id'], $tenantId]);
    $pdo->prepare(
        "INSERT INTO user_tenant_roles (user_id, tenant_id, app, role) VALUES (?, ?, 'budget', 'encargado')"
    )->execute([$encargadoOther['id'], $tenantId]);
    $pdo->prepare(
        "INSERT INTO user_tenant_roles (user_id, tenant_id, app, role) VALUES (?, ?, 'budget', 'tenant_admin')"
    )->execute([$tenantAdmin['id'], $tenantId]);
    $pdo->prepare('INSERT INTO super_admins (user_id) VALUES (?)')->execute([$saUser['id']]);

    $pdo->prepare(
        'INSERT INTO user_department_permissions (user_id, tenant_id, department_id, can_edit) VALUES (?, ?, ?, 1)'
    )->execute([$encargado['id'], $tenantId, $divId]);
    $pdo->prepare(
        'INSERT INTO user_department_permissions (user_id, tenant_id, department_id, can_edit) VALUES (?, ?, ?, 1)'
    )->execute([$encargadoOther['id'], $tenantId, $otherDivId]);

    $setPolicy($pdo, $tenantId, $divId, $year, 1);
    $setPolicy($pdo, $tenantId, $otherDivId, $year, 0);

    // --- Fixtures para flags ---
    $hidOwn = $makeActivity($pdo, $tenantId, $year, $activityId, $ccId, $accountCodeId, $divId);
    $hidYearClosed = $makeActivity($pdo, $tenantId, $year, $activityId, $ccId, $accountCodeId, $otherDivId);
    $hidAjena = $makeActivity($pdo, $tenantId, $year, $activityId, $ccId, $accountCodeId, $otherDivId);

    $hdrOwn = BudgetService::getHeader($pdo, $tenantId, $hidOwn);
    $hdrYearClosed = BudgetService::getHeader($pdo, $tenantId, $hidYearClosed);
    $hdrAjena = BudgetService::getHeader($pdo, $tenantId, $hidAjena);

    $flagsEnc = UserPermissions::canDeleteFlagsForHeaders(
        $pdo,
        $encargado['id'],
        $tenantId,
        [$hdrOwn, $hdrAjena, $hdrYearClosed],
        $year
    );
    assertTrue('canDeleteFlags propia → true', !empty($flagsEnc[$hidOwn]));
    assertTrue('canDeleteFlags ajena → false', empty($flagsEnc[$hidAjena]));
    assertTrue('canDeleteFlags año cerrado (other) → false', empty($flagsEnc[$hidYearClosed]));

    $flagsOtherClosed = UserPermissions::canDeleteFlagsForHeaders(
        $pdo,
        $encargadoOther['id'],
        $tenantId,
        [$hdrYearClosed],
        $year
    );
    assertTrue(
        'canDeleteFlags encargado other con allow_edit=0 → false',
        empty($flagsOtherClosed[$hidYearClosed])
    );

    $flagsSa = UserPermissions::canDeleteFlagsForHeaders(
        $pdo,
        $saUser['id'],
        $tenantId,
        [$hdrAjena, $hdrYearClosed],
        $year
    );
    assertTrue('canDeleteFlags SA ajena → true', !empty($flagsSa[$hidAjena]));
    assertTrue('canDeleteFlags SA año cerrado → true', !empty($flagsSa[$hidYearClosed]));

    $flagsTa = UserPermissions::canDeleteFlagsForHeaders(
        $pdo,
        $tenantAdmin['id'],
        $tenantId,
        [$hdrAjena, $hdrYearClosed],
        $year
    );
    assertTrue('canDeleteFlags tenant_admin ajena → true', !empty($flagsTa[$hidAjena]));
    assertTrue('canDeleteFlags tenant_admin año cerrado → true', !empty($flagsTa[$hidYearClosed]));

    // --- Encargado OK (servicio) ---
    assertTrue(
        'encargado puede borrar propia con año editable',
        UserPermissions::canDeleteActivityHeader($pdo, $encargado['id'], $tenantId, $hdrOwn, $year)
    );
    BudgetService::deleteActivity($pdo, $tenantId, $hidOwn);
    assertTrue('encargado OK → header eliminado', !headerExists($pdo, $tenantId, $hidOwn));
    assertTrue('encargado OK → details CASCADE', detailCount($pdo, $hidOwn) === 0);
    $createdHeaderIds = array_values(array_filter($createdHeaderIds, static fn (int $id): bool => $id !== $hidOwn));

    // --- Encargado sin permiso año ---
    assertTrue(
        'encargado other_div con allow_edit=0 → no delete',
        !UserPermissions::canDeleteActivityHeader($pdo, $encargadoOther['id'], $tenantId, $hdrYearClosed, $year)
    );
    assertTrue('header año cerrado permanece', headerExists($pdo, $tenantId, $hidYearClosed));

    // --- Encargado ajena (año editable en other) ---
    $setPolicy($pdo, $tenantId, $otherDivId, $year, 1);
    $hdrAjena = BudgetService::getHeader($pdo, $tenantId, $hidAjena);
    assertTrue(
        'encargado ajena → no delete aunque año editable',
        !UserPermissions::canDeleteActivityHeader($pdo, $encargado['id'], $tenantId, $hdrAjena, $year)
    );
    assertTrue('header ajena permanece', headerExists($pdo, $tenantId, $hidAjena));

    // --- Legacy: created_by null, department_id = owning dept ---
    $hidLegacy = $makeActivity($pdo, $tenantId, $year, $activityId, $ccId, $accountCodeId, $divId);
    $pdo->prepare(
        'UPDATE budget_headers SET created_by = NULL, department_id = ?, division_id = ? WHERE id = ? AND tenant_id = ?'
    )->execute([$divId, $divId, $hidLegacy, $tenantId]);
    $hdrLegacy = BudgetService::getHeader($pdo, $tenantId, $hidLegacy);
    assertTrue('legacy created_by null/0', (int) ($hdrLegacy['created_by'] ?? 0) === 0);
    assertTrue('legacy department_id = owning', (int) ($hdrLegacy['department_id'] ?? 0) === $divId);
    assertTrue(
        'encargado puede borrar legacy propia (department_id)',
        UserPermissions::canDeleteActivityHeader($pdo, $encargado['id'], $tenantId, $hdrLegacy, $year)
    );
    $flagsLegacy = UserPermissions::canDeleteFlagsForHeaders(
        $pdo,
        $encargado['id'],
        $tenantId,
        [$hdrLegacy],
        $year
    );
    assertTrue('canDeleteFlags legacy propia → true', !empty($flagsLegacy[$hidLegacy]));
    BudgetService::deleteActivity($pdo, $tenantId, $hidLegacy);
    assertTrue('legacy OK → eliminada', !headerExists($pdo, $tenantId, $hidLegacy));
    $createdHeaderIds = array_values(array_filter($createdHeaderIds, static fn (int $id): bool => $id !== $hidLegacy));

    // --- Tenant admin / SA (servicio) ---
    $setPolicy($pdo, $tenantId, $otherDivId, $year, 0);
    $hidTa = $makeActivity($pdo, $tenantId, $year, $activityId, $ccId, $accountCodeId, $otherDivId);
    $hdrTa = BudgetService::getHeader($pdo, $tenantId, $hidTa);
    assertTrue(
        'tenant_admin puede borrar ajena con año cerrado',
        UserPermissions::canDeleteActivityHeader($pdo, $tenantAdmin['id'], $tenantId, $hdrTa, $year)
    );
    BudgetService::deleteActivity($pdo, $tenantId, $hidTa);
    assertTrue('tenant_admin OK → eliminada', !headerExists($pdo, $tenantId, $hidTa));
    $createdHeaderIds = array_values(array_filter($createdHeaderIds, static fn (int $id): bool => $id !== $hidTa));

    $hidSa = $makeActivity($pdo, $tenantId, $year, $activityId, $ccId, $accountCodeId, $otherDivId);
    $hdrSa = BudgetService::getHeader($pdo, $tenantId, $hidSa);
    assertTrue(
        'super admin puede borrar ajena con año cerrado',
        UserPermissions::canDeleteActivityHeader($pdo, $saUser['id'], $tenantId, $hdrSa, $year)
    );
    BudgetService::deleteActivity($pdo, $tenantId, $hidSa);
    assertTrue('super admin OK → eliminada', !headerExists($pdo, $tenantId, $hidSa));
    $createdHeaderIds = array_values(array_filter($createdHeaderIds, static fn (int $id): bool => $id !== $hidSa));

    // --- Bloqueo OC vigente ---
    $hidOc = $makeActivity($pdo, $tenantId, $year, $activityId, $ccId, $accountCodeId, $divId);
    $stDet = $pdo->prepare('SELECT id FROM budget_details WHERE budget_header_id = ? LIMIT 1');
    $stDet->execute([$hidOc]);
    $detailId = (int) $stDet->fetchColumn();
    $folio = (string) $year;
    $numOc = 900000 + random_int(1, 99999);
    $pdo->prepare(
        'INSERT INTO purchase_orders (tenant_id, folio_year, numero_oc, ordered_at, supplier_id, total_amount, status, note)
         VALUES (?, ?, ?, NOW(), ?, 100, 0, ?)'
    )->execute([$tenantId, $folio, $numOc, $supplierId, 'test delete oc block']);
    $poId = (int) $pdo->lastInsertId();
    $createdPoIds[] = $poId;
    $pdo->prepare(
        'INSERT INTO po_budget_allocations (tenant_id, purchase_order_id, budget_detail_id, amount, req_number)
         VALUES (?, ?, ?, 50.00, ?)'
    )->execute([$tenantId, $poId, $detailId, 'REQ-DEL']);
    assertTrue('headerUtilized > 0 con OC', BudgetService::headerUtilized($pdo, $hidOc) > 0);
    $ocBlocked = false;
    $ocMsg = '';
    try {
        BudgetService::deleteActivity($pdo, $tenantId, $hidOc);
    } catch (RuntimeException $e) {
        $ocBlocked = true;
        $ocMsg = $e->getMessage();
    }
    assertTrue('con OC → delete rechazado', $ocBlocked);
    assertTrue(
        'mensaje imputacion OC',
        str_contains(strtolower($ocMsg), 'imputacion') || str_contains(strtolower($ocMsg), 'orden de compra')
    );
    assertTrue('con OC → entidad intacta (header)', headerExists($pdo, $tenantId, $hidOc));
    assertTrue('con OC → entidad intacta (detail)', detailCount($pdo, $hidOc) === 1);

    // --- OC anulada: utilized==0 pero allocation permanece ---
    $hidAnul = $makeActivity($pdo, $tenantId, $year, $activityId, $ccId, $accountCodeId, $divId);
    $stDet->execute([$hidAnul]);
    $detailAnul = (int) $stDet->fetchColumn();
    $numOcAnul = 900000 + random_int(1, 99999);
    $pdo->prepare(
        'INSERT INTO purchase_orders (tenant_id, folio_year, numero_oc, ordered_at, supplier_id, total_amount, status, note)
         VALUES (?, ?, ?, NOW(), ?, 100, ?, ?)'
    )->execute([
        $tenantId,
        $folio,
        $numOcAnul,
        $supplierId,
        PurchaseOrderStatus::ANULADA,
        'test delete oc anulada',
    ]);
    $poAnulId = (int) $pdo->lastInsertId();
    $createdPoIds[] = $poAnulId;
    $pdo->prepare(
        'INSERT INTO po_budget_allocations (tenant_id, purchase_order_id, budget_detail_id, amount, req_number)
         VALUES (?, ?, ?, 25.00, ?)'
    )->execute([$tenantId, $poAnulId, $detailAnul, 'REQ-ANUL']);
    assertTrue('anulada → headerUtilized == 0', BudgetService::headerUtilized($pdo, $hidAnul) == 0.0);
    $anulBlocked = false;
    $anulMsg = '';
    try {
        BudgetService::deleteActivity($pdo, $tenantId, $hidAnul);
    } catch (RuntimeException $e) {
        $anulBlocked = true;
        $anulMsg = $e->getMessage();
    }
    assertTrue('anulada con allocation → delete rechazado', $anulBlocked);
    assertTrue(
        'anulada → mensaje OC',
        str_contains(strtolower($anulMsg), 'imputacion') || str_contains(strtolower($anulMsg), 'orden de compra')
    );
    assertTrue('anulada → header intacto', headerExists($pdo, $tenantId, $hidAnul));

    // --- Handler: CSRF invalido → 403 + sin mutacion ---
    $hidHttp = $makeActivity($pdo, $tenantId, $year, $activityId, $ccId, $accountCodeId, $divId);
    $csrfRun = runActivityDeleteHandler([
        'user_id' => $encargado['id'],
        'tenant_id' => $tenantId,
        'header_id' => $hidHttp,
        'csrf_post' => 'spoof',
        'csrf_sess' => 'real-token',
    ]);
    assertTrue(
        'handler CSRF invalido → mensaje CSRF',
        str_contains($csrfRun['out'], 'CSRF')
    );
    assertTrue(
        'handler CSRF invalido → HTTP 403',
        str_contains($csrfRun['out'], '__HTTP__403')
    );
    assertTrue('handler CSRF invalido → sin mutacion', headerExists($pdo, $tenantId, $hidHttp));

    // --- Handler: forbidden (ajena) → 403 + sin mutacion ---
    $forbidRun = runActivityDeleteHandler([
        'user_id' => $encargado['id'],
        'tenant_id' => $tenantId,
        'header_id' => $hidAjena,
        'csrf_post' => 'ok-token',
        'csrf_sess' => 'ok-token',
    ]);
    assertTrue(
        'handler forbidden → 403 permiso',
        str_contains($forbidRun['out'], '__HTTP__403')
            && (str_contains($forbidRun['out'], 'permiso') || str_contains($forbidRun['out'], 'solo lectura'))
    );
    assertTrue('handler forbidden → sin mutacion', headerExists($pdo, $tenantId, $hidAjena));

    // --- Handler: allowed → elimina ---
    $okRun = runActivityDeleteHandler([
        'user_id' => $encargado['id'],
        'tenant_id' => $tenantId,
        'header_id' => $hidHttp,
        'csrf_post' => 'ok-token',
        'csrf_sess' => 'ok-token',
    ]);
    assertTrue(
        'handler allowed → sin 403',
        !str_contains($okRun['out'], '__HTTP__403')
    );
    assertTrue('handler allowed → header eliminado', !headerExists($pdo, $tenantId, $hidHttp));
    $createdHeaderIds = array_values(array_filter($createdHeaderIds, static fn (int $id): bool => $id !== $hidHttp));

    // UI gates presentes
    $viewSrc = (string) file_get_contents(dirname(__DIR__) . '/public/budget/activity_view.php');
    $dashSrc = (string) file_get_contents(dirname(__DIR__) . '/public/budget/inc/dashboard_view.php');
    assertTrue('activity_view tiene Eliminar + activity_delete', str_contains($viewSrc, 'activity_delete.php') && str_contains($viewSrc, 'Eliminar'));
    assertTrue('dashboard_view tiene Eliminar + activity_delete', str_contains($dashSrc, 'activity_delete.php') && str_contains($dashSrc, 'Eliminar'));
    assertTrue(
        'dashboard no muestra Eliminar si occupied_amount null',
        str_contains($dashSrc, '$occNum !== null') && !str_contains($dashSrc, '$occNum === null ||')
    );
} catch (Throwable $e) {
    fail('excepcion no controlada: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
} finally {
    foreach ($createdPoIds as $poId) {
        $pdo->prepare('DELETE FROM po_budget_allocations WHERE purchase_order_id = ?')->execute([$poId]);
        $pdo->prepare('DELETE FROM purchase_orders WHERE id = ?')->execute([$poId]);
    }
    foreach ($createdHeaderIds as $hid) {
        $pdo->prepare('DELETE FROM budget_headers WHERE id = ?')->execute([$hid]);
    }
    foreach ($createdUserIds as $uid) {
        $pdo->prepare('DELETE FROM user_department_permissions WHERE user_id = ?')->execute([$uid]);
        $pdo->prepare('DELETE FROM user_tenant_roles WHERE user_id = ?')->execute([$uid]);
        $pdo->prepare('DELETE FROM super_admins WHERE user_id = ?')->execute([$uid]);
        $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$uid]);
    }
    foreach (array_reverse($policyRowsToRestore) as $row) {
        if ($row['had_row']) {
            $pdo->prepare(
                'UPDATE tenant_department_edit_policy SET allow_edit = ? WHERE tenant_id = ? AND year = ? AND department_id = ?'
            )->execute([$row['allow_edit'], $row['tenant_id'], $row['year'], $row['department_id']]);
        } else {
            $pdo->prepare(
                'DELETE FROM tenant_department_edit_policy WHERE tenant_id = ? AND year = ? AND department_id = ?'
            )->execute([$row['tenant_id'], $row['year'], $row['department_id']]);
        }
    }
    foreach ($createdDepartmentIds as $deptId) {
        try {
            $pdo->prepare('DELETE FROM departments WHERE id = ?')->execute([$deptId]);
        } catch (Throwable) {
            // referenciado; dejar
        }
    }
    if ($createdSupplierId > 0) {
        try {
            $pdo->prepare('DELETE FROM suppliers WHERE id = ?')->execute([$createdSupplierId]);
        } catch (Throwable) {
            // puede estar referenciado; dejar
        }
    }
}

echo "\n=== Resumen: OK={$ok} FAIL={$fail} ===\n";
exit($fail > 0 ? 1 : 0);
