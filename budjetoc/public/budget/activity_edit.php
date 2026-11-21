<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';
$pdo = Db::pdo($CONFIG);
Auth::checkPortal($pdo, Auth::REALM_BUDGET);
$tid = (int) Auth::tenantId();
$uid = (int) Auth::userId();
$hid = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$returnRaw = trim((string) ($_GET['return'] ?? $_POST['return'] ?? ''));
$listReturnHref = budget_list_return_href($returnRaw !== '' ? $returnRaw : null);
$returnSuffix = budget_return_query_suffix($returnRaw !== '' ? $returnRaw : null);
$listContext = budget_list_context_from_return($pdo, $tid, $returnRaw !== '' ? $returnRaw : null);

$h = BudgetService::getHeader($pdo, $tid, $hid);
if ($h === null) {
    header('Location: index.php');
    exit;
}

$year = budget_year_from_activity_number((string) $h['activity_number']);
if (!UserPermissions::canEditActivityHeader($pdo, $uid, $tid, $h, $year)) {
    http_response_code(403);
    exit('Esta actividad esta en solo lectura para su perfil o ' . t_es('ano') . ' seleccionado.');
}

$isAdmin = UserPermissions::isBudgetTenantAdmin($pdo, $uid, $tid);
$creatorDeptId = (int) ($h['created_by'] ?? 0);
if ($creatorDeptId <= 0) {
    $creatorDeptId = (int) ($h['department_id'] ?? 0);
}
if ($creatorDeptId <= 0) {
    $creatorDeptId = (int) ($h['division_id'] ?? 0);
}
$creatorRow = $creatorDeptId > 0 ? DepartmentCatalog::getById($pdo, $creatorDeptId, $tid) : null;
$adminSeleccionoCoordinacion = $creatorRow !== null && ($creatorRow['type'] ?? '') === 'coordinacion';
$mostrarCentroCosto = !$adminSeleccionoCoordinacion;

$months = [
    1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril', 5 => 'Mayo', 6 => 'Junio',
    7 => 'Julio', 8 => 'Agosto', 9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre',
];

$formError = null;
if (isset($_GET['error'])) {
    if ($_GET['error'] === 'detail') {
        $formError = 'Debe mantener al menos una linea con cuenta asociada.';
    } elseif ($_GET['error'] === 'save') {
        $formError = 'No se pudo guardar. Revise montos e imputaciones OC.';
    } elseif ($_GET['error'] === 'rules' && !empty($_GET['code'])) {
        $formError = BudgetActivityRules::errorMessage((string) $_GET['code']);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post_csrf();
    $header = [
        'month' => (int) ($_POST['month'] ?? 0),
        'cost_center_id' => !empty($_POST['cost_center_id']) ? (int) $_POST['cost_center_id'] : null,
        'activity_id' => (int) ($_POST['activity_id'] ?? 0),
        'division_id' => !empty($_POST['division_id']) ? (int) $_POST['division_id'] : null,
        'department_id' => !empty($_POST['department_id']) ? (int) $_POST['department_id'] : null,
        'observations' => mb_substr(utf8_normalize(trim((string) ($_POST['observations'] ?? ''))), 0, 100),
    ];
    $details = [];
    $rawDetails = is_array($_POST['details'] ?? null) ? array_values($_POST['details']) : [];
    foreach ($rawDetails as $row) {
        if (!is_array($row)) {
            continue;
        }
        $acId = isset($row['account_code_id']) ? (int) $row['account_code_id'] : 0;
        if ($acId > 0) {
            $line = [
                'account_code_id' => $acId,
                'quantity' => (float) ($row['quantity'] ?? 1),
                'unit_price' => (float) ($row['unit_price'] ?? 0),
                'observation' => utf8_normalize(trim((string) ($row['observation'] ?? ''))),
            ];
            if (!empty($row['id'])) {
                $line['id'] = (int) $row['id'];
            }
            $details[] = $line;
        }
    }
    if ($details === []) {
        header('Location: activity_edit.php?id=' . $hid . '&error=detail' . $returnSuffix);
        exit;
    }

    $rules = BudgetActivityRules::validateAndNormalizeHeader($pdo, $tid, $header, $creatorDeptId > 0 ? $creatorDeptId : null);
    if (!$rules['ok']) {
        header('Location: activity_edit.php?id=' . $hid . '&error=rules&code=' . rawurlencode($rules['error']) . $returnSuffix);
        exit;
    }
    $header = $rules['header'];

    try {
        BudgetService::updateActivity($pdo, $tid, $hid, $header, $details);
        header('Location: activity_view.php?id=' . $hid . '&ok=1' . $returnSuffix);
        exit;
    } catch (Throwable) {
        header('Location: activity_edit.php?id=' . $hid . '&error=save' . $returnSuffix);
        exit;
    }
}

$stL = $pdo->prepare(
    'SELECT bd.*, ac.account_id, ac.name AS account_name
     FROM budget_details bd
     INNER JOIN account_codes ac ON ac.id = bd.account_code_id
     WHERE bd.budget_header_id = ?
     ORDER BY bd.id'
);
$stL->execute([$hid]);
$lines = $stL->fetchAll(PDO::FETCH_ASSOC);

$costCenters = CostCenterCatalog::listByTenant($pdo, $tid);

$activities = $pdo->prepare('SELECT id, name FROM activities WHERE tenant_id = ? ORDER BY name');
$activities->execute([$tid]);
$activities = $activities->fetchAll(PDO::FETCH_ASSOC);

$accountCodes = $pdo->prepare('SELECT id, account_id, name FROM account_codes WHERE tenant_id = ? ORDER BY account_id');
$accountCodes->execute([$tid]);
$accountCodes = $accountCodes->fetchAll(PDO::FETCH_ASSOC);

$allDepts = DepartmentCatalog::listByTenant($pdo, $tid);
$divisions = array_values(array_filter($allDepts, static fn (array $d): bool => ($d['type'] ?? '') === 'estandar'));
$areasBajoDivision = [];
$divId = (int) ($h['division_id'] ?? 0);
if ($divId > 0 && !$adminSeleccionoCoordinacion) {
    $areasBajoDivision = DepartmentCatalog::listAreasUnderDivision($pdo, $tid, $divId);
}

$layoutTitle = 'Editar ' . $h['activity_number'];
$layoutRealm = 'budget';
$layoutBootstrap = true;
$layoutCss = '../css/app.css';
$layoutPageHeading = 'Editar actividad';
$layoutBackHref = 'activity_view.php?id=' . $hid . $returnSuffix;
$layoutBackLabel = 'Volver';
$layoutNav = [
    ['href' => $listReturnHref, 'label' => 'Home'],
    ['href' => 'activity_view.php?id=' . $hid . $returnSuffix, 'label' => (string) $h['activity_number']],
];
require dirname(__DIR__) . '/inc/layout_top.php';
?>
<?php if ($formError): ?><p class="alert alert-err"><?= h($formError) ?></p><?php endif; ?>
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

<form method="post" class="card" accept-charset="UTF-8">
  <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
  <?php if ($returnRaw !== '' && $listReturnHref !== 'index.php'): ?>
  <input type="hidden" name="return" value="<?= h($listReturnHref) ?>">
  <?php endif; ?>
  <input type="hidden" name="id" value="<?= $hid ?>">

  <h2><?= h((string) $h['activity_number']) ?></h2>
  <section class="grid">
    <div>
      <label for="month">Mes</label>
      <select name="month" id="month" required>
        <?php foreach ($months as $num => $name): ?>
        <option value="<?= $num ?>"<?= $num === (int) $h['month'] ? ' selected' : '' ?>><?= h($name) ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <?php if ($mostrarCentroCosto): ?>
    <div>
      <label for="cost_center_id">Centro de costo</label>
      <select name="cost_center_id" id="cost_center_id" required>
        <option value="">Seleccione</option>
        <?php foreach ($costCenters as $cc): ?>
        <option value="<?= (int) $cc['id'] ?>"<?= (int) ($h['cost_center_id'] ?? 0) === (int) $cc['id'] ? ' selected' : '' ?>><?= h($cc['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <?php endif; ?>

    <?php if ($adminSeleccionoCoordinacion): ?>
    <div>
      <label for="division_id">Division de destino</label>
      <select name="division_id" id="division_id" required>
        <?php foreach ($divisions as $d): ?>
        <option value="<?= (int) $d['id'] ?>"<?= (int) ($h['division_id'] ?? 0) === (int) $d['id'] ? ' selected' : '' ?>><?= h($d['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <input type="hidden" name="department_id" value="<?= (int) ($h['department_id'] ?? 0) ?>">
    <?php elseif ($divId > 0): ?>
    <input type="hidden" name="division_id" value="<?= $divId ?>">
    <?php if ($areasBajoDivision !== []): ?>
    <div style="grid-column: 1 / -1;">
      <label for="department_id_area">Area / centro organico (opcional)</label>
      <select name="department_id" id="department_id_area">
        <option value="">Ninguno</option>
        <?php foreach ($areasBajoDivision as $ad): ?>
        <option value="<?= (int) $ad['id'] ?>"<?= (int) ($h['department_id'] ?? 0) === (int) $ad['id'] ? ' selected' : '' ?>><?= h($ad['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <?php endif; ?>
    <?php endif; ?>

    <div>
      <label for="activity_id">Actividad</label>
      <select name="activity_id" id="activity_id" required>
        <?php foreach ($activities as $a): ?>
        <option value="<?= (int) $a['id'] ?>"<?= (int) $h['activity_id'] === (int) $a['id'] ? ' selected' : '' ?>><?= h($a['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div style="grid-column: 1 / -1;">
      <label for="observations">Observaciones</label>
      <input type="text" name="observations" id="observations" maxlength="100" required value="<?= h((string) ($h['observations'] ?? '')) ?>">
    </div>
  </section>

  <h2 style="margin-top:1.25rem;">Detalle presupuesto</h2>
  <div data-budget-detail>
    <table class="data table-budget-detail">
      <colgroup>
        <col class="col-cuenta">
        <col class="col-qty">
        <col class="col-unit">
        <col class="col-total">
        <col class="col-obs">
        <col class="col-actions">
      </colgroup>
      <thead>
        <tr>
          <th>Cuenta</th>
          <th class="col-qty">Cant.</th>
          <th class="col-unit">$ unitario</th>
          <th class="num">Total</th>
          <th>Observacion</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($lines as $i => $ln): ?>
        <?php $lt = round((float) $ln['quantity'] * (float) $ln['unit_price'], 2); ?>
        <tr class="detail-row">
          <td>
            <input type="hidden" name="details[<?= $i ?>][id]" value="<?= (int) $ln['id'] ?>">
            <select name="details[<?= $i ?>][account_code_id]" required>
              <option value="">Seleccione</option>
              <?php foreach ($accountCodes as $ac): ?>
              <option value="<?= (int) $ac['id'] ?>"<?= (int) $ln['account_code_id'] === (int) $ac['id'] ? ' selected' : '' ?>><?= h(account_code_label($ac)) ?></option>
              <?php endforeach; ?>
            </select>
          </td>
          <td class="col-qty"><input type="number" step="0.01" min="0" name="details[<?= $i ?>][quantity]" class="quantity" value="<?= h((string) $ln['quantity']) ?>" required></td>
          <td class="col-unit"><input type="number" step="0.01" min="0" name="details[<?= $i ?>][unit_price]" class="unit-price" value="<?= h((string) $ln['unit_price']) ?>" required></td>
          <td class="num"><span class="row-total"><?= budget_int_fmt($lt) ?></span></td>
          <td><input type="text" name="details[<?= $i ?>][observation]" maxlength="500" required value="<?= h((string) ($ln['observation'] ?? '')) ?>"></td>
          <td><button type="button" class="btn-remove btn">Quitar</button></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <p class="num" style="font-weight:600;margin-top:0.5rem;">Total: <span data-grand-total>0</span></p>
    <button type="button" data-add-row class="btn">+ Agregar fila</button>
  </div>

  <p style="margin-top:1rem;">
    <button type="submit" class="btn btn-primary">Guardar cambios</button>
    <a href="activity_view.php?id=<?= $hid ?><?= h($returnSuffix) ?>" class="btn">Cancelar</a>
  </p>
</form>

<script>
window.BUDGET_ACCOUNT_OPTIONS = <?= json_encode(array_map(static function (array $ac): array {
    return ['id' => (int) $ac['id'], 'account_id' => (int) $ac['account_id'], 'name' => (string) $ac['name'], 'label' => account_code_label($ac)];
}, $accountCodes), JSON_UNESCAPED_UNICODE) ?>;
</script>
<script src="../js/budget-detail.js"></script>
<?php require dirname(__DIR__) . '/inc/layout_bottom.php';
