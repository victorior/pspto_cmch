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
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $requestedTenantId = isset($_POST['tenant_id']) ? (int) $_POST['tenant_id'] : $requestedTenantId;
}
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

$validationReport = null;
$importReport = null;
$flash = '';
$flashKind = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post_csrf();
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'validate_csv') {
        if (!AdminAccess::canManageTenant($pdo, $uid, $tenantId)) {
            $flash = 'No tiene permisos para ese tenant.';
            $flashKind = 'error';
        } elseif (!isset($_FILES['csv_file'])) {
            $flash = 'Seleccione un archivo CSV.';
            $flashKind = 'error';
            $validationReport = [
                'ok' => false,
                'would_upload' => 0,
                'errors' => [[
                    'line' => null,
                    'group' => '',
                    'activity_number' => null,
                    'message' => 'Seleccione un archivo CSV.',
                ]],
            ];
        } else {
            $validationReport = AdminBudgetActivityCsvImportService::validateFromUploadedFile(
                $pdo,
                $tenantId,
                $_FILES['csv_file'],
            );
            if ($validationReport['ok']) {
                $flash = 'Validacion OK: '
                    . (int) $validationReport['would_upload']
                    . ' grupo(s) listos para subir. Re-suba el mismo CSV y confirme la carga.';
                $flashKind = 'ok';
            } else {
                $flash = 'Validacion con errores: corrija el CSV y vuelva a validar. No se habilita la carga.';
                $flashKind = 'error';
            }
        }
    } elseif ($action === 'confirm_csv') {
        if (!AdminAccess::canManageTenant($pdo, $uid, $tenantId)) {
            $flash = 'No tiene permisos para ese tenant.';
            $flashKind = 'error';
        } elseif (!isset($_FILES['csv_file'])) {
            $flash = 'Debe re-subir el archivo CSV para confirmar la carga.';
            $flashKind = 'error';
            $importReport = [
                'ok' => false,
                'created' => [],
                'errors' => [[
                    'line' => null,
                    'group' => '',
                    'activity_number' => null,
                    'message' => 'Debe re-subir el archivo CSV para confirmar la carga.',
                ]],
            ];
        } else {
            $importReport = AdminBudgetActivityCsvImportService::importFromUploadedFile(
                $pdo,
                $tenantId,
                $_FILES['csv_file'],
            );
            $createdCount = count($importReport['created'] ?? []);
            if ($importReport['ok'] && $createdCount > 0) {
                $flash = 'Carga confirmada: se crearon ' . $createdCount . ' actividad(es).';
                $flashKind = 'ok';
            } elseif ($createdCount > 0) {
                $flash = 'Carga parcial: se crearon ' . $createdCount
                    . ' actividad(es); revise los errores de los grupos no insertados.';
                $flashKind = 'error';
            } else {
                $flash = 'No se creo ninguna actividad. Corrija el CSV y vuelva a validar.';
                $flashKind = 'error';
            }
        }
    } else {
        $flash = 'Accion no reconocida.';
        $flashKind = 'error';
    }
}

$sampleHref = admin_href('budget_activities_import.php?sample_csv=1&tenant_id=' . $tenantId);
$showConfirm = is_array($validationReport) && ($validationReport['ok'] ?? false);

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

/**
 * @param list<array{line?: int|null, group?: string, activity_number?: string|null, message?: string}> $errList
 */
$renderErrorList = static function (array $errList): void {
    if ($errList === []) {
        return;
    }
    echo '<h3 class="h6">Errores</h3>';
    echo '<ul style="margin:0.25rem 0 0;padding-left:1.25rem;">';
    foreach ($errList as $err) {
        $bits = [];
        if (!empty($err['line'])) {
            $bits[] = 'linea ' . (int) $err['line'];
        }
        if (($err['group'] ?? '') !== '') {
            $bits[] = 'grupo ' . (string) $err['group'];
        }
        if (!empty($err['activity_number'])) {
            $bits[] = (string) $err['activity_number'];
        }
        $prefix = $bits !== [] ? ('[' . implode(' · ', $bits) . '] ') : '';
        echo '<li>' . h($prefix . (string) ($err['message'] ?? '')) . '</li>';
    }
    echo '</ul>';
};
?>
<div class="card">
  <?php if ($tenantFallbackNotice): ?>
  <p style="color:var(--danger, #a33);font-size:0.9rem;">Tenant solicitado no disponible; se usa el primero administrable.</p>
  <?php endif; ?>
  <p style="color:var(--muted);font-size:0.9rem;margin-top:0;">
    Solo super admin. Elija el tenant, descargue la plantilla CSV (UTF-8), complete las actividades
    indicando Division o Coordinacion por fila, valide el archivo y, si no hay errores, re-suba el CSV
    para confirmar las altas.
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

  <h2 class="h6" style="margin-top:1.25rem;">Validar CSV</h2>
  <form method="post" enctype="multipart/form-data" action="<?= h(admin_href('budget_activities_import.php?tenant_id=' . $tenantId)) ?>" class="page-actions" style="flex-wrap:wrap;gap:0.5rem;">
    <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="action" value="validate_csv">
    <input type="hidden" name="tenant_id" value="<?= (int) $tenantId ?>">
    <label for="csv_file">Archivo CSV</label>
    <input type="file" id="csv_file" name="csv_file" accept=".csv,text/csv" required>
    <button type="submit" class="btn btn-primary">Validar</button>
  </form>

  <?php if ($flash !== ''): ?>
  <p style="margin-top:1rem;color:<?= $flashKind === 'ok' ? 'var(--ok, #2a7)' : 'var(--danger, #a33)' ?>;">
    <?= h($flash) ?>
  </p>
  <?php endif; ?>

  <?php if (is_array($importReport)): ?>
  <?php if (($importReport['created'] ?? []) !== []): ?>
  <h3 class="h6" style="margin-top:1rem;">Actividades creadas</h3>
  <ul style="margin:0.25rem 0 0;padding-left:1.25rem;">
    <?php foreach ($importReport['created'] as $row): ?>
    <li>
      <?= h((string) ($row['activity_number'] ?? '')) ?>
      (id <?= (int) ($row['header_id'] ?? 0) ?>, unidad <?= (int) ($row['created_by'] ?? 0) ?>)
    </li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
  <?php
    $importErrors = $importReport['errors'] ?? [];
    if ($importErrors !== []) {
        $renderErrorList($importErrors);
    }
  ?>
  <?php endif; ?>

  <?php if (is_array($validationReport)): ?>
  <p style="margin-top:0.75rem;">
    Grupos que se subirian (sin errores de grupo):
    <strong><?= (int) $validationReport['would_upload'] ?></strong>
  </p>
  <?php
    $renderErrorList($validationReport['errors'] ?? []);
  ?>
  <?php endif; ?>

  <?php if ($showConfirm): ?>
  <h2 class="h6" style="margin-top:1.25rem;">Confirmar carga</h2>
  <p style="color:var(--muted);font-size:0.85rem;">
    Por seguridad no se guarda el archivo en sesion: vuelva a seleccionar el CSV limpio y confirme.
  </p>
  <form method="post" enctype="multipart/form-data" action="<?= h(admin_href('budget_activities_import.php?tenant_id=' . $tenantId)) ?>" class="page-actions" style="flex-wrap:wrap;gap:0.5rem;">
    <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="action" value="confirm_csv">
    <input type="hidden" name="tenant_id" value="<?= (int) $tenantId ?>">
    <label for="csv_file_confirm">Archivo CSV (re-subir)</label>
    <input type="file" id="csv_file_confirm" name="csv_file" accept=".csv,text/csv" required>
    <button type="submit" class="btn btn-primary">Confirmar altas</button>
  </form>
  <?php endif; ?>
</div>
<p><a href="<?= h(AdminAccess::adminHomeHref()) ?>" class="btn btn-back">&larr; Inicio</a></p>
<?php require dirname(__DIR__) . '/inc/layout_bottom.php';
