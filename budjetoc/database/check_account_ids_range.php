<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

$pdo = Db::pdo($CONFIG);
$tid = isset($argv[1]) ? (int) $argv[1] : 2;
$hid = isset($argv[2]) ? (int) $argv[2] : 7;

echo "BD: " . ($CONFIG['db']['name'] ?? '') . "  tenant_id={$tid}\n\n";

echo "=== account_codes id 30-45 (orden por id) ===\n";
$st = $pdo->prepare(
    'SELECT id, account_id, name FROM account_codes WHERE tenant_id = ? AND id BETWEEN 30 AND 45 ORDER BY id'
);
$st->execute([$tid]);
while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
    echo sprintf("id=%-3s  account_id=%-10s  %s\n", $r['id'], $r['account_id'], $r['name']);
}

echo "\n=== Donde dice PASAJES o PREMIOS (tenant {$tid}) ===\n";
$st = $pdo->prepare(
    "SELECT id, account_id, name FROM account_codes WHERE tenant_id = ? AND (name LIKE '%PASAJES%' OR name LIKE '%PREMIOS%') ORDER BY id"
);
$st->execute([$tid]);
while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
    echo sprintf("id=%-3s  account_id=%-10s  %s\n", $r['id'], $r['account_id'], $r['name']);
}

echo "\n=== budget_details -> account_codes (header {$hid}) ===\n";
$st = $pdo->prepare(
    'SELECT bd.id AS linea, bd.account_code_id AS fk_guardado,
            ac.id AS ac_pk, ac.account_id, ac.name
     FROM budget_details bd
     LEFT JOIN account_codes ac ON ac.id = bd.account_code_id
     WHERE bd.budget_header_id = ?'
);
$st->execute([$hid]);
while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
    echo sprintf(
        "linea %s: budget_details.account_code_id=%s  ->  account_codes.id=%s  name=%s  (plan account_id=%s)\n",
        $r['linea'],
        $r['fk_guardado'],
        $r['ac_pk'] ?? 'NULL',
        $r['name'] ?? 'NULL',
        $r['account_id'] ?? 'NULL'
    );
}

echo "\n=== Si fk fuera 37 en lugar de 35 ===\n";
$st = $pdo->prepare('SELECT id, account_id, name FROM account_codes WHERE id = 37 AND tenant_id = ?');
$st->execute([$tid]);
$row37 = $st->fetch(PDO::FETCH_ASSOC);
echo $row37 ? sprintf("id=37 => %s (account_id=%s)\n", $row37['name'], $row37['account_id']) : "No hay fila id=37 en tenant {$tid}\n";

$st = $pdo->prepare('SELECT id, account_id, name FROM account_codes WHERE id = 35 AND tenant_id = ?');
$st->execute([$tid]);
$row35 = $st->fetch(PDO::FETCH_ASSOC);
echo $row35 ? sprintf("id=35 => %s (account_id=%s)\n", $row35['name'], $row35['account_id']) : "No hay fila id=35\n";
