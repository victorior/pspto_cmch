<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';
Auth::startRealm(Auth::REALM_ADMIN);
$pdo = Db::pdo($CONFIG);
AdminAccess::checkAdminPanel($pdo);

$uid = (int) Auth::userId();
$err = '';
$ok = '';

$tenants = AdminAccess::manageableTenants($pdo, $uid);
if ($tenants === []) {
    http_response_code(403);
    exit('Sin tenants asignados.');
}

$tenantId = (int) ($_GET['tenant_id'] ?? $_POST['tenant_id'] ?? $tenants[0]['id']);
if (!AdminAccess::canManageTenant($pdo, $uid, $tenantId)) {
    $tenantId = (int) $tenants[0]['id'];
}

$yCal = (int) date('Y');
$policyYear = (int) ($_GET['year'] ?? $_POST['year'] ?? $yCal);
if (!in_array($policyYear, EditPolicyService::selectableYears(), true)) {
    $policyYear = $yCal;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post_csrf();
    $action = (string) ($_POST['action'] ?? 'save');
    if (!AdminAccess::canManageTenant($pdo, $uid, $tenantId)) {
        $err = 'No puede modificar permisos de ese tenant.';
    } elseif ($action === 'allow_all') {
        EditPolicyService::setAllForTenantYear($pdo, $tenantId, $policyYear, true);
        $ok = 'Todos los departamentos pueden editar para ' . $policyYear . '.';
    } elseif ($action === 'deny_all') {
        EditPolicyService::setAllForTenantYear($pdo, $tenantId, $policyYear, false);
        $ok = 'Todos los departamentos quedaron en solo lectura para ' . $policyYear . '.';
    } else {
        $posted = $_POST['allow_edit'] ?? [];
        $allowMap = [];
        if (is_array($posted)) {
            foreach ($posted as $deptId => $val) {
                $allowMap[(int) $deptId] = 1;
            }
        }
        $allDepts = UserPermissions::listDepartmentsForTenant($pdo, $tenantId);
        $saveMap = [];
        foreach ($allDepts as $d) {
            $did = (int) $d['id'];
            $saveMap[$did] = isset($allowMap[$did]) ? 1 : 0;
        }
        EditPolicyService::savePolicies($pdo, $tenantId, $policyYear, $saveMap);
        $ok = 'Permisos guardados para ' . $policyYear . '.';
    }
}

$policies = EditPolicyService::listForTenantYear($pdo, $tenantId, $policyYear);
$showTenantPicker = count($tenants) > 1 || AdminAccess::isFullSuperadmin();
$formAction = 'edit_permissions.php';

$layoutTitle = 'Permisos de edicion';
$layoutRealm = 'admin';
$layoutCss = '../css/app.css';
$layoutPageHeading = 'Permisos de edicion para Divisiones / Centros de Costo';
$layoutBreadcrumb = [
    ['href' => AdminAccess::adminHomeHref(), 'label' => 'Inicio'],
    ['label' => 'Permisos de edicion'],
];
$layoutNav = AdminAccess::navItems('edit_permissions');
require dirname(__DIR__) . '/inc/layout_top.php';
?>
<div class="card">
  <p style="color:var(--muted);font-size:0.9rem;margin-top:0;">
    Define si cada division o coordinacion puede crear y modificar actividades del presupuesto en el ano seleccionado.
    Los encargados respetan estos permisos al entrar a Presupuesto.
  </p>
  <?php require dirname(__DIR__) . '/inc/edit_permissions_body.php'; ?>
</div>
<?php if (AdminAccess::isFullSuperadmin()): ?>
<p><a href="<?= h(url_path('admin/index.php')) ?>" class="btn btn-back">&larr; Inicio</a></p>
<?php else: ?>
<div class="card" style="margin-top:1rem;">
  <h2>Ir a Presupuesto</h2>
  <p class="page-actions">
    <a class="btn btn-primary" href="<?= h(url_path('budget/login.php')) ?>">Abrir Presupuesto</a>
  </p>
</div>
<p><a href="<?= h(url_path('budget/index.php')) ?>" class="btn btn-back">&larr; Volver a Presupuesto</a></p>
<?php endif; ?>
<?php require dirname(__DIR__) . '/inc/layout_bottom.php';
