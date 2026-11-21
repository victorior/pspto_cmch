<?php

declare(strict_types=1);

/** Permisos por division / coordinacion (departments; logica PSPTO / BudgetFlow). */
final class UserPermissions
{
    /** @var array<string, list<int>|null> */
    private static array $allowedDepartmentIdsCache = [];

    public static function budgetRole(PDO $pdo, int $userId, int $tenantId): ?string
    {
        $st = $pdo->prepare(
            "SELECT role FROM user_tenant_roles WHERE user_id = ? AND tenant_id = ? AND app = 'budget' LIMIT 1"
        );
        $st->execute([$userId, $tenantId]);
        $role = $st->fetchColumn();

        return $role !== false ? (string) $role : null;
    }

    public static function isBudgetTenantAdmin(PDO $pdo, int $userId, int $tenantId): bool
    {
        if (Auth::isSuperadminUser($pdo, $userId) || Auth::isSuperadmin()) {
            return true;
        }

        return self::budgetRole($pdo, $userId, $tenantId) === 'tenant_admin';
    }

    public static function ocRole(PDO $pdo, int $userId, int $tenantId): ?string
    {
        $st = $pdo->prepare(
            "SELECT role FROM user_tenant_roles WHERE user_id = ? AND tenant_id = ? AND app = 'oc' LIMIT 1"
        );
        $st->execute([$userId, $tenantId]);
        $role = $st->fetchColumn();

        return $role !== false ? (string) $role : null;
    }

    /** Plantilla impresion OC: solo admin de colegio / superadmin, no encargado OC. */
    public static function canManageOcPrintTemplate(PDO $pdo, int $userId, int $tenantId): bool
    {
        if (Auth::isSuperadminUser($pdo, $userId) || Auth::isSuperadmin()) {
            return true;
        }
        if (self::isBudgetTenantAdmin($pdo, $userId, $tenantId)) {
            return true;
        }
        $ocRole = self::ocRole($pdo, $userId, $tenantId);

        return in_array($ocRole, ['tenant_admin', 'admin'], true);
    }

    /**
     * IDs de departments asignados al encargado. null = sin restriccion (admin).
     *
     * @return list<int>|null
     */
    public static function allowedDepartmentIds(PDO $pdo, int $userId, int $tenantId): ?array
    {
        $cacheKey = $userId . ':' . $tenantId;
        if (array_key_exists($cacheKey, self::$allowedDepartmentIdsCache)) {
            return self::$allowedDepartmentIdsCache[$cacheKey];
        }
        if (Auth::isSuperadminUser($pdo, $userId) || Auth::isSuperadmin()) {
            self::$allowedDepartmentIdsCache[$cacheKey] = null;

            return null;
        }
        if (self::isBudgetTenantAdmin($pdo, $userId, $tenantId)) {
            self::$allowedDepartmentIdsCache[$cacheKey] = null;

            return null;
        }
        $st = $pdo->prepare(
            'SELECT department_id FROM user_department_permissions WHERE user_id = ? AND tenant_id = ?'
        );
        $st->execute([$userId, $tenantId]);
        $ids = [];
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $ids[] = (int) $row['department_id'];
        }
        self::$allowedDepartmentIdsCache[$cacheKey] = $ids;

        return $ids;
    }

    /**
     * Departments visibles para encargado (WHERE id IN). Admin: catalogo completo.
     *
     * @return list<array{id: int, name: string, type: string, parent_id: int|null}>
     */
    public static function listAccessibleDepartments(PDO $pdo, int $userId, int $tenantId): array
    {
        $allowed = self::allowedDepartmentIds($pdo, $userId, $tenantId);
        if ($allowed === null) {
            return DepartmentCatalog::listByTenant($pdo, $tenantId);
        }
        if ($allowed === []) {
            return [];
        }

        return DepartmentCatalog::listByIds($pdo, $tenantId, $allowed);
    }

    public static function canUseDepartment(PDO $pdo, int $userId, int $tenantId, int $departmentId): bool
    {
        if ($departmentId <= 0) {
            return true;
        }
        $allowed = self::allowedDepartmentIds($pdo, $userId, $tenantId);
        if ($allowed === null) {
            return true;
        }

        return in_array($departmentId, $allowed, true);
    }

    /**
     * Departamento al que aplica la politica de edicion para una actividad.
     *
     * @param array{created_by?: int|null, department_id?: int|null, division_id?: int|null} $headerRow
     */
    public static function resolvePolicyDepartmentId(array $headerRow): int
    {
        $createdBy = (int) ($headerRow['created_by'] ?? 0);
        if ($createdBy > 0) {
            return $createdBy;
        }
        $deptId = (int) ($headerRow['department_id'] ?? 0);
        if ($deptId > 0) {
            return $deptId;
        }

        return (int) ($headerRow['division_id'] ?? 0);
    }

    public static function canEditActivitiesForDepartment(
        PDO $pdo,
        int $userId,
        int $tenantId,
        int $departmentId,
        int $year,
    ): bool {
        if ($departmentId <= 0) {
            return false;
        }
        if (Auth::isSuperadmin() || Auth::isSuperadminUser($pdo, $userId) || self::isBudgetTenantAdmin($pdo, $userId, $tenantId)) {
            return true;
        }
        if (!self::canUseDepartment($pdo, $userId, $tenantId, $departmentId)) {
            return false;
        }

        return self::departmentEditPolicy($pdo, $tenantId, $departmentId, $year) === 1;
    }

    /**
     * Lectura de actividad: encargado si tiene permiso sobre la coordinacion creadora o si la actividad
     * aparece en el ambito de una division asignada (CC que imputan a la division o carga propia).
     * Admin de colegio y superadmin ven todas (imputacion OC sigue siendo transversal en modulo OC).
     *
     * @param array{created_by?: int|null, department_id?: int|null, division_id?: int|null, cost_center_id?: int|null} $headerRow
     */
    public static function canViewActivityHeader(PDO $pdo, int $userId, int $tenantId, array $headerRow): bool
    {
        if (self::isBudgetTenantAdmin($pdo, $userId, $tenantId)) {
            return true;
        }
        $deptId = self::resolvePolicyDepartmentId($headerRow);
        if (self::canUseDepartment($pdo, $userId, $tenantId, $deptId)) {
            return true;
        }
        $allowed = self::allowedDepartmentIds($pdo, $userId, $tenantId);
        if ($allowed === null || $allowed === []) {
            return false;
        }
        foreach (DepartmentCatalog::listByIds($pdo, $tenantId, $allowed) as $assignedDept) {
            if (($assignedDept['type'] ?? '') !== 'estandar') {
                continue;
            }
            if (self::activityVisibleToDivisionEncargado($pdo, $tenantId, $headerRow, (int) $assignedDept['id'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Misma visibilidad que el listado del dashboard (vistas by_centros_costo y by_division).
     *
     * @param array{created_by?: int|null, department_id?: int|null, division_id?: int|null, cost_center_id?: int|null} $headerRow
     */
    private static function activityVisibleToDivisionEncargado(
        PDO $pdo,
        int $tenantId,
        array $headerRow,
        int $divisionDeptId,
    ): bool {
        return self::activityInDivisionByDivisionView($pdo, $tenantId, $headerRow, $divisionDeptId)
            || self::activityInDivisionByCentrosCostoView($pdo, $tenantId, $headerRow, $divisionDeptId);
    }

    /**
     * @param array{created_by?: int|null, department_id?: int|null, division_id?: int|null} $headerRow
     */
    private static function activityInDivisionByDivisionView(
        PDO $pdo,
        int $tenantId,
        array $headerRow,
        int $divisionDeptId,
    ): bool {
        $createdBy = (int) ($headerRow['created_by'] ?? 0);
        $departmentId = (int) ($headerRow['department_id'] ?? 0);
        $divisionId = self::headerNullableInt($headerRow, 'division_id');

        if (BudgetService::hasCreatedByColumn($pdo)) {
            if ($createdBy === $divisionDeptId) {
                $creator = DepartmentCatalog::getById($pdo, $createdBy, $tenantId);

                return $creator !== null && ($creator['type'] ?? '') === 'estandar';
            }

            return $createdBy === 0 && $divisionId === $divisionDeptId && $departmentId === 0;
        }

        return $divisionId === $divisionDeptId && $departmentId === 0;
    }

    /**
     * @param array{created_by?: int|null, department_id?: int|null, division_id?: int|null, cost_center_id?: int|null} $headerRow
     */
    private static function activityInDivisionByCentrosCostoView(
        PDO $pdo,
        int $tenantId,
        array $headerRow,
        int $divisionDeptId,
    ): bool {
        if (self::headerUsesCatalogCostCenter($pdo, $tenantId, self::headerNullableInt($headerRow, 'cost_center_id'))) {
            return false;
        }
        if (self::activityInDivisionByDivisionView($pdo, $tenantId, $headerRow, $divisionDeptId)) {
            return false;
        }

        $createdBy = (int) ($headerRow['created_by'] ?? 0);
        $departmentId = (int) ($headerRow['department_id'] ?? 0);
        $divisionId = self::headerNullableInt($headerRow, 'division_id');
        $creator = $createdBy > 0 ? DepartmentCatalog::getById($pdo, $createdBy, $tenantId) : null;
        $department = $departmentId > 0 ? DepartmentCatalog::getById($pdo, $departmentId, $tenantId) : null;

        if (BudgetService::hasCreatedByColumn($pdo)) {
            if ($creator !== null && ($creator['type'] ?? '') === 'coordinacion') {
                if ($divisionId === $divisionDeptId) {
                    return true;
                }
                if ($divisionId === null && (int) ($creator['parent_id'] ?? 0) === $divisionDeptId) {
                    return true;
                }
            }
            if (
                $divisionId === null
                && $department !== null
                && ($department['type'] ?? '') === 'coordinacion'
                && (int) ($department['parent_id'] ?? 0) === $divisionDeptId
                && ($creator === null || ($creator['type'] ?? '') === 'coordinacion')
            ) {
                return true;
            }

            return false;
        }

        if (
            $department !== null
            && ($department['type'] ?? '') === 'coordinacion'
            && (
                $divisionId === $divisionDeptId
                || (int) ($department['parent_id'] ?? 0) === $divisionDeptId
            )
        ) {
            return true;
        }

        return false;
    }

    private static function headerUsesCatalogCostCenter(PDO $pdo, int $tenantId, ?int $costCenterId): bool
    {
        if ($costCenterId === null || $costCenterId <= 0) {
            return false;
        }

        return CostCenterCatalog::getById($pdo, $costCenterId, $tenantId) !== null;
    }

    /** @param array<string, mixed> $headerRow */
    private static function headerNullableInt(array $headerRow, string $key): ?int
    {
        if (!array_key_exists($key, $headerRow) || $headerRow[$key] === null || $headerRow[$key] === '') {
            return null;
        }

        return (int) $headerRow[$key];
    }

    /**
     * @param array{created_by?: int|null, department_id?: int|null, division_id?: int|null, activity_number?: string} $headerRow
     */
    public static function canEditActivityHeader(
        PDO $pdo,
        int $userId,
        int $tenantId,
        array $headerRow,
        ?int $year = null,
    ): bool {
        if ($year === null) {
            $year = budget_year_from_activity_number((string) ($headerRow['activity_number'] ?? ''));
        }
        $deptId = self::resolvePolicyDepartmentId($headerRow);

        return self::canEditActivitiesForDepartment($pdo, $userId, $tenantId, $deptId, $year);
    }

    public static function departmentEditPolicy(PDO $pdo, int $tenantId, int $departmentId, ?int $year = null): int
    {
        return self::departmentEditPoliciesBatch($pdo, $tenantId, [$departmentId], $year)[$departmentId] ?? 0;
    }

    /**
     * @param list<int> $departmentIds
     * @return array<int, int> department_id => allow_edit (0|1)
     */
    public static function departmentEditPoliciesBatch(
        PDO $pdo,
        int $tenantId,
        array $departmentIds,
        ?int $year = null,
    ): array {
        $year = $year ?? (int) date('Y');
        $departmentIds = array_values(array_unique(array_filter(array_map('intval', $departmentIds))));
        if ($departmentIds === []) {
            return [];
        }
        $yCal = (int) date('Y');
        $default = $year < $yCal ? 0 : ($year === $yCal ? 1 : 0);
        $ph = implode(',', array_fill(0, count($departmentIds), '?'));
        $st = $pdo->prepare(
            "SELECT department_id, allow_edit FROM tenant_department_edit_policy
             WHERE tenant_id = ? AND year = ? AND department_id IN ({$ph})"
        );
        $st->execute(array_merge([$tenantId, $year], $departmentIds));
        $explicit = [];
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $explicit[(int) $row['department_id']] = (int) $row['allow_edit'];
        }
        $out = [];
        foreach ($departmentIds as $did) {
            $out[$did] = $explicit[$did] ?? $default;
        }

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $headerRows
     * @return array<int, bool> header_id => puede editar
     */
    public static function canEditFlagsForHeaders(
        PDO $pdo,
        int $userId,
        int $tenantId,
        array $headerRows,
        int $year,
    ): array {
        if ($headerRows === []) {
            return [];
        }
        if (Auth::isSuperadmin() || Auth::isSuperadminUser($pdo, $userId) || self::isBudgetTenantAdmin($pdo, $userId, $tenantId)) {
            $out = [];
            foreach ($headerRows as $row) {
                $out[(int) $row['id']] = true;
            }

            return $out;
        }
        $allowed = self::allowedDepartmentIds($pdo, $userId, $tenantId);
        $deptIds = [];
        foreach ($headerRows as $row) {
            $deptIds[] = self::resolvePolicyDepartmentId($row);
        }
        $policies = self::departmentEditPoliciesBatch($pdo, $tenantId, $deptIds, $year);
        $out = [];
        foreach ($headerRows as $row) {
            $hid = (int) $row['id'];
            $deptId = self::resolvePolicyDepartmentId($row);
            if ($deptId <= 0) {
                $out[$hid] = false;
                continue;
            }
            if ($allowed !== null && !in_array($deptId, $allowed, true)) {
                $out[$hid] = false;
                continue;
            }
            $out[$hid] = ($policies[$deptId] ?? 0) === 1;
        }

        return $out;
    }

    /** @param list<int> $departmentIds */
    public static function setDepartmentPermissions(PDO $pdo, int $userId, int $tenantId, array $departmentIds): void
    {
        $pdo->prepare('DELETE FROM user_department_permissions WHERE user_id = ? AND tenant_id = ?')
            ->execute([$userId, $tenantId]);
        $ins = $pdo->prepare(
            'INSERT INTO user_department_permissions (user_id, tenant_id, department_id, can_edit) VALUES (?, ?, ?, ?)'
        );
        $year = (int) date('Y');
        foreach ($departmentIds as $did) {
            $did = (int) $did;
            if ($did <= 0) {
                continue;
            }
            $canEdit = self::departmentEditPolicy($pdo, $tenantId, $did, $year);
            $ins->execute([$userId, $tenantId, $did, $canEdit]);
        }
    }

    /**
     * @return list<array{id: int, name: string, type: string}>
     */
    public static function listDepartmentsForTenant(PDO $pdo, int $tenantId): array
    {
        return DepartmentCatalog::listByTenant($pdo, $tenantId);
    }

    /**
     * @param list<int>|null $allowedIds
     * @return list<array{id: int, name: string, type: string}>
     */
    public static function filterDepartments(array $departments, ?array $allowedIds): array
    {
        if ($allowedIds === null) {
            return $departments;
        }
        if ($allowedIds === []) {
            return [];
        }

        return array_values(array_filter(
            $departments,
            static fn (array $d): bool => in_array((int) $d['id'], $allowedIds, true)
        ));
    }

    /** @return array<int, list<int>> tenant_id => department ids */
    public static function assignedByUser(PDO $pdo, int $userId): array
    {
        $st = $pdo->prepare(
            'SELECT tenant_id, department_id FROM user_department_permissions WHERE user_id = ?'
        );
        $st->execute([$userId]);
        $out = [];
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $tid = (int) $row['tenant_id'];
            $out[$tid][] = (int) $row['department_id'];
        }

        return $out;
    }
}
