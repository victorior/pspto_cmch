<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';
$pdo = Db::pdo($CONFIG);
Auth::checkPortal($pdo, Auth::REALM_BUDGET);
$tid = (int) Auth::tenantId();
$uid = (int) Auth::userId();

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

$resolved = BudgetExportService::resolveExport($pdo, $tid, $uid, $_GET, true);
if (!$resolved['ok']) {
    http_response_code($resolved['code']);
    echo json_encode(['ok' => false, 'error' => $resolved['error']], JSON_UNESCAPED_UNICODE);
    exit;
}

$token = BudgetExportService::storePreparedExport($tid, $uid, $resolved);
$counts = BudgetExportService::countCsvRows($pdo, $resolved['headers']);

echo json_encode([
    'ok' => true,
    'export_token' => $token,
    'activity_count' => $counts['activity_count'],
    'row_count' => $counts['row_count'],
    'budget_year' => $resolved['budget_year'],
    'filename' => $resolved['filename'],
    'department_name' => (string) ($resolved['dept']['name'] ?? ''),
], JSON_UNESCAPED_UNICODE);
