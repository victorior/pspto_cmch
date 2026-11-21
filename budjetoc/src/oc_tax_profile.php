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

/**
 * Default de retencion Boleta por ano comercial (Ley 21.133).
 * Mapa minimo: 2026=15,25; 2027=16; 2028=17.
 * Anos menores a 2026 → 15,25; anos mayores a 2028 → 17 hasta ampliar mapa.
 */
function oc_boleta_retencion_default_for_year(int $year): float
{
    if ($year <= 2026) {
        return 15.25;
    }
    if ($year === 2027) {
        return 16.0;
    }

    return 17.0;
}

/** @return array<int, float> */
function oc_boleta_retencion_rate_map(): array
{
    return [
        2026 => 15.25,
        2027 => 16.0,
        2028 => 17.0,
    ];
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

/**
 * Campos base a persistir segun tipo de documento (stories 1.1 / 1.2 / 1.3).
 * Factura + rama iva|exento (null/omitido → iva/19); Boleta ignora $facturaMode
 * y usa retencion + % explícito o default por folio_year (Ley 21.133).
 *
 * @return array{tax_doc_type: string, tax_mode: string, tax_percent: float}
 */
function oc_tax_profile_base_fields(
    string $docType,
    ?string $facturaMode = null,
    ?int $folioYear = null,
    ?float $retencionPercent = null
): array {
    $docType = strtolower(trim($docType));
    if ($docType === 'boleta') {
        $year = $folioYear ?? (int) date('Y');
        $rate = $retencionPercent ?? oc_boleta_retencion_default_for_year($year);

        return [
            'tax_doc_type' => 'boleta',
            'tax_mode' => 'retencion',
            'tax_percent' => $rate,
        ];
    }
    if ($docType === 'factura') {
        $mode = $facturaMode === null ? '' : strtolower(trim($facturaMode));
        if ($mode === '' || $mode === 'iva') {
            return oc_tax_profile_iva_fields();
        }
        if ($mode === 'exento') {
            return oc_tax_profile_exento_fields();
        }

        throw new InvalidArgumentException('Rama de factura invalida: use iva o exento.');
    }

    throw new InvalidArgumentException('Tipo de documento invalido: use factura o boleta.');
}

/** @return array{tax_doc_type: string, tax_mode: string, tax_percent: float} */
function oc_tax_profile_iva_fields(): array
{
    return [
        'tax_doc_type' => 'factura',
        'tax_mode' => 'iva',
        'tax_percent' => 19.0,
    ];
}

/** @return array{tax_doc_type: string, tax_mode: string, tax_percent: float} */
function oc_tax_profile_exento_fields(): array
{
    return [
        'tax_doc_type' => 'factura',
        'tax_mode' => 'exento',
        'tax_percent' => 0.0,
    ];
}

/**
 * Mapeo frontera formulario → perfil (mismas validaciones/mensajes que oc_new POST).
 *
 * @return array{tax_doc_type: string, tax_mode: string, tax_percent: float}
 * @throws RuntimeException
 */
function oc_tax_profile_from_form_input(
    string $taxDocType,
    string $taxFacturaMode = '',
    ?int $folioYear = null,
    ?string $retencionPercentRaw = null
): array {
    $taxDocType = strtolower(trim($taxDocType));
    $taxFacturaMode = strtolower(trim($taxFacturaMode));
    if ($taxDocType !== 'factura' && $taxDocType !== 'boleta') {
        throw new RuntimeException('Tipo de documento invalido.');
    }
    if ($taxDocType === 'factura') {
        if ($taxFacturaMode !== 'iva' && $taxFacturaMode !== 'exento') {
            throw new RuntimeException('Debe indicar si la factura es Afecta IVA o Exenta.');
        }

        return oc_tax_profile_base_fields('factura', $taxFacturaMode);
    }

    $year = $folioYear ?? (int) date('Y');
    $raw = $retencionPercentRaw === null ? '' : trim(str_replace(',', '.', (string) $retencionPercentRaw));
    if ($raw === '') {
        return oc_tax_profile_base_fields('boleta', null, $year, null);
    }
    if (!is_numeric($raw)) {
        throw new RuntimeException('El % de retencion debe ser un numero valido.');
    }
    $pct = (float) $raw;
    if ($pct <= 0.0 || $pct > 100.0) {
        throw new RuntimeException('El % de retencion debe ser mayor a 0 y menor o igual a 100.');
    }

    return oc_tax_profile_base_fields('boleta', null, $year, $pct);
}

/** True si la OC tiene perfil tributario persistido (tipo o modo no vacio). */
function oc_tax_profile_is_persisted(array $poRow): bool
{
    $docType = trim((string) ($poRow['tax_doc_type'] ?? ''));
    $mode = trim((string) ($poRow['tax_mode'] ?? ''));

    return $docType !== '' || $mode !== '';
}

/** @param array<int, array<string, mixed>> $lines */
function oc_tax_lines_subtotal(array $lines): float
{
    $subtotal = 0.0;
    foreach ($lines as $ln) {
        if (isset($ln['line_total'])) {
            $subtotal += (float) $ln['line_total'];
        } else {
            $subtotal += (float) ($ln['qty'] ?? 0) * (float) ($ln['unit_price'] ?? 0);
        }
    }

    return round($subtotal, 4);
}

/**
 * Resuelve perfil: persistido si hay tipo/modo; si no, inferencia legado por lineas.
 *
 * @param array<string, mixed> $poRow Fila purchase_orders (tax_* opcionales)
 * @param array<int, array<string, mixed>> $lines
 * @return array{
 *   mode: 'iva'|'exento'|'retencion',
 *   tax_percent: float,
 *   tax_amount: float,
 *   total: float,
 *   subtotal: float,
 *   tax_label: string,
 *   doc_type: 'factura'|'boleta'|null
 * }
 */
function oc_tax_profile_resolve(array $poRow, array $lines): array
{
    $subtotal = oc_tax_lines_subtotal($lines);
    $docType = strtolower(trim((string) ($poRow['tax_doc_type'] ?? '')));
    $mode = strtolower(trim((string) ($poRow['tax_mode'] ?? '')));
    $percentRaw = $poRow['tax_percent'] ?? null;
    $percent = ($percentRaw !== null && $percentRaw !== '') ? (float) $percentRaw : null;

    if ($docType !== '' || $mode !== '') {
        if ($docType === 'boleta' || $mode === 'retencion') {
            $rate = $percent ?? oc_boleta_retencion_rate_percent('');
            $prof = oc_tax_profile_retencion($subtotal, $rate);
            $prof['doc_type'] = 'boleta';

            return $prof;
        }
        if ($mode === 'exento') {
            $prof = oc_tax_profile_exento($subtotal);
            $prof['doc_type'] = 'factura';

            return $prof;
        }
        $prof = oc_tax_profile_iva($subtotal);
        $prof['doc_type'] = 'factura';

        return $prof;
    }

    $prof = oc_tax_profile_from_po_lines($lines);
    $prof['doc_type'] = $prof['mode'] === 'retencion' ? 'boleta' : 'factura';

    return $prof;
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
