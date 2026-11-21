<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';
$pdo = Db::pdo($CONFIG);
Auth::startRealm(Auth::REALM_ADMIN);
AdminAccess::checkAdminPanel($pdo);
$err = '';
$ok = '';
$importReport = null;

$currentUserId = (int) (Auth::userId() ?? 0);
$tenants = AdminAccess::manageableTenants($pdo, $currentUserId);

$activeTabSlug = (string) ($_GET['tab'] ?? '');
if ($activeTabSlug === '' && $tenants !== []) {
    $activeTabSlug = (string) $tenants[0]['slug'];
}

if ($tenants === []) {
    http_response_code(403);
    $err = 'Su usuario no tiene ningun tenant administrable (o estan inactivos). Para ver herramientas de CRUD aqui, debe ser admin de colegio (rol tenant_admin) en al menos un tenant activo.';
}

if (isset($_GET['sample_csv']) && (string) $_GET['sample_csv'] === '1') {
    if (!headers_sent()) {
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="usuarios_ejemplo.csv"');
    }
    echo AdminUserCsvImportService::sampleCsvContent();
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post_csrf();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'create_user') {
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $pass = (string) ($_POST['password'] ?? '');
        $name = trim((string) ($_POST['full_name'] ?? ''));
        $tenantId = (int) ($_POST['tenant_id'] ?? 0);
        $app = (string) ($_POST['app'] ?? '');
        $budgetRole = (string) ($_POST['budget_role'] ?? 'encargado');
        if (!AdminAccess::canManageTenant($pdo, $currentUserId, $tenantId)) {
            $err = 'No tiene permisos para gestionar ese tenant.';
        } else {
        foreach ($tenants as $t) {
            if ((int) $t['id'] === $tenantId) {
                $activeTabSlug = (string) $t['slug'];
                break;
            }
        }
        if ($email === '' || strlen($pass) < 8 || $tenantId <= 0 || !in_array($app, ['budget', 'oc'], true)) {
            $err = 'Complete email, contrasena (min 8), tenant y tipo de acceso.';
        } elseif ($app === 'budget' && !in_array($budgetRole, ['encargado', 'tenant_admin'], true)) {
            $err = 'Rol de presupuesto invalido.';
        } else {
            try {
                $pdo->beginTransaction();
                $hash = password_hash($pass, PASSWORD_DEFAULT);
                $pdo->prepare('INSERT INTO users (email, password_hash, full_name) VALUES (?, ?, ?)')
                    ->execute([$email, $hash, $name !== '' ? $name : $email]);
                $uid = (int) $pdo->lastInsertId();
                $role = $app === 'budget' ? $budgetRole : 'operator';
                $pdo->prepare('INSERT INTO user_tenant_roles (user_id, tenant_id, app, role) VALUES (?, ?, ?, ?)')
                    ->execute([$uid, $tenantId, $app, $role]);
                $pdo->commit();
                if ($app === 'budget' && $budgetRole === 'encargado') {
                    header('Location: user_departments.php?user_id=' . $uid . '&ok=created&tab=' . rawurlencode($activeTabSlug));
                    exit;
                }
                $ok = $app === 'budget' ? 'Usuario presupuesto creado.' : 'Usuario OC creado.';
            } catch (Throwable $e) {
                $pdo->rollBack();
                $err = str_contains($e->getMessage(), 'Duplicate') ? 'Email ya registrado.' : 'Error al crear usuario.';
            }
        }
        }
    }

    if ($action === 'import_csv') {
        $tenantId = (int) ($_POST['tenant_id'] ?? 0);
        $defaultPass = (string) ($_POST['default_password'] ?? '');
        foreach ($tenants as $t) {
            if ((int) $t['id'] === $tenantId) {
                $activeTabSlug = (string) $t['slug'];
                break;
            }
        }
        if (!AdminAccess::canManageTenant($pdo, $currentUserId, $tenantId)) {
            $err = 'No tiene permisos para gestionar ese tenant.';
        } elseif (!isset($_FILES['csv_file'])) {
            $err = 'Seleccione un archivo CSV.';
        } else {
            try {
                $importReport = AdminUserCsvImportService::importFromUploadedFile(
                    $pdo,
                    $tenantId,
                    $_FILES['csv_file'],
                    $defaultPass,
                );
                $ok = sprintf(
                    'Importacion: %d creados, %d actualizados, %d accesos nuevos.',
                    $importReport['created'],
                    $importReport['updated'],
                    $importReport['roles_added'],
                );
                if ($importReport['skipped'] > 0) {
                    $ok .= ' Omitidos: ' . (int) $importReport['skipped'] . '.';
                }
            } catch (Throwable $e) {
                $err = 'Error al importar: ' . $e->getMessage();
            }
        }
    }

    if ($action === 'grant_oc_access') {
        $tenantId = (int) ($_POST['tenant_id'] ?? 0);
        $userIdToGrant = (int) ($_POST['user_id'] ?? 0);
        foreach ($tenants as $t) {
            if ((int) $t['id'] === $tenantId) {
                $activeTabSlug = (string) $t['slug'];
                break;
            }
        }
        if ($tenantId <= 0 || $userIdToGrant <= 0) {
            $err = 'Solicitud inválida.';
        } elseif (!AdminAccess::canManageTenant($pdo, $currentUserId, $tenantId)) {
            $err = 'No tiene permisos para gestionar ese tenant.';
        } else {
            try {
                $pdo->prepare(
                    "INSERT INTO user_tenant_roles (user_id, tenant_id, app, role)
                     VALUES (?, ?, 'oc', 'operator')
                     ON DUPLICATE KEY UPDATE role = role"
                )->execute([$userIdToGrant, $tenantId]);
                $ok = 'Acceso OC asignado.';
            } catch (Throwable $e) {
                $err = 'Error al asignar acceso OC.';
            }
        }
    }
}

if (isset($_GET['ok'])) {
    $ok = match ((string) $_GET['ok']) {
        'created' => 'Usuario creado. Asigne divisiones y coordinaciones.',
        'updated' => 'Usuario actualizado.',
        'deleted' => 'Acceso eliminado.',
        default => '',
    };
}
if (isset($_GET['err']) && $_GET['err'] === 'notfound') {
    $err = 'Usuario no encontrado.';
}

$allRoles = [];
if ($tenants !== []) {
    $tenantIds = array_map(static fn (array $t): int => (int) $t['id'], $tenants);
    $placeholders = implode(',', array_fill(0, count($tenantIds), '?'));
    $stAll = $pdo->prepare(
        "SELECT u.id, u.email, u.full_name, u.active, utr.tenant_id, utr.app, utr.role,
                t.name AS tenant_name, t.slug AS tenant_slug,
                (SELECT COUNT(*) FROM user_department_permissions udp
                 WHERE udp.user_id = u.id AND udp.tenant_id = utr.tenant_id) AS n_depts,
                (SELECT 1 FROM super_admins sa WHERE sa.user_id = u.id LIMIT 1) AS is_superadmin
         FROM user_tenant_roles utr
         INNER JOIN users u ON u.id = utr.user_id
         INNER JOIN tenants t ON t.id = utr.tenant_id
         WHERE utr.tenant_id IN ({$placeholders})
         ORDER BY t.name, utr.app, u.email"
    );
    $stAll->execute($tenantIds);
    $allRoles = $stAll->fetchAll(PDO::FETCH_ASSOC);
}

/** @var array<int, list<array<string, mixed>>> $rolesByTenant */
$rolesByTenant = [];
foreach ($allRoles as $r) {
    $rolesByTenant[(int) $r['tenant_id']][] = $r;
}

/** @var array<int, array<int, array{has_budget: bool, has_oc: bool}>> $appsByTenantUser */
$appsByTenantUser = [];
foreach ($allRoles as $r) {
    $tid = (int) $r['tenant_id'];
    $uidRow = (int) $r['id'];
    if (!isset($appsByTenantUser[$tid][$uidRow])) {
        $appsByTenantUser[$tid][$uidRow] = ['has_budget' => false, 'has_oc' => false];
    }
    if (($r['app'] ?? '') === 'budget') {
        $appsByTenantUser[$tid][$uidRow]['has_budget'] = true;
    }
    if (($r['app'] ?? '') === 'oc') {
        $appsByTenantUser[$tid][$uidRow]['has_oc'] = true;
    }
}

$layoutTitle = 'Usuarios';
$layoutRealm = 'admin';
$layoutCss = '../css/app.css';
$layoutBootstrap = true;
$layoutPageHeading = 'Usuarios por tenant';
$layoutBreadcrumb = [
    ['href' => 'index.php', 'label' => 'Inicio'],
    ['label' => 'Usuarios'],
];
$layoutBackHref = 'index.php';
$layoutBackLabel = 'Volver al panel';
$layoutNav = AdminAccess::navItems('users');
require dirname(__DIR__) . '/inc/layout_top.php';
?>
<?php if ($err): ?>
<div class="alert alert-danger alert-dismissible fade show" role="alert">
  <?= h($err) ?>
  <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Cerrar"></button>
</div>
<?php endif; ?>
<?php if ($ok): ?>
<div class="alert alert-success alert-dismissible fade show" role="alert">
  <?= h($ok) ?>
  <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Cerrar"></button>
</div>
<?php endif; ?>
<?php if (is_array($importReport) && $importReport['errors'] !== []): ?>
<div class="alert alert-warning" role="alert">
  <strong>Detalle de la importacion:</strong>
  <ul class="mb-0" style="margin-top:0.5rem;">
    <?php foreach (array_slice($importReport['errors'], 0, 20) as $importErr): ?>
    <li><?= h($importErr) ?></li>
    <?php endforeach; ?>
    <?php if (count($importReport['errors']) > 20): ?>
    <li><?= h('... y ' . (count($importReport['errors']) - 20) . ' mas.') ?></li>
    <?php endif; ?>
  </ul>
</div>
<?php endif; ?>

<?php if ($tenants === []): ?>
<div class="card shadow-sm">
  <div class="card-body">
    <p class="mb-0"><?= h($err !== '' ? $err : 'Sin tenants administrables.') ?></p>
  </div>
</div>
<?php else: ?>

<ul class="nav nav-tabs mb-3" role="tablist">
  <?php foreach ($tenants as $t):
      $slug = (string) $t['slug'];
      $isActive = $slug === $activeTabSlug;
      $nUsers = count($rolesByTenant[(int) $t['id']] ?? []);
  ?>
  <li class="nav-item" role="presentation">
    <a class="nav-link<?= $isActive ? ' active' : '' ?>"
       href="users.php?tab=<?= h(rawurlencode($slug)) ?>"
       role="tab"
       <?= $isActive ? 'aria-selected="true"' : '' ?>>
      <?= h((string) $t['name']) ?>
      <span class="badge text-bg-secondary ms-1"><?= $nUsers ?></span>
    </a>
  </li>
  <?php endforeach; ?>
</ul>

<div class="tab-content">
  <?php foreach ($tenants as $t):
      $tid = (int) $t['id'];
      $slug = (string) $t['slug'];
      $isActive = $slug === $activeTabSlug;
      $tenantRoles = $rolesByTenant[$tid] ?? [];
  ?>
  <div class="tab-pane fade<?= $isActive ? ' in active' : '' ?>" id="tab-<?= h($slug) ?>" role="tabpanel">
    <div class="card shadow-sm mb-4">
      <div class="card-body">
        <h2 class="h5 mb-3"><?= h((string) $t['name']) ?> <span class="text-muted fw-normal small">(<?= h($slug) ?>)</span></h2>

        <h3 class="h6">Alta individual</h3>
        <p class="text-muted small">Cree un usuario con email, nombre y acceso a Presupuesto u OC.</p>
        <form method="post" class="row g-3 align-items-end mb-4" data-tenant-form="<?= $tid ?>">
          <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="action" value="create_user">
          <input type="hidden" name="tenant_id" value="<?= $tid ?>">
          <div class="col-md-3">
            <label class="form-label">Email</label>
            <input type="email" name="email" class="form-control" required autocomplete="off">
          </div>
          <div class="col-md-2">
            <label class="form-label">Contrasena</label>
            <input type="password" name="password" class="form-control" minlength="8" required autocomplete="new-password">
          </div>
          <div class="col-md-2">
            <label class="form-label">Nombre</label>
            <input type="text" name="full_name" class="form-control" autocomplete="off">
          </div>
          <div class="col-md-2">
            <label class="form-label">Portal</label>
            <select name="app" class="form-select sel-app" required>
              <option value="">&mdash;</option>
              <option value="budget">Presupuesto</option>
              <option value="oc">Ordenes de compra</option>
            </select>
          </div>
          <div class="col-md-2 wrap-budget-role">
            <label class="form-label">Rol presupuesto</label>
            <select name="budget_role" class="form-select">
              <option value="encargado">Encargado</option>
              <option value="tenant_admin">Admin colegio</option>
            </select>
          </div>
          <div class="col-md-1">
            <button type="submit" class="btn btn-primary w-100">Crear</button>
          </div>
        </form>

        <h3 class="h6">Carga masiva (CSV)</h3>
        <p class="text-muted small mb-2">
          Columnas: <code>email</code>, <code>nombre</code>, <code>contrasena</code>, <code>portal</code> (<code>budget</code> u <code>oc</code>), <code>rol</code> (<code>encargado</code> o <code>tenant_admin</code>).
          Separador <code>;</code> o <code>,</code>.
          <a href="users.php?sample_csv=1">Descargar ejemplo</a>
        </p>
        <form method="post" enctype="multipart/form-data" class="row g-3 align-items-end mb-0">
          <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="action" value="import_csv">
          <input type="hidden" name="tenant_id" value="<?= $tid ?>">
          <div class="col-md-4">
            <label class="form-label">Archivo CSV</label>
            <input type="file" name="csv_file" class="form-control" accept=".csv,text/csv" required>
          </div>
          <div class="col-md-3">
            <label class="form-label">Contrasena por defecto</label>
            <input type="password" name="default_password" class="form-control" minlength="8"
                   placeholder="Si falta en alguna fila" autocomplete="new-password">
          </div>
          <div class="col-md-2">
            <button type="submit" class="btn btn-default w-100">Importar CSV</button>
          </div>
        </form>
      </div>
    </div>

    <div class="card shadow-sm">
      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table table-striped table-hover mb-0">
            <thead class="table-light">
              <tr>
                <th>Email</th>
                <th>Nombre</th>
                <th>Portal</th>
                <th>Rol</th>
                <th>Estado</th>
                <th>Divisiones</th>
                <th class="text-end">Acciones</th>
              </tr>
            </thead>
            <tbody>
            <?php if ($tenantRoles === []): ?>
              <tr>
                <td colspan="7" class="text-center text-muted py-4">Sin usuarios en este tenant.</td>
              </tr>
            <?php else: ?>
              <?php
              $usersGrouped = [];
              foreach ($tenantRoles as $r) {
                  $uidRow = (int) $r['id'];
                  if (!isset($usersGrouped[$uidRow])) {
                      $usersGrouped[$uidRow] = [
                          'id' => $uidRow,
                          'email' => (string) ($r['email'] ?? ''),
                          'full_name' => (string) ($r['full_name'] ?? ''),
                          'active' => (int) ($r['active'] ?? 1),
                          'is_superadmin' => (int) ($r['is_superadmin'] ?? 0),
                          'budget_role' => null,
                          'budget_n_depts' => 0,
                          'oc_role' => null,
                      ];
                  }
                  if (($r['app'] ?? '') === 'budget') {
                      $usersGrouped[$uidRow]['budget_role'] = (string) ($r['role'] ?? '');
                      $usersGrouped[$uidRow]['budget_n_depts'] = (int) ($r['n_depts'] ?? 0);
                  } elseif (($r['app'] ?? '') === 'oc') {
                      $usersGrouped[$uidRow]['oc_role'] = (string) ($r['role'] ?? '');
                  }
              }
              foreach ($usersGrouped as $u):
                  $editBudgetHref = 'user_edit.php?user_id=' . (int) $u['id']
                      . '&tenant_id=' . $tid
                      . '&app=budget'
                      . '&tab=' . rawurlencode($slug);
                  $editOcHref = 'user_edit.php?user_id=' . (int) $u['id']
                      . '&tenant_id=' . $tid
                      . '&app=oc'
                      . '&tab=' . rawurlencode($slug);
                  $hasBudget = is_string($u['budget_role']) && $u['budget_role'] !== '';
                  $hasOc = is_string($u['oc_role']) && $u['oc_role'] !== '';
                  $editDataHref = $hasBudget ? $editBudgetHref : ($hasOc ? $editOcHref : '');
              ?>
              <tr class="<?= !(int) $u['active'] ? 'table-secondary' : '' ?>">
                <td>
                  <?php if ($editDataHref !== ''): ?>
                  <a href="<?= h($editDataHref) ?>"><?= h((string) $u['email']) ?></a>
                  <?php else: ?>
                  <?= h((string) $u['email']) ?>
                  <?php endif; ?>
                  <?php if ((int) $u['is_superadmin']): ?>
                  <span class="badge text-bg-warning ms-1" title="Superadministrador">SA</span>
                  <?php endif; ?>
                </td>
                <td><?= h((string) $u['full_name']) ?></td>
                <td>
                  <?php if ($hasBudget): ?><span class="badge text-bg-primary">Presupuesto</span><?php endif; ?>
                  <?php if ($hasOc): ?><span class="badge text-bg-info">OC</span><?php endif; ?>
                  <?php if (!$hasBudget && !$hasOc): ?>&mdash;<?php endif; ?>
                </td>
                <td>
                  <?php if ($hasBudget): ?>
                    <div><strong>Presupuesto:</strong> <span class="badge text-bg-secondary"><?= h((string) $u['budget_role']) ?></span></div>
                  <?php endif; ?>
                  <?php if ($hasOc): ?>
                    <div><strong>OC:</strong> <span class="badge text-bg-secondary"><?= h((string) $u['oc_role']) ?></span></div>
                  <?php endif; ?>
                </td>
                <td>
                  <?php if ((int) $u['active']): ?>
                  <span class="badge text-bg-success">Activo</span>
                  <?php else: ?>
                  <span class="badge text-bg-secondary">Inactivo</span>
                  <?php endif; ?>
                </td>
                <td>
                  <?php if ($hasBudget && $u['budget_role'] === 'encargado'): ?>
                    <?= (int) $u['budget_n_depts'] ?> asignadas
                  <?php elseif ($hasBudget && $u['budget_role'] === 'tenant_admin'): ?>
                    todas (admin colegio)
                  <?php elseif ($hasBudget): ?>
                    todas
                  <?php else: ?>
                    &mdash;
                  <?php endif; ?>
                </td>
                <td class="text-end text-nowrap">
                  <?php if ($editDataHref !== ''): ?>
                    <a class="btn btn-sm btn-primary" href="<?= h($editDataHref) ?>">Editar datos</a>
                  <?php endif; ?>
                  <?php if ($hasBudget): ?>
                    <a class="btn btn-sm btn-default" href="<?= h($editBudgetHref) ?>">Rol presupuesto</a>
                  <?php endif; ?>
                  <?php if ($hasOc): ?>
                    <a class="btn btn-sm btn-default" href="<?= h($editOcHref) ?>">Rol OC</a>
                  <?php endif; ?>
                  <?php if ($hasBudget && !$hasOc && (int) $u['active']): ?>
                    <form method="post" style="display:inline;" onsubmit="return confirm('Asignar acceso OC a este usuario en este tenant?');">
                      <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
                      <input type="hidden" name="action" value="grant_oc_access">
                      <input type="hidden" name="tenant_id" value="<?= $tid ?>">
                      <input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">
                      <button type="submit" class="btn btn-sm btn-outline-primary">Dar acceso OC</button>
                    </form>
                  <?php endif; ?>
                  <?php if ($hasBudget && $u['budget_role'] === 'encargado'): ?>
                    <a class="btn btn-sm btn-outline-secondary"
                       href="user_departments.php?user_id=<?= (int) $u['id'] ?>&amp;tab=<?= h(rawurlencode($slug)) ?>">Divisiones</a>
                  <?php endif; ?>
                </td>
              </tr>
              <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<?php endif; ?>

<script>
(function () {
  document.querySelectorAll('[data-tenant-form]').forEach(function (form) {
    var app = form.querySelector('.sel-app');
    var wrap = form.querySelector('.wrap-budget-role');
    if (!app || !wrap) return;
    function toggle() {
      wrap.style.display = app.value === 'budget' ? '' : 'none';
    }
    app.addEventListener('change', toggle);
    toggle();
  });
})();
</script>
<?php require dirname(__DIR__) . '/inc/layout_bottom.php';
