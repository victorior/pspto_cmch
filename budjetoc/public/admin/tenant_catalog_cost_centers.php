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
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'add_cc') {
        $name = trim((string) ($_POST['name'] ?? ''));
        if ($name !== '') {
            if (!CatalogService::costCenterExists($pdo, $tid, $name)) {
                $pdo->prepare('INSERT INTO cost_centers (tenant_id, name) VALUES (?, ?)')->execute([$tid, $name]);
            }
            CatalogService::propagateCostCenter($pdo, $tid, $name);
            tenant_catalog_redirect_ok($tid, 'tenant_catalog_cost_centers.php', 'Centro de costo agregado (replicado a los demas tenants).');
        }
        $err = 'Nombre requerido.';
    } elseif ($action === 'rename_cc') {
        $id = (int) ($_POST['id'] ?? 0);
        $newName = trim((string) ($_POST['new_name'] ?? ''));
        $st = $pdo->prepare('SELECT name FROM cost_centers WHERE id = ? AND tenant_id = ? LIMIT 1');
        $st->execute([$id, $tid]);
        $oldName = $st->fetchColumn();
        if ($oldName === false) {
            $err = 'Centro de costo no encontrado.';
        } else {
            $result = CatalogService::renameCostCenterAcrossTenants($pdo, (string) $oldName, $newName);
            if ($result['ok']) {
                tenant_catalog_redirect_ok($tid, 'tenant_catalog_cost_centers.php', (string) $result['message']);
            } else {
                $err = (string) $result['error'];
            }
        }
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
  <ul class="catalog-list catalog-list--editable"><?php foreach ($rows as $r): ?>
    <li>
      <form method="post" class="catalog-rename-form">
        <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="tenant_id" value="<?= $tid ?>">
        <input type="hidden" name="action" value="rename_cc">
        <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
        <span class="catalog-rename-form__current"><?= h($r['name']) ?></span>
        <input name="new_name" value="<?= h($r['name']) ?>" required aria-label="Nuevo nombre del centro de costo">
        <button type="submit" class="btn btn-primary">Renombrar</button>
      </form>
    </li>
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
