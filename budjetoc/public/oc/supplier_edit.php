<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';
$pdo = Db::pdo($CONFIG);
Auth::checkPortal($pdo, Auth::REALM_OC);
$tid = (int) Auth::tenantId();
$year = (int) ($_GET['year'] ?? $_POST['year'] ?? date('Y'));
$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$row = SupplierService::get($pdo, $tid, $id);
if ($row === null) {
    header('Location: suppliers.php?year=' . $year . '&err=notfound');
    exit;
}
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post_csrf();
    try {
        if (isset($_POST['delete'])) {
            SupplierService::delete($pdo, $tid, $id);
            header('Location: suppliers.php?year=' . $year . '&deleted=1');
            exit;
        }
        SupplierService::update(
            $pdo,
            $tid,
            $id,
            (string) ($_POST['legal_name'] ?? ''),
            (string) ($_POST['trade_name'] ?? ''),
            (string) ($_POST['phone'] ?? ''),
            (string) ($_POST['email'] ?? ''),
        );
        header('Location: suppliers.php?year=' . $year . '&updated=1');
        exit;
    } catch (Throwable $e) {
        $err = $e->getMessage();
    }
}

$layoutTitle = 'Editar proveedor';
$layoutRealm = 'oc';
$layoutBootstrap = true;
$layoutCss = '../css/app.css';
$layoutBackHref = 'suppliers.php?year=' . $year;
$layoutBackLabel = 'Volver a proveedores';
$yp = urlencode((string) $year);
$layoutNav = [
    ['href' => 'suppliers.php?year=' . $yp, 'label' => 'Proveedores', 'active' => true],
];
require dirname(__DIR__) . '/inc/layout_top.php';
?>
<?php if ($err !== ''): ?><p class="alert alert-err"><?= h($err) ?></p><?php endif; ?>
<form method="post" class="card" style="max-width:28rem;">
  <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
  <input type="hidden" name="year" value="<?= $year ?>">
  <input type="hidden" name="id" value="<?= $id ?>">
  <label><?= h(t_es('razon_social')) ?> *</label>
  <input name="legal_name" required value="<?= h((string) $row['legal_name']) ?>">
  <label style="margin-top:0.5rem;">Nombre comercial</label>
  <input name="trade_name" value="<?= h((string) ($row['trade_name'] ?? '')) ?>">
  <label><?= h(t_es('telefono')) ?></label>
  <input name="phone" value="<?= h((string) ($row['phone'] ?? '')) ?>">
  <label style="margin-top:0.5rem;">Email</label>
  <input name="email" type="email" value="<?= h((string) ($row['email'] ?? '')) ?>">
  <p style="margin-top:0.75rem;">
    <button type="submit" class="btn btn-primary">Guardar</button>
    <button type="submit" name="delete" value="1" class="btn" style="margin-left:0.5rem;"
      onclick="return confirm('Eliminar este proveedor?');">Eliminar</button>
  </p>
</form>
<?php require dirname(__DIR__) . '/inc/layout_bottom.php';
