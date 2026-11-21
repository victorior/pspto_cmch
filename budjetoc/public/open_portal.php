<?php

declare(strict_types=1);

/**
 * Puente POST desde el home hacia Presupuesto u OC (CSRF).
 */
require dirname(__DIR__) . '/src/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect_to('index.php?err=portal_method');
}

Auth::startRealm(Auth::REALM_ADMIN);
require_post_csrf();

$portal = (string) ($_POST['portal'] ?? '');
if (!in_array($portal, ['budget', 'oc'], true)) {
    redirect_to('index.php?err=portal_invalido');
}

$pdo = Db::pdo($CONFIG);

$uid = Auth::ensureAdminHomeSession($pdo);
if ($uid === null) {
    redirect_to('index.php?err=sesion_home_perdida');
}

$app = $portal;
$realm = $app === 'budget' ? Auth::REALM_BUDGET : Auth::REALM_OC;

$isSa = Auth::isSuperadminUser($pdo, (int) $uid);
$tenants = Auth::tenantsForApp($pdo, (int) $uid, $app);
if ($tenants === []) {
    redirect_to('index.php?err=sin_acceso_' . rawurlencode($app));
}

Auth::startRealm($realm);
if (count($tenants) === 1) {
    Auth::loginUser((int) $uid, $realm, (int) $tenants[0]['id'], $isSa);
    Auth::commitSession();
    redirect_to($app . '/index.php');
}

$_SESSION['pick_user_id'] = (int) $uid;
$_SESSION['pick_tenants'] = $tenants;
$_SESSION['pick_is_superadmin'] = $isSa;
Auth::commitSession();
redirect_to($app . '/pick_tenant.php');
