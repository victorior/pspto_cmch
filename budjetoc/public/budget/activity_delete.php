<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';
$pdo = Db::pdo($CONFIG);
Auth::checkPortal($pdo, Auth::REALM_BUDGET);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Metodo no permitido');
}

require_post_csrf();

$tid = (int) Auth::tenantId();
$uid = (int) Auth::userId();
$hid = (int) ($_POST['id'] ?? 0);
$returnRaw = trim((string) ($_POST['return'] ?? ''));
$listReturnHref = budget_list_return_href($returnRaw !== '' ? $returnRaw : null);
$returnSuffix = budget_return_query_suffix($returnRaw !== '' ? $returnRaw : null);

$h = BudgetService::getHeader($pdo, $tid, $hid);
if ($h === null) {
    header('Location: ' . $listReturnHref);
    exit;
}

$year = budget_year_from_activity_number((string) ($h['activity_number'] ?? ''));
if (!UserPermissions::canDeleteActivityHeader($pdo, $uid, $tid, $h, $year)) {
    http_response_code(403);
    exit('No tiene permiso para eliminar esta actividad o el ' . t_es('ano') . ' esta en solo lectura.');
}

try {
    BudgetService::deleteActivity($pdo, $tid, $hid);
    $sep = str_contains($listReturnHref, '?') ? '&' : '?';
    header('Location: ' . $listReturnHref . $sep . 'deleted=1');
    exit;
} catch (RuntimeException $e) {
    header('Location: activity_view.php?id=' . $hid . '&error=oc' . $returnSuffix);
    exit;
} catch (Throwable) {
    header('Location: activity_view.php?id=' . $hid . '&error=delete' . $returnSuffix);
    exit;
}
