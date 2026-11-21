<?php

declare(strict_types=1);

/**
 * Importa presupuesto 2026 desde records2026.csv (export Excel).
 * Agrupa por Num -> budget_headers; cada fila -> budget_details.
 */
final class Records2026ImportService
{
    /** @var array<string, string> */
    private const DIVISION_ALIASES = [
        'msuperior' => 'media superior',
        'minicial' => 'media inicial',
        'cbasica' => 'c basica',
        'cinicial' => 'c inicial',
        'mdsuperior' => 'media superior',
        'gestionestadio' => 'estadio',
        'administracion' => 'administracion.',
        'direccion' => 'direccion',
        'direccioncc' => 'direccion',
    ];

    /** @var array<string, string> */
    private const COST_CENTER_ALIASES = [
        'direccion' => 'direccioncc',
        'gestionestadio' => 'docencia',
    ];


    /**
     * @return array{
     *   headers_created: int,
     *   headers_skipped: int,
     *   details_created: int,
     *   groups: int,
     *   errors: list<string>
     * }
     */
    public static function importFromCsv(
        PDO $pdo,
        string $csvPath,
        int $year,
        ?int $tenantId = null,
        bool $dryRun = false,
    ): array {
        if (!is_file($csvPath)) {
            throw new InvalidArgumentException('No se encuentra el archivo: ' . $csvPath);
        }

        $rows = self::readCsv($csvPath);
        if ($rows === []) {
            throw new RuntimeException('El CSV no tiene filas de datos.');
        }

        if ($tenantId === null) {
            $tenantId = self::detectTenantId($pdo, $rows);
        }
        if ($tenantId === null || $tenantId <= 0) {
            throw new RuntimeException('No se pudo determinar el tenant (use BUD_IMPORT_TENANT=iae).');
        }

        $maps = self::loadCatalogMaps($pdo, $tenantId);
        $groups = self::groupByNum($rows);

        $stats = [
            'headers_created' => 0,
            'headers_skipped' => 0,
            'details_created' => 0,
            'groups' => count($groups),
            'errors' => [],
        ];

        $maxNum = 0;
        foreach ($groups as $num => $groupRows) {
            usort($groupRows, static fn (array $a, array $b): int => (int) $a['Cod'] <=> (int) $b['Cod']);
            $lead = $groupRows[0];
            $activityNumber = $num . '-' . $year;
            $maxNum = max($maxNum, (int) $num);

            $stExists = $pdo->prepare(
                'SELECT id FROM budget_headers WHERE tenant_id = ? AND activity_number = ? LIMIT 1'
            );
            $stExists->execute([$tenantId, $activityNumber]);
            if ($stExists->fetchColumn()) {
                $stats['headers_skipped']++;
                continue;
            }

            try {
                $header = self::buildHeader($pdo, $tenantId, $lead, $maps);
                $details = [];
                foreach ($groupRows as $row) {
                    $details[] = self::buildDetail($row, $maps);
                }
            } catch (Throwable $e) {
                $stats['errors'][] = "Num {$num}: " . $e->getMessage();
                continue;
            }

            if ($dryRun) {
                $stats['headers_created']++;
                $stats['details_created'] += count($details);
                continue;
            }

            $pdo->beginTransaction();
            try {
                $createdBy = $header['created_by'] ?? null;
                unset($header['created_by']);
                $hasCreatedBy = BudgetService::hasCreatedByColumn($pdo);
                $hasBudgetYear = BudgetService::hasBudgetYearColumn($pdo);
                if ($hasBudgetYear && $hasCreatedBy) {
                    $pdo->prepare(
                        'INSERT INTO budget_headers (tenant_id, activity_number, budget_year, month, cost_center_id, activity_id, division_id, department_id, observations, created_by)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                    )->execute([
                        $tenantId,
                        $activityNumber,
                        $year,
                        (int) $header['month'],
                        $header['cost_center_id'],
                        (int) $header['activity_id'],
                        $header['division_id'],
                        $header['department_id'],
                        $header['observations'],
                        $createdBy,
                    ]);
                } elseif ($hasBudgetYear) {
                    $pdo->prepare(
                        'INSERT INTO budget_headers (tenant_id, activity_number, budget_year, month, cost_center_id, activity_id, division_id, department_id, observations)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
                    )->execute([
                        $tenantId,
                        $activityNumber,
                        $year,
                        (int) $header['month'],
                        $header['cost_center_id'],
                        (int) $header['activity_id'],
                        $header['division_id'],
                        $header['department_id'],
                        $header['observations'],
                    ]);
                } elseif ($hasCreatedBy) {
                    $pdo->prepare(
                        'INSERT INTO budget_headers (tenant_id, activity_number, month, cost_center_id, activity_id, division_id, department_id, observations, created_by)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
                    )->execute([
                        $tenantId,
                        $activityNumber,
                        (int) $header['month'],
                        $header['cost_center_id'],
                        (int) $header['activity_id'],
                        $header['division_id'],
                        $header['department_id'],
                        $header['observations'],
                        $createdBy,
                    ]);
                } else {
                    $pdo->prepare(
                        'INSERT INTO budget_headers (tenant_id, activity_number, month, cost_center_id, activity_id, division_id, department_id, observations)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
                    )->execute([
                        $tenantId,
                        $activityNumber,
                        (int) $header['month'],
                        $header['cost_center_id'],
                        (int) $header['activity_id'],
                        $header['division_id'],
                        $header['department_id'],
                        $header['observations'],
                    ]);
                }
                $hid = (int) $pdo->lastInsertId();
                $ins = $pdo->prepare(
                    'INSERT INTO budget_details (budget_header_id, account_code_id, quantity, unit_price, observation)
                     VALUES (?, ?, ?, ?, ?)'
                );
                foreach ($details as $d) {
                    $ins->execute([$hid, $d['account_code_id'], $d['quantity'], $d['unit_price'], $d['observation']]);
                    $stats['details_created']++;
                }
                $pdo->commit();
                $stats['headers_created']++;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $stats['errors'][] = "Num {$num}: " . $e->getMessage();
            }
        }

        if (!$dryRun && $maxNum > 0) {
            self::syncActivitySequence($pdo, $tenantId, $year, $maxNum);
        }

        return $stats;
    }

    /** @return list<array<string, string>> */
    private static function readCsv(string $path): array
    {
        $contents = utf8_file_contents($path);
        $fh = fopen('php://memory', 'rb+');
        if ($fh === false) {
            throw new RuntimeException('No se pudo abrir buffer CSV');
        }
        fwrite($fh, $contents);
        rewind($fh);
        $header = null;
        $rows = [];
        while (($data = fgetcsv($fh, 0, ';')) !== false) {
            if ($header === null) {
                $header = array_map(static fn (string $h): string => trim(preg_replace('/^\xEF\xBB\xBF/', '', $h) ?? $h), $data);
                continue;
            }
            if ($data === [null] || $data === []) {
                continue;
            }
            $row = [];
            foreach ($header as $i => $col) {
                $row[$col] = isset($data[$i]) ? trim((string) $data[$i]) : '';
            }
            if (($row['Num'] ?? '') === '') {
                continue;
            }
            $rows[] = $row;
        }
        fclose($fh);

        return $rows;
    }

    /** @param list<array<string, string>> $rows */
    private static function detectTenantId(PDO $pdo, array $rows): ?int
    {
        $creatorId = (int) ($rows[0]['id'] ?? 0);
        if ($creatorId <= 0) {
            return null;
        }
        $st = $pdo->prepare('SELECT tenant_id FROM departments WHERE id = ? LIMIT 1');
        $st->execute([$creatorId]);
        $tid = $st->fetchColumn();

        return $tid !== false ? (int) $tid : null;
    }

    public static function detectTenantFromCsv(PDO $pdo, string $csvPath): ?int
    {
        $rows = self::readCsv($csvPath);
        if ($rows === []) {
            return null;
        }

        return self::detectTenantId($pdo, $rows);
    }

    /**
     * @param list<array<string, string>> $rows
     * @return array<string, list<array<string, string>>>
     */
    private static function groupByNum(array $rows): array
    {
        $groups = [];
        foreach ($rows as $row) {
            $num = trim((string) ($row['Num'] ?? ''));
            if ($num === '') {
                continue;
            }
            $groups[$num][] = $row;
        }

        return $groups;
    }

    /**
     * @return array{
     *   cost_centers: array<string, int>,
     *   activities: array<string, int>,
     *   accounts: array<string, int>,
     *   accounts_by_id: array<int, int>,
     *   divisions: array<string, array{id: int, type: string, parent_id: int|null}>,
     *   departments: array<string, array{id: int, type: string, parent_id: int|null}>
     * }
     */
    private static function loadCatalogMaps(PDO $pdo, int $tenantId): array
    {
        $maps = [
            'cost_centers' => [],
            'cost_center_ids' => [],
            'activities' => [],
            'accounts' => [],
            'accounts_by_id' => [],
            'divisions' => [],
            'departments' => [],
        ];

        $st = $pdo->prepare('SELECT id, name FROM cost_centers WHERE tenant_id = ?');
        $st->execute([$tenantId]);
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $ccId = (int) $row['id'];
            $maps['cost_centers'][self::normKey((string) $row['name'])] = $ccId;
            $maps['cost_center_ids'][$ccId] = true;
        }

        $st = $pdo->prepare('SELECT id, name FROM activities WHERE tenant_id = ?');
        $st->execute([$tenantId]);
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $maps['activities'][self::normKey((string) $row['name'])] = (int) $row['id'];
        }

        $st = $pdo->prepare('SELECT id, account_id, name FROM account_codes WHERE tenant_id = ?');
        $st->execute([$tenantId]);
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $pk = (int) $row['id'];
            $maps['accounts'][self::normKey((string) $row['name'])] = $pk;
            $maps['accounts_by_id'][(int) $row['account_id']] = $pk;
        }

        $st = $pdo->prepare('SELECT id, name, type, parent_id FROM departments WHERE tenant_id = ?');
        $st->execute([$tenantId]);
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $entry = [
                'id' => (int) $row['id'],
                'type' => (string) $row['type'],
                'parent_id' => $row['parent_id'] !== null ? (int) $row['parent_id'] : null,
            ];
            $key = self::normKey((string) $row['name']);
            $maps['departments'][$key] = $entry;
            if ($entry['type'] === 'estandar') {
                $maps['divisions'][$key] = $entry;
            }
        }

        return $maps;
    }

    /**
     * @param array<string, string> $row
     * @param array{cost_centers: array<string, int>, activities: array<string, int>, accounts: array<string, int>, accounts_by_id: array<int, int>, departments: array<string, array{id: int, type: string, parent_id: int|null}>} $maps
     * @return array{month: int, cost_center_id: ?int, activity_id: int, division_id: ?int, department_id: ?int, observations: string, created_by: int}
     */
    private static function buildHeader(PDO $pdo, int $tenantId, array $row, array $maps): array
    {
        $creatorId = (int) ($row['id'] ?? 0);
        $creator = DepartmentCatalog::getById($pdo, $creatorId, $tenantId);
        if ($creator === null) {
            throw new RuntimeException("Department id {$creatorId} no existe en tenant {$tenantId}");
        }

        $month = (int) ($row['Mes'] ?? 0);
        if ($month < 1 || $month > 12) {
            throw new RuntimeException('Mes invalido: ' . ($row['Mes'] ?? ''));
        }

        $activityId = self::resolveActivityId((string) ($row['Actividad'] ?? ''), $maps);
        $observations = mb_substr(utf8_normalize(trim((string) ($row['Obs'] ?? ''))), 0, 100);
        $creatorType = (string) ($creator['type'] ?? 'estandar');

        if ($creatorType === 'estandar') {
            $ccId = self::resolveCostCenterId($pdo, $tenantId, (string) ($row['Ccosto'] ?? ''), $maps);
            $divisionId = $creatorId;
            $departmentId = self::resolveSubDepartmentId($creatorId, (string) ($row['Div'] ?? ''), $maps);

            return [
                'month' => $month,
                'cost_center_id' => $ccId,
                'activity_id' => $activityId,
                'division_id' => $divisionId,
                'department_id' => $departmentId,
                'observations' => $observations,
                'created_by' => $creatorId,
            ];
        }

        if ($creatorType === 'coordinacion') {
            $divisionId = self::resolveDivisionId((string) ($row['Div'] ?? ''), $maps);
            if ($divisionId === null) {
                throw new RuntimeException('Division destino no encontrada: ' . ($row['Div'] ?? ''));
            }
            $ccRaw = trim((string) ($row['Ccosto'] ?? ''));
            $ccId = null;
            if ($ccRaw !== '' && preg_match('/^\d+$/', $ccRaw) === 1) {
                $ccId = self::resolveCostCenterId($pdo, $tenantId, $ccRaw, $maps);
            }

            return [
                'month' => $month,
                'cost_center_id' => $ccId,
                'activity_id' => $activityId,
                'division_id' => $divisionId,
                'department_id' => $creatorId,
                'observations' => $observations,
                'created_by' => $creatorId,
            ];
        }

        throw new RuntimeException('Tipo de department desconocido: ' . $creatorType);
    }

    /**
     * @param array<string, string> $row
     * @param array{accounts: array<string, int>} $maps
     * @return array{account_code_id: int, quantity: float, unit_price: float, observation: string}
     */
    private static function buildDetail(array $row, array $maps): array
    {
        $accountId = self::resolveAccountId((string) ($row['Cta Asociada'] ?? ''), $maps);

        return [
            'account_code_id' => $accountId,
            'quantity' => (float) str_replace(',', '.', (string) ($row['Cant.'] ?? '1')),
            'unit_price' => (float) str_replace(',', '.', (string) ($row['$Unitario'] ?? '0')),
            'observation' => mb_substr(utf8_normalize(trim((string) ($row['Sub_obs.'] ?? ''))), 0, 500),
        ];
    }

    /** @param array{activities: array<string, int>} $maps */
    private static function resolveActivityId(string $name, array $maps): int
    {
        $key = self::normKey($name);
        if (isset($maps['activities'][$key])) {
            return $maps['activities'][$key];
        }
        throw new RuntimeException('Actividad no encontrada: ' . $name);
    }

    /**
     * @param array{accounts: array<string, int>, accounts_by_id?: array<int, int>} $maps
     */
    private static function resolveAccountId(string $value, array $maps): int
    {
        $value = trim($value);
        if ($value !== '' && preg_match('/^\d+$/', $value) === 1) {
            $aid = (int) $value;
            if (isset($maps['accounts_by_id'][$aid])) {
                return $maps['accounts_by_id'][$aid];
            }
        }
        $key = self::normKey($value);
        if (isset($maps['accounts'][$key])) {
            return $maps['accounts'][$key];
        }
        throw new RuntimeException('Cuenta asociada no encontrada: ' . $value);
    }

    /**
     * Ccosto numerico = cost_centers.id; texto = nombre en catalogo.
     *
     * @param array{cost_centers: array<string, int>, cost_center_ids: array<int, true>} $maps
     */
    private static function resolveCostCenterId(PDO $pdo, int $tenantId, string $value, array $maps): int
    {
        $value = trim($value);
        if ($value !== '' && preg_match('/^\d+$/', $value) === 1) {
            $id = (int) $value;
            if (isset($maps['cost_center_ids'][$id])) {
                return $id;
            }
            $st = $pdo->prepare('SELECT id FROM cost_centers WHERE id = ? AND tenant_id = ? LIMIT 1');
            $st->execute([$id, $tenantId]);
            if ($st->fetchColumn()) {
                return $id;
            }
            throw new RuntimeException("Centro de costo id {$id} no existe en tenant {$tenantId}");
        }

        $key = self::normKey($value);
        $alias = self::COST_CENTER_ALIASES[$key] ?? $key;
        if (isset($maps['cost_centers'][$alias])) {
            return $maps['cost_centers'][$alias];
        }
        if (isset($maps['cost_centers'][$key])) {
            return $maps['cost_centers'][$key];
        }
        throw new RuntimeException('Centro de costo no encontrado: ' . $value);
    }

    /**
     * Elimina actividades importadas desde records2026 (Num-YYYY).
     *
     * @return array{headers_deleted: int, details_deleted: int}
     */
    public static function revertImport(PDO $pdo, int $tenantId, int $year): array
    {
        $st = $pdo->prepare(
            'SELECT id FROM budget_headers WHERE tenant_id = ? AND activity_number LIKE ?'
        );
        $st->execute([$tenantId, '%-' . $year]);
        $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
        if ($ids === []) {
            return ['headers_deleted' => 0, 'details_deleted' => 0];
        }

        $ph = implode(',', array_fill(0, count($ids), '?'));
        $stDet = $pdo->prepare("SELECT COUNT(*) FROM budget_details WHERE budget_header_id IN ({$ph})");
        $stDet->execute($ids);
        $detailsDeleted = (int) $stDet->fetchColumn();

        $pdo->prepare("DELETE FROM budget_details WHERE budget_header_id IN ({$ph})")->execute($ids);
        $stHdr = $pdo->prepare("DELETE FROM budget_headers WHERE id IN ({$ph})");
        $stHdr->execute($ids);
        $headersDeleted = $stHdr->rowCount();

        return ['headers_deleted' => $headersDeleted, 'details_deleted' => $detailsDeleted];
    }

    /**
     * @param array{departments: array<string, array{id: int, type: string, parent_id: int|null}>} $maps
     */
    private static function resolveDivisionId(string $name, array $maps): ?int
    {
        $key = self::normKey($name);
        $try = [$key];
        if (isset(self::DIVISION_ALIASES[$key])) {
            $try[] = self::normKey(self::DIVISION_ALIASES[$key]);
        }
        foreach ($try as $k) {
            $dept = $maps['divisions'][$k] ?? null;
            if ($dept !== null) {
                return $dept['id'];
            }
        }

        return null;
    }

    /**
     * @param array{departments: array<string, array{id: int, type: string, parent_id: int|null}>} $maps
     */
    private static function resolveSubDepartmentId(int $creatorDivisionId, string $motionDivName, array $maps): ?int
    {
        $key = self::normKey($motionDivName);
        $dept = $maps['departments'][$key] ?? null;
        if ($dept === null) {
            $alias = self::DIVISION_ALIASES[$key] ?? null;
            if ($alias !== null) {
                $dept = $maps['departments'][self::normKey($alias)] ?? null;
            }
        }
        if ($dept === null || $dept['type'] !== 'estandar') {
            return null;
        }
        if ($dept['id'] === $creatorDivisionId) {
            return null;
        }
        if ($dept['parent_id'] === $creatorDivisionId) {
            return $dept['id'];
        }

        return null;
    }

    private static function syncActivitySequence(PDO $pdo, int $tenantId, int $year, int $maxNum): void
    {
        $st = $pdo->prepare('SELECT last_number FROM activity_sequences WHERE tenant_id = ? AND year = ?');
        $st->execute([$tenantId, $year]);
        $current = $st->fetchColumn();
        if ($current === false) {
            $pdo->prepare('INSERT INTO activity_sequences (tenant_id, year, last_number) VALUES (?, ?, ?)')
                ->execute([$tenantId, $year, $maxNum]);
        } elseif ((int) $current < $maxNum) {
            $pdo->prepare('UPDATE activity_sequences SET last_number = ? WHERE tenant_id = ? AND year = ?')
                ->execute([$maxNum, $tenantId, $year]);
        }
    }

    private static function normKey(string $value): string
    {
        $value = html_entity_decode(trim($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = mb_strtolower($value, 'UTF-8');
        $trans = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        if ($trans !== false) {
            $value = $trans;
        }
        $value = preg_replace('/[^a-z0-9]+/', '', $value) ?? '';

        return $value;
    }
}
