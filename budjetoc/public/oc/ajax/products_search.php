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
$perPage = $pick ? 5 : 15;
$offset = ($page - 1) * $perPage;
$total = ProductService::count($pdo, $tid, $q);
$totalPages = max(1, (int) ceil($total / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
    $offset = ($page - 1) * $perPage;
}
$rows = ProductService::list($pdo, $tid, $q, $perPage, $offset);
$year = (int) ($_GET['year'] ?? date('Y'));

/**
 * Paginacion estilo legacy (‹ Prev … Next ›) para modal OC.
 */
function oc_products_paginate(int $page, int $tpages, int $adjacents = 4): string
{
    $prevlabel = '&lsaquo; Prev';
    $nextlabel = 'Next &rsaquo;';
    $out = '<ul class="pagination pagination-sm" style="margin:0;">';
    if ($page === 1) {
        $out .= "<li class=\"disabled\"><span><a>$prevlabel</a></span></li>";
    } else {
        $p = $page - 1;
        $out .= "<li><span><a href=\"#\" class=\"btn-page\" data-page=\"$p\">$prevlabel</a></span></li>";
    }
    if ($page > ($adjacents + 1)) {
        $out .= '<li><a href="#" class="btn-page" data-page="1">1</a></li>';
    }
    if ($page > ($adjacents + 2)) {
        $out .= '<li class="disabled"><a>...</a></li>';
    }
    $pmin = ($page > $adjacents) ? $page - $adjacents : 1;
    $pmax = ($page < ($tpages - $adjacents)) ? $page + $adjacents : $tpages;
    for ($i = $pmin; $i <= $pmax; $i++) {
        if ($i === $page) {
            $out .= "<li class=\"active\"><a>$i</a></li>";
        } else {
            $out .= "<li><a href=\"#\" class=\"btn-page\" data-page=\"$i\">$i</a></li>";
        }
    }
    if ($page < ($tpages - $adjacents - 1)) {
        $out .= '<li class="disabled"><a>...</a></li>';
    }
    if ($page < ($tpages - $adjacents)) {
        $out .= "<li><a href=\"#\" class=\"btn-page\" data-page=\"$tpages\">$tpages</a></li>";
    }
    if ($page < $tpages) {
        $p = $page + 1;
        $out .= "<li><span><a href=\"#\" class=\"btn-page\" data-page=\"$p\">$nextlabel</a></span></li>";
    } else {
        $out .= "<li class=\"disabled\"><span><a>$nextlabel</a></span></li>";
    }
    $out .= '</ul>';

    return $out;
}

if ($rows === []) {
    echo $pick
        ? '<p class="text-muted">Sin resultados.</p>'
        : '<p class="dash-muted">Sin productos. <a href="product_new.php?year=' . $year . '">Crear producto</a></p>';
    exit;
}

if ($pick): ?>
<div class="table-responsive">
  <table class="table table-bordered table-condensed table-hover" style="margin-bottom:0;">
    <thead>
      <tr class="warning">
        <th><?= h(t_es('codigo')) ?></th>
        <th>Producto</th>
        <th class="text-right" style="width:5rem;">Cant.</th>
        <th class="text-right" style="min-width:6rem;">Precio</th>
        <th class="text-center" style="width:4rem;">Agregar</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($rows as $row): ?>
      <?php
        $pid = (int) $row['id'];
        $cod = (string) $row['code'];
        $nom = (string) $row['name'];
        $precio = (float) $row['unit_price'];
        $active = (int) ($row['active'] ?? 1) === 1;
        ?>
      <tr<?= $active ? '' : ' style="opacity:0.65;"' ?>>
        <td><?= h($cod) ?></td>
        <td><?= h($nom) ?><?= $active ? '' : ' <span class="label label-default">Inactivo</span>' ?></td>
        <td class="text-right">
          <input type="text" class="form-control input-sm text-right" id="cantidad_<?= $pid ?>"
                 value="1" autocomplete="off" style="min-width:4rem;">
        </td>
        <td class="text-right">
          <input type="text" class="form-control input-sm text-right" id="precio_venta_<?= $pid ?>"
                 value="<?= h((string) $precio) ?>" autocomplete="off" style="min-width:6rem;">
        </td>
        <td class="text-center">
          <button type="button" class="btn btn-info btn-sm btn-pick-product"
            data-pid="<?= $pid ?>"
            data-code="<?= h($cod) ?>"
            data-name="<?= h($nom) ?>"
            title="Agregar linea">
            <span class="glyphicon glyphicon-plus"></span>
          </button>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
    <?php if ($totalPages > 1): ?>
    <tfoot>
      <tr>
        <td colspan="5" class="text-right" style="border-top:1px solid #ddd;">
          <?= oc_products_paginate($page, $totalPages) ?>
        </td>
      </tr>
    </tfoot>
    <?php endif; ?>
  </table>
</div>
<?php else: ?>
<table class="data" style="margin:0;">
  <thead>
    <tr>
      <th><?= h(t_es('codigo')) ?></th>
      <th>Nombre</th>
      <th>Estado</th>
      <th class="num">Precio</th>
      <th></th>
    </tr>
  </thead>
  <tbody>
  <?php foreach ($rows as $row): ?>
    <tr>
      <td><?= h((string) $row['code']) ?></td>
      <td><?= h((string) $row['name']) ?></td>
      <td><?= (int) ($row['active'] ?? 1) === 1 ? 'Activo' : 'Inactivo' ?></td>
      <td class="num"><?= money_fmt((float) $row['unit_price']) ?></td>
      <td style="white-space:nowrap;">
        <a class="btn btn-sm" href="product_edit.php?id=<?= (int) $row['id'] ?>&year=<?= $year ?>">Editar</a>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php if ($totalPages > 1): ?>
<p style="margin:0.5rem 0 0;display:flex;gap:0.35rem;flex-wrap:wrap;">
  <?php for ($p = 1; $p <= $totalPages; $p++): ?>
    <?php if ($p === $page): ?>
      <strong><?= $p ?></strong>
    <?php else: ?>
      <a href="#" class="btn-page" data-page="<?= $p ?>"><?= $p ?></a>
    <?php endif; ?>
  <?php endfor; ?>
</p>
<?php endif; ?>
<?php endif; ?>
