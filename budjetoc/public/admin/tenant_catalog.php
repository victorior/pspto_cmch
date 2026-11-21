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
    if ($action === 'sync_to_all') {
        $result = CatalogService::replicateFromTenant($pdo, $tid);
        $a = $result['added'];
        tenant_catalog_redirect_ok(
            $tid,
            'tenant_catalog.php',
            sprintf(
                t_es('catalogo') . ' replicado a %d tenant(s). Agregados: %d CC, %d deptos, %d actividades, %d cuentas.',
                $result['targets'],
                $a['cost_centers'],
                $a['departments'],
                $a['activities'],
                $a['account_codes']
            )
        );
    }
}

$stCnt = $pdo->prepare('SELECT COUNT(*) FROM cost_centers WHERE tenant_id = ?');
$stCnt->execute([$tid]);
$counts['cc'] = (int) $stCnt->fetchColumn();

$stCnt = $pdo->prepare("SELECT COUNT(*) FROM departments WHERE tenant_id = ? AND type = 'estandar'");
$stCnt->execute([$tid]);
$counts['divisions'] = (int) $stCnt->fetchColumn();

$stCnt = $pdo->prepare("SELECT COUNT(*) FROM departments WHERE tenant_id = ? AND type = 'coordinacion'");
$stCnt->execute([$tid]);
$counts['coordinations'] = (int) $stCnt->fetchColumn();

$stCnt = $pdo->prepare('SELECT COUNT(*) FROM activities WHERE tenant_id = ?');
$stCnt->execute([$tid]);
$counts['activities'] = (int) $stCnt->fetchColumn();

$stCnt = $pdo->prepare('SELECT COUNT(*) FROM account_codes WHERE tenant_id = ?');
$stCnt->execute([$tid]);
$counts['accounts'] = (int) $stCnt->fetchColumn();

tenant_catalog_layout_start($tenant, $tid, t_es('catalogo') . ' del tenant');
tenant_catalog_flash($err, $ok);
tenant_catalog_tenant_header($tenant, $tid);
tenant_catalog_subnav($tid, 'hub');
?>
<div class="card">
  <h2><?= h(t_es('catalogo')) ?> compartido entre tenants</h2>
  <p style="color:var(--muted);font-size:0.9rem;margin:0 0 0.75rem;">
    Cada colegio mantiene su propia copia. Al agregar un item en una seccion se replica a los demas tenants si aun no existe.
    Use las secciones del menu para editar listas largas sin saturar una sola pantalla.
  </p>
  <form method="post" onsubmit="return confirm('Copiar entradas faltantes de este catalogo a todos los demas tenants?');">
    <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="tenant_id" value="<?= $tid ?>">
    <input type="hidden" name="action" value="sync_to_all">
    <button type="submit" class="btn btn-primary">Replicar este catalogo a todos los tenants</button>
  </form>
</div>

<div class="catalog-hub-grid">
  <a class="card catalog-hub-card" href="<?= h(admin_href('tenant_catalog_cost_centers.php?id=' . $tid)) ?>">
    <h2>Centros de costo</h2>
    <p class="catalog-hub-count"><?= $counts['cc'] ?> registros</p>
    <span class="btn">Gestionar</span>
  </a>
  <a class="card catalog-hub-card" href="<?= h(admin_href('tenant_catalog_divisions.php?id=' . $tid)) ?>">
    <h2>Divisiones (estandar)</h2>
    <p class="catalog-hub-count"><?= $counts['divisions'] ?> registros</p>
    <span class="btn">Gestionar</span>
  </a>
  <a class="card catalog-hub-card" href="<?= h(admin_href('tenant_catalog_coordinations.php?id=' . $tid)) ?>">
    <h2>Coordinaciones</h2>
    <p class="catalog-hub-count"><?= $counts['coordinations'] ?> registros</p>
    <span class="btn">Gestionar</span>
  </a>
  <a class="card catalog-hub-card" href="<?= h(admin_href('tenant_catalog_activities.php?id=' . $tid)) ?>">
    <h2>Actividades (catalogo)</h2>
    <p class="catalog-hub-count"><?= $counts['activities'] ?> registros</p>
    <span class="btn">Gestionar</span>
  </a>
  <a class="card catalog-hub-card" href="<?= h(admin_href('tenant_catalog_accounts.php?id=' . $tid)) ?>">
    <h2>Cuentas contables</h2>
    <p class="catalog-hub-count"><?= $counts['accounts'] ?> registros</p>
    <span class="btn">Gestionar</span>
  </a>
</div>
<?php require dirname(__DIR__) . '/inc/layout_bottom.php';
