<?php

declare(strict_types=1);

/**
 * Prueba CLI de sesiones entre Admin / Presupuesto / OC.
 * Uso: php database/test_session_portals.php [email]
 */

require dirname(__DIR__) . '/src/bootstrap.php';

$pdo = Db::pdo($CONFIG);
$email = trim((string) ($argv[1] ?? 'admin@local.test'));
$fail = 0;

function pass(string $msg): void
{
    global $fail;
    echo "  OK   {$msg}\n";
}

function fail(string $msg): void
{
    global $fail;
    $fail++;
    echo "  FAIL {$msg}\n";
}

function reset_all_realms(): void
{
    foreach ([Auth::REALM_ADMIN, Auth::REALM_BUDGET, Auth::REALM_OC] as $realm) {
        Auth::startRealm($realm);
        Auth::logout();
    }
}

echo "=== Pruebas sesion multi-portal ===\n\n";

$st = $pdo->prepare('SELECT id FROM users WHERE email = ? AND active = 1 LIMIT 1');
$st->execute([strtolower($email)]);
$uid = (int) ($st->fetchColumn() ?: 0);
if ($uid <= 0) {
    fail("Usuario no encontrado: {$email}");
    echo "\nResumen: FAIL\n";
    exit(1);
}
pass("user_id={$uid}");

$stSa = $pdo->prepare('SELECT 1 FROM super_admins WHERE user_id = ?');
$stSa->execute([$uid]);
$isSa = (bool) $stSa->fetchColumn();
pass($isSa ? 'superadmin' : 'no superadmin');

$cfgPath = rtrim((string) ($CONFIG['base_url'] ?? ''), '/') . '/';
pass('cookie path esperado: ' . $cfgPath);

reset_all_realms();

// 1) Login admin
Auth::startRealm(Auth::REALM_ADMIN);
Auth::loginUser($uid, Auth::REALM_ADMIN, null, $isSa);
Auth::commitSession();
Auth::startRealm(Auth::REALM_ADMIN);
if (Auth::userId() === $uid && Auth::realm() === Auth::REALM_ADMIN) {
    pass('sesion admin creada');
} else {
    fail('sesion admin creada');
}

// 2) Presupuesto desde admin (open portal)
$tenants = Auth::tenantsForApp($pdo, $uid, 'budget');
if ($tenants === []) {
    fail('sin tenants budget');
} else {
    $tid = (int) $tenants[0]['id'];
    Auth::startRealm(Auth::REALM_BUDGET);
    Auth::loginUser($uid, Auth::REALM_BUDGET, $tid, $isSa);
    Auth::commitSession();
    Auth::startRealm(Auth::REALM_BUDGET);
    if (Auth::userId() === $uid && Auth::tenantId() === $tid && Auth::realm() === Auth::REALM_BUDGET) {
        pass('sesion budget desde admin');
    } else {
        fail('sesion budget desde admin');
    }
}

// 3) Admin sigue viva tras budget
Auth::startRealm(Auth::REALM_ADMIN);
if (Auth::userId() === $uid) {
    pass('admin persiste tras abrir budget');
} else {
    fail('admin persiste tras abrir budget (causa tipica de cierre al cambiar portal)');
}

// 4) bootstrap admin desde budget (enter_from_portal)
Auth::startRealm(Auth::REALM_BUDGET);
if (AdminAccess::bootstrapAdminSessionFromPeerRealms($pdo) && Auth::userId() === $uid) {
    pass('bootstrapAdminSessionFromPeerRealms');
} else {
    fail('bootstrapAdminSessionFromPeerRealms');
}

// 5) OC desde home session
Auth::startRealm(Auth::REALM_OC);
Auth::logout();
$ocTenants = Auth::tenantsForApp($pdo, $uid, 'oc');
if ($ocTenants === []) {
    fail('sin tenants oc');
} else {
    $ocTid = (int) $ocTenants[0]['id'];
    $homeUid = Auth::ensureAdminHomeSession($pdo);
    if ($homeUid === $uid) {
        pass('ensureAdminHomeSession');
    } else {
        fail('ensureAdminHomeSession uid=' . var_export($homeUid, true));
    }
    Auth::startRealm(Auth::REALM_OC);
    Auth::loginUser($uid, Auth::REALM_OC, $ocTid, $isSa);
    Auth::commitSession();
    Auth::startRealm(Auth::REALM_OC);
    if (Auth::userId() === $uid && Auth::tenantId() === $ocTid) {
        pass('sesion oc');
    } else {
        fail('sesion oc');
    }
}

// 6) admin reconstruido bajo demanda (sin refresh en cada hit de portal)
Auth::startRealm(Auth::REALM_ADMIN);
Auth::logout();
Auth::startRealm(Auth::REALM_OC);
$homeUid = Auth::ensureAdminHomeSession($pdo);
if ($homeUid === $uid) {
    pass('ensureAdminHomeSession desde OC sin refresh por request');
} else {
    fail('ensureAdminHomeSession desde OC sin refresh por request');
}

// 7) checkPortal bootstrap (simula carga index budget)
Auth::startRealm(Auth::REALM_BUDGET);
Auth::logout();
Auth::startRealm(Auth::REALM_ADMIN);
Auth::logout();
if (Auth::bootstrapPortalSessionFromAdmin($pdo, 'budget') && Auth::tenantId() !== null) {
    pass('bootstrapPortalSessionFromAdmin(budget)');
} else {
    fail('bootstrapPortalSessionFromAdmin(budget)');
}

echo "\n=== Resumen: " . ($fail === 0 ? 'OK' : "FAIL={$fail}") . " ===\n";
exit($fail > 0 ? 1 : 0);
