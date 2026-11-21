<?php

declare(strict_types=1);

/** Sesiones separadas: admin | budget | oc */
final class Auth
{
    public const REALM_ADMIN = 'admin';
    public const REALM_BUDGET = 'budget';
    public const REALM_OC = 'oc';

    public static function startRealm(string $realm): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        session_name('budjetoc_' . $realm);
        [$path, $altPath] = self::resolveSessionCookiePaths();

        self::applySessionCookieParams($path);
        session_start();

        if (self::sessionHasPortalUser()) {
            self::syncSessionRealm($realm);

            return;
        }

        if ($altPath === null || $altPath === $path) {
            return;
        }

        // No guardar sesión vacía en la ruta nueva (pisaba cookies válidas en /budjetoc/).
        if (function_exists('session_abort')) {
            session_abort();
        } else {
            session_write_close();
        }

        self::applySessionCookieParams($altPath);
        session_start();
        if (!self::sessionHasPortalUser()) {
            if (function_exists('session_abort')) {
                session_abort();
            } else {
                session_write_close();
            }
            self::applySessionCookieParams($path);
            session_start();

            return;
        }

        $payload = $_SESSION;
        session_write_close();
        self::applySessionCookieParams($path);
        session_start();
        $_SESSION = array_merge($_SESSION, $payload);
        self::syncSessionRealm($realm);
        self::expireSessionCookieAtPath($altPath);
    }

    private static function syncSessionRealm(string $realm): void
    {
        if (!self::sessionHasPortalUser()) {
            return;
        }
        if (!isset($_SESSION['realm']) || (string) $_SESSION['realm'] !== $realm) {
            $_SESSION['realm'] = $realm;
        }
    }

    public static function commitSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
    }

    /** @return array{0: string, 1: string|null} [ruta cookie principal, ruta alternativa a migrar] */
    private static function resolveSessionCookiePaths(): array
    {
        global $CONFIG;
        $path = '/';
        $altPath = null;
        if (is_array($CONFIG ?? null) && !empty($CONFIG['base_url'])) {
            $parsed = parse_url((string) $CONFIG['base_url'], PHP_URL_PATH);
            if (is_string($parsed) && $parsed !== '' && $parsed !== '/') {
                $path = str_ends_with($parsed, '/') ? $parsed : $parsed . '/';
                if (str_contains($path, '/public/')) {
                    $altPath = rtrim(dirname(rtrim($path, '/')), '/') . '/';
                }
            }
        }

        return [$path, $altPath];
    }

    private static function applySessionCookieParams(string $path): void
    {
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => $path,
            'httponly' => true,
            'samesite' => 'Lax',
            'secure' => self::sessionCookieSecure(),
        ]);
    }

    private static function expireSessionCookieAtPath(string $path): void
    {
        if (headers_sent()) {
            return;
        }
        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => $path,
            'httponly' => true,
            'samesite' => 'Lax',
            'secure' => self::sessionCookieSecure(),
        ]);
    }

    private static function sessionHasPortalUser(): bool
    {
        return self::sessionPayloadHasPortalUser($_SESSION);
    }

    /** @param array<string, mixed>|null $payload */
    private static function sessionPayloadHasPortalUser(?array $payload): bool
    {
        if ($payload === null) {
            return false;
        }
        if (isset($payload['user_id']) && is_numeric($payload['user_id'])) {
            return true;
        }

        return isset($payload['pick_user_id']) && is_numeric($payload['pick_user_id']);
    }

    public static function portalSessionUserId(): ?int
    {
        $u = self::userId();
        if ($u !== null) {
            return $u;
        }
        $pick = $_SESSION['pick_user_id'] ?? null;

        return is_numeric($pick) ? (int) $pick : null;
    }

    private static function sessionCookieSecure(): bool
    {
        global $CONFIG;
        if (is_array($CONFIG ?? null) && ($CONFIG['env'] ?? '') === 'local') {
            return false;
        }
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            return true;
        }

        return (($_SERVER['SERVER_PORT'] ?? '') === '443');
    }

    public static function hasValidAdminSession(): bool
    {
        return self::userId() !== null
            && self::realm() === self::REALM_ADMIN
            && self::isSuperadmin();
    }

    /** Repara sesion admin si falta is_superadmin pero el usuario esta en super_admins. */
    public static function ensureAdminSuperadminSession(PDO $pdo): bool
    {
        self::startRealm(self::REALM_ADMIN);
        $uid = self::userId();
        if ($uid === null || self::realm() !== self::REALM_ADMIN) {
            return false;
        }
        if (!self::isSuperadmin() && self::isSuperadminUser($pdo, $uid)) {
            $_SESSION['is_superadmin'] = true;
        }

        return self::hasValidAdminSession();
    }

    public static function userId(): ?int
    {
        $u = $_SESSION['user_id'] ?? null;

        return is_numeric($u) ? (int) $u : null;
    }

    public static function tenantId(): ?int
    {
        $t = $_SESSION['tenant_id'] ?? null;

        return is_numeric($t) ? (int) $t : null;
    }

    public static function isSuperadmin(): bool
    {
        return !empty($_SESSION['is_superadmin']);
    }

    public static function isSuperadminUser(PDO $pdo, int $userId): bool
    {
        $st = $pdo->prepare('SELECT 1 FROM super_admins WHERE user_id = ? LIMIT 1');
        $st->execute([$userId]);

        return (bool) $st->fetchColumn();
    }

    public static function realm(): string
    {
        return (string) ($_SESSION['realm'] ?? '');
    }

    public static function loginUser(int $userId, string $realm, ?int $tenantId, bool $superadmin = false): void
    {
        $sameUser = self::userId() === $userId
            && self::realm() === $realm
            && self::tenantId() === $tenantId
            && self::isSuperadmin() === $superadmin;
        if (!$sameUser && session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        $_SESSION['user_id'] = $userId;
        $_SESSION['realm'] = $realm;
        $_SESSION['tenant_id'] = $tenantId;
        $_SESSION['is_superadmin'] = $superadmin;
    }

    public static function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
    }

    public static function check(string $expectedRealm): void
    {
        if (self::userId() === null || self::realm() !== $expectedRealm) {
            header('Location: login.php');
            exit;
        }
        if ($expectedRealm !== self::REALM_ADMIN && self::tenantId() === null) {
            header('Location: login.php');
            exit;
        }
    }

    /** Presupuesto u OC: si falta sesion del portal, intenta reutilizar sesion admin (superadmin). */
    public static function checkPortal(PDO $pdo, string $realm): void
    {
        self::startRealm($realm);
        $app = $realm === self::REALM_BUDGET ? 'budget' : 'oc';
        if (self::userId() !== null && self::realm() === $realm && self::tenantId() !== null) {
            $uid = (int) self::userId();
            $tid = (int) self::tenantId();
            if (!self::hasTenantApp($pdo, $uid, $tid, $app)) {
                $tenants = self::tenantsForApp($pdo, $uid, $app);
                if ($tenants === []) {
                    redirect_to($app . '/login.php');
                }
                if (count($tenants) === 1) {
                    self::loginUser($uid, $realm, (int) $tenants[0]['id'], self::isSuperadmin());
                    self::commitSession();
                } else {
                    $_SESSION['pick_user_id'] = $uid;
                    $_SESSION['pick_tenants'] = $tenants;
                    $_SESSION['pick_is_superadmin'] = self::isSuperadmin();
                    self::commitSession();
                    redirect_to($app . '/pick_tenant.php');
                }
            }

            return;
        }
        if (!empty($_SESSION['pick_user_id']) && is_array($_SESSION['pick_tenants'] ?? null) && $_SESSION['pick_tenants'] !== []) {
            redirect_to(($realm === self::REALM_BUDGET ? 'budget' : 'oc') . '/pick_tenant.php');
        }
        if (self::bootstrapPortalSessionFromAdmin($pdo, $app)) {
            if (self::tenantId() !== null) {
                return;
            }
            if (!empty($_SESSION['pick_user_id']) && is_array($_SESSION['pick_tenants'] ?? null)) {
                redirect_to($app . '/pick_tenant.php');
            }
        }
        redirect_to($app . '/login.php');
    }

    public static function checkSuperadmin(): void
    {
        if (!self::hasValidAdminSession()) {
            header('Location: login.php');
            exit;
        }
    }

    /**
     * Superadmin con sesion admin activa -> Presupuesto u OC sin perder la sesion admin.
     *
     * @param 'budget'|'oc' $app
     */
    public static function openPortalFromAdminSession(PDO $pdo, string $app): void
    {
        if (!in_array($app, ['budget', 'oc'], true)) {
            throw new InvalidArgumentException('app invalido');
        }

        if (!self::ensureAdminSuperadminSession($pdo)) {
            redirect_to('admin/login.php');
        }

        $uid = (int) self::userId();
        $realm = $app === 'budget' ? self::REALM_BUDGET : self::REALM_OC;
        $tenants = self::tenantsForApp($pdo, $uid, $app);
        if ($tenants === []) {
            http_response_code(403);
            echo '<!DOCTYPE html><html lang="es"><head><meta charset="utf-8"><title>Sin tenants</title></head><body>';
            echo '<p>No hay colegios activos con el modulo <strong>' . htmlspecialchars($app, ENT_QUOTES, 'UTF-8') . '</strong> habilitado.</p>';
            echo '<p><a href="' . htmlspecialchars(url_path('admin/index.php'), ENT_QUOTES, 'UTF-8') . '">Volver al panel Admin</a></p>';
            echo '</body></html>';
            exit;
        }

        $tid = (int) ($_POST['tenant_id'] ?? $_GET['tenant_id'] ?? 0);
        if ($tid <= 0 && count($tenants) === 1) {
            $tid = (int) $tenants[0]['id'];
        }

        self::startRealm($realm);

        if ($tid > 0) {
            $valid = false;
            foreach ($tenants as $t) {
                if ((int) $t['id'] === $tid) {
                    $valid = true;
                    break;
                }
            }
            if ($valid) {
                self::loginUser($uid, $realm, $tid, true);
                self::commitSession();
                redirect_to($app . '/index.php');
            }
        }

        $_SESSION['pick_user_id'] = $uid;
        $_SESSION['pick_tenants'] = $tenants;
        $_SESSION['pick_is_superadmin'] = true;
        self::commitSession();
        redirect_to($app . '/pick_tenant.php');
    }

    /**
     * Recupera sesion Presupuesto/OC si se perdio el estado intermedio (pick_tenant).
     *
     * @param 'budget'|'oc' $app
     */
    public static function bootstrapPortalSessionFromAdmin(PDO $pdo, string $app): bool
    {
        if (!in_array($app, ['budget', 'oc'], true)) {
            return false;
        }

        $realm = $app === 'budget' ? self::REALM_BUDGET : self::REALM_OC;
        self::startRealm($realm);
        if (self::userId() !== null && self::realm() === $realm && self::tenantId() !== null) {
            return true;
        }

        if (!self::ensureAdminSuperadminSession($pdo)) {
            self::startRealm($realm);

            return false;
        }

        $uid = (int) self::userId();
        $tenants = self::tenantsForApp($pdo, $uid, $app);
        if ($tenants === []) {
            self::startRealm($realm);

            return false;
        }

        self::startRealm($realm);
        if (count($tenants) === 1) {
            self::loginUser($uid, $realm, (int) $tenants[0]['id'], true);
            self::commitSession();

            return true;
        }

        $_SESSION['pick_user_id'] = $uid;
        $_SESSION['pick_tenants'] = $tenants;
        $_SESSION['pick_is_superadmin'] = true;
        self::commitSession();

        return true;
    }

    /**
     * Sesión admin del home (public/index.php): válida o reconstruida desde Presupuesto/OC.
     * No abre primero una sesión admin vacía (evitaba leer la cookie de presupuesto/OC).
     */
    public static function ensureAdminHomeSession(PDO $pdo): ?int
    {
        foreach ([self::REALM_ADMIN, self::REALM_BUDGET, self::REALM_OC] as $realm) {
            self::startRealm($realm);
            $uid = self::portalSessionUserId();
            if ($uid === null) {
                continue;
            }
            $uid = (int) $uid;
            if ($realm === self::REALM_ADMIN) {
                if (self::realm() !== self::REALM_ADMIN) {
                    $_SESSION['realm'] = self::REALM_ADMIN;
                }

                return $uid;
            }

            self::startRealm(self::REALM_ADMIN);
            self::writeAdminHomeSession($pdo, $uid);
            self::commitSession();

            return $uid;
        }

        self::startRealm(self::REALM_ADMIN);

        return null;
    }

    /**
     * Crea sesión admin a partir de una sesión activa en Presupuesto u OC (cambio de portal sin re-login).
     */
    public static function bootstrapAdminSessionFromPortal(PDO $pdo): ?int
    {
        foreach ([self::REALM_BUDGET, self::REALM_OC] as $fallbackRealm) {
            self::startRealm($fallbackRealm);
            $u = self::portalSessionUserId();
            if ($u === null) {
                continue;
            }
            self::startRealm(self::REALM_ADMIN);
            self::writeAdminHomeSession($pdo, (int) $u);
            self::commitSession();

            return (int) $u;
        }

        self::startRealm(self::REALM_ADMIN);

        return null;
    }

    /** Sesión admin del home sin regenerar id (evita pisar otras cookies de portal). */
    private static function writeAdminHomeSession(PDO $pdo, int $userId): void
    {
        $isSa = self::isSuperadminUser($pdo, $userId);
        $_SESSION['user_id'] = $userId;
        $_SESSION['realm'] = self::REALM_ADMIN;
        $_SESSION['is_superadmin'] = $isSa;
        if ($isSa) {
            $_SESSION['is_tenant_policy_admin'] = false;
            $_SESSION['tenant_id'] = null;
        } else {
            $tenantIds = AdminAccess::tenantAdminTenantIds($pdo, $userId);
            $_SESSION['is_tenant_policy_admin'] = $tenantIds !== [];
            $_SESSION['tenant_id'] = $tenantIds !== [] ? (int) $tenantIds[0] : null;
        }
    }

    /** Crea sesión admin del home tras login (Google o contraseña). */
    public static function establishAdminHomeSession(PDO $pdo, int $userId): void
    {
        self::startRealm(self::REALM_ADMIN);
        self::writeAdminHomeSession($pdo, $userId);
        self::commitSession();
    }

    /**
     * Login Google: usuario existente por google_id o email (vincula google_id).
     *
     * @return array{id: int, email: string, full_name: string}|null
     */
    public static function attemptGoogle(
        PDO $pdo,
        string $googleId,
        string $email,
        string $fullName,
        ?string $avatarUrl
    ): ?array {
        $googleId = trim($googleId);
        $email = trim(strtolower($email));
        if ($googleId === '' || $email === '') {
            return null;
        }

        $st = $pdo->prepare(
            'SELECT id, email, full_name, active, google_id FROM users WHERE google_id = ? LIMIT 1'
        );
        $st->execute([$googleId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            $st = $pdo->prepare(
                'SELECT id, email, full_name, active, google_id FROM users WHERE email = ? LIMIT 1'
            );
            $st->execute([$email]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                return null;
            }
            $existingGoogleId = trim((string) ($row['google_id'] ?? ''));
            if ($existingGoogleId !== '' && !hash_equals($existingGoogleId, $googleId)) {
                return null;
            }
            self::linkGoogleAccount($pdo, (int) $row['id'], $googleId, $fullName, $avatarUrl);
        } else {
            self::touchGoogleProfile($pdo, (int) $row['id'], $fullName, $avatarUrl);
        }

        if (!(int) $row['active']) {
            return null;
        }

        return [
            'id' => (int) $row['id'],
            'email' => (string) $row['email'],
            'full_name' => (string) $row['full_name'],
        ];
    }

    private static function linkGoogleAccount(
        PDO $pdo,
        int $userId,
        string $googleId,
        string $fullName,
        ?string $avatarUrl
    ): void {
        $st = $pdo->prepare(
            'UPDATE users SET google_id = ?, full_name = CASE WHEN full_name = "" THEN ? ELSE full_name END,
             avatar_url = COALESCE(?, avatar_url) WHERE id = ?'
        );
        $st->execute([$googleId, $fullName, $avatarUrl, $userId]);
    }

    private static function touchGoogleProfile(
        PDO $pdo,
        int $userId,
        string $fullName,
        ?string $avatarUrl
    ): void {
        if ($avatarUrl === null && $fullName === '') {
            return;
        }
        $st = $pdo->prepare(
            'UPDATE users SET full_name = CASE WHEN full_name = "" AND ? <> "" THEN ? ELSE full_name END,
             avatar_url = COALESCE(?, avatar_url) WHERE id = ?'
        );
        $st->execute([$fullName, $fullName, $avatarUrl, $userId]);
    }

    public static function loginPathForRealm(string $realm): string
    {
        if (GoogleAuth::isEnabled()) {
            return '../index.php';
        }

        return match ($realm) {
            self::REALM_ADMIN => 'login.php',
            self::REALM_BUDGET => '../budget/login.php',
            self::REALM_OC => '../oc/login.php',
            default => 'login.php',
        };
    }

    public static function verifyPassword(string $plain, string $hash): bool
    {
        return password_verify($plain, $hash);
    }

    /** @return array{id: int, email: string, full_name: string}|null */
    public static function attempt(PDO $pdo, string $email, string $password): ?array
    {
        $st = $pdo->prepare('SELECT id, email, password_hash, full_name, active FROM users WHERE email = ? LIMIT 1');
        $st->execute([trim(strtolower($email))]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row || !(int) $row['active']) {
            return null;
        }
        $hash = (string) ($row['password_hash'] ?? '');
        if ($hash === '' || !self::verifyPassword($password, $hash)) {
            return null;
        }

        return [
            'id' => (int) $row['id'],
            'email' => (string) $row['email'],
            'full_name' => (string) $row['full_name'],
        ];
    }

    public static function hasTenantApp(PDO $pdo, int $userId, int $tenantId, string $app): bool
    {
        $st = $pdo->prepare(
            'SELECT 1 FROM user_tenant_roles utr
             INNER JOIN tenants t ON t.id = utr.tenant_id AND t.active = 1
             WHERE utr.user_id = ? AND utr.tenant_id = ? AND utr.app = ? LIMIT 1'
        );
        $st->execute([$userId, $tenantId, $app]);
        if ($st->fetchColumn()) {
            return true;
        }
        $stSa = $pdo->prepare('SELECT 1 FROM super_admins WHERE user_id = ?');
        $stSa->execute([$userId]);

        return (bool) $stSa->fetchColumn();
    }

    /** @return list<array{id: int, name: string, slug: string}> */
    public static function tenantsForApp(PDO $pdo, int $userId, string $app): array
    {
        $stSa = $pdo->prepare('SELECT 1 FROM super_admins WHERE user_id = ?');
        $stSa->execute([$userId]);
        if ($stSa->fetchColumn()) {
            $flag = $app === 'budget' ? 'budget_enabled' : 'oc_enabled';

            return $pdo->query(
                "SELECT id, name, slug FROM tenants WHERE active = 1 AND {$flag} = 1 ORDER BY name"
            )->fetchAll(PDO::FETCH_ASSOC);
        }
        $st = $pdo->prepare(
            'SELECT t.id, t.name, t.slug FROM user_tenant_roles utr
             INNER JOIN tenants t ON t.id = utr.tenant_id AND t.active = 1
             WHERE utr.user_id = ? AND utr.app = ?
             ORDER BY t.name'
        );
        $st->execute([$userId, $app]);

        return $st->fetchAll(PDO::FETCH_ASSOC);
    }
}
