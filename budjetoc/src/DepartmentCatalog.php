<?php

declare(strict_types=1);

/**
 * Catalogo departments (divisiones y coordinaciones) por tenant.
 *
 * Parte del modelo dual: permisos de encargado (user_department_permissions),
 * created_by en budget_headers y selector del dashboard. Para centros de costo
 * contables del catalogo plano usar CostCenterCatalog.
 *
 * Cache por request.
 */
final class DepartmentCatalog
{
    /** @var array<int, list<array{id: int, name: string, type: string, parent_id: int|null}>> */
    private static array $tenantList = [];

    /** @var array<int, array<int, array{id: int, name: string, type: string, parent_id: int|null}>> */
    private static array $tenantById = [];

    /** @var array<int, true> */
    private static array $tenantFullyLoaded = [];

    private static function isTenantFullyLoaded(int $tenantId): bool
    {
        return isset(self::$tenantFullyLoaded[$tenantId]);
    }

    private static function loadTenant(PDO $pdo, int $tenantId): void
    {
        if (self::isTenantFullyLoaded($tenantId)) {
            return;
        }
        $st = $pdo->prepare(
            'SELECT id, name, type, parent_id FROM departments WHERE tenant_id = ? ORDER BY type, name'
        );
        $st->execute([$tenantId]);
        $list = [];
        $byId = [];
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $norm = self::normalizeRow($row);
            $list[] = $norm;
            $byId[$norm['id']] = $norm;
        }
        self::$tenantList[$tenantId] = $list;
        self::$tenantById[$tenantId] = $byId;
        self::$tenantFullyLoaded[$tenantId] = true;
    }

    /**
     * @param list<array{id: int, name: string, type: string, parent_id: int|null}> $rows
     */
    private static function mergeRows(int $tenantId, array $rows): void
    {
        if (!isset(self::$tenantById[$tenantId])) {
            self::$tenantById[$tenantId] = [];
        }
        if (!isset(self::$tenantList[$tenantId])) {
            self::$tenantList[$tenantId] = [];
        }
        foreach ($rows as $norm) {
            if (!isset(self::$tenantById[$tenantId][$norm['id']])) {
                self::$tenantList[$tenantId][] = $norm;
            }
            self::$tenantById[$tenantId][$norm['id']] = $norm;
        }
    }

    /**
     * @param list<int> $ids
     * @return list<array{id: int, name: string, type: string, parent_id: int|null}>
     */
    public static function listByIds(PDO $pdo, int $tenantId, array $ids): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $ids),
            static fn (int $id): bool => $id > 0
        )));
        if ($ids === []) {
            return [];
        }
        if (self::isTenantFullyLoaded($tenantId)) {
            return array_values(array_filter(
                self::$tenantList[$tenantId],
                static fn (array $d): bool => in_array($d['id'], $ids, true)
            ));
        }

        $missing = array_values(array_filter(
            $ids,
            static fn (int $id): bool => !isset(self::$tenantById[$tenantId][$id])
        ));
        if ($missing !== []) {
            $ph = implode(',', array_fill(0, count($missing), '?'));
            $st = $pdo->prepare(
                "SELECT id, name, type, parent_id FROM departments
                 WHERE tenant_id = ? AND id IN ({$ph})
                 ORDER BY type, name"
            );
            $st->execute(array_merge([$tenantId], $missing));
            $rows = [];
            while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                $rows[] = self::normalizeRow($row);
            }
            self::mergeRows($tenantId, $rows);
        }

        $out = [];
        foreach ($ids as $id) {
            if (isset(self::$tenantById[$tenantId][$id])) {
                $out[] = self::$tenantById[$tenantId][$id];
            }
        }
        usort(
            $out,
            static function (array $a, array $b): int {
                $typeCmp = strcasecmp((string) $a['type'], (string) $b['type']);

                return $typeCmp !== 0 ? $typeCmp : strcasecmp((string) $a['name'], (string) $b['name']);
            }
        );

        return $out;
    }

    /**
     * @return array{id: int, name: string, type: string, parent_id: int|null}|null
     */
    public static function getById(PDO $pdo, int $id, int $tenantId): ?array
    {
        if ($id <= 0) {
            return null;
        }
        if (self::isTenantFullyLoaded($tenantId)) {
            return self::$tenantById[$tenantId][$id] ?? null;
        }
        if (isset(self::$tenantById[$tenantId][$id])) {
            return self::$tenantById[$tenantId][$id];
        }

        return self::listByIds($pdo, $tenantId, [$id])[0] ?? null;
    }

    /**
     * @return list<array{id: int, name: string, type: string, parent_id: int|null}>
     */
    public static function listByTenant(PDO $pdo, int $tenantId): array
    {
        self::loadTenant($pdo, $tenantId);

        return self::$tenantList[$tenantId];
    }

    /**
     * Areas hijas (type estandar con parent_id = division).
     *
     * @return list<array{id: int, name: string, type: string, parent_id: int|null}>
     */
    public static function listAreasUnderDivision(PDO $pdo, int $tenantId, int $divisionId): array
    {
        if ($divisionId <= 0) {
            return [];
        }
        if (self::isTenantFullyLoaded($tenantId)) {
            $out = [];
            foreach (self::$tenantList[$tenantId] as $row) {
                if (($row['type'] ?? '') === 'estandar' && ($row['parent_id'] ?? null) === $divisionId) {
                    $out[] = $row;
                }
            }
            usort(
                $out,
                static fn (array $a, array $b): int => strcasecmp((string) $a['name'], (string) $b['name'])
            );

            return $out;
        }

        $st = $pdo->prepare(
            "SELECT id, name, type, parent_id FROM departments
             WHERE tenant_id = ? AND type = 'estandar' AND parent_id = ?
             ORDER BY name"
        );
        $st->execute([$tenantId, $divisionId]);
        $out = [];
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $out[] = self::normalizeRow($row);
        }
        self::mergeRows($tenantId, $out);

        return $out;
    }

    /**
     * @param array<string, mixed> $row
     * @return array{id: int, name: string, type: string, parent_id: int|null}
     */
    private static function normalizeRow(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'type' => (string) $row['type'],
            'parent_id' => isset($row['parent_id']) && $row['parent_id'] !== null
                ? (int) $row['parent_id']
                : null,
        ];
    }
}
