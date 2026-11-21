<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';
$pdo = Db::pdo($CONFIG);
Auth::checkPortal($pdo, Auth::REALM_BUDGET);
$tid = (int) Auth::tenantId();
$uid = (int) Auth::userId();
if (isset($_GET['budget_year'])) {
    $by = (int) $_GET['budget_year'];
    $yCalInit = (int) date('Y');
    if (in_array($by, [$yCalInit - 1, $yCalInit, $yCalInit + 1], true)) {
        if (!isset($_SESSION['budjetoc_budget_year']) || !is_array($_SESSION['budjetoc_budget_year'])) {
            $_SESSION['budjetoc_budget_year'] = [];
        }
        $_SESSION['budjetoc_budget_year'][$tid] = $by;
    }
}
$year = (int) ($_POST['year'] ?? $_GET['year'] ?? $_SESSION['budjetoc_budget_year'][$tid] ?? date('Y'));
$yCal = (int) date('Y');
if ($year < $yCal - 1 || $year > $yCal + 1) {
    $year = $yCal;
}

$isAdmin = UserPermissions::isBudgetTenantAdmin($pdo, $uid, $tid);
$isEncargado = !$isAdmin;
$allowedDeptIds = UserPermissions::allowedDepartmentIds($pdo, $uid, $tid);
$allDepts = $isEncargado && $allowedDeptIds !== null
    ? DepartmentCatalog::listByIds($pdo, $tid, $allowedDeptIds)
    : DepartmentCatalog::listByTenant($pdo, $tid);
$divisions = array_values(array_filter($allDepts, static fn (array $d): bool => ($d['type'] ?? '') === 'estandar'));

if (!isset($_SESSION['budjetoc_budget_admin_dept']) || !is_array($_SESSION['budjetoc_budget_admin_dept'])) {
    $_SESSION['budjetoc_budget_admin_dept'] = [];
}
if ($isAdmin && isset($_GET['clear_ctx'])) {
    unset($_SESSION['budjetoc_budget_admin_dept'][$tid]);
    header('Location: activity_new.php?year=' . $year);
    exit;
}
if ($isAdmin && isset($_GET['department_id'])) {
    $preset = (int) $_GET['department_id'];
    if ($preset > 0 && DepartmentCatalog::getById($pdo, $preset, $tid) !== null) {
        $_SESSION['budjetoc_budget_admin_dept'][$tid] = $preset;
        header('Location: activity_new.php?year=' . $year);
        exit;
    }
}
$adminDeptSession = (int) ($_SESSION['budjetoc_budget_admin_dept'][$tid] ?? 0);

$departments = $allDepts;

if ($isEncargado && $departments !== [] && isset($_GET['department_id'])) {
    $pickEnc = (int) $_GET['department_id'];
    if ($allowedDeptIds !== null && in_array($pickEnc, $allowedDeptIds, true)) {
        $selEnc = null;
        $restEnc = [];
        foreach ($departments as $d) {
            if ((int) $d['id'] === $pickEnc) {
                $selEnc = $d;
            } else {
                $restEnc[] = $d;
            }
        }
        if ($selEnc !== null) {
            $departments = array_values(array_merge([$selEnc], $restEnc));
        }
    }
}

$months = [
    1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril', 5 => 'Mayo', 6 => 'Junio',
    7 => 'Julio', 8 => 'Agosto', 9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre',
];

$formError = null;
if (isset($_GET['error'])) {
    if ($_GET['error'] === 'detail') {
        $formError = 'Debe agregar al menos una fila con cuenta asociada seleccionada.';
    } elseif ($_GET['error'] === 'save') {
        $formError = 'Error al guardar la actividad. Intente de nuevo.';
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
            $details[] = [
                'account_code_id' => $acId,
                'quantity' => (float) ($row['quantity'] ?? 1),
                'unit_price' => (float) ($row['unit_price'] ?? 0),
                'observation' => utf8_normalize(trim((string) ($row['observation'] ?? ''))),
            ];
        }
    }
    if ($details === []) {
        header('Location: activity_new.php?year=' . $year . '&error=detail');
        exit;
    }

    if ($allowedDeptIds !== null) {
        $allowedIds = array_map('intval', $allowedDeptIds);
        $deptId = (int) ($header['department_id'] ?? 0);
        $divId = (int) ($header['division_id'] ?? 0);
        $ok = false;
        if ($deptId > 0 && in_array($deptId, $allowedIds, true)) {
            $ok = true;
        } elseif ($divId > 0 && in_array($divId, $allowedIds, true)) {
            $ok = true;
        } elseif ($deptId > 0) {
            $sub = DepartmentCatalog::getById($pdo, $deptId, $tid);
            if ($sub !== null && ($sub['type'] ?? '') === 'estandar') {
                $p = (int) ($sub['parent_id'] ?? 0);
                if ($p > 0 && in_array($p, $allowedIds, true)) {
                    $ok = true;
                }
            }
        }
        if (!$ok) {
            http_response_code(403);
            echo 'No puede asignar ese departamento o division.';
            exit;
        }
    }

    $creatorDeptId = (int) ($_POST['creator_department_id'] ?? 0);
    if ($creatorDeptId <= 0) {
        $dDeptId = (int) ($header['department_id'] ?? 0);
        $dDivId = (int) ($header['division_id'] ?? 0);
        if ($dDeptId > 0) {
            $deptRow = DepartmentCatalog::getById($pdo, $dDeptId, $tid);
            if ($deptRow !== null && ($deptRow['type'] ?? '') === 'coordinacion') {
                $creatorDeptId = $dDeptId;
            }
        }
        if ($creatorDeptId <= 0 && $dDivId > 0) {
            $creatorDeptId = $dDivId;
        }
    }

    $rules = BudgetActivityRules::validateAndNormalizeHeader($pdo, $tid, $header, $creatorDeptId > 0 ? $creatorDeptId : null);
    if (!$rules['ok']) {
        header('Location: activity_new.php?year=' . $year . '&error=rules&code=' . rawurlencode($rules['error']));
        exit;
    }
    $header = $rules['header'];

    try {
        $hid = BudgetService::createActivity($pdo, $tid, $header, $details, $year, $creatorDeptId > 0 ? $creatorDeptId : null);
        header('Location: activity_view.php?id=' . $hid);
        exit;
    } catch (Throwable $e) {
        header('Location: activity_new.php?year=' . $year . '&error=save');
        exit;
    }
}

$header = [];
$divisionOptions = [];
$encargadoEsCoordinacion = false;
$adminSeleccionoCoordinacion = false;
$areasBajoDivision = [];

if ($isEncargado && $departments !== []) {
    $firstType = $departments[0]['type'] ?? 'estandar';
    $encargadoEsCoordinacion = $firstType === 'coordinacion';
    if ($encargadoEsCoordinacion) {
        if (empty($header['department_id'])) {
            $header['department_id'] = (int) $departments[0]['id'];
        }
        if (empty($header['division_id']) && $divisions !== []) {
            $header['division_id'] = (int) $divisions[0]['id'];
        }
        $coord0 = $departments[0];
        if (BudgetActivityRules::isGestionEstadioCoordination($coord0)) {
            $eid = BudgetActivityRules::findEstadioDivisionId($pdo, $tid);
            if ($eid !== null) {
                $divisions = array_values(array_filter($divisions, static fn (array $d): bool => (int) $d['id'] === $eid));
                $header['division_id'] = $eid;
            }
        }
    } else {
        if (empty($header['division_id'])) {
            $header['division_id'] = (int) $departments[0]['id'];
        }
        $divisionOptions = $departments;
    }
}

if ($isAdmin && $adminDeptSession > 0) {
    foreach ($allDepts as $d) {
        if ((int) $d['id'] === $adminDeptSession) {
            if (($d['type'] ?? '') === 'coordinacion') {
                $adminSeleccionoCoordinacion = true;
                $divisionOptions = [];
                $header['department_id'] = (int) $d['id'];
                $departments = [$d];
                if (empty($header['division_id']) && $divisions !== []) {
                    $header['division_id'] = (int) $divisions[0]['id'];
                }
                if (BudgetActivityRules::isGestionEstadioCoordination($d)) {
                    $eid = BudgetActivityRules::findEstadioDivisionId($pdo, $tid);
                    if ($eid !== null) {
                        $divisions = array_values(array_filter($divisions, static fn (array $x): bool => (int) $x['id'] === $eid));
                        $header['division_id'] = $eid;
                    }
                }
            } else {
                $divisionOptions = [$d];
                $header['division_id'] = (int) $d['id'];
            }
            break;
        }
    }
}

$divForAreas = (int) ($header['division_id'] ?? 0);
if ($divForAreas > 0 && !$adminSeleccionoCoordinacion && !$encargadoEsCoordinacion) {
    $areasBajoDivision = DepartmentCatalog::listAreasUnderDivision($pdo, $tid, $divForAreas);
}

$predCreator = null;
if ($isEncargado && $departments !== []) {
    $predCreator = (int) ($departments[0]['id'] ?? 0);
} elseif ($isAdmin && $adminDeptSession > 0) {
    $predCreator = $adminDeptSession;
}

$creatorDeptId = $predCreator ?? 0;

$cabeceraTitulo = 'Cabecera';
if ($creatorDeptId > 0) {
    $creatorDeptRow = DepartmentCatalog::getById($pdo, $creatorDeptId, $tid);
    if ($creatorDeptRow !== null) {
        $cabeceraTitulo = (string) $creatorDeptRow['name'];
    }
}

$costCenters = CostCenterCatalog::listByTenant($pdo, $tid);

$activities = $pdo->prepare('SELECT id, name FROM activities WHERE tenant_id = ? ORDER BY name');
$activities->execute([$tid]);
$activities = $activities->fetchAll(PDO::FETCH_ASSOC);

$accountCodes = $pdo->prepare('SELECT id, account_id, name FROM account_codes WHERE tenant_id = ? ORDER BY account_id');
$accountCodes->execute([$tid]);
$accountCodes = $accountCodes->fetchAll(PDO::FETCH_ASSOC);

$mostrarCentroCosto = ($isAdmin || ($isEncargado && !$encargadoEsCoordinacion)) && !$adminSeleccionoCoordinacion;
$mostrarDivisionCoordinacion = ($isEncargado && $encargadoEsCoordinacion) || $adminSeleccionoCoordinacion;
$deptListCoord = $departments;
$ocultarSelectorCoordinacionUnica = $adminSeleccionoCoordinacion && count($deptListCoord) === 1;
$mostrarDivisionUnica = $divisionOptions !== []
    && !$adminSeleccionoCoordinacion
    && (($isEncargado && !$encargadoEsCoordinacion) || $isAdmin);

$encargadoMultiDepto = $isEncargado && $allowedDeptIds !== null && count($allowedDeptIds) > 1;
$adminSinContexto = $isAdmin && $adminDeptSession <= 0;

$layoutTitle = 'Nueva actividad';
$layoutRealm = 'budget';
$layoutBootstrap = true;
$layoutCss = '../css/app.css';
$layoutNav = [
    ['href' => 'index.php', 'label' => 'Home'],
    ['href' => 'activity_new.php', 'label' => 'Nueva', 'active' => true],
];
require dirname(__DIR__) . '/inc/layout_top.php';
?>
<?php if ($formError): ?><p class="alert alert-err"><?= h($formError) ?></p><?php endif; ?>
<?php if ($isEncargado && $departments === []): ?>
<p class="alert alert-err">No tiene divisiones ni coordinaciones asignadas. Contacte al administrador.</p>
<?php endif; ?>

<?php if ($encargadoMultiDepto): ?>
<div class="card">
  <p><strong>Crear actividad como:</strong></p>
  <p>
    <?php foreach ($departments as $d): ?>
    <a class="btn" href="activity_new.php?year=<?= $year ?>&amp;department_id=<?= (int) $d['id'] ?>"><?= h($d['name']) ?></a>
    <?php endforeach; ?>
  </p>
</div>
<?php endif; ?>

<?php if ($adminSinContexto): ?>
<div class="card">
  <p><strong>Contexto (division o coordinacion):</strong> elija desde que unidad crea la actividad.</p>
  <label for="admin_ctx">Departamento</label>
  <select id="admin_ctx" onchange="if(this.value) location.href='activity_new.php?year=<?= $year ?>&amp;department_id='+this.value;">
    <option value="">Seleccione...</option>
    <?php foreach ($allDepts as $d): ?>
    <option value="<?= (int) $d['id'] ?>"><?= h($d['name']) ?> (<?= h($d['type']) ?>)</option>
    <?php endforeach; ?>
  </select>
</div>
<?php elseif ($isAdmin && $adminDeptSession > 0): ?>
<?php $ctxDept = DepartmentCatalog::getById($pdo, $adminDeptSession, $tid); ?>
<p class="alert" style="background:#e0f2fe;color:#0369a1;">Contexto: <?= h($ctxDept['name'] ?? '') ?>
  <a href="activity_new.php?year=<?= $year ?>&amp;clear_ctx=1">Cambiar</a></p>
<?php endif; ?>

<?php if (!$adminSinContexto && !($isEncargado && $departments === [])): ?>
<form method="post" class="card" accept-charset="UTF-8">
  <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
  <input type="hidden" name="year" value="<?= $year ?>">
  <input type="hidden" name="creator_department_id" value="<?= $creatorDeptId ?>">

  <h2><?= h($cabeceraTitulo) ?></h2>
  <section class="grid">
    <div>
      <label for="month">Mes</label>
      <select name="month" id="month" required>
        <?php foreach ($months as $num => $name): ?>
        <option value="<?= $num ?>"<?= $num === (int) date('n') ? ' selected' : '' ?>><?= h($name) ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <?php if ($mostrarCentroCosto): ?>
    <div>
      <label for="cost_center_id">Centro de costo</label>
      <select name="cost_center_id" id="cost_center_id" required>
        <option value="">Seleccione</option>
        <?php foreach ($costCenters as $cc): ?>
        <option value="<?= (int) $cc['id'] ?>"<?= isset($header['cost_center_id']) && (int) $header['cost_center_id'] === (int) $cc['id'] ? ' selected' : '' ?>><?= h($cc['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <?php endif; ?>

    <?php if ($areasBajoDivision !== [] && $mostrarCentroCosto && !$mostrarDivisionCoordinacion): ?>
    <div style="grid-column: 1 / -1;">
      <label for="department_id_area">Area / centro organico (opcional)</label>
      <select name="department_id" id="department_id_area">
        <option value="">Ninguno</option>
        <?php foreach ($areasBajoDivision as $ad): ?>
        <option value="<?= (int) $ad['id'] ?>"<?= isset($header['department_id']) && (int) $header['department_id'] === (int) $ad['id'] ? ' selected' : '' ?>><?= h($ad['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <?php endif; ?>

    <?php if ($mostrarDivisionCoordinacion): ?>
    <div style="grid-column: 1 / -1;">
      <label for="division_id">Division de destino</label>
      <p style="font-size:0.8rem;color:var(--muted);margin:0 0 0.35rem;">La coordinacion imputa el gasto a la division que elija aqui.</p>
      <select name="division_id" id="division_id" required>
        <option value="">Seleccione</option>
        <?php foreach ($divisions as $div): ?>
        <option value="<?= (int) $div['id'] ?>"<?= isset($header['division_id']) && (int) $header['division_id'] === (int) $div['id'] ? ' selected' : '' ?>><?= h($div['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <?php endif; ?>

    <?php if (($isEncargado && $encargadoEsCoordinacion) || ($adminSeleccionoCoordinacion && !$ocultarSelectorCoordinacionUnica)): ?>
    <div>
      <label for="department_id">Coordinacion</label>
      <select name="department_id" id="department_id" required>
        <?php if (count($deptListCoord) > 1): ?>
        <option value="">Seleccione</option>
        <?php endif; ?>
        <?php foreach ($deptListCoord as $d): ?>
        <option value="<?= (int) $d['id'] ?>"<?= isset($header['department_id']) && (int) $header['department_id'] === (int) $d['id'] ? ' selected' : '' ?>><?= h($d['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <?php elseif ($adminSeleccionoCoordinacion && $ocultarSelectorCoordinacionUnica): ?>
    <input type="hidden" name="department_id" value="<?= (int) ($deptListCoord[0]['id'] ?? 0) ?>">
    <?php endif; ?>

    <?php if ($mostrarDivisionUnica): ?>
    <div>
      <label for="division_id_enc">Division</label>
      <select name="division_id" id="division_id_enc" required>
        <?php foreach ($divisionOptions as $d): ?>
        <option value="<?= (int) $d['id'] ?>"<?= (isset($header['division_id']) && (int) $header['division_id'] === (int) $d['id']) || (!isset($header['division_id']) && (int) $d['id'] === (int) ($divisionOptions[0]['id'] ?? 0)) ? ' selected' : '' ?>><?= h($d['name']) ?></option>
        <?php endforeach; ?>
      </select>
      <?php if ($areasBajoDivision === []): ?>
      <input type="hidden" name="department_id" value="">
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <div>
      <label for="activity_id">Actividad</label>
      <select name="activity_id" id="activity_id" required>
        <option value="">Seleccione</option>
        <?php foreach ($activities as $a): ?>
        <option value="<?= (int) $a['id'] ?>"><?= h($a['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div style="grid-column: 1 / -1;">
      <label for="observations">Observaciones</label>
      <input type="text" name="observations" id="observations" maxlength="100" required placeholder="Max. 100 caracteres">
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
        <tr class="detail-row">
          <td>
            <select name="details[0][account_code_id]" required>
              <option value="">Seleccione</option>
              <?php foreach ($accountCodes as $ac): ?>
              <option value="<?= (int) $ac['id'] ?>"><?= h(account_code_label($ac)) ?></option>
              <?php endforeach; ?>
            </select>
          </td>
          <td class="col-qty"><input type="number" step="0.01" min="0" name="details[0][quantity]" class="quantity" value="1" required></td>
          <td class="col-unit"><input type="number" step="0.01" min="0" name="details[0][unit_price]" class="unit-price" value="0" required></td>
          <td class="num"><span class="row-total">0</span></td>
          <td><input type="text" name="details[0][observation]" maxlength="500" required></td>
          <td><button type="button" class="btn-remove btn">Quitar</button></td>
        </tr>
      </tbody>
    </table>
    <p class="num" style="font-weight:600;margin-top:0.5rem;">Total: <span data-grand-total>0</span></p>
    <button type="button" data-add-row class="btn">+ Agregar fila</button>
  </div>

  <p style="margin-top:1rem;">
    <button type="submit" class="btn btn-primary">Guardar actividad</button>
    <a href="index.php" class="btn">Cancelar</a>
  </p>
</form>

<script>
window.BUDGET_ACCOUNT_OPTIONS = <?= json_encode(array_map(static function (array $ac): array {
    return ['id' => (int) $ac['id'], 'account_id' => (int) $ac['account_id'], 'name' => (string) $ac['name'], 'label' => account_code_label($ac)];
}, $accountCodes), JSON_UNESCAPED_UNICODE) ?>;
</script>
<script src="../js/budget-detail.js"></script>
<?php endif; ?>
<?php require dirname(__DIR__) . '/inc/layout_bottom.php';
