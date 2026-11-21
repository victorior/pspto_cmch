<?php

declare(strict_types=1);

require dirname(__DIR__, 3) . '/src/bootstrap_ajax.php';

Auth::checkPortal(Db::pdo($CONFIG), Auth::REALM_OC);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    json_utf8(['ok' => false, 'error' => 'Metodo no permitido.']);
    exit;
}

require_post_csrf();

$pdo = Db::pdo($CONFIG);
$tid = (int) Auth::tenantId();

try {
    $code = (string) ($_POST['code'] ?? '');
    $name = (string) ($_POST['name'] ?? '');
    $price = ProductService::parsePrice((string) ($_POST['unit_price'] ?? ''));
    $active = (string) ($_POST['active'] ?? '1') !== '0';
    $id = ProductService::create($pdo, $tid, $code, $name, $price, $active);
    json_utf8([
        'ok' => true,
        'id' => $id,
        'code' => $code,
        'name' => $name,
        'price' => $price,
    ]);
} catch (Throwable $e) {
    json_utf8(['ok' => false, 'error' => $e->getMessage()]);
}
