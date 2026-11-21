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
    if ((string) ($_POST['action'] ?? '') === 'add_cc') {
        $name = trim((string) ($_POST['name'] ?? ''));
        if ($name !== '') {
            if (!CatalogService::costCenterExists($pdo, $tid, $name)) {
                $pdo->prepare('INSERT INTO cost_centers (tenant_id, name) VALUES (?, ?)')->execute([$tid, $name]);
            }
            CatalogService::propagateCostCenter($pdo, $tid, $name);
            tenant_catalog_redirect_ok($tid, 'tenant_catalog_cost_centers.php', 'Centro de costo agregado (replicado a los demas tenants).');
        }
        $err = 'Nombre requerido.';
    }
}

$st = $pdo->prepare('SELECT id, name FROM cost_centers WHERE tenant_id = ? ORDER BY name');
$st->execute([$tid]);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

tenant_catalog_layout_start($tenant, $tid, 'Centros de costo', 'tenants', true);
tenant_catalog_flash($err, $ok);
tenant_catalog_tenant_header($tenant, $tid, true);
tenant_catalog_subnav($tid, 'cc');
?>
<div class="card">
  <h2>Centros de costo (<?= count($rows) ?>)</h2>
  <?php if ($rows === []): ?>
  <p style="color:var(--muted);">Sin registros. Agregue el primero abajo.</p>
  <?php else: ?>
  <ul class="catalog-list"><?php foreach ($rows as $r): ?>
    <li><?= h($r['name']) ?></li>
  <?php endforeach; ?></ul>
  <?php endif; ?>
  <form method="post" class="catalog-add-form">
    <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="tenant_id" value="<?= $tid ?>">
    <input type="hidden" name="action" value="add_cc">
    <label>Nuevo centro de costo</label>
    <input name="name" placeholder="Nombre" required>
    <button type="submit" class="btn btn-primary">Agregar</button>
  </form>
</div>
<?php require dirname(__DIR__) . '/inc/layout_bottom.php';
