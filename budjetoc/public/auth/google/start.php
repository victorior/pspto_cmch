<?php

declare(strict_types=1);

require dirname(__DIR__, 3) . '/src/bootstrap.php';

Auth::startRealm(Auth::REALM_ADMIN);
GoogleAuth::redirectToGoogle();
