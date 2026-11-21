<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';
logout_all_portals();
redirect_to('index.php');
