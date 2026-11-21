<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap_ajax.php';
header('Content-Type: text/html; charset=utf-8');
Auth::checkPortal(Db::pdo($CONFIG), Auth::REALM_OC);

$pdo = Db::pdo($CONFIG);
$tid = (int) Auth::tenantId();
$q = trim((string) ($_GET['q'] ?? ''));
$year = (int) ($_GET['year'] ?? 0);
if ($year <= 0) {
    $year = (int) date('Y');
}

$rows = BudgetService::searchLinesForOc($pdo, $tid, $q, $year, 80, false);

if ($rows === []) {
    echo '<p class="text-muted">Sin lineas con saldo para ' . h((string) $year) . '. Busque por '
        . h(t_es('n_ordinal') . ' actividad') . ' (ej. 9815-' . $year . '), codigo u observacion.</p>';
    exit;
}
?>
<div class="table-responsive">
  <table class="table table-bordered table-condensed table-hover" style="margin-bottom:0;">
    <thead>
      <tr class="warning">
        <th><?= h(t_es('n_ordinal')) ?></th>
        <th>Cod</th>
        <th><?= h(t_es('observacion')) ?></th>
        <th class="text-right">Monto</th>
        <th class="text-right">Utilizado</th>
        <th class="text-right">Quedan</th>
        <th class="text-right" style="min-width:7rem;">Ocupar</th>
        <th class="text-center" style="width:4rem;">Agregar</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <?php
        $did = (int) $r['detail_id'];
        $actNum = (string) $r['activity_number'];
        $actShort = $actNum;
        if (preg_match('/^(\d+)-/', $actNum, $m) === 1) {
            $actShort = $m[1];
        }
        $cod = (string) (int) ($r['account_code'] ?? 0);
        $obs = trim((string) ($r['line_observation'] ?? $r['observation'] ?? ''));
        $lineTot = round((float) $r['line_total'], 2);
        $lineUtil = round((float) ($r['utilized'] ?? 0), 2);
        $lineRem = round((float) $r['line_remaining'], 2);
        $hdrRem = round((float) $r['header_remaining'], 2);
        $hasSaldo = $lineRem > 0.005 && $hdrRem > 0.005;
        $ocuparDefault = $hasSaldo ? (float) ($r['ocupar_default'] ?? min($lineRem, $hdrRem)) : 0.0;
        $ocuparTitle = 'Monto linea: ' . money_fmt($lineTot)
            . '. Saldo linea: ' . money_fmt($lineRem)
            . '. Saldo actividad: ' . money_fmt($hdrRem) . '.';
        ?>
      <tr>
        <td><?= h($actShort) ?></td>
        <td><?= h($cod) ?></td>
        <td><?= h($obs) ?></td>
        <td class="text-right"><?= h(money_fmt($lineTot)) ?></td>
        <td class="text-right"><?= h(money_fmt($lineUtil)) ?></td>
        <td class="text-right"><?= h(money_fmt($lineRem)) ?></td>
        <td class="text-right">
          <input type="number" step="any" min="0" class="form-control input-sm text-right budget-ocupar-input"
                 id="precio_venta_<?= $did ?>"
                 name="ocupar_<?= $did ?>"
                 title="<?= h($ocuparTitle) ?>"
                 value="<?= h((string) $ocuparDefault) ?>"
                 data-max="<?= h((string) $ocuparDefault) ?>"
                 <?= $hasSaldo ? '' : 'disabled' ?>>
        </td>
        <td class="text-center">
          <button type="button" class="btn btn-info btn-sm btn-pick-budget"
            data-detail-id="<?= $did ?>"
            data-activity="<?= h($actNum) ?>"
            data-account="<?= h((string) ($r['account_label'] ?? '')) ?>"
            data-ocupar-max="<?= h((string) $ocuparDefault) ?>"
            data-line-rem="<?= h((string) $lineRem) ?>"
            data-header-rem="<?= h((string) $hdrRem) ?>"
            title="<?= $hasSaldo ? 'Agregar imputacion' : 'Sin saldo disponible' ?>"
            <?= $hasSaldo ? '' : 'disabled' ?>>
            <span class="glyphicon glyphicon-plus"></span>
          </button>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
