<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/database/sql_import_helpers.php';

/** Importa usuarios, roles y permisos desde cna14865_budgetflow.sql */
final class LegacyUserImportService
{
    /**
     * @return array{users_created: int, users_updated: int, roles: int, depts: int, superadmins: int, skipped: int, errors: list<string>}
     */
    public static function importFromDump(
        PDO $pdo,
        string $dumpPath,
        string $defaultPassword,
        ?int $onlyLegacyTenantId = null,
    ): array {
        if (!is_file($dumpPath)) {
            throw new InvalidArgumentException('No se encuentra el archivo dump: ' . $dumpPath);
        }
        if (strlen($defaultPassword) < 8) {
            throw new InvalidArgumentException('La contrasena por defecto debe tener al menos 8 caracteres.');
        }

        $dump = file_get_contents($dumpPath);
        if ($dump === false) {
            throw new RuntimeException('No se pudo leer el dump.');
        }

        $stats = [
            'users_created' => 0,
            'users_updated' => 0,
            'roles' => 0,
            'depts' => 0,
            'superadmins' => 0,
            'skipped' => 0,
            'errors' => [],
        ];

        $tenantMap = bud_resolve_tenant_map($pdo);
        if ($onlyLegacyTenantId !== null && !isset($tenantMap[$onlyLegacyTenantId])) {
            throw new InvalidArgumentException('Tenant legacy no reconocido: ' . $onlyLegacyTenantId);
        }

        $deptMap = self::buildDepartmentIdMap($pdo, $dump, $tenantMap, $onlyLegacyTenantId);
        $emailToUserId = self::loadExistingUsersByEmail($pdo);
        $defaultHash = password_hash($defaultPassword, PASSWORD_DEFAULT);

        $pdo->beginTransaction();
        try {
            foreach (bud_sql_extract_insert_tuples($dump, 'users') as $tuple) {
                $f = bud_sql_parse_tuple($tuple);
                if (count($f) < 2) {
                    continue;
                }
                $email = strtolower(trim((string) ($f[1] ?? '')));
                if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $stats['skipped']++;
                    continue;
                }
                $fullName = trim((string) ($f[2] ?? ''));
                if (!isset($emailToUserId[$email])) {
                    $pdo->prepare(
                        'INSERT INTO users (email, password_hash, full_name, active) VALUES (?, ?, ?, 1)'
                    )->execute([$email, $defaultHash, $fullName !== '' ? $fullName : $email]);
                    $emailToUserId[$email] = (int) $pdo->lastInsertId();
                    $stats['users_created']++;
                } else {
                    $pdo->prepare('UPDATE users SET full_name = ? WHERE id = ? AND full_name = \'\'')
                        ->execute([$fullName !== '' ? $fullName : $email, $emailToUserId[$email]]);
                    $stats['users_updated']++;
                }
            }

            $insRole = $pdo->prepare(
                'INSERT IGNORE INTO user_tenant_roles (user_id, tenant_id, app, role) VALUES (?, ?, \'budget\', ?)'
            );
            foreach (bud_sql_extract_insert_tuples($dump, 'user_tenant_roles') as $tuple) {
                $f = bud_sql_parse_tuple($tuple);
                if (count($f) < 3) {
                    continue;
                }
                $legacyUserId = (int) ($f[1] ?? 0);
                $legacyTenantId = (int) ($f[2] ?? 0);
                if ($onlyLegacyTenantId !== null && $legacyTenantId !== $onlyLegacyTenantId) {
                    continue;
                }
                if (!isset($tenantMap[$legacyTenantId])) {
                    continue;
                }
                $email = self::legacyUserEmailById($dump, $legacyUserId);
                if ($email === null || !isset($emailToUserId[$email])) {
                    $stats['skipped']++;
                    continue;
                }
                $role = (string) ($f[3] ?? 'encargado');
                if (!in_array($role, ['encargado', 'tenant_admin'], true)) {
                    $role = 'encargado';
                }
                $insRole->execute([$emailToUserId[$email], $tenantMap[$legacyTenantId], $role]);
                if ($insRole->rowCount() > 0) {
                    $stats['roles']++;
                }
            }

            $insDept = $pdo->prepare(
                'INSERT IGNORE INTO user_department_permissions (user_id, tenant_id, department_id, can_edit)
                 VALUES (?, ?, ?, ?)'
            );
            foreach (bud_sql_extract_insert_tuples($dump, 'user_department_permissions') as $tuple) {
                $f = bud_sql_parse_tuple($tuple);
                if (count($f) < 4) {
                    continue;
                }
                $legacyUserId = (int) ($f[1] ?? 0);
                $legacyTenantId = (int) ($f[2] ?? 0);
                $legacyDeptId = (int) ($f[3] ?? 0);
                if ($onlyLegacyTenantId !== null && $legacyTenantId !== $onlyLegacyTenantId) {
                    continue;
                }
                if (!isset($tenantMap[$legacyTenantId], $deptMap[$legacyDeptId])) {
                    continue;
                }
                $email = self::legacyUserEmailById($dump, $legacyUserId);
                if ($email === null || !isset($emailToUserId[$email])) {
                    continue;
                }
                $canEdit = (int) ($f[4] ?? 1);
                $insDept->execute([
                    $emailToUserId[$email],
                    $tenantMap[$legacyTenantId],
                    $deptMap[$legacyDeptId],
                    $canEdit ? 1 : 0,
                ]);
                if ($insDept->rowCount() > 0) {
                    $stats['depts']++;
                }
            }

            $stSa = $pdo->prepare('SELECT 1 FROM super_admins WHERE user_id = ? LIMIT 1');
            $insSa = $pdo->prepare('INSERT INTO super_admins (user_id) VALUES (?)');
            foreach (bud_sql_extract_insert_tuples($dump, 'super_admins') as $tuple) {
                $f = bud_sql_parse_tuple($tuple);
                if ($f === []) {
                    continue;
                }
                $legacyUserId = (int) ($f[0] ?? 0);
                $email = self::legacyUserEmailById($dump, $legacyUserId);
                if ($email === null || !isset($emailToUserId[$email])) {
                    continue;
                }
                $newUid = $emailToUserId[$email];
                $stSa->execute([$newUid]);
                if (!$stSa->fetchColumn()) {
                    $insSa->execute([$newUid]);
                    $stats['superadmins']++;
                }
            }

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        return $stats;
    }

    /** @return array<string, int> email => user id */
    private static function loadExistingUsersByEmail(PDO $pdo): array
    {
        $map = [];
        $st = $pdo->query('SELECT id, email FROM users');
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $map[strtolower((string) $row['email'])] = (int) $row['id'];
        }

        return $map;
    }

    /**
     * @param array<int, int> $tenantMap
     * @return array<int, int> legacy dept id => new dept id
     */
    private static function buildDepartmentIdMap(
        PDO $pdo,
        string $dump,
        array $tenantMap,
        ?int $onlyLegacyTenantId,
    ): array {
        $map = [];
        foreach (bud_sql_extract_insert_tuples($dump, 'departments') as $tuple) {
            $f = bud_sql_parse_tuple($tuple);
            if (count($f) < 3) {
                continue;
            }
            $legacyDeptId = (int) ($f[0] ?? 0);
            $legacyTenantId = (int) ($f[1] ?? 0);
            $name = (string) ($f[2] ?? '');
            if ($onlyLegacyTenantId !== null && $legacyTenantId !== $onlyLegacyTenantId) {
                continue;
            }
            if ($name === '' || !isset($tenantMap[$legacyTenantId])) {
                continue;
            }
            $st = $pdo->prepare(
                'SELECT id FROM departments WHERE tenant_id = ? AND name = ? LIMIT 1'
            );
            $st->execute([$tenantMap[$legacyTenantId], $name]);
            $newId = $st->fetchColumn();
            if ($newId !== false) {
                $map[$legacyDeptId] = (int) $newId;
            }
        }

        return $map;
    }

    private static function legacyUserEmailById(string $dump, int $legacyUserId): ?string
    {
        static $cache = null;
        if ($cache === null) {
            $cache = [];
            foreach (bud_sql_extract_insert_tuples($dump, 'users') as $tuple) {
                $f = bud_sql_parse_tuple($tuple);
                if (count($f) < 2) {
                    continue;
                }
                $cache[(int) ($f[0] ?? 0)] = strtolower(trim((string) $f[1]));
            }
        }
        $email = $cache[$legacyUserId] ?? null;

        return ($email !== null && $email !== '') ? $email : null;
    }

    /** @return array<int, int> legacy tenant id => new tenant id */
    public static function legacyTenantIdBySlug(PDO $pdo): array
    {
        $out = [];
        $map = bud_resolve_tenant_map($pdo);
        foreach (bud_legacy_source_tenants() as $oldId => $meta) {
            if (isset($map[$oldId])) {
                $out[$oldId] = $map[$oldId];
            }
        }

        return $out;
    }

    public static function legacyTenantIdForSlug(string $slug): ?int
    {
        foreach (bud_legacy_source_tenants() as $oldId => $meta) {
            if ($meta['slug'] === $slug) {
                return $oldId;
            }
        }

        return null;
    }
}
