<?php

declare(strict_types=1);

/**
 * Pruebas CLI del perfil tributario 1.1 (matriz edge-case + persistencia OcService/OcPrintView).
 * Uso: php database/test_oc_tax_profile_1_1.php
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

echo "=== Perfil tributario 1.1 (oc_tax_profile) ===\n\n";

$linesFactura = [
    ['product_code' => 'P1', 'product_name' => 'Producto', 'qty' => 1, 'unit_price' => 1000],
];
$linesBoleta = [
    [
        'product_code' => 'servicio boleta_12.25%',
        'product_name' => 'servicio boleta_12.25% (12.25% Impto. Retenido)',
        'qty' => 1,
        'unit_price' => 1000,
    ],
];

// Crear con Factura → base fields
$baseF = oc_tax_profile_base_fields('factura');
assertEq('factura tax_doc_type', 'factura', $baseF['tax_doc_type']);
assertEq('factura tax_mode', 'iva', $baseF['tax_mode']);
assertEq('factura tax_percent', 19.0, $baseF['tax_percent']);

$resolvedF = oc_tax_profile_resolve($baseF, $linesFactura);
assertEq('crear factura mode', 'iva', $resolvedF['mode']);
assertEq('crear factura doc_type', 'factura', $resolvedF['doc_type']);
assertEq('crear factura percent', 19.0, $resolvedF['tax_percent']);

// Crear con Boleta → base fields + resolve (default anual Ley 21.133; ya no 12.25 provisional)
$baseB = oc_tax_profile_base_fields('boleta');
assertEq('boleta tax_doc_type', 'boleta', $baseB['tax_doc_type']);
assertEq('boleta tax_mode', 'retencion', $baseB['tax_mode']);
$defaultRate = oc_boleta_retencion_default_for_year((int) date('Y'));
assertEq('boleta tax_percent default anual', $defaultRate, $baseB['tax_percent']);

$resolvedB = oc_tax_profile_resolve($baseB, $linesFactura);
assertEq('crear boleta mode', 'retencion', $resolvedB['mode']);
assertEq('crear boleta doc_type', 'boleta', $resolvedB['doc_type']);
assertEq('crear boleta manda sobre lineas no-boleta', 'boleta', $resolvedB['doc_type']);

// Editar tipo Factura↔Boleta
$switched = oc_tax_profile_resolve(oc_tax_profile_base_fields('boleta'), $linesFactura);
assertEq('editar a boleta', 'boleta', $switched['doc_type']);
$switchedBack = oc_tax_profile_resolve(oc_tax_profile_base_fields('factura'), $linesBoleta);
assertEq('editar a factura manda sobre lineas boleta', 'factura', $switchedBack['doc_type']);
assertEq('editar a factura mode iva', 'iva', $switchedBack['mode']);

// Historica sin perfil → inferencia
$legacyEmpty = oc_tax_profile_resolve(
    ['tax_doc_type' => null, 'tax_mode' => null, 'tax_percent' => null],
    $linesBoleta
);
assertEq('historica boleta por lineas', 'retencion', $legacyEmpty['mode']);
assertEq('historica doc_type boleta', 'boleta', $legacyEmpty['doc_type']);

$legacyFactura = oc_tax_profile_resolve([], $linesFactura);
assertEq('historica factura por lineas', 'iva', $legacyFactura['mode']);

// Historica define tipo → deja de inferir
$defined = oc_tax_profile_resolve(oc_tax_profile_base_fields('factura'), $linesBoleta);
assertEq('historica define factura', 'factura', $defined['doc_type']);
assertEq('historica define mode iva', 'iva', $defined['mode']);

assertEq('is_persisted true', true, oc_tax_profile_is_persisted($baseF));
assertEq('is_persisted false', false, oc_tax_profile_is_persisted([]));

try {
    oc_tax_profile_base_fields('otro');
    fail('base_fields invalido debia lanzar');
} catch (InvalidArgumentException) {
    pass('base_fields invalido lanza');
}

echo "\n=== Persistencia OcService / OcPrintView ===\n\n";

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
                'Proveedor Test 1.1 Tax',
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
        $actNum = 'T11-' . $year . '-' . substr((string) microtime(true), -6);
        $pdo->prepare(
            'INSERT INTO budget_headers (tenant_id, activity_number, budget_year, month, activity_id, observations)
             VALUES (?, ?, ?, 1, ?, ?)'
        )->execute([$tid, $actNum, $year, $activityId, 'test oc tax profile 1.1']);
        $headerId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO budget_details (budget_header_id, account_code_id, quantity, unit_price, observation)
             VALUES (?, ?, 1, 50000, ?)'
        )->execute([$headerId, $accountCodeId, 'linea test 1.1']);
        $detailId = (int) $pdo->lastInsertId();
        pass("budget header={$headerId} detail={$detailId}");

        $allocs = [['budget_detail_id' => $detailId, 'amount' => 1000.0, 'req_number' => 'T11']];
        $orderedAt = date('Y-m-d H:i:s');
        $folioYear = (string) $year;

        $poId = OcService::createPurchaseOrder(
            $pdo,
            $tid,
            $uid,
            $folioYear,
            $orderedAt,
            $supplierId,
            'test tax 1.1',
            $linesFactura,
            $allocs,
            'T11',
            '',
            0,
            0,
            0,
            0,
            oc_tax_profile_base_fields('factura'),
        );
        pass("createPurchaseOrder id={$poId}");

        $stTax = $pdo->prepare(
            'SELECT tax_doc_type, tax_mode, tax_percent FROM purchase_orders WHERE id = ? AND tenant_id = ?'
        );
        $stTax->execute([$poId, $tid]);
        $taxRow = $stTax->fetch(PDO::FETCH_ASSOC) ?: [];
        assertEq('create tax_doc_type', 'factura', (string) ($taxRow['tax_doc_type'] ?? ''));
        assertEq('create tax_mode', 'iva', (string) ($taxRow['tax_mode'] ?? ''));
        assertEq('create tax_percent', 19.0, (float) ($taxRow['tax_percent'] ?? 0));

        OcService::updatePurchaseOrder(
            $pdo,
            $tid,
            $poId,
            $orderedAt,
            $supplierId,
            'test tax 1.1 boleta',
            $linesFactura,
            $allocs,
            'T11',
            '',
            0,
            0,
            0,
            0,
            oc_tax_profile_base_fields('boleta'),
        );
        $stTax->execute([$poId, $tid]);
        $taxRow = $stTax->fetch(PDO::FETCH_ASSOC) ?: [];
        assertEq('update-with-profile tax_doc_type', 'boleta', (string) ($taxRow['tax_doc_type'] ?? ''));
        assertEq('update-with-profile tax_mode', 'retencion', (string) ($taxRow['tax_mode'] ?? ''));

        // Volver a factura con lineas que parecen boleta; luego update-with-null no debe tocar tax_*.
        OcService::updatePurchaseOrder(
            $pdo,
            $tid,
            $poId,
            $orderedAt,
            $supplierId,
            'test tax 1.1 factura persistida',
            $linesBoleta,
            $allocs,
            'T11',
            '',
            0,
            0,
            0,
            0,
            oc_tax_profile_base_fields('factura'),
        );
        $stTax->execute([$poId, $tid]);
        $beforeNull = $stTax->fetch(PDO::FETCH_ASSOC) ?: [];
        assertEq('pre-null tax_doc_type', 'factura', (string) ($beforeNull['tax_doc_type'] ?? ''));

        OcService::updatePurchaseOrder(
            $pdo,
            $tid,
            $poId,
            $orderedAt,
            $supplierId,
            'test tax 1.1 historica null',
            $linesBoleta,
            $allocs,
            'T11',
            '',
            0,
            0,
            0,
            0,
            null,
        );
        $stTax->execute([$poId, $tid]);
        $afterNull = $stTax->fetch(PDO::FETCH_ASSOC) ?: [];
        assertEq(
            'update-with-null deja tax_doc_type',
            (string) ($beforeNull['tax_doc_type'] ?? ''),
            (string) ($afterNull['tax_doc_type'] ?? '')
        );
        assertEq(
            'update-with-null deja tax_mode',
            (string) ($beforeNull['tax_mode'] ?? ''),
            (string) ($afterNull['tax_mode'] ?? '')
        );
        assertEq(
            'update-with-null deja tax_percent',
            (float) ($beforeNull['tax_percent'] ?? 0),
            (float) ($afterNull['tax_percent'] ?? 0)
        );

        $view = OcPrintView::build($pdo, $tid, $poId);
        if ($view === null) {
            fail('OcPrintView::build null');
        } else {
            $tp = $view['taxProfile'] ?? [];
            assertEq('OcPrintView doc_type factura pese a lineas boleta', 'factura', $tp['doc_type'] ?? null);
            assertEq('OcPrintView mode iva', 'iva', $tp['mode'] ?? null);
            assertEq('OcPrintView printBoletaFmt false', false, (bool) ($view['printBoletaFmt'] ?? true));
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
