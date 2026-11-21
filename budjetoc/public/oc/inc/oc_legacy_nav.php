<?php

declare(strict_types=1);

/** @var int $ocNavYear */
/** @var string $ocNavBrand */
$ocNavYear = $ocNavYear ?? (int) date('Y');
$ocNavBrand = $ocNavBrand ?? 'OC';
$yp = urlencode((string) $ocNavYear);
$here = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
global $CONFIG;
$ocNavContext = layout_portal_context(Db::pdo($CONFIG));
$ocNavMetaParts = array_values(array_filter(
    [
        trim((string) ($ocNavContext['tenant_name'] ?? '')),
        trim((string) ($ocNavContext['user_name'] ?? '')),
    ],
    static fn (string $s): bool => $s !== ''
));
?>
<nav class="navbar navbar-default">
  <div class="container-fluid">
    <div class="navbar-header">
      <button type="button" class="navbar-toggle collapsed" data-toggle="collapse" data-target="#oc-legacy-nav" aria-expanded="false">
        <span class="sr-only">Menu</span>
        <span class="icon-bar"></span>
        <span class="icon-bar"></span>
        <span class="icon-bar"></span>
      </button>
      <a class="navbar-brand" href="index.php?year=<?= $yp ?>"><?= h($ocNavBrand) ?></a>
      <?php if ($ocNavMetaParts !== []): ?>
      <p class="navbar-text oc-legacy-meta hidden-xs"><?= h(implode(' · ', $ocNavMetaParts)) ?></p>
      <?php endif; ?>
    </div>
    <div class="collapse navbar-collapse" id="oc-legacy-nav">
      <ul class="nav navbar-nav">
        <li<?= $here === 'index.php' ? ' class="active"' : '' ?>><a href="index.php?year=<?= $yp ?>"><i class="glyphicon glyphicon-list-alt"></i> Listado OC</a></li>
        <li<?= $here === 'oc_new.php' ? ' class="active"' : '' ?>><a href="oc_new.php?year=<?= $yp ?>"><i class="glyphicon glyphicon-edit"></i> Nueva OC</a></li>
        <li<?= $here === 'products.php' ? ' class="active"' : '' ?>><a href="products.php?year=<?= $yp ?>"><i class="glyphicon glyphicon-barcode"></i> Productos</a></li>
        <li<?= $here === 'suppliers.php' ? ' class="active"' : '' ?>><a href="suppliers.php?year=<?= $yp ?>"><i class="glyphicon glyphicon-user"></i> Proveedores</a></li>
      </ul>
      <ul class="nav navbar-nav navbar-right">
        <li<?= $here === 'budget.php' ? ' class="active"' : '' ?>><a href="budget.php?year=<?= $yp ?>"><i class="glyphicon glyphicon-euro"></i> Presupuesto</a></li>
        <li><a href="<?= h(portal_home_href()) ?>"><i class="glyphicon glyphicon-home"></i> Inicio</a></li>
        <li><a href="logout.php"><i class="glyphicon glyphicon-off"></i> Salir</a></li>
      </ul>
    </div>
  </div>
</nav>
