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
            if (!CatalogService::departmentExists($pdo, $tid, $name, 'coordinacion')) {
                $pdo->prepare('INSERT INTO departments (tenant_id, name, type) VALUES (?, ?, ?)')
                    ->execute([$tid, $name, 'coordinacion']);
            }
            CatalogService::propagateDepartment($pdo, $tid, $name, 'coordinacion');
            tenant_catalog_redirect_ok($tid, 'tenant_catalog_coordinations.php', 'Coordinacion agregada (replicada a los demas tenants).');
        }
        $err = 'Nombre requerido.';
    }
}

$st = $pdo->prepare("SELECT id, name FROM departments WHERE tenant_id = ? AND type = 'coordinacion' ORDER BY name");
$st->execute([$tid]);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

tenant_catalog_layout_start($tenant, $tid, 'Coordinaciones', 'tenants', true);
tenant_catalog_flash($err, $ok);
tenant_catalog_tenant_header($tenant, $tid, true);
tenant_catalog_subnav($tid, 'coordinations');
?>
<div class="card">
  <h2>Coordinaciones (<?= count($rows) ?>)</h2>
  <?php if ($rows === []): ?>
  <p style="color:var(--muted);">Sin registros. Agregue la primera coordinacion abajo.</p>
  <?php else: ?>
  <ul class="catalog-list"><?php foreach ($rows as $r): ?>
    <li><?= h($r['name']) ?></li>
  <?php endforeach; ?></ul>
  <?php endif; ?>
  <form method="post" class="catalog-add-form">
    <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="tenant_id" value="<?= $tid ?>">
    <input type="hidden" name="action" value="add_dept">
    <label>Nueva coordinacion</label>
    <input name="name" placeholder="Nombre" required>
    <button type="submit" class="btn btn-primary">Agregar</button>
  </form>
</div>
<?php require dirname(__DIR__) . '/inc/layout_bottom.php';
