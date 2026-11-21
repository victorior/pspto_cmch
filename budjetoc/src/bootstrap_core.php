<?php

declare(strict_types=1);

$configPath = dirname(__DIR__) . '/config/config.php';
if (!is_file($configPath)) {
    http_response_code(500);
    echo 'Falta config/config.php';
    exit;
}

/** @var array $CONFIG */
$CONFIG = require $configPath;

// Secretos fuera del DocumentRoot (p. ej. /home/nanodata/config/cmchbudget-secrets.local.php).
$secretsPath = getenv('BUDJETOC_SECRETS') ?: (string) ($CONFIG['secrets_path'] ?? '');
if ($secretsPath !== '' && is_file($secretsPath)) {
    $secrets = require $secretsPath;
    if (is_array($secrets)) {
        $CONFIG = array_replace_recursive($CONFIG, $secrets);
    }
}

$dbName = (string) ($CONFIG['db']['name'] ?? '');
$env = (string) ($CONFIG['env'] ?? 'local');
// Local unificado: solo budjetoc. Produccion: el nombre viene de secrets/config (p. ej. nanodata_cmchbudgetOC).
if ($env === 'local' && $dbName !== 'budjetoc') {
    $msg = "En local solo se permite la base 'budjetoc' (config db.name = '{$dbName}').";
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, $msg . PHP_EOL);
        exit(1);
    }
    http_response_code(500);
    echo htmlspecialchars($msg, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    exit;
}
if ($dbName === '' || !preg_match('/^[A-Za-z0-9_]+$/', $dbName)) {
    $msg = 'db.name invalido o vacio.';
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, $msg . PHP_EOL);
        exit(1);
    }
    http_response_code(500);
    echo htmlspecialchars($msg, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    exit;
}

if (function_exists('mb_internal_encoding')) {
    mb_internal_encoding('UTF-8');
}
ini_set('default_charset', 'UTF-8');

require_once __DIR__ . '/SecurityHeaders.php';
if (PHP_SAPI !== 'cli') {
    SecurityHeaders::sendIfWeb();
}

if (PHP_SAPI !== 'cli' && !headers_sent()) {
    $script = (string) ($_SERVER['SCRIPT_NAME'] ?? '');
    $isJson = str_contains($script, '/ajax/')
        || str_contains($script, 'export_activities_meta.php')
        || str_contains($script, 'ajax_');
    if (!$isJson) {
        header('Content-Type: text/html; charset=UTF-8');
    }
    if (str_contains($script, '/admin/')) {
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('Pragma: no-cache');
    }
}

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/GoogleAuth.php';
require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/Auth.php';
require_once __DIR__ . '/LoginRateLimiter.php';
require_once __DIR__ . '/PurchaseOrderStatus.php';
require_once __DIR__ . '/DepartmentCatalog.php';
require_once __DIR__ . '/CostCenterCatalog.php';
require_once __DIR__ . '/UserPermissions.php';
require_once __DIR__ . '/AdminAccess.php';
require_once __DIR__ . '/ProductionConfigGuard.php';

ProductionConfigGuard::warnIfNeeded($CONFIG);
