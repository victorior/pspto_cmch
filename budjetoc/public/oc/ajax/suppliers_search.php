<?php

declare(strict_types=1);

require dirname(__DIR__, 3) . '/src/bootstrap_ajax.php';
Auth::checkPortal(Db::pdo($CONFIG), Auth::REALM_OC);

header('Content-Type: text/html; charset=utf-8');

$pdo = Db::pdo($CONFIG);
$tid = (int) Auth::tenantId();
$q = trim((string) ($_GET['q'] ?? ''));
$page = max(1, (int) ($_GET['page'] ?? 1));
$pick = (string) ($_GET['pick'] ?? '') === '1';
$perPage = $pick ? 10 : 15;
$offset = ($page - 1) * $perPage;
$total = SupplierService::count($pdo, $tid, $q);
$totalPages = max(1, (int) ceil($total / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
    $offset = ($page - 1) * $perPage;
}
$rows = SupplierService::list($pdo, $tid, $q, $perPage, $offset);
$year = (int) ($_GET['year'] ?? date('Y'));

if ($rows === []) {
    echo '<p class="dash-muted">Sin proveedores. <a href="supplier_new.php?year=' . $year . '">Crear proveedor</a></p>';
    exit;
}

if ($pick): ?>
<table class="data" style="margin:0;">
  <thead>
    <tr>
      <th><?= h(t_es('razon_social')) ?></th>
      <th>Nombre comercial</th>
      <th><?= h(t_es('telefono')) ?></th>
      <th>Email</th>
      <th></th>
    </tr>
  </thead>
  <tbody>
  <?php foreach ($rows as $row): ?>
    <tr>
      <td><?= h((string) $row['legal_name']) ?></td>
      <td><?= h((string) ($row['trade_name'] ?? '')) ?></td>
      <td><?= h((string) ($row['phone'] ?? '')) ?></td>
      <td><?= h((string) ($row['email'] ?? '')) ?></td>
      <td>
        <button type="button" class="btn btn-primary btn-sm btn-pick-supplier"
          data-id="<?= (int) $row['id'] ?>"
          data-label="<?= h(SupplierService::displayName($row)) ?>"
          data-name="<?= h((string) $row['legal_name']) ?>"
          data-phone="<?= h((string) ($row['phone'] ?? '')) ?>"
          data-email="<?= h((string) ($row['email'] ?? '')) ?>">Seleccionar</button>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php else: ?>
<table class="data" style="margin:0;">
  <thead>
    <tr>
      <th><?= h(t_es('razon_social')) ?></th>
      <th>Nombre comercial</th>
      <th><?= h(t_es('telefono')) ?></th>
      <th>Email</th>
      <th></th>
    </tr>
  </thead>
  <tbody>
  <?php foreach ($rows as $row): ?>
    <tr>
      <td><?= h((string) $row['legal_name']) ?></td>
      <td><?= h((string) ($row['trade_name'] ?? '')) ?></td>
      <td><?= h((string) ($row['phone'] ?? '')) ?></td>
      <td><?= h((string) ($row['email'] ?? '')) ?></td>
      <td><a class="btn btn-sm" href="supplier_edit.php?id=<?= (int) $row['id'] ?>&year=<?= $year ?>">Editar</a></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php endif; ?>
<?php if ($totalPages > 1): ?>
<p style="margin:0.5rem 0 0;display:flex;gap:0.35rem;flex-wrap:wrap;">
  <?php for ($p = 1; $p <= $totalPages; $p++): ?>
    <?php if ($p === $page): ?>
      <strong><?= $p ?></strong>
    <?php else: ?>
      <a href="#" class="btn-sup-page" data-page="<?= $p ?>"><?= $p ?></a>
    <?php endif; ?>
  <?php endfor; ?>
</p>
<?php endif; ?>
