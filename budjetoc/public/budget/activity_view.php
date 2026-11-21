<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';
$pdo = Db::pdo($CONFIG);
Auth::checkPortal($pdo, Auth::REALM_BUDGET);
$tid = (int) Auth::tenantId();
$uid = (int) Auth::userId();
$hid = (int) ($_GET['id'] ?? 0);

$hasCreatedBy = BudgetService::hasCreatedByColumn($pdo);
$createdByJoin = $hasCreatedBy
    ? ' LEFT JOIN departments cr ON cr.id = bh.created_by AND cr.tenant_id = bh.tenant_id'
    : '';
$createdBySel = $hasCreatedBy ? ', cr.name AS created_by_name' : ', NULL AS created_by_name';
$st = $pdo->prepare(
    'SELECT bh.*, a.name AS activity_name, cc.name AS cost_center_name,
            d.name AS department_name, div_d.name AS division_name'
    . $createdBySel
    . ' FROM budget_headers bh
     INNER JOIN activities a ON a.id = bh.activity_id
     LEFT JOIN cost_centers cc ON cc.id = bh.cost_center_id
     LEFT JOIN departments d ON d.id = bh.department_id AND d.tenant_id = bh.tenant_id
     LEFT JOIN departments div_d ON div_d.id = bh.division_id AND div_d.tenant_id = bh.tenant_id'
    . $createdByJoin
    . ' WHERE bh.id = ? AND bh.tenant_id = ?'
);
$st->execute([$hid, $tid]);
$h = $st->fetch(PDO::FETCH_ASSOC);
if (!$h) {
    header('Location: index.php');
    exit;
}

if (!UserPermissions::canViewActivityHeader($pdo, $uid, $tid, $h)) {
    http_response_code(403);
    echo '<!DOCTYPE html><html lang="es"><head><meta charset="utf-8"><title>Acceso denegado</title>';
    echo '<link rel="stylesheet" href="../css/app.css"></head><body><div class="wrap"><div class="card">';
    echo '<p class="alert alert-err">No tiene permiso para ver esta actividad en su ámbito asignado.</p>';
    echo '<p><a class="btn" href="index.php">Volver al listado</a></p></div></div></body></html>';
    exit;
}

$stL = $pdo->prepare(
    'SELECT bd.*, ac.id AS account_codes_pk, ac.account_id, ac.name AS account_name
     FROM budget_details bd
     INNER JOIN account_codes ac ON ac.id = bd.account_code_id AND ac.tenant_id = ?
     WHERE bd.budget_header_id = ?
     ORDER BY bd.id'
);
$stL->execute([$tid, $hid]);
$lines = $stL->fetchAll(PDO::FETCH_ASSOC);
$detailIds = array_map(static fn (array $ln): int => (int) $ln['id'], $lines);
$utilByDetail = BudgetService::utilizedSumsByDetailIds($pdo, $detailIds);
$ocImputations = BudgetService::ocImputationsForHeader($pdo, $tid, $hid);
$ocByDetail = $ocImputations['oc_numbers_by_detail'] ?? [];
$reqByDetail = $ocImputations['req_numbers_by_detail'] ?? [];
$ocAllocations = BudgetService::allocationsForHeader($pdo, $tid, $hid);
$ocAllocationsVoided = BudgetService::allocationsForHeaderVoided($pdo, $tid, $hid);
$headerObservations = trim((string) ($h['observations'] ?? ''));
$total = BudgetService::headerTotal($pdo, $hid);
$util = BudgetService::utilizedSumsByHeaderIds($pdo, [$hid])[$hid] ?? 0.0;
$year = budget_year_from_activity_number((string) $h['activity_number']);
$canEdit = UserPermissions::canEditActivityHeader($pdo, (int) Auth::userId(), $tid, $h, $year);
$saveOk = isset($_GET['ok']) && $_GET['ok'] === '1';
$listReturnHref = budget_list_return_href($_GET['return'] ?? null);
$returnSuffix = budget_return_query_suffix($_GET['return'] ?? null);
$listContext = budget_list_context_from_return($pdo, $tid, $_GET['return'] ?? null);
$activityDivisionName = trim((string) ($h['division_name'] ?? ''));
$activityCcCoordLabel = budget_ccosto_coordinacion_label($h);

$layoutTitle = $h['activity_number'];
$layoutRealm = 'budget';
$layoutBootstrap = true;
$layoutCss = '../css/app.css';
$layoutPageHeading = (string) $h['activity_number'];
$layoutBreadcrumb = [['href' => $listReturnHref, 'label' => 'Inicio'], ['label' => (string) $h['activity_number']]];
$layoutBackHref = $listReturnHref;
$layoutBackLabel = 'Volver al listado';
$layoutNav = [
    ['href' => $listReturnHref, 'label' => 'Home', 'active' => true],
    ['href' => 'activity_new.php', 'label' => 'Nueva actividad'],
];
require dirname(__DIR__) . '/inc/layout_top.php';
?>
<?php if ($saveOk): ?><p class="alert alert-ok">Cambios guardados.</p><?php endif; ?>
<?php if ($listContext !== null): ?>
<div class="dash-activity-top">
  <p class="dash-activity-top__line">
    <strong><?= h($listContext['department_kind']) ?>: <?= h($listContext['department_name']) ?></strong>
    <span class="dash-muted"><?php
      $ctxParts = [];
      if ($listContext['budget_year'] !== null) {
          $ctxParts[] = 'Presupuesto ' . (int) $listContext['budget_year'];
      }
      if ($listContext['month_name'] !== null) {
          $ctxParts[] = $listContext['month_name'];
      }
      if ($listContext['list_view_label'] !== null) {
          $ctxParts[] = $listContext['list_view_label'];
      }
      echo $ctxParts !== [] ? ' — ' . h(implode(' — ', $ctxParts)) : '';
    ?></span>
  </p>
</div>
<?php endif; ?>
<div class="card">
  <?php if ($canEdit): ?>
  <p style="margin:0 0 0.75rem;">
    <a href="activity_edit.php?id=<?= $hid ?><?= h($returnSuffix) ?>" class="btn btn-primary btn-sm">Editar actividad</a>
  </p>
  <?php endif; ?>
  <div style="overflow-x:auto;">
    <table class="data" style="margin:0;">
      <thead>
        <tr>
          <th>Mes</th>
          <th><?= budget_ccosto_coordinacion_header() ?></th>
          <th>Division</th>
          <th>Actividad</th>
          <th>N° Actividad</th>
          <th>Observacion</th>
          <th class="num">Presupuesto</th>
          <th class="num">Ocupado</th>
          <th class="num">Quedan</th>
        </tr>
      </thead>
      <tbody>
        <tr>
          <td><?= h(budget_month_label((int) ($h['month'] ?? 0))) ?></td>
          <td><?= h($activityCcCoordLabel) ?></td>
          <td><?= $activityDivisionName !== '' ? h($activityDivisionName) : '—' ?></td>
          <td><?= h((string) ($h['activity_name'] ?? '')) ?></td>
          <td><?= h((string) ($h['activity_number'] ?? '')) ?></td>
          <td><?= $headerObservations !== '' ? nl2br(h($headerObservations)) : '—' ?></td>
          <td class="num"><?= h(budget_int_fmt((float) $total)) ?></td>
          <td class="num"><?= h(budget_int_fmt((float) $util)) ?></td>
          <td class="num"><?= h(budget_int_fmt((float) max(0, $total - $util))) ?></td>
        </tr>
      </tbody>
    </table>
  </div>
</div>
<div class="card" style="padding:0;overflow:hidden;">
<table class="data" style="margin:0;">
  <thead>
    <tr>
      <th>Cuenta asociada</th>
      <th class="num">Cant.</th>
      <th class="num">$ unitario</th>
      <th class="num">Total</th>
      <th>Notas</th>
      <th class="num">Utilizado OC</th>
      <th>Nº OC</th>
      <th>Nº Req.</th>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($lines as $ln): ?>
    <?php
      $lt = round((float) $ln['quantity'] * (float) $ln['unit_price'], 2);
      $u = $utilByDetail[(int) $ln['id']] ?? 0.0;
      $lineNote = trim((string) ($ln['observation'] ?? ''));
      $ocNum = trim((string) ($ocByDetail[(int) $ln['id']] ?? ''));
      $reqNum = trim((string) ($reqByDetail[(int) $ln['id']] ?? ''));
    ?>
    <tr>
      <td><?= h(budget_account_label($pdo, [
          'id' => (int) ($ln['account_codes_pk'] ?? $ln['account_code_id'] ?? 0),
          'account_id' => (int) ($ln['account_id'] ?? 0),
          'name' => (string) ($ln['account_name'] ?? ''),
      ])) ?></td>
      <td class="num"><?= h(number_format((float) $ln['quantity'], 2, ',', '.')) ?></td>
      <td class="num"><?= budget_int_fmt((float) $ln['unit_price']) ?></td>
      <td class="num"><?= budget_int_fmt($lt) ?></td>
      <td style="max-width:16rem;font-size:0.88rem;"><?= $lineNote !== '' ? h($lineNote) : '—' ?></td>
      <td class="num"><?= $u > 0.005 ? budget_int_fmt($u) : '—' ?></td>
      <td style="font-size:0.88rem;"><?= $ocNum !== '' ? h($ocNum) : '—' ?></td>
      <td style="font-size:0.88rem;"><?= $reqNum !== '' ? h($reqNum) : '—' ?></td>
    </tr>
    <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php if ($ocAllocations !== []): ?>
<div class="card">
  <h3>Órdenes de compra imputadas (vigentes)</h3>
  <p class="dash-muted" style="margin:0 0 0.75rem;font-size:0.9rem;">Consumen presupuesto de esta actividad.</p>
  <table class="data">
    <thead>
      <tr>
        <th>Nº OC</th>
        <th>Fecha OC</th>
        <th>Descripcion</th>
        <th>Proveedor</th>
        <th>Cuenta</th>
        <th class="num">Monto</th>
        <th>REQ</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($ocAllocations as $alloc): ?>
      <?php
        $folio = oc_folio_label((string) ($alloc['folio_year'] ?? ''), (int) ($alloc['numero_oc'] ?? 0));
        $ocDesc = trim((string) ($alloc['po_line_descriptions'] ?? ''));
        if ($ocDesc === '') {
            $ocDesc = trim((string) ($alloc['po_note'] ?? ''));
        }
        $ocSupplier = trim((string) ($alloc['supplier_trade_name'] ?? '')) !== ''
            ? trim((string) $alloc['supplier_trade_name'])
            : trim((string) ($alloc['supplier_legal_name'] ?? ''));
      ?>
      <tr>
        <td>
          <a href="../oc/view_from_activity.php?id=<?= (int) ($alloc['purchase_order_id'] ?? 0) ?>&amp;activity_id=<?= $hid ?>">
            <?= h($folio) ?>
          </a>
        </td>
        <td><?= h(substr((string) ($alloc['ordered_at'] ?? ''), 0, 10)) ?></td>
        <td style="max-width:14rem;font-size:0.88rem;"><?= $ocDesc !== '' ? h($ocDesc) : '—' ?></td>
        <td style="max-width:12rem;font-size:0.88rem;"><?= $ocSupplier !== '' ? h($ocSupplier) : '—' ?></td>
        <td><?= h(budget_account_label($pdo, [
            'account_id' => (int) ($alloc['account_id'] ?? 0),
            'name' => (string) ($alloc['account_name'] ?? ''),
        ])) ?></td>
        <td class="num"><?= budget_int_fmt((float) ($alloc['amount'] ?? 0)) ?></td>
        <td><?= h((string) ($alloc['req_number'] ?? '')) !== '' ? h((string) $alloc['req_number']) : '—' ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>
<?php if ($ocAllocationsVoided !== []): ?>
<div class="card" style="border-color:#fecaca;">
  <h3>Historial — imputaciones reversadas (OC anuladas)</h3>
  <p class="dash-muted" style="margin:0 0 0.75rem;font-size:0.9rem;">
    Estas imputaciones ya no consumen presupuesto; se conservan como registro de lo que estuvo imputado.
  </p>
  <table class="data">
    <thead>
      <tr>
        <th>Nº OC</th>
        <th>Fecha OC</th>
        <th>Anulada</th>
        <th>Descripcion</th>
        <th>Proveedor</th>
        <th>Cuenta</th>
        <th class="num">Monto (reversado)</th>
        <th>REQ</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($ocAllocationsVoided as $alloc): ?>
      <?php
        $folio = oc_folio_label((string) ($alloc['folio_year'] ?? ''), (int) ($alloc['numero_oc'] ?? 0));
        $voidedRaw = trim((string) ($alloc['voided_at'] ?? ''));
        $voidedLabel = $voidedRaw !== '' ? substr($voidedRaw, 0, 16) : '—';
        $ocDesc = trim((string) ($alloc['po_line_descriptions'] ?? ''));
        if ($ocDesc === '') {
            $ocDesc = trim((string) ($alloc['po_note'] ?? ''));
        }
        $ocSupplier = trim((string) ($alloc['supplier_trade_name'] ?? '')) !== ''
            ? trim((string) $alloc['supplier_trade_name'])
            : trim((string) ($alloc['supplier_legal_name'] ?? ''));
      ?>
      <tr style="color:#64748b;">
        <td>
          <a href="../oc/view_from_activity.php?id=<?= (int) ($alloc['purchase_order_id'] ?? 0) ?>&amp;activity_id=<?= $hid ?>">
            <?= h($folio) ?>
          </a>
          <span style="margin-left:0.35rem;font-size:0.8rem;font-weight:600;color:#b91c1c;">Anulada</span>
        </td>
        <td><?= h(substr((string) ($alloc['ordered_at'] ?? ''), 0, 10)) ?></td>
        <td><?= h($voidedLabel) ?></td>
        <td style="max-width:14rem;font-size:0.88rem;"><?= $ocDesc !== '' ? h($ocDesc) : '—' ?></td>
        <td style="max-width:12rem;font-size:0.88rem;"><?= $ocSupplier !== '' ? h($ocSupplier) : '—' ?></td>
        <td><?= h(budget_account_label($pdo, [
            'account_id' => (int) ($alloc['account_id'] ?? 0),
            'name' => (string) ($alloc['account_name'] ?? ''),
        ])) ?></td>
        <td class="num"><?= budget_int_fmt((float) ($alloc['amount'] ?? 0)) ?></td>
        <td><?= h((string) ($alloc['req_number'] ?? '')) !== '' ? h((string) $alloc['req_number']) : '—' ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>
<?php require dirname(__DIR__) . '/inc/layout_bottom.php';
