<?php

declare(strict_types=1);

final class OcService
{
    public static function nextNumeroOc(PDO $pdo, int $tenantId, string $folioYear): int
    {
        $st = $pdo->prepare(
            'SELECT COALESCE(MAX(numero_oc), 0) + 1 FROM purchase_orders WHERE tenant_id = ? AND folio_year = ?'
        );
        $st->execute([$tenantId, $folioYear]);

        return (int) $st->fetchColumn();
    }

    /**
     * @param list<array{budget_detail_id: int, amount: float, req_number?: string}> $allocations
     * @param array{tax_doc_type: string, tax_mode: string, tax_percent: float}|null $taxProfile
     */
    public static function createPurchaseOrder(
        PDO $pdo,
        int $tenantId,
        int $userId,
        string $folioYear,
        string $orderedAt,
        int $supplierId,
        string $note,
        array $lines,
        array $allocations,
        string $sellerRef = '',
        string $paymentTerms = '',
        int $accountCode1 = 0,
        int $accountCode2 = 0,
        int $accountCode3 = 0,
        int $accountCode4 = 0,
        ?array $taxProfile = null,
    ): int {
        $pdo->beginTransaction();
        try {
            $numero = self::nextNumeroOc($pdo, $tenantId, $folioYear);
            $total = 0.0;
            foreach ($lines as $ln) {
                $total += (float) $ln['qty'] * (float) $ln['unit_price'];
            }
            $total = round($total, 4);

            $taxDocType = $taxProfile !== null ? (string) ($taxProfile['tax_doc_type'] ?? '') : null;
            $taxMode = $taxProfile !== null ? (string) ($taxProfile['tax_mode'] ?? '') : null;
            $taxPercent = $taxProfile !== null ? (float) ($taxProfile['tax_percent'] ?? 0) : null;
            if ($taxDocType === '') {
                $taxDocType = null;
            }
            if ($taxMode === '') {
                $taxMode = null;
            }

            $pdo->prepare(
                'INSERT INTO purchase_orders (
                    tenant_id, folio_year, numero_oc, ordered_at, supplier_id,
                    seller_ref, payment_terms, total_amount, note,
                    account_code_1, account_code_2, account_code_3, account_code_4, created_by,
                    tax_doc_type, tax_mode, tax_percent
                 ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $tenantId,
                $folioYear,
                $numero,
                $orderedAt,
                $supplierId,
                mb_substr($sellerRef, 0, 64),
                mb_substr($paymentTerms, 0, 32),
                $total,
                $note,
                $accountCode1,
                $accountCode2,
                $accountCode3,
                $accountCode4,
                $userId,
                $taxDocType,
                $taxMode,
                $taxPercent,
            ]);
            $poId = (int) $pdo->lastInsertId();

            $insL = $pdo->prepare(
                'INSERT INTO po_lines (tenant_id, purchase_order_id, product_code, product_name, qty, unit_price, sort_order)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $sort = 0;
            foreach ($lines as $ln) {
                $insL->execute([
                    $tenantId,
                    $poId,
                    (string) $ln['product_code'],
                    (string) $ln['product_name'],
                    (float) $ln['qty'],
                    (float) $ln['unit_price'],
                    $sort++,
                ]);
            }

            $insA = $pdo->prepare(
                'INSERT INTO po_budget_allocations (tenant_id, purchase_order_id, budget_detail_id, amount, req_number)
                 VALUES (?, ?, ?, ?, ?)'
            );
            $normalized = self::normalizeAllocations($allocations);
            if ($normalized === []) {
                throw new RuntimeException('Debe imputar al menos una linea de presupuesto con monto mayor a cero.');
            }
            BudgetService::assertOcAllocationsWithinSaldo($pdo, $tenantId, $normalized);

            foreach ($normalized as $a) {
                $did = (int) $a['budget_detail_id'];
                $amt = round((float) $a['amount'], 2);
                self::assertDetailTenant($pdo, $tenantId, $did);
                $insA->execute([
                    $tenantId,
                    $poId,
                    $did,
                    $amt,
                    trim((string) ($a['req_number'] ?? '')),
                ]);
            }

            $pdo->commit();

            return $poId;
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * @param list<array{budget_detail_id: int, amount: float, req_number?: string}> $allocations
     * @return list<array{budget_detail_id: int, amount: float, req_number: string}>
     */
    public static function normalizeAllocations(array $allocations): array
    {
        $out = [];
        foreach ($allocations as $a) {
            $did = (int) ($a['budget_detail_id'] ?? 0);
            $amt = round((float) ($a['amount'] ?? 0), 2);
            if ($did <= 0 || $amt <= 0) {
                continue;
            }
            $out[] = [
                'budget_detail_id' => $did,
                'amount' => $amt,
                'req_number' => trim((string) ($a['req_number'] ?? '')),
            ];
        }

        return $out;
    }

    public static function assertDetailTenant(PDO $pdo, int $tenantId, int $detailId): void
    {
        $st = $pdo->prepare(
            'SELECT 1 FROM budget_details bd
             INNER JOIN budget_headers bh ON bh.id = bd.budget_header_id
             WHERE bd.id = ? AND bh.tenant_id = ?'
        );
        $st->execute([$detailId, $tenantId]);
        if (!$st->fetchColumn()) {
            throw new RuntimeException('Linea de presupuesto no valida para este tenant');
        }
    }

    /** @return list<array<string, mixed>> */
    public static function listOrders(
        PDO $pdo,
        int $tenantId,
        int $year,
        ?int $status = null,
        ?int $limit = null,
        int $offset = 0,
    ): array {
        $where = 'po.tenant_id = ? AND po.folio_year = ?';
        $params = [$tenantId, (string) $year];
        if ($status !== null && $status >= 0 && $status <= 2) {
            $where .= ' AND po.status = ?';
            $params[] = $status;
        }
        $sql = 'SELECT po.id, po.folio_year, po.numero_oc, po.ordered_at, po.total_amount, po.status, po.note,
                    po.invoice_number_in,
                    s.legal_name AS supplier_name
             FROM purchase_orders po
             INNER JOIN suppliers s ON s.id = po.supplier_id
             WHERE ' . $where . '
             ORDER BY po.numero_oc DESC';
        if ($limit !== null && $limit > 0) {
            $sql .= ' LIMIT ' . (int) $limit . ' OFFSET ' . max(0, $offset);
        }
        $st = $pdo->prepare($sql);
        $st->execute($params);

        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function countOrders(PDO $pdo, int $tenantId, int $year, ?int $status = null): int
    {
        $where = 'tenant_id = ? AND folio_year = ?';
        $params = [$tenantId, (string) $year];
        if ($status !== null && $status >= 0 && $status <= 2) {
            $where .= ' AND status = ?';
            $params[] = $status;
        }
        $st = $pdo->prepare('SELECT COUNT(*) FROM purchase_orders WHERE ' . $where);
        $st->execute($params);

        return (int) $st->fetchColumn();
    }

    public const STATUS_ABIERTA = PurchaseOrderStatus::ABIERTA;
    public const STATUS_CERRADA = PurchaseOrderStatus::CERRADA;
    public const STATUS_ANULADA = PurchaseOrderStatus::ANULADA;

    private static bool $voidedAtBackfilled = false;

    /**
     * Rellena voided_at faltante en OCs anuladas. Solo CLI (database/backfill_voided_at.php).
     * La columna debe existir (migracion 007).
     */
    public static function ensureVoidedAtBackfill(PDO $pdo): void
    {
        if (self::$voidedAtBackfilled) {
            return;
        }
        self::$voidedAtBackfilled = true;
        try {
            $pdo->exec(
                'UPDATE purchase_orders
                 SET voided_at = COALESCE(voided_at, ordered_at, created_at, NOW())
                 WHERE status = ' . PurchaseOrderStatus::ANULADA . ' AND voided_at IS NULL'
            );
        } catch (Throwable) {
            // Columna voided_at ausente: aplicar database/migrations/007_purchase_orders_voided_at.sql
        }
    }

    /** @deprecated Usar ensureVoidedAtBackfill() */
    public static function ensureVoidedAtColumn(PDO $pdo): void
    {
        self::ensureVoidedAtBackfill($pdo);
    }

    public static function canEditPurchaseOrder(array $poRow): bool
    {
        return (int) ($poRow['status'] ?? -1) === self::STATUS_ABIERTA;
    }

    /**
     * Cambio solo de perfil tributario: Abierta o Cerrada (no Anulada).
     * No desbloquea edicion completa de lineas/presupuesto/proveedor.
     */
    public static function canChangeTaxProfile(array $poRow): bool
    {
        $status = (int) ($poRow['status'] ?? -1);

        return $status === self::STATUS_ABIERTA || $status === self::STATUS_CERRADA;
    }

    /**
     * Reglas de transicion de estado OC.
     * - No reabrir a Abierta desde Cerrada/Anulada (evita volver a editar contenido).
     * - Para pasar a Cerrada se exige numero de factura/boleta proveedor (&gt; 0).
     *
     * @param array<string, mixed> $poRow Fila con status e invoice_number_in
     */
    public static function assertStatusTransition(array $poRow, int $newStatus): void
    {
        $cur = (int) ($poRow['status'] ?? -1);
        if ($newStatus < 0 || $newStatus > 2) {
            throw new RuntimeException('Estado de OC invalido.');
        }
        if ($newStatus === $cur) {
            return;
        }
        if ($newStatus === self::STATUS_ABIERTA && $cur !== self::STATUS_ABIERTA) {
            throw new RuntimeException(
                'No se puede reabrir una OC cerrada o anulada. El contenido ya no es editable.'
            );
        }
        if ($newStatus === self::STATUS_CERRADA) {
            $inv = (int) ($poRow['invoice_number_in'] ?? 0);
            if ($inv <= 0) {
                throw new RuntimeException(
                    'Debe ingresar el numero de factura/boleta del proveedor antes de cerrar la OC.'
                );
            }
        }
    }

    /**
     * Actualiza unicamente tax_doc_type / tax_mode / tax_percent (multi-tenant).
     *
     * @param array{tax_doc_type: string, tax_mode: string, tax_percent: float} $taxProfile
     */
    public static function updatePurchaseOrderTaxProfile(
        PDO $pdo,
        int $tenantId,
        int $purchaseOrderId,
        array $taxProfile,
    ): void {
        $st = $pdo->prepare('SELECT id, status FROM purchase_orders WHERE id = ? AND tenant_id = ?');
        $st->execute([$purchaseOrderId, $tenantId]);
        $po = $st->fetch(PDO::FETCH_ASSOC);
        if ($po === false) {
            throw new RuntimeException('OC no encontrada.');
        }
        if (!self::canChangeTaxProfile($po)) {
            throw new RuntimeException('No se puede cambiar el perfil tributario de una OC anulada.');
        }

        $pdo->prepare(
            'UPDATE purchase_orders SET tax_doc_type = ?, tax_mode = ?, tax_percent = ?
             WHERE id = ? AND tenant_id = ?'
        )->execute([
            (string) ($taxProfile['tax_doc_type'] ?? ''),
            (string) ($taxProfile['tax_mode'] ?? ''),
            (float) ($taxProfile['tax_percent'] ?? 0),
            $purchaseOrderId,
            $tenantId,
        ]);
    }

    /**
     * @return array{
     *   order: array<string, mixed>,
     *   lines: list<array{product_code: string, product_name: string, qty: float, unit_price: float}>,
     *   allocations: list<array{budget_detail_id: int, amount: float, req_number: string, activity_number: string, account_label: string}>
     * }|null
     */
    public static function getPurchaseOrderForEdit(PDO $pdo, int $tenantId, int $purchaseOrderId): ?array
    {
        $st = $pdo->prepare(
            'SELECT po.*, COALESCE(NULLIF(s.trade_name, \'\'), s.legal_name) AS supplier_display,
                    s.phone AS supplier_phone, s.email AS supplier_email
             FROM purchase_orders po
             INNER JOIN suppliers s ON s.id = po.supplier_id
             WHERE po.id = ? AND po.tenant_id = ?'
        );
        $st->execute([$purchaseOrderId, $tenantId]);
        $po = $st->fetch(PDO::FETCH_ASSOC);
        if ($po === false) {
            return null;
        }

        $stL = $pdo->prepare(
            'SELECT product_code, product_name, qty, unit_price
             FROM po_lines WHERE purchase_order_id = ? AND tenant_id = ?
             ORDER BY sort_order, id'
        );
        $stL->execute([$purchaseOrderId, $tenantId]);
        $lines = [];
        foreach ($stL->fetchAll(PDO::FETCH_ASSOC) as $ln) {
            $lines[] = [
                'product_code' => (string) $ln['product_code'],
                'product_name' => (string) $ln['product_name'],
                'qty' => (float) $ln['qty'],
                'unit_price' => (float) $ln['unit_price'],
            ];
        }

        $stA = $pdo->prepare(
            'SELECT pba.budget_detail_id, pba.amount, pba.req_number,
                    bh.activity_number, ac.account_id, ac.name AS account_name
             FROM po_budget_allocations pba
             INNER JOIN budget_details bd ON bd.id = pba.budget_detail_id
             INNER JOIN budget_headers bh ON bh.id = bd.budget_header_id
             INNER JOIN account_codes ac ON ac.id = bd.account_code_id
             WHERE pba.purchase_order_id = ? AND pba.tenant_id = ?
             ORDER BY pba.id'
        );
        $stA->execute([$purchaseOrderId, $tenantId]);
        $allocations = [];
        foreach ($stA->fetchAll(PDO::FETCH_ASSOC) as $a) {
            $did = (int) $a['budget_detail_id'];
            $saldo = BudgetService::detailAllocationSaldo($pdo, $tenantId, $did, $purchaseOrderId);
            $allocations[] = [
                'budget_detail_id' => $did,
                'amount' => round((float) $a['amount'], 2),
                'req_number' => trim((string) ($a['req_number'] ?? '')),
                'activity_number' => (string) $a['activity_number'],
                'account_label' => account_code_label([
                    'account_id' => $a['account_id'] ?? 0,
                    'name' => $a['account_name'] ?? '',
                ]),
                'line_remaining' => $saldo['line_remaining'],
                'header_remaining' => $saldo['header_remaining'],
            ];
        }

        return [
            'order' => $po,
            'lines' => $lines,
            'allocations' => $allocations,
        ];
    }

    /**
     * @param list<array{product_code: string, product_name: string, qty: float, unit_price: float}> $lines
     * @param list<array{budget_detail_id: int, amount: float, req_number?: string}> $allocations
     * @param array{tax_doc_type: string, tax_mode: string, tax_percent: float}|null $taxProfile
     *        null = no cambiar columnas tax_*; array = persistir perfil base
     */
    public static function updatePurchaseOrder(
        PDO $pdo,
        int $tenantId,
        int $purchaseOrderId,
        string $orderedAt,
        int $supplierId,
        string $note,
        array $lines,
        array $allocations,
        string $sellerRef = '',
        string $paymentTerms = '',
        int $accountCode1 = 0,
        int $accountCode2 = 0,
        int $accountCode3 = 0,
        int $accountCode4 = 0,
        ?array $taxProfile = null,
    ): void {
        $st = $pdo->prepare('SELECT id, status, folio_year FROM purchase_orders WHERE id = ? AND tenant_id = ?');
        $st->execute([$purchaseOrderId, $tenantId]);
        $po = $st->fetch(PDO::FETCH_ASSOC);
        if ($po === false) {
            throw new RuntimeException('OC no encontrada.');
        }
        if (!self::canEditPurchaseOrder($po)) {
            throw new RuntimeException('Solo las OC en estado Abierta pueden editarse.');
        }

        $total = 0.0;
        foreach ($lines as $ln) {
            $total += (float) $ln['qty'] * (float) $ln['unit_price'];
        }
        $total = round($total, 4);

        $normalized = self::normalizeAllocations($allocations);
        if ($normalized === []) {
            throw new RuntimeException('Debe imputar al menos una linea de presupuesto con monto mayor a cero.');
        }
        BudgetService::assertOcAllocationsWithinSaldo($pdo, $tenantId, $normalized, $purchaseOrderId);

        $pdo->beginTransaction();
        try {
            if ($taxProfile !== null) {
                $pdo->prepare(
                    'UPDATE purchase_orders SET ordered_at = ?, supplier_id = ?, seller_ref = ?, payment_terms = ?,
                        total_amount = ?, note = ?, account_code_1 = ?, account_code_2 = ?, account_code_3 = ?, account_code_4 = ?,
                        tax_doc_type = ?, tax_mode = ?, tax_percent = ?
                     WHERE id = ? AND tenant_id = ?'
                )->execute([
                    $orderedAt,
                    $supplierId,
                    mb_substr($sellerRef, 0, 64),
                    mb_substr($paymentTerms, 0, 32),
                    $total,
                    $note,
                    $accountCode1,
                    $accountCode2,
                    $accountCode3,
                    $accountCode4,
                    (string) ($taxProfile['tax_doc_type'] ?? ''),
                    (string) ($taxProfile['tax_mode'] ?? ''),
                    (float) ($taxProfile['tax_percent'] ?? 0),
                    $purchaseOrderId,
                    $tenantId,
                ]);
            } else {
                $pdo->prepare(
                    'UPDATE purchase_orders SET ordered_at = ?, supplier_id = ?, seller_ref = ?, payment_terms = ?,
                        total_amount = ?, note = ?, account_code_1 = ?, account_code_2 = ?, account_code_3 = ?, account_code_4 = ?
                     WHERE id = ? AND tenant_id = ?'
                )->execute([
                    $orderedAt,
                    $supplierId,
                    mb_substr($sellerRef, 0, 64),
                    mb_substr($paymentTerms, 0, 32),
                    $total,
                    $note,
                    $accountCode1,
                    $accountCode2,
                    $accountCode3,
                    $accountCode4,
                    $purchaseOrderId,
                    $tenantId,
                ]);
            }

            $pdo->prepare('DELETE FROM po_lines WHERE purchase_order_id = ? AND tenant_id = ?')
                ->execute([$purchaseOrderId, $tenantId]);
            $insL = $pdo->prepare(
                'INSERT INTO po_lines (tenant_id, purchase_order_id, product_code, product_name, qty, unit_price, sort_order)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $sort = 0;
            foreach ($lines as $ln) {
                $insL->execute([
                    $tenantId,
                    $purchaseOrderId,
                    (string) $ln['product_code'],
                    (string) $ln['product_name'],
                    (float) $ln['qty'],
                    (float) $ln['unit_price'],
                    $sort++,
                ]);
            }

            $pdo->prepare('DELETE FROM po_budget_allocations WHERE purchase_order_id = ? AND tenant_id = ?')
                ->execute([$purchaseOrderId, $tenantId]);
            $insA = $pdo->prepare(
                'INSERT INTO po_budget_allocations (tenant_id, purchase_order_id, budget_detail_id, amount, req_number)
                 VALUES (?, ?, ?, ?, ?)'
            );
            foreach ($normalized as $a) {
                $did = (int) $a['budget_detail_id'];
                self::assertDetailTenant($pdo, $tenantId, $did);
                $insA->execute([
                    $tenantId,
                    $purchaseOrderId,
                    $did,
                    round((float) $a['amount'], 2),
                    trim((string) ($a['req_number'] ?? '')),
                ]);
            }

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}
