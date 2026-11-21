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
    if ((string) ($_POST['action'] ?? '') === 'add_account') {
        $accountId = (int) ($_POST['account_id'] ?? 0);
        $name = trim((string) ($_POST['name'] ?? ''));
        if ($accountId <= 0) {
            $err = 'ID de cuenta requerido (numerico, como en plan_cuenta).';
        } else {
            if (!CatalogService::accountCodeExists($pdo, $tid, $accountId)) {
                try {
                    $pdo->prepare('INSERT INTO account_codes (tenant_id, account_id, name) VALUES (?, ?, ?)')
                        ->execute([$tid, $accountId, $name]);
                } catch (Throwable) {
                    $err = 'ID duplicado en este tenant.';
                }
            }
            if ($err === '') {
                CatalogService::propagateAccountCode($pdo, $tid, $accountId, $name);
                tenant_catalog_redirect_ok($tid, 'tenant_catalog_accounts.php', 'Cuenta contable agregada (replicada a los demas tenants).');
            }
        }
    }
}

$st = $pdo->prepare('SELECT id, account_id, name FROM account_codes WHERE tenant_id = ? ORDER BY account_id');
$st->execute([$tid]);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

tenant_catalog_layout_start($tenant, $tid, 'Cuentas contables', 'tenants', true);
tenant_catalog_flash($err, $ok);
tenant_catalog_tenant_header($tenant, $tid, true);
tenant_catalog_subnav($tid, 'accounts');
?>
<div class="card">
  <h2>Cuentas contables (<?= count($rows) ?>)</h2>
  <p style="color:var(--muted);font-size:0.88rem;margin-top:0;">
    El <strong>ID</strong> es el codigo numerico del plan de cuentas (archivo <code>plan_cuenta.sql</code>), no el id interno de la fila.
  </p>
  <?php if ($rows === []): ?>
  <p style="color:var(--muted);">Sin registros. Importe con <code>php database/import_plan_cuenta.php --all-tenants</code> o agregue una cuenta abajo.</p>
  <?php else: ?>
  <table class="data">
    <thead><tr><th>ID cuenta</th><th>Nombre</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr><td><?= (int) $r['account_id'] ?></td><td><?= h($r['name']) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
  <form method="post" class="catalog-add-form catalog-add-form--grid">
    <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="tenant_id" value="<?= $tid ?>">
    <input type="hidden" name="action" value="add_account">
    <div>
      <label>ID cuenta (plan_cuenta)</label>
      <input name="account_id" type="number" min="1" step="1" placeholder="51131002" required>
    </div>
    <div>
      <label>Nombre</label>
      <input name="name" placeholder="Nombre">
    </div>
    <div class="catalog-add-form__actions">
      <button type="submit" class="btn btn-primary">Agregar</button>
    </div>
  </form>
</div>
<?php require dirname(__DIR__) . '/inc/layout_bottom.php';
