<?php

declare(strict_types=1);

/**
 * Catalogo cost_centers (centros de costo contables) por tenant.
 *
 * Parte del modelo dual: tabla independiente de departments, referenciada por
 * budget_headers.cost_center_id cuando una division crea la actividad.
 * Algunos nombres coinciden con coordinaciones receive-only en departments;
 * la relacion es por nombre/reglas (BudgetActivityRules), no por FK.
 *
 * Cache por request.
 */
final class CostCenterCatalog
{
    /** @var array<int, list<array{id: int, name: string}>> */
    private static array $tenantList = [];

    /** @var array<int, array<int, array{id: int, name: string}>> */
    private static array $tenantById = [];

    private static function loadTenant(PDO $pdo, int $tenantId): void
    {
        if (isset(self::$tenantList[$tenantId])) {
            return;
        }
        $st = $pdo->prepare('SELECT id, name FROM cost_centers WHERE tenant_id = ? ORDER BY name');
        $st->execute([$tenantId]);
        $list = [];
        $byId = [];
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $norm = [
                'id' => (int) $row['id'],
                'name' => (string) $row['name'],
            ];
            $list[] = $norm;
            $byId[$norm['id']] = $norm;
        }
        self::$tenantList[$tenantId] = $list;
        self::$tenantById[$tenantId] = $byId;
    }

    /**
     * @return array{id: int, name: string}|null
     */
    public static function getById(PDO $pdo, int $id, int $tenantId): ?array
    {
        if ($id <= 0) {
            return null;
        }
        self::loadTenant($pdo, $tenantId);

        return self::$tenantById[$tenantId][$id] ?? null;
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    public static function listByTenant(PDO $pdo, int $tenantId): array
    {
        self::loadTenant($pdo, $tenantId);

        return self::$tenantList[$tenantId];
    }
}
