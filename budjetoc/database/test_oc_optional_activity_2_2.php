<?php

declare(strict_types=1);

/**
 * Story 2.2: avanzar estado sin actividad e imputar después (solo Abierta).
 * Uso: php database/test_oc_optional_activity_2_2.php
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

function fetchPo(PDO $pdo, int $tenantId, int $poId): ?array
{
    $st = $pdo->prepare(
        'SELECT id, status, invoice_number_in FROM purchase_orders WHERE id = ? AND tenant_id = ?'
    );
    $st->execute([$poId, $tenantId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    return $row === false ? null : $row;
}

echo "=== Story 2.2: avanzar estado sin actividad e imputar después ===\n\n";

// Status path no exige actividad (grep estático)
$updatePhp = file_get_contents(dirname(__DIR__) . '/public/oc/update.php');
$statusBlock = '';
if (is_string($updatePhp) && preg_match('/if\s*\(\s*!empty\s*\(\s*\$_POST\s*\[\s*[\'"]status_only[\'"]\s*\]\s*\)\s*\)\s*\{(.*?)^\}/ms', $updatePhp, $m)) {
    $statusBlock = $m[1];
}
$assertSrc = '';
$ocServiceSrc = file_get_contents(dirname(__DIR__) . '/src/OcService.php');
if (is_string($ocServiceSrc) && preg_match('/function assertStatusTransition\(.*?^\s{4}\}/ms', $ocServiceSrc, $m2)) {
    $assertSrc = $m2[0];
}
$statusPathMentionsActivity = (bool) preg_match('/alloc|actividad|activity/i', $statusBlock . "\n" . $assertSrc);
if ($statusBlock !== '' && $assertSrc !== '' && !$statusPathMentionsActivity) {
    pass('status path (update.php status_only + assertStatusTransition) no exige actividad');
} else {
    fail('status path exige actividad o no se pudo leer el bloque');
}

$poCloseId = 0;
$poOpenId = 0;
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
                'Proveedor Test 2.2 Optional Activity',
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
        $actNum = 'T22-' . $year . '-' . substr((string) microtime(true), -6);
        $pdo->prepare(
            'INSERT INTO budget_headers (tenant_id, activity_number, budget_year, month, activity_id, observations)
             VALUES (?, ?, ?, 1, ?, ?)'
        )->execute([$tid, $actNum, $year, $activityId, 'test oc optional activity 2.2']);
        $headerId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO budget_details (budget_header_id, account_code_id, quantity, unit_price, observation)
             VALUES (?, ?, 1, 50000, ?)'
        )->execute([$headerId, $accountCodeId, 'linea test 2.2']);
        $detailId = (int) $pdo->lastInsertId();
        pass("budget header={$headerId} detail={$detailId}");

        $lines = [
            ['product_code' => 'P22', 'product_name' => 'Producto 2.2', 'qty' => 1, 'unit_price' => 1000],
        ];
        $orderedAt = date('Y-m-d H:i:s');
        $folioYear = (string) $year;
        $tax = function_exists('oc_tax_profile_base_fields')
            ? oc_tax_profile_base_fields('factura')
            : null;

        // --- Cerrar sin allocs: create 0 allocs → invoice → close ---
        $poCloseId = OcService::createPurchaseOrder(
            $pdo,
            $tid,
            $uid,
            $folioYear,
            $orderedAt,
            $supplierId,
            'test 2.2 cerrar sin allocs',
            $lines,
            [],
            'T22',
            '',
            0,
            0,
            0,
            0,
            $tax,
        );
        pass("create 0 allocs (cerrar) id={$poCloseId}");
        assertEq('create → 0 allocs', 0, countAllocs($pdo, $tid, $poCloseId));

        $poClose = fetchPo($pdo, $tid, $poCloseId);
        if ($poClose === null) {
            fail('OC cerrar no encontrada tras create');
        } else {
            try {
                OcService::assertStatusTransition($poClose, OcService::STATUS_CERRADA);
                fail('cerrar sin invoice debia fallar');
            } catch (RuntimeException $e) {
                if (stripos($e->getMessage(), 'factura') !== false) {
                    pass('cerrar sin invoice bloqueado (mensaje N° factura)');
                } else {
                    fail('cerrar sin invoice: mensaje inesperado: ' . $e->getMessage());
                }
            }

            $invoiceNo = 22001;
            $pdo->prepare(
                'UPDATE purchase_orders SET invoice_number_in = ? WHERE id = ? AND tenant_id = ?'
            )->execute([$invoiceNo, $poCloseId, $tid]);
            $poClose = fetchPo($pdo, $tid, $poCloseId);
            assertEq('invoice_number_in persistido', $invoiceNo, (int) ($poClose['invoice_number_in'] ?? 0));

            try {
                OcService::assertStatusTransition($poClose, OcService::STATUS_CERRADA);
                $pdo->prepare(
                    'UPDATE purchase_orders SET status = ?, voided_at = NULL WHERE id = ? AND tenant_id = ?'
                )->execute([OcService::STATUS_CERRADA, $poCloseId, $tid]);
                pass('cerrar con factura y 0 allocs ok');
            } catch (Throwable $e) {
                fail('cerrar con factura y 0 allocs: ' . $e->getMessage());
            }

            $poClose = fetchPo($pdo, $tid, $poCloseId);
            assertEq('estado Cerrada tras cierre', OcService::STATUS_CERRADA, (int) ($poClose['status'] ?? -1));
            assertEq('tras cierre sigue 0 allocs', 0, countAllocs($pdo, $tid, $poCloseId));

            // Imputar en Cerrada bloqueado (canEdit + update)
            if (!OcService::canEditPurchaseOrder($poClose)) {
                pass('canEdit Cerrada = false');
            } else {
                fail('canEdit Cerrada debia ser false');
            }
            try {
                OcService::updatePurchaseOrder(
                    $pdo,
                    $tid,
                    $poCloseId,
                    $orderedAt,
                    $supplierId,
                    'intento imputar en cerrada',
                    $lines,
                    [['budget_detail_id' => $detailId, 'amount' => 1000.0, 'req_number' => 'T22']],
                    'T22',
                    '',
                    0,
                    0,
                    0,
                    0,
                    $tax,
                );
                fail('update completo en Cerrada debia fallar');
            } catch (RuntimeException $e) {
                pass('update completo en Cerrada bloqueado');
            }
            assertEq('Cerrada sin allocs tras intento edit', 0, countAllocs($pdo, $tid, $poCloseId));
        }

        // --- Imputar después en Abierta ---
        $poOpenId = OcService::createPurchaseOrder(
            $pdo,
            $tid,
            $uid,
            $folioYear,
            $orderedAt,
            $supplierId,
            'test 2.2 imputar despues',
            $lines,
            [],
            'T22',
            '',
            0,
            0,
            0,
            0,
            $tax,
        );
        pass("create 0 allocs (abierta) id={$poOpenId}");
        assertEq('abierta sin allocs', 0, countAllocs($pdo, $tid, $poOpenId));

        $poOpen = fetchPo($pdo, $tid, $poOpenId);
        if ($poOpen === null || !OcService::canEditPurchaseOrder($poOpen)) {
            fail('OC Abierta no editable');
        } else {
            pass('canEdit Abierta = true');
        }

        OcService::updatePurchaseOrder(
            $pdo,
            $tid,
            $poOpenId,
            $orderedAt,
            $supplierId,
            'test 2.2 con allocs despues',
            $lines,
            [['budget_detail_id' => $detailId, 'amount' => 1000.0, 'req_number' => 'T22']],
            'T22',
            '',
            0,
            0,
            0,
            0,
            $tax,
        );
        pass('update Abierta con allocs ok');
        assertEq('imputar despues → 1 alloc', 1, countAllocs($pdo, $tid, $poOpenId));
        assertEq('sigue Abierta tras imputar', OcService::STATUS_ABIERTA, (int) (fetchPo($pdo, $tid, $poOpenId)['status'] ?? -1));
    }
} catch (Throwable $e) {
    fail('persistencia: ' . $e->getMessage());
} finally {
    foreach ([$poCloseId, $poOpenId] as $pid) {
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
    if ($poCloseId > 0 || $poOpenId > 0 || $headerId > 0) {
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
