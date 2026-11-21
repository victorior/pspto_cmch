<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';
$pdo = Db::pdo($CONFIG);
Auth::checkPortal($pdo, Auth::REALM_OC);
$tid = (int) Auth::tenantId();
$year = (int) ($_GET['year'] ?? $_POST['year'] ?? date('Y'));
$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$product = ProductService::get($pdo, $tid, $id);
if ($product === null) {
    header('Location: products.php?year=' . $year . '&err=notfound');
    exit;
}
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post_csrf();
    try {
        if (isset($_POST['delete'])) {
            ProductService::delete($pdo, $tid, $id);
            header('Location: products.php?year=' . $year . '&deleted=1');
            exit;
        }
        $code = (string) ($_POST['code'] ?? '');
        $name = (string) ($_POST['name'] ?? '');
        $price = ProductService::parsePrice((string) ($_POST['unit_price'] ?? ''));
        $active = isset($_POST['active']);
        ProductService::update($pdo, $tid, $id, $code, $name, $price, $active);
        header('Location: products.php?year=' . $year . '&updated=1');
        exit;
    } catch (PDOException $e) {
        $err = str_contains($e->getMessage(), 'Duplicate') ? 'Ya existe otro producto con ese codigo.' : 'Error al guardar.';
    } catch (Throwable $e) {
        $err = $e->getMessage();
    }
}

$layoutTitle = 'Editar producto';
$layoutRealm = 'oc';
$layoutBootstrap = true;
$layoutCss = '../css/app.css';
$layoutPageHeading = 'Editar producto';
$layoutBackHref = 'products.php?year=' . $year;
$layoutBackLabel = 'Volver a productos';
$yp = urlencode((string) $year);
$layoutNav = [
    ['href' => 'products.php?year=' . $yp, 'label' => 'Productos', 'active' => true],
];
require dirname(__DIR__) . '/inc/layout_top.php';
?>
<?php if ($err !== ''): ?><p class="alert alert-err"><?= h($err) ?></p><?php endif; ?>
<form method="post" class="card" style="max-width:28rem;">
  <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
  <input type="hidden" name="year" value="<?= $year ?>">
  <input type="hidden" name="id" value="<?= $id ?>">
  <label>Codigo *</label>
  <input name="code" required maxlength="32" value="<?= h((string) $product['code']) ?>">
  <label style="margin-top:0.5rem;">Nombre *</label>
  <input name="name" required maxlength="255" value="<?= h((string) $product['name']) ?>">
  <label style="margin-top:0.5rem;">Precio unitario *</label>
  <input name="unit_price" required inputmode="decimal" value="<?= h((string) $product['unit_price']) ?>">
  <label style="margin-top:0.5rem;display:flex;align-items:center;gap:0.35rem;">
    <input type="checkbox" name="active" value="1" style="width:auto;"<?= (int) ($product['active'] ?? 1) === 1 ? ' checked' : '' ?>> Activo
  </label>
  <p style="margin-top:0.75rem;">
    <button type="submit" class="btn btn-primary">Guardar cambios</button>
    <button type="submit" name="delete" value="1" class="btn" style="margin-left:0.5rem;"
      onclick="return confirm('Eliminar este producto?');">Eliminar</button>
  </p>
</form>
<?php require dirname(__DIR__) . '/inc/layout_bottom.php';
