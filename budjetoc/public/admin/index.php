<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';
Auth::startRealm(Auth::REALM_ADMIN);
$pdo = Db::pdo($CONFIG);
AdminAccess::requireSuperadminOrRedirect($pdo);

$tenants = (int) $pdo->query('SELECT COUNT(*) FROM tenants')->fetchColumn();
$budgetUsers = (int) $pdo->query("SELECT COUNT(DISTINCT user_id) FROM user_tenant_roles WHERE app = 'budget'")->fetchColumn();
$ocUsers = (int) $pdo->query("SELECT COUNT(DISTINCT user_id) FROM user_tenant_roles WHERE app = 'oc'")->fetchColumn();
$activeTenants = $pdo->query(
    'SELECT id, name, slug FROM tenants WHERE active = 1 AND budget_enabled = 1 ORDER BY name'
)->fetchAll(PDO::FETCH_ASSOC);

$layoutTitle = 'Panel admin';
$layoutRealm = 'admin';
$layoutCss = '../css/app.css';
$layoutPageHeading = 'Panel de administracion';
$layoutBreadcrumb = [
    ['href' => 'index.php', 'label' => 'Inicio'],
];
$layoutNav = AdminAccess::navItems('home');
require dirname(__DIR__) . '/inc/layout_top.php';
?>
<div class="card">
  <h2>Resumen</h2>
  <p>Tenants: <strong><?= $tenants ?></strong> &middot; Usuarios presupuesto: <strong><?= $budgetUsers ?></strong> &middot; Usuarios OC: <strong><?= $ocUsers ?></strong></p>
  <p style="color:var(--muted);font-size:0.9rem;">Cree un tenant, configure divisiones y centros de costo, luego asigne usuarios de presupuesto (sin acceso OC) y encargados OC (solo imputan gastos).</p>
</div>
<div class="card">
  <h2>Acciones rapidas</h2>
  <p class="page-actions">
    <a class="btn btn-primary" href="<?= h(url_path('admin/tenants.php')) ?>">Gestionar tenants</a>
    <a class="btn" href="<?= h(url_path('admin/users.php')) ?>">Gestionar usuarios</a>
    <a class="btn" href="<?= h(url_path('admin/edit_permissions.php')) ?>">Permisos de edicion</a>
  </p>
</div>
<div class="card">
  <h2>Entrar a Presupuesto u OC (superadmin)</h2>
  <p style="color:var(--muted);font-size:0.9rem;">
    Acceso total sin restricciones de division. Mismo email y clave del admin, o entre directo desde aqui.
  </p>
  <p class="page-actions">
    <form method="post" action="<?= h(url_path('admin/open_portal.php')) ?>" style="display:inline;">
      <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="portal" value="budget">
      <?php if (count($activeTenants) === 1): ?>
      <input type="hidden" name="tenant_id" value="<?= (int) $activeTenants[0]['id'] ?>">
      <?php endif; ?>
      <button type="submit" class="btn btn-primary">Abrir Presupuesto</button>
    </form>
    <form method="post" action="<?= h(url_path('admin/open_portal.php')) ?>" style="display:inline;">
      <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="portal" value="oc">
      <button type="submit" class="btn btn-primary">Abrir OC</button>
    </form>
  </p>
  <?php if (count($activeTenants) > 1): ?>
  <p style="margin-top:0.75rem;font-size:0.88rem;color:var(--muted);">
    Presupuesto por colegio:
    <?php foreach ($activeTenants as $i => $t): ?>
    <?= $i > 0 ? ' · ' : '' ?>
    <form method="post" action="<?= h(url_path('admin/open_portal.php')) ?>" style="display:inline;">
      <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="portal" value="budget">
      <input type="hidden" name="tenant_id" value="<?= (int) $t['id'] ?>">
      <button type="submit" class="btn-link"><?= h($t['name']) ?></button>
    </form>
    <?php endforeach; ?>
  </p>
  <?php endif; ?>
  <p style="margin-top:0.75rem;font-size:0.85rem;">
    <a href="../budget/login.php">Login Presupuesto</a> &middot;
    <a href="../oc/login.php">Login OC</a> &middot;
    <a href="../index.php">Portal publico</a>
  </p>
</div>
<?php require dirname(__DIR__) . '/inc/layout_bottom.php';
