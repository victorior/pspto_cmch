<?php

declare(strict_types=1);

/** Permisos globales por tenant / division(coordinacion) / ano. */
final class EditPolicyService
{
    /** @return list<int> */
    public static function selectableYears(): array
    {
        $y = (int) date('Y');

        return [$y - 1, $y, $y + 1];
    }

    public static function yearLabel(int $year): string
    {
        $y = (int) date('Y');
        if ($year === $y) {
            return $year . ' (en curso)';
        }

        return (string) $year;
    }

    /**
     * Crea filas faltantes con valor por defecto (misma logica que UserPermissions::departmentEditPolicy).
     */
    public static function ensureRowsForYear(PDO $pdo, int $tenantId, int $year): void
    {
        $st = $pdo->prepare('SELECT id FROM departments WHERE tenant_id = ?');
        $st->execute([$tenantId]);
        $ins = $pdo->prepare(
            'INSERT IGNORE INTO tenant_department_edit_policy (tenant_id, department_id, year, allow_edit)
             VALUES (?, ?, ?, ?)'
        );
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $deptId = (int) $row['id'];
            $default = UserPermissions::departmentEditPolicy($pdo, $tenantId, $deptId, $year);
            $ins->execute([$tenantId, $deptId, $year, $default]);
        }
    }

    /**
     * @return list<array{id: int, name: string, type: string, allow_edit: int}>
     */
    public static function listForTenantYear(PDO $pdo, int $tenantId, int $year): array
    {
        self::ensureRowsForYear($pdo, $tenantId, $year);
        $st = $pdo->prepare(
            'SELECT d.id, d.name, d.type, p.allow_edit
             FROM departments d
             LEFT JOIN tenant_department_edit_policy p
               ON p.department_id = d.id AND p.tenant_id = d.tenant_id AND p.year = ?
             WHERE d.tenant_id = ?
             ORDER BY d.type, d.name'
        );
        $st->execute([$year, $tenantId]);
        $rows = [];
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $rows[] = [
                'id' => (int) $row['id'],
                'name' => (string) $row['name'],
                'type' => (string) $row['type'],
                'allow_edit' => (int) ($row['allow_edit'] ?? 0),
            ];
        }

        return $rows;
    }

    /** @param array<int, int> $allowByDepartment department_id => 0|1 */
    public static function savePolicies(PDO $pdo, int $tenantId, int $year, array $allowByDepartment): void
    {
        $st = $pdo->prepare(
            'INSERT INTO tenant_department_edit_policy (tenant_id, department_id, year, allow_edit)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE allow_edit = VALUES(allow_edit)'
        );
        foreach ($allowByDepartment as $deptId => $allow) {
            $deptId = (int) $deptId;
            if ($deptId <= 0) {
                continue;
            }
            $chk = $pdo->prepare('SELECT 1 FROM departments WHERE id = ? AND tenant_id = ? LIMIT 1');
            $chk->execute([$deptId, $tenantId]);
            if (!$chk->fetchColumn()) {
                continue;
            }
            $st->execute([$tenantId, $deptId, $year, (int) $allow === 1 ? 1 : 0]);
        }
    }

    public static function setAllForTenantYear(PDO $pdo, int $tenantId, int $year, bool $allowEdit): void
    {
        self::ensureRowsForYear($pdo, $tenantId, $year);
        $val = $allowEdit ? 1 : 0;
        $pdo->prepare(
            'UPDATE tenant_department_edit_policy SET allow_edit = ?
             WHERE tenant_id = ? AND year = ?'
        )->execute([$val, $tenantId, $year]);
    }

    public static function departmentTypeLabel(string $type): string
    {
        return $type === 'coordinacion' ? 'coordinacion' : 'estandar';
    }
}
