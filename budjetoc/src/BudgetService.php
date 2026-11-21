<?php

declare(strict_types=1);

final class BudgetService
{
    private static ?bool $hasCreatedByColumn = null;

    private static ?bool $hasBudgetYearColumn = null;

    private static function schemaChecksEnabled(): bool
    {
        global $CONFIG;
        if (isset($CONFIG['schema_checks'])) {
            return (bool) $CONFIG['schema_checks'];
        }

        return ($CONFIG['env'] ?? 'local') !== 'production';
    }

    public static function hasCreatedByColumn(PDO $pdo): bool
    {
        if (!self::schemaChecksEnabled()) {
            return true;
        }
        if (self::$hasCreatedByColumn === null) {
            $st = $pdo->query("SHOW COLUMNS FROM budget_headers LIKE 'created_by'");
            self::$hasCreatedByColumn = (bool) $st->fetch(PDO::FETCH_ASSOC);
        }

        return self::$hasCreatedByColumn;
    }

    public static function hasBudgetYearColumn(PDO $pdo): bool
    {
        if (!self::schemaChecksEnabled()) {
            return true;
        }
        if (self::$hasBudgetYearColumn === null) {
            $st = $pdo->query("SHOW COLUMNS FROM budget_headers LIKE 'budget_year'");
            self::$hasBudgetYearColumn = (bool) $st->fetch(PDO::FETCH_ASSOC);
        }

        return self::$hasBudgetYearColumn;
    }

    private static function whereActivityYear(PDO $pdo, string $alias, int $year): string
    {
        if (self::hasBudgetYearColumn($pdo)) {
            return ' AND ' . $alias . '.budget_year = ' . (int) $year;
        }

        return ' AND CAST(SUBSTRING_INDEX(' . $alias . '.activity_number, \'-\', -1) AS UNSIGNED) = ' . (int) $year;
    }

    /** Clausula parametrizada para filtro por ano (listHeaders). */
    private static function activityYearEqualsClause(PDO $pdo, string $alias): string
    {
        if (self::hasBudgetYearColumn($pdo)) {
            return ' AND ' . $alias . '.budget_year = ?';
        }

        return ' AND CAST(SUBSTRING_INDEX(' . $alias . '.activity_number, \'-\', -1) AS UNSIGNED) = ?';
    }

    /**
     * @param list<int> $headerIds
     * @return array<int, float> header_id => utilizado
     */
    public static function utilizedSumsByHeaderIds(
        PDO $pdo,
        array $headerIds,
        ?int $excludePurchaseOrderId = null,
    ): array {
        $headerIds = array_values(array_unique(array_filter(array_map('intval', $headerIds))));
        if ($headerIds === []) {
            return [];
        }
        $ph = implode(',', array_fill(0, count($headerIds), '?'));
        $sql = "SELECT bd.budget_header_id AS hid, COALESCE(SUM(pba.amount), 0) AS s
             FROM budget_details bd
             INNER JOIN po_budget_allocations pba ON pba.budget_detail_id = bd.id
             INNER JOIN purchase_orders po ON po.id = pba.purchase_order_id
                 AND po.tenant_id = pba.tenant_id AND po.status <> " . PurchaseOrderStatus::ANULADA . "
             WHERE bd.budget_header_id IN ({$ph})";
        $params = $headerIds;
        if ($excludePurchaseOrderId !== null && $excludePurchaseOrderId > 0) {
            $sql .= ' AND pba.purchase_order_id <> ?';
            $params[] = $excludePurchaseOrderId;
        }
        $sql .= ' GROUP BY bd.budget_header_id';
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $out = [];
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $out[(int) $row['hid']] = round((float) $row['s'], 2);
        }

        return $out;
    }

    /**
     * @param list<int> $detailIds
     * @return array<int, float> detail_id => utilizado
     */
    public static function utilizedSumsByDetailIds(
        PDO $pdo,
        array $detailIds,
        ?int $excludePurchaseOrderId = null,
    ): array {
        $detailIds = array_values(array_unique(array_filter(array_map('intval', $detailIds))));
        if ($detailIds === []) {
            return [];
        }
        $ph = implode(',', array_fill(0, count($detailIds), '?'));
        $sql = "SELECT pba.budget_detail_id AS did, COALESCE(SUM(pba.amount), 0) AS s
             FROM po_budget_allocations pba
             INNER JOIN purchase_orders po ON po.id = pba.purchase_order_id
                 AND po.tenant_id = pba.tenant_id AND po.status <> " . PurchaseOrderStatus::ANULADA . "
             WHERE pba.budget_detail_id IN ({$ph})";
        $params = $detailIds;
        if ($excludePurchaseOrderId !== null && $excludePurchaseOrderId > 0) {
            $sql .= ' AND pba.purchase_order_id <> ?';
            $params[] = $excludePurchaseOrderId;
        }
        $sql .= ' GROUP BY pba.budget_detail_id';
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $out = [];
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $out[(int) $row['did']] = round((float) $row['s'], 2);
        }

        return $out;
    }

    /** @param list<array<string, mixed>> $rows */
    private static function enrichDashboardRows(PDO $pdo, array $rows): array
    {
        if ($rows === []) {
            return [];
        }
        $headerIds = array_map(static fn (array $r): int => (int) $r['id'], $rows);
        $totalsByHeader = self::headerTotalsByHeaderIds($pdo, $headerIds);
        $utilByHeader = self::utilizedSumsByHeaderIds($pdo, $headerIds);
        foreach ($rows as &$r) {
            $hid = (int) $r['id'];
            $tot = round($totalsByHeader[$hid] ?? 0.0, 2);
            $occ = $utilByHeader[$hid] ?? 0.0;
            $r['total'] = $tot;
            $r['utilized'] = $occ;
            $r['remaining'] = max(0, round($tot - $occ, 2));
            $r['occupied_amount'] = $occ;
            $r['remaining_amount'] = $r['remaining'];
        }
        unset($r);

        return $rows;
    }

    /**
     * Cabeceras por id (export reutiliza token de sesion sin repetir resolveExport).
     *
     * @param list<int> $headerIds
     * @return list<array<string, mixed>>
     */
    public static function listHeadersByIds(PDO $pdo, int $tenantId, array $headerIds): array
    {
        $headerIds = array_values(array_unique(array_filter(array_map('intval', $headerIds))));
        if ($headerIds === []) {
            return [];
        }
        $ph = implode(',', array_fill(0, count($headerIds), '?'));
        $createdJoin = self::hasCreatedByColumn($pdo)
            ? ' LEFT JOIN departments cr ON cr.id = bh.created_by AND cr.tenant_id = bh.tenant_id'
            : '';
        $createdSel = self::hasCreatedByColumn($pdo)
            ? ', bh.created_by, cr.name AS created_by_name'
            : '';
        $sql = 'SELECT bh.id, bh.activity_number, bh.month, bh.observations,
                    bh.department_id, bh.division_id' . $createdSel . ',
                    cc.name AS cost_center_name, a.name AS activity_name,
                    d.name AS department_name,
                    div_d.name AS division_name
             FROM budget_headers bh
             LEFT JOIN cost_centers cc ON cc.id = bh.cost_center_id
             INNER JOIN activities a ON a.id = bh.activity_id
             LEFT JOIN departments d ON d.id = bh.department_id
             LEFT JOIN departments div_d ON div_d.id = bh.division_id'
            . $createdJoin . '
             WHERE bh.tenant_id = ? AND bh.id IN (' . $ph . ')
             ORDER BY bh.month ASC, bh.activity_number';
        $st = $pdo->prepare($sql);
        $st->execute(array_merge([$tenantId], $headerIds));

        return self::enrichDashboardRows($pdo, $st->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * @param list<int>|null $departmentIds
     * @return list<array<string, mixed>>
     */
    public static function listByMonthFiltered(
        PDO $pdo,
        int $tenantId,
        ?int $month,
        ?array $departmentIds,
        int $budgetYear,
        bool $idsOnly = false,
    ): array {
        if ($idsOnly) {
            $sql = 'SELECT bh.id FROM budget_headers bh WHERE bh.tenant_id = ?';
            $params = [$tenantId];
            if ($month !== null) {
                $sql .= ' AND bh.month = ?';
                $params[] = $month;
            }
            $sql .= self::whereActivityYear($pdo, 'bh', $budgetYear);
            if ($departmentIds !== null && $departmentIds !== []) {
                $ph = implode(',', array_fill(0, count($departmentIds), '?'));
                if (self::hasCreatedByColumn($pdo)) {
                    $sql .= " AND (bh.department_id IN ({$ph}) OR bh.division_id IN ({$ph}) OR bh.created_by IN ({$ph}))";
                    $params = array_merge($params, $departmentIds, $departmentIds, $departmentIds);
                } else {
                    $sql .= " AND (bh.department_id IN ({$ph}) OR bh.division_id IN ({$ph}))";
                    $params = array_merge($params, $departmentIds, $departmentIds);
                }
            }
            $sql .= $month === null ? ' ORDER BY bh.month ASC, bh.id' : ' ORDER BY bh.id';
            $st = $pdo->prepare($sql);
            $st->execute($params);

            return self::rowsAsHeaderIds($st->fetchAll(PDO::FETCH_ASSOC));
        }

        $createdJoin = self::hasCreatedByColumn($pdo)
            ? ' LEFT JOIN departments cr ON cr.id = bh.created_by AND cr.tenant_id = bh.tenant_id'
            : '';
        $createdSel = self::hasCreatedByColumn($pdo)
            ? ', bh.created_by, cr.name AS created_by_name'
            : '';
        $sql = 'SELECT bh.id, bh.activity_number, bh.month, bh.observations,
                    bh.department_id, bh.division_id' . $createdSel . ',
                    cc.name AS cost_center_name, a.name AS activity_name,
                    d.name AS department_name,
                    div_d.name AS division_name
             FROM budget_headers bh
             LEFT JOIN cost_centers cc ON cc.id = bh.cost_center_id
             INNER JOIN activities a ON a.id = bh.activity_id
             LEFT JOIN departments d ON d.id = bh.department_id
             LEFT JOIN departments div_d ON div_d.id = bh.division_id'
            . $createdJoin . '
             WHERE bh.tenant_id = ?';
        $params = [$tenantId];
        if ($month !== null) {
            $sql .= ' AND bh.month = ?';
            $params[] = $month;
        }
        $sql .= self::whereActivityYear($pdo, 'bh', $budgetYear);
        if ($departmentIds !== null && $departmentIds !== []) {
            $ph = implode(',', array_fill(0, count($departmentIds), '?'));
            if (self::hasCreatedByColumn($pdo)) {
                $sql .= " AND (bh.department_id IN ({$ph}) OR bh.division_id IN ({$ph}) OR bh.created_by IN ({$ph}))";
                $params = array_merge($params, $departmentIds, $departmentIds, $departmentIds);
            } else {
                $sql .= " AND (bh.department_id IN ({$ph}) OR bh.division_id IN ({$ph}))";
                $params = array_merge($params, $departmentIds, $departmentIds);
            }
        }
        $sql .= $month === null ? ' ORDER BY bh.month ASC, bh.activity_number' : ' ORDER BY bh.activity_number';
        $st = $pdo->prepare($sql);
        $st->execute($params);

        return self::enrichDashboardRows($pdo, $st->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Panel division (estandar): vista by_centros_costo | by_division.
     *
     * @param list<int> $divisionIds
     * @return list<array<string, mixed>>
     */
    public static function listByMonthForEncargadoDivision(
        PDO $pdo,
        int $tenantId,
        ?int $month,
        array $divisionIds,
        string $view,
        int $budgetYear,
        bool $idsOnly = false,
    ): array {
        if ($divisionIds === []) {
            return [];
        }

        $divisionDeptId = (int) $divisionIds[0];
        $hasCreatedBy = self::hasCreatedByColumn($pdo);
        $createdSel = $hasCreatedBy && !$idsOnly ? ', bh.created_by, cr.name AS created_by_name' : '';
        $createdJoin = $hasCreatedBy
            ? ' LEFT JOIN departments cr ON cr.id = bh.created_by AND cr.tenant_id = bh.tenant_id'
            : '';
        $ccJoin = $view === 'by_division'
            ? ' INNER JOIN cost_centers cc ON cc.id = bh.cost_center_id AND cc.tenant_id = bh.tenant_id'
            : ' LEFT JOIN cost_centers cc ON cc.id = bh.cost_center_id';

        $detailSelect = $idsOnly
            ? 'SELECT bh.id'
            : 'SELECT bh.id, bh.activity_number, bh.month, bh.observations,
                    bh.department_id, bh.division_id' . $createdSel . ',
                    cc.name AS cost_center_name, a.name AS activity_name,
                    d.name AS department_name,
                    div_d.name AS division_name';
        $activityJoin = $idsOnly ? '' : ' INNER JOIN activities a ON a.id = bh.activity_id';
        $deptJoins = $idsOnly
            ? ''
            : ' LEFT JOIN departments d ON d.id = bh.department_id
             LEFT JOIN departments div_d ON div_d.id = bh.division_id';

        $baseSelect = $detailSelect
             . ' FROM budget_headers bh'
            . $ccJoin
            . $activityJoin
            . $deptJoins
            . $createdJoin
            . ' LEFT JOIN departments dpar ON dpar.id = bh.department_id AND dpar.tenant_id = bh.tenant_id
             WHERE bh.tenant_id = ?';
        $params = [$tenantId];
        if ($month !== null) {
            $baseSelect .= ' AND bh.month = ?';
            $params[] = $month;
        }
        $baseSelect .= self::whereActivityYear($pdo, 'bh', $budgetYear);

        if ($view === 'by_division') {
            // Actividades cargadas por esta division (estandar) imputadas a centros de costo del catalogo.
            if ($hasCreatedBy) {
                $sql = $baseSelect
                    . ' AND ('
                    . '(bh.created_by = ? AND cr.type = \'estandar\')'
                    . ' OR (bh.created_by IS NULL AND bh.division_id = ? AND bh.department_id IS NULL)'
                    . ')';
                $params[] = $divisionDeptId;
                $params[] = $divisionDeptId;
            } else {
                $sql = $baseSelect . ' AND bh.division_id = ? AND bh.department_id IS NULL';
                $params[] = $divisionDeptId;
            }
        } else {
            // Centros de costo (coordinaciones) imputan a la division; el catalogo cost_centers no imputa
            // (las divisiones cargan actividades hacia esos ids — vista by_division).
            $excludeCatalogCc = ' AND (bh.cost_center_id IS NULL OR NOT EXISTS ('
                . 'SELECT 1 FROM cost_centers cc_cat WHERE cc_cat.id = bh.cost_center_id AND cc_cat.tenant_id = bh.tenant_id'
                . '))';

            if ($hasCreatedBy) {
                $sql = $baseSelect
                    . ' AND ('
                    . ' (cr.type = \'coordinacion\' AND ('
                    . '   bh.division_id = ?'
                    . '   OR (bh.division_id IS NULL AND cr.parent_id = ?)'
                    . ' ))'
                    . ' OR (bh.division_id IS NULL AND dpar.type = \'coordinacion\' AND dpar.parent_id = ?'
                    . '     AND (cr.id IS NULL OR cr.type = \'coordinacion\'))'
                    . ')'
                    . $excludeCatalogCc
                    . ' AND NOT (bh.created_by = ? AND cr.type = \'estandar\')'
                    . ' AND NOT (bh.created_by IS NULL AND bh.division_id = ? AND bh.department_id IS NULL)';
                $params[] = $divisionDeptId;
                $params[] = $divisionDeptId;
                $params[] = $divisionDeptId;
                $params[] = $divisionDeptId;
                $params[] = $divisionDeptId;
            } else {
                $sql = $baseSelect
                    . ' AND bh.department_id IS NOT NULL'
                    . ' AND EXISTS ('
                    . 'SELECT 1 FROM departments d_coord WHERE d_coord.id = bh.department_id'
                    . ' AND d_coord.tenant_id = bh.tenant_id AND d_coord.type = \'coordinacion\''
                    . ')'
                    . ' AND (bh.division_id = ? OR EXISTS ('
                    . 'SELECT 1 FROM departments d_coord2 WHERE d_coord2.id = bh.department_id'
                    . ' AND d_coord2.parent_id = ?'
                    . '))'
                    . $excludeCatalogCc
                    . ' AND NOT (bh.division_id = ? AND bh.department_id IS NULL)';
                $params[] = $divisionDeptId;
                $params[] = $divisionDeptId;
                $params[] = $divisionDeptId;
            }
        }

        $sql .= $month === null
            ? ($idsOnly ? ' ORDER BY bh.month ASC, bh.id' : ' ORDER BY bh.month ASC, bh.activity_number')
            : ($idsOnly ? ' ORDER BY bh.id' : ' ORDER BY bh.activity_number');
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        if ($idsOnly) {
            return self::rowsAsHeaderIds($rows);
        }

        return self::enrichDashboardRows($pdo, $rows);
    }

    /** @param list<array{id: int|string}> $rows
     * @return list<array{id: int}>
     */
    private static function rowsAsHeaderIds(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $out[] = ['id' => (int) $row['id']];
        }

        return $out;
    }

    /** @return list<int> */
    private static function costCenterIdsForEstandarDivision(PDO $pdo, int $tenantId, int $divisionDepartmentId): array
    {
        $div = DepartmentCatalog::getById($pdo, $divisionDepartmentId, $tenantId);
        if ($div === null || ($div['type'] ?? '') !== 'estandar') {
            return [];
        }
        $divNk = self::normKeyDescarga((string) ($div['name'] ?? ''));
        if ($divNk === '') {
            return [];
        }
        $ids = [];
        foreach (CostCenterCatalog::listByTenant($pdo, $tenantId) as $row) {
            $ccNk = self::normKeyDescarga((string) ($row['name'] ?? ''));
            if ($ccNk === '') {
                continue;
            }
            if ($ccNk === $divNk || (strlen($divNk) >= 6 && (str_contains($ccNk, $divNk) || str_contains($divNk, $ccNk)))) {
                $ids[] = (int) $row['id'];
            }
        }

        return array_values(array_unique($ids));
    }

    private static function normKeyDescarga(string $s): string
    {
        $s = trim(mb_strtolower($s, 'UTF-8'));
        if ($s === '') {
            return '';
        }
        $s = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s) ?: $s;

        return preg_replace('/[^a-z0-9]/', '', strtolower($s)) ?? '';
    }

    public static function nextActivityNumber(PDO $pdo, int $tenantId, int $year): string
    {
        $pdo->beginTransaction();
        try {
            $st = $pdo->prepare('SELECT last_number FROM activity_sequences WHERE tenant_id = ? AND year = ? FOR UPDATE');
            $st->execute([$tenantId, $year]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            $next = 1;
            if ($row) {
                $next = (int) $row['last_number'] + 1;
                $pdo->prepare('UPDATE activity_sequences SET last_number = ? WHERE tenant_id = ? AND year = ?')
                    ->execute([$next, $tenantId, $year]);
            } else {
                $pdo->prepare('INSERT INTO activity_sequences (tenant_id, year, last_number) VALUES (?, ?, 1)')
                    ->execute([$tenantId, $year]);
            }
            $pdo->commit();

            return str_pad((string) $next, 4, '0', STR_PAD_LEFT) . '-' . $year;
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public static function headerTotal(PDO $pdo, int $headerId): float
    {
        $map = self::headerTotalsByHeaderIds($pdo, [$headerId]);

        return $map[$headerId] ?? 0.0;
    }

    /**
     * @param list<int> $headerIds
     * @return array<int, float> header_id => presupuesto total
     */
    public static function headerTotalsByHeaderIds(PDO $pdo, array $headerIds): array
    {
        $headerIds = array_values(array_unique(array_filter(array_map('intval', $headerIds))));
        if ($headerIds === []) {
            return [];
        }
        $ph = implode(',', array_fill(0, count($headerIds), '?'));
        $st = $pdo->prepare(
            "SELECT budget_header_id AS hid, COALESCE(SUM(quantity * unit_price), 0) AS s
             FROM budget_details
             WHERE budget_header_id IN ({$ph})
             GROUP BY budget_header_id"
        );
        $st->execute($headerIds);
        $out = [];
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $out[(int) $row['hid']] = round((float) $row['s'], 2);
        }

        return $out;
    }

    /**
     * @param list<int> $headerIds
     * @return array<int, list<array<string, mixed>>>
     */
    public static function detailsGroupedByHeaderIds(PDO $pdo, array $headerIds): array
    {
        $headerIds = array_values(array_unique(array_filter(array_map('intval', $headerIds))));
        if ($headerIds === []) {
            return [];
        }
        $ph = implode(',', array_fill(0, count($headerIds), '?'));
        $st = $pdo->prepare(
            "SELECT bd.budget_header_id AS header_id, bd.id AS detail_id,
                    ac.account_id, ac.name AS account_name,
                    bd.quantity, bd.unit_price, bd.observation
             FROM budget_details bd
             INNER JOIN account_codes ac ON ac.id = bd.account_code_id
             WHERE bd.budget_header_id IN ({$ph})
             ORDER BY bd.budget_header_id, bd.id"
        );
        $st->execute($headerIds);
        $grouped = [];
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $hid = (int) $row['header_id'];
            $grouped[$hid][] = $row;
        }

        return $grouped;
    }

    /**
     * Actividades creadas por una coordinacion (created_by o department_id legacy).
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    public static function filterRowsCreatedByDepartment(array $rows, int $departmentId): array
    {
        return array_values(array_filter(
            $rows,
            static function (array $row) use ($departmentId): bool {
                $createdBy = (int) ($row['created_by'] ?? 0);
                if ($createdBy > 0) {
                    return $createdBy === $departmentId;
                }

                return (int) ($row['department_id'] ?? 0) === $departmentId;
            }
        ));
    }

    public static function detailUtilized(PDO $pdo, int $detailId, ?int $excludePurchaseOrderId = null): float
    {
        $sql = 'SELECT COALESCE(SUM(pba.amount), 0)
             FROM po_budget_allocations pba
             INNER JOIN purchase_orders po ON po.id = pba.purchase_order_id
                 AND po.tenant_id = pba.tenant_id AND po.status <> ' . PurchaseOrderStatus::ANULADA . '
             WHERE pba.budget_detail_id = ?';
        $params = [$detailId];
        if ($excludePurchaseOrderId !== null && $excludePurchaseOrderId > 0) {
            $sql .= ' AND pba.purchase_order_id <> ?';
            $params[] = $excludePurchaseOrderId;
        }
        $st = $pdo->prepare($sql);
        $st->execute($params);

        return round((float) $st->fetchColumn(), 2);
    }

    public static function headerUtilized(PDO $pdo, int $headerId, ?int $excludePurchaseOrderId = null): float
    {
        $sql = 'SELECT COALESCE(SUM(pba.amount), 0)
             FROM po_budget_allocations pba
             INNER JOIN budget_details bd ON bd.id = pba.budget_detail_id
             INNER JOIN purchase_orders po ON po.id = pba.purchase_order_id
                 AND po.tenant_id = pba.tenant_id AND po.status <> ' . PurchaseOrderStatus::ANULADA . '
             WHERE bd.budget_header_id = ?';
        $params = [$headerId];
        if ($excludePurchaseOrderId !== null && $excludePurchaseOrderId > 0) {
            $sql .= ' AND pba.purchase_order_id <> ?';
            $params[] = $excludePurchaseOrderId;
        }
        $st = $pdo->prepare($sql);
        $st->execute($params);

        return round((float) $st->fetchColumn(), 2);
    }

    /**
     * Lineas de presupuesto para modal OC (mismo tenant).
     *
     * @return list<array<string, mixed>>
     */
    public static function searchLines(PDO $pdo, int $tenantId, string $q, int $limit = 20): array
    {
        $q = trim($q);
        $sql = 'SELECT bd.id AS detail_id, bh.id AS header_id, bh.activity_number,
                       bd.quantity, bd.unit_price, (bd.quantity * bd.unit_price) AS line_total,
                       COALESCE(bd.observation, \'\') AS line_observation,
                       bh.observations AS header_observations,
                       ac.account_id AS account_code, ac.name AS account_name
                FROM budget_details bd
                INNER JOIN budget_headers bh ON bh.id = bd.budget_header_id AND bh.tenant_id = ?
                INNER JOIN account_codes ac ON ac.id = bd.account_code_id
                WHERE bh.tenant_id = ?';
        $params = [$tenantId, $tenantId];
        if ($q !== '') {
            $sql .= ' AND (bh.activity_number LIKE ? OR bh.observations LIKE ? OR bd.observation LIKE ?'
                . ' OR CAST(ac.account_id AS CHAR) LIKE ? OR ac.name LIKE ?)';
            $like = '%' . $q . '%';
            $params = array_merge($params, [$like, $like, $like, $like, $like]);
        }
        $sql .= ' ORDER BY bh.activity_number ASC, bd.id ASC LIMIT ' . (int) $limit;
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        if ($rows === []) {
            return [];
        }
        $detailIds = array_map(static fn (array $r): int => (int) $r['detail_id'], $rows);
        $headerIds = array_map(static fn (array $r): int => (int) $r['header_id'], $rows);
        $utilByDetail = self::utilizedSumsByDetailIds($pdo, $detailIds);
        $utilByHeader = self::utilizedSumsByHeaderIds($pdo, $headerIds);
        $headerTotals = self::headerTotalsByHeaderIds($pdo, $headerIds);
        foreach ($rows as &$r) {
            $did = (int) $r['detail_id'];
            $hid = (int) $r['header_id'];
            $lineTot = round((float) $r['line_total'], 2);
            $util = (float) ($utilByDetail[$did] ?? 0.0);
            $hdrTot = $headerTotals[$hid] ?? 0.0;
            $hdrUtil = (float) ($utilByHeader[$hid] ?? 0.0);
            $r['utilized'] = $util;
            $r['line_remaining'] = max(0, round($lineTot - $util, 2));
            $r['header_total'] = $hdrTot;
            $r['header_utilized'] = $hdrUtil;
            $r['header_remaining'] = max(0, round($hdrTot - $hdrUtil, 2));
        }
        unset($r);

        return $rows;
    }

    /**
     * Lineas de presupuesto para imputar OC (mismo tenant, un solo DB).
     * Filtra por ano de actividad y opcionalmente solo lineas con saldo.
     *
     * @return list<array<string, mixed>>
     */
    public static function searchLinesForOc(
        PDO $pdo,
        int $tenantId,
        string $q,
        ?int $year = null,
        int $limit = 30,
        bool $onlyWithBalance = true,
    ): array {
        $rows = self::searchLines($pdo, $tenantId, $q, $limit * 3);
        $filtered = [];
        foreach ($rows as $r) {
            $actYear = budget_year_from_activity_number((string) ($r['activity_number'] ?? ''));
            if ($year !== null && $actYear !== $year) {
                continue;
            }
            if ($onlyWithBalance && (float) ($r['line_remaining'] ?? 0) <= 0.005) {
                continue;
            }
            $lineRem = (float) ($r['line_remaining'] ?? 0);
            $hdrRem = (float) ($r['header_remaining'] ?? 0);
            $r['ocupar_default'] = $hdrRem > 0.005 ? min($lineRem, $hdrRem) : $lineRem;
            $r['account_label'] = account_code_label([
                'account_id' => $r['account_code'] ?? 0,
                'name' => $r['account_name'] ?? '',
            ]);
            $filtered[] = $r;
            if (count($filtered) >= $limit) {
                break;
            }
        }

        return $filtered;
    }

    /**
     * Saldo disponible para imputar una nueva OC en una linea (tenant + detail).
     *
     * @return array{
     *   detail_id: int,
     *   header_id: int,
     *   activity_number: string,
     *   line_total: float,
     *   line_utilized: float,
     *   line_remaining: float,
     *   header_total: float,
     *   header_utilized: float,
     *   header_remaining: float
     * }
     */
    public static function detailAllocationSaldo(
        PDO $pdo,
        int $tenantId,
        int $detailId,
        ?int $excludePurchaseOrderId = null,
    ): array {
        $st = $pdo->prepare(
            'SELECT bd.id AS detail_id, bh.id AS header_id, bh.activity_number,
                    (bd.quantity * bd.unit_price) AS line_total
             FROM budget_details bd
             INNER JOIN budget_headers bh ON bh.id = bd.budget_header_id
             WHERE bd.id = ? AND bh.tenant_id = ?'
        );
        $st->execute([$detailId, $tenantId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new RuntimeException('Linea de presupuesto no encontrada');
        }
        $hid = (int) $row['header_id'];
        $did = (int) $row['detail_id'];
        $lineTot = round((float) $row['line_total'], 2);
        $lineUtil = self::detailUtilized($pdo, $did, $excludePurchaseOrderId);
        $hdrTot = self::headerTotal($pdo, $hid);
        $hdrUtil = self::headerUtilized($pdo, $hid, $excludePurchaseOrderId);

        return [
            'detail_id' => $did,
            'header_id' => $hid,
            'activity_number' => (string) $row['activity_number'],
            'line_total' => $lineTot,
            'line_utilized' => $lineUtil,
            'line_remaining' => max(0, round($lineTot - $lineUtil, 2)),
            'header_total' => $hdrTot,
            'header_utilized' => $hdrUtil,
            'header_remaining' => max(0, round($hdrTot - $hdrUtil, 2)),
        ];
    }

    /**
     * @param list<array{budget_detail_id: int, amount: float}> $allocations
     */
    public static function assertOcAllocationsWithinSaldo(
        PDO $pdo,
        int $tenantId,
        array $allocations,
        ?int $excludePurchaseOrderId = null,
    ): void {
        $pending = [];
        foreach ($allocations as $a) {
            $did = (int) ($a['budget_detail_id'] ?? 0);
            $amt = round((float) ($a['amount'] ?? 0), 2);
            if ($did <= 0 || $amt <= 0) {
                continue;
            }
            $pending[] = ['detail_id' => $did, 'amount' => $amt];
        }
        if ($pending === []) {
            return;
        }

        $detailIds = array_values(array_unique(array_map(static fn (array $a): int => $a['detail_id'], $pending)));
        $ph = implode(',', array_fill(0, count($detailIds), '?'));
        $st = $pdo->prepare(
            "SELECT bd.id AS detail_id, bh.id AS header_id, bh.activity_number,
                    (bd.quantity * bd.unit_price) AS line_total
             FROM budget_details bd
             INNER JOIN budget_headers bh ON bh.id = bd.budget_header_id
             WHERE bd.id IN ({$ph}) AND bh.tenant_id = ?"
        );
        $st->execute(array_merge($detailIds, [$tenantId]));
        $lineMeta = [];
        $headerIds = [];
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $did = (int) $row['detail_id'];
            $hid = (int) $row['header_id'];
            $lineMeta[$did] = [
                'header_id' => $hid,
                'activity_number' => (string) $row['activity_number'],
                'line_total' => round((float) $row['line_total'], 2),
            ];
            $headerIds[$hid] = $hid;
        }

        $utilByDetail = self::utilizedSumsByDetailIds($pdo, $detailIds, $excludePurchaseOrderId);
        $headerIds = array_values($headerIds);
        $hdrTotals = self::headerTotalsByHeaderIds($pdo, $headerIds);
        $hdrUtil = self::utilizedSumsByHeaderIds($pdo, $headerIds, $excludePurchaseOrderId);

        $byHeader = [];
        $byDetail = [];
        foreach ($pending as $a) {
            $did = $a['detail_id'];
            $amt = $a['amount'];
            $meta = $lineMeta[$did] ?? null;
            if ($meta === null) {
                throw new RuntimeException('Linea de presupuesto no encontrada');
            }
            $lineUtil = $utilByDetail[$did] ?? 0.0;
            $lineRem = max(0, round($meta['line_total'] - $lineUtil, 2)) - ($byDetail[$did] ?? 0);
            if ($amt > $lineRem + 0.02) {
                throw new RuntimeException(sprintf(
                    'Monto %.2f supera saldo de linea (%.2f) en actividad %s',
                    $amt,
                    $lineRem,
                    $meta['activity_number']
                ));
            }
            $hid = $meta['header_id'];
            $hdrTot = $hdrTotals[$hid] ?? 0.0;
            $hdrUsed = $hdrUtil[$hid] ?? 0.0;
            $hdrRem = max(0, round($hdrTot - $hdrUsed, 2)) - ($byHeader[$hid] ?? 0);
            if ($amt > $hdrRem + 0.02) {
                throw new RuntimeException(sprintf(
                    'Monto %.2f supera saldo de actividad %s (disponible %.2f)',
                    $amt,
                    $meta['activity_number'],
                    $hdrRem
                ));
            }
            $byDetail[$did] = ($byDetail[$did] ?? 0) + $amt;
            $byHeader[$hid] = ($byHeader[$hid] ?? 0) + $amt;
        }
    }

    /**
     * @param list<int>|null $allowedDepartmentIds null = sin filtro (admin tenant)
     * @return list<array<string, mixed>>
     */
    public static function listHeaders(
        PDO $pdo,
        int $tenantId,
        int $year,
        ?int $month,
        ?array $allowedDepartmentIds = null,
    ): array {
        $sql = 'SELECT bh.id, bh.activity_number, bh.month, bh.observations,
                       cc.name AS cost_center_name, a.name AS activity_name,
                       d.name AS department_name, div_d.name AS division_name
                FROM budget_headers bh
                LEFT JOIN cost_centers cc ON cc.id = bh.cost_center_id
                INNER JOIN activities a ON a.id = bh.activity_id
                LEFT JOIN departments d ON d.id = bh.department_id
                LEFT JOIN departments div_d ON div_d.id = bh.division_id
                WHERE bh.tenant_id = ?'
            . self::activityYearEqualsClause($pdo, 'bh');
        $params = [$tenantId, $year];
        if ($allowedDepartmentIds !== null) {
            if ($allowedDepartmentIds === []) {
                return [];
            }
            $ph = implode(',', array_fill(0, count($allowedDepartmentIds), '?'));
            $sql .= " AND (bh.division_id IN ({$ph}) OR bh.department_id IN ({$ph}))";
            $params = array_merge($params, $allowedDepartmentIds, $allowedDepartmentIds);
        }
        if ($month !== null) {
            $sql .= ' AND bh.month = ?';
            $params[] = $month;
        }
        $sql .= ' ORDER BY bh.month, bh.activity_number';
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        return self::enrichDashboardRows($pdo, $rows);
    }

    /**
     * Imputaciones OC vigentes de una actividad (excluye anuladas; cuentan en saldo).
     *
     * @return list<array<string, mixed>>
     */
    public static function allocationsForHeader(PDO $pdo, int $tenantId, int $headerId): array
    {
        return self::allocationsForHeaderByStatus($pdo, $tenantId, $headerId, false);
    }

    /**
     * Imputaciones de OC anuladas (historial reversado; no cuenta en saldo).
     *
     * @return list<array<string, mixed>>
     */
    public static function allocationsForHeaderVoided(PDO $pdo, int $tenantId, int $headerId): array
    {
        return self::allocationsForHeaderByStatus($pdo, $tenantId, $headerId, true);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function allocationsForHeaderByStatus(
        PDO $pdo,
        int $tenantId,
        int $headerId,
        bool $voidedOnly,
    ): array {
        $statusSql = $voidedOnly
            ? 'po.status = ' . PurchaseOrderStatus::ANULADA
            : 'po.status <> ' . PurchaseOrderStatus::ANULADA;
        $st = $pdo->prepare(
            "SELECT po.id AS purchase_order_id, po.folio_year, po.numero_oc, po.ordered_at, po.status,
                    po.voided_at, po.note AS po_note, bd.id AS detail_id, pba.amount, pba.req_number,
                    ac.account_id, ac.name AS account_name,
                    s.legal_name AS supplier_legal_name, s.trade_name AS supplier_trade_name,
                    (SELECT GROUP_CONCAT(TRIM(pl.product_name) ORDER BY pl.sort_order, pl.id SEPARATOR ' / ')
                     FROM po_lines pl
                     WHERE pl.purchase_order_id = po.id AND pl.tenant_id = po.tenant_id
                       AND TRIM(pl.product_name) <> '') AS po_line_descriptions
             FROM po_budget_allocations pba
             INNER JOIN budget_details bd ON bd.id = pba.budget_detail_id
             INNER JOIN budget_headers bh ON bh.id = bd.budget_header_id AND bh.tenant_id = ?
             INNER JOIN purchase_orders po ON po.id = pba.purchase_order_id AND po.tenant_id = pba.tenant_id
             INNER JOIN suppliers s ON s.id = po.supplier_id
             INNER JOIN account_codes ac ON ac.id = bd.account_code_id
             WHERE bh.id = ? AND {$statusSql}
             ORDER BY po.folio_year DESC, po.numero_oc DESC, pba.id"
        );
        $st->execute([$tenantId, $headerId]);

        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Crea cabecera + detalles (cabecera ya validada por BudgetActivityRules).
     * Si $activityNumber viene informado, se conserva ese numero (carga CSV) y se sincroniza
     * activity_sequences al maximo del año; si es null, se consume la secuencia como en UI manual.
     *
     * @param array{month: int, cost_center_id?: int|null, activity_id: int, division_id?: int|null, department_id?: int|null, observations?: string|null} $header
     * @param list<array{account_code_id: int, quantity: float, unit_price: float, observation: string}> $details
     */
    public static function createActivity(
        PDO $pdo,
        int $tenantId,
        array $header,
        array $details,
        int $year,
        ?int $createdByDepartmentId = null,
        ?string $activityNumber = null,
    ): int {
        if ($details === []) {
            throw new InvalidArgumentException('At least one detail line is required');
        }
        $fixedNumber = $activityNumber !== null ? trim($activityNumber) : '';
        $useFixedNumber = $fixedNumber !== '';
        $actNum = $useFixedNumber
            ? $fixedNumber
            : self::nextActivityNumber($pdo, $tenantId, $year);
        $pdo->beginTransaction();
        try {
            $createdBy = $createdByDepartmentId !== null && $createdByDepartmentId > 0
                ? $createdByDepartmentId
                : null;
            $hasBudgetYear = self::hasBudgetYearColumn($pdo);
            if (self::hasCreatedByColumn($pdo)) {
                if ($hasBudgetYear) {
                    $pdo->prepare(
                        'INSERT INTO budget_headers (tenant_id, activity_number, budget_year, month, cost_center_id, activity_id, division_id, department_id, observations, created_by)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                    )->execute([
                        $tenantId,
                        $actNum,
                        $year,
                        (int) $header['month'],
                        isset($header['cost_center_id']) && $header['cost_center_id'] !== null
                            ? (int) $header['cost_center_id']
                            : null,
                        (int) $header['activity_id'],
                        isset($header['division_id']) && $header['division_id'] !== null
                            ? (int) $header['division_id']
                            : null,
                        isset($header['department_id']) && $header['department_id'] !== null
                            ? (int) $header['department_id']
                            : null,
                        $header['observations'] ?? null,
                        $createdBy,
                    ]);
                } else {
                    $pdo->prepare(
                        'INSERT INTO budget_headers (tenant_id, activity_number, month, cost_center_id, activity_id, division_id, department_id, observations, created_by)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
                    )->execute([
                        $tenantId,
                        $actNum,
                        (int) $header['month'],
                        isset($header['cost_center_id']) && $header['cost_center_id'] !== null
                            ? (int) $header['cost_center_id']
                            : null,
                        (int) $header['activity_id'],
                        isset($header['division_id']) && $header['division_id'] !== null
                            ? (int) $header['division_id']
                            : null,
                        isset($header['department_id']) && $header['department_id'] !== null
                            ? (int) $header['department_id']
                            : null,
                        $header['observations'] ?? null,
                        $createdBy,
                    ]);
                }
            } elseif ($hasBudgetYear) {
                $pdo->prepare(
                    'INSERT INTO budget_headers (tenant_id, activity_number, budget_year, month, cost_center_id, activity_id, division_id, department_id, observations)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
                )->execute([
                    $tenantId,
                    $actNum,
                    $year,
                    (int) $header['month'],
                    isset($header['cost_center_id']) && $header['cost_center_id'] !== null
                        ? (int) $header['cost_center_id']
                        : null,
                    (int) $header['activity_id'],
                    isset($header['division_id']) && $header['division_id'] !== null
                        ? (int) $header['division_id']
                        : null,
                    isset($header['department_id']) && $header['department_id'] !== null
                        ? (int) $header['department_id']
                        : null,
                    $header['observations'] ?? null,
                ]);
            } else {
                $pdo->prepare(
                    'INSERT INTO budget_headers (tenant_id, activity_number, month, cost_center_id, activity_id, division_id, department_id, observations)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
                )->execute([
                    $tenantId,
                    $actNum,
                    (int) $header['month'],
                    isset($header['cost_center_id']) && $header['cost_center_id'] !== null
                        ? (int) $header['cost_center_id']
                        : null,
                    (int) $header['activity_id'],
                    isset($header['division_id']) && $header['division_id'] !== null
                        ? (int) $header['division_id']
                        : null,
                    isset($header['department_id']) && $header['department_id'] !== null
                        ? (int) $header['department_id']
                        : null,
                    $header['observations'] ?? null,
                ]);
            }
            $hid = (int) $pdo->lastInsertId();
            $ins = $pdo->prepare(
                'INSERT INTO budget_details (budget_header_id, account_code_id, quantity, unit_price, observation)
                 VALUES (?, ?, ?, ?, ?)'
            );
            foreach ($details as $row) {
                $ins->execute([
                    $hid,
                    (int) $row['account_code_id'],
                    (float) $row['quantity'],
                    (float) $row['unit_price'],
                    $row['observation'] ?? '',
                ]);
            }
            if ($useFixedNumber) {
                self::syncActivitySequenceFromNumber($pdo, $tenantId, $year, $actNum);
            }
            $pdo->commit();

            return $hid;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Alinea activity_sequences al maximo base numerico del año tras un INSERT con numero fijo.
     * Patron equivalente a Records2026ImportService::syncActivitySequence.
     */
    private static function syncActivitySequenceFromNumber(
        PDO $pdo,
        int $tenantId,
        int $year,
        string $activityNumber,
    ): void {
        $base = 0;
        if (preg_match('/^(\d+)-\d{4}$/', $activityNumber, $m) === 1) {
            $base = (int) $m[1];
        } elseif (preg_match('/^\d+$/', $activityNumber) === 1) {
            $base = (int) $activityNumber;
        }
        if ($base <= 0) {
            return;
        }
        $st = $pdo->prepare('SELECT last_number FROM activity_sequences WHERE tenant_id = ? AND year = ?');
        $st->execute([$tenantId, $year]);
        $current = $st->fetchColumn();
        if ($current === false) {
            $pdo->prepare('INSERT INTO activity_sequences (tenant_id, year, last_number) VALUES (?, ?, ?)')
                ->execute([$tenantId, $year, $base]);
        } elseif ((int) $current < $base) {
            $pdo->prepare('UPDATE activity_sequences SET last_number = ? WHERE tenant_id = ? AND year = ?')
                ->execute([$base, $tenantId, $year]);
        }
    }

    /** @return array<string, mixed>|null */
    public static function getHeader(PDO $pdo, int $tenantId, int $headerId): ?array
    {
        $st = $pdo->prepare('SELECT * FROM budget_headers WHERE id = ? AND tenant_id = ? LIMIT 1');
        $st->execute([$headerId, $tenantId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }

    /**
     * @param array{month: int, cost_center_id?: int|null, activity_id: int, division_id?: int|null, department_id?: int|null, observations?: string|null} $header
     * @param list<array{id?: int, account_code_id: int, quantity: float, unit_price: float, observation: string}> $details
     */
    public static function updateActivity(PDO $pdo, int $tenantId, int $headerId, array $header, array $details): void
    {
        if ($details === []) {
            throw new InvalidArgumentException('At least one detail line is required');
        }
        $existing = self::getHeader($pdo, $tenantId, $headerId);
        if ($existing === null) {
            throw new InvalidArgumentException('Activity not found');
        }

        $pdo->beginTransaction();
        try {
            $pdo->prepare(
                'UPDATE budget_headers SET month = ?, cost_center_id = ?, activity_id = ?, division_id = ?, department_id = ?, observations = ?
                 WHERE id = ? AND tenant_id = ?'
            )->execute([
                (int) $header['month'],
                isset($header['cost_center_id']) && $header['cost_center_id'] !== null
                    ? (int) $header['cost_center_id']
                    : null,
                (int) $header['activity_id'],
                isset($header['division_id']) && $header['division_id'] !== null
                    ? (int) $header['division_id']
                    : null,
                isset($header['department_id']) && $header['department_id'] !== null
                    ? (int) $header['department_id']
                    : null,
                $header['observations'] ?? null,
                $headerId,
                $tenantId,
            ]);

            $stExisting = $pdo->prepare(
                'SELECT id FROM budget_details WHERE budget_header_id = ?'
            );
            $stExisting->execute([$headerId]);
            $existingIds = [];
            while ($row = $stExisting->fetch(PDO::FETCH_ASSOC)) {
                $existingIds[] = (int) $row['id'];
            }

            $keepIds = [];
            $upd = $pdo->prepare(
                'UPDATE budget_details SET account_code_id = ?, quantity = ?, unit_price = ?, observation = ?
                 WHERE id = ? AND budget_header_id = ?'
            );
            $ins = $pdo->prepare(
                'INSERT INTO budget_details (budget_header_id, account_code_id, quantity, unit_price, observation)
                 VALUES (?, ?, ?, ?, ?)'
            );

            foreach ($details as $row) {
                $detailId = isset($row['id']) ? (int) $row['id'] : 0;
                $lineTotal = round((float) $row['quantity'] * (float) $row['unit_price'], 2);
                if ($detailId > 0 && in_array($detailId, $existingIds, true)) {
                    $util = self::detailUtilized($pdo, $detailId);
                    if ($util > 0 && $lineTotal < $util) {
                        throw new InvalidArgumentException(
                            'No puede bajar el total de una linea por debajo de lo imputado en OC (' . $detailId . ')'
                        );
                    }
                    $upd->execute([
                        (int) $row['account_code_id'],
                        (float) $row['quantity'],
                        (float) $row['unit_price'],
                        $row['observation'] ?? '',
                        $detailId,
                        $headerId,
                    ]);
                    $keepIds[] = $detailId;
                } else {
                    $ins->execute([
                        $headerId,
                        (int) $row['account_code_id'],
                        (float) $row['quantity'],
                        (float) $row['unit_price'],
                        $row['observation'] ?? '',
                    ]);
                    $keepIds[] = (int) $pdo->lastInsertId();
                }
            }

            foreach ($existingIds as $oldId) {
                if (in_array($oldId, $keepIds, true)) {
                    continue;
                }
                if (self::detailUtilized($pdo, $oldId) > 0) {
                    throw new InvalidArgumentException(
                        'No puede eliminar lineas con imputacion OC. Deje el monto en cero o ajuste la linea.'
                    );
                }
                $pdo->prepare('DELETE FROM budget_details WHERE id = ? AND budget_header_id = ?')
                    ->execute([$oldId, $headerId]);
            }

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Busca cabecera por numero de actividad (exacto o prefijo numerico + ano).
     *
     * @return array<string, mixed>|null
     */
    public static function findHeaderByActivityNumber(
        PDO $pdo,
        int $tenantId,
        string $activityNumber,
        ?int $year = null,
    ): ?array {
        $q = trim($activityNumber);
        if ($q === '') {
            return null;
        }
        $sql = 'SELECT bh.id, bh.activity_number, bh.month, bh.observations,
                       cc.name AS cost_center_name, a.name AS activity_name,
                       d.name AS department_name, div_d.name AS division_name
                FROM budget_headers bh
                LEFT JOIN cost_centers cc ON cc.id = bh.cost_center_id
                INNER JOIN activities a ON a.id = bh.activity_id
                LEFT JOIN departments d ON d.id = bh.department_id
                LEFT JOIN departments div_d ON div_d.id = bh.division_id
                WHERE bh.tenant_id = ? AND (bh.activity_number = ?';
        $params = [$tenantId, $q];
        if (!str_contains($q, '-')) {
            $sql .= ' OR bh.activity_number LIKE ?';
            $params[] = $q . '-%';
        }
        $sql .= ')';
        if ($year !== null) {
            $sql .= self::activityYearEqualsClause($pdo, 'bh');
            $params[] = $year;
        }
        $sql .= ' ORDER BY bh.activity_number LIMIT 2';
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        if ($rows === []) {
            return null;
        }
        if (count($rows) > 1) {
            foreach ($rows as $r) {
                if ((string) $r['activity_number'] === $q) {
                    $hid = (int) $r['id'];
                    $tot = round(self::headerTotalsByHeaderIds($pdo, [$hid])[$hid] ?? 0.0, 2);
                    $occ = self::headerUtilized($pdo, $hid);
                    $r['total'] = $tot;
                    $r['occupied'] = $occ;
                    $r['remaining'] = max(0, round($tot - $occ, 2));

                    return $r;
                }
            }
        }
        $row = $rows[0];
        $hid = (int) $row['id'];
        $tot = self::headerTotalsByHeaderIds($pdo, [$hid])[$hid] ?? 0.0;
        $tot = round($tot, 2);
        $occ = self::headerUtilized($pdo, $hid);
        $row['total'] = $tot;
        $row['occupied'] = $occ;
        $row['remaining'] = max(0, round($tot - $occ, 2));

        return $row;
    }

    /**
     * Imputaciones OC por subactividad (excluye OC anuladas).
     *
     * @return array{
     *   total: float,
     *   links: int,
     *   by_detail: array<int, float>,
     *   oc_numbers_by_detail: array<int, string>,
     *   req_numbers_by_detail: array<int, string>
     * }
     */
    public static function ocImputationsForHeader(PDO $pdo, int $tenantId, int $headerId): array
    {
        $st = $pdo->prepare(
            'SELECT pba.budget_detail_id AS detail_id, pba.amount, pba.req_number,
                    po.folio_year, po.numero_oc, po.seller_ref, po.status
             FROM po_budget_allocations pba
             INNER JOIN budget_details bd ON bd.id = pba.budget_detail_id
             INNER JOIN budget_headers bh ON bh.id = bd.budget_header_id AND bh.tenant_id = ?
             INNER JOIN purchase_orders po ON po.id = pba.purchase_order_id AND po.tenant_id = pba.tenant_id
             WHERE bh.id = ? AND po.status <> ?'
        );
        $st->execute([$tenantId, $headerId, PurchaseOrderStatus::ANULADA]);
        $total = 0.0;
        $links = 0;
        $byDetail = [];
        $ocValues = [];
        $reqValues = [];
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $links++;
            $amount = round((float) ($row['amount'] ?? 0), 2);
            $total += $amount;
            $detailId = (int) ($row['detail_id'] ?? 0);
            if ($detailId <= 0) {
                continue;
            }
            $byDetail[$detailId] = ($byDetail[$detailId] ?? 0.0) + $amount;

            $folio = oc_folio_label((string) ($row['folio_year'] ?? ''), (int) ($row['numero_oc'] ?? 0));
            $ocLabel = 'OC ' . $folio;
            $ocValues[$detailId][$ocLabel] = true;

            $req = trim((string) ($row['req_number'] ?? ''));
            if ($req === '') {
                $req = trim((string) ($row['seller_ref'] ?? ''));
            }
            if ($req !== '') {
                $reqValues[$detailId][$req] = true;
            }
        }
        $joinDistinct = static function (array $values): array {
            $out = [];
            foreach ($values as $detailId => $set) {
                $out[(int) $detailId] = mb_substr(implode(' / ', array_keys($set)), 0, 64);
            }

            return $out;
        };
        foreach ($byDetail as $detailId => $amount) {
            $byDetail[$detailId] = round((float) $amount, 2);
        }

        return [
            'total' => round($total, 2),
            'links' => $links,
            'by_detail' => $byDetail,
            'oc_numbers_by_detail' => $joinDistinct($ocValues),
            'req_numbers_by_detail' => $joinDistinct($reqValues),
        ];
    }

    /**
     * Lineas de subactividad para pantalla Presupuesto OC.
     *
     * @return list<array<string, mixed>>
     */
    public static function detailLinesForOcBudgetEditor(
        PDO $pdo,
        int $tenantId,
        int $headerId,
        array $ocImputations,
    ): array {
        $st = $pdo->prepare(
            'SELECT bd.id AS detail_id, bh.activity_number, bh.month, bh.observations AS header_observations,
                    COALESCE(bd.observation, \'\') AS line_observation,
                    (bd.quantity * bd.unit_price) AS line_total,
                    ac.account_id, ac.name AS account_name,
                    cc.name AS cost_center_name, a.name AS activity_name,
                    d.name AS department_name, div_d.name AS division_name
             FROM budget_details bd
             INNER JOIN budget_headers bh ON bh.id = bd.budget_header_id AND bh.tenant_id = ?
             INNER JOIN account_codes ac ON ac.id = bd.account_code_id
             LEFT JOIN cost_centers cc ON cc.id = bh.cost_center_id
             INNER JOIN activities a ON a.id = bh.activity_id
             LEFT JOIN departments d ON d.id = bh.department_id
             LEFT JOIN departments div_d ON div_d.id = bh.division_id
             WHERE bh.id = ?
             ORDER BY bd.id'
        );
        $st->execute([$tenantId, $headerId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        if ($rows === []) {
            return [];
        }

        $detailIds = array_map(static fn (array $r): int => (int) $r['detail_id'], $rows);

        $byDetail = $ocImputations['by_detail'] ?? [];
        $ocByDetail = $ocImputations['oc_numbers_by_detail'] ?? [];
        $reqByDetail = $ocImputations['req_numbers_by_detail'] ?? [];
        $actYear = budget_year_from_activity_number((string) ($rows[0]['activity_number'] ?? ''));

        foreach ($rows as &$r) {
            $did = (int) $r['detail_id'];
            $lineTot = round((float) $r['line_total'], 2);
            $util = round((float) ($byDetail[$did] ?? 0), 2);
            $ocNum = (string) ($ocByDetail[$did] ?? '');
            $reqNum = (string) ($reqByDetail[$did] ?? '');
            $r['num'] = activity_num_short((string) $r['activity_number']);
            $r['year'] = $actYear;
            $r['line_total'] = $lineTot;
            $r['utilized_amount'] = $util > 0.005 ? $util : '';
            $r['oc_number'] = $ocNum;
            $r['req_number'] = $reqNum;
            $r['quedan'] = max(0, round($lineTot - ($util > 0 ? $util : 0), 2));
            $r['account_label'] = account_code_label([
                'account_id' => $r['account_id'] ?? 0,
                'name' => $r['account_name'] ?? '',
            ]);
        }
        unset($r);

        return $rows;
    }

    /**
     * @param list<array<string, mixed>> $detailLines
     */
    public static function sumDetailUtilized(array $detailLines): float
    {
        $sum = 0.0;
        foreach ($detailLines as $ln) {
            $v = $ln['utilized_amount'] ?? '';
            if ($v === '' || $v === null) {
                continue;
            }
            $sum += round((float) $v, 2);
        }

        return round($sum, 2);
    }

    public static function applyHeaderSummaryFromDetails(array &$header, array $detailLines): void
    {
        $tot = round((float) ($header['total'] ?? 0), 2);
        $occ = self::sumDetailUtilized($detailLines);
        $header['occupied'] = $occ;
        $header['remaining'] = max(0, round($tot - $occ, 2));
    }
}
