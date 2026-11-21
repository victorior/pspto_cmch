<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';
$pdo = Db::pdo($CONFIG);
Auth::checkPortal($pdo, Auth::REALM_OC);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect_to('oc/index.php');
}

require_post_csrf();

$id = (int) ($_POST['id'] ?? 0);
$tid = (int) Auth::tenantId();
$year = (int) ($_POST['year'] ?? date('Y'));
$loc = 'oc/index.php?year=' . max(2000, min(2100, $year));

if ($id <= 0) {
    redirect_to($loc);
}

if (!empty($_POST['invoice_only'])) {
    $inv = max(0, (int) ($_POST['invoice_number_in'] ?? 0));
    $st = $pdo->prepare(
        'UPDATE purchase_orders SET invoice_number_in = ? WHERE id = ? AND tenant_id = ?'
    );
    $st->execute([$inv, $id, $tid]);
    redirect_to($loc);
}

if (!empty($_POST['status_only'])) {
    $status = (int) ($_POST['status'] ?? 0);
    if ($status < 0 || $status > 2) {
        $status = 0;
    }
    if ($status === OcService::STATUS_ANULADA) {
        $st = $pdo->prepare(
            'UPDATE purchase_orders SET status = ?, voided_at = COALESCE(voided_at, NOW())
             WHERE id = ? AND tenant_id = ?'
        );
        $st->execute([$status, $id, $tid]);
    } else {
        $st = $pdo->prepare(
            'UPDATE purchase_orders SET status = ?, voided_at = NULL WHERE id = ? AND tenant_id = ?'
        );
        $st->execute([$status, $id, $tid]);
    }
    redirect_to($loc);
}

if (!empty($_POST['tax_only'])) {
    $st = $pdo->prepare('SELECT id, status, folio_year, numero_oc FROM purchase_orders WHERE id = ? AND tenant_id = ?');
    $st->execute([$id, $tid]);
    $po = $st->fetch(PDO::FETCH_ASSOC);
    if ($po === false) {
        redirect_to($loc);
    }
    try {
        if (!OcService::canChangeTaxProfile($po)) {
            throw new RuntimeException('No se puede cambiar el perfil tributario de una OC anulada.');
        }
        $folioYearInt = (int) ($po['folio_year'] ?? $year);
        $taxProfile = oc_tax_profile_from_form_input(
            strtolower(trim((string) ($_POST['tax_doc_type'] ?? ''))),
            strtolower(trim((string) ($_POST['tax_factura_mode'] ?? ''))),
            $folioYearInt,
            trim((string) ($_POST['tax_retencion_percent'] ?? ''))
        );
        OcService::updatePurchaseOrderTaxProfile($pdo, $tid, $id, $taxProfile);
        redirect_to($loc . '&tax_updated=' . (int) ($po['numero_oc'] ?? 0));
    } catch (Throwable $e) {
        redirect_to('oc/oc_tax_edit.php?id=' . $id . '&year=' . max(2000, min(2100, $year))
            . '&err=' . rawurlencode($e->getMessage()));
    }
}

redirect_to($loc);
