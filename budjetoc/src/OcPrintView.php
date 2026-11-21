<?php

declare(strict_types=1);

/** Carga datos y variables de vista para impresion / lectura de OC. */
final class OcPrintView
{
    /**
     * @return array<string, mixed>|null Variables para public/oc/inc/oc_print_render.php
     */
    public static function build(PDO $pdo, int $tenantId, int $purchaseOrderId): ?array
    {
        if ($purchaseOrderId <= 0) {
            return null;
        }

        $st = $pdo->prepare(
            'SELECT po.*, s.legal_name, s.trade_name, s.phone, s.email
             FROM purchase_orders po
             INNER JOIN suppliers s ON s.id = po.supplier_id
             WHERE po.id = ? AND po.tenant_id = ?'
        );
        $st->execute([$purchaseOrderId, $tenantId]);
        $po = $st->fetch(PDO::FETCH_ASSOC);
        if (!$po) {
            return null;
        }

        $st = $pdo->prepare(
            'SELECT product_code, product_name, qty, unit_price, (qty * unit_price) AS line_total
             FROM po_lines WHERE purchase_order_id = ? AND tenant_id = ? ORDER BY sort_order, id'
        );
        $st->execute([$purchaseOrderId, $tenantId]);
        $lines = $st->fetchAll(PDO::FETCH_ASSOC);

        $taxProfile = oc_tax_profile_resolve($po, $lines);
        $printBoletaFmt = ($taxProfile['doc_type'] ?? null) === 'boleta'
            || $taxProfile['mode'] === 'retencion';

        $st = $pdo->prepare('SELECT * FROM tenant_settings WHERE tenant_id = ?');
        $st->execute([$tenantId]);
        $ts = $st->fetch(PDO::FETCH_ASSOC) ?: [];

        $st = $pdo->prepare(
            'SELECT pba.amount AS amount_used, pba.req_number,
                    bh.activity_number, ac.account_id AS account_code, ac.name AS account_name,
                    bd.observation, (bd.quantity * bd.unit_price) AS line_total
             FROM po_budget_allocations pba
             INNER JOIN budget_details bd ON bd.id = pba.budget_detail_id
             INNER JOIN budget_headers bh ON bh.id = bd.budget_header_id
             INNER JOIN account_codes ac ON ac.id = bd.account_code_id
             WHERE pba.purchase_order_id = ? AND pba.tenant_id = ?
             ORDER BY bh.activity_number, bd.id'
        );
        $st->execute([$purchaseOrderId, $tenantId]);
        $budgetRows = $st->fetchAll(PDO::FETCH_ASSOC);

        $budgetInline = '';
        if ($budgetRows !== []) {
            $chunks = [];
            foreach ($budgetRows as $b) {
                $amt = round((float) $b['amount_used'], 2);
                $amtStr = abs($amt - round($amt)) < 0.005
                    ? (string) (int) round($amt)
                    : rtrim(rtrim(number_format($amt, 2, '.', ''), '0'), '.');
                $chunks[] = h((string) $b['activity_number']) . ' - ' . h($amtStr);
            }
            $budgetInline = implode('---', $chunks);
            if ($budgetInline !== '') {
                $budgetInline .= '---';
            }
        }

        $folioYear = (string) ($po['folio_year'] ?? date('Y'));
        $folioLabel = oc_folio_label($folioYear, (int) $po['numero_oc']);
        $isAnulada = (int) ($po['status'] ?? -1) === OcService::STATUS_ANULADA;
        $fmtOcDateTime = static function (?string $raw): string {
            $raw = trim((string) $raw);
            if ($raw === '') {
                return '—';
            }

            return substr($raw, 0, 16);
        };

        $sinValor = t_es('sin_valor');
        $supplier = trim((string) ($po['trade_name'] ?? '')) !== ''
            ? (string) $po['trade_name']
            : (string) $po['legal_name'];
        $payKey = trim((string) ($po['payment_terms'] ?? ''));
        $paymentLabels = [
            '0' => 'Seleccione',
            '8' => 'Abono 40%',
            '9' => 'Abono 50%',
            '6' => t_es('pago_15_dias'),
            '5' => t_es('pago_7_dias'),
            '2' => t_es('pago_30_dias'),
            '3' => 'Transferencia bancaria',
            '4' => t_es('credito'),
            '7' => 'Contado',
        ];
        $paymentLabel = $paymentLabels[$payKey] ?? ($payKey !== '' ? $payKey : $sinValor);
        $ctaLabel = AccountCodeCatalog::poCtaLabel($pdo, $tenantId, $po, $sinValor);

        $contactName = oc_strip_http_urls(trim((string) ($ts['print_contact_name'] ?? '')));
        $contactRole = oc_strip_http_urls(trim((string) ($ts['print_contact_role'] ?? '')));
        $contactEmail = oc_strip_http_urls(trim((string) ($ts['print_contact_email'] ?? '')));
        $deliveryHours = oc_strip_http_urls(trim((string) ($ts['print_delivery_hours'] ?? '')));
        $deliveryAddr = oc_strip_http_urls(trim((string) ($ts['print_delivery_address'] ?? '')));
        $introReception = t_es('print_reception_intro');
        if ($deliveryHours !== '' && stripos($deliveryHours, $introReception) === 0) {
            $deliveryHours = trim(substr($deliveryHours, strlen($introReception)), " \t\n\r:.");
        }
        $invoiceTo = oc_strip_http_urls(trim((string) ($ts['print_invoice_to'] ?? '')));
        $footerText = oc_strip_http_urls(trim((string) ($ts['print_footer_text'] ?? '')));

        return [
            'po' => $po,
            'lines' => $lines,
            'taxProfile' => $taxProfile,
            'printBoletaFmt' => $printBoletaFmt,
            'ts' => $ts,
            'budgetRows' => $budgetRows,
            'budgetInline' => $budgetInline,
            'folioYear' => $folioYear,
            'folioLabel' => $folioLabel,
            'company' => (string) ($ts['company_name'] ?? 'OC'),
            'isAnulada' => $isAnulada,
            'voidedAtLabel' => $fmtOcDateTime((string) ($po['voided_at'] ?? '')),
            'sinValor' => $sinValor,
            'supplier' => $supplier,
            'invoiceNumberIn' => (int) ($po['invoice_number_in'] ?? 0),
            'paymentLabel' => $paymentLabel,
            'ctaLabel' => $ctaLabel,
            'contactName' => $contactName,
            'contactRole' => $contactRole,
            'contactEmail' => $contactEmail,
            'deliveryHours' => $deliveryHours,
            'deliveryAddr' => $deliveryAddr,
            'introReception' => $introReception,
            'invoiceTo' => $invoiceTo,
            'footerText' => $footerText,
            'hasPrintFooter' => $deliveryHours !== '' || $deliveryAddr !== '' || $contactName !== ''
                || $contactRole !== '' || $contactEmail !== '' || $invoiceTo !== '' || $footerText !== '',
            'fmtPrintMoney' => static function (float $n, int $decimals) use ($printBoletaFmt): string {
                if ($printBoletaFmt) {
                    return number_format($n, $decimals, '.', ',');
                }

                return number_format($n, $decimals, ',', '.');
            },
            'fmtPrintPct' => static function (float $p): string {
                $s = number_format($p, 4, '.', '');
                $s = rtrim(rtrim($s, '0'), '.');

                return $s === '' ? '0' : $s;
            },
        ];
    }
}
