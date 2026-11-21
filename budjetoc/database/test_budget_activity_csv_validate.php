<?php

declare(strict_types=1);

/**
 * Smoke CLI — validacion previa CSV actividades Presupuesto (historia 2 / CAP-4).
 * Uso: php database/test_budget_activity_csv_validate.php
 *
 * Cubre matriz I/O: limpio, duplicado, unidad invalida, parseo/BOM/tilde, sin filas.
 * No INSERT (verifica conteo budget_headers estable).
 */

require dirname(__DIR__) . '/src/bootstrap.php';

$pdo = Db::pdo($CONFIG);
$fail = 0;
$ok = 0;

function pass(string $msg): void
{
    global $ok;
    $ok++;
    echo "  OK   {$msg}\n";
}

function fail(string $msg): void
{
    global $fail;
    $fail++;
    echo "  FAIL {$msg}\n";
}

function assertTrue(string $label, bool $cond): void
{
    if ($cond) {
        pass($label);
    } else {
        fail($label);
    }
}

/**
 * @param list<list<string>> $dataRows
 */
function buildCsv(array $dataRows): string
{
    $headers = AdminBudgetActivityCsvImportService::templateHeaders();
    $lines = [implode(';', $headers)];
    foreach ($dataRows as $row) {
        $padded = [];
        foreach ($headers as $i => $_) {
            $padded[] = (string) ($row[$i] ?? '');
        }
        $lines[] = implode(';', $padded);
    }

    return AdminBudgetActivityCsvImportService::UTF8_BOM . implode("\r\n", $lines) . "\r\n";
}

echo "=== Validacion CSV actividades Presupuesto (CAP-4) ===\n\n";

$st = $pdo->query('SELECT id FROM tenants WHERE active = 1 ORDER BY id ASC LIMIT 1');
$tenantId = (int) ($st->fetchColumn() ?: 0);
if ($tenantId <= 0) {
    $st = $pdo->query('SELECT id FROM tenants ORDER BY id ASC LIMIT 1');
    $tenantId = (int) ($st->fetchColumn() ?: 0);
}
if ($tenantId <= 0) {
    fail('Sin tenant en BD');
    echo "\nResultado: {$ok} ok, {$fail} fail\n";
    exit(1);
}
pass('tenant_id=' . $tenantId);

$stCount = $pdo->prepare('SELECT COUNT(*) FROM budget_headers WHERE tenant_id = ?');
$stCount->execute([$tenantId]);
$headersBefore = (int) $stCount->fetchColumn();

// Catalogos reales del tenant
$st = $pdo->prepare("SELECT id, name FROM departments WHERE tenant_id = ? AND type = 'estandar' ORDER BY id ASC LIMIT 1");
$st->execute([$tenantId]);
$division = $st->fetch(PDO::FETCH_ASSOC);

$st = $pdo->prepare("SELECT id, name FROM departments WHERE tenant_id = ? AND type = 'coordinacion' ORDER BY id ASC LIMIT 1");
$st->execute([$tenantId]);
$coord = $st->fetch(PDO::FETCH_ASSOC) ?: null;

$st = $pdo->prepare('SELECT id, name FROM cost_centers WHERE tenant_id = ? ORDER BY id ASC LIMIT 1');
$st->execute([$tenantId]);
$cc = $st->fetch(PDO::FETCH_ASSOC);

$st = $pdo->prepare('SELECT id, name FROM activities WHERE tenant_id = ? ORDER BY id ASC LIMIT 1');
$st->execute([$tenantId]);
$activity = $st->fetch(PDO::FETCH_ASSOC);

$st = $pdo->prepare('SELECT account_id, name FROM account_codes WHERE tenant_id = ? ORDER BY account_id ASC LIMIT 1');
$st->execute([$tenantId]);
$account = $st->fetch(PDO::FETCH_ASSOC);

$year = (int) date('Y');
$uniqueBaseA = (string) (880000 + (int) (microtime(true) * 10) % 10000);
$uniqueBaseB = (string) ((int) $uniqueBaseA + 1);

// --- Parseo / encoding (plantilla sample con BOM + ñ vía validación) ---
$sample = AdminBudgetActivityCsvImportService::sampleCsvContent();
assertTrue('sample CSV empieza con BOM', str_starts_with($sample, AdminBudgetActivityCsvImportService::UTF8_BOM));
$sampleResult = AdminBudgetActivityCsvImportService::validateUploadedCsv($pdo, $tenantId, $sample);
assertTrue('sample parsea sin crash (ok bool)', is_bool($sampleResult['ok']));
$sampleMsgs = implode("\n", array_map(
    static fn (array $e): string => (string) ($e['message'] ?? ''),
    $sampleResult['errors']
));
assertTrue('sample errores sin mojibake Ã', !preg_match('/Ã.|â€|ï¿½/u', $sampleMsgs));
$hasEncodingNoise = str_contains(mb_strtolower($sampleMsgs), 'encoding')
    || str_contains(mb_strtolower($sampleMsgs), 'utf-8');
assertTrue('sample no reporta error de encoding', !$hasEncodingNoise);
// Si el validador menciona la actividad de ejemplo, debe preservar tilde/ñ.
if (str_contains($sampleMsgs, 'pedag') || str_contains($sampleMsgs, 'uñoa') || str_contains($sampleMsgs, 'Uñoa')) {
    assertTrue(
        'sample mensaje preserva pedagógico/Ñuñoa',
        str_contains($sampleMsgs, 'pedagógico') || str_contains($sampleMsgs, 'Ñuñoa')
    );
}

// --- Sin filas / solo headers ---
$headersOnly = AdminBudgetActivityCsvImportService::UTF8_BOM
    . implode(';', AdminBudgetActivityCsvImportService::templateHeaders()) . "\r\n";
$emptyResult = AdminBudgetActivityCsvImportService::validateUploadedCsv($pdo, $tenantId, $headersOnly);
assertTrue('solo headers → ok=false', $emptyResult['ok'] === false);
assertTrue('solo headers → would_upload=0', (int) $emptyResult['would_upload'] === 0);
assertTrue('solo headers → tiene error', $emptyResult['errors'] !== []);

$blankResult = AdminBudgetActivityCsvImportService::validateUploadedCsv($pdo, $tenantId, '');
assertTrue('CSV vacio → ok=false', $blankResult['ok'] === false);

// --- Header distinto a plantilla ---
$badHeader = AdminBudgetActivityCsvImportService::UTF8_BOM . "foo;bar\r\n1;2\r\n";
$badH = AdminBudgetActivityCsvImportService::validateUploadedCsv($pdo, $tenantId, $badHeader);
assertTrue('header distinto → ok=false', $badH['ok'] === false);
assertTrue(
    'header distinto menciona plantilla',
    str_contains((string) ($badH['errors'][0]['message'] ?? ''), 'plantilla')
);

$canClean = $division && $cc && $activity && $account;
if (!$canClean) {
    fail('Faltan catalogos (division/CC/actividad/cuenta) para caso limpio');
} else {
    $divName = (string) $division['name'];
    $ccName = (string) $cc['name'];
    $actName = (string) $activity['name'];
    $acctCode = (string) $account['account_id'];

    $cleanRows = [
        [$uniqueBaseA, (string) $year, '3', $actName, 'division', $divName, $divName, $ccName, 'Obs tilde áéí', $acctCode, '1', '1000', 'Linea'],
    ];
    if ($coord) {
        $coordName = (string) $coord['name'];
        $cleanRows[] = [
            $uniqueBaseB,
            (string) $year,
            '4',
            $actName,
            'coordinacion',
            $coordName,
            $divName,
            '',
            'Obs ñ',
            $acctCode,
            '2',
            '500',
            'Kits',
        ];
    }

    $cleanCsv = buildCsv($cleanRows);
    $clean = AdminBudgetActivityCsvImportService::validateUploadedCsv($pdo, $tenantId, $cleanCsv);
    $expectedGroups = count($cleanRows);
    assertTrue('CSV limpio → ok=true', $clean['ok'] === true);
    assertTrue(
        "CSV limpio → would_upload={$expectedGroups}",
        (int) $clean['would_upload'] === $expectedGroups
    );
    assertTrue('CSV limpio → sin errores', $clean['errors'] === []);

    // --- would_upload cuenta grupos, no lineas de detalle ---
    $multiDetailBase = (string) ((int) $uniqueBaseA + 70);
    $multiDetailCsv = buildCsv([
        [$multiDetailBase, (string) $year, '3', $actName, 'division', $divName, $divName, $ccName, 'Obs Ñuñoa', $acctCode, '1', '1000', 'A'],
        [$multiDetailBase, (string) $year, '3', $actName, 'division', $divName, $divName, $ccName, 'Obs Ñuñoa', $acctCode, '2', '500', 'B'],
    ]);
    $multiDetail = AdminBudgetActivityCsvImportService::validateUploadedCsv($pdo, $tenantId, $multiDetailCsv);
    assertTrue('multi-detalle mismo grupo → ok=true', $multiDetail['ok'] === true);
    assertTrue('multi-detalle would_upload===1', (int) $multiDetail['would_upload'] === 1);

    // --- Ruta is_file (temp) + encoding en mensaje de error ---
    $tmpPath = tempnam(sys_get_temp_dir(), 'bacsv');
    assertTrue('temp file creado', is_string($tmpPath) && $tmpPath !== '');
    if (is_string($tmpPath)) {
        file_put_contents($tmpPath, $cleanCsv);
        $fromFile = AdminBudgetActivityCsvImportService::validateUploadedCsv($pdo, $tenantId, $tmpPath);
        assertTrue('validateUploadedCsv via is_file → ok', $fromFile['ok'] === true);
        assertTrue(
            'validateUploadedCsv via is_file would_upload',
            (int) $fromFile['would_upload'] === $expectedGroups
        );
        @unlink($tmpPath);
    }

    $utfErrCsv = buildCsv([
        [
            (string) ((int) $uniqueBaseA + 71),
            (string) $year,
            '3',
            $actName,
            'division',
            'Unidad Ñuñoa XYZ',
            'Unidad Ñuñoa XYZ',
            $ccName,
            'Obs pedagógico',
            $acctCode,
            '1',
            '10',
            'x',
        ],
    ]);
    $utfErr = AdminBudgetActivityCsvImportService::validateUploadedCsv($pdo, $tenantId, $utfErrCsv);
    $utfMsg = implode(' ', array_map(
        static fn (array $e): string => (string) ($e['message'] ?? ''),
        $utfErr['errors']
    ));
    assertTrue('error UTF-8 preserva Ñuñoa', str_contains($utfMsg, 'Ñuñoa'));
    assertTrue('error UTF-8 sin mojibake', !preg_match('/Ã.|â€|ï¿½/u', $utfMsg));

    // --- validateFromUploadedFile fallos ---
    $upNoFile = AdminBudgetActivityCsvImportService::validateFromUploadedFile($pdo, $tenantId, [
        'error' => UPLOAD_ERR_NO_FILE,
        'tmp_name' => '',
        'size' => 0,
    ]);
    assertTrue('UPLOAD_ERR_NO_FILE → ok=false', $upNoFile['ok'] === false);
    assertTrue(
        'UPLOAD_ERR_NO_FILE mensaje',
        str_contains((string) ($upNoFile['errors'][0]['message'] ?? ''), 'CSV')
    );

    $upIni = AdminBudgetActivityCsvImportService::validateFromUploadedFile($pdo, $tenantId, [
        'error' => UPLOAD_ERR_INI_SIZE,
        'tmp_name' => '/tmp/x',
        'size' => 100,
    ]);
    assertTrue('UPLOAD_ERR_INI_SIZE → ok=false', $upIni['ok'] === false);

    $upEmpty = AdminBudgetActivityCsvImportService::validateFromUploadedFile($pdo, $tenantId, [
        'error' => UPLOAD_ERR_OK,
        'tmp_name' => '/tmp/x',
        'size' => 0,
    ]);
    assertTrue('upload size vacio → ok=false', $upEmpty['ok'] === false);
    assertTrue(
        'upload size vacio mensaje',
        str_contains((string) ($upEmpty['errors'][0]['message'] ?? ''), 'vacio')
    );

    $upOver = AdminBudgetActivityCsvImportService::validateFromUploadedFile($pdo, $tenantId, [
        'error' => UPLOAD_ERR_OK,
        'tmp_name' => '/tmp/x',
        'size' => 2_097_153,
    ]);
    assertTrue('upload oversize → ok=false', $upOver['ok'] === false);
    assertTrue(
        'upload oversize mensaje',
        str_contains((string) ($upOver['errors'][0]['message'] ?? ''), '2 MB')
    );

    // --- Duplicado: insert temporal en transaccion (rollback) para no dejar datos ---
    $dupNum = $uniqueBaseA . '-' . $year;
    $dupCsv = buildCsv([
        [$uniqueBaseA, (string) $year, '3', $actName, 'division', $divName, $divName, $ccName, '', $acctCode, '1', '10', 'x'],
    ]);
    $pdo->beginTransaction();
    try {
        if (BudgetService::hasBudgetYearColumn($pdo) && BudgetService::hasCreatedByColumn($pdo)) {
            $pdo->prepare(
                'INSERT INTO budget_headers (tenant_id, activity_number, budget_year, month, cost_center_id, activity_id, division_id, department_id, observations, created_by)
                 VALUES (?, ?, ?, 3, ?, ?, ?, NULL, ?, ?)'
            )->execute([
                $tenantId,
                $dupNum,
                $year,
                (int) $cc['id'],
                (int) $activity['id'],
                (int) $division['id'],
                'tmp-dup-test',
                (int) $division['id'],
            ]);
        } elseif (BudgetService::hasCreatedByColumn($pdo)) {
            $pdo->prepare(
                'INSERT INTO budget_headers (tenant_id, activity_number, month, cost_center_id, activity_id, division_id, department_id, observations, created_by)
                 VALUES (?, ?, 3, ?, ?, ?, NULL, ?, ?)'
            )->execute([
                $tenantId,
                $dupNum,
                (int) $cc['id'],
                (int) $activity['id'],
                (int) $division['id'],
                'tmp-dup-test',
                (int) $division['id'],
            ]);
        } else {
            $pdo->prepare(
                'INSERT INTO budget_headers (tenant_id, activity_number, month, cost_center_id, activity_id, division_id, department_id, observations)
                 VALUES (?, ?, 3, ?, ?, ?, NULL, ?)'
            )->execute([
                $tenantId,
                $dupNum,
                (int) $cc['id'],
                (int) $activity['id'],
                (int) $division['id'],
                'tmp-dup-test',
            ]);
        }
        $dup = AdminBudgetActivityCsvImportService::validateUploadedCsv($pdo, $tenantId, $dupCsv);
        assertTrue('duplicado → ok=false', $dup['ok'] === false);
        $dupMsg = strtolower(implode(' ', array_map(
            static fn (array $e): string => (string) ($e['message'] ?? ''),
            $dup['errors']
        )));
        assertTrue('duplicado menciona ya existe', str_contains($dupMsg, 'ya existe'));
        assertTrue('duplicado would_upload=0', (int) $dup['would_upload'] === 0);
    } catch (Throwable $e) {
        fail('duplicado setup: ' . $e->getMessage());
    }
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    // --- Unidad invalida ---
    $badUnitCsv = buildCsv([
        [
            (string) ((int) $uniqueBaseA + 50),
            (string) $year,
            '3',
            $actName,
            'division',
            'Unidad Inventada XYZ 999',
            'Unidad Inventada XYZ 999',
            $ccName,
            '',
            $acctCode,
            '1',
            '10',
            'x',
        ],
    ]);
    $badUnit = AdminBudgetActivityCsvImportService::validateUploadedCsv($pdo, $tenantId, $badUnitCsv);
    assertTrue('unidad invalida → ok=false', $badUnit['ok'] === false);
    $unitMsg = strtolower(implode(' ', array_map(
        static fn (array $e): string => (string) ($e['message'] ?? ''),
        $badUnit['errors']
    )));
    assertTrue(
        'unidad invalida mensaje legible',
        str_contains($unitMsg, 'no encontrada') || str_contains($unitMsg, 'no es')
    );
}

// Sin INSERT
$stCount->execute([$tenantId]);
$headersAfter = (int) $stCount->fetchColumn();
assertTrue('sin INSERT (budget_headers estable)', $headersBefore === $headersAfter);

echo "\nResultado: {$ok} ok, {$fail} fail\n";
exit($fail > 0 ? 1 : 0);
