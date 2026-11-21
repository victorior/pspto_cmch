<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';
Auth::startRealm(Auth::REALM_OC);
Auth::check(Auth::REALM_OC);
$pdo = Db::pdo($CONFIG);
$tid = (int) Auth::tenantId();
$uid = (int) Auth::userId();
$year = (string) ($_GET['year'] ?? $_POST['year'] ?? date('Y'));
$budgetYear = (int) $year;
$err = '';
$ok = '';
$editPoId = (int) ($_GET['id'] ?? $_POST['purchase_order_id'] ?? 0);
$editData = null;
$initialLines = [];
$initialBudget = [];
$formDefaults = [];
$isEdit = false;
$noteDefault = '';
$reqDefault = '';
$condicionesDefault = '';
$folioYearDefault = $year;
$taxDocTypeDefault = '';
$taxDocTypeRequired = true;

if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $editPoId > 0) {
    $editData = OcService::getPurchaseOrderForEdit($pdo, $tid, $editPoId);
    if ($editData === null) {
        $err = 'OC no encontrada.';
        $editPoId = 0;
    } elseif (!OcService::canEditPurchaseOrder($editData['order'])) {
        $err = 'Solo las OC en estado Abierta pueden editarse.';
        $editPoId = 0;
        $editData = null;
    } else {
        $po = $editData['order'];
        $year = (string) ($po['folio_year'] ?? $year);
        $budgetYear = (int) $year;
        $formDefaults['req_number'] = trim((string) ($po['seller_ref'] ?? ''));
        $ts = strtotime((string) ($po['ordered_at']));
        $formDefaults['ordered_at'] = $ts ? date('Y-m-d\TH:i', $ts) : date('Y-m-d\TH:i');
        $formDefaults['note'] = trim((string) ($po['note'] ?? ''));
        $formDefaults['condiciones'] = trim((string) ($po['payment_terms'] ?? ''));
        $formDefaults['folio_year'] = (string) ($po['folio_year'] ?? $year);
        $formDefaults['nro_cta'] = (string) (AccountCodeCatalog::displayAccountId($pdo, $tid, (int) ($po['account_code_1'] ?? 0)) ?? 0);
        $formDefaults['nro_cta2'] = (string) (AccountCodeCatalog::displayAccountId($pdo, $tid, (int) ($po['account_code_2'] ?? 0)) ?? 0);
        $formDefaults['nro_cta3'] = (string) (AccountCodeCatalog::displayAccountId($pdo, $tid, (int) ($po['account_code_3'] ?? 0)) ?? 0);
        $formDefaults['nro_cta4'] = (string) (AccountCodeCatalog::displayAccountId($pdo, $tid, (int) ($po['account_code_4'] ?? 0)) ?? 0);
        $formDefaults['tax_doc_type'] = strtolower(trim((string) ($po['tax_doc_type'] ?? '')));
        $selectedSupplierId = (int) ($po['supplier_id'] ?? 0);
        $selectedSupplierName = trim((string) ($po['supplier_display'] ?? ''));
        $selectedSupplierPhone = trim((string) ($po['supplier_phone'] ?? ''));
        $selectedSupplierEmail = trim((string) ($po['supplier_email'] ?? ''));
        $nextOc = (int) ($po['numero_oc'] ?? 0);
        $initialLines = $editData['lines'];
        foreach ($editData['allocations'] as $a) {
            $initialBudget[] = [
                'detail_id' => (int) $a['budget_detail_id'],
                'activity' => (string) $a['activity_number'],
                'account' => (string) $a['account_label'],
                'amount' => (float) $a['amount'],
                'req_number' => (string) $a['req_number'],
                'line_rem' => (float) $a['line_remaining'],
                'header_rem' => (float) $a['header_remaining'],
            ];
        }
    }
}

$nextOc = $nextOc ?? OcService::nextNumeroOc($pdo, $tid, $year);

$selectedSupplierId = $selectedSupplierId ?? (int) ($_POST['supplier_id'] ?? 0);
$selectedSupplierName = $selectedSupplierName ?? '';
$selectedSupplierPhone = $selectedSupplierPhone ?? '';
$selectedSupplierEmail = $selectedSupplierEmail ?? '';
if ($selectedSupplierId > 0 && $selectedSupplierName === '') {
    $supRow = SupplierService::get($pdo, $tid, $selectedSupplierId);
    if ($supRow !== null) {
        $selectedSupplierName = trim((string) $supRow['legal_name']);
        $selectedSupplierPhone = trim((string) ($supRow['phone'] ?? ''));
        $selectedSupplierEmail = trim((string) ($supRow['email'] ?? ''));
    }
}

$rep = static function (string $key, string $fallback = '') use ($formDefaults): string {
    if (isset($_POST[$key])) {
        return (string) $_POST[$key];
    }
    if (array_key_exists($key, $formDefaults)) {
        return (string) $formDefaults[$key];
    }

    return $fallback;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post_csrf();
    try {
        $purchaseOrderId = (int) ($_POST['purchase_order_id'] ?? 0);
        $supplierId = (int) ($_POST['supplier_id'] ?? 0);
        $orderedAt = trim((string) ($_POST['ordered_at'] ?? ''));
        $orderedAt = str_replace('T', ' ', $orderedAt);
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $orderedAt)) {
            $orderedAt .= ':00';
        }
        $note = trim((string) ($_POST['note'] ?? ''));
        $folioYear = trim((string) ($_POST['folio_year'] ?? $year));
        $reqDefault = trim((string) ($_POST['req_number'] ?? ''));
        $lines = [];
        foreach ($_POST['lines'] ?? [] as $ln) {
            if (!is_array($ln)) {
                continue;
            }
            $code = trim((string) ($ln['product_code'] ?? ''));
            $name = trim((string) ($ln['product_name'] ?? ''));
            $qty = (float) ($ln['qty'] ?? 0);
            $price = (float) ($ln['unit_price'] ?? 0);
            if ($code === '' && $name === '') {
                continue;
            }
            $lines[] = [
                'product_code' => $code,
                'product_name' => $name,
                'qty' => $qty,
                'unit_price' => $price,
            ];
        }
        $allocs = [];
        foreach ($_POST['budget'] ?? [] as $b) {
            if (!is_array($b)) {
                continue;
            }
            $did = (int) ($b['detail_id'] ?? 0);
            $amt = (float) ($b['amount'] ?? 0);
            $req = trim((string) ($b['req_number'] ?? ''));
            if ($req === '') {
                $req = $reqDefault;
            }
            if ($did > 0 && $amt > 0) {
                $allocs[] = [
                    'budget_detail_id' => $did,
                    'amount' => $amt,
                    'req_number' => $req,
                ];
            }
        }
        if ($supplierId <= 0 || $orderedAt === '') {
            throw new RuntimeException('Proveedor y fecha son obligatorios.');
        }
        if ($lines === []) {
            throw new RuntimeException('Agregue al menos una linea de producto.');
        }
        if ($allocs === []) {
            throw new RuntimeException('Debe imputar al menos una linea de presupuesto (actividad ' . $year . ').');
        }
        $nroCta1 = (int) ($_POST['nro_cta'] ?? 0);
        if ($nroCta1 <= 0) {
            throw new RuntimeException('Seleccione al menos la primera cuenta (' . t_es('n_cta') . ').');
        }

        $sellerRef = trim((string) ($_POST['req_number'] ?? ''));
        $paymentTerms = trim((string) ($_POST['condiciones'] ?? ''));
        $acct = [
            (int) ($_POST['nro_cta'] ?? 0),
            (int) ($_POST['nro_cta2'] ?? 0),
            (int) ($_POST['nro_cta3'] ?? 0),
            (int) ($_POST['nro_cta4'] ?? 0),
        ];

        $taxDocTypePost = strtolower(trim((string) ($_POST['tax_doc_type'] ?? '')));
        $taxProfile = null;
        if ($taxDocTypePost === '') {
            if ($purchaseOrderId <= 0) {
                throw new RuntimeException('Debe elegir el tipo de documento (Factura o Boleta de Honorarios).');
            }
            $stTax = $pdo->prepare(
                'SELECT tax_doc_type, tax_mode, tax_percent FROM purchase_orders WHERE id = ? AND tenant_id = ?'
            );
            $stTax->execute([$purchaseOrderId, $tid]);
            $existingTax = $stTax->fetch(PDO::FETCH_ASSOC);
            if ($existingTax === false) {
                throw new RuntimeException('OC no encontrada.');
            }
            if (oc_tax_profile_is_persisted($existingTax)) {
                throw new RuntimeException('Debe elegir el tipo de documento (Factura o Boleta de Honorarios).');
            }
            // Historica sin perfil: dejar tax_* sin cambios (inferencia legado).
        } elseif ($taxDocTypePost !== 'factura' && $taxDocTypePost !== 'boleta') {
            throw new RuntimeException('Tipo de documento invalido.');
        } else {
            $taxProfile = oc_tax_profile_base_fields($taxDocTypePost);
        }

        if ($purchaseOrderId > 0) {
            OcService::updatePurchaseOrder(
                $pdo,
                $tid,
                $purchaseOrderId,
                $orderedAt,
                $supplierId,
                $note,
                $lines,
                $allocs,
                $sellerRef,
                $paymentTerms,
                $acct[0],
                $acct[1],
                $acct[2],
                $acct[3],
                $taxProfile,
            );
            $st = $pdo->prepare('SELECT numero_oc FROM purchase_orders WHERE id = ? AND tenant_id = ?');
            $st->execute([$purchaseOrderId, $tid]);
            $num = (int) $st->fetchColumn();
            header('Location: index.php?year=' . urlencode($folioYear) . '&updated=' . $num);
            exit;
        }

        if ($taxProfile === null) {
            throw new RuntimeException('Debe elegir el tipo de documento (Factura o Boleta de Honorarios).');
        }

        $poId = OcService::createPurchaseOrder(
            $pdo,
            $tid,
            $uid,
            $folioYear,
            $orderedAt,
            $supplierId,
            $note,
            $lines,
            $allocs,
            $sellerRef,
            $paymentTerms,
            $acct[0],
            $acct[1],
            $acct[2],
            $acct[3],
            $taxProfile,
        );
        $st = $pdo->prepare('SELECT numero_oc FROM purchase_orders WHERE id = ? AND tenant_id = ?');
        $st->execute([$poId, $tid]);
        $num = (int) $st->fetchColumn();
        header('Location: index.php?year=' . urlencode($folioYear) . '&ok=' . $num);
        exit;
    } catch (Throwable $e) {
        $err = $e->getMessage();
        if ($editPoId <= 0) {
            $editPoId = (int) ($_POST['purchase_order_id'] ?? 0);
        }
    }
}

if (isset($_GET['ok'])) {
    $ok = 'OC ' . (string) $_GET['ok'] . ' creada.';
}

$stT = $pdo->prepare('SELECT name, slug FROM tenants WHERE id = ?');
$stT->execute([$tid]);
$tenantRow = $stT->fetch(PDO::FETCH_ASSOC) ?: [];
$ocNavBrand = strtoupper((string) ($tenantRow['slug'] ?? 'OC'));
$ocNavYear = (int) $year;
$dtDefault = $rep('ordered_at', date('Y-m-d\TH:i'));
$nroCtaDefault = (int) $rep('nro_cta', '0');
$nroCta2Default = (int) $rep('nro_cta2', '0');
$nroCta3Default = (int) $rep('nro_cta3', '0');
$nroCta4Default = (int) $rep('nro_cta4', '0');
$condicionesDefault = $rep('condiciones', '');
$noteDefault = $rep('note', '');
$reqDefault = $rep('req_number', '');
$folioYearDefault = $rep('folio_year', $year);
$taxDocTypeDefault = $rep('tax_doc_type', '');
if ($editPoId > 0 && $editData === null) {
    $editData = OcService::getPurchaseOrderForEdit($pdo, $tid, $editPoId);
    if ($editData !== null && OcService::canEditPurchaseOrder($editData['order'])) {
        $isEdit = true;
        $nextOc = (int) ($editData['order']['numero_oc'] ?? $nextOc);
        $initialLines = $editData['lines'];
        $initialBudget = [];
        foreach ($editData['allocations'] as $a) {
            $initialBudget[] = [
                'detail_id' => (int) $a['budget_detail_id'],
                'activity' => (string) $a['activity_number'],
                'account' => (string) $a['account_label'],
                'amount' => (float) $a['amount'],
                'req_number' => (string) $a['req_number'],
                'line_rem' => (float) $a['line_remaining'],
                'header_rem' => (float) $a['header_remaining'],
            ];
        }
    } else {
        $editPoId = 0;
        $isEdit = false;
    }
} else {
    $isEdit = $editPoId > 0 && $editData !== null;
}

if ($isEdit && $editData !== null) {
    if ($taxDocTypeDefault === '') {
        $taxDocTypeDefault = strtolower(trim((string) ($editData['order']['tax_doc_type'] ?? '')));
    }
    if ($taxDocTypeDefault === '') {
        $modeHydrate = strtolower(trim((string) ($editData['order']['tax_mode'] ?? '')));
        if ($modeHydrate === 'retencion') {
            $taxDocTypeDefault = 'boleta';
        } elseif ($modeHydrate === 'iva' || $modeHydrate === 'exento') {
            $taxDocTypeDefault = 'factura';
        }
    }
}
// Nueva OC o edicion con perfil ya persistido: tipo obligatorio. Historica sin perfil: opcional.
$taxDocTypeRequired = !$isEdit
    || $taxDocTypeDefault !== ''
    || ($isEdit && $editData !== null && oc_tax_profile_is_persisted($editData['order']));

$layoutTitle = $isEdit
    ? $ocNavBrand . ' ' . t_es('sep_title') . ' ' . t_es('editar_oc')
    : $ocNavBrand . ' ' . t_es('sep_title') . ' ' . t_es('nueva_oc');
require __DIR__ . '/inc/layout_oc_legacy_top.php';
require __DIR__ . '/inc/oc_new_legacy_body.php';
ob_start();
?>
<script>
window.OC_NEW_CONFIG = {
  budgetYear: <?= (int) $budgetYear ?>,
  csrf: <?= json_encode(csrf_token(), JSON_UNESCAPED_UNICODE) ?>,
  purchaseOrderId: <?= $editPoId ?>,
  supplierId: <?= $selectedSupplierId ?>,
  supplierName: <?= json_encode($selectedSupplierName, JSON_UNESCAPED_UNICODE) ?>,
  supplierPhone: <?= json_encode($selectedSupplierPhone, JSON_UNESCAPED_UNICODE) ?>,
  supplierEmail: <?= json_encode($selectedSupplierEmail, JSON_UNESCAPED_UNICODE) ?>,
  nroCta: <?= $nroCtaDefault ?>,
  nroCta2: <?= $nroCta2Default ?>,
  nroCta3: <?= $nroCta3Default ?>,
  nroCta4: <?= $nroCta4Default ?>,
  taxDocType: <?= json_encode($taxDocTypeDefault, JSON_UNESCAPED_UNICODE) ?>,
  taxDocTypeRequired: <?= $taxDocTypeRequired ? 'true' : 'false' ?>,
  boletaRetencionPercent: <?= json_encode(oc_boleta_retencion_rate_percent(''), JSON_UNESCAPED_UNICODE) ?>,
  initialLines: <?= json_encode($initialLines, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_HEX_TAG) ?>,
  initialBudget: <?= json_encode($initialBudget, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_HEX_TAG) ?>,
  pickIxStart: <?= count($initialBudget) ?>,
  lineIxStart: <?= count($initialLines) ?>,
  labels: {
    nCta: <?= json_encode(t_es('n_cta'), JSON_UNESCAPED_UNICODE) ?>,
    nOrdinal: <?= json_encode(t_es('n_ordinal'), JSON_UNESCAPED_UNICODE) ?>
  }
};
</script>
<script src="js/oc_new_legacy.js?v=20261121"></script>
<?php
$layoutPageScripts = (string) ob_get_clean();
require __DIR__ . '/inc/layout_oc_legacy_bottom.php';
