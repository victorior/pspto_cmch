<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';
require_once __DIR__ . '/inc/oc_layout_nav.php';

$pdo = Db::pdo($CONFIG);
Auth::checkPortal($pdo, Auth::REALM_OC);
$tid = (int) Auth::tenantId();
$year = (int) ($_GET['year'] ?? $_POST['year'] ?? date('Y'));
if ($year < 2000 || $year > 2100) {
    $year = (int) date('Y');
}

$error = null;
$result = null;
$detailLines = [];
$ocAllocationsVoided = [];

$searchNum = trim((string) ($_GET['activity_number'] ?? ''));
if ($searchNum !== '') {
    $result = BudgetService::findHeaderByActivityNumber($pdo, $tid, $searchNum, $year);
    if ($result === null) {
        $error = t_es('no_actividad') . ' "' . $searchNum . '" (' . $year . ').';
    } else {
        $hid = (int) $result['id'];
        $ocImputations = BudgetService::ocImputationsForHeader($pdo, $tid, $hid);
        $detailLines = BudgetService::detailLinesForOcBudgetEditor($pdo, $tid, $hid, $ocImputations);
        BudgetService::applyHeaderSummaryFromDetails($result, $detailLines);
        $ocAllocationsVoided = BudgetService::allocationsForHeaderVoided($pdo, $tid, $hid);
    }
}

$bfBudgetId = static function ($v): string {
    if ($v === null || $v === '') {
        return t_es('em_dash');
    }

    return (string) (int) $v;
};

$bfLabel = static function (?string $s): string {
    $t = trim((string) $s);

    return $t !== '' ? $t : t_es('em_dash');
};

$layoutTitle = 'Presupuesto OC';
$layoutRealm = 'oc';
$layoutBootstrap = true;
$layoutCss = '../css/app.css';
$layoutPageHeading = 'Presupuesto';
$layoutBreadcrumb = [
    ['href' => 'index.php?year=' . $year, 'label' => 'Inicio'],
    ['label' => 'Presupuesto'],
];
$layoutBackHref = 'index.php?year=' . $year;
$layoutBackLabel = 'Volver al listado OC';
$layoutNav = oc_layout_nav($pdo, $year, 'budget');
$layoutBodyClass = 'page-oc-budget';
require dirname(__DIR__) . '/inc/layout_top.php';
?>
<style>
  .oc-budget-table-wrap { overflow-x: auto; margin-top: 0.75rem; }
  table.budget-oc-lines { width: 100%; font-size: 0.88rem; }
  table.budget-oc-lines th, table.budget-oc-lines td {
    white-space: normal; word-wrap: break-word; vertical-align: top;
    padding: 0.35rem 0.4rem;
  }
  table.budget-oc-lines td.budget-cell-obs { max-width: 12rem; }
</style>
<?php if ($error): ?>
<p class="alert alert-err"><?= h($error) ?></p>
<?php endif; ?>
<?php if ($result && $detailLines !== []): ?>
<p class="alert alert-err">
  <?= h('Página sólo de consultas. Las imputaciones se realizan al crear o editar la O.C. respectiva.') ?>
</p>
<?php endif; ?>

<div class="card">
  <form method="get" class="grid" style="align-items:end;gap:0.75rem;">
    <input type="hidden" name="year" value="<?= $year ?>">
    <div>
      <label for="budget_activity_number">Buscar <?= h(t_es('n_ordinal')) ?> de Actividad</label>
      <input id="budget_activity_number" type="text" name="activity_number"
        value="<?= h($searchNum) ?>" required autocomplete="off"
        placeholder="Ej. 9314 o 9314-<?= $year ?>" style="max-width:16rem;">
    </div>
    <div>
      <button type="submit" class="btn btn-primary">Buscar</button>
    </div>
  </form>
</div>

<?php if ($result): ?>
<div class="card">
  <div class="oc-budget-table-wrap" style="margin-top:0;">
    <table class="data" style="margin-bottom:0.75rem;">
      <thead>
        <tr>
          <th>Mes</th>
          <th>C. costo</th>
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
          <td><?= h(budget_month_label((int) ($result['month'] ?? 0))) ?></td>
          <td><?= h($bfLabel($result['cost_center_name'] ?? $result['department_name'] ?? null)) ?></td>
          <td><?= h($bfLabel($result['division_name'] ?? null)) ?></td>
          <td><?= h((string) ($result['activity_name'] ?? '')) ?></td>
          <td><?= h((string) ($result['activity_number'] ?? '')) ?></td>
          <td><?= nl2br(h((string) ($result['observations'] ?? ''))) ?></td>
          <td class="num"><?= h(budget_int_fmt((float) ($result['total'] ?? 0))) ?></td>
          <td class="num"><?= h(budget_int_fmt((float) ($result['occupied'] ?? 0))) ?></td>
          <td class="num"><?= h(budget_int_fmt((float) ($result['remaining'] ?? 0))) ?></td>
        </tr>
      </tbody>
    </table>
  </div>

  <?php if ($detailLines !== []): ?>
  <div class="oc-budget-table-wrap">
      <table class="data budget-oc-lines">
        <thead>
          <tr>
            <th>Num</th>
            <th class="num">Mes</th>
            <th>Ccosto</th>
            <th>Div</th>
            <th>Actividad</th>
            <th>Obs</th>
            <th class="num">Ano</th>
            <th>Cta Asociada</th>
            <th class="num">Monto</th>
            <th>Sub_obs.</th>
            <th class="num">Cod</th>
            <th class="num">Utilizado</th>
            <th><?= h(t_es('n_ordinal')) ?> O.C.</th>
            <th><?= h(t_es('n_ordinal')) ?> Req.</th>
            <th class="num">Quedan</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($detailLines as $ln): ?>
          <?php
            $lt = (float) ($ln['line_total'] ?? 0);
            $utilVal = $ln['utilized_amount'];
            $utilStr = ($utilVal !== null && $utilVal !== '')
                ? budget_int_fmt((float) $utilVal)
                : '—';
            $quedan = (float) ($ln['quedan'] ?? max(0, $lt));
            $ocNum = trim((string) ($ln['oc_number'] ?? ''));
            $reqNum = trim((string) ($ln['req_number'] ?? ''));
            ?>
          <tr>
            <td><?= h((string) ($ln['num'] ?? '')) ?></td>
            <td class="num"><?= h($bfBudgetId($ln['month'] ?? null)) ?></td>
            <td><?= h($bfLabel($ln['cost_center_name'] ?? $ln['department_name'] ?? null)) ?></td>
            <td><?= h($bfLabel($ln['division_name'] ?? null)) ?></td>
            <td><?= h((string) ($ln['activity_name'] ?? '')) ?></td>
            <td class="budget-cell-obs"><?= nl2br(h((string) ($ln['header_observations'] ?? ''))) ?></td>
            <td class="num"><?= (int) ($ln['year'] ?? $year) ?></td>
            <td><?= h((string) ($ln['account_label'] ?? '')) ?></td>
            <td class="num"><?= h(budget_int_fmt($lt)) ?></td>
            <td class="budget-cell-obs"><?= nl2br(h((string) ($ln['line_observation'] ?? ''))) ?></td>
            <td class="num"><?= h((string) ($ln['account_id'] ?? '')) ?></td>
            <td class="num"><?= h($utilStr) ?></td>
            <td><?= $ocNum !== '' ? h($ocNum) : '—' ?></td>
            <td><?= $reqNum !== '' ? h($reqNum) : '—' ?></td>
            <td class="num"><?= h(budget_int_fmt($quedan)) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
  </div>
  <?php else: ?>
  <p class="text-muted">La actividad no tiene subactividades (lineas de detalle).</p>
  <?php endif; ?>

  <?php if ($ocAllocationsVoided !== []): ?>
  <div style="margin-top:1.25rem;padding-top:1rem;border-top:1px solid #fecaca;">
    <h3 style="margin:0 0 0.5rem;font-size:1rem;">Historial — OC anuladas (imputaciones reversadas)</h3>
    <p class="text-muted" style="margin:0 0 0.75rem;font-size:0.88rem;">No consumen saldo; registro de lo imputado antes de anular.</p>
    <table class="data">
      <thead>
        <tr>
          <th>Nº OC</th>
          <th>Fecha OC</th>
          <th>Anulada</th>
          <th>Cuenta</th>
          <th class="num">Monto</th>
          <th>REQ</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($ocAllocationsVoided as $alloc): ?>
        <?php
          $folio = oc_folio_label((string) ($alloc['folio_year'] ?? ''), (int) ($alloc['numero_oc'] ?? 0));
          $voidedRaw = trim((string) ($alloc['voided_at'] ?? ''));
        ?>
        <tr>
          <td><?= h($folio) ?> <span style="color:#b91c1c;font-size:0.8rem;font-weight:600;">Anulada</span></td>
          <td><?= h(substr((string) ($alloc['ordered_at'] ?? ''), 0, 10)) ?></td>
          <td><?= $voidedRaw !== '' ? h(substr($voidedRaw, 0, 16)) : '—' ?></td>
          <td><?= h(account_code_label(['account_id' => $alloc['account_id'] ?? 0, 'name' => $alloc['account_name'] ?? ''])) ?></td>
          <td class="num"><?= h(budget_int_fmt((float) ($alloc['amount'] ?? 0))) ?></td>
          <td><?= trim((string) ($alloc['req_number'] ?? '')) !== '' ? h((string) $alloc['req_number']) : '—' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php require dirname(__DIR__) . '/inc/layout_bottom.php'; ?>
