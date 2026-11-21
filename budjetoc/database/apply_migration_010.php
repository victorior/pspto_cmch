<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

$pdo = Db::pdo($CONFIG);
$st = $pdo->query("SHOW COLUMNS FROM budget_headers LIKE 'budget_year'");
if ($st->fetch()) {
    echo "budget_year: ya existe\n";
    exit(0);
}

$pdo->exec(
    'ALTER TABLE budget_headers
     ADD COLUMN budget_year smallint unsigned NOT NULL DEFAULT 0 AFTER activity_number'
);
$pdo->exec(
    "UPDATE budget_headers
     SET budget_year = CAST(SUBSTRING_INDEX(activity_number, '-', -1) AS UNSIGNED)
     WHERE budget_year = 0"
);
try {
    $pdo->exec(
        'CREATE INDEX idx_bh_tenant_year_month ON budget_headers (tenant_id, budget_year, month)'
    );
} catch (PDOException $e) {
    if (str_contains($e->getMessage(), 'Duplicate')) {
        echo "indice idx_bh_tenant_year_month: ya existe\n";
    } else {
        throw $e;
    }
}

echo "OK: migracion 010 budget_year aplicada\n";
