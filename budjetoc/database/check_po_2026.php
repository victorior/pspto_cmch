<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

$pdo = Db::pdo($CONFIG);
$tid = 4;

echo "=== purchase_orders tenant {$tid} (IAE) por ano ===\n";
$st = $pdo->prepare(
    'SELECT folio_year, COUNT(*) AS c,
            SUM(note LIKE \'[legacy-oc-iae]%\' OR note LIKE \'[legacy-oc-iae] %\') AS legacy
     FROM purchase_orders WHERE tenant_id = ?
     GROUP BY folio_year ORDER BY folio_year'
);
$st->execute([$tid]);
while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
    echo "  {$r['folio_year']}: {$r['c']} total, {$r['legacy']} legacy IAE dump\n";
}

$st = $pdo->prepare('SELECT COUNT(*) FROM purchase_orders WHERE tenant_id = ? AND folio_year = ?');
$st->execute([$tid, '2026']);
echo "\nOC folio 2026: " . (int) $st->fetchColumn() . "\n";

$st = $pdo->prepare(
    'SELECT COUNT(*) FROM po_budget_allocations pba
     INNER JOIN purchase_orders po ON po.id = pba.purchase_order_id
     WHERE po.tenant_id = ? AND po.folio_year = ?'
);
$st->execute([$tid, '2026']);
echo "Imputaciones ligadas a OC 2026: " . (int) $st->fetchColumn() . "\n";

$st = $pdo->prepare(
    'SELECT COUNT(*), COALESCE(SUM(amount),0) FROM po_budget_allocations pba
     INNER JOIN budget_details bd ON bd.id = pba.budget_detail_id
     INNER JOIN budget_headers bh ON bh.id = bd.budget_header_id
     WHERE bh.tenant_id = ? AND bh.activity_number LIKE ?'
);
$st->execute([$tid, '%-2026']);
$row = $st->fetch(PDO::FETCH_NUM);
echo "Imputaciones en actividades *-2026 (cualquier OC): {$row[0]} filas, monto {$row[1]}\n";

echo "\nEsas imputaciones vienen de OC folio:\n";
$st = $pdo->prepare(
    'SELECT po.folio_year, COUNT(*) AS n
     FROM po_budget_allocations pba
     INNER JOIN purchase_orders po ON po.id = pba.purchase_order_id
     INNER JOIN budget_details bd ON bd.id = pba.budget_detail_id
     INNER JOIN budget_headers bh ON bh.id = bd.budget_header_id
     WHERE bh.tenant_id = ? AND bh.activity_number LIKE ?
     GROUP BY po.folio_year'
);
$st->execute([$tid, '%-2026']);
while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
    echo "  folio {$r['folio_year']}: {$r['n']}\n";
}
