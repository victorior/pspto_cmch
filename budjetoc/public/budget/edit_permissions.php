<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';
$pdo = Db::pdo($CONFIG);
Auth::checkPortal($pdo, Auth::REALM_BUDGET);
$uid = (int) Auth::userId();
$tid = (int) Auth::tenantId();

if (!UserPermissions::isBudgetTenantAdmin($pdo, $uid, $tid) && !Auth::isSuperadmin()) {
    http_response_code(403);
    exit('Solo administradores de colegio pueden gestionar permisos de edicion.');
}

$err = '';
$ok = '';
$tenantId = $tid;
$tenants = [['id' => $tid, 'name' => '', 'slug' => '']];
$stT = $pdo->prepare('SELECT name FROM tenants WHERE id = ?');
$stT->execute([$tid]);
$tenants[0]['name'] = (string) ($stT->fetchColumn() ?: 'Colegio');

$yCal = (int) date('Y');
$policyYear = (int) ($_GET['year'] ?? $_POST['year'] ?? $yCal);
if (!in_array($policyYear, EditPolicyService::selectableYears(), true)) {
    $policyYear = $yCal;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post_csrf();
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

$policies = EditPolicyService::listForTenantYear($pdo, $tenantId, $policyYear);
$showTenantPicker = false;
$formAction = 'edit_permissions.php';

$layoutTitle = 'Permisos de edicion';
$layoutRealm = 'budget';
$layoutCss = '../css/app.css';
$layoutPageHeading = 'Permisos de edicion para Divisiones / Centros de Costo';
$layoutBackHref = 'index.php?budget_year=' . $policyYear;
$layoutBackLabel = 'Volver al presupuesto';
$layoutBreadcrumb = [
    ['label' => 'Permisos de edicion'],
];
$formPostAction = 'edit_permissions.php';
$layoutNav = [
    ['href' => 'index.php', 'label' => 'Home'],
    ['href' => 'edit_permissions.php', 'label' => 'Permisos de edicion', 'active' => true],
];
require dirname(__DIR__) . '/inc/layout_top.php';
?>
<div class="card">
  <p style="color:var(--muted);font-size:0.9rem;margin-top:0;">
    Active o desactive la edicion de actividades por division y coordinacion para el <?= h(t_es('ano')) ?> seleccionado.
  </p>
  <?php require dirname(__DIR__) . '/inc/edit_permissions_body.php'; ?>
</div>
<p><a href="index.php?budget_year=<?= (int) $policyYear ?>" class="btn btn-back">&larr; Volver al presupuesto</a></p>
<?php require dirname(__DIR__) . '/inc/layout_bottom.php';
