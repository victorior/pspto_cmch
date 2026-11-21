<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';
require_once __DIR__ . '/inc/oc_layout_nav.php';

$pdo = Db::pdo($CONFIG);
Auth::checkPortal($pdo, Auth::REALM_OC);
$tid = (int) Auth::tenantId();
$uid = (int) Auth::userId();
$year = (int) ($_GET['year'] ?? date('Y'));

if (!UserPermissions::canManageOcPrintTemplate($pdo, $uid, $tid)) {
    http_response_code(403);
    echo 'Solo administradores pueden editar la plantilla de impresion.';
    echo ' <a href="index.php?year=' . (int) $year . '">Volver al listado</a>';
    exit;
}

$err = '';
$ok = '';
$ts = TenantPrintSettings::load($pdo, $tid);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post_csrf();
    try {
        TenantPrintSettings::saveFromPost($pdo, $tid, $_POST);
        $ts = TenantPrintSettings::load($pdo, $tid);
        $ok = 'Plantilla de impresion guardada. El pie de la OC se genera desde estos datos.';
    } catch (Throwable $e) {
        $err = 'No se pudo guardar: ' . $e->getMessage();
    }
}

$layoutTitle = 'Plantilla impresion OC';
$layoutRealm = 'oc';
$layoutBootstrap = true;
$layoutCss = '../css/app.css';
$layoutPageHeading = 'Plantilla impresion (pie de pagina y cabecera)';
$layoutBreadcrumb = [
    ['href' => 'index.php?year=' . $year, 'label' => 'Home'],
    ['label' => 'Plantilla'],
];
$layoutNav = oc_layout_nav($pdo, $year, 'settings');
require dirname(__DIR__) . '/inc/layout_top.php';
?>
<?php if ($err !== ''): ?><div class="alert alert-err"><?= h($err) ?></div><?php endif; ?>
<?php if ($ok !== ''): ?><div class="alert alert-ok"><?= h($ok) ?></div><?php endif; ?>

<div class="card">
  <p class="text-muted" style="margin-top:0;">
    Configure aqui el pie de pagina y datos de cabecera que aparecen al imprimir o guardar PDF de una OC.
    Los cambios aplican a todas las ordenes de compra de su institucion.
  </p>
  <form method="post">
    <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
    <h2 style="font-size:1rem;margin:1rem 0 0.5rem;">Cabecera</h2>
    <div class="grid" style="grid-template-columns:repeat(auto-fill,minmax(14rem,1fr));gap:0.65rem;">
      <div><label><?= h(t_es('razon_social')) ?></label><input name="company_name" value="<?= h((string) ($ts['company_name'] ?? '')) ?>"></div>
      <div><label><?= h(t_es('direccion')) ?></label><input name="address" value="<?= h((string) ($ts['address'] ?? '')) ?>"></div>
      <div><label>RUT</label><input name="tax_id" value="<?= h((string) ($ts['tax_id'] ?? '')) ?>"></div>
      <div><label>Giro</label><input name="business_line" value="<?= h((string) ($ts['business_line'] ?? '')) ?>"></div>
      <div><label><?= h(t_es('telefono')) ?></label><input name="phone" value="<?= h((string) ($ts['phone'] ?? '')) ?>"></div>
      <div style="grid-column:1/-1;">
        <label>URL logo (PNG/JPG o enlace publico)</label>
        <input name="print_logo_path" value="<?= h((string) ($ts['print_logo_path'] ?? '')) ?>" placeholder="https://...">
      </div>
    </div>

    <h2 style="font-size:1rem;margin:1.25rem 0 0.5rem;"><?= h(t_es('pie_pagina')) ?></h2>
    <div class="grid" style="grid-template-columns:repeat(auto-fill,minmax(14rem,1fr));gap:0.65rem;">
      <div><label>Contacto (nombre)</label><input name="print_contact_name" value="<?= h((string) ($ts['print_contact_name'] ?? '')) ?>"></div>
      <div><label>Contacto (cargo)</label><input name="print_contact_role" value="<?= h((string) ($ts['print_contact_role'] ?? '')) ?>"></div>
      <div><label>Contacto (email)</label><input name="print_contact_email" type="email" value="<?= h((string) ($ts['print_contact_email'] ?? '')) ?>"></div>
      <div style="grid-column:1/-1;">
        <label><?= h(t_es('print_linea_2_horario')) ?></label>
        <input name="print_delivery_hours" value="<?= h((string) ($ts['print_delivery_hours'] ?? '')) ?>"
               placeholder="Lunes a Viernes 9:00 a 13:00, tarde: 15:30 a 17:00.">
      </div>
      <div style="grid-column:1/-1;">
        <label><?= h(t_es('print_linea_3_direccion')) ?><?= h(t_es('print_reception_por')) ?>&raquo;)</label>
        <input name="print_delivery_address" value="<?= h((string) ($ts['print_delivery_address'] ?? '')) ?>"
               placeholder="Baquedano 648.">
      </div>
      <div style="grid-column:1/-1;">
        <label><?= h(t_es('facturar_a')) ?></label>
        <textarea name="print_invoice_to" rows="2" style="resize:vertical;"><?= h((string) ($ts['print_invoice_to'] ?? '')) ?></textarea>
      </div>
      <div style="grid-column:1/-1;">
        <label><?= h(t_es('pie_pagina')) ?> (copyright u otro texto final)</label>
        <input name="print_footer_text" value="<?= h((string) ($ts['print_footer_text'] ?? '')) ?>" placeholder="<?= h(t_es('print_footer_placeholder')) ?>">
      </div>
    </div>
    <p style="margin-top:1rem;">
      <button type="submit" class="btn btn-primary">Guardar plantilla</button>
      <a class="btn" href="index.php?year=<?= $year ?>">Volver al listado</a>
    </p>
  </form>
</div>
<?php require dirname(__DIR__) . '/inc/layout_bottom.php';
