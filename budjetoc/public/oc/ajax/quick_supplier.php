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
    $id = SupplierService::create(
        $pdo,
        $tid,
        (string) ($_POST['legal_name'] ?? ''),
        (string) ($_POST['trade_name'] ?? ''),
        (string) ($_POST['phone'] ?? ''),
        (string) ($_POST['email'] ?? ''),
    );
    $row = SupplierService::get($pdo, $tid, $id);
    json_utf8([
        'ok' => true,
        'id' => $id,
        'nombre_cliente' => trim((string) ($row['legal_name'] ?? '')),
        'telefono_cliente' => (string) ($row['phone'] ?? ''),
        'email_cliente' => (string) ($row['email'] ?? ''),
    ]);
} catch (Throwable $e) {
    json_utf8(['ok' => false, 'error' => $e->getMessage()]);
}
