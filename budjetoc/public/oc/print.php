<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';
$pdo = Db::pdo($CONFIG);
Auth::checkPortal($pdo, Auth::REALM_OC);
$tid = (int) Auth::tenantId();

$id = (int) ($_GET['id'] ?? 0);
$year = (int) ($_GET['year'] ?? ($_GET['return_year'] ?? date('Y')));

$view = OcPrintView::build($pdo, $tid, $id);
if ($view === null) {
    http_response_code(404);
    echo 'OC no encontrada';
    exit;
}

extract($view, EXTR_SKIP);
$backHref = 'index.php?year=' . rawurlencode((string) ($folioYear ?? $year));
$backLabel = 'Volver al listado';
$cssHref = '../css/app.css';
$showAllocationsDetailTable = false;

require __DIR__ . '/inc/oc_print_render.php';
