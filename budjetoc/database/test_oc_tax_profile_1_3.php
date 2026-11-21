<?php

declare(strict_types=1);

/**
 * Pruebas CLI del perfil tributario 1.3 (retencion default anual + % editable).
 * Uso: php database/test_oc_tax_profile_1_3.php
 */

require dirname(__DIR__) . '/src/bootstrap.php';

$pdo = Db::pdo($CONFIG);
$fail = 0;
$ok = 0;

function pass(string $msg): void
{
    global $ok;
    $ok++;
    echo "  OK   {$msg}\n";
}

function fail(string $msg): void
{
    global $fail;
    $fail++;
    echo "  FAIL {$msg}\n";
}

function assertEq(string $label, mixed $expected, mixed $actual): void
{
    if ($expected === $actual) {
        pass($label);
    } else {
        fail($label . ' expected=' . var_export($expected, true) . ' actual=' . var_export($actual, true));
    }
}

function assertFloatEq(string $label, float $expected, float $actual, float $eps = 0.0001): void
{
    if (abs($expected - $actual) <= $eps) {
        pass($label);
    } else {
        fail($label . ' expected=' . var_export($expected, true) . ' actual=' . var_export($actual, true));
    }
}

echo "=== Perfil tributario 1.3 (retencion anual + editable) ===\n\n";

assertFloatEq('default year 2026', 15.25, oc_boleta_retencion_default_for_year(2026));
assertFloatEq('default year 2027', 16.0, oc_boleta_retencion_default_for_year(2027));
assertFloatEq('default year 2028', 17.0, oc_boleta_retencion_default_for_year(2028));
assertFloatEq('default year <2026 → 15.25', 15.25, oc_boleta_retencion_default_for_year(2025));
assertFloatEq('default year >2028 → 17', 17.0, oc_boleta_retencion_default_for_year(2029));

$map = oc_boleta_retencion_rate_map();
assertFloatEq('mapa 2026', 15.25, (float) $map[2026]);
assertFloatEq('mapa 2027', 16.0, (float) $map[2027]);
assertFloatEq('mapa 2028', 17.0, (float) $map[2028]);

// Boleta 2026 default sin editar %
$base2026 = oc_tax_profile_base_fields('boleta', null, 2026, null);
assertEq('boleta 2026 tax_mode', 'retencion', $base2026['tax_mode']);
assertFloatEq('boleta 2026 tax_percent 15.25', 15.25, (float) $base2026['tax_percent']);

$form2026 = oc_tax_profile_from_form_input('boleta', '', 2026, '');
assertFloatEq('form boleta 2026 default 15.25', 15.25, (float) $form2026['tax_percent']);

$form2027 = oc_tax_profile_from_form_input('boleta', '', 2027, '');
assertFloatEq('form boleta 2027 default 16', 16.0, (float) $form2027['tax_percent']);

$form2028 = oc_tax_profile_from_form_input('boleta', '', 2028, '');
assertFloatEq('form boleta 2028 default 17', 17.0, (float) $form2028['tax_percent']);

// Editar %
$formEdit = oc_tax_profile_from_form_input('boleta', '', 2026, '10.75');
assertFloatEq('form boleta % editado 10.75', 10.75, (float) $formEdit['tax_percent']);
assertEq('form boleta editado mode', 'retencion', $formEdit['tax_mode']);

$formComma = oc_tax_profile_from_form_input('boleta', '', 2026, '10,75');
assertFloatEq('form boleta % con coma', 10.75, (float) $formComma['tax_percent']);

try {
    oc_tax_profile_from_form_input('boleta', '', 2026, 'abc');
    fail('form % invalido debia lanzar');
} catch (RuntimeException $e) {
    pass('form % invalido lanza');
}

try {
    oc_tax_profile_from_form_input('boleta', '', 2026, '0');
    fail('form % cero debia lanzar');
} catch (RuntimeException $e) {
    pass('form % cero lanza');
}

try {
    oc_tax_profile_from_form_input('boleta', '', 2026, '100.1');
    fail('form % >100 debia lanzar');
} catch (RuntimeException $e) {
    pass('form % >100 lanza');
}

// Factura no usa retencion
$formFactura = oc_tax_profile_from_form_input('factura', 'iva', 2026, '15.25');
assertEq('factura ignora % retencion → iva', 'iva', $formFactura['tax_mode']);
assertFloatEq('factura percent 19', 19.0, (float) $formFactura['tax_percent']);

// Totales: retencion = subtotal × R/100 (2 dec); total = subtotal − retencion
$lines = [
    ['product_code' => 'P1', 'product_name' => 'Servicio', 'qty' => 1, 'unit_price' => 1000],
];
$resolvedDefault = oc_tax_profile_resolve($base2026, $lines);
assertEq('totales mode retencion', 'retencion', $resolvedDefault['mode']);
assertFloatEq('totales tax_percent 15.25', 15.25, (float) $resolvedDefault['tax_percent']);
assertFloatEq('totales retencion 152.50', 152.5, (float) $resolvedDefault['tax_amount']);
assertFloatEq('totales total 847.50', 847.5, (float) $resolvedDefault['total']);

$editedProfile = oc_tax_profile_base_fields('boleta', null, 2026, 10.75);
$resolvedEdit = oc_tax_profile_resolve($editedProfile, $lines);
assertFloatEq('editado tax_percent 10.75', 10.75, (float) $resolvedEdit['tax_percent']);
assertFloatEq('editado retencion 107.50', 107.5, (float) $resolvedEdit['tax_amount']);
assertFloatEq('editado total 892.50', 892.5, (float) $resolvedEdit['total']);

// Persistido manda sobre inferencia / 12.25 legado
$linesBoletaGlosa = [
    [
        'product_code' => 'servicio boleta_12.25%',
        'product_name' => 'servicio boleta_12.25% (12.25% Impto. Retenido)',
        'qty' => 1,
        'unit_price' => 1000,
    ],
];
$persisted = [
    'tax_doc_type' => 'boleta',
    'tax_mode' => 'retencion',
    'tax_percent' => 15.25,
];
$resPersisted = oc_tax_profile_resolve($persisted, $linesBoletaGlosa);
assertFloatEq('persistido manda sobre glosa 12.25', 15.25, (float) $resPersisted['tax_percent']);
assertFloatEq('persistido retencion no 122.50', 152.5, (float) $resPersisted['tax_amount']);

$legacyInfer = oc_tax_profile_resolve(
    ['tax_doc_type' => null, 'tax_mode' => null, 'tax_percent' => null],
    $linesBoletaGlosa
);
assertFloatEq('legado sin perfil sigue glosa 12.25', 12.25, (float) $legacyInfer['tax_percent']);

echo "\n=== Persistencia OcService / OcPrintView 1.3 ===\n\n";

$poId = 0;
$headerId = 0;
$tid = 0;
$createdSupplierId = 0;

try {
    $st = $pdo->query(
        'SELECT t.id AS tenant_id,
                (SELECT id FROM suppliers s WHERE s.tenant_id = t.id LIMIT 1) AS supplier_id,
                (SELECT id FROM account_codes ac WHERE ac.tenant_id = t.id LIMIT 1) AS account_code_id,
                (SELECT id FROM activities a WHERE a.tenant_id = t.id LIMIT 1) AS activity_id
         FROM tenants t
         HAVING account_code_id IS NOT NULL AND activity_id IS NOT NULL
         ORDER BY (supplier_id IS NULL), t.id
         LIMIT 1'
    );
    $fx = $st->fetch(PDO::FETCH_ASSOC);
    if ($fx === false) {
        fail('Sin tenant con account_codes/activities para fixtures');
    } else {
        $tid = (int) $fx['tenant_id'];
        $supplierId = (int) ($fx['supplier_id'] ?? 0);
        $accountCodeId = (int) $fx['account_code_id'];
        $activityId = (int) $fx['activity_id'];
        $uid = (int) $pdo->query('SELECT id FROM users LIMIT 1')->fetchColumn();
        pass("fixture tenant_id={$tid}");

        if ($supplierId <= 0) {
            $createdSupplierId = SupplierService::create(
                $pdo,
                $tid,
                'Proveedor Test 1.3 Tax',
                '',
                '',
                ''
            );
            $supplierId = $createdSupplierId;
            pass("supplier creado id={$supplierId}");
        } else {
            pass("supplier existente id={$supplierId}");
        }

        $year = 2026;
        $actNum = 'T13-' . $year . '-' . substr((string) microtime(true), -6);
        $pdo->prepare(
            'INSERT INTO budget_headers (tenant_id, activity_number, budget_year, month, activity_id, observations)
             VALUES (?, ?, ?, 1, ?, ?)'
        )->execute([$tid, $actNum, $year, $activityId, 'test oc tax profile 1.3']);
        $headerId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO budget_details (budget_header_id, account_code_id, quantity, unit_price, observation)
             VALUES (?, ?, 1, 50000, ?)'
        )->execute([$headerId, $accountCodeId, 'linea test 1.3']);
        $detailId = (int) $pdo->lastInsertId();
        pass("budget header={$headerId} detail={$detailId}");

        $allocs = [['budget_detail_id' => $detailId, 'amount' => 1000.0, 'req_number' => 'T13']];
        $orderedAt = date('Y-m-d H:i:s');
        $folioYear = '2026';

        // Aceptar default 15.25 sin editar
        $poId = OcService::createPurchaseOrder(
            $pdo,
            $tid,
            $uid,
            $folioYear,
            $orderedAt,
            $supplierId,
            'test tax 1.3 default',
            $lines,
            $allocs,
            'T13',
            '',
            0,
            0,
            0,
            0,
            oc_tax_profile_from_form_input('boleta', '', 2026, ''),
        );
        pass("createPurchaseOrder default id={$poId}");

        $stTax = $pdo->prepare(
            'SELECT tax_doc_type, tax_mode, tax_percent FROM purchase_orders WHERE id = ? AND tenant_id = ?'
        );
        $stTax->execute([$poId, $tid]);
        $taxRow = $stTax->fetch(PDO::FETCH_ASSOC) ?: [];
        assertEq('create default tax_doc_type', 'boleta', (string) ($taxRow['tax_doc_type'] ?? ''));
        assertEq('create default tax_mode', 'retencion', (string) ($taxRow['tax_mode'] ?? ''));
        assertFloatEq('create default tax_percent 15.25', 15.25, (float) ($taxRow['tax_percent'] ?? 0));

        $viewDefault = OcPrintView::build($pdo, $tid, $poId);
        if ($viewDefault === null) {
            fail('OcPrintView default null');
        } else {
            $tp = $viewDefault['taxProfile'] ?? [];
            assertEq('print default mode retencion', 'retencion', $tp['mode'] ?? null);
            assertFloatEq('print default tax_percent 15.25', 15.25, (float) ($tp['tax_percent'] ?? 0));
            assertFloatEq('print default tax_amount 152.50', 152.5, (float) ($tp['tax_amount'] ?? 0));
            assertFloatEq('print default total 847.50', 847.5, (float) ($tp['total'] ?? 0));
            assertEq('print default boleta fmt', true, (bool) ($viewDefault['printBoletaFmt'] ?? false));
        }

        // Editar % a 10.75 y verificar impresion
        OcService::updatePurchaseOrder(
            $pdo,
            $tid,
            $poId,
            $orderedAt,
            $supplierId,
            'test tax 1.3 editado',
            $lines,
            $allocs,
            'T13',
            '',
            0,
            0,
            0,
            0,
            oc_tax_profile_from_form_input('boleta', '', 2026, '10.75'),
        );
        $stTax->execute([$poId, $tid]);
        $taxRow = $stTax->fetch(PDO::FETCH_ASSOC) ?: [];
        assertFloatEq('update editado tax_percent 10.75', 10.75, (float) ($taxRow['tax_percent'] ?? 0));

        $viewEdit = OcPrintView::build($pdo, $tid, $poId);
        if ($viewEdit === null) {
            fail('OcPrintView editado null');
        } else {
            $tp = $viewEdit['taxProfile'] ?? [];
            assertFloatEq('print editado tax_percent 10.75', 10.75, (float) ($tp['tax_percent'] ?? 0));
            assertFloatEq('print editado tax_amount 107.50', 107.5, (float) ($tp['tax_amount'] ?? 0));
            assertFloatEq('print editado total 892.50', 892.5, (float) ($tp['total'] ?? 0));
            assertEq('print editado boleta fmt', true, (bool) ($viewEdit['printBoletaFmt'] ?? false));
        }

        // Factura oculta retencion en perfil (persistido factura)
        OcService::updatePurchaseOrder(
            $pdo,
            $tid,
            $poId,
            $orderedAt,
            $supplierId,
            'test tax 1.3 factura',
            $lines,
            $allocs,
            'T13',
            '',
            0,
            0,
            0,
            0,
            oc_tax_profile_from_form_input('factura', 'iva', 2026, '15.25'),
        );
        $viewFactura = OcPrintView::build($pdo, $tid, $poId);
        if ($viewFactura === null) {
            fail('OcPrintView factura null');
        } else {
            $tp = $viewFactura['taxProfile'] ?? [];
            assertEq('factura print mode iva', 'iva', $tp['mode'] ?? null);
            assertEq('factura printBoletaFmt false', false, (bool) ($viewFactura['printBoletaFmt'] ?? true));
        }
    }
} catch (Throwable $e) {
    fail('persistencia: ' . $e->getMessage());
} finally {
    if ($poId > 0 && $tid > 0) {
        $pdo->prepare('DELETE FROM purchase_orders WHERE id = ? AND tenant_id = ?')->execute([$poId, $tid]);
    }
    if ($headerId > 0 && $tid > 0) {
        $pdo->prepare('DELETE FROM budget_details WHERE budget_header_id = ?')->execute([$headerId]);
        $pdo->prepare('DELETE FROM budget_headers WHERE id = ? AND tenant_id = ?')->execute([$headerId, $tid]);
    }
    if ($createdSupplierId > 0 && $tid > 0) {
        $pdo->prepare('DELETE FROM suppliers WHERE id = ? AND tenant_id = ?')->execute([$createdSupplierId, $tid]);
    }
    if ($poId > 0 || $headerId > 0) {
        pass('cleanup filas de prueba');
    }
}

echo "\n";
if ($fail > 0) {
    echo "FALLARON {$fail} (OK {$ok})\n";
    exit(1);
}
echo "OK {$ok}\n";
exit(0);
