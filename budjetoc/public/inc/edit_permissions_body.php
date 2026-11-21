<?php

declare(strict_types=1);

/** @var PDO $pdo */
/** @var int $tenantId */
/** @var int $policyYear */
/** @var list<array{id: int, name: string, slug: string}> $tenants */
/** @var list<array{id: int, name: string, type: string, allow_edit: int}> $policies */
/** @var string $formAction */
/** @var string|null $formPostAction */
/** @var bool $showTenantPicker */
/** @var string $err */
/** @var string $ok */

$years = EditPolicyService::selectableYears();
?>
<?php if ($err !== ''): ?>
<div class="alert alert-err"><?= h($err) ?></div>
<?php endif; ?>
<?php if ($ok !== ''): ?>
<div class="alert alert-ok"><?= h($ok) ?></div>
<?php endif; ?>

<form method="get" class="form-inline" style="margin-bottom:1rem;display:flex;flex-wrap:wrap;gap:0.75rem;align-items:flex-end;">
  <?php if ($showTenantPicker): ?>
  <div>
    <label for="tenant_id">Tenant</label>
    <select name="tenant_id" id="tenant_id" onchange="this.form.submit()">
      <?php foreach ($tenants as $t): ?>
      <option value="<?= (int) $t['id'] ?>"<?= (int) $t['id'] === $tenantId ? ' selected' : '' ?>><?= h($t['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <?php else: ?>
  <input type="hidden" name="tenant_id" value="<?= $tenantId ?>">
  <?php endif; ?>
  <div>
    <label for="year">Ano (permisos)</label>
    <select name="year" id="year" onchange="this.form.submit()">
      <?php foreach ($years as $y): ?>
      <option value="<?= $y ?>"<?= $y === $policyYear ? ' selected' : '' ?>><?= h(EditPolicyService::yearLabel($y)) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <noscript><button type="submit" class="btn">Aplicar</button></noscript>
</form>

<?php $formPostAction = $formPostAction ?? admin_href($formAction); ?>
<form method="post" action="<?= h($formPostAction) ?>" id="edit-policy-form">
  <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
  <input type="hidden" name="tenant_id" value="<?= $tenantId ?>">
  <input type="hidden" name="year" value="<?= $policyYear ?>">

  <table class="data edit-policy-table">
    <thead>
      <tr>
        <th style="width:10rem;">Permiso de edicion</th>
        <th>Division / Centro de Costo</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($policies as $row): ?>
      <tr>
        <td>
          <label class="edit-policy-check">
            <input type="checkbox" name="allow_edit[<?= (int) $row['id'] ?>]" value="1"<?= $row['allow_edit'] === 1 ? ' checked' : '' ?>>
            Pueden editar
          </label>
        </td>
        <td>
          <strong><?= h($row['name']) ?></strong>
          <span class="dash-muted">(<?= h(EditPolicyService::departmentTypeLabel($row['type'])) ?>)</span>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <div class="edit-policy-bulk" style="margin:1rem 0;">
    <span class="dash-muted" style="margin-right:0.5rem;">Seleccionar todos:</span>
    <button type="button" class="btn" id="btn-all-edit">Todos pueden editar</button>
    <button type="button" class="btn" id="btn-all-view">Todos solo ver</button>
  </div>

  <p>
    <button type="submit" name="action" value="save" class="btn btn-primary">Guardar</button>
  </p>
</form>

<script>
(function () {
  var form = document.getElementById('edit-policy-form');
  if (!form) return;
  var boxes = form.querySelectorAll('input[type=checkbox][name^="allow_edit"]');
  document.getElementById('btn-all-edit')?.addEventListener('click', function () {
    boxes.forEach(function (cb) { cb.checked = true; });
  });
  document.getElementById('btn-all-view')?.addEventListener('click', function () {
    boxes.forEach(function (cb) { cb.checked = false; });
  });
})();
</script>
