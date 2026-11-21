<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';
$pdo = Db::pdo($CONFIG);
foreach (['SEGÚN', 'subterráneo', 'altísimos', 'Dragón', 'niños'] as $t) {
    $st = $pdo->prepare('SELECT COUNT(*) FROM products WHERE name LIKE ?');
    $st->execute(['%' . $t . '%']);
    echo "{$t}: " . $st->fetchColumn() . "\n";
}
echo '???? restantes: ' . $pdo->query("SELECT COUNT(*) FROM products WHERE name LIKE '%????%'")->fetchColumn() . "\n";
