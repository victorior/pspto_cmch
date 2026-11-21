<?php
$c = require dirname(__DIR__) . '/config/config.php';
require_once __DIR__ . '/require_budjetoc_db.php';
require_budjetoc_database($c);
$p = new PDO('mysql:host=' . $c['db']['host'] . ';dbname=' . $c['db']['name'] . ';charset=utf8mb4', $c['db']['user'], $c['db']['pass']);
echo $p->query('SHOW CREATE TABLE account_codes')->fetch(PDO::FETCH_NUM)[1], "\n";
echo 'null account_id: ', $p->query('SELECT COUNT(*) FROM account_codes WHERE account_id IS NULL')->fetchColumn(), "\n";
try {
    $p->exec('ALTER TABLE account_codes DROP INDEX uq_tenant_code');
    echo "dropped index\n";
} catch (Throwable $e) {
    echo 'index: ', $e->getMessage(), "\n";
}
try {
    $p->exec('ALTER TABLE account_codes DROP COLUMN code');
    echo "dropped column code\n";
} catch (Throwable $e) {
    echo 'col: ', $e->getMessage(), "\n";
}
foreach ($p->query("SELECT TABLE_NAME, CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE REFERENCED_TABLE_NAME='account_codes' AND TABLE_SCHEMA=DATABASE()") as $r) {
    echo "FK ref: {$r['TABLE_NAME']}.{$r['CONSTRAINT_NAME']}\n";
}
echo $p->query('SHOW CREATE TABLE account_codes')->fetch(PDO::FETCH_NUM)[1], "\n";
