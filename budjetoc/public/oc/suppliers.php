<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';
require_once __DIR__ . '/inc/oc_layout_nav.php';
$pdo = Db::pdo($CONFIG);
Auth::checkPortal($pdo, Auth::REALM_OC);
$year = (int) ($_GET['year'] ?? date('Y'));

$layoutTitle = 'Proveedores';
$layoutRealm = 'oc';
$layoutBootstrap = true;
$layoutCss = '../css/app.css';
$layoutPageHeading = t_es('catalogo_proveedores');
$layoutBackHref = 'index.php?year=' . $year;
$layoutBackLabel = 'Volver al listado OC';
$yp = urlencode((string) $year);
$layoutNav = oc_layout_nav($pdo, $year, 'suppliers');
require dirname(__DIR__) . '/inc/layout_top.php';
?>
<?php if (isset($_GET['created'])): ?><p class="alert alert-ok">Proveedor creado.</p><?php endif; ?>
<?php if (isset($_GET['updated'])): ?><p class="alert alert-ok">Proveedor actualizado.</p><?php endif; ?>
<?php if (isset($_GET['deleted'])): ?><p class="alert alert-ok">Proveedor eliminado.</p><?php endif; ?>
<?php if (isset($_GET['err'])): ?>
<p class="alert alert-err"><?= h(match ((string) $_GET['err']) {
    'notfound' => 'Proveedor no encontrado.',
    default => 'Error al procesar el proveedor.',
}) ?></p>
<?php endif; ?>

<div class="card">
  <form class="grid" style="align-items:end;" onsubmit="return false;">
    <div>
      <label for="q"><?= h(t_es('razon_social')) ?>, nombre, email o <?= h(t_es('telefono')) ?></label>
      <input type="text" id="q" placeholder="Buscar" autocomplete="off">
    </div>
    <div>
      <button type="button" class="btn btn-primary" id="btn-buscar">Buscar</button>
      <a class="btn" href="supplier_new.php?year=<?= $year ?>">Nuevo proveedor</a>
    </div>
  </form>
  <p id="loader" class="dash-muted" style="margin:0.5rem 0 0;"></p>
  <div id="resultados" style="margin-top:0.75rem;"></div>
</div>

<script>
(function () {
  var year = <?= (int) $year ?>;
  var page = 1;

  function bindPages() {
    document.querySelectorAll('.btn-sup-page').forEach(function (a) {
      a.addEventListener('click', function (e) {
        e.preventDefault();
        load(parseInt(a.getAttribute('data-page'), 10));
      });
    });
  }

  function load(p) {
    page = p || 1;
    var q = document.getElementById('q').value || '';
    document.getElementById('loader').textContent = 'Cargando...';
    fetch('ajax/suppliers_search.php?page=' + page + '&year=' + year + '&q=' + encodeURIComponent(q))
      .then(function (r) { return r.text(); })
      .then(function (html) {
        document.getElementById('resultados').innerHTML = html;
        document.getElementById('loader').textContent = '';
        bindPages();
      })
      .catch(function () {
        document.getElementById('resultados').innerHTML = '<p class="alert alert-err">Error al cargar.</p>';
        document.getElementById('loader').textContent = '';
      });
  }

  document.getElementById('btn-buscar').addEventListener('click', function () { load(1); });
  document.getElementById('q').addEventListener('keydown', function (e) {
    if (e.key === 'Enter') { e.preventDefault(); load(1); }
  });
  document.getElementById('q').addEventListener('input', function () {
    clearTimeout(window._supDebounce);
    window._supDebounce = setTimeout(function () { load(1); }, 350);
  });
  load(1);
})();
</script>
<?php require dirname(__DIR__) . '/inc/layout_bottom.php';
