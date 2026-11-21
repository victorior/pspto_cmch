<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

$pdo = Db::pdo($CONFIG);
$cfg = $CONFIG['db'] ?? [];

echo 'Conexion: host=' . ($cfg['host'] ?? '') . ' db=' . ($cfg['name'] ?? '') . ' user=' . ($cfg['user'] ?? '') . "\n\n";

foreach ([33, 34, 35, 36, 37] as $pk) {
    echo "=== account_codes.id = {$pk} (TODOS los tenants) ===\n";
    $st = $pdo->prepare(
        'SELECT ac.id, ac.tenant_id, t.slug, t.name AS tenant_name, ac.account_id, ac.name
         FROM account_codes ac
         LEFT JOIN tenants t ON t.id = ac.tenant_id
         WHERE ac.id = ?
         ORDER BY ac.tenant_id'
    );
    $st->execute([$pk]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    if ($rows === []) {
        echo "  (sin fila con este id)\n";
    }
    foreach ($rows as $r) {
        echo sprintf(
            "  tenant_id=%s slug=%s | account_id=%s | %s\n",
            $r['tenant_id'],
            $r['slug'] ?? '?',
            $r['account_id'],
            $r['name']
        );
    }
    echo "\n";
}

echo "=== Actividad budget_headers.id=7 ===\n";
$st = $pdo->prepare('SELECT id, tenant_id, activity_number FROM budget_headers WHERE id = 7');
$st->execute();
$h = $st->fetch(PDO::FETCH_ASSOC);
if (!$h) {
    echo "  No existe header 7\n";
} else {
    echo "  tenant_id={$h['tenant_id']} activity_number={$h['activity_number']}\n";
    $tid = (int) $h['tenant_id'];
    $st = $pdo->prepare(
        'SELECT bd.id, bd.account_code_id, ac.id, ac.tenant_id, ac.name
         FROM budget_details bd
         LEFT JOIN account_codes ac ON ac.id = bd.account_code_id
         WHERE bd.budget_header_id = 7'
    );
    $st->execute();
    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
        echo sprintf(
            "  linea %s: fk=%s -> ac.id=%s ac.tenant=%s name=%s\n",
            $r['id'],
            $r['account_code_id'],
            $r['id'] ?? 'NULL',
            $r['tenant_id'] ?? 'NULL',
            $r['name'] ?? 'NULL'
        );
        if ((int) ($r['tenant_id'] ?? 0) !== $tid) {
            echo "    *** ALERTA: cuenta de otro tenant que el de la actividad ***\n";
        }
    }
}

echo "\n=== Listado id 30-40 SOLO tenant de actividad 7 ===\n";
if (!empty($h)) {
    $tid = (int) $h['tenant_id'];
    $st = $pdo->prepare(
        'SELECT id, account_id, name FROM account_codes WHERE tenant_id = ? AND id BETWEEN 30 AND 40 ORDER BY id'
    );
    $st->execute([$tid]);
    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
        echo sprintf("  id=%s account_id=%s %s\n", $r['id'], $r['account_id'], $r['name']);
    }
}

echo "\n=== COUNT(*) total account_codes ===\n";
echo '  ' . $pdo->query('SELECT COUNT(*) FROM account_codes')->fetchColumn() . "\n";
