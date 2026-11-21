<?php

declare(strict_types=1);

require dirname(__DIR__, 3) . '/src/bootstrap_ajax.php';

header('Content-Type: application/json; charset=utf-8');

Auth::checkPortal(Db::pdo($CONFIG), Auth::REALM_OC);

$pdo = Db::pdo($CONFIG);
$tid = (int) Auth::tenantId();

$rows = CatalogService::listOcPlanCuentaOptions($pdo, $tid);
$items = [];
foreach ($rows as $c) {
    $items[] = [
        'id' => (int) $c['id'],
        'label' => account_code_label([
            'account_id' => $c['id'],
            'name' => $c['name'],
        ]),
    ];
}

json_utf8([
    'ok' => true,
    'items' => $items,
]);
