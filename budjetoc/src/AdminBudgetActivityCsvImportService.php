<?php

declare(strict_types=1);

/**
 * Plantilla CSV, validacion previa (CAP-4) y confirmacion de altas (CAP-3)
 * de actividades de Presupuesto para super admin.
 */
final class AdminBudgetActivityCsvImportService
{
    /** UTF-8 BOM para que Excel preserve tildes y eñes. */
    public const UTF8_BOM = "\xEF\xBB\xBF";

    private const MAX_BYTES = 2_097_152;

    /**
     * Solo smoke CLI: si coincide con el activity_number del grupo, el INSERT lanza
     * (simula fallo mid-batch tras validate OK). Se ignora/limpia si PHP_SAPI !== 'cli'.
     */
    public static ?string $debugFailInsertActivityNumber = null;

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

    /**
     * Valida un CSV subido (multipart) sin insertar.
     *
     * @param array{error?: int, tmp_name?: string, size?: int, name?: string} $file
     * @return array{ok: bool, would_upload: int, errors: list<array{line?: int|null, group: string, activity_number?: string|null, message: string}>}
     */
    public static function validateFromUploadedFile(PDO $pdo, int $tenantId, array $file): array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return self::failResult('No se recibio un archivo CSV valido.');
        }
        $size = (int) ($file['size'] ?? 0);
        if ($size <= 0) {
            return self::failResult('El archivo CSV esta vacio.');
        }
        if ($size > self::MAX_BYTES) {
            return self::failResult('El archivo supera el limite de 2 MB.');
        }
        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            return self::failResult('Archivo de carga invalido.');
        }

        return self::validateUploadedCsv($pdo, $tenantId, $tmp);
    }

    /**
     * Valida CSV desde ruta de archivo o contenido string. No INSERT.
     *
     * @return array{ok: bool, would_upload: int, errors: list<array{line?: int|null, group: string, activity_number?: string|null, message: string}>}
     */
    public static function validateUploadedCsv(PDO $pdo, int $tenantId, string $csvOrPath): array
    {
        if ($tenantId <= 0) {
            return self::failResult('Tenant invalido.');
        }

        try {
            if (is_file($csvOrPath)) {
                $size = filesize($csvOrPath);
                if ($size === false || $size <= 0) {
                    return self::failResult('El archivo CSV esta vacio.');
                }
                if ($size > self::MAX_BYTES) {
                    return self::failResult('El archivo supera el limite de 2 MB.');
                }
                $csv = utf8_file_contents($csvOrPath);
            } else {
                $csv = $csvOrPath;
                if (str_starts_with($csv, self::UTF8_BOM)) {
                    $csv = substr($csv, strlen(self::UTF8_BOM));
                }
            }
        } catch (Throwable $e) {
            return self::failResult('No se pudo leer el CSV: ' . $e->getMessage());
        }

        $csv = trim($csv);
        if ($csv === '') {
            return self::failResult('El archivo CSV esta vacio.');
        }

        try {
            $parsed = self::parseTemplateCsv($csv);
        } catch (RuntimeException $e) {
            return self::failResult($e->getMessage());
        }

        if ($parsed['rows'] === []) {
            return self::failResult('El CSV no tiene filas de datos (solo encabezados o vacio).');
        }

        $maps = self::loadCatalogMaps($pdo, $tenantId);
        $grouped = self::groupRows($parsed['rows']);
        $errors = $grouped['row_errors'];
        $groups = $grouped['groups'];

        if ($groups === [] && $errors === []) {
            return self::failResult('No se encontraron grupos validos (columna num/year).');
        }

        $wouldUpload = 0;

        foreach ($groups as $groupKey => $group) {
            $groupErrors = self::validateGroup($pdo, $tenantId, $groupKey, $group, $maps);
            if ($groupErrors === []) {
                $wouldUpload++;
            } else {
                foreach ($groupErrors as $err) {
                    $errors[] = $err;
                }
            }
        }

        return [
            'ok' => $errors === [],
            'would_upload' => $wouldUpload,
            'errors' => $errors,
        ];
    }

    /**
     * Confirma altas desde CSV subido (multipart): re-valida e inserta solo si ok.
     *
     * @param array{error?: int, tmp_name?: string, size?: int, name?: string} $file
     * @return array{
     *   ok: bool,
     *   created: list<array{activity_number: string, header_id: int, created_by: int}>,
     *   errors: list<array{line?: int|null, group: string, activity_number?: string|null, message: string}>
     * }
     */
    public static function importFromUploadedFile(PDO $pdo, int $tenantId, array $file): array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return self::failImportResult('No se recibio un archivo CSV valido. Debe re-subir el CSV para confirmar.');
        }
        $size = (int) ($file['size'] ?? 0);
        if ($size <= 0) {
            return self::failImportResult('El archivo CSV esta vacio. Debe re-subir el CSV para confirmar.');
        }
        if ($size > self::MAX_BYTES) {
            return self::failImportResult('El archivo supera el limite de 2 MB.');
        }
        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            return self::failImportResult('Archivo de carga invalido.');
        }

        return self::importUploadedCsv($pdo, $tenantId, $tmp);
    }

    /**
     * Re-valida el CSV e inserta grupos OK. Si la validacion falla, cero INSERT.
     *
     * @return array{
     *   ok: bool,
     *   created: list<array{activity_number: string, header_id: int, created_by: int}>,
     *   errors: list<array{line?: int|null, group: string, activity_number?: string|null, message: string}>
     * }
     */
    public static function importUploadedCsv(PDO $pdo, int $tenantId, string $csvOrPath): array
    {
        $validation = self::validateUploadedCsv($pdo, $tenantId, $csvOrPath);
        if (!($validation['ok'] ?? false)) {
            return [
                'ok' => false,
                'created' => [],
                'errors' => $validation['errors'],
            ];
        }

        try {
            if (is_file($csvOrPath)) {
                $csv = utf8_file_contents($csvOrPath);
            } else {
                $csv = $csvOrPath;
                if (str_starts_with($csv, self::UTF8_BOM)) {
                    $csv = substr($csv, strlen(self::UTF8_BOM));
                }
            }
        } catch (Throwable $e) {
            return self::failImportResult('No se pudo leer el CSV: ' . $e->getMessage());
        }

        $csv = trim($csv);
        try {
            $parsed = self::parseTemplateCsv($csv);
        } catch (RuntimeException $e) {
            return self::failImportResult($e->getMessage());
        }

        $maps = self::loadCatalogMaps($pdo, $tenantId);
        $grouped = self::groupRows($parsed['rows']);
        $created = [];
        $errors = $grouped['row_errors'];

        if (PHP_SAPI !== 'cli') {
            self::$debugFailInsertActivityNumber = null;
        }

        foreach ($grouped['groups'] as $groupKey => $group) {
            $analyzed = self::analyzeGroup($pdo, $tenantId, $groupKey, $group, $maps);
            if ($analyzed['errors'] !== []) {
                // Tras validate OK no deberia ocurrir; detener inserts de grupos siguientes.
                foreach ($analyzed['errors'] as $err) {
                    $errors[] = $err;
                }
                break;
            }
            $payload = $analyzed['payload'];
            if ($payload === null) {
                $errors[] = [
                    'line' => $group['lines'][0]['line'] ?? null,
                    'group' => $group['num'] !== '' ? (string) $group['num'] : $groupKey,
                    'activity_number' => $group['activity_number'],
                    'message' => 'No se pudo resolver el grupo para insertar.',
                ];
                break;
            }
            try {
                if (self::$debugFailInsertActivityNumber !== null
                    && self::$debugFailInsertActivityNumber === $payload['activity_number']
                ) {
                    throw new RuntimeException('simulated insert failure');
                }
                $hid = BudgetService::createActivity(
                    $pdo,
                    $tenantId,
                    $payload['header'],
                    $payload['details'],
                    $payload['year'],
                    $payload['created_by'],
                    $payload['activity_number'],
                );
                $created[] = [
                    'activity_number' => $payload['activity_number'],
                    'header_id' => $hid,
                    'created_by' => $payload['created_by'],
                ];
            } catch (Throwable $e) {
                $errors[] = [
                    'line' => $group['lines'][0]['line'] ?? null,
                    'group' => $group['num'] !== '' ? (string) $group['num'] : $groupKey,
                    'activity_number' => $payload['activity_number'],
                    'message' => 'Error al crear la actividad: ' . $e->getMessage(),
                ];
            }
        }

        return [
            'ok' => $errors === [],
            'created' => $created,
            'errors' => $errors,
        ];
    }

    /**
     * @return array{ok: false, would_upload: 0, errors: list<array{line?: int|null, group: string, activity_number?: string|null, message: string}>}
     */
    private static function failResult(string $message): array
    {
        return [
            'ok' => false,
            'would_upload' => 0,
            'errors' => [[
                'line' => null,
                'group' => '',
                'activity_number' => null,
                'message' => $message,
            ]],
        ];
    }

    /**
     * @return array{
     *   ok: false,
     *   created: list<array{activity_number: string, header_id: int, created_by: int}>,
     *   errors: list<array{line?: int|null, group: string, activity_number?: string|null, message: string}>
     * }
     */
    private static function failImportResult(string $message): array
    {
        return [
            'ok' => false,
            'created' => [],
            'errors' => [[
                'line' => null,
                'group' => '',
                'activity_number' => null,
                'message' => $message,
            ]],
        ];
    }

    /**
     * @return array{rows: list<array{line: int, cells: array<string, string>}>}
     */
    private static function parseTemplateCsv(string $csv): array
    {
        $fh = fopen('php://memory', 'rb+');
        if ($fh === false) {
            throw new RuntimeException('No se pudo procesar el CSV.');
        }
        fwrite($fh, $csv);
        rewind($fh);

        $expected = self::templateHeaders();
        $header = null;
        $rows = [];
        $lineNo = 0;

        while (($data = fgetcsv($fh, 0, ';')) !== false) {
            $lineNo++;
            if ($data === [null] || $data === []) {
                continue;
            }
            if ($header === null) {
                $cells = [];
                foreach ($data as $cell) {
                    $cells[] = self::normalizeHeaderCell((string) $cell);
                }
                // Recortar columnas vacias al final (Excel a veces agrega).
                while ($cells !== [] && end($cells) === '') {
                    array_pop($cells);
                }
                if ($cells !== $expected) {
                    fclose($fh);
                    throw new RuntimeException(
                        'Encabezados distintos a la plantilla. Esperado: '
                        . implode(';', $expected)
                    );
                }
                $header = $expected;
                continue;
            }

            $assoc = [];
            foreach ($header as $i => $key) {
                $assoc[$key] = utf8_normalize(trim((string) ($data[$i] ?? '')));
            }
            if (implode('', $assoc) === '') {
                continue;
            }
            $rows[] = ['line' => $lineNo, 'cells' => $assoc];
        }
        fclose($fh);

        return ['rows' => $rows];
    }

    private static function normalizeHeaderCell(string $label): string
    {
        $label = trim($label);
        if (str_starts_with($label, self::UTF8_BOM)) {
            $label = substr($label, strlen(self::UTF8_BOM));
        }

        return trim($label);
    }

    /**
     * @param list<array{line: int, cells: array<string, string>}> $rows
     * @return array{
     *   groups: array<string, array{activity_number: string, num: string, year: int, year_raw: string, lines: list<array{line: int, cells: array<string, string>}>}>,
     *   row_errors: list<array{line?: int|null, group: string, activity_number?: string|null, message: string}>
     * }
     */
    private static function groupRows(array $rows): array
    {
        $groups = [];
        $rowErrors = [];
        foreach ($rows as $row) {
            $cells = $row['cells'];
            $line = (int) $row['line'];
            $num = trim((string) ($cells['num'] ?? ''));
            $yearRaw = trim((string) ($cells['year'] ?? ''));
            if ($num === '' && $yearRaw === '') {
                $rowErrors[] = [
                    'line' => $line,
                    'group' => '',
                    'activity_number' => null,
                    'message' => 'Fila sin num ni year.',
                ];
                continue;
            }
            $year = preg_match('/^\d+$/', $yearRaw) === 1 ? (int) $yearRaw : 0;
            $activityNumber = self::composeActivityNumber($num, $year);
            if (!isset($groups[$activityNumber])) {
                $groups[$activityNumber] = [
                    'activity_number' => $activityNumber,
                    'num' => $num !== '' ? activity_num_short($num) : $num,
                    'year' => $year,
                    'year_raw' => $yearRaw,
                    'lines' => [],
                ];
            }
            $groups[$activityNumber]['lines'][] = $row;
        }

        return ['groups' => $groups, 'row_errors' => $rowErrors];
    }

    /** Compone N-YYYY; sin default silencioso de año (year<=0 → sufijo -sin-year). */
    private static function composeActivityNumber(string $num, int $year): string
    {
        $num = trim($num);
        if ($num === '') {
            return 'sin-num';
        }
        if (preg_match('/^\d+-\d{4}$/', $num) === 1) {
            return $num;
        }
        $base = activity_num_short($num);
        if ($year <= 0) {
            return $base . '-sin-year';
        }

        return $base . '-' . $year;
    }

    /**
     * @param array{activity_number: string, num: string, year: int, year_raw: string, lines: list<array{line: int, cells: array<string, string>}>} $group
     * @param array{
     *   cost_centers: array<string, int>,
     *   activities: array<string, int>,
     *   accounts: array<string, int>,
     *   accounts_by_id: array<int, int>,
     *   departments: array<string, array{id: int, name: string, type: string, parent_id: int|null}>
     * } $maps
     * @return list<array{line?: int|null, group: string, activity_number?: string|null, message: string}>
     */
    private static function validateGroup(PDO $pdo, int $tenantId, string $groupKey, array $group, array $maps): array
    {
        return self::analyzeGroup($pdo, $tenantId, $groupKey, $group, $maps)['errors'];
    }

    /**
     * Resuelve cabecera/detalle/unidad del grupo; si hay errores, payload=null.
     *
     * @param array{activity_number: string, num: string, year: int, year_raw: string, lines: list<array{line: int, cells: array<string, string>}>} $group
     * @param array{
     *   cost_centers: array<string, int>,
     *   activities: array<string, int>,
     *   accounts: array<string, int>,
     *   accounts_by_id: array<int, int>,
     *   departments: array<string, array{id: int, name: string, type: string, parent_id: int|null}>
     * } $maps
     * @return array{
     *   errors: list<array{line?: int|null, group: string, activity_number?: string|null, message: string}>,
     *   payload: null|array{
     *     activity_number: string,
     *     year: int,
     *     created_by: int,
     *     header: array{month: int, cost_center_id?: int|null, activity_id: int, division_id?: int|null, department_id?: int|null, observations?: string|null},
     *     details: list<array{account_code_id: int, quantity: float, unit_price: float, observation: string}>
     *   }
     * }
     */
    private static function analyzeGroup(PDO $pdo, int $tenantId, string $groupKey, array $group, array $maps): array
    {
        $errors = [];
        $actNum = $group['activity_number'];
        $firstLine = $group['lines'][0]['line'] ?? null;
        $lead = $group['lines'][0]['cells'];
        $groupLabel = $group['num'] !== '' ? (string) $group['num'] : $groupKey;

        $push = static function (string $message, ?int $line = null) use (&$errors, $groupLabel, $actNum, $firstLine): void {
            $errors[] = [
                'line' => $line ?? $firstLine,
                'group' => $groupLabel,
                'activity_number' => ($actNum !== 'sin-num' && !str_ends_with($actNum, '-sin-year')) ? $actNum : null,
                'message' => $message,
            ];
        };

        if ($group['num'] === '' || $actNum === 'sin-num') {
            $push('Falta num (numero de actividad).');

            return ['errors' => $errors, 'payload' => null];
        }

        $yearRaw = (string) ($group['year_raw'] ?? '');
        if ($yearRaw === '') {
            $push('year obligatorio.');
        } elseif (preg_match('/^\d+$/', $yearRaw) !== 1) {
            $push('year invalido.');
        } elseif ($group['year'] < 2000 || $group['year'] > 2100) {
            $push('year invalido.');
        }

        $leadNum = trim((string) ($lead['num'] ?? ''));
        if (preg_match('/^(\d+)-(\d{4})$/', $leadNum, $numParts) === 1
            && $yearRaw !== ''
            && preg_match('/^\d+$/', $yearRaw) === 1
            && (int) $yearRaw !== (int) $numParts[2]
        ) {
            $push('year inconsistente con num ' . $leadNum . ' (year=' . $yearRaw . ').');
        }

        $headerKeys = [
            'month',
            'activity_name',
            'creator_type',
            'creator_name',
            'division_name',
            'cost_center_name',
            'observations',
        ];
        foreach (array_slice($group['lines'], 1) as $later) {
            $laterCells = $later['cells'];
            $laterLine = (int) $later['line'];
            foreach ($headerKeys as $hk) {
                if (trim((string) ($laterCells[$hk] ?? '')) !== trim((string) ($lead[$hk] ?? ''))) {
                    $push(
                        'Fila del grupo difiere en ' . $hk . ' respecto a la primera linea.',
                        $laterLine
                    );
                }
            }
        }

        $month = (int) ($lead['month'] ?? 0);
        if ($month < 1 || $month > 12) {
            $push('month invalido (1-12).');
        }

        $activityName = trim((string) ($lead['activity_name'] ?? ''));
        $activityId = 0;
        if ($activityName === '') {
            $push('activity_name obligatorio.');
        } else {
            $activityId = $maps['activities'][self::normKey($activityName)] ?? 0;
            if ($activityId <= 0) {
                $push('Actividad no encontrada en el tenant: ' . $activityName);
            }
        }

        $creatorTypeRaw = mb_strtolower(trim((string) ($lead['creator_type'] ?? '')), 'UTF-8');
        $creatorName = trim((string) ($lead['creator_name'] ?? ''));
        if ($creatorTypeRaw === '' || $creatorName === '') {
            $push('creator_type y creator_name son obligatorios.');

            return ['errors' => $errors, 'payload' => null];
        }
        if (!in_array($creatorTypeRaw, ['division', 'coordinacion'], true)) {
            $push('creator_type debe ser division o coordinacion.');

            return ['errors' => $errors, 'payload' => null];
        }

        $creatorExpectedType = $creatorTypeRaw === 'division' ? 'estandar' : 'coordinacion';
        $creatorEntry = $maps['departments'][self::normKey($creatorName)] ?? null;
        if ($creatorEntry === null) {
            $push('Unidad creadora no encontrada: ' . $creatorName);

            return ['errors' => $errors, 'payload' => null];
        }
        if (($creatorEntry['type'] ?? '') !== $creatorExpectedType) {
            $push(
                'creator_name "' . $creatorName . '" no es tipo '
                . ($creatorTypeRaw === 'division' ? 'division (estandar)' : 'coordinacion')
                . ' en el tenant.'
            );

            return ['errors' => $errors, 'payload' => null];
        }
        $creatorDeptId = (int) $creatorEntry['id'];

        $divisionName = trim((string) ($lead['division_name'] ?? ''));
        $ccName = trim((string) ($lead['cost_center_name'] ?? ''));
        $header = [
            'month' => $month,
            'activity_id' => $activityId,
            'observations' => mb_substr(trim((string) ($lead['observations'] ?? '')), 0, 500),
            'division_id' => null,
            'department_id' => null,
            'cost_center_id' => null,
        ];

        if ($creatorTypeRaw === 'division') {
            $header['division_id'] = $creatorDeptId;
            if ($ccName === '') {
                $push('cost_center_name obligatorio para division.');
            } else {
                $ccId = $maps['cost_centers'][self::normKey($ccName)] ?? 0;
                if ($ccId <= 0) {
                    $push('Centro de costo no encontrado: ' . $ccName);
                } else {
                    $header['cost_center_id'] = $ccId;
                }
            }
            // division_name distinto puede ser sub-area bajo la division.
            if ($divisionName !== '' && self::normKey($divisionName) !== self::normKey($creatorName)) {
                $sub = $maps['departments'][self::normKey($divisionName)] ?? null;
                if ($sub !== null
                    && ($sub['type'] ?? '') === 'estandar'
                    && (int) ($sub['parent_id'] ?? 0) === $creatorDeptId
                ) {
                    $header['department_id'] = (int) $sub['id'];
                } else {
                    $push('division_name no es el creador ni un area hija valida: ' . $divisionName);
                }
            }
        } else {
            if ($divisionName === '') {
                $push('division_name obligatorio para coordinacion.');
            } else {
                $div = $maps['departments'][self::normKey($divisionName)] ?? null;
                if ($div === null || ($div['type'] ?? '') !== 'estandar') {
                    $push('Division destino no valida: ' . $divisionName);
                } else {
                    $header['division_id'] = (int) $div['id'];
                }
            }
            if ($ccName !== '') {
                $push('cost_center_name debe estar vacio para coordinacion.');
            }
            $header['cost_center_id'] = null;
            $header['department_id'] = $creatorDeptId;
        }

        $normalizedHeader = null;
        if ($activityId > 0 && $month >= 1 && $month <= 12 && ($header['division_id'] ?? null) !== null) {
            $norm = BudgetActivityRules::validateAndNormalizeHeader($pdo, $tenantId, $header, $creatorDeptId);
            if (!($norm['ok'] ?? false)) {
                $code = (string) ($norm['error'] ?? '');
                $push(BudgetActivityRules::errorMessage($code));
            } else {
                $normalizedHeader = $norm['header'];
            }
        }

        $stExists = $pdo->prepare(
            'SELECT id FROM budget_headers WHERE tenant_id = ? AND activity_number = ? LIMIT 1'
        );
        $stExists->execute([$tenantId, $actNum]);
        if ($stExists->fetchColumn()) {
            $push('El activity_number ya existe en el tenant (solo altas): ' . $actNum);
        }

        $details = [];
        $detailCount = 0;
        foreach ($group['lines'] as $row) {
            $cells = $row['cells'];
            $line = (int) $row['line'];
            $accountRaw = trim((string) ($cells['account_code'] ?? ''));
            $qtyRaw = trim((string) ($cells['qty'] ?? ''));
            $priceRaw = trim((string) ($cells['unit_price'] ?? ''));
            if ($accountRaw === '' && $qtyRaw === '' && $priceRaw === '') {
                continue;
            }
            $detailCount++;
            if ($accountRaw === '') {
                $push('account_code obligatorio en linea de detalle.', $line);
                continue;
            }
            $accountId = self::resolveAccountId($accountRaw, $maps);
            if ($accountId <= 0) {
                $push('Cuenta no encontrada: ' . $accountRaw, $line);
            }
            $qtyOk = true;
            $priceOk = true;
            if ($qtyRaw === '' || !is_numeric(str_replace(',', '.', $qtyRaw))) {
                $push('qty invalido.', $line);
                $qtyOk = false;
            } elseif ((float) str_replace(',', '.', $qtyRaw) < 0) {
                $push('qty no puede ser negativo.', $line);
                $qtyOk = false;
            }
            if ($priceRaw === '' || !is_numeric(str_replace(',', '.', $priceRaw))) {
                $push('unit_price invalido.', $line);
                $priceOk = false;
            } elseif ((float) str_replace(',', '.', $priceRaw) < 0) {
                $push('unit_price no puede ser negativo.', $line);
                $priceOk = false;
            }
            if ($accountId > 0 && $qtyOk && $priceOk) {
                $details[] = [
                    'account_code_id' => $accountId,
                    'quantity' => (float) str_replace(',', '.', $qtyRaw),
                    'unit_price' => (float) str_replace(',', '.', $priceRaw),
                    'observation' => mb_substr(trim((string) ($cells['detail_label'] ?? '')), 0, 255),
                ];
            }
        }
        if ($detailCount === 0) {
            $push('El grupo requiere al menos una linea de detalle (account_code/qty/unit_price).');
        }

        if ($errors !== [] || $normalizedHeader === null || $details === []) {
            return ['errors' => $errors, 'payload' => null];
        }

        return [
            'errors' => [],
            'payload' => [
                'activity_number' => $actNum,
                'year' => (int) $group['year'],
                'created_by' => $creatorDeptId,
                'header' => $normalizedHeader,
                'details' => $details,
            ],
        ];
    }

    /**
     * @return array{
     *   cost_centers: array<string, int>,
     *   activities: array<string, int>,
     *   accounts: array<string, int>,
     *   accounts_by_id: array<int, int>,
     *   departments: array<string, array{id: int, name: string, type: string, parent_id: int|null}>
     * }
     */
    private static function loadCatalogMaps(PDO $pdo, int $tenantId): array
    {
        $maps = [
            'cost_centers' => [],
            'activities' => [],
            'accounts' => [],
            'accounts_by_id' => [],
            'departments' => [],
        ];

        $st = $pdo->prepare('SELECT id, name FROM cost_centers WHERE tenant_id = ?');
        $st->execute([$tenantId]);
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $maps['cost_centers'][self::normKey((string) $row['name'])] = (int) $row['id'];
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

        foreach (DepartmentCatalog::listByTenant($pdo, $tenantId) as $d) {
            $maps['departments'][self::normKey((string) $d['name'])] = [
                'id' => (int) $d['id'],
                'name' => (string) $d['name'],
                'type' => (string) ($d['type'] ?? 'estandar'),
                'parent_id' => $d['parent_id'] !== null ? (int) $d['parent_id'] : null,
            ];
        }

        return $maps;
    }

    /**
     * @param array{accounts: array<string, int>, accounts_by_id: array<int, int>} $maps
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

        return $maps['accounts'][$key] ?? 0;
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
