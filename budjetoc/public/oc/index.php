<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';
require_once __DIR__ . '/inc/oc_layout_nav.php';

$pdo = Db::pdo($CONFIG);
Auth::checkPortal($pdo, Auth::REALM_OC);
$tid = (int) Auth::tenantId();
$year = (int) ($_GET['year'] ?? date('Y'));

$status = isset($_GET['status']) ? (int) $_GET['status'] : 0;
if ($status < 0 || $status > 2) {
    $status = 0;
}
$ocPerPage = 25;
$ocPage = isset($_GET['page']) ? (int) $_GET['page'] : 1;
if ($ocPage < 1) {
    $ocPage = 1;
}
$ocTotal = OcService::countOrders($pdo, $tid, $year, $status);
$ocTotalPages = max(1, (int) ceil($ocTotal / $ocPerPage));
if ($ocPage > $ocTotalPages) {
    $ocPage = $ocTotalPages;
}
$ocOffset = ($ocPage - 1) * $ocPerPage;
$rows = OcService::listOrders($pdo, $tid, $year, $status, $ocPerPage, $ocOffset);

$statusPage = match ($status) {
    1 => ['key' => 'closed', 'label' => 'OC cerradas'],
    2 => ['key' => 'void', 'label' => 'OC anuladas'],
    default => ['key' => 'open', 'label' => 'OC abiertas'],
};

$statusLabels = [
    0 => 'Abierta',
    1 => 'Cerrada',
    2 => 'Anulada',
];

$layoutTitle = t_es('ordenes_compra');
$layoutRealm = 'oc';
$layoutBootstrap = true;
$layoutCss = '../css/app.css';
$layoutPageHeading = $statusPage['label'];
$layoutBreadcrumb = [['href' => 'index.php?year=' . $year . '&status=' . $status, 'label' => 'Inicio'], ['label' => $statusPage['label']]];
$layoutNav = oc_layout_nav($pdo, $year, $statusPage['key']);
$canManagePrintTemplate = UserPermissions::canManageOcPrintTemplate($pdo, (int) Auth::userId(), $tid);
require dirname(__DIR__) . '/inc/layout_top.php';
if (isset($_GET['ok'])) {
    $okNum = (string) $_GET['ok'];
    echo '<p class="alert alert-ok">OC ' . h($okNum) . ' creada.</p>';
}
if (isset($_GET['updated'])) {
    $upNum = (string) $_GET['updated'];
    echo '<p class="alert alert-ok">OC ' . h($upNum) . ' actualizada.</p>';
}
if (isset($_GET['tax_updated'])) {
    $taxNum = (string) $_GET['tax_updated'];
    echo '<p class="alert alert-ok">OC ' . h($taxNum) . ': ' . h(t_es('oc_perfil_actualizado')) . '</p>';
}
?>
<div class="card">
  <form method="get" style="display:flex;gap:0.5rem;align-items:end;flex-wrap:wrap;">
    <div><label><?= h(t_es('ano_folio')) ?></label><input type="number" name="year" value="<?= $year ?>"></div>
    <input type="hidden" name="status" value="<?= $status ?>">
    <button class="btn">Ver</button>
    <a class="btn btn-primary" href="oc_new.php?year=<?= $year ?>">Nueva OC</a>
    <?php if ($canManagePrintTemplate): ?>
    <a class="btn" href="settings.php?year=<?= $year ?>"><?= h(t_es('plantilla_impresion')) ?></a>
    <?php endif; ?>
  </form>
</div>
<div class="card">
  <table class="data">
    <thead><tr>
      <th><?= h(t_es('n_ordinal')) ?> OC</th>
      <th>Fecha</th>
      <th>Proveedor</th>
      <th class="num">Total</th>
      <th>Estado</th>
      <th><?= h(t_es('factura')) ?> / Boleta</th>
      <th></th>
    </tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <?php
        $poId = (int) $r['id'];
        $stKey = (int) $r['status'];
        $invIn = (int) ($r['invoice_number_in'] ?? 0);
        ?>
      <tr>
        <td><?= h($r['folio_year'] . '-' . $r['numero_oc']) ?></td>
        <td><?= h(substr((string) $r['ordered_at'], 0, 10)) ?></td>
        <td><?= h($r['supplier_name']) ?></td>
        <td class="num"><?= money_fmt((float) $r['total_amount']) ?></td>
        <td>
          <form method="post" action="update.php" style="display:flex;flex-wrap:nowrap;gap:0.35rem;align-items:center;">
            <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="id" value="<?= $poId ?>">
            <input type="hidden" name="status_only" value="1">
            <input type="hidden" name="year" value="<?= $year ?>">
            <select name="status" aria-label="Estado OC" style="max-width:7rem;">
              <?php foreach ($statusLabels as $sk => $sl): ?>
              <option value="<?= $sk ?>" <?= $stKey === $sk ? 'selected' : '' ?>><?= h($sl) ?></option>
              <?php endforeach; ?>
            </select>
            <button type="submit" class="btn">OK</button>
          </form>
        </td>
        <td>
          <form method="post" action="update.php" style="display:flex;flex-wrap:nowrap;gap:0.35rem;align-items:center;">
            <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="id" value="<?= $poId ?>">
            <input type="hidden" name="invoice_only" value="1">
            <input type="hidden" name="year" value="<?= $year ?>">
            <input type="number" name="invoice_number_in" value="<?= $invIn > 0 ? (string) $invIn : '' ?>"
                   min="0" placeholder="<?= h(t_es('n_ordinal')) ?>" title="<?= h(t_es('n_ordinal_colon')) ?> factura o boleta proveedor"
                   style="width:7rem;">
            <button type="submit" class="btn">OK</button>
          </form>
        </td>
        <td style="white-space:nowrap;">
          <?php if ($stKey === OcService::STATUS_ABIERTA): ?>
          <a class="btn" href="oc_new.php?id=<?= $poId ?>&year=<?= $year ?>"><?= h(t_es('editar')) ?></a>
          <?php endif; ?>
          <?php if (OcService::canChangeTaxProfile($r)): ?>
          <a class="btn" href="oc_tax_edit.php?id=<?= $poId ?>&year=<?= $year ?>"><?= h(t_es('perfil_tributario')) ?></a>
          <?php endif; ?>
          <a class="btn btn-primary" href="print.php?id=<?= $poId ?>&year=<?= $year ?>">Ver OC</a>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if ($rows === []): ?><tr><td colspan="7"><?= h(t_es('sin_oc_en_ano')) ?></td></tr><?php endif; ?>
    </tbody>
  </table>
  <?php if ($ocTotal > 0): ?>
  <p class="dash-muted" style="margin:0.75rem 0 0;font-size:0.85rem;">
    Mostrando <?= $ocTotal === 0 ? 0 : ($ocOffset + 1) ?><?= h(t_es('range_sep')) ?><?= min($ocOffset + $ocPerPage, $ocTotal) ?> de <?= (int) $ocTotal ?> OC
    <?php if ($ocTotalPages > 1): ?>
    <span style="margin-left:0.5rem;">
      <?php if ($ocPage > 1): ?>
      <a href="index.php?year=<?= (int) $year ?>&amp;status=<?= (int) $status ?>&amp;page=<?= $ocPage - 1 ?>">&larr; Anterior</a>
      <?php endif; ?>
      <?php if ($ocPage > 1 && $ocPage < $ocTotalPages): ?> | <?php endif; ?>
      <?php if ($ocPage < $ocTotalPages): ?>
      <a href="index.php?year=<?= (int) $year ?>&amp;status=<?= (int) $status ?>&amp;page=<?= $ocPage + 1 ?>">Siguiente &rarr;</a>
      <?php endif; ?>
    </span>
    <?php endif; ?>
  </p>
  <?php endif; ?>
</div>
<?php require dirname(__DIR__) . '/inc/layout_bottom.php';
