<?php

declare(strict_types=1);

require dirname(__DIR__, 3) . '/src/bootstrap.php';

Auth::startRealm(Auth::REALM_ADMIN);

if (!GoogleAuth::isEnabled()) {
    redirect_to('index.php?err=google_disabled');
}

$error = (string) ($_GET['error'] ?? '');
if ($error !== '') {
    redirect_to('index.php?err=google_denied');
}

$code = (string) ($_GET['code'] ?? '');
$state = (string) ($_GET['state'] ?? '');
if ($code === '' || !GoogleAuth::validateOAuthState($state)) {
    redirect_to('index.php?err=google_state');
}

$profile = GoogleAuth::fetchUserProfile($code);
if ($profile === null) {
    redirect_to('index.php?err=google_token');
}

$pdo = Db::pdo($CONFIG);
$user = Auth::attemptGoogle(
    $pdo,
    $profile['sub'],
    $profile['email'],
    $profile['name'],
    $profile['picture']
);

if ($user === null) {
    redirect_to('index.php?err=google_no_account');
}

Auth::establishAdminHomeSession($pdo, (int) $user['id']);
redirect_to('');
