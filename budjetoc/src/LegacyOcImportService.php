<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/database/sql_import_helpers.php';

final class LegacyOcImportService
{
    private const IMPORT_NOTE_TAG = '[legacy-oc-iae]';
    private const IMPORT_NOTE_TAG_2026 = '[legacy-oc-iae-2026]';
    private const SERIES_2026_MIN = 2026000;
    private const SERIES_2026_MAX = 2027000;

    /**
     * @return array{
     *   orders_created: int,
     *   orders_skipped: int,
     *   lines_created: int,
     *   allocations_created: int,
     *   allocations_skipped: int,
     *   suppliers_created: int,
     *   errors: list<string>
     * }
     */
    public static function importFromDump(
        PDO $pdo,
        string $dumpPath,
        int $tenantId,
        bool $dryRun = true,
    ): array {
        $dump = file_get_contents($dumpPath);
        if ($dump === false || $dump === '') {
            throw new RuntimeException('No se pudo leer el dump: ' . $dumpPath);
        }

        $stats = [
            'orders_created' => 0,
            'orders_skipped' => 0,
            'lines_created' => 0,
            'allocations_created' => 0,
            'allocations_skipped' => 0,
            'suppliers_created' => 0,
            'errors' => [],
        ];

        OcService::ensureVoidedAtBackfill($pdo);

        $personMap = self::loadPersons($dump);
        $productMap = self::loadProducts($dump);
        $linesByNum = self::indexLines($dump);
        $budgetByNum = self::indexBudget($dump);
        $facts = self::loadFacturas($dump);

        $supplierCache = self::existingSuppliersByName($pdo, $tenantId);
        $headerIds = self::existingHeaderIds($pdo, $tenantId);

        if (!$dryRun) {
            $pdo->beginTransaction();
        }

        try {
            foreach ($facts as $fact) {
                $folioYear = (string) ($fact['folio_year'] ?? '');
                $numeroOc = (int) ($fact['numero_factura'] ?? 0);
                if ($folioYear === '' || $numeroOc <= 0) {
                    continue;
                }

                if (self::orderExists($pdo, $tenantId, $folioYear, $numeroOc)) {
                    $stats['orders_skipped']++;
                    continue;
                }

                $legacyPersonId = (int) ($fact['id_cliente'] ?? 0);
                $supplierId = self::resolveSupplier(
                    $pdo,
                    $tenantId,
                    $legacyPersonId,
                    $personMap,
                    $supplierCache,
                    $stats,
                    $dryRun
                );
                if ($supplierId === null) {
                    $stats['errors'][] = "OC {$folioYear}-{$numeroOc}: proveedor legacy {$legacyPersonId} no encontrado";
                    continue;
                }

                $lines = $linesByNum[$numeroOc] ?? [];
                if ($lines === []) {
                    $stats['errors'][] = "OC {$folioYear}-{$numeroOc}: sin lineas en detalle_factura";
                    continue;
                }

                $total = self::sumLines($lines);
                if ($total <= 0) {
                    $total = (float) ($fact['total_venta'] ?? 0);
                }
                $total = round($total, 4);

                $allocations = self::buildAllocations(
                    $pdo,
                    $tenantId,
                    $budgetByNum[$numeroOc] ?? [],
                    $fact,
                    $headerIds,
                    $stats
                );

                if ($dryRun) {
                    $stats['orders_created']++;
                    $stats['lines_created'] += count($lines);
                    $stats['allocations_created'] += count($allocations);
                    continue;
                }

                $orderedAt = (string) ($fact['ordered_at'] ?? date('Y-m-d H:i:s'));
                $status = self::mapStatus((int) ($fact['estado_factura'] ?? 0));
                $voidedAt = $status === OcService::STATUS_ANULADA ? $orderedAt : null;
                $pdo->prepare(
                    'INSERT INTO purchase_orders
                     (tenant_id, folio_year, numero_oc, ordered_at, supplier_id, total_amount, status, voided_at, note, created_by)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                )->execute([
                    $tenantId,
                    $folioYear,
                    $numeroOc,
                    $orderedAt,
                    $supplierId,
                    $total,
                    $status,
                    $voidedAt,
                    self::buildNote((string) ($fact['note'] ?? '')),
                    self::resolveCreatedBy($pdo, (string) ($fact['id_vendedor'] ?? '')),
                ]);
                $poId = (int) $pdo->lastInsertId();
                $stats['orders_created']++;

                $insLine = $pdo->prepare(
                    'INSERT INTO po_lines (tenant_id, purchase_order_id, product_code, product_name, qty, unit_price, sort_order)
                     VALUES (?, ?, ?, ?, ?, ?, ?)'
                );
                $sort = 0;
                foreach ($lines as $ln) {
                    $insLine->execute([
                        $tenantId,
                        $poId,
                        (string) $ln['product_code'],
                        (string) $ln['product_name'],
                        (float) $ln['qty'],
                        (float) $ln['unit_price'],
                        $sort++,
                    ]);
                    $stats['lines_created']++;
                }

                if ($allocations !== []) {
                    $insAlloc = $pdo->prepare(
                        'INSERT INTO po_budget_allocations (tenant_id, purchase_order_id, budget_detail_id, amount, req_number)
                         VALUES (?, ?, ?, ?, ?)'
                    );
                    foreach ($allocations as $alloc) {
                        $insAlloc->execute([
                            $tenantId,
                            $poId,
                            $alloc['budget_detail_id'],
                            $alloc['amount'],
                            $alloc['req_number'],
                        ]);
                        $stats['allocations_created']++;
                    }
                }
            }

            if (!$dryRun) {
                $pdo->commit();
            }
        } catch (Throwable $e) {
            if (!$dryRun && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return $stats;
    }

    /**
     * OC serie 2026xxxx en detalle_factura/budget sin fila en facturas.
     * nun_budget legacy = numero de actividad (ej. 9577 -> 9577-2026).
     *
     * @return array{
     *   orders_created: int,
     *   orders_skipped: int,
     *   lines_created: int,
     *   allocations_created: int,
     *   allocations_skipped: int,
     *   suppliers_created: int,
     *   errors: list<string>
     * }
     */
    public static function importSeries2026FromDump(
        PDO $pdo,
        string $dumpPath,
        int $tenantId,
        bool $dryRun = true,
    ): array {
        $dump = file_get_contents($dumpPath);
        if ($dump === false || $dump === '') {
            throw new RuntimeException('No se pudo leer el dump: ' . $dumpPath);
        }

        $folioYear = '2026';
        $stats = [
            'orders_created' => 0,
            'orders_skipped' => 0,
            'lines_created' => 0,
            'allocations_created' => 0,
            'allocations_skipped' => 0,
            'suppliers_created' => 0,
            'errors' => [],
        ];

        $productMap = self::loadProducts($dump);
        $linesByNum = self::indexLines($dump);
        $budgetByNum = self::indexBudget($dump);
        $nums = self::collectSeries2026Numbers($linesByNum, $budgetByNum);

        $supplierCache = self::existingSuppliersByName($pdo, $tenantId);
        $defaultSupplierId = self::ensurePlaceholderSupplier($pdo, $tenantId, $supplierCache, $stats, $dryRun);

        if (!$dryRun) {
            $pdo->beginTransaction();
        }

        try {
            foreach ($nums as $numeroOc) {
                if (self::orderExists($pdo, $tenantId, $folioYear, $numeroOc)) {
                    $stats['orders_skipped']++;
                    continue;
                }

                $allocations = self::buildAllocations2026(
                    $pdo,
                    $tenantId,
                    $budgetByNum[$numeroOc] ?? [],
                    $stats
                );
                $lines = self::buildPoLinesFromLegacy($linesByNum[$numeroOc] ?? [], $productMap, $numeroOc);
                if ($lines === [] && $allocations === []) {
                    $stats['errors'][] = "OC 2026-{$numeroOc}: sin detalle_factura ni imputacion budget";
                    continue;
                }

                $total = round(self::sumLines($lines), 4);
                if ($total <= 0 && $allocations !== []) {
                    foreach ($allocations as $a) {
                        $total += (float) $a['amount'];
                    }
                    $total = round($total, 4);
                    if (count($lines) === 1 && (float) $lines[0]['unit_price'] === 0.0) {
                        $lines[0]['unit_price'] = $total;
                    }
                }

                if ($dryRun) {
                    $stats['orders_created']++;
                    $stats['lines_created'] += count($lines);
                    $stats['allocations_created'] += count($allocations);
                    continue;
                }

                if ($defaultSupplierId === null || $defaultSupplierId <= 0) {
                    $stats['errors'][] = "OC 2026-{$numeroOc}: proveedor placeholder no disponible";
                    continue;
                }

                $pdo->prepare(
                    'INSERT INTO purchase_orders
                     (tenant_id, folio_year, numero_oc, ordered_at, supplier_id, total_amount, status, note, created_by)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
                )->execute([
                    $tenantId,
                    $folioYear,
                    $numeroOc,
                    self::orderedAtForSeries2026($numeroOc),
                    $defaultSupplierId,
                    $total,
                    1,
                    self::IMPORT_NOTE_TAG_2026,
                    null,
                ]);
                $poId = (int) $pdo->lastInsertId();
                $stats['orders_created']++;

                $insL = $pdo->prepare(
                    'INSERT INTO po_lines (tenant_id, purchase_order_id, product_code, product_name, qty, unit_price, sort_order)
                     VALUES (?, ?, ?, ?, ?, ?, ?)'
                );
                $sort = 0;
                foreach ($lines as $ln) {
                    $insL->execute([
                        $tenantId,
                        $poId,
                        $ln['product_code'],
                        $ln['product_name'],
                        $ln['qty'],
                        $ln['unit_price'],
                        $sort++,
                    ]);
                    $stats['lines_created']++;
                }

                if ($allocations !== []) {
                    $insAlloc = $pdo->prepare(
                        'INSERT INTO po_budget_allocations (tenant_id, purchase_order_id, budget_detail_id, amount, req_number)
                         VALUES (?, ?, ?, ?, ?)'
                    );
                    foreach ($allocations as $alloc) {
                        $insAlloc->execute([
                            $tenantId,
                            $poId,
                            $alloc['budget_detail_id'],
                            $alloc['amount'],
                            $alloc['req_number'],
                        ]);
                        $stats['allocations_created']++;
                    }
                }
            }

            if (!$dryRun) {
                $pdo->commit();
            }
        } catch (Throwable $e) {
            if (!$dryRun && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return $stats;
    }

    /** @return array{orders_deleted: int, lines_deleted: int, allocations_deleted: int} */
    public static function revertImport(PDO $pdo, int $tenantId, ?string $folioYear = null): array
    {
        $notePrefix = $folioYear === '2026' ? self::IMPORT_NOTE_TAG_2026 : self::IMPORT_NOTE_TAG;
        $st = $pdo->prepare(
            'SELECT id FROM purchase_orders WHERE tenant_id = ? AND note LIKE ?'
        );
        $st->execute([$tenantId, $notePrefix . '%']);
        $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
        if ($ids === []) {
            return ['orders_deleted' => 0, 'lines_deleted' => 0, 'allocations_deleted' => 0];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $pdo->prepare("DELETE FROM po_budget_allocations WHERE purchase_order_id IN ({$placeholders})")
            ->execute($ids);
        $allocDeleted = count($ids);

        $pdo->prepare("DELETE FROM po_lines WHERE purchase_order_id IN ({$placeholders})")
            ->execute($ids);
        $lineDeleted = count($ids);

        $pdo->prepare("DELETE FROM purchase_orders WHERE id IN ({$placeholders})")
            ->execute($ids);

        return [
            'orders_deleted' => count($ids),
            'lines_deleted' => $lineDeleted,
            'allocations_deleted' => $allocDeleted,
        ];
    }

    /** @return list<array<string, mixed>> */
    private static function loadFacturas(string $dump): array
    {
        $rows = [];
        foreach (bud_sql_extract_insert_tuples($dump, 'facturas') as $tuple) {
            $f = bud_sql_parse_tuple($tuple);
            $rows[] = [
                'folio_year' => (string) ($f[1] ?? ''),
                'numero_factura' => (int) ($f[2] ?? 0),
                'ordered_at' => (string) ($f[3] ?? ''),
                'id_cliente' => (int) ($f[4] ?? 0),
                'id_vendedor' => (string) ($f[5] ?? ''),
                'total_venta' => (float) str_replace(',', '.', (string) ($f[7] ?? '0')),
                'estado_factura' => (int) ($f[8] ?? 0),
                'note' => (string) ($f[9] ?? ''),
                'nro_cta' => (int) ($f[10] ?? 0),
                'nro_cta2' => (int) ($f[11] ?? 0),
                'nro_cta3' => (int) ($f[12] ?? 0),
                'nro_cta4' => (int) ($f[13] ?? 0),
                'invoice_in' => (int) ($f[14] ?? 0),
            ];
        }

        return $rows;
    }

    /** @return array<int, array{name: string, email: string, phone: string}> */
    private static function loadPersons(string $dump): array
    {
        $map = [];
        foreach (bud_sql_extract_insert_tuples($dump, 'person') as $tuple) {
            $f = bud_sql_parse_tuple($tuple);
            $id = (int) ($f[0] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $legal = trim((string) ($f[2] ?? ''));
            $last = trim((string) ($f[3] ?? ''));
            $name = $legal !== '' ? $legal : $last;
            if ($legal !== '' && $last !== '' && !str_contains(mb_strtoupper($legal), mb_strtoupper($last))) {
                $name = $legal . ' ' . $last;
            }
            $map[$id] = [
                'name' => $name,
                'email' => trim((string) ($f[9] ?? '')),
                'phone' => trim((string) ($f[6] ?? '')),
            ];
        }

        return $map;
    }

    /** @return array<int, array{code: string, name: string}> */
    private static function loadProducts(string $dump): array
    {
        $map = [];
        foreach (bud_sql_extract_insert_tuples($dump, 'products') as $tuple) {
            $f = bud_sql_parse_tuple($tuple);
            $id = (int) ($f[0] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $map[$id] = [
                'code' => trim((string) ($f[1] ?? '')),
                'name' => trim((string) ($f[2] ?? '')),
            ];
        }

        return $map;
    }

    /**
     * @return array<int, list<array{product_code: string, product_name: string, qty: float, unit_price: float}>>
     */
    private static function indexLines(string $dump): array
    {
        $productMap = self::loadProducts($dump);
        $byNum = [];
        foreach (bud_sql_extract_insert_tuples($dump, 'detalle_factura') as $tuple) {
            $f = bud_sql_parse_tuple($tuple);
            $num = (int) ($f[1] ?? 0);
            $prodId = (int) ($f[2] ?? 0);
            $prod = $productMap[$prodId] ?? ['code' => (string) $prodId, 'name' => 'Producto ' . $prodId];
            $byNum[$num][] = [
                'product_code' => $prod['code'] !== '' ? $prod['code'] : (string) $prodId,
                'product_name' => $prod['name'] !== '' ? $prod['name'] : 'Producto ' . $prodId,
                'qty' => (float) ($f[3] ?? 0),
                'unit_price' => (float) str_replace(',', '.', (string) ($f[4] ?? '0')),
            ];
        }

        return $byNum;
    }

    /** @return array<int, list<array{header_id: int, cod_budget: int, amount: float}>> */
    private static function indexBudget(string $dump): array
    {
        $byNum = [];
        foreach (bud_sql_extract_insert_tuples($dump, 'budget') as $tuple) {
            $f = bud_sql_parse_tuple($tuple);
            $num = (int) ($f[1] ?? 0);
            $byNum[$num][] = [
                'header_id' => (int) ($f[2] ?? 0),
                'cod_budget' => (int) ($f[3] ?? 0),
                'amount' => (float) ($f[4] ?? 0),
            ];
        }

        return $byNum;
    }

    /**
     * @param list<array{header_id: int, cod_budget: int, amount: float}> $legacyRows
     * @param array<string, mixed> $fact
     * @param array<int, true> $headerIds
     * @param array<string, int> $stats
     * @return list<array{budget_detail_id: int, amount: float, req_number: string}>
     */
    private static function buildAllocations(
        PDO $pdo,
        int $tenantId,
        array $legacyRows,
        array $fact,
        array $headerIds,
        array &$stats,
    ): array {
        $req = (int) ($fact['invoice_in'] ?? 0) > 0 ? (string) (int) $fact['invoice_in'] : '';
        $out = [];
        foreach ($legacyRows as $row) {
            $headerId = (int) $row['header_id'];
            if (!isset($headerIds[$headerId])) {
                $stats['allocations_skipped']++;
                continue;
            }
            $detailId = self::resolveBudgetDetailId(
                $pdo,
                $tenantId,
                $headerId,
                (int) $row['cod_budget'],
                $fact,
                (float) $row['amount']
            );
            if ($detailId === null) {
                $stats['allocations_skipped']++;
                continue;
            }
            $amt = round((float) $row['amount'], 2);
            if ($amt <= 0) {
                $stats['allocations_skipped']++;
                continue;
            }
            $out[] = [
                'budget_detail_id' => $detailId,
                'amount' => $amt,
                'req_number' => $req,
            ];
        }

        return $out;
    }

    /** @param array<string, mixed> $fact */
    private static function resolveBudgetDetailId(
        PDO $pdo,
        int $tenantId,
        int $headerId,
        int $codBudget,
        array $fact,
        float $amount,
    ): ?int {
        $st = $pdo->prepare(
            'SELECT bd.id, ac.account_id, bd.unit_price, bd.quantity
             FROM budget_details bd
             INNER JOIN budget_headers bh ON bh.id = bd.budget_header_id
             INNER JOIN account_codes ac ON ac.id = bd.account_code_id
             WHERE bh.tenant_id = ? AND bh.id = ?'
        );
        $st->execute([$tenantId, $headerId]);
        $details = $st->fetchAll(PDO::FETCH_ASSOC);
        if ($details === []) {
            return null;
        }
        foreach ($details as $d) {
            if ((int) $d['id'] === $codBudget) {
                return (int) $d['id'];
            }
        }
        $accounts = [];
        foreach (['nro_cta', 'nro_cta2', 'nro_cta3', 'nro_cta4'] as $key) {
            $a = (int) ($fact[$key] ?? 0);
            if ($a > 0) {
                $accounts[$a] = true;
            }
        }
        if ($accounts !== []) {
            $matched = [];
            foreach ($details as $d) {
                if (isset($accounts[(int) $d['account_id']])) {
                    $matched[] = $d;
                }
            }
            if (count($matched) === 1) {
                return (int) $matched[0]['id'];
            }
            if ($matched !== []) {
                $details = $matched;
            }
        }
        if (count($details) === 1) {
            return (int) $details[0]['id'];
        }
        if ($amount > 0 && count($details) > 1) {
            $best = null;
            $bestDiff = PHP_FLOAT_MAX;
            foreach ($details as $d) {
                $lineTotal = (float) $d['unit_price'] * (float) $d['quantity'];
                $diff = abs($lineTotal - $amount);
                if ($diff < $bestDiff) {
                    $bestDiff = $diff;
                    $best = (int) $d['id'];
                }
            }

            return $best;
        }

        return (int) $details[0]['id'];
    }

    /** @return array<int, true> */
    private static function existingHeaderIds(PDO $pdo, int $tenantId): array
    {
        $st = $pdo->prepare('SELECT id FROM budget_headers WHERE tenant_id = ?');
        $st->execute([$tenantId]);
        $set = [];
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $set[(int) $id] = true;
        }

        return $set;
    }

    /** @return array<string, int> legal_name => supplier id */
    private static function existingSuppliersByName(PDO $pdo, int $tenantId): array
    {
        $st = $pdo->prepare('SELECT id, legal_name FROM suppliers WHERE tenant_id = ?');
        $st->execute([$tenantId]);
        $map = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $key = self::normName((string) $row['legal_name']);
            if ($key !== '') {
                $map[$key] = (int) $row['id'];
            }
        }

        return $map;
    }

    /**
     * @param array<int, array{name: string, email: string, phone: string}> $personMap
     * @param array<string, int> $supplierCache
     */
    private static function resolveSupplier(
        PDO $pdo,
        int $tenantId,
        int $legacyPersonId,
        array $personMap,
        array &$supplierCache,
        array &$stats,
        bool $dryRun,
    ): ?int {
        $person = $personMap[$legacyPersonId] ?? null;
        if ($person === null || $person['name'] === '') {
            return null;
        }
        $key = self::normName($person['name']);
        if (isset($supplierCache[$key])) {
            return $supplierCache[$key];
        }
        if ($dryRun) {
            $supplierCache[$key] = -1;

            return -1;
        }
        $pdo->prepare(
            'INSERT INTO suppliers (tenant_id, legal_name, trade_name, phone, email)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([
            $tenantId,
            $person['name'],
            '',
            $person['phone'],
            $person['email'],
        ]);
        $id = (int) $pdo->lastInsertId();
        $supplierCache[$key] = $id;
        $stats['suppliers_created']++;

        return $id;
    }

    private static function normName(string $name): string
    {
        $name = preg_replace('/\s+/u', ' ', trim($name)) ?? trim($name);

        return mb_strtoupper($name, 'UTF-8');
    }

    private static function orderExists(PDO $pdo, int $tenantId, string $folioYear, int $numeroOc): bool
    {
        $st = $pdo->prepare(
            'SELECT 1 FROM purchase_orders WHERE tenant_id = ? AND folio_year = ? AND numero_oc = ? LIMIT 1'
        );
        $st->execute([$tenantId, $folioYear, $numeroOc]);

        return (bool) $st->fetchColumn();
    }

    /** @param list<array{qty: float, unit_price: float}> $lines */
    private static function sumLines(array $lines): float
    {
        $total = 0.0;
        foreach ($lines as $ln) {
            $total += (float) $ln['qty'] * (float) $ln['unit_price'];
        }

        return $total;
    }

    private static function mapStatus(int $legacy): int
    {
        if ($legacy === 3) {
            return 2;
        }

        return 1;
    }

    private static function buildNote(string $legacyNote): string
    {
        $legacyNote = trim($legacyNote);
        if ($legacyNote === '') {
            return self::IMPORT_NOTE_TAG;
        }

        return self::IMPORT_NOTE_TAG . ' ' . $legacyNote;
    }

    /** @param array<int, list<array{product_code: string, product_name: string, qty: float, unit_price: float}>> $linesByNum */
    /** @param array<int, list<array{header_id: int, cod_budget: int, amount: float}>> $budgetByNum */
    /** @return list<int> */
    private static function collectSeries2026Numbers(array $linesByNum, array $budgetByNum): array
    {
        $nums = [];
        foreach (array_keys($linesByNum) as $n) {
            if ($n >= self::SERIES_2026_MIN && $n < self::SERIES_2026_MAX) {
                $nums[$n] = true;
            }
        }
        foreach (array_keys($budgetByNum) as $n) {
            if ($n >= self::SERIES_2026_MIN && $n < self::SERIES_2026_MAX) {
                $nums[$n] = true;
            }
        }
        $list = array_keys($nums);
        sort($list, SORT_NUMERIC);

        return $list;
    }

    private static function orderedAtForSeries2026(int $numeroOc): string
    {
        $seq = $numeroOc - self::SERIES_2026_MIN;
        if ($seq < 0) {
            $seq = 0;
        }
        $month = 1 + (int) floor($seq / 100) % 12;

        return sprintf('2026-%02d-15 12:00:00', max(1, min(12, $month)));
    }

    /**
     * @param array<int, array{code: string, name: string}> $productMap
     * @param list<array{product_code: string, product_name: string, qty: float, unit_price: float}> $rawLines
     * @return list<array{product_code: string, product_name: string, qty: float, unit_price: float}>
     */
    private static function buildPoLinesFromLegacy(array $rawLines, array $productMap, int $numeroOc): array
    {
        if ($rawLines !== []) {
            return $rawLines;
        }

        return [
            [
                'product_code' => 'legacy-' . $numeroOc,
                'product_name' => 'Importado dump IAE (sin detalle)',
                'qty' => 1.0,
                'unit_price' => 0.0,
            ],
        ];
    }

    /**
     * @param list<array{header_id: int, cod_budget: int, amount: float}> $legacyRows
     * @param array<string, int> $stats
     * @return list<array{budget_detail_id: int, amount: float, req_number: string}>
     */
    private static function buildAllocations2026(
        PDO $pdo,
        int $tenantId,
        array $legacyRows,
        array &$stats,
    ): array {
        $out = [];
        $emptyFact = [
            'nro_cta' => 0,
            'nro_cta2' => 0,
            'nro_cta3' => 0,
            'nro_cta4' => 0,
            'invoice_in' => 0,
        ];
        foreach ($legacyRows as $row) {
            $legacyNun = (int) $row['header_id'];
            $headerId = self::resolveHeaderIdFromLegacyNun($pdo, $tenantId, $legacyNun, 2026);
            if ($headerId === null) {
                $stats['allocations_skipped']++;
                continue;
            }
            $detailId = self::resolveBudgetDetailId(
                $pdo,
                $tenantId,
                $headerId,
                (int) $row['cod_budget'],
                $emptyFact,
                (float) $row['amount']
            );
            if ($detailId === null) {
                $stats['allocations_skipped']++;
                continue;
            }
            $amt = round((float) $row['amount'], 2);
            if ($amt <= 0) {
                $stats['allocations_skipped']++;
                continue;
            }
            $out[] = [
                'budget_detail_id' => $detailId,
                'amount' => $amt,
                'req_number' => '',
            ];
        }

        return $out;
    }

    private static function resolveHeaderIdFromLegacyNun(PDO $pdo, int $tenantId, int $legacyNun, int $year): ?int
    {
        if ($legacyNun <= 0) {
            return null;
        }
        $st = $pdo->prepare('SELECT id FROM budget_headers WHERE tenant_id = ? AND id = ? LIMIT 1');
        $st->execute([$tenantId, $legacyNun]);
        $id = $st->fetchColumn();
        if ($id !== false) {
            return (int) $id;
        }
        $act = $legacyNun . '-' . $year;
        $st = $pdo->prepare('SELECT id FROM budget_headers WHERE tenant_id = ? AND activity_number = ? LIMIT 1');
        $st->execute([$tenantId, $act]);
        $id = $st->fetchColumn();
        if ($id !== false) {
            return (int) $id;
        }
        if (BudgetService::hasBudgetYearColumn($pdo)) {
            $st = $pdo->prepare(
                'SELECT id FROM budget_headers
                 WHERE tenant_id = ? AND budget_year = ?
                   AND CAST(SUBSTRING_INDEX(activity_number, \'-\', 1) AS UNSIGNED) = ?
                 LIMIT 1'
            );
            $st->execute([$tenantId, $year, $legacyNun]);
        } else {
            $st = $pdo->prepare(
                'SELECT id FROM budget_headers
                 WHERE tenant_id = ? AND CAST(SUBSTRING_INDEX(activity_number, \'-\', -1) AS UNSIGNED) = ?
                   AND CAST(SUBSTRING_INDEX(activity_number, \'-\', 1) AS UNSIGNED) = ?
                 LIMIT 1'
            );
            $st->execute([$tenantId, $year, $legacyNun]);
        }
        $id = $st->fetchColumn();

        return $id !== false ? (int) $id : null;
    }

    /** @param array<string, int> $supplierCache */
    private static function ensurePlaceholderSupplier(
        PDO $pdo,
        int $tenantId,
        array &$supplierCache,
        array &$stats,
        bool $dryRun,
    ): ?int {
        $name = 'Proveedor pendiente (import OC 2026 IAE)';
        $key = self::normName($name);
        if (isset($supplierCache[$key]) && $supplierCache[$key] > 0) {
            return $supplierCache[$key];
        }
        if ($dryRun) {
            return -1;
        }
        $st = $pdo->prepare('SELECT id FROM suppliers WHERE tenant_id = ? AND legal_name = ? LIMIT 1');
        $st->execute([$tenantId, $name]);
        $id = $st->fetchColumn();
        if ($id !== false) {
            $supplierCache[$key] = (int) $id;

            return (int) $id;
        }
        $pdo->prepare(
            'INSERT INTO suppliers (tenant_id, legal_name, trade_name, phone, email) VALUES (?, ?, ?, ?, ?)'
        )->execute([$tenantId, $name, '', '', '']);
        $newId = (int) $pdo->lastInsertId();
        $supplierCache[$key] = $newId;
        $stats['suppliers_created']++;

        return $newId;
    }

    private static function resolveCreatedBy(PDO $pdo, string $legacySeller): ?int
    {
        $legacySeller = trim($legacySeller);
        if ($legacySeller === '' || !ctype_digit($legacySeller)) {
            return null;
        }
        $st = $pdo->prepare('SELECT id FROM users WHERE id = ? LIMIT 1');
        $st->execute([(int) $legacySeller]);

        $id = $st->fetchColumn();

        return $id === false ? null : (int) $id;
    }
}
