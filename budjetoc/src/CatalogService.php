<?php

declare(strict_types=1);

/**
 * Catalogo por tenant (filas independientes) con replicacion del maestro a todos.
 * Cada tenant conserva sus propios id para presupuesto/OC; se sincronizan altas por nombre/codigo.
 */
final class CatalogService
{
    /** @return list<int> */
    public static function tenantIds(PDO $pdo, bool $activeOnly = true): array
    {
        $sql = 'SELECT id FROM tenants';
        if ($activeOnly) {
            $sql .= ' WHERE active = 1';
        }
        $sql .= ' ORDER BY id';

        return array_map('intval', $pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN));
    }

    public static function resolveMasterTenantId(PDO $pdo, ?int $preferredId = null): int
    {
        if ($preferredId !== null && $preferredId > 0) {
            $st = $pdo->prepare('SELECT id FROM tenants WHERE id = ? LIMIT 1');
            $st->execute([$preferredId]);

            if ($st->fetchColumn()) {
                return $preferredId;
            }
        }

        global $CONFIG;
        $slug = is_array($CONFIG ?? null) ? (string) ($CONFIG['catalog_master_tenant_slug'] ?? '') : '';
        if ($slug !== '') {
            $st = $pdo->prepare('SELECT id FROM tenants WHERE slug = ? LIMIT 1');
            $st->execute([$slug]);
            $id = $st->fetchColumn();
            if ($id !== false) {
                return (int) $id;
            }
        }

        $row = $pdo->query(
            'SELECT t.id FROM tenants t
             LEFT JOIN departments d ON d.tenant_id = t.id
             GROUP BY t.id
             ORDER BY COUNT(d.id) DESC, t.id ASC
             LIMIT 1'
        )->fetch(PDO::FETCH_ASSOC);

        return $row ? (int) $row['id'] : 1;
    }

    /**
     * Copia entradas faltantes del tenant origen hacia otros (solo INSERT, no borra ni actualiza).
     *
     * @param list<int>|null $targetTenantIds null = todos los demas tenants activos
     * @return array{added: array<string, int>, targets: int}
     */
    public static function replicateFromTenant(PDO $pdo, int $sourceTenantId, ?array $targetTenantIds = null): array
    {
        $sourceTenantId = max(1, $sourceTenantId);
        if ($targetTenantIds === null) {
            $targetTenantIds = array_values(array_filter(
                self::tenantIds($pdo, true),
                static fn (int $id): bool => $id !== $sourceTenantId
            ));
        } else {
            $targetTenantIds = array_values(array_unique(array_map('intval', $targetTenantIds)));
            $targetTenantIds = array_values(array_filter(
                $targetTenantIds,
                static fn (int $id): bool => $id > 0 && $id !== $sourceTenantId
            ));
        }

        $added = [
            'cost_centers' => 0,
            'departments' => 0,
            'activities' => 0,
            'account_codes' => 0,
        ];

        if ($targetTenantIds === []) {
            return ['added' => $added, 'targets' => 0];
        }

        $pdo->beginTransaction();
        try {
            foreach ($targetTenantIds as $targetId) {
                $added['cost_centers'] += self::copyCostCenters($pdo, $sourceTenantId, $targetId);
                $added['departments'] += self::copyDepartments($pdo, $sourceTenantId, $targetId);
                $added['activities'] += self::copyActivities($pdo, $sourceTenantId, $targetId);
                $added['account_codes'] += self::copyAccountCodes($pdo, $sourceTenantId, $targetId);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        return ['added' => $added, 'targets' => count($targetTenantIds)];
    }

    public static function propagateCostCenter(PDO $pdo, int $sourceTenantId, string $name): void
    {
        $name = trim($name);
        if ($name === '') {
            return;
        }
        foreach (self::otherTenantIds($pdo, $sourceTenantId) as $targetId) {
            self::insertCostCenterIfMissing($pdo, $targetId, $name);
        }
    }

    public static function propagateDepartment(PDO $pdo, int $sourceTenantId, string $name, string $type): void
    {
        $name = trim($name);
        if ($name === '' || !in_array($type, ['estandar', 'coordinacion'], true)) {
            return;
        }
        foreach (self::otherTenantIds($pdo, $sourceTenantId) as $targetId) {
            self::insertDepartmentIfMissing($pdo, $targetId, $name, $type);
        }
    }

    public static function propagateActivity(PDO $pdo, int $sourceTenantId, string $name): void
    {
        $name = trim($name);
        if ($name === '') {
            return;
        }
        foreach (self::otherTenantIds($pdo, $sourceTenantId) as $targetId) {
            self::insertActivityIfMissing($pdo, $targetId, $name);
        }
    }

    public static function propagateAccountCode(PDO $pdo, int $sourceTenantId, int $accountId, string $name): void
    {
        if ($accountId <= 0) {
            return;
        }
        foreach (self::otherTenantIds($pdo, $sourceTenantId) as $targetId) {
            self::insertAccountCodeIfMissing($pdo, $targetId, $accountId, $name);
        }
    }

    /** @return list<int> */
    private static function otherTenantIds(PDO $pdo, int $sourceTenantId): array
    {
        return array_values(array_filter(
            self::tenantIds($pdo, true),
            static fn (int $id): bool => $id !== $sourceTenantId
        ));
    }

    private static function copyCostCenters(PDO $pdo, int $sourceTenantId, int $targetTenantId): int
    {
        $st = $pdo->prepare('SELECT name FROM cost_centers WHERE tenant_id = ?');
        $st->execute([$sourceTenantId]);
        $n = 0;
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            if (self::insertCostCenterIfMissing($pdo, $targetTenantId, (string) $row['name'])) {
                $n++;
            }
        }

        return $n;
    }

    private static function copyDepartments(PDO $pdo, int $sourceTenantId, int $targetTenantId): int
    {
        $st = $pdo->prepare('SELECT name, type FROM departments WHERE tenant_id = ?');
        $st->execute([$sourceTenantId]);
        $n = 0;
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            if (self::insertDepartmentIfMissing($pdo, $targetTenantId, (string) $row['name'], (string) $row['type'])) {
                $n++;
            }
        }

        return $n;
    }

    private static function copyActivities(PDO $pdo, int $sourceTenantId, int $targetTenantId): int
    {
        $st = $pdo->prepare('SELECT name FROM activities WHERE tenant_id = ?');
        $st->execute([$sourceTenantId]);
        $n = 0;
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            if (self::insertActivityIfMissing($pdo, $targetTenantId, (string) $row['name'])) {
                $n++;
            }
        }

        return $n;
    }

    private static function copyAccountCodes(PDO $pdo, int $sourceTenantId, int $targetTenantId): int
    {
        $st = $pdo->prepare('SELECT account_id, name FROM account_codes WHERE tenant_id = ?');
        $st->execute([$sourceTenantId]);
        $n = 0;
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            if (self::insertAccountCodeIfMissing($pdo, $targetTenantId, (int) $row['account_id'], (string) $row['name'])) {
                $n++;
            }
        }

        return $n;
    }

    private static function insertCostCenterIfMissing(PDO $pdo, int $tenantId, string $name): bool
    {
        if (self::costCenterExists($pdo, $tenantId, $name)) {
            return false;
        }
        $pdo->prepare('INSERT INTO cost_centers (tenant_id, name) VALUES (?, ?)')->execute([$tenantId, $name]);

        return true;
    }

    private static function insertDepartmentIfMissing(PDO $pdo, int $tenantId, string $name, string $type): bool
    {
        if (self::departmentExists($pdo, $tenantId, $name, $type)) {
            return false;
        }
        $pdo->prepare('INSERT INTO departments (tenant_id, name, type) VALUES (?, ?, ?)')
            ->execute([$tenantId, $name, $type]);

        return true;
    }

    private static function insertActivityIfMissing(PDO $pdo, int $tenantId, string $name): bool
    {
        if (self::activityExists($pdo, $tenantId, $name)) {
            return false;
        }
        $pdo->prepare('INSERT INTO activities (tenant_id, name) VALUES (?, ?)')->execute([$tenantId, $name]);

        return true;
    }

    private static function insertAccountCodeIfMissing(PDO $pdo, int $tenantId, int $accountId, string $name): bool
    {
        if (self::accountCodeExists($pdo, $tenantId, $accountId)) {
            return false;
        }
        try {
            $pdo->prepare('INSERT INTO account_codes (tenant_id, account_id, name) VALUES (?, ?, ?)')
                ->execute([$tenantId, $accountId, $name]);
        } catch (Throwable) {
            return false;
        }

        return true;
    }

    public static function costCenterExists(PDO $pdo, int $tenantId, string $name): bool
    {
        $st = $pdo->prepare(
            'SELECT 1 FROM cost_centers WHERE tenant_id = ? AND LOWER(TRIM(name)) = LOWER(TRIM(?)) LIMIT 1'
        );
        $st->execute([$tenantId, $name]);

        return (bool) $st->fetchColumn();
    }

    public static function departmentExists(PDO $pdo, int $tenantId, string $name, string $type): bool
    {
        $st = $pdo->prepare(
            'SELECT 1 FROM departments WHERE tenant_id = ? AND type = ? AND LOWER(TRIM(name)) = LOWER(TRIM(?)) LIMIT 1'
        );
        $st->execute([$tenantId, $type, $name]);

        return (bool) $st->fetchColumn();
    }

    public static function activityExists(PDO $pdo, int $tenantId, string $name): bool
    {
        $st = $pdo->prepare(
            'SELECT 1 FROM activities WHERE tenant_id = ? AND LOWER(TRIM(name)) = LOWER(TRIM(?)) LIMIT 1'
        );
        $st->execute([$tenantId, $name]);

        return (bool) $st->fetchColumn();
    }

    public static function accountCodeExists(PDO $pdo, int $tenantId, int $accountId): bool
    {
        $st = $pdo->prepare(
            'SELECT 1 FROM account_codes WHERE tenant_id = ? AND account_id = ? LIMIT 1'
        );
        $st->execute([$tenantId, $accountId]);

        return (bool) $st->fetchColumn();
    }

    /**
     * Opciones Nº Cta para OC: cuentas del tenant (account_codes), o plan_cuenta global si no hay filas.
     *
     * @return list<array{id:int, name:string}>
     */
    public static function listOcPlanCuentaOptions(PDO $pdo, int $tenantId): array
    {
        $st = $pdo->prepare(
            'SELECT id, account_id, name FROM account_codes WHERE tenant_id = ? ORDER BY account_id ASC'
        );
        $st->execute([$tenantId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        if ($rows !== []) {
            $out = [];
            foreach ($rows as $r) {
                $out[] = [
                    'id' => AccountCodeCatalog::displayAccountIdFromRow($pdo, $r),
                    'name' => trim((string) $r['name']),
                ];
            }

            return $out;
        }

        try {
            $legacy = $pdo->query('SELECT id, name FROM plan_cuenta ORDER BY name ASC')->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable) {
            return [];
        }

        $out = [];
        foreach ($legacy as $r) {
            $out[] = [
                'id' => (int) $r['id'],
                'name' => trim((string) $r['name']),
            ];
        }

        return $out;
    }
}
