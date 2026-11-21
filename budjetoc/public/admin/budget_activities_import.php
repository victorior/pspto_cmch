<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';
Auth::startRealm(Auth::REALM_ADMIN);
$pdo = Db::pdo($CONFIG);
AdminAccess::requireSuperadminSession($pdo);

$uid = (int) Auth::userId();
$tenants = AdminAccess::manageableTenants($pdo, $uid);
if ($tenants === []) {
    http_response_code(403);
    exit('Sin tenants activos.');
}

$requestedTenantId = isset($_GET['tenant_id']) ? (int) $_GET['tenant_id'] : 0;
$tenantId = $requestedTenantId > 0 ? $requestedTenantId : (int) $tenants[0]['id'];
$tenantFallbackNotice = false;
if (!AdminAccess::canManageTenant($pdo, $uid, $tenantId)) {
    $tenantId = (int) $tenants[0]['id'];
    $tenantFallbackNotice = $requestedTenantId > 0;
}

$tenantName = '';
foreach ($tenants as $t) {
    if ((int) $t['id'] === $tenantId) {
        $tenantName = (string) $t['name'];
        break;
    }
}

if (isset($_GET['sample_csv']) && (string) $_GET['sample_csv'] === '1') {
    if (headers_sent()) {
        http_response_code(500);
        exit('No se pudo iniciar la descarga de la plantilla.');
    }
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="plantilla_actividades_presupuesto.csv"');
    header('Cache-Control: no-store');
    echo AdminBudgetActivityCsvImportService::sampleCsvContent();
    exit;
}

$sampleHref = admin_href('budget_activities_import.php?sample_csv=1&tenant_id=' . $tenantId);

$layoutTitle = 'Carga actividades';
$layoutRealm = 'admin';
$layoutCss = '../css/app.css';
$layoutPageHeading = 'Carga masiva de actividades de Presupuesto';
$layoutBreadcrumb = [
    ['href' => AdminAccess::adminHomeHref(), 'label' => 'Inicio'],
    ['label' => 'Carga actividades'],
];
$layoutNav = AdminAccess::navItems('budget_activities_import');
require dirname(__DIR__) . '/inc/layout_top.php';
?>
<div class="card">
  <?php if ($tenantFallbackNotice): ?>
  <p style="color:var(--danger, #a33);font-size:0.9rem;">Tenant solicitado no disponible; se usa el primero administrable.</p>
  <?php endif; ?>
  <p style="color:var(--muted);font-size:0.9rem;margin-top:0;">
    Solo super admin. Elija el tenant, descargue la plantilla CSV (UTF-8), complete las actividades
    indicando Division o Coordinacion por fila, y vuelva a esta pantalla para validar/cargar
    (validacion y alta en entregas siguientes).
  </p>

  <?php if (count($tenants) > 1): ?>
  <form method="get" action="<?= h(admin_href('budget_activities_import.php')) ?>" class="page-actions" style="margin-bottom:1rem;">
    <label for="tenant_id">Tenant</label>
    <select id="tenant_id" name="tenant_id" onchange="this.form.submit()">
      <?php foreach ($tenants as $t): ?>
      <option value="<?= (int) $t['id'] ?>"<?= (int) $t['id'] === $tenantId ? ' selected' : '' ?>>
        <?= h((string) $t['name']) ?>
      </option>
      <?php endforeach; ?>
    </select>
    <button type="submit" class="btn">Aplicar tenant</button>
  </form>
  <?php else: ?>
  <p><strong>Tenant:</strong> <?= h($tenantName !== '' ? $tenantName : (string) $tenants[0]['name']) ?></p>
  <?php endif; ?>

  <h2 class="h6">Plantilla</h2>
  <p>
    <a class="btn btn-primary" href="<?= h($sampleHref) ?>">Descargar plantilla CSV</a>
  </p>
  <p style="color:var(--muted);font-size:0.85rem;">
    Separador <code>;</code>, UTF-8 con BOM (tildes y eñes). Incluye dos filas de ejemplo
    (Division y Coordinacion): borrelas o adaptalas antes de la carga real.
    Columnas: <code>num;year;month;activity_name;creator_type;creator_name;division_name;cost_center_name;observations;account_code;qty;unit_price;detail_label</code>.
    <code>creator_type</code> = <code>division</code> o <code>coordinacion</code>; CC obligatorio si division, vacio si coordinacion.
  </p>
</div>
<p><a href="<?= h(AdminAccess::adminHomeHref()) ?>" class="btn btn-back">&larr; Inicio</a></p>
<?php require dirname(__DIR__) . '/inc/layout_bottom.php';
