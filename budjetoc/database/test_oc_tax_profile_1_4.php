<?php

declare(strict_types=1);

/**
 * Pruebas CLI del perfil tributario 1.4 (cambiar perfil en Abierta/Cerrada; Anulada bloqueada).
 * Uso: php database/test_oc_tax_profile_1_4.php
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

echo "=== Perfil tributario 1.4 (cambio en cualquier estado operativo) ===\n\n";

assertEq(
    'canChangeTaxProfile ABIERTA',
    true,
    OcService::canChangeTaxProfile(['status' => OcService::STATUS_ABIERTA])
);
assertEq(
    'canChangeTaxProfile CERRADA',
    true,
    OcService::canChangeTaxProfile(['status' => OcService::STATUS_CERRADA])
);
assertEq(
    'canChangeTaxProfile ANULADA',
    false,
    OcService::canChangeTaxProfile(['status' => OcService::STATUS_ANULADA])
);
assertEq(
    'canEditPurchaseOrder CERRADA sigue false',
    false,
    OcService::canEditPurchaseOrder(['status' => OcService::STATUS_CERRADA])
);
assertEq(
    'canEditPurchaseOrder ABIERTA true',
    true,
    OcService::canEditPurchaseOrder(['status' => OcService::STATUS_ABIERTA])
);

$lines = [
    ['product_code' => 'P1', 'product_name' => 'Servicio test 1.4', 'qty' => 1, 'unit_price' => 1000],
];

$poId = 0;
$headerId = 0;
$tid = 0;
$createdSupplierId = 0;
$supplierId = 0;
$orderedAt = date('Y-m-d H:i:s');
$allocs = [];

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
                'Proveedor Test 1.4 Tax',
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
        $actNum = 'T14-' . $year . '-' . substr((string) microtime(true), -6);
        $pdo->prepare(
            'INSERT INTO budget_headers (tenant_id, activity_number, budget_year, month, activity_id, observations)
             VALUES (?, ?, ?, 1, ?, ?)'
        )->execute([$tid, $actNum, $year, $activityId, 'test oc tax profile 1.4']);
        $headerId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO budget_details (budget_header_id, account_code_id, quantity, unit_price, observation)
             VALUES (?, ?, 1, 50000, ?)'
        )->execute([$headerId, $accountCodeId, 'linea test 1.4']);
        $detailId = (int) $pdo->lastInsertId();
        pass("budget header={$headerId} detail={$detailId}");

        $allocs = [['budget_detail_id' => $detailId, 'amount' => 1000.0, 'req_number' => 'T14']];
        $folioYear = '2026';

        $poId = OcService::createPurchaseOrder(
            $pdo,
            $tid,
            $uid,
            $folioYear,
            $orderedAt,
            $supplierId,
            'test tax 1.4',
            $lines,
            $allocs,
            'T14',
            '',
            0,
            0,
            0,
            0,
            oc_tax_profile_from_form_input('factura', 'iva', 2026, ''),
        );
        pass("createPurchaseOrder id={$poId}");

        // Abierta: cambio perfil tax-only (factura → boleta default año)
        OcService::updatePurchaseOrderTaxProfile(
            $pdo,
            $tid,
            $poId,
            oc_tax_profile_from_form_input('boleta', '', 2026, '')
        );
        $stTax = $pdo->prepare(
            'SELECT tax_doc_type, tax_mode, tax_percent, status, supplier_id, total_amount, note
             FROM purchase_orders WHERE id = ? AND tenant_id = ?'
        );
        $stTax->execute([$poId, $tid]);
        $taxRow = $stTax->fetch(PDO::FETCH_ASSOC) ?: [];
        assertEq('abierta→boleta tax_doc_type', 'boleta', (string) ($taxRow['tax_doc_type'] ?? ''));
        assertEq('abierta→boleta tax_mode', 'retencion', (string) ($taxRow['tax_mode'] ?? ''));
        assertFloatEq('abierta→boleta default % 15.25', 15.25, (float) ($taxRow['tax_percent'] ?? 0));
        assertEq('abierta supplier intacto', $supplierId, (int) ($taxRow['supplier_id'] ?? 0));
        assertFloatEq('abierta total_amount intacto', 1000.0, (float) ($taxRow['total_amount'] ?? 0));

        $viewAbierta = OcPrintView::build($pdo, $tid, $poId);
        if ($viewAbierta === null) {
            fail('OcPrintView abierta null');
        } else {
            $tp = $viewAbierta['taxProfile'] ?? [];
            assertEq('print abierta mode retencion', 'retencion', $tp['mode'] ?? null);
            assertFloatEq('print abierta tax_percent 15.25', 15.25, (float) ($tp['tax_percent'] ?? 0));
            assertEq('print abierta boleta fmt', true, (bool) ($viewAbierta['printBoletaFmt'] ?? false));
        }

        // Cerrar OC
        $pdo->prepare('UPDATE purchase_orders SET status = ? WHERE id = ? AND tenant_id = ?')
            ->execute([OcService::STATUS_CERRADA, $poId, $tid]);
        assertEq(
            'canEdit CERRADA false',
            false,
            OcService::canEditPurchaseOrder(['status' => OcService::STATUS_CERRADA])
        );

        // updatePurchaseOrder completo debe fallar en Cerrada
        try {
            OcService::updatePurchaseOrder(
                $pdo,
                $tid,
                $poId,
                $orderedAt,
                $supplierId,
                'no debe',
                $lines,
                $allocs,
                'T14',
                '',
                0,
                0,
                0,
                0,
                oc_tax_profile_from_form_input('factura', 'iva', 2026, ''),
            );
            fail('updatePurchaseOrder en Cerrada debia fallar');
        } catch (RuntimeException $e) {
            pass('updatePurchaseOrder Cerrada bloqueado');
        }

        // Cerrada: Factura exenta
        OcService::updatePurchaseOrderTaxProfile(
            $pdo,
            $tid,
            $poId,
            oc_tax_profile_from_form_input('factura', 'exento', 2026, '')
        );
        $stTax->execute([$poId, $tid]);
        $taxRow = $stTax->fetch(PDO::FETCH_ASSOC) ?: [];
        assertEq('cerrada exento tax_doc_type', 'factura', (string) ($taxRow['tax_doc_type'] ?? ''));
        assertEq('cerrada exento tax_mode', 'exento', (string) ($taxRow['tax_mode'] ?? ''));
        assertFloatEq('cerrada exento tax_percent 0', 0.0, (float) ($taxRow['tax_percent'] ?? -1));
        assertEq('cerrada status sigue CERRADA', OcService::STATUS_CERRADA, (int) ($taxRow['status'] ?? -1));
        assertEq('cerrada note intacta', 'test tax 1.4', (string) ($taxRow['note'] ?? ''));

        $viewExento = OcPrintView::build($pdo, $tid, $poId);
        if ($viewExento === null) {
            fail('OcPrintView exento null');
        } else {
            $tp = $viewExento['taxProfile'] ?? [];
            assertEq('print cerrada exento mode', 'exento', $tp['mode'] ?? null);
            assertFloatEq('print cerrada exento total=subtotal', 1000.0, (float) ($tp['total'] ?? 0));
            assertEq('print cerrada exento no boleta', false, (bool) ($viewExento['printBoletaFmt'] ?? true));
        }

        // Lineas no tocadas
        $stL = $pdo->prepare(
            'SELECT COUNT(*) FROM po_lines WHERE purchase_order_id = ? AND tenant_id = ?'
        );
        $stL->execute([$poId, $tid]);
        assertEq('lineas intactas tras tax-only', 1, (int) $stL->fetchColumn());

        // Cerrada: cambiar a Boleta con % editable
        OcService::updatePurchaseOrderTaxProfile(
            $pdo,
            $tid,
            $poId,
            oc_tax_profile_from_form_input('boleta', '', 2026, '10.75')
        );
        $stTax->execute([$poId, $tid]);
        $taxRow = $stTax->fetch(PDO::FETCH_ASSOC) ?: [];
        assertEq('cerrada→boleta tax_doc_type', 'boleta', (string) ($taxRow['tax_doc_type'] ?? ''));
        assertEq('cerrada→boleta tax_mode', 'retencion', (string) ($taxRow['tax_mode'] ?? ''));
        assertFloatEq('cerrada→boleta % editado 10.75', 10.75, (float) ($taxRow['tax_percent'] ?? 0));

        $viewBoleta = OcPrintView::build($pdo, $tid, $poId);
        if ($viewBoleta === null) {
            fail('OcPrintView boleta cerrada null');
        } else {
            $tp = $viewBoleta['taxProfile'] ?? [];
            assertEq('print cerrada boleta mode', 'retencion', $tp['mode'] ?? null);
            assertFloatEq('print cerrada boleta % 10.75', 10.75, (float) ($tp['tax_percent'] ?? 0));
            assertFloatEq('print cerrada boleta retencion 107.50', 107.5, (float) ($tp['tax_amount'] ?? 0));
            assertFloatEq('print cerrada boleta total 892.50', 892.5, (float) ($tp['total'] ?? 0));
            assertEq('print cerrada boleta fmt', true, (bool) ($viewBoleta['printBoletaFmt'] ?? false));
        }

        // Factura→Boleta sin % → default folio_year
        OcService::updatePurchaseOrderTaxProfile(
            $pdo,
            $tid,
            $poId,
            oc_tax_profile_from_form_input('factura', 'iva', 2026, '')
        );
        OcService::updatePurchaseOrderTaxProfile(
            $pdo,
            $tid,
            $poId,
            oc_tax_profile_from_form_input('boleta', '', 2026, '')
        );
        $stTax->execute([$poId, $tid]);
        $taxRow = $stTax->fetch(PDO::FETCH_ASSOC) ?: [];
        assertFloatEq('factura→boleta default año 15.25', 15.25, (float) ($taxRow['tax_percent'] ?? 0));
        assertEq('factura→boleta mode retencion', 'retencion', (string) ($taxRow['tax_mode'] ?? ''));

        // Anulada: bloqueo
        $pdo->prepare('UPDATE purchase_orders SET status = ? WHERE id = ? AND tenant_id = ?')
            ->execute([OcService::STATUS_ANULADA, $poId, $tid]);
        try {
            OcService::updatePurchaseOrderTaxProfile(
                $pdo,
                $tid,
                $poId,
                oc_tax_profile_from_form_input('factura', 'iva', 2026, '')
            );
            fail('tax profile en Anulada debia fallar');
        } catch (RuntimeException $e) {
            pass('tax profile Anulada bloqueado');
            assertEq(
                'mensaje anulada',
                true,
                str_contains($e->getMessage(), 'anulada')
            );
        }

        // Tenant isolation: otro tenant_id no encuentra la OC
        try {
            OcService::updatePurchaseOrderTaxProfile(
                $pdo,
                $tid + 999999,
                $poId,
                oc_tax_profile_from_form_input('factura', 'iva', 2026, '')
            );
            fail('otro tenant debia fallar');
        } catch (RuntimeException $e) {
            pass('multi-tenant: OC no encontrada en otro tenant');
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
