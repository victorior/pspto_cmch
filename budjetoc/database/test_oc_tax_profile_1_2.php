<?php

declare(strict_types=1);

/**
 * Pruebas CLI del perfil tributario 1.2 (factura afecta/exenta + totales/impresión).
 * Uso: php database/test_oc_tax_profile_1_2.php
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

echo "=== Perfil tributario 1.2 (factura afecta/exenta) ===\n\n";

$linesNeto = [
    ['product_code' => 'P1', 'product_name' => 'Producto con IVA en glosa', 'qty' => 1, 'unit_price' => 1000],
];
$linesExentoGlosa = [
    ['product_code' => 'P2', 'product_name' => 'Servicio exento de IVA', 'qty' => 1, 'unit_price' => 1000],
];
$linesBoleta = [
    [
        'product_code' => 'servicio boleta_12.25%',
        'product_name' => 'servicio boleta_12.25% (12.25% Impto. Retenido)',
        'qty' => 1,
        'unit_price' => 1000,
    ],
];

// Factura + Afecta IVA
$baseIva = oc_tax_profile_base_fields('factura', 'iva');
assertEq('afecta tax_doc_type', 'factura', $baseIva['tax_doc_type']);
assertEq('afecta tax_mode', 'iva', $baseIva['tax_mode']);
assertEq('afecta tax_percent', 19.0, $baseIva['tax_percent']);
$resIva = oc_tax_profile_resolve($baseIva, $linesNeto);
assertEq('afecta resolve mode', 'iva', $resIva['mode']);
assertEq('afecta tax_amount', 190.0, $resIva['tax_amount']);
assertEq('afecta total', 1190.0, $resIva['total']);
assertEq('afecta subtotal', 1000.0, $resIva['subtotal']);

// Factura sin rama → default iva (API)
$baseDefault = oc_tax_profile_base_fields('factura');
assertEq('default factura mode iva', 'iva', $baseDefault['tax_mode']);
assertEq('default factura percent 19', 19.0, $baseDefault['tax_percent']);

// Factura + Exenta
$baseEx = oc_tax_profile_base_fields('factura', 'exento');
assertEq('exenta tax_doc_type', 'factura', $baseEx['tax_doc_type']);
assertEq('exenta tax_mode', 'exento', $baseEx['tax_mode']);
assertEq('exenta tax_percent', 0.0, $baseEx['tax_percent']);
$resEx = oc_tax_profile_resolve($baseEx, $linesNeto);
assertEq('exenta resolve mode', 'exento', $resEx['mode']);
assertEq('exenta tax_amount 0', 0.0, $resEx['tax_amount']);
assertEq('exenta total = subtotal', 1000.0, $resEx['total']);
assertEq('exenta doc_type factura', 'factura', $resEx['doc_type']);

// Persistido manda: factura exenta + lineas que no dicen exento
$resManda = oc_tax_profile_resolve($baseEx, $linesNeto);
assertEq('persistido exento manda sobre lineas IVA', 'exento', $resManda['mode']);
assertEq('persistido exento no infiere iva', 0.0, $resManda['tax_amount']);

// Persistido afecta manda sobre lineas "exento de IVA"
$resAfectaManda = oc_tax_profile_resolve($baseIva, $linesExentoGlosa);
assertEq('persistido iva manda sobre glosa exento', 'iva', $resAfectaManda['mode']);
assertEq('persistido iva tax_amount', 190.0, $resAfectaManda['tax_amount']);

// Boleta ignora segundo arg (rama factura)
$baseBoleta = oc_tax_profile_base_fields('boleta', 'exento');
assertEq('boleta ignora rama → retencion', 'retencion', $baseBoleta['tax_mode']);
assertEq('boleta tax_doc_type', 'boleta', $baseBoleta['tax_doc_type']);
$resBoleta = oc_tax_profile_resolve($baseBoleta, $linesNeto);
assertEq('boleta resolve mode retencion', 'retencion', $resBoleta['mode']);
assertEq('boleta doc_type', 'boleta', $resBoleta['doc_type']);

// Cambiar afecta ↔ exenta
$switched = oc_tax_profile_resolve(oc_tax_profile_base_fields('factura', 'exento'), $linesNeto);
assertEq('cambiar a exento mode', 'exento', $switched['mode']);
$switchedBack = oc_tax_profile_resolve(oc_tax_profile_base_fields('factura', 'iva'), $linesNeto);
assertEq('cambiar a iva mode', 'iva', $switchedBack['mode']);

// Boleta → Factura exenta
$boletaToFactura = oc_tax_profile_resolve(oc_tax_profile_base_fields('factura', 'exento'), $linesBoleta);
assertEq('boleta→factura exenta mode', 'exento', $boletaToFactura['mode']);
assertEq('boleta→factura exenta doc_type', 'factura', $boletaToFactura['doc_type']);
assertEq('boleta→factura exenta tax 0', 0.0, $boletaToFactura['tax_amount']);

try {
    oc_tax_profile_base_fields('factura', 'retencion');
    fail('rama factura invalida debia lanzar');
} catch (InvalidArgumentException) {
    pass('rama factura invalida lanza');
}

// Frontera formulario → perfil (oc_new POST)
$formEx = oc_tax_profile_from_form_input('factura', 'exento');
assertEq('form factura+exento mode', 'exento', $formEx['tax_mode']);
assertEq('form factura+exento percent', 0.0, $formEx['tax_percent']);
$formIva = oc_tax_profile_from_form_input('factura', 'iva');
assertEq('form factura+iva mode', 'iva', $formIva['tax_mode']);
$formBoleta = oc_tax_profile_from_form_input('boleta', 'exento');
assertEq('form boleta ignora mode → retencion', 'retencion', $formBoleta['tax_mode']);
assertEq('form boleta doc_type', 'boleta', $formBoleta['tax_doc_type']);
try {
    oc_tax_profile_from_form_input('factura', '');
    fail('form factura sin mode debia lanzar');
} catch (RuntimeException $e) {
    assertEq(
        'form factura sin mode mensaje',
        'Debe indicar si la factura es Afecta IVA o Exenta.',
        $e->getMessage()
    );
}
try {
    oc_tax_profile_from_form_input('otro', 'iva');
    fail('form tipo invalido debia lanzar');
} catch (RuntimeException $e) {
    assertEq('form tipo invalido mensaje', 'Tipo de documento invalido.', $e->getMessage());
}

echo "\n=== Persistencia OcService / OcPrintView 1.2 ===\n\n";

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
                'Proveedor Test 1.2 Tax',
                '',
                '',
                ''
            );
            $supplierId = $createdSupplierId;
            pass("supplier creado id={$supplierId}");
        } else {
            pass("supplier existente id={$supplierId}");
        }

        $year = (int) date('Y');
        $actNum = 'T12-' . $year . '-' . substr((string) microtime(true), -6);
        $pdo->prepare(
            'INSERT INTO budget_headers (tenant_id, activity_number, budget_year, month, activity_id, observations)
             VALUES (?, ?, ?, 1, ?, ?)'
        )->execute([$tid, $actNum, $year, $activityId, 'test oc tax profile 1.2']);
        $headerId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO budget_details (budget_header_id, account_code_id, quantity, unit_price, observation)
             VALUES (?, ?, 1, 50000, ?)'
        )->execute([$headerId, $accountCodeId, 'linea test 1.2']);
        $detailId = (int) $pdo->lastInsertId();
        pass("budget header={$headerId} detail={$detailId}");

        $allocs = [['budget_detail_id' => $detailId, 'amount' => 1000.0, 'req_number' => 'T12']];
        $orderedAt = date('Y-m-d H:i:s');
        $folioYear = (string) $year;

        // Crear Factura afecta
        $poId = OcService::createPurchaseOrder(
            $pdo,
            $tid,
            $uid,
            $folioYear,
            $orderedAt,
            $supplierId,
            'test tax 1.2 afecta',
            $linesNeto,
            $allocs,
            'T12',
            '',
            0,
            0,
            0,
            0,
            oc_tax_profile_base_fields('factura', 'iva'),
        );
        pass("createPurchaseOrder afecta id={$poId}");

        $stTax = $pdo->prepare(
            'SELECT tax_doc_type, tax_mode, tax_percent FROM purchase_orders WHERE id = ? AND tenant_id = ?'
        );
        $stTax->execute([$poId, $tid]);
        $taxRow = $stTax->fetch(PDO::FETCH_ASSOC) ?: [];
        assertEq('create afecta tax_mode', 'iva', (string) ($taxRow['tax_mode'] ?? ''));
        assertEq('create afecta tax_percent', 19.0, (float) ($taxRow['tax_percent'] ?? 0));

        $viewAfecta = OcPrintView::build($pdo, $tid, $poId);
        if ($viewAfecta === null) {
            fail('OcPrintView afecta null');
        } else {
            $tp = $viewAfecta['taxProfile'] ?? [];
            assertEq('print afecta mode iva', 'iva', $tp['mode'] ?? null);
            assertEq('print afecta tax_amount', 190.0, (float) ($tp['tax_amount'] ?? 0));
            assertEq('print afecta printBoletaFmt false', false, (bool) ($viewAfecta['printBoletaFmt'] ?? true));
        }

        // Crear Factura exenta (create path, no solo update)
        $pdo->prepare('DELETE FROM purchase_orders WHERE id = ? AND tenant_id = ?')->execute([$poId, $tid]);
        $poId = OcService::createPurchaseOrder(
            $pdo,
            $tid,
            $uid,
            $folioYear,
            $orderedAt,
            $supplierId,
            'test tax 1.2 create exenta',
            $linesNeto,
            $allocs,
            'T12',
            '',
            0,
            0,
            0,
            0,
            oc_tax_profile_base_fields('factura', 'exento'),
        );
        pass("createPurchaseOrder exenta id={$poId}");
        $stTax->execute([$poId, $tid]);
        $taxRow = $stTax->fetch(PDO::FETCH_ASSOC) ?: [];
        assertEq('create exenta tax_mode', 'exento', (string) ($taxRow['tax_mode'] ?? ''));
        assertEq('create exenta tax_percent', 0.0, (float) ($taxRow['tax_percent'] ?? -1));
        $viewCreateEx = OcPrintView::build($pdo, $tid, $poId);
        if ($viewCreateEx === null) {
            fail('OcPrintView create exenta null');
        } else {
            $tp = $viewCreateEx['taxProfile'] ?? [];
            assertEq('print create exenta mode', 'exento', $tp['mode'] ?? null);
            assertEq('print create exenta tax 0', 0.0, (float) ($tp['tax_amount'] ?? -1));
            assertEq('print create exenta no boleta', false, (bool) ($viewCreateEx['printBoletaFmt'] ?? true));
        }

        // Actualizar a Factura afecta y de nuevo a exenta (lineas siguen "con IVA")
        OcService::updatePurchaseOrder(
            $pdo,
            $tid,
            $poId,
            $orderedAt,
            $supplierId,
            'test tax 1.2 update afecta',
            $linesNeto,
            $allocs,
            'T12',
            '',
            0,
            0,
            0,
            0,
            oc_tax_profile_base_fields('factura', 'iva'),
        );
        OcService::updatePurchaseOrder(
            $pdo,
            $tid,
            $poId,
            $orderedAt,
            $supplierId,
            'test tax 1.2 exenta',
            $linesNeto,
            $allocs,
            'T12',
            '',
            0,
            0,
            0,
            0,
            oc_tax_profile_base_fields('factura', 'exento'),
        );
        $stTax->execute([$poId, $tid]);
        $taxRow = $stTax->fetch(PDO::FETCH_ASSOC) ?: [];
        assertEq('update exenta tax_mode', 'exento', (string) ($taxRow['tax_mode'] ?? ''));
        assertEq('update exenta tax_percent', 0.0, (float) ($taxRow['tax_percent'] ?? -1));

        $viewEx = OcPrintView::build($pdo, $tid, $poId);
        if ($viewEx === null) {
            fail('OcPrintView exenta null');
        } else {
            $tp = $viewEx['taxProfile'] ?? [];
            assertEq('print exenta mode', 'exento', $tp['mode'] ?? null);
            assertEq('print exenta tax_amount 0', 0.0, (float) ($tp['tax_amount'] ?? -1));
            assertEq('print exenta total', 1000.0, (float) ($tp['total'] ?? 0));
            assertEq('print exenta doc_type factura', 'factura', $tp['doc_type'] ?? null);
            assertEq('print exenta no boleta', false, (bool) ($viewEx['printBoletaFmt'] ?? true));
        }

        // Boleta → Factura exenta
        OcService::updatePurchaseOrder(
            $pdo,
            $tid,
            $poId,
            $orderedAt,
            $supplierId,
            'test tax 1.2 boleta',
            $linesNeto,
            $allocs,
            'T12',
            '',
            0,
            0,
            0,
            0,
            oc_tax_profile_base_fields('boleta'),
        );
        OcService::updatePurchaseOrder(
            $pdo,
            $tid,
            $poId,
            $orderedAt,
            $supplierId,
            'test tax 1.2 boleta a factura exenta',
            $linesBoleta,
            $allocs,
            'T12',
            '',
            0,
            0,
            0,
            0,
            oc_tax_profile_base_fields('factura', 'exento'),
        );
        $viewSwitch = OcPrintView::build($pdo, $tid, $poId);
        if ($viewSwitch === null) {
            fail('OcPrintView boleta→factura null');
        } else {
            $tp = $viewSwitch['taxProfile'] ?? [];
            assertEq('boleta→factura print mode exento', 'exento', $tp['mode'] ?? null);
            assertEq('boleta→factura printBoletaFmt false', false, (bool) ($viewSwitch['printBoletaFmt'] ?? true));
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
echo "Todas las pruebas pasaron ({$ok}).\n";
exit(0);
