<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

echo "OK: aplicacion conectada a " . $CONFIG['db']['name'] . "\n";
$st = Db::pdo($CONFIG)->query('SELECT DATABASE()');
echo "MySQL DATABASE(): " . $st->fetchColumn() . "\n";
