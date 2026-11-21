<?php

declare(strict_types=1);

/**
 * Vista OC en modo solo lectura desde una actividad de Presupuesto.
 */

require dirname(__DIR__, 2) . '/src/bootstrap.php';

$pdo = Db::pdo($CONFIG);
Auth::checkPortal($pdo, Auth::REALM_BUDGET);
$tid = (int) Auth::tenantId();
$uid = (int) Auth::userId();

$poId = (int) ($_GET['id'] ?? 0);
$hid = (int) ($_GET['activity_id'] ?? 0);
$backHref = url_path('budget/activity_view.php?id=' . $hid);
if ($poId <= 0 || $hid <= 0) {
    http_response_code(400);
    exit('Solicitud inválida.');
}

function oc_from_activity_error(string $msg, string $backHref): void
{
    http_response_code(403);
    echo '<!DOCTYPE html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>Acceso denegado</title><link rel="stylesheet" href="../css/app.css"></head><body>';
    echo '<div class="wrap"><div class="card">';
    echo '<p class="alert alert-err">' . h($msg) . '</p>';
    echo '<p style="margin:0;"><a class="btn btn-primary" href="' . h($backHref) . '">&larr; Volver a la actividad</a></p>';
    echo '</div></div></body></html>';
    exit;
}

$st = $pdo->prepare(
    'SELECT 1
     FROM po_budget_allocations pba
     INNER JOIN budget_details bd ON bd.id = pba.budget_detail_id
     INNER JOIN budget_headers bh ON bh.id = bd.budget_header_id
     WHERE pba.purchase_order_id = ? AND pba.tenant_id = ? AND bh.id = ? AND bh.tenant_id = ?
     LIMIT 1'
);
$st->execute([$poId, $tid, $hid, $tid]);
if (!$st->fetchColumn()) {
    oc_from_activity_error('No tiene acceso a esta O.C. desde la actividad indicada.', $backHref);
}

$bh = BudgetService::getHeader($pdo, $tid, $hid);
if ($bh === null) {
    http_response_code(404);
    exit('Actividad no encontrada.');
}
if (!UserPermissions::canViewActivityHeader($pdo, $uid, $tid, $bh)) {
    oc_from_activity_error('No tiene permiso para ver esta actividad en su ámbito asignado.', $backHref);
}

$view = OcPrintView::build($pdo, $tid, $poId);
if ($view === null) {
    http_response_code(404);
    exit('OC no encontrada');
}

extract($view, EXTR_SKIP);
$backLabel = 'Volver a la actividad';
$cssHref = '../css/app.css';
$showAllocationsDetailTable = true;

require __DIR__ . '/inc/oc_print_render.php';
