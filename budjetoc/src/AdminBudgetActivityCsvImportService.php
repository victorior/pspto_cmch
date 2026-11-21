<?php

declare(strict_types=1);

/**
 * Plantilla CSV (y futuros import) de actividades de Presupuesto para super admin.
 * Story 1: solo sampleCsvContent(); validación/alta en historias posteriores.
 */
final class AdminBudgetActivityCsvImportService
{
    /** UTF-8 BOM para que Excel preserve tildes y eñes. */
    public const UTF8_BOM = "\xEF\xBB\xBF";

    /**
     * Encabezados canónicos de la plantilla (separador ;).
     *
     * @return list<string>
     */
    public static function templateHeaders(): array
    {
        return [
            'num',
            'year',
            'month',
            'activity_name',
            'creator_type',
            'creator_name',
            'division_name',
            'cost_center_name',
            'observations',
            'account_code',
            'qty',
            'unit_price',
            'detail_label',
        ];
    }

    /** Contenido de plantilla descargable: BOM + encabezados + 2 filas de ejemplo. */
    public static function sampleCsvContent(): string
    {
        $year = (string) (int) date('Y');
        $rows = [
            self::templateHeaders(),
            [
                '9001',
                $year,
                '3',
                'Material de oficina',
                'division',
                'Media Superior',
                'Media Superior',
                'Docencia',
                'Ejemplo division - borrar antes de cargar',
                '210101',
                '1',
                '15000',
                'Resmas',
            ],
            [
                '9002',
                $year,
                '3',
                'Apoyo pedagógico Ñuñoa',
                'coordinacion',
                'Gestión Estadio',
                'Estadio',
                '',
                'Ejemplo coordinacion - borrar antes de cargar',
                '210102',
                '2',
                '25000',
                'Kits',
            ],
        ];

        $lines = [];
        foreach ($rows as $row) {
            $lines[] = implode(';', array_map(static fn (string $cell): string => self::escapeCsvCell($cell), $row));
        }

        return self::UTF8_BOM . implode("\r\n", $lines) . "\r\n";
    }

    private static function escapeCsvCell(string $cell): string
    {
        if (
            str_contains($cell, ';')
            || str_contains($cell, '"')
            || str_contains($cell, "\n")
            || str_contains($cell, "\r")
        ) {
            return '"' . str_replace('"', '""', $cell) . '"';
        }

        return $cell;
    }
}
