<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';
$pdo = Db::pdo($CONFIG);
Auth::startRealm(Auth::REALM_ADMIN);
AdminAccess::requireSuperadminSession($pdo);
$err = '';
$ok = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post_csrf();
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'add') {
        $name = trim((string) ($_POST['name'] ?? ''));
        $slug = slugify((string) ($_POST['slug'] ?? ''));
        if ($name === '' || $slug === '') {
            $err = 'Nombre y slug requeridos.';
        } else {
            try {
                $pdo->beginTransaction();
                $pdo->prepare(
                    'INSERT INTO tenants (name, slug, budget_enabled, oc_enabled) VALUES (?, ?, 1, 1)'
                )->execute([$name, $slug]);
                $tid = (int) $pdo->lastInsertId();
                $pdo->prepare('INSERT INTO tenant_settings (tenant_id, company_name) VALUES (?, ?)')
                    ->execute([$tid, $name]);
                $pdo->commit();
                $masterId = CatalogService::resolveMasterTenantId($pdo);
                if ($masterId !== $tid) {
                    CatalogService::replicateFromTenant($pdo, $masterId, [$tid]);
                }
                $ok = 'Tenant creado con ' . t_es('catalogo') . ' base replicado. Puede ajustarlo en ' . t_es('catalogo') . '.';
            } catch (Throwable $e) {
                $pdo->rollBack();
                $err = str_contains($e->getMessage(), 'Duplicate') ? 'Slug ya existe.' : 'Error al crear.';
            }
        }
    } elseif ($action === 'toggle' && (int) ($_POST['id'] ?? 0) > 0) {
        $pdo->prepare('UPDATE tenants SET active = 1 - active WHERE id = ?')->execute([(int) $_POST['id']]);
        $ok = 'Estado actualizado.';
    }
}

$rows = $pdo->query(
    'SELECT t.id, t.name, t.slug, t.active, t.budget_enabled, t.oc_enabled,
            (SELECT COUNT(*) FROM user_tenant_roles utr WHERE utr.tenant_id = t.id AND utr.app = \'budget\') AS n_budget,
            (SELECT COUNT(*) FROM user_tenant_roles utr WHERE utr.tenant_id = t.id AND utr.app = \'oc\') AS n_oc
     FROM tenants t ORDER BY t.id'
)->fetchAll(PDO::FETCH_ASSOC);

$layoutTitle = 'Tenants';
$layoutRealm = 'admin';
$layoutCss = '../css/app.css';
$layoutPageHeading = 'Gestion de tenants';
$layoutBreadcrumb = [
    ['href' => 'index.php', 'label' => 'Inicio'],
    ['label' => 'Tenants'],
];
$layoutBackHref = 'index.php';
$layoutBackLabel = 'Volver al panel';
$layoutNav = [
    ['href' => 'index.php', 'label' => 'Inicio'],
    ['href' => 'tenants.php', 'label' => 'Tenants', 'active' => true],
    ['href' => 'users.php', 'label' => 'Usuarios'],
];
require dirname(__DIR__) . '/inc/layout_top.php';
?>
<?php if ($err): ?><div class="alert alert-err"><?= h($err) ?></div><?php endif; ?>
<?php if ($ok): ?><div class="alert alert-ok"><?= h($ok) ?></div><?php endif; ?>

<div class="card">
  <h2>Nuevo tenant</h2>
  <form method="post" class="grid" style="grid-template-columns:1fr 1fr auto;align-items:end;">
    <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="action" value="add">
    <div><label>Nombre</label><input name="name" required></div>
    <div><label>Slug (URL)</label><input name="slug" required pattern="[a-z0-9\-]+" placeholder="colegio-norte"></div>
    <div><button type="submit" class="btn btn-primary">Crear</button></div>
  </form>
</div>

<div class="card">
  <h2>Tenants</h2>
  <table class="data">
    <thead>
      <tr><th>ID</th><th>Nombre</th><th>Slug</th><th>Presup.</th><th>OC</th><th>Usuarios</th><th></th></tr>
    </thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td><?= (int) $r['id'] ?></td>
        <td><?= h($r['name']) ?><?= (int) $r['active'] ? '' : ' (inactivo)' ?></td>
        <td><code><?= h($r['slug']) ?></code></td>
        <td><?= (int) $r['budget_enabled'] ? 'Si' : 'No' ?></td>
        <td><?= (int) $r['oc_enabled'] ? 'Si' : 'No' ?></td>
        <td>P: <?= (int) $r['n_budget'] ?> &middot; OC: <?= (int) $r['n_oc'] ?></td>
        <td>
          <a class="btn" href="tenant_catalog.php?id=<?= (int) $r['id'] ?>">Catalogo</a>
          <form method="post" style="display:inline;">
            <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action" value="toggle">
            <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
            <button type="submit" class="btn"><?= (int) $r['active'] ? 'Desactivar' : 'Activar' ?></button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php require dirname(__DIR__) . '/inc/layout_bottom.php';
