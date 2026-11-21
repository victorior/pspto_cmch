<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';
$pdo = Db::pdo($CONFIG);
Auth::startRealm(Auth::REALM_ADMIN);
AdminAccess::checkAdminPanel($pdo);

$uid = (int) ($_GET['user_id'] ?? $_POST['user_id'] ?? 0);
$tenantId = (int) ($_GET['tenant_id'] ?? $_POST['tenant_id'] ?? 0);
$app = (string) ($_GET['app'] ?? $_POST['app'] ?? '');
$tabBack = (string) ($_GET['tab'] ?? $_POST['tab'] ?? '');

if ($uid <= 0 || $tenantId <= 0 || !in_array($app, ['budget', 'oc'], true)) {
    header('Location: users.php' . ($tabBack !== '' ? '?tab=' . rawurlencode($tabBack) : ''));
    exit;
}

$currentUserId = (int) (Auth::userId() ?? 0);
if (!AdminAccess::canManageTenant($pdo, $currentUserId, $tenantId)) {
    header('Location: users.php' . ($tabBack !== '' ? '?tab=' . rawurlencode($tabBack) : ''));
    exit;
}

$st = $pdo->prepare(
    'SELECT u.id, u.email, u.full_name, u.active,
            utr.role, t.name AS tenant_name, t.slug AS tenant_slug
     FROM users u
     INNER JOIN user_tenant_roles utr ON utr.user_id = u.id
     INNER JOIN tenants t ON t.id = utr.tenant_id
     WHERE u.id = ? AND utr.tenant_id = ? AND utr.app = ?
     LIMIT 1'
);
$st->execute([$uid, $tenantId, $app]);
$row = $st->fetch(PDO::FETCH_ASSOC);
if (!$row) {
    header('Location: users.php?err=notfound' . ($tabBack !== '' ? '&tab=' . rawurlencode($tabBack) : ''));
    exit;
}

$isSuperadminUser = Auth::isSuperadminUser($pdo, $uid);
$err = '';
$ok = '';

$listHref = 'users.php' . ($tabBack !== '' ? '?tab=' . rawurlencode($tabBack) : '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post_csrf();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'delete_role') {
        if ($isSuperadminUser) {
            $err = 'No se puede quitar el acceso de un superadministrador.';
        } else {
            try {
                $pdo->beginTransaction();
                $pdo->prepare(
                    'DELETE FROM user_department_permissions WHERE user_id = ? AND tenant_id = ?'
                )->execute([$uid, $tenantId]);
                $pdo->prepare(
                    'DELETE FROM user_tenant_roles WHERE user_id = ? AND tenant_id = ? AND app = ?'
                )->execute([$uid, $tenantId, $app]);
                $stCount = $pdo->prepare('SELECT COUNT(*) FROM user_tenant_roles WHERE user_id = ?');
                $stCount->execute([$uid]);
                $nRoles = (int) $stCount->fetchColumn();
                if ($nRoles === 0) {
                    $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$uid]);
                }
                $pdo->commit();
                header('Location: ' . $listHref . (str_contains($listHref, '?') ? '&' : '?') . 'ok=deleted');
                exit;
            } catch (Throwable $e) {
                $pdo->rollBack();
                $err = 'Error al eliminar el acceso.';
            }
        }
    } elseif ($action === 'save') {
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $name = trim((string) ($_POST['full_name'] ?? ''));
        $pass = (string) ($_POST['password'] ?? '');
        $active = isset($_POST['active']) ? 1 : 0;
        $role = (string) ($_POST['role'] ?? '');

        if ($email === '') {
            $err = 'El email es obligatorio.';
        } elseif ($pass !== '' && strlen($pass) < 8) {
            $err = 'La contrasena debe tener al menos 8 caracteres.';
        } elseif ($app === 'budget' && !in_array($role, ['encargado', 'tenant_admin'], true)) {
            $err = 'Rol de presupuesto invalido.';
        } elseif ($app === 'oc' && $role !== 'operator') {
            $role = 'operator';
        } elseif ($isSuperadminUser && $active === 0) {
            $err = 'No se puede desactivar un superadministrador.';
        } else {
            try {
                $pdo->beginTransaction();
                if ($pass !== '') {
                    $hash = password_hash($pass, PASSWORD_DEFAULT);
                    $pdo->prepare(
                        'UPDATE users SET email = ?, full_name = ?, password_hash = ?, active = ? WHERE id = ?'
                    )->execute([$email, $name !== '' ? $name : $email, $hash, $active, $uid]);
                } else {
                    $pdo->prepare(
                        'UPDATE users SET email = ?, full_name = ?, active = ? WHERE id = ?'
                    )->execute([$email, $name !== '' ? $name : $email, $active, $uid]);
                }
                $pdo->prepare(
                    'UPDATE user_tenant_roles SET role = ? WHERE user_id = ? AND tenant_id = ? AND app = ?'
                )->execute([$role, $uid, $tenantId, $app]);
                if ($app === 'budget' && $role === 'tenant_admin') {
                    $pdo->prepare(
                        'DELETE FROM user_department_permissions WHERE user_id = ? AND tenant_id = ?'
                    )->execute([$uid, $tenantId]);
                }
                $pdo->commit();
                header('Location: ' . $listHref . (str_contains($listHref, '?') ? '&' : '?') . 'ok=updated');
                exit;
            } catch (Throwable $e) {
                $pdo->rollBack();
                $err = str_contains($e->getMessage(), 'Duplicate') ? 'Ese email ya esta en uso.' : 'Error al guardar.';
            }
        }
    }
}

$portalLabel = $app === 'budget' ? 'Presupuesto' : 'Ordenes de compra';
$layoutBackHref = $listHref;
$layoutTitle = 'Editar usuario';
$layoutRealm = 'admin';
$layoutCss = '../css/app.css';
$layoutBootstrap = true;
$layoutPageHeading = 'Editar nombre, correo y acceso';
$layoutBreadcrumb = [
    ['href' => 'index.php', 'label' => 'Inicio'],
    ['href' => $layoutBackHref, 'label' => 'Usuarios'],
    ['label' => (string) $row['email']],
];
$layoutBackLabel = 'Volver a usuarios';
$layoutNav = AdminAccess::navItems('users');
require dirname(__DIR__) . '/inc/layout_top.php';
?>
<?php if ($err): ?>
<div class="alert alert-danger alert-dismissible fade show" role="alert">
  <?= h($err) ?>
  <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Cerrar"></button>
</div>
<?php endif; ?>

<div class="card shadow-sm mb-3">
  <div class="card-body">
    <p class="text-muted small mb-2">
      Tenant: <strong><?= h((string) $row['tenant_name']) ?></strong>
      &middot; Portal: <strong><?= h($portalLabel) ?></strong>
      <?php if ($isSuperadminUser): ?>
      &middot; <span class="badge text-bg-warning">Superadmin</span>
      <?php endif; ?>
    </p>

    <form method="post" class="row g-3" style="max-width: 42rem;">
      <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="user_id" value="<?= $uid ?>">
      <input type="hidden" name="tenant_id" value="<?= $tenantId ?>">
      <input type="hidden" name="app" value="<?= h($app) ?>">
      <input type="hidden" name="tab" value="<?= h($tabBack) ?>">

      <div class="col-12">
        <label class="form-label">Email</label>
        <input type="email" name="email" class="form-control" required
               value="<?= h((string) $row['email']) ?>">
      </div>
      <div class="col-12">
        <label class="form-label">Nombre</label>
        <input type="text" name="full_name" class="form-control"
               value="<?= h((string) $row['full_name']) ?>">
      </div>
      <div class="col-12">
        <label class="form-label">Nueva contrasena</label>
        <input type="password" name="password" class="form-control" minlength="8"
               autocomplete="new-password" placeholder="Dejar vacio para no cambiar">
      </div>
      <?php if ($app === 'budget'): ?>
      <div class="col-12">
        <label class="form-label">Rol presupuesto</label>
        <select name="role" class="form-select">
          <option value="encargado"<?= $row['role'] === 'encargado' ? ' selected' : '' ?>>Encargado</option>
          <option value="tenant_admin"<?= $row['role'] === 'tenant_admin' ? ' selected' : '' ?>>Admin colegio</option>
        </select>
      </div>
      <?php else: ?>
      <input type="hidden" name="role" value="operator">
      <div class="col-12">
        <label class="form-label">Rol OC</label>
        <p class="form-control-plaintext mb-0">operator (encargado de compras)</p>
      </div>
      <?php endif; ?>
      <div class="col-12">
        <div class="form-check">
          <input class="form-check-input" type="checkbox" name="active" id="active"
                 value="1"<?= (int) $row['active'] ? ' checked' : '' ?>
                 <?= $isSuperadminUser ? ' disabled' : '' ?>>
          <label class="form-check-label" for="active">Usuario activo (puede iniciar sesion)</label>
        </div>
        <?php if ($isSuperadminUser): ?>
        <input type="hidden" name="active" value="1">
        <?php endif; ?>
      </div>
      <div class="col-12">
        <button type="submit" class="btn btn-primary">Guardar cambios</button>
        <?php if ($app === 'budget' && $row['role'] === 'encargado'): ?>
        <a class="btn btn-outline-secondary"
           href="user_departments.php?user_id=<?= $uid ?>&amp;tab=<?= h(rawurlencode($tabBack !== '' ? $tabBack : (string) $row['tenant_slug'])) ?>">
          Divisiones / coordinaciones
        </a>
        <?php endif; ?>
      </div>
    </form>
  </div>
</div>

<?php if (!$isSuperadminUser): ?>
<div class="card shadow-sm border-danger">
  <div class="card-body">
    <h2 class="h6 text-danger">Quitar acceso en este tenant</h2>
    <p class="small text-muted mb-2">
      Elimina el acceso a <?= h($portalLabel) ?> en este colegio.
      Si el usuario no tiene mas accesos en ningun tenant, se borrara su cuenta.
    </p>
    <form method="post" onsubmit="return confirm('Quitar este acceso? Esta accion no se puede deshacer.');">
      <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="action" value="delete_role">
      <input type="hidden" name="user_id" value="<?= $uid ?>">
      <input type="hidden" name="tenant_id" value="<?= $tenantId ?>">
      <input type="hidden" name="app" value="<?= h($app) ?>">
      <input type="hidden" name="tab" value="<?= h($tabBack) ?>">
      <button type="submit" class="btn btn-outline-danger btn-sm">Eliminar acceso</button>
    </form>
  </div>
</div>
<?php endif; ?>

<?php require dirname(__DIR__) . '/inc/layout_bottom.php';
