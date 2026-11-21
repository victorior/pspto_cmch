<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';
$pdo = Db::pdo($CONFIG);
Auth::checkPortal($pdo, Auth::REALM_BUDGET);
$tid = (int) Auth::tenantId();
$uid = (int) Auth::userId();

$token = trim((string) ($_GET['export_token'] ?? ''));
if ($token !== '') {
    $resolved = BudgetExportService::loadPreparedExport($pdo, $tid, $uid, $token);
} else {
    $resolved = BudgetExportService::resolveExport($pdo, $tid, $uid, $_GET);
}

if (!$resolved['ok']) {
    http_response_code($resolved['code']);
    header('Content-Type: text/plain; charset=UTF-8');
    echo $resolved['error'];
    exit;
}

while (ob_get_level() > 0) {
    ob_end_clean();
}

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $resolved['filename'] . '"');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');

$out = fopen('php://output', 'wb');
if ($out === false) {
    http_response_code(500);
    exit('No se pudo generar el archivo.');
}

BudgetExportService::writeCsvHeader($out);
BudgetExportService::streamCsvBody($pdo, $out, $resolved['headers'], $resolved['budget_year']);
fclose($out);
exit;
