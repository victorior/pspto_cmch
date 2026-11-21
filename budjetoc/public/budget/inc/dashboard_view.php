<?php

declare(strict_types=1);

/** @var bool $dashboardShowActivities */
/** @var int $budgetYear */
/** @var int $yCal */
/** @var int $yearPrev */
/** @var int $yearNext */
/** @var bool $nextYearEditEnabled */
/** @var bool $dashboardReceiveOnlyNotice */
/** @var array{name: string, tenant_name: string} $currentUserInfo */
/** @var bool $showDepartmentSelector */
/** @var list<array{id: int, name: string, type: string, parent_id: int|null}> $departmentsForSelector */
/** @var int|null $selectedDepartmentId */
/** @var bool $encargadoEsDivision */
/** @var string|null $encargadoView */
/** @var bool $adminSeleccionoCoordinacion */
/** @var string|null $adminView */
/** @var bool $adminVistaCoordinacion */
/** @var bool $adminVistaDivisionPorCentrosCosto */
/** @var list<array<string, mixed>> $list */
/** @var int $month */
/** @var array<int, string> $months */
/** @var string|null $dashboardNuevaActividadUrl */
/** @var string|null $dashboardNuevaActividadBlockedReason */
/** @var string|null $dashboardExportUrl */
/** @var string|null $dashboardExportMetaUrl */
/** @var bool $isEncargado */
/** @var string|null $selectedDepartmentKind */
/** @var string|null $selectedDepartmentName */
/** @var array<int, bool> $listEditFlags */

$listEditFlags = $listEditFlags ?? [];

$dashboardReturnDepartmentId = isset($_GET['return_department_id']) ? (int) $_GET['return_department_id'] : 0;

$dashboardBaseUrl = static function (int $year) use (
    $month,
    $selectedDepartmentId,
    $encargadoEsDivision,
    $encargadoView,
    $adminSeleccionoCoordinacion,
    $adminView,
    $dashboardShowActivities
): string {
    $query = ['budget_year' => $year, 'month' => $month];
    if ($encargadoEsDivision && $encargadoView !== null) {
        $query['encargado_view'] = $encargadoView;
    }
    if ($adminSeleccionoCoordinacion && $adminView !== null) {
        $query['admin_view'] = $adminView;
    }

    return budget_dashboard_url(
        ($selectedDepartmentId !== null && $selectedDepartmentId > 0) ? $selectedDepartmentId : null,
        $query,
        $dashboardShowActivities
    );
};

$dashboardUrl = static function (?int $departmentId, array $query = [], bool $showActivities = false): string {
    return budget_dashboard_url($departmentId, $query, $showActivities);
};
?>
<?php if (!$dashboardShowActivities): ?>
<h1 class="dash-title">Dashboard</h1>
<?php if ($dashboardReceiveOnlyNotice): ?>
<div class="alert alert-warn">Esta coordinacion solo recibe imputaciones de otras areas; no puede crear nuevas actividades de presupuesto.</div>
<?php endif; ?>
<p class="dash-muted">Seleccione el <?= h(t_es('ano')) ?> presupuestario y un mes para ver las actividades.</p>
<div class="dash-pills">
  <span class="dash-muted" style="margin-right:0.25rem;"><?= h(t_es('ano')) ?>:</span>
  <?php
    $yearsUi = [
        ['y' => $yearPrev, 'label' => (string) $yearPrev, 'dim' => true, 'active' => $budgetYear === $yearPrev],
        ['y' => $yCal, 'label' => $yCal . ' (actual)', 'dim' => false, 'active' => $budgetYear === $yCal],
        ['y' => $yearNext, 'label' => (string) $yearNext, 'dim' => !$nextYearEditEnabled && $budgetYear !== $yearNext, 'active' => $budgetYear === $yearNext],
    ];
    foreach ($yearsUi as $item):
        $cls = 'dash-pill' . (!empty($item['active']) ? ' active' : '') . (!empty($item['dim']) && empty($item['active']) ? ' dim' : '');
    ?>
  <a href="<?= h($dashboardBaseUrl((int) $item['y'])) ?>" class="<?= $cls ?>"><?= h($item['label']) ?></a>
  <?php endforeach; ?>
</div>
<?php if (!$nextYearEditEnabled && $budgetYear === $yearNext): ?>
<p class="alert alert-warn">El <?= h(t_es('ano')) ?> <?= (int) $yearNext ?> esta en solo lectura hasta que un administrador active permisos de edicion.</p>
<?php endif; ?>
<?php endif; ?>

<?php if (!$dashboardShowActivities && $showDepartmentSelector && $departmentsForSelector !== []): ?>
<?php
$departamentos = array_values(array_filter($departmentsForSelector, static fn (array $d): bool => ($d['type'] ?? '') === 'estandar'));
$coordinacionesAll = array_values(array_filter($departmentsForSelector, static fn (array $d): bool => ($d['type'] ?? '') === 'coordinacion'));
$coordinacionesOperativas = [];
$centrosCostoCatalogo = [];
foreach ($coordinacionesAll as $d) {
    if (BudgetActivityRules::isCatalogReceiveCostCenterName((string) ($d['name'] ?? ''))) {
        $centrosCostoCatalogo[] = $d;
    } else {
        $coordinacionesOperativas[] = $d;
    }
}
$centrosCostoCatalogo = BudgetActivityRules::sortCatalogReceiveDepartments($centrosCostoCatalogo);
usort(
    $coordinacionesOperativas,
    static fn (array $a, array $b): int => strcmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''))
);
$selectorOptionCount = count($departamentos) + count($coordinacionesOperativas) + count($centrosCostoCatalogo);

$renderSelectorColumn = static function (
    string $title,
    array $items
) use (
    $budgetYear,
    $month,
    $selectedDepartmentId,
    $dashboardUrl
): void {
    ?>
  <div class="dash-section">
    <h2><?= h($title) ?></h2>
    <table class="data">
      <tbody>
        <?php if ($items === []): ?>
        <tr><td class="dash-muted">—</td></tr>
        <?php else: ?>
        <?php foreach ($items as $d):
            $did = (int) $d['id'];
            $isSelected = $selectedDepartmentId === $did;
            $deptQuery = ['budget_year' => $budgetYear, 'month' => $month];
            if ($selectedDepartmentId !== null && $selectedDepartmentId > 0) {
                $deptQuery['return_department_id'] = $selectedDepartmentId;
            }
            $deptUrl = $dashboardUrl($did, $deptQuery, true);
        ?>
        <tr class="<?= $isSelected ? 'row-selected' : '' ?>">
          <td><a href="<?= h($deptUrl) ?>" class="dash-dept-btn<?= $isSelected ? ' selected' : '' ?>"><?= h($d['name']) ?> &rarr;</a></td>
        </tr>
        <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
    <?php
};
?>
<?php if ($selectorOptionCount > 1): ?>
<p class="dash-muted"><strong>Seleccione una Division, Coordinacion o Centro de Costo:</strong></p>
<?php endif; ?>
<div class="dash-grid cols-3">
  <?php $renderSelectorColumn('Divisiones', $departamentos); ?>
  <?php $renderSelectorColumn('Coordinaciones/C.C.', $coordinacionesOperativas); ?>
  <?php $renderSelectorColumn('Centros de Costo', $centrosCostoCatalogo); ?>
</div>
<?php if ($selectorOptionCount > 1 && ($selectedDepartmentId === null || $selectedDepartmentId <= 0)): ?>
<p class="dash-muted">Elija una Division, Coordinacion o Centro de Costo arriba para ver las actividades de forma acotada.</p>
<?php endif; ?>
<?php endif; ?>


<?php if ($dashboardShowActivities && ($selectedDepartmentId ?? 0) > 0): ?>
<?php
$cameFromOtherDepartment = $dashboardReturnDepartmentId > 0
    && $dashboardReturnDepartmentId !== (int) $selectedDepartmentId;
$canPickOtherDepartment = $showDepartmentSelector && count($departmentsForSelector) > 1;
$dashboardShowVolver = $cameFromOtherDepartment || $canPickOtherDepartment;
$volverUrl = '';
if ($dashboardShowVolver) {
    $volverDepartmentId = $dashboardReturnDepartmentId > 0
        ? $dashboardReturnDepartmentId
        : (int) ($selectedDepartmentId ?? 0);
    $volverUrl = $dashboardUrl(
        $volverDepartmentId > 0 ? $volverDepartmentId : null,
        ['budget_year' => $budgetYear, 'month' => $month],
        false
    );
}
$activityBarTenant = trim((string) ($currentUserInfo['tenant_name'] ?? ''));
?>
<div class="dash-activity-top">
  <p class="dash-activity-top__line">
    <?php if (($selectedDepartmentKind ?? '') !== '' && ($selectedDepartmentName ?? '') !== ''): ?>
    <strong><?= h($selectedDepartmentKind) ?>: <?= h($selectedDepartmentName) ?></strong>
    <span class="dash-muted"> &mdash; Presupuesto <?= (int) $budgetYear ?> &mdash; <?= h($months[$month] ?? (string) $month) ?><?php if ($activityBarTenant !== ''): ?> | <?= h($activityBarTenant) ?><?php endif; ?></span>
    <?php endif; ?>
    <span class="dash-activity-top__actions">
      <?php if ($dashboardShowVolver): ?>
      <a href="<?= h($volverUrl) ?>" class="btn btn-back btn-sm">&larr; Volver</a>
      <?php endif; ?>
      <?php if ($dashboardNuevaActividadUrl !== null): ?>
      <a href="<?= h($dashboardNuevaActividadUrl) ?>" class="btn btn-primary btn-sm">Nueva actividad</a>
      <?php endif; ?>
      <?php if ($dashboardExportUrl !== null && ($dashboardExportMetaUrl ?? null) !== null): ?>
      <button type="button"
              class="btn btn-sm js-export-activities"
              data-export-url="<?= h($dashboardExportUrl) ?>"
              data-export-meta="<?= h($dashboardExportMetaUrl) ?>">
        Descargar Excel
      </button>
      <?php endif; ?>
    </span>
  </p>
</div>
<?php if ($dashboardNuevaActividadBlockedReason !== null): ?>
<p class="alert alert-warn"><?= h($dashboardNuevaActividadBlockedReason) ?></p>
<?php endif; ?>
<?php endif; ?>

<?php if ($dashboardShowActivities && ($selectedDepartmentId ?? 0) > 0 && ($encargadoEsDivision || $adminSeleccionoCoordinacion)): ?>
<p class="dash-muted"><strong>Que listado desea ver?</strong></p>
<div class="dash-view-toggle">
  <?php
    $paramView = $adminSeleccionoCoordinacion ? 'admin_view' : 'encargado_view';
    $currentView = $adminSeleccionoCoordinacion
        ? ($adminView ?? 'by_centros_costo')
        : ($encargadoView ?? 'by_division');
    $baseUrl = $dashboardUrl($selectedDepartmentId, ['budget_year' => $budgetYear, 'month' => $month], true);
  ?>
  <a href="<?= h($baseUrl . '&' . $paramView . '=by_centros_costo') ?>" class="dash-pill<?= $currentView === 'by_centros_costo' ? ' active' : '' ?>">Ver actividades por Coordinaciones / C. C.</a>
  <a href="<?= h($baseUrl . '&' . $paramView . '=by_division') ?>" class="dash-pill<?= $currentView === 'by_division' ? ' active' : '' ?>">Ver actividades cargadas por esta Division</a>
</div>
<?php endif; ?>

<?php if ($dashboardShowActivities && ($selectedDepartmentId ?? 0) > 0): ?>
<div class="dash-months">
  <?php foreach ($months as $num => $name):
      $monthQuery = ['budget_year' => $budgetYear, 'month' => $num];
      if ($encargadoEsDivision && $encargadoView !== null) {
          $monthQuery['encargado_view'] = $encargadoView;
      }
      if ($adminSeleccionoCoordinacion && $adminView !== null) {
          $monthQuery['admin_view'] = $adminView;
      }
      $monthNavUrl = $dashboardUrl($selectedDepartmentId, $monthQuery, true);
  ?>
  <a href="<?= h($monthNavUrl) ?>" class="dash-pill<?= $num === $month ? ' active' : '' ?>"><?= h($name) ?></a>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php
$showList = $dashboardShowActivities && ($selectedDepartmentId ?? 0) > 0;
if ($showList):
    $listReturnQuery = ['budget_year' => $budgetYear, 'month' => $month];
    if ($encargadoEsDivision && $encargadoView !== null) {
        $listReturnQuery['encargado_view'] = $encargadoView;
    }
    if ($adminSeleccionoCoordinacion && $adminView !== null) {
        $listReturnQuery['admin_view'] = $adminView;
    }
    $listReturnUrl = $dashboardUrl($selectedDepartmentId, $listReturnQuery, true);
    $activityReturnSuffix = '&return=' . rawurlencode($listReturnUrl);
    $encargadoEsCoordinacion = $isEncargado && !$encargadoEsDivision;
    $vistaCentrosCosto = ($encargadoEsDivision && ($encargadoView ?? '') === 'by_centros_costo')
        || ($adminSeleccionoCoordinacion && ($adminView ?? '') === 'by_centros_costo');
    $vistaPorDivisionDescarga = ($encargadoEsDivision && ($encargadoView ?? '') === 'by_division')
        || ($adminSeleccionoCoordinacion && ($adminView ?? '') === 'by_division');
    $ocultarColumnaCostoYUsarCosto = $vistaCentrosCosto || $encargadoEsCoordinacion || $adminVistaCoordinacion;
    $ocultarColumnaCentroCosto = $vistaPorDivisionDescarga;
    $ocultarColumnaCoordinacion = false;
    $numColumnas = 8;
    if (!$ocultarColumnaCostoYUsarCosto) {
        $numColumnas++;
    }
    if (!$ocultarColumnaCoordinacion && !$ocultarColumnaCentroCosto) {
        $numColumnas++;
    }
    $na = '-';
?>
<div class="card">
<table class="data">
  <thead>
    <tr>
      <th>Mes</th>
      <?php if (!$ocultarColumnaCostoYUsarCosto): ?><th><?= budget_ccosto_coordinacion_header() ?></th><?php endif; ?>
      <th>Division</th>
      <?php if (!$ocultarColumnaCoordinacion && !$ocultarColumnaCentroCosto): ?>
      <th><?= budget_ccosto_coordinacion_header() ?></th>
      <?php endif; ?>
      <th>Actividad</th>
      <th>N&deg; Actividad</th>
      <th>Observacion</th>
      <th class="num">Presupuesto</th>
      <th class="num">Ocupado</th>
      <th class="num">Quedan</th>
      <th>Accion</th>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($list as $row):
        $totalRow = (float) ($row['total'] ?? 0);
        $occNum = isset($row['occupied_amount']) ? (float) $row['occupied_amount'] : null;
        $occCell = $occNum !== null ? budget_int_fmt($occNum) : $na;
        $quedanCell = $occNum !== null
            ? budget_int_fmt(max(0.0, round($totalRow - $occNum, 2)))
            : (isset($row['remaining_amount']) ? budget_int_fmt((float) $row['remaining_amount']) : $na);
        $obsCab = trim((string) ($row['observations'] ?? ''));
    ?>
    <tr>
      <td><?= h($months[(int) $row['month']] ?? (string) $row['month']) ?></td>
      <?php if (!$ocultarColumnaCostoYUsarCosto): ?>
      <td><?= h(budget_ccosto_coordinacion_label($row)) ?></td>
      <?php endif; ?>
      <td><?= h((string) ($row['division_name'] ?? $na)) ?></td>
      <?php if (!$ocultarColumnaCoordinacion && !$ocultarColumnaCentroCosto): ?>
      <td><?= h(budget_ccosto_coordinacion_label($row)) ?></td>
      <?php endif; ?>
      <td><?= h((string) ($row['activity_name'] ?? '')) ?></td>
      <td><?= h((string) ($row['activity_number'] ?? '')) ?></td>
      <td style="max-width:14rem;font-size:0.8rem;"><?= $obsCab !== '' ? h($obsCab) : $na ?></td>
      <td class="num"><?= budget_int_fmt($totalRow) ?></td>
      <td class="num"><?= h($occCell) ?></td>
      <td class="num"><?= h($quedanCell) ?></td>
      <td class="actions-cell">
        <a href="activity_view.php?id=<?= (int) $row['id'] ?><?= h($activityReturnSuffix) ?>">Ver</a>
        <?php if (!empty($listEditFlags[(int) $row['id']])): ?>
        &nbsp;|&nbsp;<a href="activity_edit.php?id=<?= (int) $row['id'] ?><?= h($activityReturnSuffix) ?>">Editar</a>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
    <?php if ($list === []): ?>
    <tr><td colspan="<?= $numColumnas ?>">No hay actividades en este mes.</td></tr>
    <?php endif; ?>
  </tbody>
</table>
</div>
<?php endif; ?>

<?php if (($dashboardExportUrl ?? null) !== null && ($dashboardExportMetaUrl ?? null) !== null): ?>
<div id="export-modal" class="export-modal" hidden aria-hidden="true">
  <div class="export-modal__backdrop"></div>
  <div class="export-modal__panel" role="dialog" aria-modal="true" aria-labelledby="export-modal-title">
    <div class="export-modal__visual" aria-hidden="true">
      <div class="export-modal__doc">
        <div class="export-modal__doc-sheet export-modal__doc-sheet--back"></div>
        <div class="export-modal__doc-sheet export-modal__doc-sheet--front">
          <span class="export-modal__doc-icon">CSV</span>
        </div>
      </div>
    </div>
    <h2 id="export-modal-title" class="export-modal__title">Preparando exportacion</h2>
    <p class="export-modal__subtitle" id="export-modal-subtitle">Ano presupuestario completo — un momento por favor.</p>
    <div class="export-modal__progress-wrap">
      <div class="export-modal__progress-track">
        <div class="export-modal__progress-bar" id="export-progress-bar" style="width:0%"></div>
      </div>
      <p class="export-modal__progress-text" id="export-progress-text">0%</p>
    </div>
    <p class="export-modal__detail" id="export-progress-detail">Iniciando…</p>
    <p class="export-modal__hint">No cierre esta ventana ni pulse de nuevo el boton hasta que termine la descarga.</p>
  </div>
</div>
<script src="js/export_activities.js?v=20260529" defer></script>
<?php endif; ?>
