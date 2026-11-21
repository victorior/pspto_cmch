<?php

declare(strict_types=1);

/**
 * Smoke CLI — confirmacion de altas CSV actividades Presupuesto (historia 3 / CAP-3).
 * Uso: php database/test_budget_activity_csv_import.php
 *
 * Cubre matriz I/O: limpio→INSERT, duplicado→0 inserts, atribucion, secuencia, cleanup.
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

echo "=== Import CSV actividades Presupuesto (CAP-3) ===\n\n";

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

$st = $pdo->prepare('SELECT id, account_id, name FROM account_codes WHERE tenant_id = ? ORDER BY account_id ASC LIMIT 1');
$st->execute([$tenantId]);
$account = $st->fetch(PDO::FETCH_ASSOC);

if (!$division || !$cc || !$activity || !$account) {
    fail('Faltan catalogos (division/CC/actividad/cuenta)');
    echo "\nResultado: {$ok} ok, {$fail} fail\n";
    exit(1);
}

$year = (int) date('Y');
$uniqueBaseA = (string) (881000 + (int) (microtime(true) * 10) % 10000);
$uniqueBaseB = (string) ((int) $uniqueBaseA + 1);
$divName = (string) $division['name'];
$divId = (int) $division['id'];
$ccName = (string) $cc['name'];
$ccId = (int) $cc['id'];
$actName = (string) $activity['name'];
$actId = (int) $activity['id'];
$acctCode = (string) $account['account_id'];
$accountCodePk = (int) $account['id'];

$createdIds = [];

$stSeq = $pdo->prepare('SELECT last_number FROM activity_sequences WHERE tenant_id = ? AND year = ?');
$stSeq->execute([$tenantId, $year]);
$priorSeqRaw = $stSeq->fetchColumn();
$priorSeqExists = $priorSeqRaw !== false;
$priorSeqVal = $priorSeqExists ? (int) $priorSeqRaw : null;

// --- Confirm sin archivo / vacio ---
$noFile = AdminBudgetActivityCsvImportService::importFromUploadedFile($pdo, $tenantId, [
    'error' => UPLOAD_ERR_NO_FILE,
    'tmp_name' => '',
    'size' => 0,
]);
assertTrue('confirm sin archivo → ok=false', $noFile['ok'] === false);
assertTrue('confirm sin archivo → created vacio', ($noFile['created'] ?? []) === []);
assertTrue(
    'confirm sin archivo mensaje re-subir',
    str_contains(strtolower((string) ($noFile['errors'][0]['message'] ?? '')), 'csv')
);

// --- CSV limpio: division (+ coordinacion si hay) ---
$cleanRows = [
    [$uniqueBaseA, (string) $year, '3', $actName, 'division', $divName, $divName, $ccName, 'Import smoke á', $acctCode, '1', '1000', 'Linea'],
];
/** @var array<string, array{created_by: int, division_id: int, department_id: int|null}> $expectedOrg */
$expectedOrg = [
    $uniqueBaseA . '-' . $year => [
        'created_by' => $divId,
        'division_id' => $divId,
        'department_id' => null,
    ],
];
$maxImportedBase = (int) $uniqueBaseA;
if ($coord) {
    $coordName = (string) $coord['name'];
    $coordId = (int) $coord['id'];
    $cleanRows[] = [
        $uniqueBaseB,
        (string) $year,
        '4',
        $actName,
        'coordinacion',
        $coordName,
        $divName,
        '',
        'Import smoke ñ',
        $acctCode,
        '2',
        '500',
        'Kits',
    ];
    $expectedOrg[$uniqueBaseB . '-' . $year] = [
        'created_by' => $coordId,
        'division_id' => $divId,
        'department_id' => $coordId,
    ];
    $maxImportedBase = max($maxImportedBase, (int) $uniqueBaseB);
}

$cleanCsv = buildCsv($cleanRows);
$stCount = $pdo->prepare('SELECT COUNT(*) FROM budget_headers WHERE tenant_id = ?');
$stCount->execute([$tenantId]);
$before = (int) $stCount->fetchColumn();

$import = AdminBudgetActivityCsvImportService::importUploadedCsv($pdo, $tenantId, $cleanCsv);
assertTrue('CSV limpio → ok=true', $import['ok'] === true);
assertTrue(
    'CSV limpio → created=' . count($cleanRows),
    count($import['created']) === count($cleanRows)
);
assertTrue('CSV limpio → sin errores', $import['errors'] === []);

$stSeq->execute([$tenantId, $year]);
$seqAfterClean = (int) ($stSeq->fetchColumn() ?: 0);
assertTrue(
    'activity_sequences last_number >= max base import',
    $seqAfterClean >= $maxImportedBase
);

foreach ($import['created'] as $row) {
    $createdIds[] = (int) $row['header_id'];
    $actNum = (string) $row['activity_number'];
    $stH = $pdo->prepare(
        'SELECT activity_number, created_by, division_id, department_id FROM budget_headers WHERE id = ? AND tenant_id = ?'
    );
    $stH->execute([(int) $row['header_id'], $tenantId]);
    $hdr = $stH->fetch(PDO::FETCH_ASSOC);
    assertTrue('fila BD existe ' . $actNum, is_array($hdr));
    if (!is_array($hdr)) {
        continue;
    }
    assertTrue(
        'activity_number CSV conservado ' . $actNum,
        (string) $hdr['activity_number'] === $actNum
    );
    $want = $expectedOrg[$actNum] ?? null;
    if ($want !== null) {
        assertTrue(
            'created_by unidad CSV ' . $actNum,
            (int) ($hdr['created_by'] ?? 0) === $want['created_by']
        );
        assertTrue(
            'division_id CSV ' . $actNum,
            (int) ($hdr['division_id'] ?? 0) === $want['division_id']
        );
        if ($want['department_id'] !== null) {
            assertTrue(
                'department_id coordinacion ' . $actNum,
                (int) ($hdr['department_id'] ?? 0) === $want['department_id']
            );
        } else {
            assertTrue(
                'department_id division null ' . $actNum,
                $hdr['department_id'] === null || (int) $hdr['department_id'] === 0
            );
        }
    }
    $stD = $pdo->prepare('SELECT COUNT(*) FROM budget_details WHERE budget_header_id = ?');
    $stD->execute([(int) $row['header_id']]);
    assertTrue('detalle creado ' . $actNum, (int) $stD->fetchColumn() >= 1);
}

$stCount->execute([$tenantId]);
$afterClean = (int) $stCount->fetchColumn();
assertTrue(
    'budget_headers +' . count($cleanRows),
    $afterClean === $before + count($cleanRows)
);

// --- createActivity(null): numeracion por secuencia ---
$seqHid = BudgetService::createActivity(
    $pdo,
    $tenantId,
    [
        'month' => 5,
        'activity_id' => $actId,
        'observations' => 'smoke secuencia',
        'division_id' => $divId,
        'department_id' => null,
        'cost_center_id' => $ccId,
    ],
    [[
        'account_code_id' => $accountCodePk,
        'quantity' => 1.0,
        'unit_price' => 10.0,
        'observation' => 'seq',
    ]],
    $year,
    $divId,
    null,
);
$createdIds[] = $seqHid;
$stSeqHdr = $pdo->prepare(
    'SELECT activity_number FROM budget_headers WHERE id = ? AND tenant_id = ?'
);
$stSeqHdr->execute([$seqHid, $tenantId]);
$seqActNum = (string) ($stSeqHdr->fetchColumn() ?: '');
assertTrue(
    'createActivity(null) activity_number N-YYYY',
    preg_match('/^\d+-' . $year . '$/', $seqActNum) === 1
);

// --- Duplicado: mismo CSV otra vez → 0 inserts ---
$dup = AdminBudgetActivityCsvImportService::importUploadedCsv($pdo, $tenantId, $cleanCsv);
assertTrue('duplicado → ok=false', $dup['ok'] === false);
assertTrue('duplicado → created vacio', ($dup['created'] ?? []) === []);
$dupMsg = strtolower(implode(' ', array_map(
    static fn (array $e): string => (string) ($e['message'] ?? ''),
    $dup['errors']
)));
assertTrue('duplicado menciona ya existe', str_contains($dupMsg, 'ya existe'));
$stCount->execute([$tenantId]);
assertTrue('duplicado sin INSERT extra', (int) $stCount->fetchColumn() === $afterClean + 1);

// --- Confirm con unidad invalida → 0 inserts ---
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
$badUnit = AdminBudgetActivityCsvImportService::importUploadedCsv($pdo, $tenantId, $badUnitCsv);
assertTrue('unidad invalida confirm → ok=false', $badUnit['ok'] === false);
assertTrue('unidad invalida confirm → 0 created', ($badUnit['created'] ?? []) === []);
$stCount->execute([$tenantId]);
assertTrue('unidad invalida sin INSERT', (int) $stCount->fetchColumn() === $afterClean + 1);

// --- Fallo mid-batch: grupo 1 OK, grupo 2 INSERT simulado falla ---
$midA = (string) ((int) $uniqueBaseA + 80);
$midB = (string) ((int) $uniqueBaseA + 81);
$midRows = [
    [$midA, (string) $year, '3', $actName, 'division', $divName, $divName, $ccName, 'mid1', $acctCode, '1', '10', 'a'],
    [$midB, (string) $year, '4', $actName, 'division', $divName, $divName, $ccName, 'mid2', $acctCode, '1', '20', 'b'],
];
$midCsv = buildCsv($midRows);
$failNum = $midB . '-' . $year;
try {
    AdminBudgetActivityCsvImportService::$debugFailInsertActivityNumber = $failNum;
    $mid = AdminBudgetActivityCsvImportService::importUploadedCsv($pdo, $tenantId, $midCsv);
} finally {
    AdminBudgetActivityCsvImportService::$debugFailInsertActivityNumber = null;
}
assertTrue('mid-batch → ok=false', $mid['ok'] === false);
assertTrue('mid-batch → created=1', count($mid['created']) === 1);
assertTrue(
    'mid-batch → grupo1 conservado',
    (string) ($mid['created'][0]['activity_number'] ?? '') === ($midA . '-' . $year)
);
$midErr = strtolower(implode(' ', array_map(
    static fn (array $e): string => (string) ($e['message'] ?? ''),
    $mid['errors']
)));
assertTrue('mid-batch → error grupo2', str_contains($midErr, 'simulated insert failure'));
foreach ($mid['created'] as $row) {
    $createdIds[] = (int) $row['header_id'];
}
$stMid = $pdo->prepare('SELECT COUNT(*) FROM budget_headers WHERE tenant_id = ? AND activity_number = ?');
$stMid->execute([$tenantId, $midA . '-' . $year]);
assertTrue('mid-batch grupo1 en BD', (int) $stMid->fetchColumn() === 1);
$stMid->execute([$tenantId, $failNum]);
assertTrue('mid-batch grupo2 ausente', (int) $stMid->fetchColumn() === 0);

// --- Cleanup headers + restaurar activity_sequences ---
$cleaned = 0;
foreach ($createdIds as $hid) {
    if ($hid <= 0) {
        continue;
    }
    $pdo->prepare('DELETE FROM budget_details WHERE budget_header_id = ?')->execute([$hid]);
    $pdo->prepare('DELETE FROM budget_headers WHERE id = ? AND tenant_id = ?')->execute([$hid, $tenantId]);
    $cleaned++;
}
assertTrue('cleanup headers=' . $cleaned, $cleaned === count($createdIds));
$stCount->execute([$tenantId]);
assertTrue('budget_headers restaurado', (int) $stCount->fetchColumn() === $before);

if ($priorSeqExists) {
    $pdo->prepare(
        'UPDATE activity_sequences SET last_number = ? WHERE tenant_id = ? AND year = ?'
    )->execute([$priorSeqVal, $tenantId, $year]);
} else {
    $pdo->prepare(
        'DELETE FROM activity_sequences WHERE tenant_id = ? AND year = ?'
    )->execute([$tenantId, $year]);
}
$stSeq->execute([$tenantId, $year]);
$seqRestored = $stSeq->fetchColumn();
if ($priorSeqExists) {
    assertTrue('activity_sequences restaurada', (int) $seqRestored === (int) $priorSeqVal);
} else {
    assertTrue('activity_sequences sin fila (como antes)', $seqRestored === false);
}

echo "\nResultado: {$ok} ok, {$fail} fail\n";
exit($fail > 0 ? 1 : 0);
