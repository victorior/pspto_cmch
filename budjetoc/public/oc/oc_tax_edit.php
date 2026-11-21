<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';
require_once __DIR__ . '/inc/oc_layout_nav.php';

$pdo = Db::pdo($CONFIG);
Auth::checkPortal($pdo, Auth::REALM_OC);
$tid = (int) Auth::tenantId();
$year = (int) ($_GET['year'] ?? $_POST['year'] ?? date('Y'));
$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$err = trim((string) ($_GET['err'] ?? ''));

$editData = $id > 0 ? OcService::getPurchaseOrderForEdit($pdo, $tid, $id) : null;
if ($editData === null) {
    header('Location: index.php?year=' . $year . '&err=notfound');
    exit;
}
$po = $editData['order'];
if (!OcService::canChangeTaxProfile($po)) {
    header('Location: index.php?year=' . $year . '&status=2');
    exit;
}

$folioYear = (string) ($po['folio_year'] ?? $year);
$year = (int) $folioYear;
$numeroOc = (int) ($po['numero_oc'] ?? 0);
$statusKey = (int) ($po['status'] ?? 0);

$taxDocTypeDefault = strtolower(trim((string) ($po['tax_doc_type'] ?? '')));
$taxFacturaModeDefault = '';
$taxRetencionPercentDefault = '';
$taxRetencionTouched = false;

if ($taxDocTypeDefault === '') {
    $modeHydrate = strtolower(trim((string) ($po['tax_mode'] ?? '')));
    if ($modeHydrate === 'retencion') {
        $taxDocTypeDefault = 'boleta';
    } elseif ($modeHydrate === 'iva' || $modeHydrate === 'exento') {
        $taxDocTypeDefault = 'factura';
    }
}
if ($taxDocTypeDefault === 'factura') {
    $modeHydrate = strtolower(trim((string) ($po['tax_mode'] ?? '')));
    $taxFacturaModeDefault = $modeHydrate === 'exento' ? 'exento' : 'iva';
}
if ($taxDocTypeDefault === 'boleta') {
    $pctHydrate = $po['tax_percent'] ?? null;
    if ($pctHydrate !== null && $pctHydrate !== '') {
        $taxRetencionPercentDefault = rtrim(rtrim(number_format((float) $pctHydrate, 4, '.', ''), '0'), '.');
        $taxRetencionTouched = true;
    } else {
        $taxRetencionPercentDefault = rtrim(
            rtrim(number_format(oc_boleta_retencion_default_for_year((int) $folioYear), 4, '.', ''), '0'),
            '.'
        );
    }
}

$statusLabels = [
    OcService::STATUS_ABIERTA => 'Abierta',
    OcService::STATUS_CERRADA => 'Cerrada',
    OcService::STATUS_ANULADA => 'Anulada',
];
$statusLabel = $statusLabels[$statusKey] ?? (string) $statusKey;

$layoutTitle = t_es('perfil_tributario');
$layoutRealm = 'oc';
$layoutBootstrap = true;
$layoutCss = '../css/app.css';
$layoutPageHeading = t_es('perfil_tributario') . ' — OC ' . $folioYear . '-' . $numeroOc;
$layoutBackHref = 'index.php?year=' . $year . '&status=' . $statusKey;
$layoutBackLabel = 'Volver a OC';
$layoutNav = oc_layout_nav($pdo, $year, $statusKey === OcService::STATUS_CERRADA ? 'closed' : 'open');
require dirname(__DIR__) . '/inc/layout_top.php';
?>
<?php if ($err !== ''): ?><p class="alert alert-err"><?= h($err) ?></p><?php endif; ?>
<form method="post" action="update.php" class="card" style="max-width:36rem;" id="oc_tax_edit_form">
  <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
  <input type="hidden" name="id" value="<?= $id ?>">
  <input type="hidden" name="year" value="<?= $year ?>">
  <input type="hidden" name="tax_only" value="1">
  <input type="hidden" name="folio_year" id="folio_year" value="<?= h($folioYear) ?>">

  <p class="dash-muted" style="margin-top:0;">
    Estado: <?= h($statusLabel) ?> · Proveedor: <?= h((string) ($po['supplier_display'] ?? '')) ?>
  </p>
  <p class="dash-muted" style="font-size:0.85rem;">
    Solo se actualiza el perfil tributario. Lineas, presupuesto y proveedor no se modifican.
  </p>

  <label for="tax_doc_type">Tipo de documento *</label>
  <select name="tax_doc_type" id="tax_doc_type" required style="display:block;width:100%;max-width:20rem;">
    <option value="">Seleccione</option>
    <option value="factura" <?= $taxDocTypeDefault === 'factura' ? 'selected' : '' ?>><?= h(t_es('factura')) ?></option>
    <option value="boleta" <?= $taxDocTypeDefault === 'boleta' ? 'selected' : '' ?>>Boleta de Honorarios</option>
  </select>

  <?php
    $facturaModeUi = $taxFacturaModeDefault === 'exento' ? 'exento' : 'iva';
    $showFacturaMode = $taxDocTypeDefault === 'factura';
    $showRetencion = $taxDocTypeDefault === 'boleta';
  ?>
  <div id="tax_factura_mode_wrap" style="margin-top:0.75rem;<?= $showFacturaMode ? '' : 'display:none;' ?>">
    <label class="dash-muted" style="display:block;">Rama factura</label>
    <label style="display:inline-flex;align-items:center;gap:0.35rem;margin-right:1rem;">
      <input type="radio" name="tax_factura_mode" id="tax_factura_mode_iva" value="iva"
             <?= $facturaModeUi === 'iva' ? 'checked' : '' ?>> Afecta IVA
    </label>
    <label style="display:inline-flex;align-items:center;gap:0.35rem;">
      <input type="radio" name="tax_factura_mode" id="tax_factura_mode_exento" value="exento"
             <?= $facturaModeUi === 'exento' ? 'checked' : '' ?>> Exenta
    </label>
  </div>

  <div id="tax_retencion_wrap" style="margin-top:0.75rem;<?= $showRetencion ? '' : 'display:none;' ?>">
    <label for="tax_retencion_percent">Retencion %</label>
    <input type="number" name="tax_retencion_percent" id="tax_retencion_percent"
           step="0.01" min="0.01" max="100"
           value="<?= h($taxRetencionPercentDefault) ?>"
           style="display:block;width:100%;max-width:10rem;"
           <?= $showRetencion ? '' : 'disabled' ?>>
  </div>

  <p style="margin-top:1rem;">
    <button type="submit" class="btn btn-primary"><?= h(t_es('guardar_perfil_tributario')) ?></button>
    <a class="btn" href="<?= h($layoutBackHref) ?>" style="margin-left:0.5rem;">Cancelar</a>
  </p>
</form>
<script>
(function () {
  var BOLETA_RETENCION_BY_YEAR = <?= json_encode(oc_boleta_retencion_rate_map(), JSON_UNESCAPED_UNICODE) ?>;
  var retencionTouched = <?= $taxRetencionTouched ? 'true' : 'false' ?>;
  var form = document.getElementById('oc_tax_edit_form');
  var sel = document.getElementById('tax_doc_type');
  var facturaWrap = document.getElementById('tax_factura_mode_wrap');
  var retencionWrap = document.getElementById('tax_retencion_wrap');
  var retencionInp = document.getElementById('tax_retencion_percent');
  var folioYear = document.getElementById('folio_year');

  function boletaDefaultForYear(y) {
    var year = parseInt(String(y || ''), 10);
    if (isNaN(year)) year = new Date().getFullYear();
    if (year <= 2026) return BOLETA_RETENCION_BY_YEAR[2026] != null ? Number(BOLETA_RETENCION_BY_YEAR[2026]) : 15.25;
    if (year === 2027) return BOLETA_RETENCION_BY_YEAR[2027] != null ? Number(BOLETA_RETENCION_BY_YEAR[2027]) : 16;
    return BOLETA_RETENCION_BY_YEAR[2028] != null ? Number(BOLETA_RETENCION_BY_YEAR[2028]) : 17;
  }
  function formatRetencionInput(rate) {
    var x = Math.round(Number(rate) * 10000) / 10000;
    return String(x).replace(/(\.\d*?)0+$/, '$1').replace(/\.$/, '');
  }
  function syncVisibility() {
    var t = String(sel.value || '').toLowerCase();
    var isFactura = t === 'factura';
    var isBoleta = t === 'boleta';
    facturaWrap.style.display = isFactura ? '' : 'none';
    retencionWrap.style.display = isBoleta ? '' : 'none';
    retencionInp.disabled = !isBoleta;
    if (isFactura && !document.querySelector('input[name="tax_factura_mode"]:checked')) {
      document.getElementById('tax_factura_mode_iva').checked = true;
    }
    if (isBoleta && !retencionTouched) {
      retencionInp.value = formatRetencionInput(boletaDefaultForYear(folioYear.value));
    }
  }
  sel.addEventListener('change', function () {
    if (String(sel.value || '').toLowerCase() === 'boleta') {
      retencionTouched = false;
    }
    syncVisibility();
  });
  retencionInp.addEventListener('input', function () { retencionTouched = true; });
  retencionInp.addEventListener('change', function () { retencionTouched = true; });
  form.addEventListener('submit', function (ev) {
    var t = String(sel.value || '').toLowerCase();
    if (t !== 'factura' && t !== 'boleta') {
      ev.preventDefault();
      sel.focus();
      return;
    }
    if (t === 'factura' && !document.querySelector('input[name="tax_factura_mode"]:checked')) {
      ev.preventDefault();
      document.getElementById('tax_factura_mode_iva').checked = true;
      return;
    }
    if (t === 'boleta') {
      retencionInp.disabled = false;
      var raw = String(retencionInp.value || '').trim().replace(',', '.');
      var pct = parseFloat(raw);
      if (raw === '' || isNaN(pct) || pct <= 0 || pct > 100) {
        ev.preventDefault();
        retencionWrap.style.display = '';
        retencionInp.focus();
      }
    }
  });
  syncVisibility();
})();
</script>
<?php require dirname(__DIR__) . '/inc/layout_bottom.php';
