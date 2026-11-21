<?php

declare(strict_types=1);

/**
 * Superadmin: POST hacia Presupuesto u OC (CSRF).
 */
require dirname(__DIR__, 2) . '/src/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect_to('admin/index.php');
}

Auth::startRealm(Auth::REALM_ADMIN);
require_post_csrf();

$portal = (string) ($_POST['portal'] ?? '');
if (!in_array($portal, ['budget', 'oc'], true)) {
    redirect_to('admin/index.php');
}

$pdo = Db::pdo($CONFIG);
Auth::startRealm(Auth::REALM_ADMIN);
AdminAccess::requireSuperadminSession($pdo);
Auth::openPortalFromAdminSession($pdo, $portal);
