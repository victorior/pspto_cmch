<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';
$pdo = Db::pdo($CONFIG);
Auth::startRealm(Auth::REALM_ADMIN);
AdminAccess::checkAdminPanel($pdo);

$uid = (int) ($_GET['user_id'] ?? $_POST['user_id'] ?? 0);
$st = $pdo->prepare('SELECT id, email, full_name FROM users WHERE id = ?');
$st->execute([$uid]);
$user = $st->fetch(PDO::FETCH_ASSOC);
if (!$user) {
    header('Location: users.php');
    exit;
}

$currentUserId = (int) (Auth::userId() ?? 0);
$err = '';
$ok = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post_csrf();
    $tenantId = (int) ($_POST['tenant_id'] ?? 0);
    if (!AdminAccess::canManageTenant($pdo, $currentUserId, $tenantId)) {
        $err = 'No tiene permisos para gestionar ese tenant.';
    } else {
    $deptIds = array_map('intval', $_POST['department_ids'] ?? []);
    $stRole = $pdo->prepare(
        "SELECT role FROM user_tenant_roles WHERE user_id = ? AND tenant_id = ? AND app = 'budget' LIMIT 1"
    );
    $stRole->execute([$uid, $tenantId]);
    $budgetRole = $stRole->fetchColumn();
    if ($budgetRole === false) {
        $err = 'Este usuario no tiene acceso Presupuesto en ese tenant.';
    } elseif ($tenantId <= 0) {
        $err = 'Seleccione un tenant.';
    } elseif ((string) $budgetRole === 'tenant_admin') {
        $ok = 'Este usuario es administrador de colegio: el sistema ya le da acceso a todas las divisiones y coordinaciones del '. t_es('catalogo') . '. No hace falta guardar casillas ' . t_es('aqui') . '.';
    } else {
        UserPermissions::setDepartmentPermissions($pdo, $uid, $tenantId, $deptIds);
        $ok = 'Divisiones y coordinaciones guardadas.';
    }
    }
}

$budgetTenants = $pdo->prepare(
    "SELECT t.id, t.name, utr.role
     FROM user_tenant_roles utr
     INNER JOIN tenants t ON t.id = utr.tenant_id
     WHERE utr.user_id = ? AND utr.app = 'budget'
     ORDER BY t.name"
);
$budgetTenants->execute([$uid]);
$budgetTenants = $budgetTenants->fetchAll(PDO::FETCH_ASSOC);

// Admin de colegio: solo puede ver/editar sus tenants administrables.
if (!AdminAccess::isFullSuperadmin()) {
    $allowed = array_map(
        static fn (array $t): int => (int) $t['id'],
        AdminAccess::manageableTenants($pdo, $currentUserId)
    );
    $budgetTenants = array_values(array_filter(
        $budgetTenants,
        static fn (array $tr): bool => in_array((int) $tr['id'], $allowed, true)
    ));
}

$assigned = UserPermissions::assignedByUser($pdo, $uid);

$tabBack = isset($_GET['tab']) ? (string) $_GET['tab'] : '';
$layoutBackHref = $tabBack !== '' ? 'users.php?tab=' . rawurlencode($tabBack) : 'users.php';
$layoutTitle = 'Divisiones / coordinaciones - ' . $user['email'];
$layoutRealm = 'admin';
$layoutCss = '../css/app.css';
$layoutBootstrap = true;
$layoutPageHeading = 'Asignar divisiones y coordinaciones';
$layoutBreadcrumb = [
    ['href' => 'index.php', 'label' => 'Inicio'],
    ['href' => $layoutBackHref, 'label' => 'Usuarios'],
    ['label' => $user['email']],
];
$layoutBackLabel = 'Volver a usuarios';
$layoutNav = [
    ['href' => 'index.php', 'label' => 'Inicio'],
    ['href' => 'tenants.php', 'label' => 'Tenants'],
    ['href' => 'users.php', 'label' => 'Usuarios', 'active' => true],
];
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

<div class="alert alert-secondary mb-4">
  <p class="mb-1">
    Usuario: <strong><?= h($user['full_name'] ?: $user['email']) ?></strong>
    <span class="text-muted">(<?= h($user['email']) ?>)</span>
  </p>
  <p class="mb-0 small">
    Para usuarios con rol
    <strong>encargado</strong>, marque las <strong>divisiones</strong> y <strong>coordinaciones</strong> permitidas.
    Los usuarios <strong>admin de colegio</strong> (<code>tenant_admin</code>) tienen acceso a todo el cat&aacute;logo de ese colegio:
    abajo se muestran todas las casillas como concedidas (solo lectura).
    Los centros de costo se eligen al crear cada actividad, no aqu&iacute;.
  </p>
</div>

<?php if ($budgetTenants === []): ?>
<div class="card shadow-sm">
  <div class="card-body">
    <p class="mb-0">
      Este usuario no tiene rol <strong>Presupuesto</strong> en ning&uacute;n tenant.
      Primero as&iacute;gnelo en <a href="users.php">Usuarios</a>.
    </p>
  </div>
</div>
<?php else: ?>
<?php foreach ($budgetTenants as $tr): ?>
<?php
$tid = (int) $tr['id'];
$depts = UserPermissions::listDepartmentsForTenant($pdo, $tid);
$isTenantAdmin = ($tr['role'] ?? '') === 'tenant_admin';
$selected = $assigned[$tid] ?? [];
if ($isTenantAdmin) {
    $selected = array_map(static fn (array $d): int => (int) $d['id'], $depts);
}
$divisions = array_values(array_filter($depts, static fn (array $d): bool => ($d['type'] ?? '') === 'estandar'));
$coords = array_values(array_filter($depts, static fn (array $d): bool => ($d['type'] ?? '') === 'coordinacion'));
?>
<div class="card shadow-sm mb-4">
  <form method="post" class="card-body">
    <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="user_id" value="<?= $uid ?>">
    <input type="hidden" name="tenant_id" value="<?= $tid ?>">
    <div style="margin-bottom:0.85rem;">
      <h2 class="h5" style="display:inline-block;margin:0 0.5rem 0 0;"><?= h($tr['name']) ?></h2>
      <span class="badge"><?= h($tr['role']) ?></span>
    </div>
    <?php if ($isTenantAdmin): ?>
    <div class="alert alert-info mb-3 py-2">
      <strong>Admin de colegio:</strong> este rol tiene acceso a
      <strong>todas</strong> las divisiones y coordinaciones de
      <?= h($tr['name']) ?>. Las casillas solo confirman ese alcance; no es obligatorio guardar.
    </div>
    <?php endif; ?>
    <?php if ($divisions === [] && $coords === []): ?>
      <p class="text-muted mb-0">
        Sin divisiones ni coordinaciones en cat&aacute;logo.
        <a href="tenant_catalog.php?id=<?= $tid ?>">Cat&aacute;logo del tenant</a>.
      </p>
    <?php else: ?>
      <div class="row dept-checks">
        <div class="col-xs-12 col-md-6">
          <div style="border:1px solid var(--border);border-radius:8px;padding:1rem;background:#fff;margin-bottom:1rem;">
            <h3 class="h6 mb-1">Divisiones</h3>
            <p class="text-muted small mb-3">Tipo est&aacute;ndar (encargado de divisi&oacute;n).</p>
            <?php if ($divisions === []): ?>
              <p class="text-muted small mb-0">Ninguna en cat&aacute;logo.</p>
            <?php else: ?>
              <div class="dept-checks-list">
                <?php foreach ($divisions as $d): ?>
                <?php $did = (int) $d['id']; ?>
                <div class="checkbox dept-check">
                  <label for="dept-<?= $tid ?>-<?= $did ?>">
                    <input type="checkbox" name="department_ids[]" value="<?= $did ?>"
                           id="dept-<?= $tid ?>-<?= $did ?>"
                           <?= in_array($did, $selected, true) ? ' checked' : '' ?>
                           <?= $isTenantAdmin ? ' disabled' : '' ?>>
                    <?= h($d['name']) ?>
                  </label>
                </div>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>
        </div>
        <div class="col-xs-12 col-md-6">
          <div style="border:1px solid var(--border);border-radius:8px;padding:1rem;background:#fff;margin-bottom:1rem;">
            <h3 class="h6 mb-1">Coordinaciones</h3>
            <p class="text-muted small mb-3">Tipo coordinaci&oacute;n (encargado de coordinaci&oacute;n).</p>
            <?php if ($coords === []): ?>
              <p class="text-muted small mb-0">Ninguna en cat&aacute;logo.</p>
            <?php else: ?>
              <div class="dept-checks-list">
                <?php foreach ($coords as $d): ?>
                <?php $did = (int) $d['id']; ?>
                <div class="checkbox dept-check">
                  <label for="dept-<?= $tid ?>-<?= $did ?>">
                    <input type="checkbox" name="department_ids[]" value="<?= $did ?>"
                           id="dept-<?= $tid ?>-<?= $did ?>"
                           <?= in_array($did, $selected, true) ? ' checked' : '' ?>
                           <?= $isTenantAdmin ? ' disabled' : '' ?>>
                    <?= h($d['name']) ?>
                  </label>
                </div>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>
        </div>
      </div>
      <?php if (!$isTenantAdmin): ?>
      <button type="submit" class="btn btn-primary mt-4">Guardar asignaci&oacute;n</button>
      <?php endif; ?>
    <?php endif; ?>
  </form>
</div>
<?php endforeach; ?>
<?php endif; ?>
<?php require dirname(__DIR__) . '/inc/layout_bottom.php';
