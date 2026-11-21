<?php

declare(strict_types=1);

/** @var array<string, mixed> $po */
/** @var list<array<string, mixed>> $lines */
/** @var array<string, mixed> $taxProfile */
/** @var bool $printBoletaFmt */
/** @var array<string, mixed> $ts */
/** @var list<array<string, mixed>> $budgetRows */
/** @var string $budgetInline */
/** @var string $folioLabel */
/** @var string $company */
/** @var bool $isAnulada */
/** @var string $voidedAtLabel */
/** @var string $sinValor */
/** @var string $supplier */
/** @var int $invoiceNumberIn */
/** @var string $paymentLabel */
/** @var string $ctaLabel */
/** @var string $contactName */
/** @var string $contactRole */
/** @var string $contactEmail */
/** @var string $deliveryHours */
/** @var string $deliveryAddr */
/** @var string $introReception */
/** @var string $invoiceTo */
/** @var string $footerText */
/** @var bool $hasPrintFooter */
/** @var Closure(float, int): string $fmtPrintMoney */
/** @var Closure(float): string $fmtPrintPct */
/** @var string $backHref */
/** @var string $backLabel */
/** @var string $cssHref */
/** @var bool $showAllocationsDetailTable */

$cssHref = $cssHref ?? '../css/app.css';
$backLabel = $backLabel ?? 'Volver';
$showAllocationsDetailTable = $showAllocationsDetailTable ?? false;

?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= h($company) ?> - OC <?= h($folioLabel) ?><?= $isAnulada ? ' (Anulada)' : '' ?></title>
  <link rel="stylesheet" href="<?= h($cssHref) ?>">
  <style>
    .wrap { position: relative; }
    .oc-void-banner {
      margin: 0 0 1rem;
      padding: 0.65rem 0.85rem;
      border: 1px solid #fecaca;
      border-radius: var(--radius);
      background: #fef2f2;
      color: #991b1b;
      font-weight: 600;
      text-align: center;
    }
    .oc-void-stamp {
      position: absolute;
      top: 42%;
      left: 50%;
      z-index: 20;
      transform: translate(-50%, -50%) rotate(-32deg);
      pointer-events: none;
      user-select: none;
      padding: 0.35rem 1.25rem;
      border: 0.35rem solid rgba(185, 28, 28, 0.45);
      border-radius: 0.35rem;
      color: rgba(185, 28, 28, 0.42);
      font-size: clamp(2.5rem, 8vw, 4.5rem);
      font-weight: 800;
      letter-spacing: 0.18em;
      line-height: 1;
      text-transform: uppercase;
      white-space: nowrap;
    }
    .print-toolbar { display: flex; gap: 0.5rem; flex-wrap: wrap; margin-bottom: 1rem; }
    .print-head { display: flex; justify-content: space-between; gap: 1rem; margin-bottom: 1rem; padding-bottom: 0.75rem; border-bottom: 1px solid var(--border); }
    .print-head h2 { margin: 0; font-size: 1.1rem; }
    .oc-supplier-row { display: flex; gap: 2rem; flex-wrap: wrap; margin-bottom: 1rem; padding: 0.75rem; border: 1px solid var(--border); border-radius: var(--radius); background: #fafcff; }
    .oc-supplier-row > div { flex: 1; min-width: 14rem; }
    table.lines { width: 100%; border-collapse: collapse; }
    table.lines th, table.lines td { border: 1px solid var(--border); padding: 0.35rem 0.5rem; }
    table.lines th { background: #f8fafc; }
    table.lines .num { text-align: right; white-space: nowrap; }
    .oc-print-footer {
      margin-top: 1.25rem;
      padding-top: 0.75rem;
      border-top: 1px solid var(--border);
      font-size: 0.85rem;
      line-height: 1.45;
      color: #334155;
    }
    .oc-footer-reception { margin-bottom: 0.5rem; }
    .oc-footer-line { display: block; margin: 0 0 0.2rem; padding: 0; line-height: 1.4; }
    .oc-print-footer p { margin: 0 0 0.35rem; }
    .oc-footer-contact { text-align: center; margin: 0.5rem 0; }
    .oc-footer-invoice { margin-top: 0.5rem; }
    .oc-footer-copy { text-align: center; margin-top: 0.5rem; font-size: 0.8rem; }
    .oc-print-pagenum { text-align: right; font-size: 0.8rem; margin-bottom: 0.35rem; }
    @media print {
      .no-print { display: none !important; }
      body { background: #fff; }
      .wrap { max-width: none; padding-bottom: 52mm; }
      .oc-void-stamp {
        position: fixed;
        top: 45%;
        left: 50%;
        transform: translate(-50%, -50%) rotate(-32deg);
        print-color-adjust: exact;
        -webkit-print-color-adjust: exact;
      }
      .oc-print-footer {
        position: fixed;
        left: 0;
        right: 0;
        bottom: 6mm;
        margin-top: 0;
        padding: 4mm 8mm 0;
        border-top: 1px solid #94a3b8;
        background: #fff;
      }
    }
  </style>
</head>
<body>
<div class="wrap">
  <div class="print-toolbar no-print">
    <a class="btn" href="<?= h($backHref) ?>">&larr; <?= h($backLabel) ?></a>
    <button type="button" class="btn btn-primary" onclick="window.print()">Imprimir / PDF</button>
  </div>

  <?php if ($isAnulada): ?>
  <p class="oc-void-banner" role="status">
    Esta orden de compra está <strong>ANULADA</strong>. Se muestra solo como historial; no tiene validez operativa ni consume presupuesto.
  </p>
  <div class="oc-void-stamp" aria-hidden="true">Anulada</div>
  <?php endif; ?>

  <div class="print-head">
    <div>
      <?php $logoPath = trim((string) ($ts['print_logo_path'] ?? '')); ?>
      <?php if ($logoPath !== ''): ?>
        <div style="margin-bottom:0.5rem;"><img src="<?= h($logoPath) ?>" alt="Logo" style="max-height:60px;max-width:240px;"></div>
      <?php endif; ?>
      <strong><?= h($company) ?></strong><br>
      <?= h((string) ($ts['address'] ?? '')) ?><br>
      Rut: <?= h((string) ($ts['tax_id'] ?? '')) ?> &middot; <?= h((string) ($ts['phone'] ?? '')) ?>
    </div>
    <div style="text-align:right;">
      <h2><?= h(t_es('orden_compra')) ?> <?= h(t_es('n_ordinal')) ?> <?= h($folioLabel) ?><?= $isAnulada ? ' — Anulada' : '' ?></h2>
      <div><strong>Fecha OC:</strong> <?= h(substr((string) $po['ordered_at'], 0, 16)) ?></div>
      <?php if ($isAnulada): ?>
      <div style="margin-top:0.35rem;font-size:0.9rem;">
        <div><strong>Fecha anulación:</strong> <?= h($voidedAtLabel) ?></div>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="oc-supplier-row">
    <div>
      <p><strong>Proveedor:</strong> <?= h($supplier) ?></p>
      <?php if ($taxProfile['mode'] === 'retencion'): ?>
      <p><strong>Boleta de honorarios</strong> <?= h(t_es('em_dash')) ?> <strong><?= h(t_es('n_ordinal_colon')) ?></strong>
        <?= $invoiceNumberIn > 0 ? h((string) $invoiceNumberIn) : h($sinValor) ?></p>
      <?php else: ?>
      <p><strong><?= h(t_es('factura')) ?></strong> <?= h(t_es('em_dash')) ?> <strong><?= h(t_es('n_ordinal_colon')) ?></strong>
        <?= $invoiceNumberIn > 0 ? h((string) $invoiceNumberIn) : h($sinValor) ?></p>
      <?php endif; ?>
      <?php if (($po['phone'] ?? '') !== '' || ($po['email'] ?? '') !== ''): ?>
      <p><strong>Contacto:</strong> <?= h((string) $po['phone']) ?> <?= h((string) $po['email']) ?></p>
      <?php endif; ?>
    </div>
    <div>
      <?php $reqNro = trim((string) ($po['seller_ref'] ?? '')); ?>
      <p><strong><?= h(t_es('nro_req')) ?>:</strong> <?= $reqNro !== '' ? h($reqNro) : h($sinValor) ?></p>
      <p><strong>Forma de pago:</strong> <?= h($paymentLabel) ?></p>
      <p><strong><?= h(t_es('n_cta')) ?></strong> <?= h($ctaLabel) ?></p>
      <p><strong><?= h(t_es('n_actv_monto')) ?></strong> <?= $budgetInline !== '' ? $budgetInline : h($sinValor) ?></p>
      <p><strong>Nota:</strong> <?= nl2br(h((string) ($po['note'] ?? ''))) ?></p>
    </div>
  </div>

  <table class="lines data">
    <thead>
      <tr>
        <th class="num">CANT.</th>
        <th><?= h(t_es('descripcion_col')) ?></th>
        <th class="num">PRECIO UNIT.</th>
        <th class="num">PRECIO TOTAL</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($lines as $ln): ?>
      <tr>
        <td class="num"><?= h((string) (int) $ln['qty']) ?></td>
        <td><?= h((string) $ln['product_name']) ?></td>
        <td class="num"><?= h($fmtPrintMoney((float) $ln['unit_price'], 2)) ?></td>
        <td class="num"><?= h($fmtPrintMoney((float) $ln['line_total'], 2)) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot>
      <tr>
        <td colspan="3" class="num"><strong>SUBTOTAL $</strong></td>
        <td class="num"><?= h($printBoletaFmt
            ? $fmtPrintMoney(round($taxProfile['subtotal']), 0)
            : $fmtPrintMoney($taxProfile['subtotal'], 2)) ?></td>
      </tr>
      <?php if ($taxProfile['mode'] === 'iva'): ?>
      <tr>
        <td colspan="3" class="num">IVA (19%)</td>
        <td class="num"><?= h($fmtPrintMoney($taxProfile['tax_amount'], 2)) ?></td>
      </tr>
      <?php elseif ($taxProfile['mode'] === 'exento'): ?>
      <tr>
        <td colspan="3" class="num">IVA (exento)</td>
        <td class="num"><?= h($fmtPrintMoney(0, 2)) ?></td>
      </tr>
      <?php else: ?>
      <tr>
        <td colspan="3" class="num">Impto. Retenido (<?= h($fmtPrintPct($taxProfile['tax_percent'])) ?>%) $</td>
        <td class="num"><?= h($fmtPrintMoney(round($taxProfile['tax_amount']), 0)) ?></td>
      </tr>
      <?php endif; ?>
      <tr>
        <td colspan="3" class="num"><strong>TOTAL $</strong></td>
        <td class="num"><strong><?= h($printBoletaFmt
            ? $fmtPrintMoney(round($taxProfile['total']), 0)
            : $fmtPrintMoney($taxProfile['total'], 2)) ?></strong></td>
      </tr>
    </tfoot>
  </table>

  <?php if ($showAllocationsDetailTable && $budgetRows !== []): ?>
  <div style="margin-top:1.25rem;padding:0.75rem;border:1px solid var(--border);border-radius:var(--radius);background:#fafcff;">
    <h3 style="margin:0 0 0.65rem;font-size:1rem;">
      Imputaciones a presupuesto<?= $isAnulada ? ' (historial — reversadas)' : '' ?>
    </h3>
    <?php if ($isAnulada): ?>
    <p style="margin:0 0 0.65rem;font-size:0.88rem;color:#991b1b;">
      Montos que estuvieron imputados; al anular la OC dejaron de consumir saldo.
    </p>
    <?php endif; ?>
    <table class="data" style="margin:0;font-size:0.88rem;">
      <thead>
        <tr>
          <th>N° Actividad</th>
          <th>Cuenta</th>
          <th class="num">Monto</th>
          <th>Nº Req.</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($budgetRows as $b): ?>
        <tr>
          <td><?= h((string) ($b['activity_number'] ?? '')) ?></td>
          <td><?= h(account_code_label(['account_id' => $b['account_code'] ?? 0, 'name' => $b['account_name'] ?? ''])) ?></td>
          <td class="num"><?= h(money_fmt((float) ($b['amount_used'] ?? 0))) ?></td>
          <td><?= trim((string) ($b['req_number'] ?? '')) !== '' ? h((string) $b['req_number']) : '—' ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

  <?php if ($hasPrintFooter): ?>
  <footer class="oc-print-footer">
    <div class="oc-print-pagenum no-print" aria-hidden="true"></div>
    <?php if ($deliveryHours !== '' || $deliveryAddr !== ''): ?>
    <div class="oc-footer-reception">
      <span class="oc-footer-line"><?= h($introReception) ?><?php if ($deliveryHours !== ''): ?> <?= h($deliveryHours) ?><?php endif; ?></span>
      <?php if ($deliveryAddr !== ''): ?>
      <span class="oc-footer-line"><?= h(t_es('print_reception_por')) ?> <?= h($deliveryAddr) ?></span>
      <?php endif; ?>
    </div>
    <?php endif; ?>
    <?php if ($contactName !== '' || $contactRole !== '' || $contactEmail !== ''): ?>
    <div class="oc-footer-contact">
      <?php if ($contactName !== ''): ?><?= h($contactName) ?><br><?php endif; ?>
      <?php if ($contactRole !== ''): ?><?= h($contactRole) ?><br><?php endif; ?>
      <?php if ($contactEmail !== ''): ?><?= h($contactEmail) ?><?php endif; ?>
    </div>
    <?php endif; ?>
    <?php if ($invoiceTo !== ''): ?>
    <div class="oc-footer-invoice">
      <strong><?= h(t_es('facturar_a')) ?>:</strong> <?= h($invoiceTo) ?>
    </div>
    <?php endif; ?>
    <?php if ($footerText !== ''): ?>
    <div class="oc-footer-copy"><?= h($footerText) ?></div>
    <?php endif; ?>
  </footer>
  <?php endif; ?>
</div>
</body>
</html>
