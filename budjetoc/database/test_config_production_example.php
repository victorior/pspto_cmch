<?php

declare(strict_types=1);

/**
 * Smoke: plantilla nanosoft en config.production.example.php.
 * Uso: php database/test_config_production_example.php
 */

$fail = 0;

function pass(string $msg): void
{
    echo "  OK   {$msg}\n";
}

function fail(string $msg): void
{
    global $fail;
    $fail++;
    echo "  FAIL {$msg}\n";
}

echo "=== Config production example (nanosoft) ===\n\n";

$path = dirname(__DIR__) . '/config/config.production.example.php';
if (!is_file($path)) {
    fail("archivo ausente: {$path}");
    echo "\nResultado: {$fail} fail\n";
    exit(1);
}

$cfg = require $path;
if (!is_array($cfg)) {
    fail('require no devolvió array');
    echo "\nResultado: {$fail} fail\n";
    exit(1);
}

$expect = [
    'base_url' => '/budget',
    'public_url' => 'https://nanosoft.cl/budget',
    'secrets_path' => '/home2/cna14865/config/budget-secrets.local.php',
];
foreach ($expect as $key => $want) {
    $got = (string) ($cfg[$key] ?? '');
    if ($got === $want) {
        pass("{$key}={$want}");
    } else {
        fail("{$key} esperado={$want} got={$got}");
    }
}

$dbName = (string) ($cfg['db']['name'] ?? '');
$dbUser = (string) ($cfg['db']['user'] ?? '');
if ($dbName === 'cna14865_cmchbudgetOC') {
    pass('db.name=cna14865_cmchbudgetOC');
} else {
    fail("db.name esperado=cna14865_cmchbudgetOC got={$dbName}");
}
if ($dbUser === 'cna14865_cmchbudgetOC_usr') {
    pass('db.user=cna14865_cmchbudgetOC_usr');
} else {
    fail("db.user esperado=cna14865_cmchbudgetOC_usr got={$dbUser}");
}

echo "\nResultado: " . ($fail === 0 ? 'ok' : "{$fail} fail") . "\n";
exit($fail === 0 ? 0 : 1);
