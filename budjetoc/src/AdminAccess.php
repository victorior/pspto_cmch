<?php

declare(strict_types=1);

/** Acceso al panel admin: superadmin o admin de colegio (tenant_admin presupuesto). */
final class AdminAccess
{
    public static function isFullSuperadmin(): bool
    {
        return Auth::isSuperadmin();
    }

    public static function isTenantPolicyAdmin(): bool
    {
        return !empty($_SESSION['is_tenant_policy_admin']);
    }

    public static function canUseAdminPanel(PDO $pdo, int $userId): bool
    {
        if (Auth::isSuperadminUser($pdo, $userId)) {
            return true;
        }

        return self::tenantAdminTenantIds($pdo, $userId) !== [];
    }

    /** @return list<int> */
    public static function tenantAdminTenantIds(PDO $pdo, int $userId): array
    {
        $st = $pdo->prepare(
            "SELECT tenant_id FROM user_tenant_roles
             WHERE user_id = ? AND app = 'budget' AND role = 'tenant_admin'"
        );
        $st->execute([$userId]);
        $ids = [];
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $ids[] = (int) $row['tenant_id'];
        }

        return $ids;
    }

    /**
     * @return list<array{id: int, name: string, slug: string}>
     */
    public static function manageableTenants(PDO $pdo, int $userId): array
    {
        if (self::isFullSuperadmin()) {
            return $pdo->query(
                'SELECT id, name, slug FROM tenants WHERE active = 1 ORDER BY name'
            )->fetchAll(PDO::FETCH_ASSOC);
        }
        $ids = self::tenantAdminTenantIds($pdo, $userId);
        if ($ids === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $st = $pdo->prepare(
            "SELECT id, name, slug FROM tenants WHERE active = 1 AND id IN ({$placeholders}) ORDER BY name"
        );
        $st->execute($ids);

        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function canManageTenant(PDO $pdo, int $userId, int $tenantId): bool
    {
        if (Auth::isSuperadminUser($pdo, $userId)) {
            return true;
        }

        return in_array($tenantId, self::tenantAdminTenantIds($pdo, $userId), true);
    }

    /**
     * Crea o actualiza sesion admin desde Presupuesto u OC si hay sesion activa alli;
     * si no, reutiliza sesion admin existente.
     */
    public static function bootstrapAdminSessionFromPeerRealms(PDO $pdo): bool
    {
        Auth::startRealm(Auth::REALM_ADMIN);
        $adminUid = Auth::userId();
        if ($adminUid !== null && self::canUseAdminPanel($pdo, (int) $adminUid)) {
            self::syncAdminSessionFlags($pdo);
            Auth::commitSession();

            return true;
        }

        foreach ([Auth::REALM_BUDGET, Auth::REALM_OC] as $peerRealm) {
            Auth::startRealm($peerRealm);
            $peerUid = Auth::portalSessionUserId();
            if ($peerUid === null) {
                continue;
            }
            $peerRealmInSession = Auth::realm();
            if ($peerRealmInSession !== '' && $peerRealmInSession !== $peerRealm) {
                continue;
            }

            Auth::startRealm(Auth::REALM_ADMIN);
            if (
                Auth::userId() === $peerUid
                && Auth::realm() === Auth::REALM_ADMIN
                && self::canUseAdminPanel($pdo, $peerUid)
            ) {
                self::syncAdminSessionFlags($pdo);
                Auth::commitSession();

                return true;
            }

            if (Auth::isSuperadminUser($pdo, $peerUid)) {
                Auth::loginUser($peerUid, Auth::REALM_ADMIN, null, true);
                $_SESSION['is_tenant_policy_admin'] = false;
                Auth::commitSession();

                return true;
            }
            $tenantAdminIds = self::tenantAdminTenantIds($pdo, $peerUid);
            if ($tenantAdminIds !== []) {
                Auth::loginUser($peerUid, Auth::REALM_ADMIN, $tenantAdminIds[0], false);
                $_SESSION['is_tenant_policy_admin'] = true;
                Auth::commitSession();

                return true;
            }
        }

        Auth::startRealm(Auth::REALM_ADMIN);

        return false;
    }

    public static function redirectAfterAdminLogin(PDO $pdo): void
    {
        Auth::startRealm(Auth::REALM_ADMIN);
        self::syncAdminSessionFlags($pdo);
        redirect_to(self::isFullSuperadmin() ? 'admin/index.php' : 'admin/edit_permissions.php');
    }

    /** Alinea flags de sesion admin con la base de datos (evita menu superadmin obsoleto en cache). */
    public static function syncAdminSessionFlags(PDO $pdo): void
    {
        $uid = Auth::userId();
        if ($uid === null || Auth::realm() !== Auth::REALM_ADMIN) {
            return;
        }
        if (Auth::isSuperadminUser($pdo, (int) $uid)) {
            $_SESSION['is_superadmin'] = true;
            $_SESSION['is_tenant_policy_admin'] = false;

            return;
        }
        $_SESSION['is_superadmin'] = false;
        $_SESSION['is_tenant_policy_admin'] = self::tenantAdminTenantIds($pdo, (int) $uid) !== [];
    }

    public static function checkAdminPanel(PDO $pdo): void
    {
        if (!self::bootstrapAdminSessionFromPeerRealms($pdo)) {
            redirect_to('admin/login.php');
        }
        if (!self::canUseAdminPanel($pdo, (int) Auth::userId())) {
            Auth::logout();
            redirect_to('admin/login.php');
        }
        self::syncAdminSessionFlags($pdo);
    }

    public static function requireSuperadminSession(PDO $pdo): void
    {
        self::checkAdminPanel($pdo);
        if (!Auth::ensureAdminSuperadminSession($pdo)) {
            redirect_to('admin/login.php');
        }
    }

    public static function requireSuperadminOrRedirect(PDO $pdo): void
    {
        self::checkAdminPanel($pdo);
        if (Auth::ensureAdminSuperadminSession($pdo)) {
            return;
        }
        redirect_to('admin/edit_permissions.php');
    }

    /** @return list<array{href: string, label: string, active?: bool}> */
    public static function navItems(string $active = ''): array
    {
        if (self::isFullSuperadmin()) {
            return [
                ['href' => admin_href('index.php'), 'label' => 'Inicio', 'active' => $active === 'home'],
                ['href' => admin_href('tenants.php'), 'label' => 'Tenants', 'active' => $active === 'tenants'],
                ['href' => admin_href('users.php'), 'label' => 'Usuarios', 'active' => $active === 'users'],
                ['href' => admin_href('edit_permissions.php'), 'label' => 'Permisos de edicion', 'active' => $active === 'edit_permissions'],
                ['href' => admin_href('budget_activities_import.php'), 'label' => 'Carga actividades', 'active' => $active === 'budget_activities_import'],
            ];
        }

        return [
            ['href' => admin_href('users.php'), 'label' => 'Usuarios', 'active' => $active === 'users'],
            ['href' => admin_href('edit_permissions.php'), 'label' => 'Permisos de edicion', 'active' => $active === 'edit_permissions'],
        ];
    }

    public static function adminHomeHref(): string
    {
        return self::isFullSuperadmin() ? admin_href('index.php') : admin_href('edit_permissions.php');
    }
}
