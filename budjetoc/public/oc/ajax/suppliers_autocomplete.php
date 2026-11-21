<?php

declare(strict_types=1);

require dirname(__DIR__, 3) . '/src/bootstrap_ajax.php';

header('Content-Type: application/json; charset=utf-8');

Auth::checkPortal(Db::pdo($CONFIG), Auth::REALM_OC);

$term = trim((string) ($_GET['term'] ?? $_GET['q'] ?? ''));
if (mb_strlen($term) < 2) {
    json_utf8([]);
    exit;
}

$pdo = Db::pdo($CONFIG);
$tid = (int) Auth::tenantId();
$like = '%' . $term . '%';

$st = $pdo->prepare(
    'SELECT id, legal_name, trade_name, phone, email
     FROM suppliers
     WHERE tenant_id = ?
       AND (legal_name LIKE ? OR trade_name LIKE ? OR email LIKE ? OR phone LIKE ?)
     ORDER BY legal_name ASC
     LIMIT 50'
);
$st->execute([$tid, $like, $like, $like, $like]);

$out = [];
while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
    $name = trim((string) $row['legal_name']);
    $trade = trim((string) $row['trade_name']);
    $label = $trade !== '' ? $name . ' / ' . $trade : $name;
    $out[] = [
        'value' => $name,
        'label' => $label,
        'id_cliente' => (int) $row['id'],
        'nombre_cliente' => $name,
        'telefono_cliente' => (string) ($row['phone'] ?? ''),
        'email_cliente' => (string) ($row['email'] ?? ''),
    ];
}

json_utf8($out);
