<?php

declare(strict_types=1);

/** Export CSV anual de actividades (streaming, un ano presupuestario). */
final class BudgetExportService
{
    private const EXPORT_TTL_SECONDS = 600;

    /**
     * @return array{ok: true, headers: list<array<string, mixed>>, dept: array{id: int, name: string, type: string}, budget_year: int, filename: string}|array{ok: false, error: string, code: int}
     */
    public static function resolveExport(PDO $pdo, int $tid, int $uid, array $query, bool $idsOnly = false): array
    {
        $yCal = (int) date('Y');
        $budgetYear = isset($query['budget_year']) ? (int) $query['budget_year'] : $yCal;
        if ($budgetYear < $yCal - 1 || $budgetYear > $yCal + 1) {
            $budgetYear = $yCal;
        }

        $selectedDepartmentId = isset($query['dept']) ? (int) $query['dept'] : 0;
        if ($selectedDepartmentId <= 0) {
            return ['ok' => false, 'error' => 'Departamento requerido.', 'code' => 400];
        }

        $isAdmin = UserPermissions::isBudgetTenantAdmin($pdo, $uid, $tid);
        $isEncargado = !$isAdmin;
        $allowedDeptIds = UserPermissions::allowedDepartmentIds($pdo, $uid, $tid);
        $selectedDept = DepartmentCatalog::getById($pdo, $selectedDepartmentId, $tid);
        if ($selectedDept === null) {
            return ['ok' => false, 'error' => 'Departamento no encontrado.', 'code' => 404];
        }

        if ($isEncargado) {
            if ($allowedDeptIds === null || $allowedDeptIds === []) {
                return ['ok' => false, 'error' => 'Sin permisos de departamento.', 'code' => 403];
            }
            if (!in_array($selectedDepartmentId, array_map('intval', $allowedDeptIds), true)) {
                return ['ok' => false, 'error' => 'No tiene permiso para exportar actividades de este departamento.', 'code' => 403];
            }
        }

        $selectedType = (string) ($selectedDept['type'] ?? 'estandar');
        $esDivision = $selectedType === 'estandar';
        $adminView = isset($query['admin_view']) && $query['admin_view'] === 'by_division'
            ? 'by_division'
            : 'by_centros_costo';

        if ($esDivision) {
            $view = $isEncargado ? 'by_division' : $adminView;
            $headers = BudgetService::listByMonthForEncargadoDivision(
                $pdo,
                $tid,
                null,
                [$selectedDepartmentId],
                $view,
                $budgetYear,
                $idsOnly
            );
        } else {
            $headers = BudgetService::listByMonthFiltered($pdo, $tid, null, [$selectedDepartmentId], $budgetYear, $idsOnly);
            if ($isEncargado && !$idsOnly) {
                $headers = BudgetService::filterRowsCreatedByDepartment($headers, $selectedDepartmentId);
            }
            if ($isEncargado && $idsOnly) {
                $headers = self::filterHeaderIdsCreatedByDepartment($pdo, $tid, $headers, $selectedDepartmentId);
            }
        }

        $deptSlug = preg_replace('/[^a-zA-Z0-9_-]+/', '_', (string) ($selectedDept['name'] ?? 'dept')) ?: 'dept';
        $filename = 'presupuesto_' . $budgetYear . '_' . $deptSlug . '.csv';

        return [
            'ok' => true,
            'headers' => $headers,
            'dept' => $selectedDept,
            'budget_year' => $budgetYear,
            'filename' => $filename,
        ];
    }

    /**
     * Guarda export preparado en sesion para evitar doble resolveExport (meta + descarga).
     *
     * @param array{ok: true, headers: list<array<string, mixed>>, budget_year: int, filename: string} $resolved
     */
    public static function storePreparedExport(int $tid, int $uid, array $resolved): string
    {
        $token = bin2hex(random_bytes(16));
        if (!isset($_SESSION['budjetoc_export_prep']) || !is_array($_SESSION['budjetoc_export_prep'])) {
            $_SESSION['budjetoc_export_prep'] = [];
        }
        $now = time();
        foreach ($_SESSION['budjetoc_export_prep'] as $k => $entry) {
            if (!is_array($entry) || (int) ($entry['expires'] ?? 0) < $now) {
                unset($_SESSION['budjetoc_export_prep'][$k]);
            }
        }
        $_SESSION['budjetoc_export_prep'][$token] = [
            'tid' => $tid,
            'uid' => $uid,
            'header_ids' => array_map(static fn (array $row): int => (int) $row['id'], $resolved['headers']),
            'budget_year' => (int) $resolved['budget_year'],
            'filename' => (string) $resolved['filename'],
            'expires' => $now + self::EXPORT_TTL_SECONDS,
        ];

        return $token;
    }

    /**
     * @return array{ok: true, headers: list<array<string, mixed>>, budget_year: int, filename: string}|array{ok: false, error: string, code: int}
     */
    public static function loadPreparedExport(PDO $pdo, int $tid, int $uid, string $token): array
    {
        $token = trim($token);
        if ($token === '') {
            return ['ok' => false, 'error' => 'Token de exportacion requerido.', 'code' => 400];
        }
        $entry = $_SESSION['budjetoc_export_prep'][$token] ?? null;
        if (!is_array($entry)) {
            return ['ok' => false, 'error' => 'Exportacion expirada. Vuelva a iniciar la descarga.', 'code' => 410];
        }
        if ((int) ($entry['expires'] ?? 0) < time()) {
            unset($_SESSION['budjetoc_export_prep'][$token]);

            return ['ok' => false, 'error' => 'Exportacion expirada. Vuelva a iniciar la descarga.', 'code' => 410];
        }
        if ((int) ($entry['tid'] ?? 0) !== $tid || (int) ($entry['uid'] ?? 0) !== $uid) {
            return ['ok' => false, 'error' => 'Token de exportacion invalido.', 'code' => 403];
        }
        $headerIds = array_map('intval', $entry['header_ids'] ?? []);
        $headers = BudgetService::listHeadersByIds($pdo, $tid, $headerIds);
        unset($_SESSION['budjetoc_export_prep'][$token]);

        return [
            'ok' => true,
            'headers' => $headers,
            'budget_year' => (int) ($entry['budget_year'] ?? (int) date('Y')),
            'filename' => (string) ($entry['filename'] ?? 'presupuesto.csv'),
        ];
    }

    /**
     * Filtra ids de cabecera para encargado de coordinacion (equivalente a filterRowsCreatedByDepartment).
     *
     * @param list<array{id: int}> $headerRows
     * @return list<array{id: int}>
     */
    private static function filterHeaderIdsCreatedByDepartment(
        PDO $pdo,
        int $tenantId,
        array $headerRows,
        int $departmentId,
    ): array {
        if ($headerRows === []) {
            return [];
        }
        $ids = array_map(static fn (array $row): int => (int) $row['id'], $headerRows);
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $st = $pdo->prepare(
            "SELECT id FROM budget_headers
             WHERE tenant_id = ? AND id IN ({$ph})
             AND (
               (COALESCE(created_by, 0) > 0 AND created_by = ?)
               OR (COALESCE(created_by, 0) = 0 AND department_id = ?)
             )"
        );
        $st->execute(array_merge([$tenantId], $ids, [$departmentId, $departmentId]));
        $allowed = [];
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $allowed[(int) $row['id']] = true;
        }
        $out = [];
        foreach ($headerRows as $row) {
            $id = (int) $row['id'];
            if (isset($allowed[$id])) {
                $out[] = ['id' => $id];
            }
        }

        return $out;
    }

    /**
     * @param list<array<string, mixed>>|list<array{id: int}> $headerRows
     * @return array{activity_count: int, row_count: int}
     */
    public static function countCsvRows(PDO $pdo, array $headerRows): array
    {
        $headerIds = array_map(static fn (array $row): int => (int) $row['id'], $headerRows);
        $activityCount = count($headerIds);
        if ($headerIds === []) {
            return ['activity_count' => 0, 'row_count' => 0];
        }

        $ph = implode(',', array_fill(0, count($headerIds), '?'));
        $st = $pdo->prepare(
            "SELECT budget_header_id AS hid, COUNT(*) AS c
             FROM budget_details
             WHERE budget_header_id IN ({$ph})
             GROUP BY budget_header_id"
        );
        $st->execute($headerIds);
        $detailCounts = [];
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $detailCounts[(int) $row['hid']] = (int) $row['c'];
        }

        $rowCount = 0;
        foreach ($headerIds as $hid) {
            $rowCount += max(1, $detailCounts[$hid] ?? 0);
        }

        return ['activity_count' => $activityCount, 'row_count' => $rowCount];
    }

    /** @param resource $out */
    public static function writeCsvHeader($out): void
    {
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, [
            'Mes',
            'Ano presupuesto',
            'Division',
            'Centro de Costo',
            'C. costo catalogo',
            'Actividad',
            'N Actividad',
            'Observacion actividad',
            'Presupuesto actividad',
            'Ocupado actividad',
            'Quedan actividad',
            'Cuenta',
            'Nombre cuenta',
            'Cantidad',
            'Precio unitario',
            'Total subactividad',
            'Observacion subactividad',
        ], ';');
    }

    /**
     * @param resource $out
     * @param list<array<string, mixed>> $headerRows
     */
    public static function streamCsvBody(PDO $pdo, $out, array $headerRows, int $budgetYear): int
    {
        $months = [
            1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril', 5 => 'Mayo', 6 => 'Junio',
            7 => 'Julio', 8 => 'Agosto', 9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre',
        ];
        $exportDecimal = static fn (float $n): string => number_format($n, 2, '.', '');
        $rowsWritten = 0;

        $headerIds = array_map(static fn (array $row): int => (int) $row['id'], $headerRows);
        $detailsByHeader = BudgetService::detailsGroupedByHeaderIds($pdo, $headerIds);

        foreach ($headerRows as $row) {
            $hid = (int) $row['id'];
            $monthNum = (int) ($row['month'] ?? 0);
            $monthLabel = $months[$monthNum] ?? (string) $monthNum;
            $totalRow = round((float) ($row['total'] ?? 0), 2);
            $occNum = isset($row['occupied_amount']) ? round((float) $row['occupied_amount'], 2) : null;
            $quedanNum = $occNum !== null ? max(0.0, round($totalRow - $occNum, 2)) : null;
            $ccostoLabel = utf8_normalize(budget_ccosto_coordinacion_label($row));

            $activityBase = [
                $monthLabel,
                (string) $budgetYear,
                utf8_normalize((string) ($row['division_name'] ?? '')),
                utf8_normalize((string) ($row['department_name'] ?? '')),
                $ccostoLabel,
                utf8_normalize((string) ($row['activity_name'] ?? '')),
                (string) ($row['activity_number'] ?? ''),
                utf8_normalize(trim((string) ($row['observations'] ?? ''))),
                $exportDecimal($totalRow),
                $occNum !== null ? $exportDecimal($occNum) : '',
                $quedanNum !== null ? $exportDecimal($quedanNum) : '',
            ];

            $details = $detailsByHeader[$hid] ?? [];

            if ($details === []) {
                fputcsv($out, array_merge($activityBase, ['', '', '', '', '', '']), ';');
                $rowsWritten++;
            } else {
                foreach ($details as $detail) {
                    $qty = round((float) ($detail['quantity'] ?? 0), 2);
                    $unit = round((float) ($detail['unit_price'] ?? 0), 2);
                    $lineTotal = round($qty * $unit, 2);
                    fputcsv($out, array_merge($activityBase, [
                        (string) ($detail['account_id'] ?? ''),
                        utf8_normalize((string) ($detail['account_name'] ?? '')),
                        $exportDecimal($qty),
                        $exportDecimal($unit),
                        $exportDecimal($lineTotal),
                        utf8_normalize(trim((string) ($detail['observation'] ?? ''))),
                    ]), ';');
                    $rowsWritten++;
                }
            }

            if ($rowsWritten % 25 === 0) {
                fflush($out);
                if (function_exists('flush')) {
                    flush();
                }
            }
        }

        fflush($out);
        if (function_exists('flush')) {
            flush();
        }

        return $rowsWritten;
    }
}
