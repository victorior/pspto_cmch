<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';
$pdo = Db::pdo($CONFIG);
Auth::checkPortal($pdo, Auth::REALM_BUDGET);
$tid = (int) Auth::tenantId();
$uid = (int) Auth::userId();
$yCal = (int) date('Y');

if (isset($_GET['department_id']) && (int) $_GET['department_id'] > 0 && !isset($_GET['dept'])) {
    $qs = $_GET;
    $qs['dept'] = (int) $_GET['department_id'];
    unset($qs['department_id']);
    header('Location: index.php?' . http_build_query($qs));
    exit;
}

if (!isset($_SESSION['budjetoc_budget_year']) || !is_array($_SESSION['budjetoc_budget_year'])) {
    $_SESSION['budjetoc_budget_year'] = [];
}
if (isset($_GET['budget_year'])) {
    $by = (int) $_GET['budget_year'];
    if (in_array($by, [$yCal - 1, $yCal, $yCal + 1], true)) {
        $_SESSION['budjetoc_budget_year'][$tid] = $by;
    }
}
$budgetYear = (int) ($_SESSION['budjetoc_budget_year'][$tid] ?? $yCal);
$yearPrev = $yCal - 1;
$yearNext = $yCal + 1;

$month = isset($_GET['month']) ? (int) $_GET['month'] : (int) date('n');
if ($month < 1 || $month > 12) {
    $month = (int) date('n');
}

$routeDepartmentId = isset($_GET['dept']) ? (int) $_GET['dept'] : 0;
$selectedDepartmentId = $routeDepartmentId > 0 ? $routeDepartmentId : null;
$dashboardShowActivities = isset($_GET['actividades']) && (string) $_GET['actividades'] === '1'
    && $selectedDepartmentId !== null && $selectedDepartmentId > 0;

$isAdmin = UserPermissions::isBudgetTenantAdmin($pdo, $uid, $tid);
$isEncargado = !$isAdmin;
$allowedDeptIds = UserPermissions::allowedDepartmentIds($pdo, $uid, $tid);

$showDepartmentSelector = false;
$departmentsForSelector = [];
$encargadoEsDivision = false;
$encargadoView = null;
$adminSeleccionoCoordinacion = false;
$adminView = null;
$adminVistaCoordinacion = false;
$adminVistaDivisionPorCentrosCosto = false;
$list = [];

if (!isset($_SESSION['budjetoc_budget_admin_dept']) || !is_array($_SESSION['budjetoc_budget_admin_dept'])) {
    $_SESSION['budjetoc_budget_admin_dept'] = [];
}
if ($isAdmin) {
    $departmentsForSelector = DepartmentCatalog::listByTenant($pdo, $tid);
    $showDepartmentSelector = true;
    $validIds = array_map(static fn (array $d): int => (int) $d['id'], $departmentsForSelector);
    if ($selectedDepartmentId !== null && $selectedDepartmentId > 0 && in_array($selectedDepartmentId, $validIds, true)) {
        $_SESSION['budjetoc_budget_admin_dept'][$tid] = $selectedDepartmentId;
        $deptIds = [$selectedDepartmentId];
        $listLoaded = false;
        foreach ($departmentsForSelector as $d) {
            if ((int) $d['id'] === $selectedDepartmentId) {
                if (($d['type'] ?? '') === 'estandar') {
                    $adminSeleccionoCoordinacion = true;
                    $adminView = isset($_GET['admin_view']) && $_GET['admin_view'] === 'by_division' ? 'by_division' : 'by_centros_costo';
                    $list = BudgetService::listByMonthForEncargadoDivision($pdo, $tid, $month, $deptIds, $adminView, $budgetYear);
                } else {
                    $adminVistaCoordinacion = true;
                    $list = BudgetService::listByMonthFiltered($pdo, $tid, $month, $deptIds, $budgetYear);
                }
                $listLoaded = true;
                break;
            }
        }
        if (!$listLoaded) {
            $list = BudgetService::listByMonthFiltered($pdo, $tid, $month, $deptIds, $budgetYear);
        }
    } else {
        $list = [];
    }
} elseif ($isEncargado) {
    if ($allowedDeptIds !== null && $allowedDeptIds !== []) {
        $departmentsForSelector = DepartmentCatalog::listByIds($pdo, $tid, $allowedDeptIds);
        $showDepartmentSelector = $departmentsForSelector !== [];
        $validIds = array_map(static fn (array $d): int => (int) $d['id'], $departmentsForSelector);
        if ($selectedDepartmentId === null || $selectedDepartmentId <= 0 || !in_array($selectedDepartmentId, $validIds, true)) {
            $selectedDepartmentId = null;
        }
        if ($selectedDepartmentId !== null && $selectedDepartmentId > 0) {
            $selectedType = null;
            foreach ($departmentsForSelector as $d) {
                if ((int) $d['id'] === $selectedDepartmentId) {
                    $selectedType = $d['type'] ?? 'estandar';
                    break;
                }
            }
            if ($selectedType === 'estandar') {
                $encargadoEsDivision = true;
                $encargadoView = isset($_GET['encargado_view']) && $_GET['encargado_view'] === 'by_centros_costo'
                    ? 'by_centros_costo'
                    : 'by_division';
                $list = BudgetService::listByMonthForEncargadoDivision(
                    $pdo,
                    $tid,
                    $month,
                    [$selectedDepartmentId],
                    $encargadoView,
                    $budgetYear
                );
            } else {
                $list = BudgetService::listByMonthFiltered($pdo, $tid, $month, [$selectedDepartmentId], $budgetYear);
            }
        } else {
            $list = [];
        }
    } else {
        $list = [];
    }
} else {
    $list = BudgetService::listByMonthFiltered($pdo, $tid, $month, $allowedDeptIds, $budgetYear);
}

if (
    $isEncargado
    && $encargadoEsDivision
    && $dashboardShowActivities
    && $selectedDepartmentId !== null
    && $selectedDepartmentId > 0
    && !isset($_GET['encargado_view'])
) {
    $qs = $_GET;
    unset($qs['department_id']);
    $qs['dept'] = $selectedDepartmentId;
    $qs['actividades'] = '1';
    $qs['encargado_view'] = 'by_division';
    header('Location: index.php?' . http_build_query($qs));
    exit;
}

if (
    $isEncargado
    && !$dashboardShowActivities
    && count($departmentsForSelector) === 1
) {
    $onlyEncargadoDeptId = (int) $departmentsForSelector[0]['id'];
    $needsActivitiesRedirect = $selectedDepartmentId !== $onlyEncargadoDeptId
        || !isset($_GET['actividades'])
        || (string) $_GET['actividades'] !== '1';
    if ($needsActivitiesRedirect) {
        $qs = $_GET;
        unset($qs['department_id']);
        $qs['dept'] = $onlyEncargadoDeptId;
        $qs['actividades'] = '1';
        if (!isset($qs['encargado_view'])) {
            foreach ($departmentsForSelector as $d) {
                if ((int) $d['id'] === $onlyEncargadoDeptId && ($d['type'] ?? '') === 'estandar') {
                    $qs['encargado_view'] = 'by_division';
                    break;
                }
            }
        }
        if ($routeDepartmentId > 0) {
            $qs['return_department_id'] = $routeDepartmentId;
        }
        header('Location: index.php?' . http_build_query($qs));
        exit;
    }
}

$stUser = $pdo->prepare('SELECT full_name, email FROM users WHERE id = ? LIMIT 1');
$stUser->execute([$uid]);
$userRow = $stUser->fetch(PDO::FETCH_ASSOC) ?: [];
$stT = $pdo->prepare('SELECT name FROM tenants WHERE id = ?');
$stT->execute([$tid]);
$tenantName = (string) $stT->fetchColumn();
$currentUserInfo = [
    'name' => ($userRow['full_name'] ?? '') !== '' ? (string) $userRow['full_name'] : (string) ($userRow['email'] ?? ''),
    'tenant_name' => $tenantName,
];

$adminVistaDivisionPorCentrosCosto = false;
if ($isAdmin && $adminSeleccionoCoordinacion && ($adminView ?? '') === 'by_centros_costo' && $selectedDepartmentId !== null && $selectedDepartmentId > 0) {
    foreach ($departmentsForSelector as $d) {
        if ((int) $d['id'] === $selectedDepartmentId && ($d['type'] ?? '') === 'estandar') {
            $adminVistaDivisionPorCentrosCosto = true;
            break;
        }
    }
}

$nextYearEditEnabled = false;
if ($selectedDepartmentId !== null && $selectedDepartmentId > 0) {
    $nextYearEditEnabled = UserPermissions::departmentEditPolicy($pdo, $tid, $selectedDepartmentId, $yearNext) === 1;
}

$dashboardReceiveOnlyNotice = isset($_GET['no_new_activity']) && $_GET['no_new_activity'] === 'receive_only';
$dashboardNuevaActividadUrl = null;
$dashboardNuevaActividadBlockedReason = null;
if ($selectedDepartmentId !== null && $selectedDepartmentId > 0) {
    $selectedDeptForCreate = DepartmentCatalog::getById($pdo, $selectedDepartmentId, $tid);
    $canAccess = $selectedDeptForCreate !== null;
    if ($isEncargado && $allowedDeptIds !== null) {
        $canAccess = $canAccess && in_array($selectedDepartmentId, array_map('intval', $allowedDeptIds), true);
    }
    if (!$canAccess) {
        $dashboardNuevaActividadBlockedReason = 'No tiene permiso para crear actividades en la division o centro seleccionado.';
    } elseif (BudgetActivityRules::isCoordinationReceiveOnlyDepartment($selectedDeptForCreate)) {
        $dashboardNuevaActividadBlockedReason = 'Este Centro de Costo solo recibe imputaciones; no puede crear actividades de presupuesto.';
    } elseif (!UserPermissions::canEditActivitiesForDepartment($pdo, $uid, $tid, $selectedDepartmentId, $budgetYear)) {
        $dashboardNuevaActividadBlockedReason = 'Nueva actividad no esta habilitada porque el ' . t_es('ano') . '/departamento seleccionado esta en solo lectura.';
    } else {
        $dashboardNuevaActividadUrl = 'activity_new.php?year=' . $budgetYear . '&department_id=' . $selectedDepartmentId;
    }
}

$dashboardExportUrl = null;
$dashboardExportMetaUrl = null;
if ($dashboardShowActivities && $selectedDepartmentId !== null && $selectedDepartmentId > 0) {
    $canExportDept = false;
    if ($isAdmin) {
        $canExportDept = true;
    } elseif ($isEncargado) {
        $canExportDept = $allowedDeptIds === null
            || in_array($selectedDepartmentId, array_map('intval', $allowedDeptIds), true);
    }
    if ($canExportDept) {
        $exportQuery = [
            'dept' => $selectedDepartmentId,
            'budget_year' => $budgetYear,
        ];
        if ($encargadoEsDivision) {
            $exportQuery['encargado_view'] = 'by_division';
        }
        if ($isAdmin && $adminSeleccionoCoordinacion && $adminView !== null) {
            $exportQuery['admin_view'] = $adminView;
        }
        $dashboardExportUrl = 'export_activities.php?' . http_build_query($exportQuery);
        $dashboardExportMetaUrl = 'export_activities_meta.php?' . http_build_query($exportQuery);
    }
}

$months = [
    1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril', 5 => 'Mayo', 6 => 'Junio',
    7 => 'Julio', 8 => 'Agosto', 9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre',
];

$selectedDepartmentKind = null;
$selectedDepartmentName = null;
if ($selectedDepartmentId !== null && $selectedDepartmentId > 0) {
    $selectedDeptRow = DepartmentCatalog::getById($pdo, $selectedDepartmentId, $tid);
    if ($selectedDeptRow !== null) {
        $selectedDepartmentName = (string) ($selectedDeptRow['name'] ?? '');
        $selectedDepartmentKind = ($selectedDeptRow['type'] ?? '') === 'coordinacion'
            ? 'Centro de costo'
            : 'Division';
    }
}

$layoutTitle = 'Dashboard';
$layoutRealm = 'budget';
$layoutBootstrap = true;
$layoutCss = '../css/app.css';
$layoutNav = [
    ['href' => 'index.php?budget_year=' . $budgetYear, 'label' => 'Home', 'active' => true],
    ['href' => 'activity_new.php?year=' . $budgetYear, 'label' => 'Nueva actividad'],
];
if ($isAdmin) {
    $layoutNav[] = ['href' => 'edit_permissions.php?year=' . $budgetYear, 'label' => 'Permisos de edicion'];
}
$listEditFlags = ($dashboardShowActivities && $list !== [])
    ? UserPermissions::canEditFlagsForHeaders($pdo, $uid, $tid, $list, $budgetYear)
    : [];
require dirname(__DIR__) . '/inc/layout_top.php';
require __DIR__ . '/inc/dashboard_view.php';
require dirname(__DIR__) . '/inc/layout_bottom.php';
