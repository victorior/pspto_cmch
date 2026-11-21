<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';
require __DIR__ . '/inc/tenant_catalog_common.php';

$ctx = tenant_catalog_bootstrap();
$pdo = $ctx['pdo'];
$tid = $ctx['tid'];
$tenant = $ctx['tenant'];
$err = $ctx['err'];
$ok = $ctx['ok'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post_csrf();
    if ((string) ($_POST['action'] ?? '') === 'add_dept') {
        $name = trim((string) ($_POST['name'] ?? ''));
        if ($name !== '') {
            if (!CatalogService::departmentExists($pdo, $tid, $name, 'estandar')) {
                $pdo->prepare('INSERT INTO departments (tenant_id, name, type) VALUES (?, ?, ?)')
                    ->execute([$tid, $name, 'estandar']);
            }
            CatalogService::propagateDepartment($pdo, $tid, $name, 'estandar');
            tenant_catalog_redirect_ok($tid, 'tenant_catalog_divisions.php', 'Division agregada (replicada a los demas tenants).');
        }
        $err = 'Nombre requerido.';
    }
}

$st = $pdo->prepare("SELECT id, name FROM departments WHERE tenant_id = ? AND type = 'estandar' ORDER BY name");
$st->execute([$tid]);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

tenant_catalog_layout_start($tenant, $tid, 'Divisiones (estandar)', 'tenants', true);
tenant_catalog_flash($err, $ok);
tenant_catalog_tenant_header($tenant, $tid, true);
tenant_catalog_subnav($tid, 'divisions');
?>
<div class="card">
  <h2>Divisiones estandar (<?= count($rows) ?>)</h2>
  <?php if ($rows === []): ?>
  <p style="color:var(--muted);">Sin registros. Agregue la primera division abajo.</p>
  <?php else: ?>
  <ul class="catalog-list"><?php foreach ($rows as $r): ?>
    <li><?= h($r['name']) ?></li>
  <?php endforeach; ?></ul>
  <?php endif; ?>
  <form method="post" class="catalog-add-form">
    <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="tenant_id" value="<?= $tid ?>">
    <input type="hidden" name="action" value="add_dept">
    <label>Nueva division</label>
    <input name="name" placeholder="Nombre" required>
    <button type="submit" class="btn btn-primary">Agregar</button>
  </form>
</div>
<?php require dirname(__DIR__) . '/inc/layout_bottom.php';
