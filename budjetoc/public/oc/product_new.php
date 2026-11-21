<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';
$pdo = Db::pdo($CONFIG);
Auth::checkPortal($pdo, Auth::REALM_OC);
$tid = (int) Auth::tenantId();
$year = (int) ($_GET['year'] ?? $_POST['year'] ?? date('Y'));
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post_csrf();
    try {
        $code = (string) ($_POST['code'] ?? '');
        $name = (string) ($_POST['name'] ?? '');
        $price = ProductService::parsePrice((string) ($_POST['unit_price'] ?? ''));
        $active = isset($_POST['active']);
        ProductService::create($pdo, $tid, $code, $name, $price, $active);
        header('Location: products.php?year=' . $year . '&created=1');
        exit;
    } catch (PDOException $e) {
        $err = str_contains($e->getMessage(), 'Duplicate') ? 'Ya existe un producto con ese codigo.' : 'Error al guardar.';
    } catch (Throwable $e) {
        $err = $e->getMessage();
    }
}

$layoutTitle = 'Nuevo producto';
$layoutRealm = 'oc';
$layoutBootstrap = true;
$layoutCss = '../css/app.css';
$layoutPageHeading = 'Nuevo producto';
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
  <label>Codigo *</label>
  <input name="code" required maxlength="32" value="<?= h((string) ($_POST['code'] ?? '')) ?>">
  <label style="margin-top:0.5rem;">Nombre *</label>
  <input name="name" required maxlength="255" value="<?= h((string) ($_POST['name'] ?? '')) ?>">
  <label style="margin-top:0.5rem;">Precio unitario *</label>
  <input name="unit_price" required inputmode="decimal" placeholder="Ej. 15000" value="<?= h((string) ($_POST['unit_price'] ?? '')) ?>">
  <label style="margin-top:0.5rem;display:flex;align-items:center;gap:0.35rem;">
    <input type="checkbox" name="active" value="1" checked style="width:auto;"> Activo
  </label>
  <button type="submit" class="btn btn-primary" style="margin-top:0.75rem;">Guardar</button>
</form>
<?php require dirname(__DIR__) . '/inc/layout_bottom.php';
