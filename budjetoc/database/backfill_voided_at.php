<?php

declare(strict_types=1);

/**
 * Backfill voided_at en OCs anuladas (migracion 009).
 * Uso: php database/backfill_voided_at.php
 * Alternativa: py database/apply_migration_009.py
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Solo CLI.\n");
    exit(1);
}

$root = dirname(__DIR__);
require $root . '/src/bootstrap.php';

$pdo = Db::pdo($CONFIG);

try {
    OcService::ensureVoidedAtBackfill($pdo);
    $pending = (int) $pdo->query(
        'SELECT COUNT(*) FROM purchase_orders WHERE status = ' . PurchaseOrderStatus::ANULADA . ' AND voided_at IS NULL'
    )->fetchColumn();
    echo "OK: backfill voided_at aplicado. OCs anuladas sin voided_at: {$pending}\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    exit(1);
}
