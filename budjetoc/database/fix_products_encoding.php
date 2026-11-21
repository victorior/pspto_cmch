<?php

declare(strict_types=1);

/**
 * Corrige tildes/ñ en products (y code) ya importados.
 * Uso: php database/fix_products_encoding.php [--dry-run]
 */

$root = dirname(__DIR__);
require $root . '/src/bootstrap.php';
require_once __DIR__ . '/sql_import_helpers.php';

$dryRun = in_array('--dry-run', $argv ?? [], true);
$pdo = Db::pdo($CONFIG);

$st = $pdo->query('SELECT id, code, name FROM products');
$updated = 0;
$upd = $pdo->prepare('UPDATE products SET code = ?, name = ? WHERE id = ?');

while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
    $id = (int) $row['id'];
    $code = bud_sql_fix_utf8_text((string) $row['code']) ?? '';
    $name = bud_sql_fix_utf8_text((string) $row['name']) ?? '';
    $name = trim(str_replace(["\r\n", "\r", "\n"], ' ', $name));
    $name = (string) preg_replace('/\s+/', ' ', $name);
    if ($code === (string) $row['code'] && $name === (string) $row['name']) {
        continue;
    }
    if ($dryRun) {
        echo "id={$id}\n  antes: " . mb_substr((string) $row['name'], 0, 100) . "\n";
        echo "  despues: " . mb_substr($name, 0, 100) . "\n\n";
    } else {
        $upd->execute([$code, $name, $id]);
    }
    $updated++;
}

echo ($dryRun ? 'Cambios pendientes' : 'Filas actualizadas') . ": {$updated}\n";
