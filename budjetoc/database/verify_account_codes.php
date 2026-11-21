<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

$pdo = Db::pdo($CONFIG);
foreach ([2, 3, 4] as $tid) {
    $st = $pdo->prepare('SELECT COUNT(*) FROM account_codes WHERE tenant_id = ?');
    $st->execute([$tid]);
    $cnt = (int) $st->fetchColumn();
    $stN = $pdo->prepare('SELECT name FROM tenants WHERE id = ?');
    $stN->execute([$tid]);
    $tname = (string) $stN->fetchColumn();
    $nulls = (int) $pdo->query("SELECT COUNT(*) FROM account_codes WHERE tenant_id = {$tid} AND account_id IS NULL")->fetchColumn();
    echo "Tenant {$tid} ({$tname}): {$cnt} cuentas, null account_id={$nulls}\n";
}
$sample = $pdo->query('SELECT account_id, name FROM account_codes WHERE tenant_id = 4 ORDER BY account_id LIMIT 5')->fetchAll(PDO::FETCH_ASSOC);
echo "\nIAE muestra:\n";
foreach ($sample as $r) {
    echo "  {$r['account_id']} - {$r['name']}\n";
}
