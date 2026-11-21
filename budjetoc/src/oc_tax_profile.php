<?php

declare(strict_types=1);

/**
 * Totales de OC seg�n tipo de documento (legado ver_factura / oc_create).
 *
 * - Factura afecta: SUBTOTAL + IVA 19% = TOTAL.
 * - Exento (glosa "exento de IVA"): sin IVA, TOTAL = SUBTOTAL.
 * - Boleta honorarios ("X % Impto. Retenido" / codigo servicio boleta_*):
 *   SUBTOTAL bruto, retencion = SUBTOTAL x X / 100, TOTAL = SUBTOTAL - retencion.
 *
 * @param array<int, array<string, mixed>> $lines Filas con product_code, product_name, line_total o qty*unit_price
 * @return array{
 *   mode: 'iva'|'exento'|'retencion',
 *   tax_percent: float,
 *   tax_amount: float,
 *   total: float,
 *   subtotal: float,
 *   tax_label: string
 * }
 */
function oc_product_code_is_servicio_boleta(string $code): bool
{
    $c = trim($code);
    if ($c === '') {
        return false;
    }
    if (preg_match('/^servicio\s+boleta_/iu', $c) === 1) {
        return true;
    }

    return preg_match('/^servicio_boleta_/iu', $c) === 1;
}

function oc_product_name_is_servicio_boleta(string $name): bool
{
    $n = trim($name);
    if ($n === '') {
        return false;
    }
    if (preg_match('/^servicio\s+boleta_/iu', $n) === 1) {
        return true;
    }

    return preg_match('/^servicio_boleta_/iu', $n) === 1;
}

/** @param array<string, mixed> $line */
function oc_line_is_servicio_boleta(array $line): bool
{
    $code = trim((string) ($line['product_code'] ?? ''));
    $name = trim((string) ($line['product_name'] ?? ''));

    return oc_product_code_is_servicio_boleta($code) || oc_product_name_is_servicio_boleta($name);
}

function oc_boleta_retencion_rate_percent(string $productName): float
{
    $n = trim($productName);
    if (preg_match('/\(\s*([0-9]+[.,]?[0-9]*)\s*%?\s*Impto\.?\s*Retenido/isu', $n, $m)) {
        return (float) str_replace(',', '.', $m[1]);
    }
    if (preg_match('/servicio\s+boleta_([0-9]+[.,]?[0-9]*)\s*%/iu', $n, $m)) {
        return (float) str_replace(',', '.', $m[1]);
    }
    if (preg_match('/servicio_boleta_([0-9]+[.,]?[0-9]*)\s*%/iu', $n, $m)) {
        return (float) str_replace(',', '.', $m[1]);
    }

    return 12.25;
}

/** @param array<int, array<string, mixed>> $lines */
function oc_tax_profile_from_po_lines(array $lines): array
{
    $subtotal = 0.0;
    foreach ($lines as $ln) {
        if (isset($ln['line_total'])) {
            $subtotal += (float) $ln['line_total'];
        } else {
            $subtotal += (float) ($ln['qty'] ?? 0) * (float) ($ln['unit_price'] ?? 0);
        }
    }
    $subtotal = round($subtotal, 4);

    $names = array_values(
        array_filter(
            array_map(static fn ($ln) => trim((string) ($ln['product_name'] ?? '')), $lines),
            static fn (string $n) => $n !== ''
        )
    );

    if ($names !== []) {
        $allExento = true;
        foreach ($names as $n) {
            if (!preg_match('/exento\s+de\s+IVA/i', $n)) {
                $allExento = false;
                break;
            }
        }
        if ($allExento) {
            return oc_tax_profile_exento($subtotal);
        }
    }

    if ($lines !== []) {
        $allBoleta = true;
        foreach ($lines as $ln) {
            if (!oc_line_is_servicio_boleta($ln)) {
                $allBoleta = false;
                break;
            }
        }
        if ($allBoleta) {
            $rates = [];
            foreach ($lines as $ln) {
                $rates[] = oc_boleta_retencion_rate_percent((string) ($ln['product_name'] ?? ''));
            }
            if ($rates !== []) {
                $r0 = $rates[0];
                foreach ($rates as $r) {
                    if (abs((float) $r - $r0) > 0.001) {
                        return oc_tax_profile_iva($subtotal);
                    }
                }

                return oc_tax_profile_retencion($subtotal, $r0);
            }
        }
    }

    if ($lines !== []) {
        $rates = [];
        foreach ($lines as $ln) {
            $n = trim((string) ($ln['product_name'] ?? ''));
            if (preg_match('/\(\s*([0-9]+[.,]?[0-9]*)\s*%?\s*Impto\.?\s*Retenido/isu', $n, $m)) {
                $rates[] = (float) str_replace(',', '.', $m[1]);
            } elseif (oc_product_name_is_servicio_boleta($n)) {
                $rates[] = oc_boleta_retencion_rate_percent($n);
            } else {
                $rates[] = null;
            }
        }
        if ($rates !== [] && !in_array(null, $rates, true)) {
            $r0 = $rates[0];
            foreach ($rates as $r) {
                if (abs((float) $r - $r0) > 0.001) {
                    return oc_tax_profile_iva($subtotal);
                }
            }

            return oc_tax_profile_retencion($subtotal, $r0);
        }
    }

    return oc_tax_profile_iva($subtotal);
}

/** @return array{mode: 'iva', tax_percent: float, tax_amount: float, total: float, subtotal: float, tax_label: string} */
function oc_tax_profile_iva(float $subtotal): array
{
    $neto = round($subtotal, 2);
    $iva = (float) (int) round($neto * 19 / 100);
    $total = (float) (int) round($neto + $iva);

    return [
        'mode' => 'iva',
        'tax_percent' => 19.0,
        'tax_amount' => $iva,
        'total' => $total,
        'subtotal' => $neto,
        'tax_label' => 'IVA',
    ];
}

/** @return array{mode: 'exento', tax_percent: float, tax_amount: float, total: float, subtotal: float, tax_label: string} */
function oc_tax_profile_exento(float $subtotal): array
{
    return [
        'mode' => 'exento',
        'tax_percent' => 0.0,
        'tax_amount' => 0.0,
        'total' => round($subtotal, 2),
        'subtotal' => $subtotal,
        'tax_label' => 'IVA',
    ];
}

/** @return array{mode: 'retencion', tax_percent: float, tax_amount: float, total: float, subtotal: float, tax_label: string} */
function oc_tax_profile_retencion(float $subtotal, float $ratePercent): array
{
    $ret = round($subtotal * $ratePercent / 100, 2);

    return [
        'mode' => 'retencion',
        'tax_percent' => $ratePercent,
        'tax_amount' => $ret,
        'total' => round($subtotal - $ret, 2),
        'subtotal' => $subtotal,
        'tax_label' => 'Imp. retenido',
    ];
}
