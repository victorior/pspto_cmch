<?php

declare(strict_types=1);

/**
 * Story 2.1: guardar OC sin imputar actividad (create/update con 0 allocs).
 * Uso: php database/test_oc_optional_activity_2_1.php
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

function countAllocs(PDO $pdo, int $tenantId, int $poId): int
{
    $st = $pdo->prepare(
        'SELECT COUNT(*) FROM po_budget_allocations WHERE purchase_order_id = ? AND tenant_id = ?'
    );
    $st->execute([$poId, $tenantId]);

    return (int) $st->fetchColumn();
}

echo "=== Story 2.1: OC sin imputar actividad ===\n\n";

$buscarBtn = file_get_contents(dirname(__DIR__) . '/public/oc/inc/oc_new_legacy_body.php');
if (is_string($buscarBtn) && str_contains($buscarBtn, 'Buscar Actividades')) {
    pass('UI Buscar Actividades intacta en oc_new_legacy_body.php');
} else {
    fail('UI Buscar Actividades no encontrada en oc_new_legacy_body.php');
}

$poEmptyId = 0;
$poWithId = 0;
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
                'Proveedor Test 2.1 Optional Activity',
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
        $actNum = 'T21-' . $year . '-' . substr((string) microtime(true), -6);
        $pdo->prepare(
            'INSERT INTO budget_headers (tenant_id, activity_number, budget_year, month, activity_id, observations)
             VALUES (?, ?, ?, 1, ?, ?)'
        )->execute([$tid, $actNum, $year, $activityId, 'test oc optional activity 2.1']);
        $headerId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO budget_details (budget_header_id, account_code_id, quantity, unit_price, observation)
             VALUES (?, ?, 1, 50000, ?)'
        )->execute([$headerId, $accountCodeId, 'linea test 2.1']);
        $detailId = (int) $pdo->lastInsertId();
        pass("budget header={$headerId} detail={$detailId}");

        $lines = [
            ['product_code' => 'P21', 'product_name' => 'Producto 2.1', 'qty' => 1, 'unit_price' => 1000],
        ];
        $orderedAt = date('Y-m-d H:i:s');
        $folioYear = (string) $year;
        $tax = function_exists('oc_tax_profile_base_fields')
            ? oc_tax_profile_base_fields('factura')
            : null;

        // Crear sin allocs
        $poEmptyId = OcService::createPurchaseOrder(
            $pdo,
            $tid,
            $uid,
            $folioYear,
            $orderedAt,
            $supplierId,
            'test 2.1 sin allocs',
            $lines,
            [],
            'T21',
            '',
            0,
            0,
            0,
            0,
            $tax,
        );
        pass("create sin allocs id={$poEmptyId}");
        assertEq('create sin allocs → 0 filas po_budget_allocations', 0, countAllocs($pdo, $tid, $poEmptyId));

        // Editar sin allocs (reabrir/guardar de nuevo)
        OcService::updatePurchaseOrder(
            $pdo,
            $tid,
            $poEmptyId,
            $orderedAt,
            $supplierId,
            'test 2.1 sin allocs edit',
            $lines,
            [],
            'T21',
            '',
            0,
            0,
            0,
            0,
            $tax,
        );
        pass('update sin allocs ok');
        assertEq('update sin allocs → sigue 0 allocs', 0, countAllocs($pdo, $tid, $poEmptyId));

        // Crear con allocs
        $allocs = [['budget_detail_id' => $detailId, 'amount' => 1000.0, 'req_number' => 'T21']];
        $poWithId = OcService::createPurchaseOrder(
            $pdo,
            $tid,
            $uid,
            $folioYear,
            $orderedAt,
            $supplierId,
            'test 2.1 con allocs',
            $lines,
            $allocs,
            'T21',
            '',
            0,
            0,
            0,
            0,
            $tax,
        );
        pass("create con allocs id={$poWithId}");
        assertEq('create con allocs → 1 fila', 1, countAllocs($pdo, $tid, $poWithId));

        // Update que vacía allocs existentes (DELETE)
        OcService::updatePurchaseOrder(
            $pdo,
            $tid,
            $poWithId,
            $orderedAt,
            $supplierId,
            'test 2.1 vaciar allocs',
            $lines,
            [],
            'T21',
            '',
            0,
            0,
            0,
            0,
            $tax,
        );
        pass('update vacía allocs ok');
        assertEq('update con budget vacío → 0 allocs', 0, countAllocs($pdo, $tid, $poWithId));
    }
} catch (Throwable $e) {
    fail('persistencia: ' . $e->getMessage());
} finally {
    foreach ([$poEmptyId, $poWithId] as $pid) {
        if ($pid > 0 && $tid > 0) {
            $pdo->prepare('DELETE FROM po_budget_allocations WHERE purchase_order_id = ? AND tenant_id = ?')
                ->execute([$pid, $tid]);
            $pdo->prepare('DELETE FROM po_lines WHERE purchase_order_id = ? AND tenant_id = ?')
                ->execute([$pid, $tid]);
            $pdo->prepare('DELETE FROM purchase_orders WHERE id = ? AND tenant_id = ?')
                ->execute([$pid, $tid]);
        }
    }
    if ($headerId > 0 && $tid > 0) {
        $pdo->prepare('DELETE FROM budget_details WHERE budget_header_id = ?')->execute([$headerId]);
        $pdo->prepare('DELETE FROM budget_headers WHERE id = ? AND tenant_id = ?')->execute([$headerId, $tid]);
    }
    if ($createdSupplierId > 0 && $tid > 0) {
        $pdo->prepare('DELETE FROM suppliers WHERE id = ? AND tenant_id = ?')->execute([$createdSupplierId, $tid]);
    }
    if ($poEmptyId > 0 || $poWithId > 0 || $headerId > 0) {
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
