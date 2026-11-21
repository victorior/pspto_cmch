<?php

declare(strict_types=1);

require dirname(__DIR__, 3) . '/src/bootstrap_ajax.php';

Auth::checkPortal(Db::pdo($CONFIG), Auth::REALM_OC);

$term = trim((string) ($_GET['term'] ?? ''));
$year = (int) ($_GET['year'] ?? 0);
if ($year <= 0) {
    $year = (int) date('Y');
}
if (mb_strlen($term) < 2) {
    json_utf8([]);
    exit;
}

$pdo = Db::pdo($CONFIG);
$tid = (int) Auth::tenantId();
$rows = BudgetService::searchLinesForOc($pdo, $tid, $term, $year, 25, true);

$dash = "\u{2014}";
$lineaWord = "l\u{00ed}nea";

$out = [];
foreach ($rows as $r) {
    $act = (string) ($r['activity_number'] ?? '');
    $acct = (string) ($r['account_label'] ?? '');
    $lineRem = (float) ($r['line_remaining'] ?? 0);
    $hdrRem = (float) ($r['header_remaining'] ?? 0);
    $ocupar = (float) ($r['ocupar_default'] ?? $lineRem);
    $lineObs = trim((string) ($r['line_observation'] ?? $r['observation'] ?? ''));
    $label = $act . ' ' . $dash . ' ' . $acct;
    if ($lineObs !== '') {
        $label .= ' ' . $dash . ' ' . mb_substr($lineObs, 0, 60);
    }
    $label .= ' (saldo ' . $lineaWord . ' ' . number_format($lineRem, 0, ',', '.') . ')';
    $out[] = [
        'label' => $label,
        'value' => $act,
        'detail_id' => (int) ($r['detail_id'] ?? 0),
        'activity' => $act,
        'account' => $acct,
        'ocupar' => $ocupar,
        'line_rem' => $lineRem,
        'header_rem' => $hdrRem,
    ];
}

json_utf8($out);
