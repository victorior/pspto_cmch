<?php

declare(strict_types=1);

/**
 * Puente POST Presupuesto/OC -> panel Admin (CSRF).
 */
require dirname(__DIR__, 2) . '/src/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect_to('admin/login.php');
}

$fromRealm = (string) ($_POST['_from_realm'] ?? 'budget');
if (!in_array($fromRealm, [Auth::REALM_BUDGET, Auth::REALM_OC], true)) {
    $fromRealm = Auth::REALM_BUDGET;
}
Auth::startRealm($fromRealm);
require_post_csrf();

$pdo = Db::pdo($CONFIG);
if (!AdminAccess::bootstrapAdminSessionFromPeerRealms($pdo)) {
    redirect_to('admin/login.php');
}

AdminAccess::redirectAfterAdminLogin($pdo);
